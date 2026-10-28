<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Audience\AudienceCheckin;
use FreeFormCertificate\Frontend\Submission\GeofenceGuard;
use FreeFormCertificate\Frontend\Submission\SubmissionContext;
use FreeFormCertificate\Frontend\Submission\SubmissionRejected;
use FreeFormCertificate\Security\GpsReading;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Baseline of what the server does with a visitor's GPS today (#2040).
 *
 * Three facts the follow-up PRs change, each pinned here so the change is
 * an edit to a named assertion rather than a silent drift:
 *
 * - The submission guard never asked for a GPS check: a GPS-only form was
 *   validated by date alone. Since PR 1 it hands the posted reading to
 *   {@see \FreeFormCertificate\Security\Geofence::check_submission()}.
 * - The check-in decides by the real distance formula, with no regard for
 *   the reading's accuracy.
 * - An area check wrote the visitor's coordinates to the activity log
 *   whenever the log was on; since PR 1 it writes nothing there unless
 *   geolocation debugging is on, and never a position
 *   ({@see LocationNeverLoggedTest}).
 *
 * @covers \FreeFormCertificate\Frontend\Submission\GeofenceGuard
 * @covers \FreeFormCertificate\Audience\AudienceCheckin
 * @covers \FreeFormCertificate\Integrations\IpGeolocation
 *
 * Every class runs for real: the reading is posted, the area is a stored
 * location, and the activity log is read off its buffer (#2121).
 */
class GeoServerBaselineTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** A 200 m area in São Paulo, stored as a location. */
	private const AREA = array(
		'id'     => 'loc_a',
		'lat'    => -23.55,
		'lng'    => -46.63,
		'radius' => 200,
	);

	/**
	 * The form's stored geofence config.
	 *
	 * @var array<string, mixed>
	 */
	private array $form_config = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'is_wp_error' )->alias( static fn( $v ) => $v instanceof \WP_Error );

		$this->form_config = array();
		Functions\when( 'get_post_meta' )->alias( fn() => $this->form_config );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults ) => array_merge( $defaults, $args ) );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_unslash' )->returnArg();
		// The activity log on, every debug area and tolerance at its default,
		// and the one stored location.
		Functions\when( 'get_option' )->alias(
			static function ( $key, $fallback = false ) {
				if ( 'ffc_settings' === $key ) {
					return ActivityLogBuffer::ENABLED_SETTINGS;
				}
				if ( 'ffc_geofence_locations' === $key ) {
					return array( self::AREA );
				}
				return 'ffc_geolocation_settings' === $key ? array() : $fallback;
			}
		);
		ActivityLogBuffer::reset();
		ActivityLogBuffer::stub_functions();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
	}

	protected function tearDown(): void {
		ActivityLogBuffer::reset();
		unset( $_SERVER['REMOTE_ADDR'] );
		Monkey\tearDown();
		parent::tearDown();
		$_POST = array();
	}

	/**
	 * Run the submission guard for form 7 with a reading posted at a point.
	 *
	 * @param float $lat Latitude.
	 * @param float $lng Longitude.
	 * @return string The refusal reason, or 'allowed'.
	 */
	private function guard_with_reading_at( float $lat, float $lng ): string {
		$_POST = array(
			'ffc_geo_lat'      => (string) $lat,
			'ffc_geo_lng'      => (string) $lng,
			'ffc_geo_accuracy' => '5',
			'ffc_geo_age'      => '1000',
		);
		$ctx          = new SubmissionContext();
		$ctx->form_id = 7;
		try {
			( new GeofenceGuard() )->apply( $ctx );
			return 'allowed';
		} catch ( SubmissionRejected $e ) {
			return (string) ( $e->get_payload()['reason'] ?? '' );
		}
	}

	/**
	 * The submission guard hands the GPS reading the browser posted to the
	 * server's geofence check.
	 *
	 * Changed by PR 1 of #2040: the guard used to ask for the date and the
	 * IP only, so the GPS area was the browser's alone.
	 */
	public function test_the_submission_guard_hands_the_gps_reading_to_the_server(): void {
		// A GPS-only form around the area: only the posted reading can
		// tell the two submissions apart.
		$this->form_config = array(
			'geo_enabled'     => '1',
			'geo_gps_enabled' => '1',
			'geo_ip_enabled'  => '0',
			'geo_areas'       => '-23.55, -46.63, 200',
		);

		$this->assertSame( 'allowed', $this->guard_with_reading_at( -23.55, -46.63 ) );
		$this->assertSame( GpsReading::REASON_OUTSIDE, $this->guard_with_reading_at( 10.0, 10.0 ) );
	}

	/**
	 * The check-in's decision, with the real distance formula: inside,
	 * outside, and a reading whose error is larger than the area, which
	 * PR 3 of #2040 refuses instead of deciding it as if it were exact.
	 */
	public function test_the_checkin_decides_by_distance_and_accuracy(): void {
		// No tolerance stored: the default, 1.5 × the radius (#2070).
		$decide = static function ( ?array $position ) {
			$result = AudienceCheckin::check_position( array( 'loc_a' ), $position );
			return true === $result ? 'inside' : $result->get_error_code();
		};

		$this->assertSame(
			array(
				'inside'    => 'inside',
				'edge'      => 'inside',
				'outside'   => AudienceCheckin::ERROR_OUTSIDE_AREA,
				'imprecise' => AudienceCheckin::ERROR_INACCURATE,
				'none'      => AudienceCheckin::ERROR_NEEDS_LOCATION,
			),
			array(
				'inside'    => $decide( array( 'lat' => -23.55, 'lng' => -46.63, 'accuracy' => 10.0 ) ),
				// About 150 m north of the centre.
				'edge'      => $decide( array( 'lat' => -23.5487, 'lng' => -46.63, 'accuracy' => 10.0 ) ),
				'outside'   => $decide( array( 'lat' => -22.9, 'lng' => -43.2, 'accuracy' => 10.0 ) ),
				// Changed by PR 3 of #2040: an 800 m reading cannot tell
				// inside from outside a 200 m area, so it is refused.
				'imprecise' => $decide( array( 'lat' => -23.55, 'lng' => -46.63, 'accuracy' => 800.0 ) ),
				'none'      => $decide( null ),
			)
		);
	}

	/**
	 * An area check writes nothing to the activity log while geolocation
	 * debugging is off, which is the default.
	 *
	 * Changed by PR 1 of #2040: it used to write the visitor's coordinates
	 * whenever the activity log was on.
	 */
	public function test_an_area_check_writes_nothing_to_the_activity_log_without_debugging(): void {
		// No tolerance stored: the default (#2070). The log is on.
		AudienceCheckin::check_position( array( 'loc_a' ), array( 'lat' => -23.5512, 'lng' => -46.6345, 'accuracy' => 10.0 ) );

		$this->assertSame( array(), ActivityLogBuffer::entries() );
	}
}

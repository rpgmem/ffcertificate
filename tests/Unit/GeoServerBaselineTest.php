<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Audience\AudienceCheckin;
use FreeFormCertificate\Frontend\Submission\GeofenceGuard;
use FreeFormCertificate\Frontend\Submission\SubmissionContext;
use Mockery;
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
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class GeoServerBaselineTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** A 200 m area in São Paulo. */
	private const AREA = array(
		'lat'    => -23.55,
		'lng'    => -46.63,
		'radius' => 200,
	);

	/**
	 * Activity-log rows written during the test.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $logged = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'is_wp_error' )->alias( static fn( $v ) => $v instanceof \WP_Error );

		$this->logged = array();
		Mockery::getConfiguration()->setConstantsMap(
			array(
				'FreeFormCertificate\Core\ActivityLog' => array( 'LEVEL_DEBUG' => 'debug' ),
				'FreeFormCertificate\Core\Debug'       => array( 'AREA_GEOFENCE' => 'debug_geofence' ),
			)
		);
		$log = Mockery::mock( 'alias:FreeFormCertificate\Core\ActivityLog' );
		$log->shouldReceive( 'log' )->andReturnUsing(
			function ( string $action, string $level = 'info', array $context = array() ): bool {
				$this->logged[] = array(
					'action'  => $action,
					'level'   => $level,
					'context' => $context,
				);
				return true;
			}
		);
		$log->shouldReceive( 'log_access_denied' )->andReturn( true );
		$debug = Mockery::mock( 'alias:FreeFormCertificate\Core\Debug' );
		$debug->shouldReceive( 'log_geofence' );
		$debug->shouldReceive( 'is_enabled' )->andReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
		$_POST = array();
	}

	/**
	 * The submission guard hands the GPS reading the browser posted to the
	 * server's geofence check.
	 *
	 * Changed by PR 1 of #2040: the guard used to ask for the date and the
	 * IP only, so the GPS area was the browser's alone.
	 */
	public function test_the_submission_guard_hands_the_gps_reading_to_the_server(): void {
		$posted = array(
			'reading' => array(
				'lat'      => 10.0,
				'lng'      => 10.0,
				'accuracy' => 5.0,
				'age'      => 1000,
			),
			'status'  => '',
		);
		Mockery::mock( 'alias:FreeFormCertificate\Security\GpsReading' )
			->shouldReceive( 'from_request' )->andReturn( $posted );

		$asked = null;
		Mockery::mock( 'alias:FreeFormCertificate\Security\Geofence' )
			->shouldReceive( 'check_submission' )
			->andReturnUsing(
				static function ( int $form_id, array $input ) use ( &$asked ): array {
					$asked = array( $form_id, $input );
					return array(
						'allowed' => true,
						'reason'  => 'validated',
						'message' => '',
					);
				}
			);

		$ctx          = new SubmissionContext();
		$ctx->form_id = 7;
		( new GeofenceGuard() )->apply( $ctx );

		$this->assertSame( array( 7, $posted ), $asked );
	}

	/**
	 * The check-in's decision, with the real distance formula: inside,
	 * outside, and a reading whose error is larger than the area, which is
	 * decided as if it were exact.
	 *
	 * PR 1 of #2040 changes the "imprecise" row.
	 */
	public function test_the_checkin_decides_by_distance_alone(): void {
		$registry = Mockery::mock( 'alias:FreeFormCertificate\Security\GeofenceLocationRegistry' );
		$registry->shouldReceive( 'get_by_ids' )->andReturn( array( self::AREA ) );

		$decide = static function ( ?array $position ) {
			$result = AudienceCheckin::check_position( array( 'loc_a' ), $position );
			return true === $result ? 'inside' : $result->get_error_code();
		};

		$this->assertSame(
			array(
				'inside'    => 'inside',
				'edge'      => 'inside',
				'outside'   => AudienceCheckin::ERROR_OUTSIDE_AREA,
				'imprecise' => 'inside',
				'none'      => AudienceCheckin::ERROR_NEEDS_LOCATION,
			),
			array(
				'inside'    => $decide( array( 'lat' => -23.55, 'lng' => -46.63 ) ),
				// About 150 m north of the centre.
				'edge'      => $decide( array( 'lat' => -23.5487, 'lng' => -46.63 ) ),
				'outside'   => $decide( array( 'lat' => -22.9, 'lng' => -43.2 ) ),
				// The position carries no accuracy: an 800 m reading at the
				// centre is the same as an exact one.
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
		$registry = Mockery::mock( 'alias:FreeFormCertificate\Security\GeofenceLocationRegistry' );
		$registry->shouldReceive( 'get_by_ids' )->andReturn( array( self::AREA ) );

		AudienceCheckin::check_position( array( 'loc_a' ), array( 'lat' => -23.5512, 'lng' => -46.6345 ) );

		$this->assertSame( array(), $this->logged );
	}
}

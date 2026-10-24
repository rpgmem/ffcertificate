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
 * - The submission guard never asks for a GPS check: a GPS-only form is
 *   validated by date alone, and coordinates posted with the submission
 *   are not read.
 * - The check-in decides by the real distance formula, with no regard for
 *   the reading's accuracy.
 * - Every area check writes the visitor's coordinates to the activity log
 *   when it is on.
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
			array( 'FreeFormCertificate\Core\ActivityLog' => array( 'LEVEL_DEBUG' => 'debug' ) )
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
		Mockery::mock( 'alias:FreeFormCertificate\Core\Debug' )->shouldReceive( 'log_geofence' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
		$_POST = array();
	}

	/**
	 * The geofence configs a form can carry, and what the guard asks of
	 * `Geofence::can_access_form()` for each today.
	 *
	 * @return array<string, array{0: array<string, bool>, 1: bool}>
	 */
	public static function guard_cases(): array {
		return array(
			'gps only'      => array( array( 'geo_enabled' => true, 'geo_gps_enabled' => true, 'geo_ip_enabled' => false ), false ),
			'gps and ip'    => array( array( 'geo_enabled' => true, 'geo_gps_enabled' => true, 'geo_ip_enabled' => true ), true ),
			'ip only'       => array( array( 'geo_enabled' => true, 'geo_gps_enabled' => false, 'geo_ip_enabled' => true ), true ),
			'geo off'       => array( array( 'geo_enabled' => false, 'geo_gps_enabled' => true, 'geo_ip_enabled' => true ), false ),
		);
	}

	/**
	 * The submission guard checks the date always and the IP when the form
	 * asks for it, and never the GPS: posted coordinates are not read.
	 *
	 * PR 1 of #2040 changes the "gps only" and "gps and ip" rows.
	 *
	 * @dataProvider guard_cases
	 *
	 * @param array<string, bool> $config   Stored geofence switches.
	 * @param bool                $check_ip Whether the guard asks for the IP check today.
	 */
	public function test_the_submission_guard_never_reads_the_gps( array $config, bool $check_ip ): void {
		$_POST = array(
			'ffc_lat'      => '10.0',
			'ffc_lng'      => '10.0',
			'ffc_accuracy' => '5',
		);

		$asked = null;
		$gf    = Mockery::mock( 'alias:FreeFormCertificate\Security\Geofence' );
		$gf->shouldReceive( 'get_form_config' )->andReturn( $config );
		$gf->shouldReceive( 'can_access_form' )->andReturnUsing(
			static function ( int $form_id, array $options ) use ( &$asked ): array {
				$asked = $options;
				return array( 'allowed' => true );
			}
		);

		$ctx          = new SubmissionContext();
		$ctx->form_id = 7;
		( new GeofenceGuard() )->apply( $ctx );

		$this->assertSame(
			array(
				'check_datetime' => true,
				'check_geo'      => $check_ip,
			),
			$asked
		);
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
	 * Every area check writes the visitor's coordinates to the activity log.
	 *
	 * PR 1 of #2040 removes them: the row keeps the decision only.
	 */
	public function test_an_area_check_logs_the_visitors_coordinates(): void {
		$registry = Mockery::mock( 'alias:FreeFormCertificate\Security\GeofenceLocationRegistry' );
		$registry->shouldReceive( 'get_by_ids' )->andReturn( array( self::AREA ) );

		AudienceCheckin::check_position( array( 'loc_a' ), array( 'lat' => -23.5512, 'lng' => -46.6345 ) );

		$this->assertCount( 1, $this->logged );
		$this->assertSame( 'ip_geolocation_debug', $this->logged[0]['action'] );
		$this->assertSame( 'debug', $this->logged[0]['level'] );
		$this->assertSame(
			array(
				'lat' => -23.5512,
				'lng' => -46.6345,
			),
			$this->logged[0]['context']['user_location']
		);
	}
}

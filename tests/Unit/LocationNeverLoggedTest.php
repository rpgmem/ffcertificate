<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Integrations\IpGeolocation;
use FreeFormCertificate\Security\Geofence;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * A visitor's position never reaches a log (#2040).
 *
 * Every geofence path that logs is driven with the geolocation debug toggle
 * ON, the case that writes the most, and every context written to the
 * activity log or to the debug log is searched for a coordinate: the GPS
 * position an area check compares, the position an IP resolves to, and the
 * position a denial carries.
 *
 * @covers \FreeFormCertificate\Integrations\IpGeolocation
 * @covers \FreeFormCertificate\Security\Geofence
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class LocationNeverLoggedTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** A position no log may carry, in either of its two spellings. */
	private const LAT = -23.5512;
	private const LNG = -46.6345;

	/**
	 * Every context written, from either log.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = array();

	/** Whether the geolocation debug toggle reads as on. */
	private bool $debug_on = true;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'is_wp_error' )->alias( static fn( $v ) => $v instanceof \WP_Error );

		$this->written = array();
		Mockery::getConfiguration()->setConstantsMap(
			array(
				'FreeFormCertificate\Core\ActivityLog' => array( 'LEVEL_DEBUG' => 'debug' ),
				'FreeFormCertificate\Core\Debug'       => array( 'AREA_GEOFENCE' => 'debug_geofence' ),
			)
		);
		$log = Mockery::mock( 'alias:FreeFormCertificate\Core\ActivityLog' );
		$log->shouldReceive( 'log' )->andReturnUsing(
			function ( string $action, string $level = 'info', array $context = array() ): bool {
				$this->written[] = $context;
				return true;
			}
		);
		$log->shouldReceive( 'log_access_denied' )->andReturnUsing(
			function ( ...$args ): bool {
				$this->written[] = $args;
				return true;
			}
		);
		$debug = Mockery::mock( 'alias:FreeFormCertificate\Core\Debug' );
		$debug->shouldReceive( 'is_enabled' )->andReturnUsing( fn() => $this->debug_on );
		$debug->shouldReceive( 'log_geofence' )->andReturnUsing(
			function ( string $message, array $context = array() ): void {
				$this->written[] = $context;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_an_area_check_logs_the_decision_and_no_position(): void {
		$inside = IpGeolocation::is_within_areas(
			array(
				'latitude'  => self::LAT,
				'longitude' => self::LNG,
			),
			array(
				array( 'lat' => -23.55, 'lng' => -46.63, 'radius' => 1000.0 ),
				array( 'lat' => -22.9, 'lng' => -43.2, 'radius' => 1000.0 ),
			)
		);

		$this->assertTrue( $inside );
		$this->assertNotSame( array(), $this->written, 'The check must still log its decision while debugging is on.' );
		$this->assertContains( array( 'message' => 'Area check', 'area' => 0, 'radius' => 1000.0, 'within' => true ), $this->written );
		$this->assertNoPosition();
	}

	public function test_an_ip_lookup_logs_no_position(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_remote_get' )->justReturn( array( 'body' => 'x' ) );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			(string) json_encode(
				array(
					'status'      => 'success',
					'country'     => 'Brazil',
					'countryCode' => 'BR',
					'regionName'  => 'São Paulo',
					'region'      => 'SP',
					'city'        => 'São Paulo',
					'lat'         => self::LAT,
					'lon'         => self::LNG,
				)
			)
		);
		Functions\when( 'wp_salt' )->justReturn( 'salt' );

		$fetch    = new \ReflectionMethod( IpGeolocation::class, 'fetch_from_ipapi' );
		$location = $fetch->invoke( null, '203.0.113.5' );

		// The lookup still returns the position; only the log copy drops it.
		$this->assertSame( self::LAT, $location['latitude'] );
		$this->assertNotSame( array(), $this->written );
		$this->assertNoPosition();
	}

	public function test_a_denial_logs_no_position(): void {
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults ) => array_merge( $defaults, $args ) );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
			->shouldReceive( 'get_user_ip' )->andReturn( '203.0.113.5' );

		$result = Geofence::check_access(
			array(
				'geo_enabled'      => true,
				'geo_gps_enabled'  => true,
				'datetime_enabled' => false,
				'geo_areas'        => '-22.9, -43.2, 200',
			),
			array(
				'check_datetime' => false,
				'check_geo'      => true,
				'user_location'  => array(
					'latitude'  => self::LAT,
					'longitude' => self::LNG,
				),
			)
		);

		$this->assertFalse( $result['allowed'] );
		$this->assertNotSame( array(), $this->written );
		$this->assertNoPosition();
	}

	public function test_the_activity_log_copy_waits_for_the_debug_toggle(): void {
		$this->debug_on = false;

		IpGeolocation::is_within_areas(
			array(
				'latitude'  => self::LAT,
				'longitude' => self::LNG,
			),
			array( array( 'lat' => -23.55, 'lng' => -46.63, 'radius' => 1000.0 ) )
		);

		// Only the debug log (which checks its own toggle) was called.
		$this->assertCount( 1, $this->written );
	}

	/**
	 * No written context carries the position, as a number or as text.
	 */
	private function assertNoPosition(): void {
		$dump = (string) json_encode( $this->written );
		foreach ( array( self::LAT, self::LNG ) as $coordinate ) {
			$this->assertStringNotContainsString( (string) $coordinate, $dump, 'A visitor\'s coordinate reached a log.' );
		}
		foreach ( array( '"latitude"', '"longitude"', '"lat"', '"lng"', '"user_location"' ) as $key ) {
			$this->assertStringNotContainsString( $key, $dump, 'A position key reached a log.' );
		}
	}
}

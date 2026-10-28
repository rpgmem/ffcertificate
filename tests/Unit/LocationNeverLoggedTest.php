<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Integrations\IpGeolocation;
use FreeFormCertificate\Security\Geofence;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * A visitor's position never reaches a log (#2040).
 *
 * Every geofence path that logs is driven with the geolocation debug toggle
 * ON, the case that writes the most, and every context written to the
 * activity log or to the debug log is searched for a coordinate: the GPS
 * position an area check compares, the position an IP resolves to, and the
 * position a denial carries. ActivityLog and Debug are the real classes:
 * the activity log's buffer and every `error_log()` line are what is
 * searched (#2117).
 *
 * @covers \FreeFormCertificate\Integrations\IpGeolocation
 * @covers \FreeFormCertificate\Security\Geofence
 */
class LocationNeverLoggedTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** A position no log may carry, in either of its two spellings. */
	private const LAT = -23.5512;
	private const LNG = -46.6345;

	/**
	 * Every line the debug log wrote.
	 *
	 * @var array<int, string>
	 */
	private array $debug_lines = array();

	/** Whether the geolocation debug toggle reads as on. */
	private bool $debug_on = true;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'is_wp_error' )->alias( static fn( $v ) => $v instanceof \WP_Error );
		Functions\when( 'wp_salt' )->justReturn( 'salt' );

		ActivityLogBuffer::reset();
		ActivityLogBuffer::stub_functions();
		Functions\when( 'get_option' )->alias(
			fn( $key, $fallback = false ) => 'ffc_settings' === $key
				? ActivityLogBuffer::ENABLED_SETTINGS + array( 'debug_geofence' => $this->debug_on ? 1 : 0 )
				: $fallback
		);

		$this->debug_lines = array();
		Functions\when( 'error_log' )->alias(
			function ( string $line ): bool {
				$this->debug_lines[] = $line;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		ActivityLogBuffer::reset();
		unset( $_SERVER['REMOTE_ADDR'] );
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
		$this->assertNotSame( array(), $this->debug_lines, 'The check must still log its decision while debugging is on.' );
		// The row as stored: JSON writes the 1000.0 radius as 1000.
		$this->assertContains(
			array( 'message' => 'Area check', 'area' => 0, 'radius' => 1000, 'within' => true ),
			array_column( ActivityLogBuffer::contexts(), 'context' )
		);
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
		$fetch    = new \ReflectionMethod( IpGeolocation::class, 'fetch_from_ipapi' );
		$location = $fetch->invoke( null, '203.0.113.5' );

		// The lookup still returns the position; only the log copy drops it.
		$this->assertSame( self::LAT, $location['latitude'] );
		$this->assertNotSame( array(), $this->debug_lines );
		$this->assertNoPosition();
	}

	public function test_a_denial_logs_no_position(): void {
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults ) => array_merge( $defaults, $args ) );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';

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
		$this->assertContains( 'access_denied', array_column( ActivityLogBuffer::contexts(), 'action' ) );
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

		// The log is on, the debug toggle is off: neither log writes a line.
		$this->assertSame( array(), ActivityLogBuffer::entries() );
		$this->assertSame( array(), $this->debug_lines );
	}

	/**
	 * No written context carries the position, as a number or as text.
	 */
	private function assertNoPosition(): void {
		$dump = (string) json_encode( ActivityLogBuffer::contexts() ) . "\n" . implode( "\n", $this->debug_lines );
		foreach ( array( self::LAT, self::LNG ) as $coordinate ) {
			$this->assertStringNotContainsString( (string) $coordinate, $dump, 'A visitor\'s coordinate reached a log.' );
		}
		// A key as JSON writes it (activity log) and as print_r() writes it (debug log).
		foreach ( array( 'latitude', 'longitude', 'lat', 'lng', 'user_location' ) as $key ) {
			$this->assertStringNotContainsString( '"' . $key . '"', $dump, 'A position key reached a log.' );
			$this->assertStringNotContainsString( '[' . $key . ']', $dump, 'A position key reached a log.' );
		}
	}
}

<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Security\Geofence;
use FreeFormCertificate\Security\GpsReading;
use FreeFormCertificate\Tests\Support\ActivityLogBuffer;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The geofence check of a submission (#2040): the date window, then the GPS
 * reading and the IP, combined by the form's "GPS or IP" setting.
 *
 * The distance check runs for real; only the IP lookup is stood in for, so
 * the IP is "inside" or "outside" by the position it resolves to. The
 * activity log, Debug and RequestInput are the real classes: a denial is
 * read from the log's buffer, and the visitor's address from `$_SERVER`
 * (#2117).
 *
 * @covers \FreeFormCertificate\Security\Geofence
 */
class GeofenceCheckSubmissionTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** A point inside the form's 500 m area, and one far from it. */
	private const INSIDE  = array( -23.55, -46.63 );
	private const OUTSIDE = array( -22.9, -43.2 );

	/**
	 * The form's stored geofence config.
	 *
	 * @var array<string, mixed>
	 */
	private array $config = array();

	/**
	 * The geolocation settings.
	 *
	 * @var array<string, mixed>
	 */
	private array $settings = array();

	/** Where the visitor's IP resolves to. */
	private array $ip_at = self::INSIDE;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'is_wp_error' )->alias( static fn( $v ) => $v instanceof \WP_Error );
		Functions\when( 'wp_parse_args' )->alias( static fn( $args, $defaults ) => array_merge( $defaults, $args ) );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_post_meta' )->alias( fn() => $this->config );
		Functions\when( 'get_option' )->alias(
			function ( $key, $fallback = false ) {
				if ( 'ffc_geolocation_settings' === $key ) {
					return $this->settings;
				}
				// The activity log on, every debug area off.
				return 'ffc_settings' === $key ? ActivityLogBuffer::ENABLED_SETTINGS : $fallback;
			}
		);
		ActivityLogBuffer::reset();
		ActivityLogBuffer::stub_functions();
		$_SERVER['REMOTE_ADDR'] = '8.8.8.8';

		$this->config = array(
			'geo_enabled'      => '1',
			'geo_gps_enabled'  => '1',
			'geo_ip_enabled'   => '0',
			'geo_areas'        => '-23.55, -46.63, 500',
			'geo_gps_ip_logic' => 'or',
		);
		$this->settings = array( 'gps_cache_ttl' => 600 );
	}

	protected function tearDown(): void {
		ActivityLogBuffer::reset();
		unset( $_SERVER['REMOTE_ADDR'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The reasons the activity log recorded as denials.
	 *
	 * @return array<int, mixed>
	 */
	private static function denied(): array {
		$denials = array_filter( ActivityLogBuffer::contexts(), static fn( array $row ) => 'access_denied' === $row['action'] );
		return array_values( array_map( static fn( array $row ) => $row['context']['reason'] ?? null, $denials ) );
	}

	/**
	 * What the browser sends for a reading at a point.
	 *
	 * @param array{0: float, 1: float} $point    Latitude, longitude.
	 * @param float                     $accuracy Metres.
	 * @return array<string, mixed>
	 */
	private static function reading( array $point, float $accuracy = 10.0 ): array {
		return array(
			'reading' => array(
				'lat'      => $point[0],
				'lng'      => $point[1],
				'accuracy' => $accuracy,
				'age'      => 1000,
			),
			'status'  => '',
		);
	}

	/**
	 * The IP's position, for a form with IP on.
	 */
	private function ip_resolves_to( array $point ): void {
		$this->config['geo_ip_enabled'] = '1';
		$this->settings['ip_api_enabled'] = 1;
		$this->settings['ip_cache_enabled'] = 1;
		Functions\when( 'get_transient' )->justReturn(
			array(
				'latitude'  => $point[0],
				'longitude' => $point[1],
			)
		);
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
	}

	public function test_a_form_without_a_geofence_is_not_checked(): void {
		$this->config = array();

		$this->assertSame( 'no_restrictions', Geofence::check_submission( 7, array( 'reading' => null, 'status' => '' ) )['reason'] );
	}

	public function test_a_gps_only_form_passes_a_reading_inside_and_refuses_one_outside(): void {
		$this->assertTrue( Geofence::check_submission( 7, self::reading( self::INSIDE ) )['allowed'] );

		$outside = Geofence::check_submission( 7, self::reading( self::OUTSIDE ) );
		$this->assertFalse( $outside['allowed'] );
		$this->assertSame( GpsReading::REASON_OUTSIDE, $outside['reason'] );
		$this->assertSame( 'This form is not available in your location.', $outside['message'] );
		$this->assertSame( array( GpsReading::REASON_OUTSIDE ), self::denied() );
	}

	public function test_no_reading_follows_the_policy(): void {
		$none = array( 'reading' => null, 'status' => '' );

		// No cases saved: the hybrid default lets "no API" through.
		$this->assertTrue( Geofence::check_submission( 7, $none )['allowed'] );

		$this->settings['gps_fallback_cases'] = array( 'no_api' => 'block' );
		$this->config['msg_geo_error']       = 'Turn your location on.';
		$strict                              = Geofence::check_submission( 7, $none );
		$this->assertFalse( $strict['allowed'] );
		$this->assertSame( GpsReading::REASON_MISSING, $strict['reason'] );
		$this->assertSame( 'Turn your location on.', $strict['message'] );
	}

	public function test_an_imprecise_reading_follows_position_unavailable(): void {
		$result = Geofence::check_submission( 7, self::reading( self::INSIDE, 900.0 ) );

		$this->assertFalse( $result['allowed'] );
		$this->assertSame( GpsReading::REASON_INACCURATE, $result['reason'] );
	}

	public function test_gps_or_ip_passes_on_either(): void {
		$this->ip_resolves_to( self::OUTSIDE );
		$this->assertTrue( Geofence::check_submission( 7, self::reading( self::INSIDE ) )['allowed'], 'GPS inside, IP outside' );

		$this->ip_resolves_to( self::INSIDE );
		$this->assertTrue( Geofence::check_submission( 7, self::reading( self::OUTSIDE ) )['allowed'], 'GPS outside, IP inside' );

		$this->ip_resolves_to( self::OUTSIDE );
		$both = Geofence::check_submission( 7, self::reading( self::OUTSIDE ) );
		$this->assertFalse( $both['allowed'] );
		$this->assertSame( GpsReading::REASON_OUTSIDE, $both['reason'] );
	}

	public function test_gps_and_ip_needs_both(): void {
		$this->config['geo_gps_ip_logic'] = 'and';

		$this->ip_resolves_to( self::OUTSIDE );
		$ip_failed = Geofence::check_submission( 7, self::reading( self::INSIDE ) );
		$this->assertFalse( $ip_failed['allowed'] );
		$this->assertSame( 'geolocation_invalid', $ip_failed['reason'] );

		$this->ip_resolves_to( self::INSIDE );
		$this->assertTrue( Geofence::check_submission( 7, self::reading( self::INSIDE ) )['allowed'] );
		$this->assertFalse( Geofence::check_submission( 7, self::reading( self::OUTSIDE ) )['allowed'] );
	}

	public function test_an_ip_only_form_ignores_the_gps(): void {
		$this->config['geo_gps_enabled'] = '0';
		$this->ip_resolves_to( self::INSIDE );

		$this->assertTrue( Geofence::check_submission( 7, array( 'reading' => null, 'status' => '' ) )['allowed'] );
	}

	public function test_a_closed_date_window_refuses_before_the_location(): void {
		$this->config['datetime_enabled'] = '1';
		$this->config['date_start']       = '2000-01-01';
		$this->config['date_end']         = '2000-01-02';
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'current_time' )->justReturn( '2027-01-01 12:00:00' );
		Functions\when( 'wp_date' )->alias( static fn( string $format ) => gmdate( $format, 1798804800 ) );

		$result = Geofence::check_submission( 7, self::reading( self::INSIDE ) );

		$this->assertFalse( $result['allowed'] );
		$this->assertSame( 'datetime_invalid', $result['reason'] );
	}

	public function test_an_administrator_with_the_geo_bypass_skips_the_location(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->settings['admin_bypass_geo'] = 1;

		$this->assertTrue( Geofence::check_submission( 7, self::reading( self::OUTSIDE ) )['allowed'] );
	}

	public function test_the_policy_is_read_into_every_case(): void {
		$this->settings['gps_fallback'] = 'block';
		$this->assertSame(
			array(
				'permission_denied'    => 'block',
				'no_api'               => 'block',
				'position_unavailable' => 'block',
				'timeout'              => 'block',
				'safety_timer'         => 'block',
			),
			Geofence::gps_fallback_cases()
		);

		$this->settings['gps_fallback_cases'] = array( 'timeout' => 'allow', 'no_api' => 'nonsense' );
		$this->assertSame(
			array(
				'permission_denied'    => 'block',
				'no_api'               => 'block',
				'position_unavailable' => 'block',
				'timeout'              => 'allow',
				'safety_timer'         => 'block',
			),
			Geofence::gps_fallback_cases()
		);
	}
}

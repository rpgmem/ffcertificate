<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Security\Geofence;

/**
 * Tests for Geofence::parse_areas(), validate_geolocation(), and has_form_expired_by_days().
 *
 * validate_geolocation() runs over the real IpGeolocation (the area check and
 * the IP lookup), GeolocationSettingsReader and ClientIpResolver; only the
 * options, `wp_remote_get` and `$_SERVER` are stubbed.
 */
class GeofenceGeolocationTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** A public address, so the lookup's private-range filter lets it through. */
	private const VISITOR_IP = '8.8.8.8';

	/** @var array<string, mixed> The server superglobal before the test. */
	private array $server_backup = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->server_backup = $_SERVER ?? array();
	}

	protected function tearDown(): void {
		$_SERVER = $this->server_backup;
		Monkey\tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// parse_areas tests
	// -------------------------------------------------------------------------

	/**
	 * Empty string input returns an empty array.
	 */
	public function test_parse_areas_returns_empty_for_empty_string(): void {
		$result = Geofence::parse_areas( '' );

		$this->assertSame( array(), $result );
	}

	/**
	 * A single valid "lat, lng, radius" line is parsed correctly.
	 */
	public function test_parse_areas_parses_valid_single_area(): void {
		$result = Geofence::parse_areas( '40.7128, -74.0060, 5000' );

		$this->assertCount( 1, $result );
		$this->assertSame( 40.7128, $result[0]['lat'] );
		$this->assertSame( -74.006, $result[0]['lng'] );
		$this->assertSame( 5000.0, $result[0]['radius'] );
	}

	/**
	 * Multiple newline-separated lines each produce an area entry.
	 */
	public function test_parse_areas_parses_multiple_areas(): void {
		$input = "40.7128, -74.0060, 5000\n34.0522, -118.2437, 10000";

		$result = Geofence::parse_areas( $input );

		$this->assertCount( 2, $result );
		$this->assertSame( 40.7128, $result[0]['lat'] );
		$this->assertSame( 34.0522, $result[1]['lat'] );
	}

	/**
	 * Lines that do not have exactly 3 comma-separated parts are skipped.
	 */
	public function test_parse_areas_skips_invalid_format(): void {
		$input = "40.7128, -74.0060\n40.7128, -74.0060, 5000, extra\nvalid, line";

		$result = Geofence::parse_areas( $input );

		$this->assertSame( array(), $result );
	}

	/**
	 * Coordinates outside valid ranges and non-positive radius are skipped.
	 */
	public function test_parse_areas_skips_out_of_range_coordinates(): void {
		$lines = implode( "\n", array(
			'91, 0, 100',      // lat > 90
			'-91, 0, 100',     // lat < -90
			'0, 181, 100',     // lng > 180
			'0, -181, 100',    // lng < -180
			'0, 0, 0',         // radius = 0
			'0, 0, -5',        // radius < 0
		) );

		$result = Geofence::parse_areas( $lines );

		$this->assertSame( array(), $result );
	}

	/**
	 * Leading/trailing whitespace is trimmed and blank lines are ignored.
	 */
	public function test_parse_areas_trims_whitespace_and_skips_blank_lines(): void {
		$input = "  40.7128 , -74.0060 , 5000  \n\n\n  34.0522, -118.2437, 10000\n  ";

		$result = Geofence::parse_areas( $input );

		$this->assertCount( 2, $result );
		$this->assertSame( 40.7128, $result[0]['lat'] );
		$this->assertSame( 34.0522, $result[1]['lat'] );
	}

	// -------------------------------------------------------------------------
	// validate_geolocation tests
	// -------------------------------------------------------------------------

	/**
	 * When geo_areas is empty, validation returns valid with 'no_areas_defined'.
	 */
	public function test_geolocation_valid_when_no_areas_defined(): void {
		Functions\when( '__' )->returnArg();

		$config = array( 'geo_areas' => '' );
		$result = Geofence::validate_geolocation( $config );

		$this->assertTrue( $result['valid'] );
		$this->assertSame( 'no_areas_defined', $result['details']['reason'] );
	}

	/**
	 * When no user_location is provided and IP lookup is not enabled,
	 * the result is invalid with 'location_unavailable'.
	 */
	public function test_geolocation_invalid_when_location_unavailable(): void {
		Functions\when( '__' )->returnArg();

		$config = array(
			'geo_areas'      => "40.7128, -74.0060, 5000",
			'geo_ip_enabled' => false,
		);

		$result = Geofence::validate_geolocation( $config, null );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'location_unavailable', $result['details']['reason'] );
	}

	/**
	 * User location within an allowed area returns valid.
	 *
	 * The real IpGeolocation::is_within_areas() decides: the visitor stands on
	 * the area's centre.
	 */
	public function test_geolocation_valid_when_within_allowed_areas(): void {
		Functions\when( '__' )->returnArg();
		$this->stub_options( array() );

		$config        = array(
			'geo_areas'      => "40.7128, -74.0060, 5000",
			'geo_ip_enabled' => false,
		);
		$user_location = array( 'latitude' => 40.7128, 'longitude' => -74.0060 );

		$result = Geofence::validate_geolocation( $config, $user_location );

		$this->assertTrue( $result['valid'] );
		// The position is not carried in the result, which a denial logs (#2040).
		$this->assertSame( array( 'reason' => 'inside_allowed_areas' ), $result['details'] );
	}

	/**
	 * User location outside all allowed areas returns invalid.
	 *
	 * The visitor stands thousands of kilometres from the only 5 km area.
	 */
	public function test_geolocation_invalid_when_outside_allowed_areas(): void {
		Functions\when( '__' )->returnArg();
		$this->stub_options( array() );

		$config        = array(
			'geo_areas'      => "40.7128, -74.0060, 5000",
			'geo_ip_enabled' => false,
		);
		$user_location = array( 'latitude' => 10.0, 'longitude' => 20.0 );

		$result = Geofence::validate_geolocation( $config, $user_location );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'outside_allowed_areas', $result['details']['reason'] );
		$this->assertSame( 1, $result['details']['areas_count'] );
	}

	/**
	 * When geo_ip_areas_permissive is enabled, the permissive IP areas are used
	 * instead of the default geo_areas for the within-areas check, with OR logic.
	 *
	 * The visitor stands in Los Angeles: outside the single default area (New
	 * York, 5 km) and inside only the second of the two permissive areas, so a
	 * valid result proves both that the permissive set was checked and that
	 * one match was enough.
	 */
	public function test_geolocation_uses_permissive_ip_areas_when_configured(): void {
		Functions\when( '__' )->returnArg();
		$this->stub_options( array() );

		$config = array(
			'geo_areas'               => "40.7128, -74.0060, 5000",
			'geo_ip_enabled'          => true,
			'geo_ip_areas_permissive' => true,
			'geo_ip_areas'            => "40.7128, -74.0060, 50000\n34.0522, -118.2437, 50000",
		);

		$user_location = array( 'latitude' => 34.0522, 'longitude' => -118.2437 );

		$result = Geofence::validate_geolocation( $config, $user_location );

		$this->assertTrue( $result['valid'] );
		$this->assertSame( array( 'reason' => 'inside_allowed_areas' ), $result['details'] );

		// The same visitor against the default areas alone is refused, so the
		// pass above came from the permissive set.
		unset( $config['geo_ip_areas_permissive'] );
		$this->assertFalse( Geofence::validate_geolocation( $config, $user_location )['valid'] );
	}

	/**
	 * When IP geolocation returns a WP_Error and the fallback is 'allow',
	 * the result should be valid with reason 'ip_fallback_allow'.
	 *
	 * The real IpGeolocation::get_location() runs with the API enabled and
	 * its HTTP request failing.
	 */
	public function test_geolocation_falls_back_on_ip_error_allow(): void {
		Functions\when( '__' )->returnArg();
		$requests = $this->stub_failing_ip_lookup();
		$this->stub_options( array( 'ip_api_enabled' => true, 'api_fallback' => 'allow' ) );

		$config = array(
			'geo_areas'      => "40.7128, -74.0060, 5000",
			'geo_ip_enabled' => true,
		);

		$result = Geofence::validate_geolocation( $config );

		$this->assertTrue( $result['valid'] );
		$this->assertSame( 'ip_fallback_allow', $result['details']['reason'] );
		$this->assertSame( 'Service unavailable', $result['details']['error'] );
		$this->assertCount( 1, $requests->calls, 'The lookup asks the IP service exactly once.' );
		$this->assertStringContainsString( self::VISITOR_IP, (string) $requests->calls[0][0] );
	}

	/**
	 * When IP geolocation returns a WP_Error and the fallback is 'block',
	 * the result should be invalid with reason 'ip_fallback_block'.
	 */
	public function test_geolocation_falls_back_on_ip_error_block(): void {
		Functions\when( '__' )->returnArg();
		$requests = $this->stub_failing_ip_lookup();
		$this->stub_options( array( 'ip_api_enabled' => true, 'api_fallback' => 'block' ) );

		$config = array(
			'geo_areas'      => "40.7128, -74.0060, 5000",
			'geo_ip_enabled' => true,
		);

		$result = Geofence::validate_geolocation( $config );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'ip_fallback_block', $result['details']['reason'] );
		$this->assertSame( 'Service unavailable', $result['details']['error'] );
		$this->assertCount( 1, $requests->calls, 'The lookup asks the IP service exactly once.' );
	}

	/**
	 * Answer `ffc_geolocation_settings` with the given settings and keep every
	 * debug area off, so Debug::is_enabled() never depends on test order.
	 *
	 * @param array<string, mixed> $geo_settings Stored geolocation settings.
	 */
	private function stub_options( array $geo_settings ): void {
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) use ( $geo_settings ) {
				if ( 'ffc_geolocation_settings' === $key ) {
					return $geo_settings;
				}
				if ( 'ffc_settings' === $key ) {
					return array();
				}
				return $default;
			}
		);
	}

	/**
	 * The visitor's address reaches the lookup through REMOTE_ADDR, and the
	 * HTTP request to the IP service fails.
	 *
	 * @return object Holder whose `calls` lists the arguments of every request.
	 */
	private function stub_failing_ip_lookup(): object {
		foreach ( array( 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED' ) as $header ) {
			unset( $_SERVER[ $header ] );
		}
		$_SERVER['REMOTE_ADDR'] = self::VISITOR_IP;
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();

		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);

		$requests        = new \stdClass();
		$requests->calls = array();
		Functions\when( 'wp_remote_get' )->alias(
			static function ( ...$args ) use ( $requests ) {
				$requests->calls[] = $args;
				return new \WP_Error( 'api_failed', 'Service unavailable' );
			}
		);

		return $requests;
	}

	// -------------------------------------------------------------------------
	// has_form_expired_by_days tests
	// -------------------------------------------------------------------------

	/**
	 * When the form has no date_end configured, has_form_expired_by_days returns false.
	 */
	public function test_expired_by_days_false_when_no_end_configured(): void {
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$result = Geofence::has_form_expired_by_days( 1, 7 );

		$this->assertFalse( $result );
	}

	/**
	 * When the form ended well before the grace period, the method returns true.
	 */
	public function test_expired_by_days_true_when_past_grace_period(): void {
		// End date: 30 days ago.  Grace period: 7 days.  30 > 7 so expired.
		$end_timestamp = time() - ( 30 * DAY_IN_SECONDS );
		$date_end      = gmdate( 'Y-m-d', $end_timestamp );

		Functions\when( 'get_post_meta' )->justReturn( array(
			'date_end' => $date_end,
		) );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );

		$result = Geofence::has_form_expired_by_days( 1, 7 );

		$this->assertTrue( $result );
	}

	/**
	 * When the form ended recently (within the grace period), the method returns false.
	 */
	public function test_expired_by_days_false_when_within_grace_period(): void {
		// End date: 3 days ago.  Grace period: 7 days.  3 < 7 so NOT expired.
		$end_timestamp = time() - ( 3 * DAY_IN_SECONDS );
		$date_end      = gmdate( 'Y-m-d', $end_timestamp );

		Functions\when( 'get_post_meta' )->justReturn( array(
			'date_end' => $date_end,
		) );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );

		$result = Geofence::has_form_expired_by_days( 1, 7 );

		$this->assertFalse( $result );
	}

	/**
	 * Negative days value is clamped to zero, meaning the form must have ended
	 * before the current moment (effectively days=0).
	 */
	public function test_expired_by_days_clamps_negative_days_to_zero(): void {
		// End date: 1 day ago with negative grace period => clamped to 0 days.
		// Since end < time() - 0, it should return true.
		$end_timestamp = time() - ( 1 * DAY_IN_SECONDS );
		$date_end      = gmdate( 'Y-m-d', $end_timestamp );

		Functions\when( 'get_post_meta' )->justReturn( array(
			'date_end' => $date_end,
		) );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );

		$result = Geofence::has_form_expired_by_days( 1, -5 );

		$this->assertTrue( $result );
	}
}

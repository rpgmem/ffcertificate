<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Security\Geofence;

/**
 * Tests for Geofence::parse_areas(), validate_geolocation(), and has_form_expired_by_days().
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class GeofenceGeolocationTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
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
     */
    public function test_geolocation_valid_when_within_allowed_areas(): void {
        Functions\when( '__' )->returnArg();

        $ip_geo_mock = Mockery::mock( 'alias:FreeFormCertificate\Integrations\IpGeolocation' );
        $ip_geo_mock->shouldReceive( 'is_within_areas' )
            ->once()
            ->andReturn( true );

        $config        = array(
            'geo_areas'      => "40.7128, -74.0060, 5000",
            'geo_ip_enabled' => false,
        );
        $user_location = array( 'latitude' => 40.7128, 'longitude' => -74.0060 );

        $result = Geofence::validate_geolocation( $config, $user_location );

        $this->assertTrue( $result['valid'] );
        $this->assertSame( $user_location, $result['details']['user_location'] );
    }

    /**
     * User location outside all allowed areas returns invalid.
     */
    public function test_geolocation_invalid_when_outside_allowed_areas(): void {
        Functions\when( '__' )->returnArg();

        $ip_geo_mock = Mockery::mock( 'alias:FreeFormCertificate\Integrations\IpGeolocation' );
        $ip_geo_mock->shouldReceive( 'is_within_areas' )
            ->once()
            ->andReturn( false );

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
     * instead of the default geo_areas for the within-areas check.
     */
    public function test_geolocation_uses_permissive_ip_areas_when_configured(): void {
        Functions\when( '__' )->returnArg();

        $ip_geo_mock = Mockery::mock( 'alias:FreeFormCertificate\Integrations\IpGeolocation' );
        $ip_geo_mock->shouldReceive( 'is_within_areas' )
            ->once()
            ->withArgs( function ( $location, $areas, $logic ) {
                // Should receive the permissive IP areas (2 entries), not the default geo_areas (1 entry).
                return count( $areas ) === 2 && $logic === 'or';
            } )
            ->andReturn( true );

        $config = array(
            'geo_areas'              => "40.7128, -74.0060, 5000",
            'geo_ip_enabled'         => true,
            'geo_ip_areas_permissive' => true,
            'geo_ip_areas'           => "40.7128, -74.0060, 50000\n34.0522, -118.2437, 50000",
        );

        $user_location = array( 'latitude' => 40.7128, 'longitude' => -74.0060 );

        $result = Geofence::validate_geolocation( $config, $user_location );

        $this->assertTrue( $result['valid'] );
    }

    /**
     * When IP geolocation returns a WP_Error and the fallback is 'allow',
     * the result should be valid with reason 'ip_fallback_allow'.
     */
    public function test_geolocation_falls_back_on_ip_error_allow(): void {
        Functions\when( '__' )->returnArg();
        // Debug::log_geofence() is a no-op in test context (class may or may not be loaded).

        $wp_error = new \WP_Error( 'api_failed', 'Service unavailable' );

        $ip_geo_mock = Mockery::mock( 'alias:FreeFormCertificate\Integrations\IpGeolocation' );
        $ip_geo_mock->shouldReceive( 'get_location' )
            ->once()
            ->andReturn( $wp_error );

        Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
            return $thing instanceof \WP_Error;
        } );
        Functions\when( 'get_option' )->alias( function ( $key, $default = array() ) {
            if ( $key === 'ffc_geolocation_settings' ) {
                return array( 'api_fallback' => 'allow' );
            }
            return $default;
        } );

        $config = array(
            'geo_areas'      => "40.7128, -74.0060, 5000",
            'geo_ip_enabled' => true,
        );

        $result = Geofence::validate_geolocation( $config );

        $this->assertTrue( $result['valid'] );
        $this->assertSame( 'ip_fallback_allow', $result['details']['reason'] );
    }

    /**
     * When IP geolocation returns a WP_Error and the fallback is 'block',
     * the result should be invalid with reason 'ip_fallback_block'.
     */
    public function test_geolocation_falls_back_on_ip_error_block(): void {
        Functions\when( '__' )->returnArg();
        // Debug::log_geofence() is a no-op in test context (class may or may not be loaded).

        $wp_error = new \WP_Error( 'api_failed', 'Service unavailable' );

        $ip_geo_mock = Mockery::mock( 'alias:FreeFormCertificate\Integrations\IpGeolocation' );
        $ip_geo_mock->shouldReceive( 'get_location' )
            ->once()
            ->andReturn( $wp_error );

        Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
            return $thing instanceof \WP_Error;
        } );
        Functions\when( 'get_option' )->alias( function ( $key, $default = array() ) {
            if ( $key === 'ffc_geolocation_settings' ) {
                return array( 'api_fallback' => 'block' );
            }
            return $default;
        } );

        $config = array(
            'geo_areas'      => "40.7128, -74.0060, 5000",
            'geo_ip_enabled' => true,
        );

        $result = Geofence::validate_geolocation( $config );

        $this->assertFalse( $result['valid'] );
        $this->assertSame( 'ip_fallback_block', $result['details']['reason'] );
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

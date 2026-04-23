<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Security\Geofence;

/**
 * Tests for Geofence::get_form_config() and Geofence::get_frontend_config().
 */
class GeofenceFrontendConfigTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // get_form_config() tests
    // ---------------------------------------------------------------

    /**
     * Empty string from get_post_meta should return null.
     */
    public function test_get_form_config_returns_null_for_empty_meta(): void {
        Functions\when( 'get_post_meta' )->justReturn( '' );

        $result = Geofence::get_form_config( 42 );

        $this->assertNull( $result );
    }

    /**
     * Non-array value from get_post_meta should return null.
     */
    public function test_get_form_config_returns_null_for_non_array_meta(): void {
        Functions\when( 'get_post_meta' )->justReturn( 'some_string' );

        $result = Geofence::get_form_config( 42 );

        $this->assertNull( $result );
    }

    /**
     * Boolean fields should be cast: '1' → true, '' → false.
     */
    public function test_get_form_config_casts_boolean_fields(): void {
        Functions\when( 'get_post_meta' )->justReturn( array(
            'datetime_enabled'        => '1',
            'geo_enabled'             => '1',
            'geo_gps_enabled'         => '',
            'geo_ip_enabled'          => '',
            'geo_ip_areas_permissive' => '1',
        ) );

        $result = Geofence::get_form_config( 10 );

        $this->assertIsArray( $result );
        $this->assertTrue( $result['datetime_enabled'] );
        $this->assertTrue( $result['geo_enabled'] );
        $this->assertFalse( $result['geo_gps_enabled'] );
        $this->assertFalse( $result['geo_ip_enabled'] );
        $this->assertTrue( $result['geo_ip_areas_permissive'] );
    }

    /**
     * Non-boolean fields should pass through unchanged.
     */
    public function test_get_form_config_preserves_other_fields(): void {
        Functions\when( 'get_post_meta' )->justReturn( array(
            'datetime_enabled'        => '1',
            'geo_enabled'             => '',
            'geo_gps_enabled'         => '',
            'geo_ip_enabled'          => '',
            'geo_ip_areas_permissive' => '',
            'date_start'              => '2026-01-01',
            'date_end'                => '2026-12-31',
            'time_start'              => '08:00',
            'time_end'                => '17:00',
            'time_mode'               => 'span',
            'msg_datetime'            => 'Custom message',
            'geo_areas'               => '40.0,-74.0,500',
        ) );

        $result = Geofence::get_form_config( 10 );

        $this->assertSame( '2026-01-01', $result['date_start'] );
        $this->assertSame( '2026-12-31', $result['date_end'] );
        $this->assertSame( '08:00', $result['time_start'] );
        $this->assertSame( '17:00', $result['time_end'] );
        $this->assertSame( 'span', $result['time_mode'] );
        $this->assertSame( 'Custom message', $result['msg_datetime'] );
        $this->assertSame( '40.0,-74.0,500', $result['geo_areas'] );
    }

    // ---------------------------------------------------------------
    // get_frontend_config() tests
    // ---------------------------------------------------------------

    /**
     * When there is no geofence config, return null.
     */
    public function test_frontend_config_returns_null_when_no_config(): void {
        Functions\when( 'get_post_meta' )->justReturn( '' );

        $result = Geofence::get_frontend_config( 5 );

        $this->assertNull( $result );
    }

    /**
     * Full admin bypass: both datetime and geo bypassed.
     * Should return adminBypass = true with datetime/geo disabled.
     */
    public function test_frontend_config_full_admin_bypass(): void {
        Functions\when( 'get_post_meta' )->justReturn( array(
            'datetime_enabled'        => '1',
            'geo_enabled'             => '1',
            'geo_gps_enabled'         => '1',
            'geo_ip_enabled'          => '1',
            'geo_ip_areas_permissive' => '',
        ) );

        // Admin with both bypasses.
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_option' )->alias( function ( $key, $default = array() ) {
            if ( 'ffc_geolocation_settings' === $key ) {
                return array(
                    'admin_bypass_datetime' => true,
                    'admin_bypass_geo'      => true,
                );
            }
            if ( 'ffc_settings' === $key ) {
                return array( 'debug_geofence' => 1 );
            }
            return $default;
        } );

        $result = Geofence::get_frontend_config( 7 );

        $this->assertIsArray( $result );
        $this->assertSame( 7, $result['formId'] );
        $this->assertTrue( $result['adminBypass'] );

        $this->assertTrue( $result['bypassInfo']['hasDatetime'] );
        $this->assertTrue( $result['bypassInfo']['hasGeo'] );

        $this->assertFalse( $result['datetime']['enabled'] );
        $this->assertFalse( $result['geo']['enabled'] );
        $this->assertTrue( $result['global']['debug'] );
    }

    /**
     * Regular (non-logged-in) user: no bypass, full config returned with
     * datetime and geo enabled.
     */
    public function test_frontend_config_no_bypass_regular_user(): void {
        Functions\when( 'get_post_meta' )->justReturn( array(
            'datetime_enabled'        => '1',
            'geo_enabled'             => '1',
            'geo_gps_enabled'         => '1',
            'geo_ip_enabled'          => '1',
            'geo_ip_areas_permissive' => '',
            'date_start'              => '2026-01-01',
            'date_end'                => '2026-12-31',
            'time_start'              => '09:00',
            'time_end'                => '17:00',
            'time_mode'               => 'daily',
            'msg_datetime'            => 'Not available now.',
            'datetime_hide_mode'      => 'hide',
            'geo_areas'               => "40.7128,-74.0060,1000\n34.0522,-118.2437,2000",
            'geo_gps_ip_logic'        => 'and',
            'msg_geo_blocked'         => 'Blocked by location.',
            'msg_geo_error'           => 'Location error.',
            'geo_hide_mode'           => 'message',
        ) );

        // Not logged in → no bypass.
        Functions\when( 'is_user_logged_in' )->justReturn( false );
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'get_option' )->alias( function ( $key, $default = array() ) {
            if ( 'ffc_geolocation_settings' === $key ) {
                return array(
                    'gps_cache_ttl' => 300,
                    'gps_fallback'  => 'block',
                );
            }
            return $default;
        } );
        Functions\when( 'absint' )->alias( function ( $v ) {
            return abs( (int) $v );
        } );

        $result = Geofence::get_frontend_config( 3 );

        $this->assertIsArray( $result );
        $this->assertSame( 3, $result['formId'] );
        $this->assertFalse( $result['adminBypass'] );
        $this->assertNull( $result['bypassInfo'] );

        $this->assertTrue( $result['datetime']['enabled'] );
        $this->assertSame( '2026-01-01', $result['datetime']['dateStart'] );
        $this->assertSame( '2026-12-31', $result['datetime']['dateEnd'] );
        $this->assertSame( '09:00', $result['datetime']['timeStart'] );
        $this->assertSame( '17:00', $result['datetime']['timeEnd'] );
        $this->assertSame( 'daily', $result['datetime']['timeMode'] );
        $this->assertSame( 'Not available now.', $result['datetime']['message'] );
        $this->assertSame( 'hide', $result['datetime']['hideMode'] );

        $this->assertTrue( $result['geo']['enabled'] );
        $this->assertTrue( $result['geo']['gpsEnabled'] );
        $this->assertTrue( $result['geo']['ipEnabled'] );
        $this->assertSame( 'and', $result['geo']['gpsIpLogic'] );
        $this->assertSame( 'Blocked by location.', $result['geo']['messageBlocked'] );
        $this->assertSame( 'Location error.', $result['geo']['messageError'] );
        $this->assertSame( 'message', $result['geo']['hideMode'] );
        $this->assertSame( 'block', $result['geo']['gpsFallback'] );
        $this->assertTrue( $result['geo']['cacheEnabled'] );
        $this->assertSame( 300, $result['geo']['cacheTtl'] );

        // Areas parsed.
        $this->assertCount( 2, $result['geo']['areas'] );
        $this->assertSame( 40.7128, $result['geo']['areas'][0]['lat'] );

        // Global.
        $this->assertFalse( $result['global']['debug'] );
    }

    /**
     * Partial bypass: datetime bypassed, geo not bypassed.
     */
    public function test_frontend_config_partial_bypass_datetime_only(): void {
        Functions\when( 'get_post_meta' )->justReturn( array(
            'datetime_enabled'        => '1',
            'geo_enabled'             => '1',
            'geo_gps_enabled'         => '1',
            'geo_ip_enabled'          => '',
            'geo_ip_areas_permissive' => '',
            'date_start'              => '2026-03-01',
            'date_end'                => '2026-03-31',
            'time_start'              => '08:00',
            'time_end'                => '18:00',
            'time_mode'               => 'daily',
            'geo_areas'               => '51.5074,-0.1278,5000',
            'geo_gps_ip_logic'        => 'or',
            'msg_geo_blocked'         => '',
            'msg_geo_error'           => '',
            'geo_hide_mode'           => 'hide',
        ) );

        // Admin with datetime bypass only.
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_option' )->alias( function ( $key, $default = array() ) {
            if ( 'ffc_geolocation_settings' === $key ) {
                return array(
                    'admin_bypass_datetime' => true,
                    'admin_bypass_geo'      => false,
                    'gps_cache_ttl'         => 900,
                    'gps_fallback'          => 'allow',
                );
            }
            return $default;
        } );
        Functions\when( 'absint' )->alias( function ( $v ) {
            return abs( (int) $v );
        } );

        $result = Geofence::get_frontend_config( 15 );

        $this->assertIsArray( $result );
        $this->assertSame( 15, $result['formId'] );

        // Partial bypass: adminBypass should be true.
        $this->assertTrue( $result['adminBypass'] );

        // bypassInfo should exist and indicate datetime bypassed.
        $this->assertIsArray( $result['bypassInfo'] );

        // Datetime should be disabled due to bypass.
        $this->assertFalse( $result['datetime']['enabled'] );

        $this->assertTrue( $result['geo']['enabled'] );

        $this->assertSame( 'allow', $result['geo']['gpsFallback'] );
        $this->assertSame( 900, $result['geo']['cacheTtl'] );
        $this->assertFalse( $result['global']['debug'] );
    }

    /**
     * Custom gps_cache_ttl from global settings is used.
     */
    public function test_frontend_config_uses_custom_cache_ttl(): void {
        Functions\when( 'get_post_meta' )->justReturn( array(
            'datetime_enabled'        => '',
            'geo_enabled'             => '1',
            'geo_gps_enabled'         => '1',
            'geo_ip_enabled'          => '',
            'geo_ip_areas_permissive' => '',
            'geo_areas'               => '48.8566,2.3522,3000',
        ) );

        Functions\when( 'is_user_logged_in' )->justReturn( false );
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'get_option' )->alias( function ( $key, $default = array() ) {
            if ( 'ffc_geolocation_settings' === $key ) {
                return array(
                    'gps_cache_ttl' => 1200,
                    'gps_fallback'  => 'allow',
                );
            }
            return $default;
        } );
        Functions\when( 'absint' )->alias( function ( $v ) {
            return abs( (int) $v );
        } );

        $result = Geofence::get_frontend_config( 20 );

        $this->assertIsArray( $result );
        $this->assertSame( 1200, $result['geo']['cacheTtl'] );
    }

    /**
     * When gps_cache_ttl is not set in settings, default to 600.
     */
    public function test_frontend_config_defaults_cache_ttl_to_600(): void {
        Functions\when( 'get_post_meta' )->justReturn( array(
            'datetime_enabled'        => '',
            'geo_enabled'             => '1',
            'geo_gps_enabled'         => '',
            'geo_ip_enabled'          => '',
            'geo_ip_areas_permissive' => '',
        ) );

        Functions\when( 'is_user_logged_in' )->justReturn( false );
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'get_option' )->alias( function ( $key, $default = array() ) {
            if ( 'ffc_geolocation_settings' === $key ) {
                // No gps_cache_ttl key at all.
                return array(
                    'gps_fallback'  => 'allow',
                );
            }
            return $default;
        } );
        Functions\when( 'absint' )->alias( function ( $v ) {
            return abs( (int) $v );
        } );

        $result = Geofence::get_frontend_config( 25 );

        $this->assertIsArray( $result );
        $this->assertSame( 600, $result['geo']['cacheTtl'] );
    }
}

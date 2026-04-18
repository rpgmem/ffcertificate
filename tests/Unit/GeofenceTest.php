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
 * Tests for Geofence: area parsing, timestamp helpers, config normalization, expiration checks.
 */
class GeofenceTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'wp_timezone' )->alias( function () {
            return new \DateTimeZone( 'UTC' );
        });
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        Functions\when( 'apply_filters' )->alias( function ( $hook, $value ) { return $value; } );

        if ( ! defined( 'DAY_IN_SECONDS' ) ) {
            define( 'DAY_IN_SECONDS', 86400 );
        }
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // parse_areas
    // ------------------------------------------------------------------

    public function test_parse_areas_empty_string_returns_empty(): void {
        $this->assertSame( array(), Geofence::parse_areas( '' ) );
    }

    public function test_parse_areas_parses_valid_lines(): void {
        $input  = "40.7128, -74.0060, 5000\n34.0522, -118.2437, 10000";
        $result = Geofence::parse_areas( $input );

        $this->assertCount( 2, $result );
        $this->assertSame( 40.7128, $result[0]['lat'] );
        $this->assertSame( -74.0060, $result[0]['lng'] );
        $this->assertSame( 5000.0, $result[0]['radius'] );
    }

    public function test_parse_areas_skips_invalid_lines(): void {
        $input  = "40.7128, -74.0060, 5000\ninvalid line\n34.0522, -118.2437, 10000";
        $result = Geofence::parse_areas( $input );

        $this->assertCount( 2, $result );
    }

    public function test_parse_areas_rejects_out_of_range_coordinates(): void {
        $input  = "200, -74, 5000\n40, -200, 5000\n40, 74, -100";
        $result = Geofence::parse_areas( $input );

        $this->assertSame( array(), $result );
    }

    public function test_parse_areas_skips_blank_lines(): void {
        $input  = "\n40.0, -74.0, 5000\n\n\n34.0, -118.0, 10000\n";
        $result = Geofence::parse_areas( $input );

        $this->assertCount( 2, $result );
    }

    // ------------------------------------------------------------------
    // get_form_end_timestamp
    // ------------------------------------------------------------------

    public function test_get_form_end_timestamp_returns_null_when_no_config(): void {
        Functions\when( 'get_post_meta' )->justReturn( '' );

        $this->assertNull( Geofence::get_form_end_timestamp( 42 ) );
    }

    public function test_get_form_end_timestamp_returns_null_when_date_end_missing(): void {
        Functions\when( 'get_post_meta' )->justReturn( array( 'datetime_enabled' => 1 ) );

        $this->assertNull( Geofence::get_form_end_timestamp( 42 ) );
    }

    public function test_get_form_end_timestamp_uses_time_end_when_set(): void {
        Functions\when( 'get_post_meta' )->justReturn(
            array(
                'date_end' => '2030-01-15',
                'time_end' => '18:30:00',
            )
        );

        $expected = ( new \DateTimeImmutable( '2030-01-15 18:30:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
        $this->assertSame( $expected, Geofence::get_form_end_timestamp( 42 ) );
    }

    public function test_get_form_end_timestamp_defaults_time_to_end_of_day(): void {
        Functions\when( 'get_post_meta' )->justReturn( array( 'date_end' => '2030-01-15' ) );

        $expected = ( new \DateTimeImmutable( '2030-01-15 23:59:59', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
        $this->assertSame( $expected, Geofence::get_form_end_timestamp( 42 ) );
    }

    // ------------------------------------------------------------------
    // get_form_start_timestamp
    // ------------------------------------------------------------------

    public function test_get_form_start_timestamp_returns_null_when_no_config(): void {
        Functions\when( 'get_post_meta' )->justReturn( '' );

        $this->assertNull( Geofence::get_form_start_timestamp( 42 ) );
    }

    public function test_get_form_start_timestamp_uses_time_start(): void {
        Functions\when( 'get_post_meta' )->justReturn(
            array(
                'date_start' => '2025-01-01',
                'time_start' => '09:00:00',
            )
        );

        $expected = ( new \DateTimeImmutable( '2025-01-01 09:00:00', new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
        $this->assertSame( $expected, Geofence::get_form_start_timestamp( 42 ) );
    }

    // ------------------------------------------------------------------
    // has_form_expired
    // ------------------------------------------------------------------

    public function test_has_form_expired_returns_false_when_no_end_timestamp(): void {
        Functions\when( 'get_post_meta' )->justReturn( '' );

        $this->assertFalse( Geofence::has_form_expired( 42 ) );
    }

    public function test_has_form_expired_returns_false_when_future_date(): void {
        Functions\when( 'get_post_meta' )->justReturn(
            array(
                'date_end' => gmdate( 'Y-m-d', time() + 86400 ),
                'time_end' => '23:59:59',
            )
        );

        $this->assertFalse( Geofence::has_form_expired( 42 ) );
    }

    public function test_has_form_expired_returns_true_when_past_date(): void {
        Functions\when( 'get_post_meta' )->justReturn(
            array(
                'date_end' => '2000-01-01',
                'time_end' => '00:00:00',
            )
        );

        $this->assertTrue( Geofence::has_form_expired( 42 ) );
    }

    // ------------------------------------------------------------------
    // has_form_expired_by_days
    // ------------------------------------------------------------------

    public function test_has_form_expired_by_days_returns_false_when_within_grace(): void {
        // Ended 2 days ago, asking if expired by 5 days grace.
        $ended_at = time() - ( 2 * 86400 );
        Functions\when( 'get_post_meta' )->justReturn(
            array(
                'date_end' => gmdate( 'Y-m-d', $ended_at ),
                'time_end' => gmdate( 'H:i:s', $ended_at ),
            )
        );

        $this->assertFalse( Geofence::has_form_expired_by_days( 42, 5 ) );
    }

    public function test_has_form_expired_by_days_returns_true_when_past_grace(): void {
        // Ended 10 days ago, asking if expired by 5 days grace.
        $ended_at = time() - ( 10 * 86400 );
        Functions\when( 'get_post_meta' )->justReturn(
            array(
                'date_end' => gmdate( 'Y-m-d', $ended_at ),
                'time_end' => gmdate( 'H:i:s', $ended_at ),
            )
        );

        $this->assertTrue( Geofence::has_form_expired_by_days( 42, 5 ) );
    }

    // ------------------------------------------------------------------
    // get_form_config
    // ------------------------------------------------------------------

    public function test_get_form_config_returns_null_when_empty(): void {
        Functions\when( 'get_post_meta' )->justReturn( '' );

        $this->assertNull( Geofence::get_form_config( 42 ) );
    }

    public function test_get_form_config_normalizes_boolean_flags(): void {
        Functions\when( 'get_post_meta' )->justReturn(
            array(
                'datetime_enabled'        => '1',
                'geo_enabled'             => 1,
                'geo_gps_enabled'         => true,
                'geo_ip_enabled'          => 0,
                'geo_ip_areas_permissive' => false,
            )
        );

        $result = Geofence::get_form_config( 42 );

        $this->assertTrue( $result['datetime_enabled'] );
        $this->assertTrue( $result['geo_enabled'] );
        $this->assertTrue( $result['geo_gps_enabled'] );
        $this->assertFalse( $result['geo_ip_enabled'] );
        $this->assertFalse( $result['geo_ip_areas_permissive'] );
    }

    // ------------------------------------------------------------------
    // should_bypass_datetime / should_bypass_geo
    // ------------------------------------------------------------------

    public function test_should_bypass_datetime_false_for_non_admin(): void {
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'apply_filters' )->alias( function ( $hook, $value ) { return $value; } );

        $this->assertFalse( Geofence::should_bypass_datetime() );
    }

    public function test_should_bypass_datetime_true_for_admin_when_setting_enabled(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_option' )->justReturn( array( 'admin_bypass_datetime' => 1 ) );

        $this->assertTrue( Geofence::should_bypass_datetime() );
    }

    public function test_should_bypass_datetime_false_for_admin_when_setting_disabled(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_option' )->justReturn( array() );

        $this->assertFalse( Geofence::should_bypass_datetime() );
    }

    public function test_should_bypass_geo_true_for_admin_when_setting_enabled(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_option' )->justReturn( array( 'admin_bypass_geo' => 1 ) );

        $this->assertTrue( Geofence::should_bypass_geo() );
    }
}

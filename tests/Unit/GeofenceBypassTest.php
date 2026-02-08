<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Security\Geofence;

/**
 * Tests for Geofence::can_access_form() bypass logic.
 */
class GeofenceBypassTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * No restrictions configured → always allowed.
     */
    public function test_no_restrictions_allows_access(): void {
        Functions\when( 'get_post_meta' )->justReturn( '' );
        Functions\when( 'wp_parse_args' )->alias( 'array_merge' );

        $result = Geofence::can_access_form( 1 );

        $this->assertTrue( $result['allowed'] );
        $this->assertSame( 'no_restrictions', $result['reason'] );
    }

    /**
     * Datetime restriction active + admin bypass enabled → allowed.
     */
    public function test_admin_bypass_datetime_skips_validation(): void {
        // Config: datetime enabled, past date range (would fail validation)
        Functions\when( 'get_post_meta' )->justReturn( array(
            'datetime_enabled' => '1',
            'geo_enabled'      => '0',
            'date_start'       => '2020-01-01',
            'date_end'         => '2020-01-02',
            'time_start'       => '08:00',
            'time_end'         => '17:00',
            'time_mode'        => 'daily',
        ) );
        Functions\when( 'wp_parse_args' )->alias( 'array_merge' );

        // Admin bypass enabled
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_option' )->alias( function( $key, $default = array() ) {
            if ( $key === 'ffc_geolocation_settings' ) {
                return array( 'admin_bypass_datetime' => true, 'admin_bypass_geo' => false );
            }
            return $default;
        } );

        $result = Geofence::can_access_form( 1, array( 'check_datetime' => true, 'check_geo' => false ) );

        $this->assertTrue( $result['allowed'] );
    }

    /**
     * Geo restriction active + admin bypass enabled → allowed.
     */
    public function test_admin_bypass_geo_skips_validation(): void {
        Functions\when( 'get_post_meta' )->justReturn( array(
            'datetime_enabled' => '0',
            'geo_enabled'      => '1',
        ) );
        Functions\when( 'wp_parse_args' )->alias( 'array_merge' );
        Functions\when( 'is_user_logged_in' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_option' )->alias( function( $key, $default = array() ) {
            if ( $key === 'ffc_geolocation_settings' ) {
                return array( 'admin_bypass_datetime' => false, 'admin_bypass_geo' => true );
            }
            return $default;
        } );

        $result = Geofence::can_access_form( 1, array( 'check_datetime' => false, 'check_geo' => true ) );

        $this->assertTrue( $result['allowed'] );
    }

    /**
     * Bypass disabled for non-admin user → validation still runs.
     */
    public function test_non_admin_cannot_bypass(): void {
        Functions\when( 'get_post_meta' )->justReturn( array(
            'datetime_enabled' => '1',
            'geo_enabled'      => '0',
            'date_start'       => '2020-01-01',
            'date_end'         => '2020-01-02',
            'time_start'       => '08:00',
            'time_end'         => '17:00',
            'time_mode'        => 'daily',
            'msg_datetime'     => 'Not available.',
        ) );
        Functions\when( 'wp_parse_args' )->alias( 'array_merge' );
        Functions\when( 'is_user_logged_in' )->justReturn( false );
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'wp_date' )->alias( function( $format ) {
            // Return a date outside the config range
            if ( $format === 'Y-m-d' ) return '2026-02-08';
            if ( $format === 'H:i' ) return '12:00';
            return '';
        } );
        Functions\when( '__' )->returnArg();

        $result = Geofence::can_access_form( 1, array( 'check_datetime' => true ) );

        $this->assertFalse( $result['allowed'] );
        $this->assertSame( 'datetime_invalid', $result['reason'] );
    }
}

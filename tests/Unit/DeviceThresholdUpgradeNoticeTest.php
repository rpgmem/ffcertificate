<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\DeviceThresholdUpgradeNotice;
use FreeFormCertificate\Security\RateLimiter;

/**
 * Gating tests for the v6.3.2 device-threshold upgrade notice.
 *
 * @covers \FreeFormCertificate\Admin\DeviceThresholdUpgradeNotice
 */
class DeviceThresholdUpgradeNoticeTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Reset RateLimiter's static settings cache between tests so each
        // test's get_option() stub is read fresh.
        $ref   = new \ReflectionClass( RateLimiter::class );
        $cache = $ref->getProperty( 'settings_cache' );
        $cache->setAccessible( true );
        $cache->setValue( null );

        Functions\when( '__' )->returnArg();
        Functions\when( '_n' )->returnArg();
        Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults ) {
            return array_merge( $defaults, is_array( $args ) ? $args : array() );
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function rendered_output(): string {
        ob_start();
        DeviceThresholdUpgradeNotice::maybe_render();
        return (string) ob_get_clean();
    }

    public function test_does_not_render_when_dismissed(): void {
        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
            if ( DeviceThresholdUpgradeNotice::OPTION_DISMISSED === $key ) {
                return '1';
            }
            return $default;
        } );
        Functions\when( 'current_user_can' )->justReturn( true );

        $this->assertSame( '', $this->rendered_output() );
    }

    public function test_does_not_render_when_user_cannot_manage(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'current_user_can' )->justReturn( false );

        $this->assertSame( '', $this->rendered_output() );
    }

    public function test_does_not_render_when_device_subsystem_disabled(): void {
        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
            if ( DeviceThresholdUpgradeNotice::OPTION_DISMISSED === $key ) {
                return '';
            }
            if ( 'ffc_rate_limit_settings' === $key ) {
                return array(
                    'device' => array( 'enabled' => false, 'match_threshold' => 5 ),
                );
            }
            return $default;
        } );
        Functions\when( 'current_user_can' )->justReturn( true );

        $this->assertSame( '', $this->rendered_output() );
    }

    public function test_does_not_render_when_threshold_already_bumped(): void {
        // Site already moved off the legacy default of 5 — leave them alone.
        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
            if ( DeviceThresholdUpgradeNotice::OPTION_DISMISSED === $key ) {
                return '';
            }
            if ( 'ffc_rate_limit_settings' === $key ) {
                return array(
                    'device' => array( 'enabled' => true, 'match_threshold' => 7 ),
                );
            }
            return $default;
        } );
        Functions\when( 'current_user_can' )->justReturn( true );

        $this->assertSame( '', $this->rendered_output() );
    }

    public function test_renders_when_legacy_default_still_active(): void {
        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
            if ( DeviceThresholdUpgradeNotice::OPTION_DISMISSED === $key ) {
                return '';
            }
            if ( 'ffc_rate_limit_settings' === $key ) {
                return array(
                    'device' => array( 'enabled' => true, 'match_threshold' => 5 ),
                );
            }
            return $default;
        } );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'admin_url' )->returnArg();
        Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'wp_kses' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );

        $output = $this->rendered_output();

        $this->assertStringContainsString( 'ffc-device-threshold-notice', $output );
        $this->assertStringContainsString( 'is-dismissible', $output );
    }
}

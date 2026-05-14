<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\SettingsAjaxEndpoint;

/**
 * Tests for the generic admin settings AJAX endpoint introduced in 6.5.4.
 *
 * @covers \FreeFormCertificate\Admin\SettingsAjaxEndpoint
 */
class SettingsAjaxEndpointTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_key' )->alias( function ( $key ) {
            return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
        } );
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );

        // Make wp_send_json_* throw so tests can detect early-exit branches.
        Functions\when( 'wp_send_json_error' )->alias( function ( $data = null ) {
            $msg = is_array( $data ) && isset( $data['message'] ) ? $data['message'] : 'error';
            throw new \RuntimeException( 'json_error: ' . $msg );
        } );
        Functions\when( 'wp_send_json_success' )->alias( function ( $data = null ) {
            throw new \RuntimeException( 'json_success: ' . wp_json_encode( $data ) );
        } );
        // wp_json_encode shim — Brain Monkey doesn't ship one.
        if ( ! function_exists( 'wp_json_encode' ) ) {
            // phpcs:ignore Generic.PHP.LowerCaseConstant
            eval( 'function wp_json_encode( $data ) { return json_encode( $data ); }' );
        }
    }

    protected function tearDown(): void {
        unset( $_POST['key'], $_POST['value'], $_POST['nonce'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // Allowlist
    // ==================================================================

    public function test_allowlist_includes_the_two_admin_bypass_keys(): void {
        $list = SettingsAjaxEndpoint::allowlist();
        $this->assertArrayHasKey( 'admin_bypass_datetime', $list );
        $this->assertArrayHasKey( 'admin_bypass_geo', $list );
        $this->assertSame( 'ffc_geolocation_settings', $list['admin_bypass_datetime']['option'] );
        $this->assertSame( 'bool', $list['admin_bypass_datetime']['type'] );
        $this->assertSame( 'manage_options', $list['admin_bypass_datetime']['cap'] );
    }

    // ==================================================================
    // sanitize_value
    // ==================================================================

    public function test_sanitize_value_bool_truthy_strings(): void {
        $this->assertTrue( SettingsAjaxEndpoint::sanitize_value( '1', 'bool' ) );
        $this->assertTrue( SettingsAjaxEndpoint::sanitize_value( 'true', 'bool' ) );
        $this->assertTrue( SettingsAjaxEndpoint::sanitize_value( 'on', 'bool' ) );
        $this->assertTrue( SettingsAjaxEndpoint::sanitize_value( 'yes', 'bool' ) );
    }

    public function test_sanitize_value_bool_falsy_strings(): void {
        $this->assertFalse( SettingsAjaxEndpoint::sanitize_value( '0', 'bool' ) );
        $this->assertFalse( SettingsAjaxEndpoint::sanitize_value( '', 'bool' ) );
        $this->assertFalse( SettingsAjaxEndpoint::sanitize_value( 'false', 'bool' ) );
        $this->assertFalse( SettingsAjaxEndpoint::sanitize_value( 'random', 'bool' ) );
    }

    public function test_sanitize_value_bool_rejects_arrays(): void {
        $this->assertFalse( SettingsAjaxEndpoint::sanitize_value( array( 'on' ), 'bool' ) );
    }

    public function test_sanitize_value_unknown_type_returns_null(): void {
        $this->assertNull( SettingsAjaxEndpoint::sanitize_value( '1', 'unknown' ) );
    }

    // ==================================================================
    // handle() — guards
    // ==================================================================

    public function test_handle_rejects_missing_key(): void {
        $_POST = array( 'nonce' => 'x', 'value' => '1' );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'Missing setting key.' );
        SettingsAjaxEndpoint::handle();
    }

    public function test_handle_rejects_unknown_key(): void {
        $_POST = array( 'nonce' => 'x', 'key' => 'not_allowed', 'value' => '1' );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'not exposed for incremental updates' );
        SettingsAjaxEndpoint::handle();
    }

    public function test_handle_rejects_without_capability(): void {
        Functions\when( 'current_user_can' )->justReturn( false );
        $_POST = array( 'nonce' => 'x', 'key' => 'admin_bypass_geo', 'value' => '1' );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'do not have permission' );
        SettingsAjaxEndpoint::handle();
    }

    // ==================================================================
    // handle() — happy path
    // ==================================================================

    public function test_handle_persists_truthy_value_into_option_array(): void {
        $captured_option = null;
        Functions\when( 'get_option' )->justReturn( array( 'unrelated' => 'preserved' ) );
        Functions\when( 'update_option' )->alias( function ( $key, $value ) use ( &$captured_option ) {
            if ( 'ffc_geolocation_settings' === $key ) {
                $captured_option = $value;
            }
            return true;
        } );

        $_POST = array(
            'nonce' => 'x',
            'key'   => 'admin_bypass_geo',
            'value' => 'true',
        );

        try {
            SettingsAjaxEndpoint::handle();
        } catch ( \RuntimeException $e ) {
            $this->assertStringStartsWith( 'json_success', $e->getMessage() );
        }

        $this->assertSame( 'preserved', $captured_option['unrelated'] );
        $this->assertTrue( $captured_option['admin_bypass_geo'] );
    }

    public function test_handle_treats_non_array_existing_option_as_empty(): void {
        $captured_option = null;
        Functions\when( 'get_option' )->justReturn( 'not_an_array' );
        Functions\when( 'update_option' )->alias( function ( $key, $value ) use ( &$captured_option ) {
            $captured_option = $value;
            return true;
        } );

        $_POST = array( 'nonce' => 'x', 'key' => 'admin_bypass_datetime', 'value' => '1' );

        try {
            SettingsAjaxEndpoint::handle();
        } catch ( \RuntimeException $e ) {
            // expected
        }

        $this->assertTrue( $captured_option['admin_bypass_datetime'] );
        $this->assertCount( 1, $captured_option );
    }
}

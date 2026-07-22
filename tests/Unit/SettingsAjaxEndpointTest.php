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
        $this->assertSame( 'ffc_manage_settings', $list['admin_bypass_datetime']['cap'] );
    }

    public function test_email_kill_switch_toggles_require_dangerzone(): void {
        // #739 §4.4 — both the inverted "enable emails" toggle and the raw
        // kill-switch slot carry the dangerzone sub-cap.
        $list = SettingsAjaxEndpoint::allowlist();
        $this->assertSame( 'ffc_manage_settings_dangerzone', $list['emails_enabled']['cap'] );
        $this->assertSame( 'ffc_manage_settings_dangerzone', $list['disable_all_emails']['cap'] );
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
    // sanitize_value — int
    // ==================================================================

    public function test_sanitize_value_int_casts_numeric_strings(): void {
        $this->assertSame( 42, SettingsAjaxEndpoint::sanitize_value( '42', 'int' ) );
        $this->assertSame( 0, SettingsAjaxEndpoint::sanitize_value( '', 'int' ) );
        $this->assertSame( 0, SettingsAjaxEndpoint::sanitize_value( 'not a number', 'int' ) );
    }

    public function test_sanitize_value_int_rejects_arrays(): void {
        $this->assertSame( 0, SettingsAjaxEndpoint::sanitize_value( array( 5 ), 'int' ) );
    }

    public function test_sanitize_value_int_clamps_to_min(): void {
        $this->assertSame( 10, SettingsAjaxEndpoint::sanitize_value( '5', 'int', array( 'min' => 10 ) ) );
    }

    public function test_sanitize_value_int_clamps_to_max(): void {
        $this->assertSame( 100, SettingsAjaxEndpoint::sanitize_value( '999', 'int', array( 'max' => 100 ) ) );
    }

    public function test_sanitize_value_int_clamps_both_sides(): void {
        $entry = array( 'min' => 1, 'max' => 10 );
        $this->assertSame( 1, SettingsAjaxEndpoint::sanitize_value( '0', 'int', $entry ) );
        $this->assertSame( 5, SettingsAjaxEndpoint::sanitize_value( '5', 'int', $entry ) );
        $this->assertSame( 10, SettingsAjaxEndpoint::sanitize_value( '20', 'int', $entry ) );
    }

    // ==================================================================
    // sanitize_value — string
    // ==================================================================

    public function test_sanitize_value_string_default_uses_sanitize_text_field(): void {
        Functions\when( 'sanitize_text_field' )->alias( function ( $s ) {
            return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $s ) ) );
        } );
        $this->assertSame( 'hello world', SettingsAjaxEndpoint::sanitize_value( "  <b>hello</b>\n  world  ", 'string' ) );
    }

    public function test_sanitize_value_string_url_uses_esc_url_raw(): void {
        Functions\when( 'esc_url_raw' )->alias( function ( $u ) {
            return 'esc:' . $u;
        } );
        $this->assertSame(
            'esc:https://example.com/',
            SettingsAjaxEndpoint::sanitize_value( 'https://example.com/', 'string', array( 'as' => 'url' ) )
        );
    }

    public function test_sanitize_value_string_multiline_uses_sanitize_textarea_field(): void {
        Functions\when( 'sanitize_textarea_field' )->alias( function ( $s ) {
            // Trim and collapse only horizontal whitespace; preserve newlines.
            return rtrim( preg_replace( '/[ \t]+/', ' ', (string) $s ) );
        } );
        $this->assertSame(
            "first line\nsecond line",
            SettingsAjaxEndpoint::sanitize_value( "first  line\nsecond  line  ", 'string', array( 'as' => 'multiline_text' ) )
        );
    }

    public function test_sanitize_value_string_rejects_arrays(): void {
        $this->assertSame( '', SettingsAjaxEndpoint::sanitize_value( array( 'x' ), 'string' ) );
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

    // ==================================================================
    // Allowlist — toggle-sweep additions
    // ==================================================================

    public function test_allowlist_covers_ffc_settings_boolean_toggles(): void {
        $list = SettingsAjaxEndpoint::allowlist();
        foreach (
            array(
                'cache_enabled',
                'cache_auto_warm',
                'qr_cache_enabled',
                'disable_all_emails',
                'enable_activity_log',
                'debug_pdf_generator',
                'debug_email_handler',
                'debug_form_processor',
                'debug_encryption',
                'debug_geofence',
                'debug_user_manager',
                'debug_rest_api',
                'debug_migrations',
                'debug_activity_log',
                'debug_frontend',
                'debug_admin',
                'debug_self_scheduling',
                'debug_audience',
                'debug_qrcode',
            ) as $key
        ) {
            $this->assertArrayHasKey( $key, $list, "missing allowlist entry for {$key}" );
            $this->assertSame( 'ffc_settings', $list[ $key ]['option'] );
            $this->assertSame( 'bool', $list[ $key ]['type'] );
            $this->assertArrayNotHasKey( 'path', $list[ $key ], "{$key} should be a flat key, not nested" );
        }
    }

    public function test_allowlist_covers_rate_limit_nested_toggles(): void {
        $list = SettingsAjaxEndpoint::allowlist();
        $expected = array(
            'ip_enabled'                       => array( 'ip', 'enabled' ),
            'email_enabled'                    => array( 'email', 'enabled' ),
            'email_check_database'             => array( 'email', 'check_database' ),
            'cpf_enabled'                      => array( 'cpf', 'enabled' ),
            'cpf_check_database'               => array( 'cpf', 'check_database' ),
            'global_enabled'                   => array( 'global', 'enabled' ),
            'device_enabled'                   => array( 'device', 'enabled' ),
            'device_bypass_logged_in_managers' => array( 'device', 'bypass_logged_in_managers' ),
            'device_log_blocks'                => array( 'device', 'log_blocks' ),
        );
        foreach ( $expected as $key => $path ) {
            $this->assertArrayHasKey( $key, $list );
            $this->assertSame( 'ffc_rate_limit_settings', $list[ $key ]['option'] );
            $this->assertSame( $path, $list[ $key ]['path'] );
        }
    }

    // ==================================================================
    // handle() — nested path writes
    // ==================================================================

    public function test_handle_writes_into_nested_path_preserving_siblings(): void {
        $captured_option = null;
        Functions\when( 'get_option' )->justReturn(
            array(
                'ip'    => array(
                    'enabled'      => false,
                    'max_per_hour' => 5,
                ),
                'email' => array( 'enabled' => true ),
            )
        );
        Functions\when( 'update_option' )->alias( function ( $key, $value ) use ( &$captured_option ) {
            if ( 'ffc_rate_limit_settings' === $key ) {
                $captured_option = $value;
            }
            return true;
        } );

        $_POST = array(
            'nonce' => 'x',
            'key'   => 'ip_enabled',
            'value' => '1',
        );

        try {
            SettingsAjaxEndpoint::handle();
        } catch ( \RuntimeException $e ) {
            // wp_send_json_success throws; that's the success branch.
        }

        $this->assertTrue( $captured_option['ip']['enabled'] );
        // Sibling values inside `ip` survive.
        $this->assertSame( 5, $captured_option['ip']['max_per_hour'] );
        // Sibling top-level groups survive.
        $this->assertTrue( $captured_option['email']['enabled'] );
    }

    public function test_handle_creates_intermediate_groups_when_missing(): void {
        $captured_option = null;
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'update_option' )->alias( function ( $key, $value ) use ( &$captured_option ) {
            $captured_option = $value;
            return true;
        } );

        $_POST = array(
            'nonce' => 'x',
            'key'   => 'device_log_blocks',
            'value' => 'true',
        );

        try {
            SettingsAjaxEndpoint::handle();
        } catch ( \RuntimeException $e ) {
            // expected
        }

        $this->assertTrue( $captured_option['device']['log_blocks'] );
    }

    // ==================================================================
    // Allowlist — full-autosave additions (int + string fields)
    // ==================================================================

    public function test_allowlist_covers_flat_typed_non_boolean_keys(): void {
        $list = SettingsAjaxEndpoint::allowlist();
        $expected = array(
            'cache_expiration'            => array( 'int', 'ffc_settings' ),
            'activity_log_retention_days' => array( 'int', 'ffc_settings' ),
            'public_csv_sync_max_rows'    => array( 'int', 'ffc_settings' ),
            'public_csv_default_limit'    => array( 'int', 'ffc_settings' ),
            'code_editor_theme'           => array( 'string', 'ffc_settings' ),
            'dark_mode'                   => array( 'string', 'ffc_settings' ),
            'cleanup_days'                => array( 'int', 'ffc_settings' ),
            'date_format'                 => array( 'string', 'ffc_settings' ),
            'date_format_custom'          => array( 'string', 'ffc_settings' ),
            'main_address'                => array( 'string', 'ffc_settings' ),
            'csv_download_page_url'       => array( 'string', 'ffc_settings' ),
            'qr_default_size'             => array( 'int', 'ffc_settings' ),
            'qr_default_margin'           => array( 'int', 'ffc_settings' ),
            'qr_default_error_level'      => array( 'string', 'ffc_settings' ),
        );
        foreach ( $expected as $key => list( $type, $option ) ) {
            $this->assertArrayHasKey( $key, $list );
            $this->assertSame( $type, $list[ $key ]['type'] );
            $this->assertSame( $option, $list[ $key ]['option'] );
            $this->assertArrayNotHasKey( 'path', $list[ $key ], "{$key} should be flat" );
        }
        // The url-as field carries the modifier.
        $this->assertSame( 'url', $list['csv_download_page_url']['as'] );
        // Numeric clamps are present where the schema demands.
        $this->assertSame( 100, $list['qr_default_size']['min'] );
        $this->assertSame( 500, $list['qr_default_size']['max'] );
    }

    public function test_allowlist_covers_rate_limit_non_boolean_nested_keys(): void {
        $list = SettingsAjaxEndpoint::allowlist();
        $expected = array(
            'ip_max_per_hour'        => array( array( 'ip',     'max_per_hour' ),     'int' ),
            'ip_apply_to'            => array( array( 'ip',     'apply_to' ),         'string' ),
            'ip_message'             => array( array( 'ip',     'message' ),          'string' ),
            'cpf_block_duration'     => array( array( 'cpf',    'block_duration' ),   'int' ),
            'device_message'         => array( array( 'device', 'message' ),          'string' ),
            'logging_max_logs'       => array( array( 'logging', 'max_logs' ),        'int' ),
        );
        foreach ( $expected as $key => list( $path, $type ) ) {
            $this->assertArrayHasKey( $key, $list );
            $this->assertSame( 'ffc_rate_limit_settings', $list[ $key ]['option'] );
            $this->assertSame( $path, $list[ $key ]['path'] );
            $this->assertSame( $type, $list[ $key ]['type'] );
        }
        // Message textareas declare multiline_text so newlines survive.
        $this->assertSame( 'multiline_text', $list['ip_message']['as'] );
        $this->assertSame( 'multiline_text', $list['device_message']['as'] );
    }

    // ==================================================================
    // handle() — int + string round-trip
    // ==================================================================

    public function test_handle_writes_int_with_clamping(): void {
        $captured = null;
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'update_option' )->alias( function ( $key, $value ) use ( &$captured ) {
            if ( 'ffc_settings' === $key ) {
                $captured = $value;
            }
            return true;
        } );

        $_POST = array(
            'nonce' => 'x',
            'key'   => 'qr_default_size',
            'value' => '999', // above max=500
        );

        try {
            SettingsAjaxEndpoint::handle();
        } catch ( \RuntimeException $e ) {
            // expected
        }

        $this->assertSame( 500, $captured['qr_default_size'] );
    }

    public function test_handle_writes_string_via_sanitize_text_field(): void {
        Functions\when( 'sanitize_text_field' )->alias( function ( $s ) {
            return strip_tags( (string) $s );
        } );
        $captured = null;
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'update_option' )->alias( function ( $key, $value ) use ( &$captured ) {
            $captured = $value;
            return true;
        } );

        $_POST = array(
            'nonce' => 'x',
            'key'   => 'date_format',
            'value' => 'd/m/Y <script>x</script>',
        );

        try {
            SettingsAjaxEndpoint::handle();
        } catch ( \RuntimeException $e ) {
            // expected
        }

        $this->assertSame( 'd/m/Y x', $captured['date_format'] );
    }

    public function test_handle_replaces_non_array_intermediate_with_an_array(): void {
        $captured_option = null;
        // Existing option has a scalar where the path expects a group.
        Functions\when( 'get_option' )->justReturn( array( 'cpf' => 'not_an_array' ) );
        Functions\when( 'update_option' )->alias( function ( $key, $value ) use ( &$captured_option ) {
            $captured_option = $value;
            return true;
        } );

        $_POST = array(
            'nonce' => 'x',
            'key'   => 'cpf_check_database',
            'value' => '1',
        );

        try {
            SettingsAjaxEndpoint::handle();
        } catch ( \RuntimeException $e ) {
            // expected
        }

        $this->assertSame( array( 'check_database' => true ), $captured_option['cpf'] );
    }
}

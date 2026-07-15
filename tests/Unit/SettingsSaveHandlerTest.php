<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\SettingsSaveHandler;

/**
 * Tests for SettingsSaveHandler: settings validation and sanitization.
 *
 * Uses Reflection to access private methods for testing pure business logic.
 *
 * @covers \FreeFormCertificate\Admin\SettingsSaveHandler
 */
class SettingsSaveHandlerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var SettingsSaveHandler */
    private $handler;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        class_exists( '\\FreeFormCertificate\\Admin\\SettingsSaveHandler' );

        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'sanitize_key' )->alias( function ( $key ) {
            return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
        } );
        Functions\when( 'sanitize_email' )->returnArg();
        Functions\when( 'sanitize_textarea_field' )->returnArg();
        Functions\when( 'absint' )->alias( function ( $val ) {
            return abs( (int) $val );
        } );
        Functions\when( 'wp_unslash' )->returnArg();

        $mock_handler = Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' );
        $this->handler = new SettingsSaveHandler( $mock_handler );
    }

    protected function tearDown(): void {
        unset( $_POST['_ffc_tab'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Invoke a private method on SettingsSaveHandler.
     */
    private function invoke( string $method, array $args = [] ) {
        $ref = new \ReflectionMethod( SettingsSaveHandler::class, $method );
        $ref->setAccessible( true );
        return $ref->invokeArgs( $this->handler, $args );
    }

    // ==================================================================
    // save_general_settings()
    // ==================================================================

    public function test_general_dark_mode_valid_values_accepted(): void {
        foreach ( array( 'off', 'on', 'auto' ) as $mode ) {
            $result = $this->invoke( 'save_general_settings', array( array(), array( 'dark_mode' => $mode ) ) );
            $this->assertSame( $mode, $result['dark_mode'] );
        }
    }

    public function test_general_dark_mode_invalid_defaults_to_off(): void {
        $result = $this->invoke( 'save_general_settings', array( array(), array( 'dark_mode' => 'invalid' ) ) );
        $this->assertSame( 'off', $result['dark_mode'] );
    }

    public function test_general_cleanup_days_stored_as_integer(): void {
        $result = $this->invoke( 'save_general_settings', array( array(), array( 'cleanup_days' => '90' ) ) );
        $this->assertSame( 90, $result['cleanup_days'] );
    }

    public function test_general_main_address_preserved(): void {
        $result = $this->invoke( 'save_general_settings', array( array(), array( 'main_address' => '123 Main St' ) ) );
        $this->assertSame( '123 Main St', $result['main_address'] );
    }

    public function test_general_existing_settings_not_overwritten(): void {
        $existing = array( 'smtp_host' => 'mail.example.com', 'custom_key' => 'value' );
        $result = $this->invoke( 'save_general_settings', array( $existing, array( 'main_address' => 'New Addr' ) ) );
        $this->assertSame( 'mail.example.com', $result['smtp_host'] );
        $this->assertSame( 'value', $result['custom_key'] );
        $this->assertSame( 'New Addr', $result['main_address'] );
    }

    public function test_general_advanced_tab_activity_log_enabled(): void {
        $_POST['_ffc_tab'] = 'advanced';
        $result = $this->invoke( 'save_general_settings', array( array(), array( 'enable_activity_log' => '1' ) ) );
        $this->assertSame( 1, $result['enable_activity_log'] );
    }

    public function test_general_advanced_tab_activity_log_absent_sets_zero(): void {
        $_POST['_ffc_tab'] = 'advanced';
        $result = $this->invoke( 'save_general_settings', array( array(), array() ) );
        $this->assertSame( 0, $result['enable_activity_log'] );
    }

    public function test_general_advanced_tab_retention_days_capped_at_365(): void {
        $_POST['_ffc_tab'] = 'advanced';
        $result = $this->invoke( 'save_general_settings', array( array(), array( 'activity_log_retention_days' => '500' ) ) );
        $this->assertSame( 365, $result['activity_log_retention_days'] );
    }

    public function test_general_advanced_tab_retention_days_within_limit(): void {
        $_POST['_ffc_tab'] = 'advanced';
        $result = $this->invoke( 'save_general_settings', array( array(), array( 'activity_log_retention_days' => '180' ) ) );
        $this->assertSame( 180, $result['activity_log_retention_days'] );
    }

    public function test_general_advanced_tab_sync_max_rows_clamped_below_minimum(): void {
        $_POST['_ffc_tab'] = 'advanced';
        $result = $this->invoke( 'save_general_settings', array( array(), array( 'public_csv_sync_max_rows' => '10' ) ) );
        $this->assertSame( 100, $result['public_csv_sync_max_rows'] );
    }

    public function test_general_advanced_tab_sync_max_rows_clamped_above_maximum(): void {
        $_POST['_ffc_tab'] = 'advanced';
        $result = $this->invoke( 'save_general_settings', array( array(), array( 'public_csv_sync_max_rows' => '99999' ) ) );
        $this->assertSame( 10000, $result['public_csv_sync_max_rows'] );
    }

    public function test_general_advanced_tab_sync_max_rows_accepts_in_range(): void {
        $_POST['_ffc_tab'] = 'advanced';
        $result = $this->invoke( 'save_general_settings', array( array(), array( 'public_csv_sync_max_rows' => '2500' ) ) );
        $this->assertSame( 2500, $result['public_csv_sync_max_rows'] );
    }

    public function test_general_advanced_tab_debug_flags_set_and_unset(): void {
        $_POST['_ffc_tab'] = 'advanced';
        $new = array( 'debug_pdf_generator' => '1', 'debug_encryption' => '1' );
        $result = $this->invoke( 'save_general_settings', array( array(), $new ) );
        $this->assertSame( 1, $result['debug_pdf_generator'] );
        $this->assertSame( 1, $result['debug_encryption'] );
        $this->assertSame( 0, $result['debug_email_handler'] );
        $this->assertSame( 0, $result['debug_form_processor'] );
    }

    public function test_general_debug_flags_ignored_on_non_advanced_tab(): void {
        $_POST['_ffc_tab'] = 'general';
        $new = array( 'debug_pdf_generator' => '1' );
        $result = $this->invoke( 'save_general_settings', array( array(), $new ) );
        $this->assertArrayNotHasKey( 'debug_pdf_generator', $result );
    }

    public function test_general_cache_tab_settings(): void {
        $_POST['_ffc_tab'] = 'cache';
        $new = array( 'cache_enabled' => '1', 'cache_expiration' => '3600', 'cache_auto_warm' => '1' );
        $result = $this->invoke( 'save_general_settings', array( array(), $new ) );
        $this->assertSame( 1, $result['cache_enabled'] );
        $this->assertSame( 3600, $result['cache_expiration'] );
        $this->assertSame( 1, $result['cache_auto_warm'] );
    }

    public function test_general_cache_settings_ignored_on_non_cache_tab(): void {
        $_POST['_ffc_tab'] = 'general';
        $new = array( 'cache_enabled' => '1' );
        $result = $this->invoke( 'save_general_settings', array( array(), $new ) );
        $this->assertArrayNotHasKey( 'cache_enabled', $result );
    }

    // ==================================================================
    // save_smtp_settings()
    // ==================================================================

    public function test_smtp_disable_all_emails_on_smtp_tab(): void {
        $_POST['_ffc_tab'] = 'smtp';
        $result = $this->invoke( 'save_smtp_settings', array( array(), array( 'disable_all_emails' => '1' ) ) );
        $this->assertSame( 1, $result['disable_all_emails'] );
    }

    public function test_smtp_disable_all_emails_absent_on_smtp_tab_sets_zero(): void {
        $_POST['_ffc_tab'] = 'smtp';
        $result = $this->invoke( 'save_smtp_settings', array( array(), array() ) );
        $this->assertSame( 0, $result['disable_all_emails'] );
    }

    public function test_smtp_disable_all_emails_ignored_on_other_tab(): void {
        $_POST['_ffc_tab'] = 'general';
        $result = $this->invoke( 'save_smtp_settings', array( array(), array( 'disable_all_emails' => '1' ) ) );
        $this->assertArrayNotHasKey( 'disable_all_emails', $result );
    }

    public function test_smtp_host_and_port_stored(): void {
        $new = array( 'smtp_host' => 'smtp.example.com', 'smtp_port' => '587' );
        $result = $this->invoke( 'save_smtp_settings', array( array(), $new ) );
        $this->assertSame( 'smtp.example.com', $result['smtp_host'] );
        $this->assertSame( 587, $result['smtp_port'] );
    }

    public function test_smtp_from_email_stored(): void {
        $result = $this->invoke( 'save_smtp_settings', array( array(), array( 'smtp_from_email' => 'test@example.com' ) ) );
        $this->assertSame( 'test@example.com', $result['smtp_from_email'] );
    }

    public function test_smtp_user_email_settings_all_saved(): void {
        $new = array(
            'send_wp_user_email_submission'  => 'yes',
            'send_wp_user_email_appointment' => 'no',
            'send_wp_user_email_csv_import'  => 'yes',
            'send_wp_user_email_migration'   => 'no',
        );
        $result = $this->invoke( 'save_smtp_settings', array( array(), $new ) );
        $this->assertSame( 'yes', $result['send_wp_user_email_submission'] );
        $this->assertSame( 'no', $result['send_wp_user_email_appointment'] );
        $this->assertSame( 'yes', $result['send_wp_user_email_csv_import'] );
        $this->assertSame( 'no', $result['send_wp_user_email_migration'] );
    }

    public function test_smtp_user_pass_from_name_stored(): void {
        $new = array(
            'smtp_user'      => 'mailer@example.com',
            'smtp_pass'      => 's3cret',
            'smtp_from_name' => 'Acme Certs',
        );
        $result = $this->invoke( 'save_smtp_settings', array( array(), $new ) );
        $this->assertSame( 'mailer@example.com', $result['smtp_user'] );
        $this->assertSame( 's3cret', $result['smtp_pass'] );
        $this->assertSame( 'Acme Certs', $result['smtp_from_name'] );
    }

    public function test_smtp_secure_and_mode_stored(): void {
        $new = array( 'smtp_mode' => 'custom', 'smtp_secure' => 'tls' );
        $result = $this->invoke( 'save_smtp_settings', array( array(), $new ) );
        $this->assertSame( 'custom', $result['smtp_mode'] );
        $this->assertSame( 'tls', $result['smtp_secure'] );
    }

    public function test_smtp_preserves_existing_settings(): void {
        $existing = array( 'dark_mode' => 'on' );
        $new = array( 'smtp_host' => 'mail.test.com' );
        $result = $this->invoke( 'save_smtp_settings', array( $existing, $new ) );
        $this->assertSame( 'on', $result['dark_mode'] );
        $this->assertSame( 'mail.test.com', $result['smtp_host'] );
    }

    // ==================================================================
    // save_qrcode_settings()
    // ==================================================================

    public function test_qrcode_size_and_margin_stored_as_int(): void {
        $new = array( 'qr_default_size' => '300', 'qr_default_margin' => '10' );
        $result = $this->invoke( 'save_qrcode_settings', array( array(), $new ) );
        $this->assertSame( 300, $result['qr_default_size'] );
        $this->assertSame( 10, $result['qr_default_margin'] );
    }

    public function test_qrcode_error_level_stored(): void {
        $result = $this->invoke( 'save_qrcode_settings', array( array(), array( 'qr_default_error_level' => 'H' ) ) );
        $this->assertSame( 'H', $result['qr_default_error_level'] );
    }

    public function test_qrcode_cache_on_cache_tab(): void {
        $_POST['_ffc_tab'] = 'cache';
        $result = $this->invoke( 'save_qrcode_settings', array( array(), array( 'qr_cache_enabled' => '1' ) ) );
        $this->assertSame( 1, $result['qr_cache_enabled'] );
    }

    public function test_qrcode_cache_ignored_on_other_tab(): void {
        $_POST['_ffc_tab'] = 'qr_code';
        $result = $this->invoke( 'save_qrcode_settings', array( array(), array( 'qr_cache_enabled' => '1' ) ) );
        $this->assertArrayNotHasKey( 'qr_cache_enabled', $result );
    }

    // ==================================================================
    // save_date_format_settings()
    // ==================================================================

    public function test_date_format_stored(): void {
        $result = $this->invoke( 'save_date_format_settings', array( array(), array( 'date_format' => 'd/m/Y' ) ) );
        $this->assertSame( 'd/m/Y', $result['date_format'] );
    }

    public function test_date_format_custom_stored(): void {
        $result = $this->invoke( 'save_date_format_settings', array( array(), array( 'date_format_custom' => 'Y-m-d H:i:s' ) ) );
        $this->assertSame( 'Y-m-d H:i:s', $result['date_format_custom'] );
    }

    public function test_date_format_preserves_existing_settings(): void {
        $existing = array( 'other_key' => 'value' );
        $result = $this->invoke( 'save_date_format_settings', array( $existing, array( 'date_format' => 'd/m/Y' ) ) );
        $this->assertSame( 'value', $result['other_key'] );
        $this->assertSame( 'd/m/Y', $result['date_format'] );
    }

    public function test_date_format_empty_new_preserves_clean(): void {
        $existing = array( 'date_format' => 'd/m/Y', 'date_format_custom' => 'custom' );
        $result = $this->invoke( 'save_date_format_settings', array( $existing, array() ) );
        $this->assertSame( 'd/m/Y', $result['date_format'] );
        $this->assertSame( 'custom', $result['date_format_custom'] );
    }

    // ==================================================================
    // save_general_settings() — additional uncovered branches
    // ==================================================================

    public function test_general_csv_download_page_url_stored(): void {
        Functions\when( 'esc_url_raw' )->returnArg();
        $result = $this->invoke( 'save_general_settings', array( array(), array( 'csv_download_page_url' => 'https://x.test/csv' ) ) );
        $this->assertSame( 'https://x.test/csv', $result['csv_download_page_url'] );
    }

    public function test_general_advanced_tab_min_level_valid(): void {
        $_POST['_ffc_tab'] = 'advanced';
        $result = $this->invoke( 'save_general_settings', array( array(), array( 'activity_log_min_level' => 'warning' ) ) );
        $this->assertSame( 'warning', $result['activity_log_min_level'] );
    }

    public function test_general_advanced_tab_min_level_invalid_defaults_to_debug(): void {
        $_POST['_ffc_tab'] = 'advanced';
        $result = $this->invoke( 'save_general_settings', array( array(), array( 'activity_log_min_level' => 'bogus' ) ) );
        $this->assertSame( 'debug', $result['activity_log_min_level'] );
    }

    public function test_general_advanced_tab_category_flags_set_and_unset(): void {
        $_POST['_ffc_tab'] = 'advanced';
        $new = array( 'activity_log_cat_submissions' => '1' );
        $result = $this->invoke( 'save_general_settings', array( array(), $new ) );
        $this->assertSame( 1, $result['activity_log_cat_submissions'] );
        // A category not present in $new is set to 0.
        $this->assertSame( 0, $result['activity_log_cat_system'] );
    }

    public function test_general_advanced_tab_public_csv_default_limit_floored_at_one(): void {
        $_POST['_ffc_tab'] = 'advanced';
        $result = $this->invoke( 'save_general_settings', array( array(), array( 'public_csv_default_limit' => '0' ) ) );
        $this->assertSame( 1, $result['public_csv_default_limit'] );
    }

    public function test_general_advanced_tab_public_csv_default_limit_value(): void {
        $_POST['_ffc_tab'] = 'advanced';
        $result = $this->invoke( 'save_general_settings', array( array(), array( 'public_csv_default_limit' => '50' ) ) );
        $this->assertSame( 50, $result['public_csv_default_limit'] );
    }

    public function test_general_advanced_tab_code_editor_theme_valid(): void {
        $_POST['_ffc_tab'] = 'advanced';
        foreach ( array( 'auto', 'light', 'dark' ) as $theme ) {
            $result = $this->invoke( 'save_general_settings', array( array(), array( 'code_editor_theme' => $theme ) ) );
            $this->assertSame( $theme, $result['code_editor_theme'] );
        }
    }

    public function test_general_advanced_tab_code_editor_theme_invalid_defaults_to_dark(): void {
        $_POST['_ffc_tab'] = 'advanced';
        $result = $this->invoke( 'save_general_settings', array( array(), array( 'code_editor_theme' => 'rainbow' ) ) );
        $this->assertSame( 'dark', $result['code_editor_theme'] );
    }

    // ==================================================================
    // save_url_shortener_settings()
    // ==================================================================

    public function test_url_shortener_checkboxes_on_tab(): void {
        $_POST['_ffc_tab'] = 'url_shortener';
        $new = array( 'url_shortener_enabled' => '1', 'url_shortener_auto_create' => '1' );
        $result = $this->invoke( 'save_url_shortener_settings', array( array(), $new ) );
        $this->assertSame( 1, $result['url_shortener_enabled'] );
        $this->assertSame( 1, $result['url_shortener_auto_create'] );
    }

    public function test_url_shortener_checkboxes_absent_on_tab_set_zero(): void {
        $_POST['_ffc_tab'] = 'url_shortener';
        $result = $this->invoke( 'save_url_shortener_settings', array( array(), array() ) );
        $this->assertSame( 0, $result['url_shortener_enabled'] );
        $this->assertSame( 0, $result['url_shortener_auto_create'] );
    }

    public function test_url_shortener_checkboxes_ignored_on_other_tab(): void {
        $_POST['_ffc_tab'] = 'general';
        $result = $this->invoke( 'save_url_shortener_settings', array( array(), array( 'url_shortener_enabled' => '1' ) ) );
        $this->assertArrayNotHasKey( 'url_shortener_enabled', $result );
    }

    public function test_url_shortener_prefix_unchanged_no_flush(): void {
        Functions\when( 'sanitize_title' )->alias( function ( $v ) {
            return strtolower( $v );
        } );
        $deleted = false;
        Functions\when( 'delete_option' )->alias( function () use ( &$deleted ) {
            $deleted = true;
            return true;
        } );
        // Same prefix => no flush branch.
        $result = $this->invoke( 'save_url_shortener_settings', array( array( 'url_shortener_prefix' => 'go' ), array( 'url_shortener_prefix' => 'go' ) ) );
        $this->assertSame( 'go', $result['url_shortener_prefix'] );
        $this->assertFalse( $deleted );
    }

    public function test_url_shortener_prefix_changed_triggers_flush(): void {
        Functions\when( 'sanitize_title' )->alias( function ( $v ) {
            return strtolower( $v );
        } );
        $deleted = false;
        Functions\when( 'delete_option' )->alias( function ( $key ) use ( &$deleted ) {
            if ( 'ffc_url_shortener_rewrite_version' === $key ) {
                $deleted = true;
            }
            return true;
        } );
        Functions\expect( 'add_action' )->once()->with( 'shutdown', 'flush_rewrite_rules' );
        $result = $this->invoke( 'save_url_shortener_settings', array( array( 'url_shortener_prefix' => 'go' ), array( 'url_shortener_prefix' => 'link' ) ) );
        $this->assertSame( 'link', $result['url_shortener_prefix'] );
        $this->assertTrue( $deleted );
    }

    public function test_url_shortener_code_length_clamped_low(): void {
        $result = $this->invoke( 'save_url_shortener_settings', array( array(), array( 'url_shortener_code_length' => '2' ) ) );
        $this->assertSame( 4, $result['url_shortener_code_length'] );
    }

    public function test_url_shortener_code_length_clamped_high(): void {
        $result = $this->invoke( 'save_url_shortener_settings', array( array(), array( 'url_shortener_code_length' => '99' ) ) );
        $this->assertSame( 10, $result['url_shortener_code_length'] );
    }

    public function test_url_shortener_code_length_in_range(): void {
        $result = $this->invoke( 'save_url_shortener_settings', array( array(), array( 'url_shortener_code_length' => '7' ) ) );
        $this->assertSame( 7, $result['url_shortener_code_length'] );
    }

    public function test_url_shortener_redirect_type_valid(): void {
        $result = $this->invoke( 'save_url_shortener_settings', array( array(), array( 'url_shortener_redirect_type' => '301' ) ) );
        $this->assertSame( 301, $result['url_shortener_redirect_type'] );
    }

    public function test_url_shortener_redirect_type_invalid_defaults_to_302(): void {
        $result = $this->invoke( 'save_url_shortener_settings', array( array(), array( 'url_shortener_redirect_type' => '418' ) ) );
        $this->assertSame( 302, $result['url_shortener_redirect_type'] );
    }

    public function test_url_shortener_post_types_array_on_tab(): void {
        $_POST['_ffc_tab'] = 'url_shortener';
        $_POST['ffc_settings'] = array( 'url_shortener_post_types' => array( 'post', 'page' ) );
        $result = $this->invoke( 'save_url_shortener_settings', array( array(), array() ) );
        $this->assertSame( array( 'post', 'page' ), $result['url_shortener_post_types'] );
        unset( $_POST['ffc_settings'] );
    }

    public function test_url_shortener_post_types_missing_on_tab_empty_array(): void {
        $_POST['_ffc_tab'] = 'url_shortener';
        $result = $this->invoke( 'save_url_shortener_settings', array( array(), array() ) );
        $this->assertSame( array(), $result['url_shortener_post_types'] );
    }

    // ==================================================================
    // save_user_access_settings()
    // ==================================================================

    public function test_user_access_settings_defaults(): void {
        Functions\when( 'home_url' )->alias( function ( $path = '' ) {
            return 'https://site.test' . $path;
        } );
        $saved = null;
        Functions\when( 'update_option' )->alias( function ( $key, $val ) use ( &$saved ) {
            $saved = array( $key, $val );
            return true;
        } );
        Functions\expect( 'add_settings_error' )->once();

        $this->invoke( 'save_user_access_settings', array() );

        $this->assertSame( 'ffc_user_access_settings', $saved[0] );
        $this->assertFalse( $saved[1]['block_wp_admin'] );
        $this->assertSame( array( 'ffc_user' ), $saved[1]['blocked_roles'] );
        $this->assertSame( 'https://site.test/dashboard', $saved[1]['redirect_url'] );
        $this->assertSame( '', $saved[1]['redirect_message'] );
        $this->assertFalse( $saved[1]['allow_admin_bar'] );
        $this->assertFalse( $saved[1]['bypass_for_admins'] );
    }

    public function test_user_access_settings_with_post_values(): void {
        Functions\when( 'home_url' )->returnArg();
        Functions\when( 'esc_url_raw' )->returnArg();
        $_POST['block_wp_admin']    = '1';
        $_POST['blocked_roles']     = array( 'subscriber', 'ffc_user' );
        $_POST['redirect_url']      = 'https://custom.test/go';
        $_POST['redirect_message']  = 'Access denied';
        $_POST['allow_admin_bar']   = '1';
        $_POST['bypass_for_admins'] = '1';

        $saved = null;
        Functions\when( 'update_option' )->alias( function ( $key, $val ) use ( &$saved ) {
            $saved = $val;
            return true;
        } );
        Functions\expect( 'add_settings_error' )->once();

        $this->invoke( 'save_user_access_settings', array() );

        $this->assertTrue( $saved['block_wp_admin'] );
        $this->assertSame( array( 'subscriber', 'ffc_user' ), $saved['blocked_roles'] );
        $this->assertSame( 'https://custom.test/go', $saved['redirect_url'] );
        $this->assertSame( 'Access denied', $saved['redirect_message'] );
        $this->assertTrue( $saved['allow_admin_bar'] );
        $this->assertTrue( $saved['bypass_for_admins'] );

        unset( $_POST['block_wp_admin'], $_POST['blocked_roles'], $_POST['redirect_url'], $_POST['redirect_message'], $_POST['allow_admin_bar'], $_POST['bypass_for_admins'] );
    }

    // ==================================================================
    // handle_danger_zone()
    // ==================================================================

    public function test_danger_zone_delete_all_no_reset(): void {
        Functions\when( 'do_action' )->justReturn( null );
        Functions\expect( 'add_settings_error' )->once();

        $sub = Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' );
        $sub->shouldReceive( 'delete_all_submissions' )->once()->with( null, false );
        $handler = new SettingsSaveHandler( $sub );

        $_POST['delete_target'] = 'all';
        $ref = new \ReflectionMethod( SettingsSaveHandler::class, 'handle_danger_zone' );
        $ref->setAccessible( true );
        $ref->invoke( $handler );

        unset( $_POST['delete_target'] );
    }

    public function test_danger_zone_specific_form_with_reset(): void {
        Functions\when( 'do_action' )->justReturn( null );
        Functions\expect( 'add_settings_error' )->once();

        $sub = Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' );
        $sub->shouldReceive( 'delete_all_submissions' )->once()->with( 42, true );
        $handler = new SettingsSaveHandler( $sub );

        $_POST['delete_target'] = '42';
        $_POST['reset_counter'] = '1';
        $ref = new \ReflectionMethod( SettingsSaveHandler::class, 'handle_danger_zone' );
        $ref->setAccessible( true );
        $ref->invoke( $handler );

        unset( $_POST['delete_target'], $_POST['reset_counter'] );
    }

    // ==================================================================
    // save_general_and_specific_settings()
    // ==================================================================

    public function test_save_general_and_specific_settings_persists(): void {
        $_POST['ffc_settings'] = array( 'main_address' => 'HQ' );
        Functions\when( 'get_option' )->justReturn( array( 'existing' => 'x' ) );
        Functions\when( 'apply_filters' )->alias( function ( $hook, $value ) {
            return $value;
        } );
        Functions\when( 'do_action' )->justReturn( null );
        $saved = null;
        Functions\when( 'update_option' )->alias( function ( $key, $val ) use ( &$saved ) {
            $saved = array( $key, $val );
            return true;
        } );
        Functions\expect( 'add_settings_error' )->once();

        $this->invoke( 'save_general_and_specific_settings', array() );

        $this->assertSame( 'ffc_settings', $saved[0] );
        $this->assertSame( 'HQ', $saved[1]['main_address'] );
        $this->assertSame( 'x', $saved[1]['existing'] );

        unset( $_POST['ffc_settings'] );
    }

    // ==================================================================
    // handle_all_submissions()
    // ==================================================================

    public function test_handle_all_submissions_bails_without_capability(): void {
        Functions\when( 'current_user_can' )->justReturn( false );
        // No nonce/save functions should be reached; absence of errors is the assertion.
        $this->handler->handle_all_submissions();
        $this->assertTrue( true );
    }

    public function test_handle_all_submissions_runs_all_branches(): void {
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'wp_verify_nonce' )->justReturn( true );
        Functions\when( 'check_admin_referer' )->justReturn( true );
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'home_url' )->returnArg();
        Functions\when( 'apply_filters' )->alias( function ( $hook, $value ) {
            return $value;
        } );
        Functions\when( 'do_action' )->justReturn( null );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'add_settings_error' )->justReturn( null );

        $_POST['ffc_settings']        = array( 'main_address' => 'x' );
        $_POST['ffc_delete_all_data'] = '1';
        $_POST['delete_target']       = 'all';

        $sub = Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' );
        $sub->shouldReceive( 'delete_all_submissions' )->once();
        $handler = new SettingsSaveHandler( $sub );

        $handler->handle_all_submissions();
        $this->assertTrue( true );

        unset( $_POST['ffc_settings'], $_POST['ffc_delete_all_data'], $_POST['delete_target'] );
    }

}

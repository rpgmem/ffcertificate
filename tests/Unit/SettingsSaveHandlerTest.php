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
 */
class SettingsSaveHandlerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var SettingsSaveHandler */
    private $handler;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

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
}

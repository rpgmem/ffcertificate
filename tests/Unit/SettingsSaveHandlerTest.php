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
		Functions\when( 'esc_url_raw' )->returnArg();
		// SMTP / Email Model saves are gated on ffc_manage_settings_smtp (#711);
		// default to granted so the existing save tests exercise the save path.
		Functions\when( 'current_user_can' )->justReturn( true );

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

	/**
	 * Drive the captcha section with a POST that names the captcha tab.
	 *
	 * @param array<string, mixed> $new     Posted values.
	 * @param array<string, mixed> $current Values already stored.
	 * @return array<string, mixed>
	 */
	private function save_captcha( array $new, array $current = array() ): array {
		$_POST['_ffc_tab'] = 'captcha';

		return $this->invoke( 'save_captcha_settings', array( $current, $new ) );
	}

	// ==================================================================
	// save_captcha_settings() — #1053 PR4
	// ==================================================================

	public function test_captcha_section_is_untouched_when_another_tab_is_saved(): void {
		// Every section here is gated on the tab marker for the same reason:
		// a POST from another tab never carried these fields, so rebuilding
		// them from it would reset settings the administrator did not touch.
		$_POST['_ffc_tab'] = 'general';

		$current = array( 'captcha_provider' => 'both', 'captcha_altcha_type' => 'switch' );
		$result  = $this->invoke( 'save_captcha_settings', array( $current, array() ) );

		$this->assertSame( $current, $result );
	}

	public function test_a_known_mode_is_saved(): void {
		Functions\when( 'is_ssl' )->justReturn( true );

		$this->assertSame( 'both', $this->save_captcha( array( 'captcha_provider' => 'both' ) )['captcha_provider'] );
		$this->assertSame( 'altcha', $this->save_captcha( array( 'captcha_provider' => 'altcha' ) )['captcha_provider'] );
	}

	public function test_an_unknown_mode_falls_back_to_the_math_challenge(): void {
		Functions\when( 'is_ssl' )->justReturn( true );

		$this->assertSame( 'math', $this->save_captcha( array( 'captcha_provider' => 'nonsense' ) )['captcha_provider'] );
	}

	public function test_the_altcha_only_mode_is_refused_without_https(): void {
		// The widget throws "Secure context (HTTPS) required" rather than
		// degrading, so accepting this would hand every visitor a form they
		// cannot submit. The previous value has to survive.
		Functions\when( 'is_ssl' )->justReturn( false );
		$errors = array();
		Functions\when( 'add_settings_error' )->alias(
			function ( $slug, $code ) use ( &$errors ): void {
				$errors[] = $code;
			}
		);

		$result = $this->save_captcha(
			array( 'captcha_provider' => 'altcha' ),
			array( 'captcha_provider' => 'both' )
		);

		$this->assertSame( 'both', $result['captcha_provider'], 'The refused save must not silently change the mode.' );
		$this->assertContains( 'ffc_captcha_requires_https', $errors, 'Refusing silently would read as a save that worked.' );
	}

	public function test_the_fallback_mode_is_allowed_without_https(): void {
		// Its <noscript> half still works, which is exactly what it is for.
		Functions\when( 'is_ssl' )->justReturn( false );

		$this->assertSame( 'both', $this->save_captcha( array( 'captcha_provider' => 'both' ) )['captcha_provider'] );
	}

	public function test_the_work_factor_is_clamped_on_save(): void {
		Functions\when( 'is_ssl' )->justReturn( true );

		$low  = $this->save_captcha( array( 'captcha_provider' => 'math', 'captcha_altcha_complexity' => 1 ) );
		$high = $this->save_captcha( array( 'captcha_provider' => 'math', 'captcha_altcha_complexity' => 99999999 ) );

		$this->assertSame( 10000, $low['captcha_altcha_complexity'] );
		$this->assertSame( 1000000, $high['captcha_altcha_complexity'] );
	}

	public function test_unchecked_attribution_boxes_read_as_off(): void {
		// A checkbox absent from the POST is unchecked, not unchanged — the
		// only way to turn one off is for its absence to mean zero.
		Functions\when( 'is_ssl' )->justReturn( true );

		$result = $this->save_captcha(
			array( 'captcha_provider' => 'math' ),
			array( 'captcha_altcha_hide_logo' => 1, 'captcha_altcha_hide_footer' => 1 )
		);

		$this->assertSame( 0, $result['captcha_altcha_hide_logo'] );
		$this->assertSame( 0, $result['captcha_altcha_hide_footer'] );
	}

	public function test_widget_attributes_are_validated_against_the_allowed_sets(): void {
		Functions\when( 'is_ssl' )->justReturn( true );

		$result = $this->save_captcha(
			array(
				'captcha_provider'    => 'math',
				'captcha_altcha_type' => 'radio',
				'captcha_altcha_auto' => 'onfocus',
			)
		);

		$this->assertSame( 'checkbox', $result['captcha_altcha_type'] );
		$this->assertSame( 'onfocus', $result['captcha_altcha_auto'] );
	}

	public function test_the_removed_layout_and_theme_keys_are_not_persisted(): void {
		// Neither worked in an inline placement, so they stopped being
		// offered; a POST that still carries them (a stale page, a bot) must
		// not resurrect a key nothing reads.
		Functions\when( 'is_ssl' )->justReturn( true );

		$result = $this->save_captcha(
			array(
				'captcha_provider'       => 'math',
				'captcha_altcha_display' => 'bar',
				'captcha_altcha_theme'   => 'dark',
			)
		);

		$this->assertArrayNotHasKey( 'captcha_altcha_display', $result );
		$this->assertArrayNotHasKey( 'captcha_altcha_theme', $result );
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

	public function test_general_cleanup_days_clamped_to_minimum_one(): void {
		$result = $this->invoke( 'save_general_settings', array( array(), array( 'cleanup_days' => '0' ) ) );
		$this->assertSame( 1, $result['cleanup_days'] );
	}

	public function test_general_cleanup_enabled_true_when_checkbox_present(): void {
		$result = $this->invoke( 'save_general_settings', array( array(), array( 'cleanup_enabled' => '1' ) ) );
		$this->assertTrue( $result['cleanup_enabled'] );
	}

	public function test_general_cleanup_enabled_false_when_checkbox_absent(): void {
		// An unchecked toggle is simply absent from POST; the rebuild must
		// record it as off (no-clobber) rather than leave a stale true.
		$result = $this->invoke( 'save_general_settings', array( array( 'cleanup_enabled' => true ), array() ) );
		$this->assertFalse( $result['cleanup_enabled'] );
	}

	public function test_general_main_address_preserved(): void {
		$result = $this->invoke( 'save_general_settings', array( array(), array( 'main_address' => '123 Main St' ) ) );
		$this->assertSame( '123 Main St', $result['main_address'] );
	}

	public function test_general_branding_logos_sanitized_as_urls(): void {
		// #865 Phase 2 — {{logo_gov}} / {{logo_org}} sources go through esc_url_raw.
		$result = $this->invoke(
			'save_general_settings',
			array(
				array(),
				array(
					'logo_gov' => 'https://cdn.example/gov.png',
					'logo_org' => 'https://cdn.example/org.png',
				),
			)
		);
		$this->assertSame( 'https://cdn.example/gov.png', $result['logo_gov'] );
		$this->assertSame( 'https://cdn.example/org.png', $result['logo_org'] );
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

	public function test_smtp_kill_switch_preserved_without_dangerzone(): void {
		// #739 §4.4 — the kill-switch needs the dangerzone sub-cap. A _smtp-only
		// operator can save the transport but the current value is preserved.
		$_POST['_ffc_tab'] = 'smtp';
		Functions\when( 'current_user_can' )->alias(
			static function ( $cap ) {
				return 'ffc_manage_settings_smtp' === $cap;
			}
		);
		$result = $this->invoke( 'save_smtp_settings', array( array( 'disable_all_emails' => 0 ), array( 'disable_all_emails' => '1' ) ) );
		$this->assertSame( 0, $result['disable_all_emails'] );
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

	public function test_smtp_settings_skipped_without_smtp_cap(): void {
		// A settings manager lacking ffc_manage_settings_smtp cannot change the
		// SMTP transport: save_smtp_settings returns $clean untouched (#711).
		Functions\when( 'current_user_can' )->justReturn( false );
		$existing = array( 'smtp_host' => 'old.example.com' );
		$result   = $this->invoke( 'save_smtp_settings', array( $existing, array( 'smtp_host' => 'new.example.com' ) ) );
		$this->assertSame( 'old.example.com', $result['smtp_host'] );
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

	public function test_url_shortener_auto_create_checkbox_on_tab(): void {
		$_POST['_ffc_tab'] = 'url_shortener';
		$new = array( 'url_shortener_auto_create' => '1' );
		$result = $this->invoke( 'save_url_shortener_settings', array( array(), $new ) );
		$this->assertSame( 1, $result['url_shortener_auto_create'] );
		// The module on/off flag is managed by the Modules tab, not this form.
		$this->assertArrayNotHasKey( 'url_shortener_enabled', $result );
	}

	public function test_url_shortener_form_preserves_enable_flag_and_zeroes_absent_auto_create(): void {
		$_POST['_ffc_tab'] = 'url_shortener';
		// An existing enabled flag must survive a form save with no checkboxes
		// present — the enable toggle now lives only on the Modules tab, so this
		// form must not zero it out (regression guard).
		$result = $this->invoke( 'save_url_shortener_settings', array( array( 'url_shortener_enabled' => 1 ), array() ) );
		$this->assertSame( 1, $result['url_shortener_enabled'], 'enable flag preserved' );
		$this->assertSame( 0, $result['url_shortener_auto_create'], 'auto_create zeroed when unchecked' );
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

	public function test_url_shortener_expose_types_subset_of_shortened(): void {
		$_POST['_ffc_tab']     = 'url_shortener';
		$_POST['ffc_settings'] = array(
			'url_shortener_post_types'        => array( 'post', 'page' ),
			// 'product' is exposed but NOT shortened → must be dropped.
			'url_shortener_expose_post_types' => array( 'post', 'product' ),
		);
		$result = $this->invoke( 'save_url_shortener_settings', array( array(), array() ) );
		$this->assertSame( array( 'post' ), $result['url_shortener_expose_post_types'] );
		unset( $_POST['ffc_settings'] );
	}

	public function test_url_shortener_expose_types_missing_on_tab_empty_array(): void {
		$_POST['_ffc_tab']     = 'url_shortener';
		$_POST['ffc_settings'] = array( 'url_shortener_post_types' => array( 'post' ) );
		$result = $this->invoke( 'save_url_shortener_settings', array( array(), array() ) );
		$this->assertSame( array(), $result['url_shortener_expose_post_types'] );
		unset( $_POST['ffc_settings'] );
	}

	public function test_url_shortener_expose_types_ignored_on_other_tab(): void {
		$_POST['_ffc_tab']     = 'general';
		$_POST['ffc_settings'] = array( 'url_shortener_expose_post_types' => array( 'post' ) );
		$result = $this->invoke( 'save_url_shortener_settings', array( array(), array() ) );
		$this->assertArrayNotHasKey( 'url_shortener_expose_post_types', $result );
		unset( $_POST['ffc_settings'] );
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
		$this->assertSame( array( 'ffc_end_user' ), $saved[1]['blocked_roles'] );
		$this->assertSame( 'https://site.test/dashboard', $saved[1]['redirect_url'] );
		$this->assertSame( '', $saved[1]['redirect_message'] );
		$this->assertFalse( $saved[1]['allow_admin_bar'] );
		$this->assertFalse( $saved[1]['bypass_for_admins'] );
	}

	public function test_user_access_settings_with_post_values(): void {
		Functions\when( 'home_url' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		$_POST['block_wp_admin']    = '1';
		$_POST['blocked_roles']     = array( 'subscriber', 'ffc_end_user' );
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
		$this->assertSame( array( 'subscriber', 'ffc_end_user' ), $saved['blocked_roles'] );
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
	// save_email_template_settings()
	// ==================================================================

	public function test_email_model_saved_when_posted(): void {
		Functions\when( 'sanitize_hex_color' )->alias( function ( $c ) {
			return preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', (string) $c ) ? $c : null;
		} );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();

		$_POST['ffc_email_template'] = array( 'header_bg' => '#123456' );
		$saved                       = null;
		Functions\when( 'update_option' )->alias( function ( $key, $val ) use ( &$saved ) {
			$saved = array( $key, $val );
			return true;
		} );
		Functions\expect( 'add_settings_error' )->once();

		$this->invoke( 'save_email_template_settings', array() );

		$this->assertSame( 'ffc_email_template', $saved[0] );
		$this->assertSame( '#123456', $saved[1]['header_bg'] );

		unset( $_POST['ffc_email_template'] );
	}

	public function test_email_model_skipped_when_not_posted(): void {
		unset( $_POST['ffc_email_template'] );
		$called = false;
		Functions\when( 'update_option' )->alias( function () use ( &$called ) {
			$called = true;
			return true;
		} );

		$this->invoke( 'save_email_template_settings', array() );

		$this->assertFalse( $called );
	}

	public function test_email_model_skipped_without_smtp_cap(): void {
		// Email Model is gated on ffc_manage_settings_smtp (#711): a manager
		// without it does not persist the model even when the form is posted.
		Functions\when( 'current_user_can' )->justReturn( false );
		$_POST['ffc_email_template'] = array( 'header_bg' => '#123456' );
		$called                      = false;
		Functions\when( 'update_option' )->alias( function () use ( &$called ) {
			$called = true;
			return true;
		} );

		$this->invoke( 'save_email_template_settings', array() );

		$this->assertFalse( $called );
		unset( $_POST['ffc_email_template'] );
	}

	// ==================================================================
	// handle_all_submissions()
	// ==================================================================

	public function test_handle_all_submissions_bails_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		// #1030: "absence of errors is the assertion" was not an assertion.
		// Say what must not be reached — the nonce checks the capability guard
		// stands in front of.
		Functions\expect( 'check_admin_referer' )->never();
		Functions\expect( 'wp_verify_nonce' )->never();

		$this->handler->handle_all_submissions();
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

<?php
/**
 * Vacuous-test baseline — generated.
 * Regenerate: FFC_UPDATE_VACUOUS_BASELINE=1 vendor/bin/phpunit --filter AssertionCoverage
 * Each entry is a test method that currently verifies nothing (no assertion
 * and no mock expectation, or only a literal assertTrue(true)) and is
 * therefore tolerated. The guard fails on any method NOT listed here (a new
 * test that proves nothing) and on any listed method that no longer
 * qualifies (it was fixed — lock the win in).
 *
 * This list is a debt register, not a target to grow.
 */

return array(
	'AppointmentCancellationHandlerTest.php::test_handle_request_no_op_when_query_var_absent',
	'AppointmentReceiptHandlerTest.php::test_handle_receipt_request_returns_early_without_query_var',
	'AssetHelperTest.php::test_enqueue_dark_mode_noop_when_off',
	'AudienceAdminAudienceTest.php::test_handle_actions_returns_early_without_permission',
	'AudienceAdminAudienceTest.php::test_handle_actions_shows_feedback_message',
	'AudienceAdminCalendarTest.php::test_handle_actions_returns_early_without_permission',
	'AudienceAdminCalendarTest.php::test_handle_actions_shows_feedback_message',
	'AudienceAdminEnvironmentTest.php::test_handle_actions_returns_early_without_permission',
	'AudienceAdminEnvironmentTest.php::test_handle_actions_shows_feedback_message',
	'AudienceAdminImportTest.php::test_handle_csv_import_does_nothing_without_action',
	'AudienceAdminPageTest.php::test_handle_form_submissions_skips_non_scheduling_page',
	'AudienceAdminSettingsTest.php::test_handle_global_holiday_actions_returns_early_without_permission',
	'AudienceAdminSettingsTest.php::test_handle_visibility_settings_does_nothing_without_post',
	'AudienceLoaderTest.php::test_register_rest_routes_creates_controller',
	'AudienceSampleCsvSourceTest.php::test_authorize_is_noop',
	'AutoloaderTest.php::test_autoload_ignores_foreign_namespace',
	'CsvTest.php::test_reader_from_string_close_idempotent',
	'CsvTest.php::test_writer_close_idempotent',
	'DashboardShortcodeTest.php::test_init_registers_shortcode_and_action',
	'DashboardShortcodeTest.php::test_send_nocache_headers_noop_when_no_post',
	'DashboardShortcodeTest.php::test_send_nocache_headers_noop_when_no_shortcode',
	'DeviceLimiterTest.php::test_record_signals_is_noop_when_disabled',
	'FormEditorTest.php::test_add_custom_metaboxes_registers_boxes',
	'FormEditorTest.php::test_enqueue_scripts_returns_early_on_wrong_hook',
	'FormEditorTest.php::test_enqueue_scripts_returns_early_on_wrong_post_type',
	'FrontendTest.php::test_frontend_assets_returns_early_when_no_post',
	'FrontendTest.php::test_frontend_assets_returns_early_when_no_shortcodes',
	'FrontendTest.php::test_frontend_assets_returns_early_when_post_is_not_wp_post',
	'PluginActivationSmokeTest.php::test_foreign_key_migration_composes_when_version_stale',
	'PublicFormsExportSourceTest.php::test_on_before_download_no_op_when_no_form_id',
	'RestControllerTest.php::test_suppress_notices_noop_when_not_rest_request',
	'SubmissionGuardsTest.php::test_nonce_guard_passes_when_valid',
	'SubmissionGuardsTest.php::test_preflight_passes_when_consent_and_email_present',
	'UrlShortenerAdminPageTest.php::test_enqueue_assets_skips_on_wrong_page',
	'VerificationHandlerTest.php::test_magic_token_rate_limited_returns_error',
);

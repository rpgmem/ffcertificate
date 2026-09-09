<?php
/**
 * Keys read from `ffc_settings` that `Settings::get_default_settings()`
 * does not declare (#993). A ratchet that may only shrink — see
 * tests/Unit/SettingsDefaultsTest.php for what the gap means and why
 * closing it is a decision rather than a sweep.
 *
 * Regenerate: FFC_UPDATE_UNDECLARED_KEYS_BASELINE=1 vendor/bin/phpunit --filter SettingsDefaults
 *
 * @package FreeFormCertificate\Tests
 */

return array(
	'activity_log_min_level',
	'activity_log_retention_days',
	'audience_csv_create_users_default',
	'csv_download_page_url',
	'dangerzone_reset_counter_default',
	'dark_mode',
	'debug_activity_log',
	'debug_admin',
	'debug_audience',
	'debug_browser_env',
	'debug_email_handler',
	'debug_encryption',
	'debug_form_processor',
	'debug_frontend',
	'debug_geofence',
	'debug_migrations',
	'debug_pdf_generator',
	'debug_qrcode',
	'debug_rest_api',
	'debug_self_scheduling',
	'debug_user_manager',
	'delete_data_on_uninstall',
	'disable_all_emails',
	'enable_activity_log',
	'logo_gov',
	'logo_org',
	'main_address',
	'public_csv_sync_max_rows',
	'required_certificate_tags',
	'url_shortener_auto_create',
	'url_shortener_code_length',
	'url_shortener_enabled',
);

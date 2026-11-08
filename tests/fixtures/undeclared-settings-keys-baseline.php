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
	'required_certificate_tags',
	'required_form_fields',
);

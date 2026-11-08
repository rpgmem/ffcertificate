<?php
/**
 * Native-dialog register (#2534) -- generated.
 * Regenerate: FFC_UPDATE_NATIVE_DIALOG_BASELINE=1 vendor/bin/phpunit --filter NativeDialogRatchet
 *
 * File => alert / confirm / prompt => how many calls it still makes. A debt
 * register that only shrinks; the remaining ones are tracked in #2535.
 */

return array(
	'assets/js/ffc-admin-activity-log.js' => array(
		'alert' => 1,
	),
	'assets/js/ffc-admin-autosave.js' => array(
		'confirm' => 2,
	),
	'assets/js/ffc-admin-field-builder.js' => array(
		'confirm' => 1,
	),
	'assets/js/ffc-admin-migrations.js' => array(
		'confirm' => 1,
	),
	'assets/js/ffc-admin-pdf.js' => array(
		'alert' => 2,
		'confirm' => 1,
		'prompt' => 1,
	),
	'assets/js/ffc-admin-submission-edit.js' => array(
		'alert' => 2,
		'confirm' => 1,
	),
	'assets/js/ffc-admin-submissions-bulk.js' => array(
		'confirm' => 2,
	),
	'assets/js/ffc-admin.js' => array(
		'alert' => 1,
		'confirm' => 1,
	),
	'assets/js/ffc-audience-admin.js' => array(
		'alert' => 1,
		'confirm' => 1,
	),
	'assets/js/ffc-cache-actions.js' => array(
		'confirm' => 1,
	),
	'assets/js/ffc-calendar-editor.js' => array(
		'alert' => 7,
		'confirm' => 2,
	),
	'assets/js/ffc-cert-email-mode.js' => array(
		'confirm' => 1,
	),
	'assets/js/ffc-core.js' => array(
		'confirm' => 1,
	),
	'assets/js/ffc-custom-fields-admin.js' => array(
		'alert' => 2,
		'confirm' => 3,
	),
	'assets/js/ffc-date-messages-admin.js' => array(
		'confirm' => 3,
	),
	'assets/js/ffc-email-model.js' => array(
		'confirm' => 1,
	),
	'assets/js/ffc-email-restore-default.js' => array(
		'confirm' => 1,
	),
	'assets/js/ffc-geofence-admin.js' => array(
		'alert' => 1,
	),
	'assets/js/ffc-locations-crud.js' => array(
		'confirm' => 1,
	),
	'assets/js/ffc-recruitment-admin.js' => array(
		'alert' => 2,
	),
	'assets/js/ffc-recruitment-candidates-import.js' => array(
		'alert' => 1,
	),
	'assets/js/ffc-recruitment-import-batched.js' => array(
		'confirm' => 2,
	),
	'assets/js/ffc-recruitment-notice-edit.js' => array(
		'alert' => 8,
	),
	'assets/js/ffc-reregistration-admin.js' => array(
		'alert' => 2,
		'confirm' => 1,
	),
	'assets/js/ffc-role-editor.js' => array(
		'alert' => 4,
	),
	'assets/js/ffc-self-scheduling-admin-appointments.js' => array(
		'alert' => 2,
		'prompt' => 1,
	),
	'assets/js/ffc-url-shortener-admin.js' => array(
		'confirm' => 1,
	),
	'assets/js/ffc-user-capabilities.js' => array(
		'alert' => 2,
	),
	'includes/audience/class-ffc-audience-admin-audience-renderer.php' => array(
		'confirm' => 1,
	),
	'includes/settings/views/ffc-tab-cache.php' => array(
		'confirm' => 1,
	),
	'templates/admin/audience/general-tab.php' => array(
		'confirm' => 1,
	),
);

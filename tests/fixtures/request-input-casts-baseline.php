<?php
/**
 * Request-input cast baseline (#1087 passo 2) — generated.
 * Regenerate: FFC_UPDATE_REQUEST_CAST_BASELINE=1 vendor/bin/phpunit --filter RequestInputCast
 *
 * Each entry is a direct integer cast over a superglobal that exists today
 * and is therefore tolerated. The guard fails on any cast NOT listed here
 * (a new one — route it through RequestInput::get_post_int()/get_get_int())
 * and on any listed cast that no longer exists (one was fixed — lock it in).
 *
 * This is a debt register, not a target to grow.
 */

return array(
	'admin/class-ffc-admin-submission-edit-page.php::POST[submission_id]#1',
	'admin/class-ffc-admin-user-capabilities.php::POST[user_id]#1',
	'admin/class-ffc-admin.php::GET[filter_form_id]#1',
	'admin/class-ffc-admin.php::GET[submission_id]#1',
	'admin/class-ffc-cert-template-admin-screen.php::POST[post_id]#1',
	'admin/class-ffc-form-editor.php::POST[qty]#1',
	'admin/class-ffc-form-editor.php::POST[template_id]#1',
	'admin/class-ffc-submissions-list.php::GET[filter_form_id]#1',
	'admin/class-ffc-submissions-list.php::GET[filter_form_id]#2',
	'audience/class-ffc-audience-admin-audience.php::GET[id]#1',
	'audience/class-ffc-audience-admin-audience.php::GET[id]#2',
	'audience/class-ffc-audience-admin-audience.php::GET[id]#3',
	'audience/class-ffc-audience-admin-audience.php::GET[remove_user]#1',
	'audience/class-ffc-audience-admin-audience.php::POST[audience_id]#1',
	'audience/class-ffc-audience-admin-audience.php::POST[audience_id]#2',
	'audience/class-ffc-audience-admin-audience.php::POST[audience_parent]#1',
	'audience/class-ffc-audience-admin-calendar.php::GET[delete_holiday]#1',
	'audience/class-ffc-audience-admin-calendar.php::GET[id]#1',
	'audience/class-ffc-audience-admin-calendar.php::GET[id]#2',
	'audience/class-ffc-audience-admin-calendar.php::GET[id]#3',
	'audience/class-ffc-audience-admin-calendar.php::POST[schedule_future_days]#1',
	'audience/class-ffc-audience-admin-calendar.php::POST[schedule_id]#1',
	'audience/class-ffc-audience-admin-calendar.php::POST[schedule_id]#2',
	'audience/class-ffc-audience-admin-environment.php::GET[id]#1',
	'audience/class-ffc-audience-admin-environment.php::GET[id]#2',
	'audience/class-ffc-audience-admin-environment.php::POST[environment_id]#1',
	'audience/class-ffc-audience-admin-environment.php::POST[environment_schedule]#1',
	'audience/class-ffc-audience-admin-import.php::POST[export_audience_id]#1',
	'audience/class-ffc-audience-admin-import.php::POST[import_audience_id]#1',
	'frontend/class-ffc-dynamic-fragments.php::POST[blocks]#1',
	'frontend/class-ffc-preflight-telemetry.php::POST[form_id]#1',
	'frontend/submission/class-ffc-form-config-resolver.php::POST[form_id]#1',
	'frontend/submission/class-ffc-schedule-exception-guard.php::POST[form_id]#1',
	'reregistration/class-ffc-reregistration-admin.php::GET[id]#1',
	'reregistration/class-ffc-reregistration-admin.php::POST[rereg_reminder_days]#1',
	'reregistration/class-ffc-reregistration-admin.php::POST[reregistration_id]#1',
	'reregistration/class-ffc-reregistration-ajax-handler.php::POST[submission_id]#1',
	'reregistration/class-ffc-reregistration-ajax-handler.php::POST[submission_id]#2',
	'reregistration/class-ffc-reregistration-frontend.php::POST[reregistration_id]#1',
	'reregistration/class-ffc-reregistration-frontend.php::POST[reregistration_id]#2',
	'reregistration/class-ffc-reregistration-frontend.php::POST[reregistration_id]#3',
	'reregistration/class-ffc-reregistration-submission-actions.php::GET[sub_id]#1',
	'reregistration/class-ffc-reregistration-submission-actions.php::GET[sub_id]#2',
	'reregistration/class-ffc-reregistration-submission-actions.php::GET[sub_id]#3',
	'reregistration/class-ffc-reregistration-submission-actions.php::POST[reregistration_id]#1',
	'self-scheduling/class-ffc-self-scheduling-cleanup-handler.php::POST[calendar_id]#1',
	'shortcodes/class-ffc-dashboard-view-mode.php::GET[ffc_view_as_user]#1',
	'url-shortener/class-ffc-url-shortener-admin-page.php::GET[id]#1',
	'url-shortener/class-ffc-url-shortener-admin-page.php::GET[id]#2',
	'url-shortener/class-ffc-url-shortener-admin-page.php::GET[id]#3',
	'url-shortener/class-ffc-url-shortener-admin-page.php::GET[id]#4',
	'url-shortener/class-ffc-url-shortener-admin-page.php::GET[id]#5',
	'url-shortener/class-ffc-url-shortener-admin-page.php::GET[id]#6',
	'url-shortener/class-ffc-url-shortener-admin-page.php::GET[id]#7',
	'url-shortener/class-ffc-url-shortener-admin-page.php::GET[id]#8',
);

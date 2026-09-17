<?php
/**
 * Uninstall handler for FFCertificate plugin.
 *
 * Removes all plugin data when the plugin is deleted via the WordPress admin.
 * This file is called automatically by WordPress — do NOT call it directly.
 *
 * @since 4.6.11
 * @package FreeFormCertificate
 */

// Abort if not called by WordPress uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// ──────────────────────────────────────
// 0. Danger Zone opt-in gate
// ──────────────────────────────────────
// The heavy cleanup below (tables, options, CPT posts, roles, caps,
// user meta) only runs when the admin opted in via Settings →
// Advanced → Danger Zone → "Delete all plugin data on uninstall".
// Default OFF, matching the WooCommerce / EDD / Yoast convention so
// deleting the plugin never wipes data unintentionally.
//
// Cron hooks are cleared regardless — their callbacks reference plugin
// code that's about to be gone, so leaving them registered would
// produce "no callback" warnings until WP self-heals on the next visit.
//
// SettingsReader isn't available here — uninstall.php runs in a
// stripped-down context that doesn't load the plugin's autoloader —
// so read the option directly from ffc_settings.
$ffcertificate_settings = get_option( 'ffc_settings', array() );
$ffcertificate_purge    = is_array( $ffcertificate_settings )
	&& '1' === (string) ( $ffcertificate_settings['delete_data_on_uninstall'] ?? '0' );

if ( ! $ffcertificate_purge ) {
	// Cron-only cleanup: leave data + structure untouched but clear
	// scheduled events that point at code about to be removed.
	wp_clear_scheduled_hook( 'ffcertificate_daily_cleanup_hook' );
	wp_clear_scheduled_hook( 'ffcertificate_process_submission_hook' );
	// #1248's internal hook, which replaced the one above in the scheduling.
	// The old one stays: there may be an event pending in the old shape.
	wp_clear_scheduled_hook( 'ffc_process_submission_async' );
	wp_clear_scheduled_hook( 'ffcertificate_warm_cache_hook' );
	wp_clear_scheduled_hook( 'ffcertificate_reregistration_expire_hook' );
	wp_clear_scheduled_hook( 'ffcertificate_self_scheduling_reminder_scan' );
	wp_clear_scheduled_hook( 'ffc_daily_cleanup_hook' );
	wp_clear_scheduled_hook( 'ffc_process_submission_hook' );
	wp_clear_scheduled_hook( 'ffc_warm_cache_hook' );
	wp_clear_scheduled_hook( 'ffc_cloudflare_cidr_refresh' );
	return;
}

// ──────────────────────────────────────
// 1. Drop all plugin database tables
// ──────────────────────────────────────
//
// **The set is discovered, and the list below is the declaration** (#1291).
// `ffc_` is this plugin's namespace and `$wpdb->prefix` scopes the pattern to
// this site, so `SHOW TABLES LIKE` names exactly what the plugin owns —
// including a table somebody forgot to declare, which is the failure this
// replaces: `ffc_recruitment_import_staging` and `ffc_recruitment_import_jobs`
// were created by their activator like every other table and left behind on
// every install until the CI fresh-install check (#994) found them. The guard
// caught that after it shipped; discovery makes it unshippable.
//
// **The two sets are UNIONED, not swapped.** A scan that returns nothing —
// a permission error, a server that answers oddly — would otherwise read as
// "no tables to drop" and delete nothing at all, which is the #1071 / #1094
// rule applied to a deletion. The declared names are attempted either way, and
// `DROP TABLE IF EXISTS` makes the overlap free.
//
// **Drop order does not matter, and the previous "children first" ordering was
// protecting against something that does not exist.** Measured: the only path
// in this plugin that ever creates a FOREIGN KEY is `MigrationForeignKeys`
// (`dbDelta()` cannot emit them and no `CREATE TABLE` declares one), and all
// seven of its constraints point at `wp_users`. No `ffc_*` table references
// another, and dropping the child side of a constraint is never blocked — so
// there is nothing to order and no reason to touch `FOREIGN_KEY_CHECKS`.
//
// **Single site, by construction.** `$wpdb->prefix` resolves to the site
// running the uninstall, so a multisite network keeps its siblings'
// `wp_<id>_ffc_*` tables. That matches what the plugin is rather than falling
// short of a promise: `includes/` contains zero occurrences of
// `is_multisite()`, `switch_to_blog()` or `get_sites()`, and `ffcertificate.php`
// declares no `Network:` header. A discovered set would not change this — it
// resolves one prefix either way. Recorded here so the next reader does not
// mistake it for a regression introduced by the sweep.
$ffcertificate_tables = array(
	// Recruitment.
	// The two CSV-import tables were once missing from this list, which is the
	// defect the discovery above now makes unshippable; they stay declared
	// because the fresh-install gate reads this list as the manifest.
	$wpdb->prefix . 'ffc_recruitment_import_staging',
	$wpdb->prefix . 'ffc_recruitment_import_jobs',
	$wpdb->prefix . 'ffc_recruitment_call',
	$wpdb->prefix . 'ffc_recruitment_classification',
	$wpdb->prefix . 'ffc_recruitment_notice_adjutancy',
	$wpdb->prefix . 'ffc_recruitment_candidate',
	$wpdb->prefix . 'ffc_recruitment_notice',
	$wpdb->prefix . 'ffc_recruitment_adjutancy',
	$wpdb->prefix . 'ffc_recruitment_reason',
	// Reregistration. The two CSV-import tables (#1214) hold
	// only in-flight job state with a TTL, but they are created by
	// ReregistrationActivator like the rest, so they belong here — the
	// fresh-install gate compares this manifest against what activation
	// actually creates, in BOTH directions.
	$wpdb->prefix . 'ffc_reregistration_import_staging',
	$wpdb->prefix . 'ffc_reregistration_import_jobs',
	$wpdb->prefix . 'ffc_reregistration_submissions',
	$wpdb->prefix . 'ffc_reregistration_audiences',
	$wpdb->prefix . 'ffc_reregistrations',
	// Custom fields.
	$wpdb->prefix . 'ffc_custom_fields',
	// Audience.
	$wpdb->prefix . 'ffc_audience_booking_users',
	$wpdb->prefix . 'ffc_audience_booking_audiences',
	$wpdb->prefix . 'ffc_audience_bookings',
	$wpdb->prefix . 'ffc_audience_members',
	$wpdb->prefix . 'ffc_audiences',
	$wpdb->prefix . 'ffc_audience_holidays',
	$wpdb->prefix . 'ffc_audience_environments',
	$wpdb->prefix . 'ffc_audience_schedule_permissions',
	$wpdb->prefix . 'ffc_audience_schedules',
	// Self-scheduling.
	$wpdb->prefix . 'ffc_self_scheduling_blocked_dates',
	$wpdb->prefix . 'ffc_self_scheduling_appointments',
	$wpdb->prefix . 'ffc_self_scheduling_calendars',
	// Rate limiting + device fingerprinting.
	$wpdb->prefix . 'ffc_rate_limit_logs',
	$wpdb->prefix . 'ffc_rate_limits',
	$wpdb->prefix . 'ffc_device_signals',
	// URL Shortener.
	$wpdb->prefix . 'ffc_short_urls',
	// User profiles.
	$wpdb->prefix . 'ffc_user_profiles',
	// Core.
	$wpdb->prefix . 'ffc_activity_log',
	$wpdb->prefix . 'ffc_submissions',
);

// The discovered half. `esc_like()` is load-bearing on the underscore: `_` is a
// LIKE wildcard, so an unescaped `ffc_` would also match `ffcX` — a table
// belonging to somebody else.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Resolves the plugin's own ffc_* tables by prefix; WordPress has no API for this and there is nothing to cache during an uninstall.
$ffcertificate_found = $wpdb->get_col(
	$wpdb->prepare(
		'SHOW TABLES LIKE %s',
		$wpdb->esc_like( $wpdb->prefix . 'ffc_' ) . '%'
	)
);

foreach ( array_unique( array_merge( $ffcertificate_tables, array_map( 'strval', (array) $ffcertificate_found ) ) ) as $ffcertificate_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Drops the plugin's own ffc_* tables; WordPress has no API for them and there is nothing left to cache once they are gone.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $ffcertificate_table ) );
}

// ──────────────────────────────────────
// 2. Delete all plugin options
// ──────────────────────────────────────
$ffcertificate_options = array(
	'ffc_settings',
	'ffc_db_version',
	'ffc_verification_page_id',
	'ffc_dashboard_page_id',
	'ffc_geolocation_settings',
	'ffc_rate_limit_settings',
	'ffc_captcha_mode_notice_dismissed',
	'ffc_rate_limit_db_version',
	'ffc_user_access_settings',
	'ffc_global_holidays',
	'ffc_cleanup_days',
	'ffc_migration_data_cleanup_completed',
	'ffc_migration_name_normalization_errors',
	'ffc_migration_name_normalization_changes',
	'ffc_migration_name_normalization_last_run',
	'ffc_encryption_migration_completed_date',
	'ffc_migration_user_link_errors',
	'ffc_migration_user_capabilities_errors',
	'ffc_migration_user_capabilities_changes',
	'ffc_migration_user_capabilities_last_run',
	'ffc_columns_dropped_date',
	'ffc_migration_user_profiles_errors',
	'ffc_migration_user_profiles_last_run',
	// Schema-version markers (audited gap — were never on the list).
	'ffc_activity_log_db_version',
	// Written by MigrationForeignKeys since 6.18.0, i.e. after the audit that
	// added its five siblings here — so it was the only one left behind.
	'ffc_foreign_keys_db_version',
	'ffc_perf_indexes_db_version',
	'ffc_submissions_db_version',
	// Version gates of the activator chains `Loader` runs on `plugins_loaded`,
	// so the schema heals after an in-place update without probing on every
	// request (#1231). Declared here because the `fresh-install` job compares in
	// BOTH directions: an option the activation writes and this manifest does
	// not declare fails CI.
	'ffc_self_scheduling_schema_version',
	'ffc_audience_schema_version',
	'ffc_url_shortener_schema_version',
	// The two chains #1311 added, which had no runtime caller at all until then.
	'ffc_reregistration_schema_version',
	'ffc_user_dashboard_schema_version',
	// Per-feature migration completion markers (audited gap).
	'ffc_sibling_instants_unix_migrated',
	'ffc_submission_date_unix_migrated',
	'ffc_submitted_at_unix_migrated',
	// Audience module options (audited gap — were never on the list).
	'ffc_aud_multiple_audiences_color',
	'ffc_aud_private_display_mode',
	'ffc_aud_scheduling_message',
	'ffc_aud_visibility_message',
	// Self-scheduling module options (audited gap).
	'ffc_ss_business_hours_booking_message',
	'ffc_ss_business_hours_viewing_message',
	'ffc_ss_private_display_mode',
	'ffc_ss_scheduling_message',
	'ffc_ss_visibility_message',
	// Recruitment module (v6.0.0).
	'ffc_recruitment_settings',
	'ffc_recruitment_schema_version',
	'ffc_recruitment_tables_version',
	// State (per-target cursor + fingerprint + completion) of the migration that
	// finishes the key rotation over the areas the first one did not cover
	// (#1236).
	'ffc_key_rotation_remaining_state',
	'ffc_recruitment_public_cache_version',
	// The admin's chosen record (ficha) template, written only when the
	// Reregistration tab is saved -- which is why the fresh-install gate never
	// saw it: that gate compares what ACTIVATION writes, and nothing here is
	// written at activation. Found while renaming the family in #1264.
	'ffc_reregistration_ficha_template',
	'ffc_ip_diagnostics_settings',
	'ffc_cloudflare_cidr_cache',
	// One-shot capability / role / migration markers, all written during
	// activation and none of them previously listed here (found by the CI
	// fresh-install check, #994 — it compares what activation actually writes
	// against this list, which a static grep cannot do because most of these
	// are written through a constant or a variable rather than a literal).
	'ffc_activity_log_export_cap_v1',
	'ffc_admin_caps_version_v6',
	'ffc_admin_role_assigned_v1',
	'ffc_admin_role_assigned_v2',
	'ffc_audience_cap_off_subscriber_v1',
	'ffc_audience_email_tokens_migrated_v1',
	'ffc_delete_caps_granted_v1',
	'ffc_email_templates_cap_v1',
	'ffc_export_caps_granted_v1',
	'ffc_false_caps_stripped_v1',
	'ffc_import_caps_granted_v1',
	// _v2 re-runs the same idempotent seeding so `ffc_import_reregistration`
	// (#1214) reaches installs that already flagged _v1 as done. The old flag
	// stays listed: an install upgraded through 6.9.0 carries both.
	'ffc_import_caps_granted_v2',
	'ffc_migration_custom_fields_tables_completed',
	'ffc_migration_dynamic_rereg_fields_completed',
	'ffc_migration_rename_capabilities_completed',
	'ffc_migration_self_scheduling_tables_completed',
	'ffc_rbac_caps_renamed_v1',
	'ffc_rbac_roles_renamed_v1',
	'ffc_reasons_caps_wired_v1',
	'ffc_recruitment_email_hub_v1',
	'ffc_settings_split_caps_v1',
	'ffc_taxonomy_caps_renamed_v1',
	'ffc_url_shortener_export_cap_v1',
	'ffc_url_shortener_rewrite_version',
);

foreach ( $ffcertificate_options as $ffcertificate_option ) {
	delete_option( $ffcertificate_option );
}

// ──────────────────────────────────────
// 3. Delete transients
// ──────────────────────────────────────
// The activity-log stats triple is explicitly named to keep the obvious
// case in code; the wildcard sweep below catches every other ffc_* /
// _ffc_* transient (per-user error transients, per-form caches, etc.)
// without having to enumerate them.
delete_transient( 'ffc_activity_stats_7' );
delete_transient( 'ffc_activity_stats_30' );
delete_transient( 'ffc_activity_stats_90' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall-time wildcard delete; bounded by table scope, no user input.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_ffc\\_%' OR option_name LIKE '\\_transient\\_timeout\\_ffc\\_%' OR option_name LIKE '\\_transient\\_\\_ffc\\_%' OR option_name LIKE '\\_transient\\_timeout\\_\\_ffc\\_%'" );

// ──────────────────────────────────────
// 4. Clear scheduled cron hooks
// ──────────────────────────────────────
wp_clear_scheduled_hook( 'ffcertificate_daily_cleanup_hook' );
wp_clear_scheduled_hook( 'ffcertificate_process_submission_hook' );
// #1248's internal hook, which replaced the one above in the scheduling.
// The old one stays: an event scheduled the old way may still be pending.
wp_clear_scheduled_hook( 'ffc_process_submission_async' );
wp_clear_scheduled_hook( 'ffcertificate_warm_cache_hook' );
wp_clear_scheduled_hook( 'ffcertificate_reregistration_expire_hook' );
wp_clear_scheduled_hook( 'ffcertificate_self_scheduling_reminder_scan' );
wp_clear_scheduled_hook( 'ffc_cloudflare_cidr_refresh' );

// Clear legacy cron hooks from pre-4.6.15 versions.
wp_clear_scheduled_hook( 'ffc_daily_cleanup_hook' );
wp_clear_scheduled_hook( 'ffc_process_submission_hook' );
wp_clear_scheduled_hook( 'ffc_warm_cache_hook' );

// ──────────────────────────────────────
// 5. Delete all FFC custom posts (CPTs)
// ──────────────────────────────────────
// Both CPTs registered by the plugin — `ffc_form` (certificate forms)
// and `ffc_self_scheduling` (self-scheduling calendars). wp_delete_post
// with force=true also clears each post's post_meta + revisions.
foreach ( array( 'ffc_form', 'ffc_self_scheduling' ) as $ffcertificate_cpt ) {
	$ffcertificate_post_ids = get_posts(
		array(
			'post_type'      => $ffcertificate_cpt,
			'posts_per_page' => -1,
			'post_status'    => 'any',
			'fields'         => 'ids',
		)
	);

	if ( ! empty( $ffcertificate_post_ids ) ) {
		foreach ( $ffcertificate_post_ids as $ffcertificate_post_id ) {
			wp_delete_post( $ffcertificate_post_id, true );
		}
	}
}

// ──────────────────────────────────────
// 6. Remove FFC roles
// ──────────────────────────────────────
remove_role( 'ffc_end_user' );
remove_role( 'ffc_recruitment_manager' );

// Module tier roles (#739 §3.2 — viewer/operator/manager ladder per domain).
// Listed inline rather than through `CapabilityManager::remove_module_roles()`
// because uninstall.php runs in a stripped-down context that doesn't load the
// plugin's autoloader. The legacy slugs below are kept so an uninstall of an
// install that never ran the rename migrations still strips the old roles.
foreach (
	array(
		'ffc_administrator',
		'ffc_readonly',
		// Certificates ladder.
		'ffc_certificates_viewer',
		'ffc_certificates_operator',
		'ffc_certificates_manager',
		'ffc_certificates_admin',
		// Forms.
		'ffc_forms_viewer',
		'ffc_forms_manager',
		// Appointments ladder.
		'ffc_appointments_viewer',
		'ffc_appointments_operator',
		'ffc_appointments_manager',
		'ffc_appointments_admin',
		// Calendars.
		'ffc_calendars_viewer',
		'ffc_calendars_manager',
		// Audiences ladder.
		'ffc_audiences_viewer',
		'ffc_audiences_operator',
		'ffc_audiences_manager',
		// Reregistration ladder.
		'ffc_reregistration_viewer',
		'ffc_reregistration_operator',
		'ffc_reregistration_manager',
		// Recruitment ladder (manager removed above).
		'ffc_recruitment_viewer',
		'ffc_recruitment_operator',
		'ffc_recruitment_admin',
		// Legacy slugs (pre-#739 renames) — harmless no-ops once migrated.
		'ffc_operator',
		'ffc_self_scheduling_manager',
		'ffc_certificate_manager',
		'ffc_audience_manager',
		'ffc_recruitment_auditor',
	) as $ffc_legacy_role
) {
	remove_role( $ffc_legacy_role );
}

// ──────────────────────────────────────
// 7. Clean up user meta
// ──────────────────────────────────────
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk statement over the plugin's own ffc_* user-meta keys, matched by prefix — the WordPress meta API cannot filter by key prefix, and doing it per user per key would be thousands of queries.
$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => 'ffc_registration_date' ) );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk statement over the plugin's own ffc_* user-meta keys, matched by prefix — the WordPress meta API cannot filter by key prefix, and doing it per user per key would be thousands of queries.
$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => 'ffc_custom_fields_data' ) );

// ──────────────────────────────────────
// 8. Remove FFC capabilities from users and roles
// ──────────────────────────────────────
//
// **Removal is by PREFIX, not by a list of names.** `ffc_` is this plugin's
// namespace, so every capability it has ever created carries it — which makes
// "remove every `ffc_*` key" both complete and impossible to fall behind. The
// list this replaced named 31 of the 60 live capabilities, so 40 survived
// uninstall on every install; the sharpest case was `ffc_import_recruitment`,
// live and unremoved, while `ffc_import_recruitment_csv` — its own pre-rename
// slug, dead since `CapabilityMigrator::taxonomy_cap_renames()` retired it —
// *was* listed. The dead name was cleaned up and the live one was not (#1290).
//
// The precondition the sweep rests on — every live capability starts with
// `ffc_` — is not assumed here: `UninstallCapabilitySweepTest` asserts it
// against `CapabilityManager::get_all_capabilities()`, so a capability named
// without the prefix fails CI rather than silently outliving the plugin.
//
// The three names below are the only ones the sweep cannot reach, and they are
// the reason this array still exists.
$ffcertificate_legacy_caps = array(
	// Pre-6.2.0 certificate capabilities, from before the plugin namespaced
	// its capability names. The rename migration that retired them was itself
	// removed in 6.18.0 (#809), so an install upgrading from before 6.2.0
	// straight to a current release still carries them. Each is followed by
	// the name that replaced it.
	'view_own_certificates', // ffc_view_own_certificates.
	'download_own_certificates', // ffc_download_own_certificates.
	'view_certificate_history', // ffc_view_own_certificate_history, via ffc_view_certificate_history (6.9.0).
);

// Users carrying ANY `ffc_*` grant. Capabilities and roles share this one
// serialized meta — a role appears in it as `s:12:"ffc_end_user";b:1;`, exactly
// like a capability — so the prefix finds both, and the sweep below removes a
// stale membership row of a role that section 6 has just deleted.
//
// The three legacy names have no prefix and so cannot widen this filter, which
// is a limit worth stating rather than working around: they were only ever
// granted to users who already held the `ffc_user` role (the 4.4.0 migration
// that seeded them ran over `'role' => 'ffc_user'`, and the profile screen that
// granted them by hand only rendered for that role), and `ffc_user` matches the
// prefix. So a user carrying them without any `ffc_*` token is not a state this
// plugin ever produced.
//
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk statement over the plugin's own ffc_* grants, matched by prefix — the WordPress meta API cannot filter by value prefix, and walking every user of the install instead would be unbounded.
$ffcertificate_user_ids = $wpdb->get_col(
	$wpdb->prepare(
		'SELECT user_id FROM %i WHERE meta_key = %s AND meta_value LIKE %s',
		$wpdb->usermeta,
		$wpdb->prefix . 'capabilities',
		'%ffc_%'
	)
);

foreach ( $ffcertificate_user_ids as $ffcertificate_uid ) {
	$ffcertificate_user = new WP_User( (int) $ffcertificate_uid );

	// `array_keys()` snapshots before the loop: `remove_cap()` rebuilds
	// `$user->caps`, so iterating it directly would mutate what is being read.
	foreach ( array_keys( (array) $ffcertificate_user->caps ) as $ffcertificate_cap ) {
		if ( 0 === strpos( (string) $ffcertificate_cap, 'ffc_' ) ) {
			$ffcertificate_user->remove_cap( (string) $ffcertificate_cap );
		}
	}

	foreach ( $ffcertificate_legacy_caps as $ffcertificate_cap ) {
		$ffcertificate_user->remove_cap( $ffcertificate_cap );
	}
}

// Roles are the second residue, and it is not a corner case — it is guaranteed.
// Section 6 deletes the FFC roles wholesale, which takes their capabilities with
// them, but FFC capabilities also sit on roles this plugin does not own.
//
// The certain one is `subscriber`: `AudienceActivator::register_capabilities()`
// grants it `ffc_view_own_audience_bookings` at activation, so EVERY install
// has carried an FFC capability on a WordPress core role since the day it was
// activated, and nothing removed it. Measured on a fresh CI install, not
// inferred.
//
// Another is `administrator`, on any install that passed through a release before
// 6.16.0 (#747): `Loader::ensure_admin_capabilities()` granted every
// `ADMIN_CAPABILITIES` entry to the native administrator role until that
// release moved the admin tier onto `ffc_administrator`. It stopped granting,
// and nothing ever removed what it had granted.
//
// The other is any custom role holding a `manage` capability, because the
// one-shot back-fills in `CapabilityMigrator` walk `wp_roles()->roles` in full
// and seed the matching `delete` / `export` / `import` capability onto every
// role that qualifies — administrator and custom roles included.
//
// Same sweep, same reason. `remove_cap()` writes the role option per call,
// which is a handful of writes on a handful of roles, once, during uninstall.
$ffcertificate_roles = wp_roles();

foreach ( array_keys( $ffcertificate_roles->roles ) as $ffcertificate_role_slug ) {
	$ffcertificate_role = get_role( (string) $ffcertificate_role_slug );
	if ( ! $ffcertificate_role instanceof WP_Role ) {
		continue;
	}

	foreach ( array_keys( (array) $ffcertificate_role->capabilities ) as $ffcertificate_cap ) {
		if ( 0 === strpos( (string) $ffcertificate_cap, 'ffc_' ) ) {
			$ffcertificate_role->remove_cap( (string) $ffcertificate_cap );
		}
	}

	foreach ( $ffcertificate_legacy_caps as $ffcertificate_cap ) {
		if ( isset( $ffcertificate_role->capabilities[ $ffcertificate_cap ] ) ) {
			$ffcertificate_role->remove_cap( $ffcertificate_cap );
		}
	}
}

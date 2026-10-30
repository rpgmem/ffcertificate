<?php
/**
 * CapabilityManager
 *
 * Manages FFC user capabilities, roles, and permission granting.
 * Extracted from UserManager (v4.12.2) for single-responsibility.
 *
 * @package FreeFormCertificate\UserDashboard
 * @since 4.12.2
 */

declare(strict_types=1);

namespace FreeFormCertificate\UserDashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manager for capability operations.
 */
class CapabilityManager {

	use \FreeFormCertificate\Core\EmailHelperTrait;

	/**
	 * Context constants for capability granting
	 */
	public const CONTEXT_CERTIFICATE = 'certificate';
	public const CONTEXT_APPOINTMENT = 'appointment';
	public const CONTEXT_AUDIENCE    = 'audience';

	/**
	 * Context key: recruitment-module candidate promotion path.
	 *
	 * Used by `UserCreator::get_or_create_user_dual()` when the candidate is
	 * being linked or created via the recruitment CSV importer (sprint 4) or
	 * a manual admin edit (sprint 9.1). No per-user caps are granted on this
	 * context — candidates rely on the `ffc_end_user` role's baseline `read` cap
	 * to access their dashboard "Minhas Convocações" section. The
	 * `ffc_manage_recruitment` admin cap is registered separately on plugin
	 * activation (see {@see RoleRegistrar::register_recruitment_manager_role()}).
	 *
	 * @since 6.0.0
	 */
	public const CONTEXT_RECRUITMENT = 'recruitment';

	/**
	 * Context key: reregistration CSV import (#1214).
	 *
	 * A no-op for capability granting, for the same reason
	 * {@see self::CONTEXT_RECRUITMENT} is: the reregistration user surface has
	 * no per-user caps of its own — it rides the `ffc_end_user` role's baseline
	 * `read` cap, and `ffc_manage_reregistration` / `ffc_import_reregistration`
	 * are admin caps registered on activation, never granted at import time.
	 *
	 * It exists rather than the import reusing `CONTEXT_CERTIFICATE`, which
	 * would grant the three certificate caps to somebody who may hold no
	 * certificate — a grant nothing asked for, recorded in the grant log as if
	 * it had been.
	 *
	 * @since 6.26.0
	 */
	public const CONTEXT_REREGISTRATION = 'reregistration';

	/**
	 * All certificate-related capabilities.
	 *
	 * @since 4.4.0
	 * @since 6.2.0 Renamed from `view_own_certificates`, `download_own_certificates`,
	 *              `view_certificate_history` (no FFC prefix) to the consistent
	 *              `ffc_*` namespace. Migration in `LegacyCapMigration` rewrites
	 *              old grants on every user + the `ffc_end_user` role definition.
	 */
	public const CERTIFICATE_CAPABILITIES = array(
		'ffc_view_own_certificates',
		'ffc_download_own_certificates',
		'ffc_view_own_certificate_history',
	);

	/**
	 * All appointment-related capabilities
	 */
	public const APPOINTMENT_CAPABILITIES = array(
		'ffc_book_own_appointments',
		'ffc_view_own_appointments',
		'ffc_cancel_own_appointments',
	);

	/**
	 * All audience-related capabilities
	 *
	 * @since 4.9.3
	 */
	public const AUDIENCE_CAPABILITIES = array(
		'ffc_view_own_audience_bookings',
	);

	/**
	 * Admin-level capabilities (not granted by default).
	 *
	 * @since 4.9.3
	 * @since 6.2.0 Expanded with module-management caps + per-domain
	 *              recruitment caps to replace blanket `manage_options`
	 *              gates with delegable, granular permissions. The pre-6.2.0
	 *              caps (`ffc_bypass_appointments`, `ffc_manage_reregistration`,
	 *              `ffc_manage_recruitment`) remain unchanged so any user
	 *              already holding them keeps their access; the new caps
	 *              add scoped delegation paths.
	 */
	public const ADMIN_CAPABILITIES = array(
		// Pre-6.2.0 caps.
		'ffc_bypass_appointments',
		'ffc_manage_reregistration',
		'ffc_manage_recruitment',

		// Module-management caps (6.2.0). Each replaces a `manage_options`
		// gate at a module-admin entry point so site admins can delegate the
		// module to a dedicated operator without giving full WP admin.
		'ffc_manage_certificates',
		'ffc_export_certificates',
		// Issue certificates in bulk from a participants file (#1945). Held by the
		// Submissions Manager and Administrator tiers.
		'ffc_import_certificates',
		'ffc_manage_appointments',
		// Capacity overbook (#941). The one manual override of the always-enforced
		// per-slot / per-block capacity: a holder can book a full slot. Distinct
		// from `ffc_bypass_appointments` (which never bypasses capacity).
		'ffc_bypass_appointment_capacity',
		'ffc_manage_audiences',
		// Audience bypass (#1859). Book a shared space outside its rules --
		// closed days, holidays, opening hours, past dates, the advance window
		// and an occupied slot -- after confirming a warning, on a calendar
		// whose "Admin bypass" toggle is on. Split from
		// `ffc_bypass_appointments`, which no longer reaches audience
		// calendars. Held by `ffc_administrator` through
		// `get_all_capabilities()`; no other role carries it yet (#1860).
		'ffc_bypass_audiences',
		'ffc_view_activity_log',
		'ffc_manage_custom_fields',
		'ffc_view_as_user',
		'ffc_manage_settings',

		// Per-domain recruitment caps (6.2.0). Granular substitutes for the
		// catch-all `ffc_manage_recruitment` — operators can be wired with
		// "view only", "view + call candidates", "view + import CSV", etc.
		// without unlocking the entire admin surface. `ffc_manage_recruitment`
		// stays as the umbrella cap (catch-all backwards-compat) for any
		// endpoint that doesn't match one of the granular entries.
		'ffc_view_recruitment',
		'ffc_import_recruitment',
		'ffc_call_recruitment',
		'ffc_view_recruitment_pii',
		'ffc_manage_recruitment_settings',
		'ffc_manage_recruitment_reasons',

		// Submission-edit cap (6.2.0). Reactivated from the legacy
		// `FUTURE_CAPABILITIES` placeholder; gates the admin submission
		// edit page so non-admin operators can fix typos in issued
		// certificates without holding `manage_options`.
		'ffc_edit_certificates',

		// Quiz grading cap (#1732). Gates the grading of open quiz answers
		// on an attempt awaiting grading, which issues or refuses the
		// certificate -- a decision, not a typo fix, so not folded into
		// `ffc_edit_certificates`.
		'ffc_grade_certificates',

		// REST-API authentication caps (6.4.1). Granted to external
		// integrators authenticating via WordPress Application Passwords
		// (Basic Auth) so they can read form definitions through
		// `GET /ffc/v1/forms` / `GET /ffc/v1/forms/{id}` without the
		// previous `__return_true` permission_callback that exposed the
		// `_ffc_form_config` blob (allowed/denied user lists, validation
		// codes, generated codes, geofence config). Only the forms cap
		// lands here; calendars and appointments stay public-by-design
		// because their REST routes serve the public booking shortcode
		// directly. See issue #139.
		'ffc_view_forms_api',

		// REST submit cap (#1861). `POST /ffc/v1/forms/{id}/submit` was
		// anonymous; it now needs an authenticated caller holding this cap.
		// Split from `ffc_view_forms_api` because submitting writes a record,
		// may create an account and sends email, which reading does not. The
		// one-shot `migrate_forms_api_submit_cap_grant()` seeds it onto every
		// current `ffc_view_forms_api` holder, so an integration that read
		// and submitted with one Application Password keeps doing both.
		'ffc_submit_forms_api',

		// Read-only "view" caps — the *view only* tier of the 3-state permission
		// model (no access / view only / view and edit). Each pairs with a `manage`
		// cap above so a surface can be shown read-only without granting
		// edit. Gate helper: `canView = manage_options || view || manage`.
		'ffc_view_certificates',
		'ffc_view_appointments',
		'ffc_view_audiences',
		'ffc_view_reregistration',
		'ffc_view_custom_fields',
		'ffc_view_settings',
		'ffc_view_recruitment_settings',
		'ffc_view_recruitment_reasons',

		// PII reveal tier (#739 §3.3). Carved out of the read-only `view`
		// caps so seeing a submission list no longer implies seeing the
		// decrypted CPF / RF / email. Mirrors `ffc_view_recruitment_pii`:
		// a holder gets the audited *reveal* tier (masked placeholder →
		// click to decrypt, which writes an audit row). Since #1863 nobody on
		// staff sees plaintext without Reveal. Lives on the manager + admin
		// tiers.
		'ffc_view_certificates_pii',
		'ffc_view_appointments_pii',
		// Reregistration joins the carve (#1815): the submissions list printed
		// the submitter's email in clear to every viewer, and its "View
		// Details" modal decrypts CPF / RF / RG. Same reveal/unmasked split;
		// the unmasked role is `ffc_reregistration_admin`. The one-shot
		// `migrate_reregistration_pii_cap_grant()` seeds it onto every current
		// `ffc_manage_reregistration` holder, so no manager loses the details.
		'ffc_view_reregistration_pii',
		// Audience Calendars join the carve (#1863): the module listed people
		// and their account emails and had no PII cap, so the reveal borrowed
		// the appointments one. The one-shot
		// `migrate_audiences_pii_cap_grant()` seeds it onto every current
		// `ffc_manage_audiences` holder.
		'ffc_view_audiences_pii',
		// The client address on the two screens that show one outside a
		// module record (#2109): the activity log, and the Rate Limit tab's
		// top blocked addresses. Each shows the address masked and reveals
		// it through its own cap, audited. The one-shot
		// `migrate_ip_pii_caps_grant()` seeds each onto whoever views that
		// screen today, so nobody loses the address on upgrade.
		'ffc_view_activity_log_pii',
		'ffc_view_settings_pii',

		// URL shortener domain (GAP B). Its own view/manage pair so the
		// Short URLs admin page is delegable without manage_options.
		'ffc_view_url_shortener',
		'ffc_manage_url_shortener',

		// Date messages (#1538). `_pii` gates the screens that list people by
		// name and birthday; the plain view/manage pair covers the rules.
		'ffc_view_date_messages',
		'ffc_manage_date_messages',
		'ffc_view_date_messages_pii',
		// Sending a rule now or scheduling an announcement (#1949). Its own cap:
		// managing rules does not include it.
		'ffc_send_date_messages',
		// Test tier (#1860). `test` is a flow verb: it sends one sample of a
		// rule to the operator's own address, which `manage` also allows, so
		// the operator tier can check a message without editing any rule.
		'ffc_test_date_messages',

		// Destructive "delete" tier (GAP E). Each `ffc_delete_<domain>` cap
		// gates the irreversible removal paths of its domain *strictly* — the
		// delete handlers no longer fall back to the broader `manage` cap, so a
		// role can hold `manage` (create/edit/configure) without being able to
		// delete. The one-shot `migrate_delete_caps_grant()` migration grants
		// each delete cap to everyone who already holds the matching `manage`
		// cap, preserving current behavior on upgrade; admins restrict by
		// removing the delete cap from a role. See `delete_cap_grant_map()`.
		'ffc_delete_certificates',
		'ffc_delete_appointments',
		'ffc_delete_audiences',
		'ffc_delete_reregistration',
		'ffc_delete_custom_fields',
		'ffc_delete_recruitment',
		'ffc_delete_url_shortener',
		// Bulk purge tier (#1872). `purge` is a flow verb: deletion BY
		// CRITERIA, a whole calendar's past, cancelled or every appointment
		// at once, narrower than the single-record `ffc_delete_appointments`
		// a Bookings Manager holds. Only the Bookings Administrator tier
		// carries it (and `ffc_administrator` through
		// `get_all_capabilities()`), so no grant migration seeds it.
		'ffc_purge_appointments',
		// Attendance tier (#1947). `checkin` is a flow verb: open and close a
		// booking day's check-in, project its QR and mark attendance by hand.
		// It sits on the Audience Calendars Operator tier, so
		// `RoleRegistrar::register_module_roles()` adds it to the stored roles
		// and no grant migration seeds it.
		'ffc_checkin_audiences',

		// Granular "export" tier (GAP G). Each `ffc_export_<domain>` cap gates
		// the bulk CSV data-extraction path of its domain *strictly* — the
		// export handlers no longer fall back to the broader `manage` cap, so a
		// role can hold `manage` (create/edit/configure) without being able to
		// extract the dataset. Mirrors the long-standing `ffc_export_certificates`
		// model. The one-shot `migrate_export_caps_grant()` migration grants each
		// export cap to everyone who already holds the matching `manage` cap,
		// preserving current behavior on upgrade; admins restrict by removing the
		// export cap from a role. See `export_cap_grant_map()`. Certificates
		// already has `ffc_export_certificates` above. Activity-log export now
		// gets its own `ffc_export_activity_log` cap too (#711 §5), split out of
		// the read-only `ffc_view_activity_log` so a view-only operator can no
		// longer bulk-extract the audit trail; the one-shot
		// `migrate_activity_log_export_cap_grant()` seeds it onto every current
		// `ffc_view_activity_log` holder, preserving behavior on upgrade.
		'ffc_export_appointments',
		'ffc_export_reregistration',
		'ffc_export_audiences',
		'ffc_export_activity_log',
		// URL-shortener export tier. Split out of `ffc_manage_url_shortener`
		// so the Short URLs list can be delegated without granting bulk CSV
		// extraction of every short link. The one-shot
		// `migrate_url_shortener_export_cap_grant()` seeds it onto every current
		// `ffc_manage_url_shortener` holder, mirroring the activity-log split
		// (`ffc_activity_log_export_cap_v1`).
		'ffc_export_url_shortener',

		// Granular "import" tier (GAP H). Bulk CSV ingestion is split out of
		// `manage` for the domains where loading external data is the most
		// sensitive action. `ffc_import_audiences` is new; `ffc_import_recruitment`
		// already exists above but is tightened in 6.9.0 to *strict* enforcement —
		// its handlers no longer accept the umbrella `ffc_manage_recruitment` as a
		// fallback, joining `ffc_delete_recruitment` (GAP E) as a carved-out tier.
		// The one-shot `migrate_import_caps_grant()` migration seeds each import
		// cap onto every holder of the matching `manage` cap, preserving current
		// behavior on upgrade. See `import_cap_grant_map()`.
		'ffc_import_audiences',
		// Reregistration joins the import tier in 6.26.0 (#1214): loading a
		// campaign's answers from a spreadsheet writes encrypted PII for people
		// who never touched the form, which is a strictly larger act than
		// managing the campaign. Seeded onto current `ffc_manage_reregistration`
		// holders by the same one-shot migration, re-run under `_v2`.
		'ffc_import_reregistration',

		// Settings sub-caps (#711). Carve the two most sensitive Settings
		// surfaces out of the blanket `ffc_manage_settings` so each can be
		// delegated — or withheld — independently: `ffc_manage_settings_smtp`
		// gates saving the SMTP transport + Email Model configuration, and
		// `ffc_manage_settings_dangerzone` gates every destructive maintenance
		// action (delete-all submissions, obsolete-shortcode / short-URL
		// cleanup, public-access disabler, submission-link audit, and data
		// migration execution — previously `manage_options`-only). The one-shot
		// `migrate_settings_split_caps_grant()` seeds both onto every holder of
		// `ffc_manage_settings`, preserving current behavior on upgrade; admins
		// restrict by removing a sub-cap from a role. See
		// `settings_split_cap_grant_map()`.
		'ffc_manage_settings_smtp',
		'ffc_manage_settings_dangerzone',
		// SMTP test tier (#1860). Sends the test message of Settings → Email
		// Model to the operator's own address -- which exercises the transport
		// without being able to change it. `ffc_manage_settings_smtp` still
		// allows it too.
		'ffc_test_settings_smtp',

		// Email-template hub cap (#964). Gates editing the GLOBAL default body /
		// subject of the plugin's emails (Settings → SMTP → "All plugin emails"),
		// carved out so email-copy editing can be delegated — or withheld —
		// independently of the blanket `ffc_manage_settings`. The one-shot
		// `migrate_email_templates_cap_grant()` seeds it onto every current
		// `ffc_manage_settings` holder, preserving current behavior on upgrade;
		// admins restrict by removing it from a role. See
		// `email_templates_cap_grant_map()`.
		'ffc_manage_email_templates',

		// Identity resolution (#1368). Gates the screen that repairs a stored
		// CPF/RF, splits an account holding two people's identifiers and
		// merges two accounts holding one person's.
		//
		// A CAP OF ITS OWN RATHER THAN `ffc_manage_settings_dangerzone`
		//
		// That one is the big hammer: it also gates delete-all, the cleanups,
		// the public-access disabler and migration execution. The identity
		// work is driven by round-trips with HR, so the person doing it needs
		// the queue and nothing else -- handing them the danger zone to fix a
		// mistyped RF is over-granting. Same reasoning that carved
		// `ffc_manage_settings_smtp` and `ffc_manage_settings_dangerzone` out
		// of the blanket `ffc_manage_settings` (#711).
		//
		// It does NOT carry PII: revealing a stored identifier stays behind
		// `ffc_view_certificates_pii`, so an operator can correct a value
		// they were told by HR without being able to read every other one.
		// The one-shot `migrate_identities_cap_grant()` seeds it onto every
		// current `ffc_manage_settings_dangerzone` holder, so nobody loses an
		// ability they already had.
		'ffc_manage_identities',

		// THE TWO HEAVY VERBS, SEPARATE FROM THE QUEUE AND FROM EACH OTHER.
		//
		// Consolidating, correcting and moving change a VALUE or a link and
		// are undone by doing them again. These two are not that, and they
		// are not each other either: a split CREATES a WordPress account, and
		// a merge destroys PROVENANCE -- afterwards nothing can say which
		// record came from which login. Bundling them would mean anybody
		// trusted to open an account is also trusted to make a merge nobody
		// can undo.
		//
		// `split` and `merge` are flow verbs, which the capability grammar
		// closes its action vocabulary with (`book`, `cancel`, `download`,
		// `call`, `bypass`) -- so these are its idiomatic shape rather than
		// an exception to it.
		//
		// The one-shot `migrate_identity_verbs_cap_grant()` seeds both onto
		// every current `ffc_manage_identities` holder, so nobody working the
		// queue today loses a verb on upgrade.
		'ffc_split_identities',
		'ffc_merge_identities',

		// Form / calendar structure management (#739). These decouple the
		// `ffc_form` and `ffc_self_scheduling` CPTs from WordPress's native
		// post capabilities: the CPTs register a custom `capability_type` +
		// `map_meta_cap`, so `current_user_can( 'edit_post', $id )` now maps to
		// these instead of `edit_others_posts` (which a plain WP Editor holds).
		// `ffc_manage_forms` governs the certificate-form structure;
		// `ffc_manage_calendars` governs the self-scheduling calendar structure
		// + options (distinct from `ffc_manage_appointments`, which governs the
		// bookings made against a calendar). The `view` twins (#739 §3.2)
		// complete the 3-state model for both CPT surfaces — a read-only tier
		// so a surface can be shown without granting structure edit (the view
		// tier deferred from PR 1.3).
		'ffc_view_forms',
		'ffc_manage_forms',
		'ffc_view_calendars',
		'ffc_manage_calendars',
	);

	/**
	 * Slug for the dedicated recruitment-manager role.
	 *
	 * The role is granted `read` + `ffc_manage_recruitment` so site admins
	 * can delegate recruitment management without giving out full
	 * `manage_options`. Created on plugin activation, removed on uninstall.
	 *
	 * @since 6.0.0
	 */
	public const RECRUITMENT_MANAGER_ROLE = 'ffc_recruitment_manager';

	/**
	 * Capabilities only `ffc_administrator` carries among the FFC roles (#1860).
	 *
	 * The activity log is the trail of what every other role did, and the
	 * identity screen rewrites which account a record belongs to; neither is
	 * a module's business, so no module role and not the read-only role holds
	 * them. `manage_options` keeps both. `RoleNamingTest` refuses any other
	 * role holding one, and `CapabilityMigrator::migrate_role_tiers()` takes
	 * them off the stored roles of an existing install.
	 *
	 * @since 6.40.0
	 */
	public const ADMINISTRATOR_ONLY_CAPABILITIES = array(
		'ffc_view_activity_log',
		'ffc_export_activity_log',
		'ffc_view_activity_log_pii',
		'ffc_manage_identities',
		'ffc_split_identities',
		'ffc_merge_identities',
	);

	/**
	 * Future capabilities (disabled by default).
	 *
	 * Now empty — historical placeholders have been retired:
	 * - `ffc_reregistration` (4.9.3): never wired. Audience-targeting on
	 *   reregistration objects already filters who can submit each form;
	 *   adding a per-user cap on top was redundant. Removed in 6.2.0.
	 * - `ffc_edit_certificates` (4.9.3): wired in 6.2.0 as a real admin
	 *   cap (see `ADMIN_CAPABILITIES` above).
	 *
	 * @since 4.9.3
	 * @since 6.2.0 Cleared. The constant remains so external code that
	 *              referenced it doesn't fatal.
	 */
	public const FUTURE_CAPABILITIES = array();

	/**
	 * Get all FFC capabilities consolidated
	 *
	 * @since 4.9.3
	 * @return list<string> All FFC capability names
	 */
	public static function get_all_capabilities(): array {
		return array_merge(
			self::CERTIFICATE_CAPABILITIES,
			self::APPOINTMENT_CAPABILITIES,
			self::AUDIENCE_CAPABILITIES,
			self::ADMIN_CAPABILITIES,
			self::FUTURE_CAPABILITIES
		);
	}

	/**
	 * Whether an account is staff that a bulk operation must never rewrite
	 * (#2242).
	 *
	 * A WordPress administrator, or anyone holding a `_pii` capability. The
	 * second covers FFC Administrator and every module's Administrator and
	 * Manager tier, because those are the tiers the PII caps sit on, so a new
	 * module's roles are covered the day they ship. A reregistration import
	 * resolving to one of these accounts would otherwise overwrite its name
	 * and identifiers, and a takeover needs nothing more than that.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return bool
	 */
	public static function is_staff_account( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		foreach ( self::get_all_capabilities() as $cap ) {
			if ( str_ends_with( $cap, '_pii' ) && user_can( $user_id, $cap ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Grant capabilities based on context
	 *
	 * @since 4.4.0
	 * @param int    $user_id WordPress user ID.
	 * @param string $context Context ('certificate', 'appointment', or 'audience').
	 * @return void
	 */
	public static function grant_context_capabilities( int $user_id, string $context ): void {
		switch ( $context ) {
			case self::CONTEXT_CERTIFICATE:
				self::grant_certificate_capabilities( $user_id );
				break;
			case self::CONTEXT_APPOINTMENT:
				self::grant_appointment_capabilities( $user_id );
				break;
			case self::CONTEXT_AUDIENCE:
				self::grant_audience_capabilities( $user_id );
				break;
			case self::CONTEXT_REREGISTRATION:
				// Intentional no-op — see the constant for why. Listed rather
				// than left to `default` so that a context added later without
				// a decision still falls through to nothing silently, while
				// these two are on record as having been decided.
			case self::CONTEXT_RECRUITMENT:
				// Intentional no-op: recruitment candidates rely on the
				// `ffc_end_user` role's baseline `read` cap. The admin-side
				// `ffc_manage_recruitment` cap is registered on activation,
				// not granted at promotion time.
				break;
		}
	}

	/**
	 * Grant certificate capabilities to a user
	 *
	 * @since 4.4.0
	 * @param int $user_id WordPress user ID.
	 * @return void
	 */
	public static function grant_certificate_capabilities( int $user_id ): void {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		$newly_granted = array();

		foreach ( self::CERTIFICATE_CAPABILITIES as $cap ) {
			if ( ! $user->has_cap( $cap ) ) {
				$user->add_cap( $cap, true );
				$newly_granted[] = $cap;
			}
		}

		if ( class_exists( '\FreeFormCertificate\Core\Debug' ) ) {
			\FreeFormCertificate\Core\Debug::log_user_manager(
				'Granted certificate capabilities',
				array(
					'user_id'      => $user_id,
					'capabilities' => self::CERTIFICATE_CAPABILITIES,
				)
			);
		}

		if ( ! empty( $newly_granted ) ) {
			self::log_and_notify_capability_grant( $user, 'certificate', $newly_granted );
		}
	}

	/**
	 * Grant appointment capabilities to a user
	 *
	 * @since 4.4.0
	 * @param int $user_id WordPress user ID.
	 * @return void
	 */
	public static function grant_appointment_capabilities( int $user_id ): void {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		$newly_granted = array();

		foreach ( self::APPOINTMENT_CAPABILITIES as $cap ) {
			if ( ! $user->has_cap( $cap ) ) {
				$user->add_cap( $cap, true );
				$newly_granted[] = $cap;
			}
		}

		if ( class_exists( '\FreeFormCertificate\Core\Debug' ) ) {
			\FreeFormCertificate\Core\Debug::log_user_manager(
				'Granted appointment capabilities',
				array(
					'user_id'      => $user_id,
					'capabilities' => self::APPOINTMENT_CAPABILITIES,
				)
			);
		}

		if ( ! empty( $newly_granted ) ) {
			self::log_and_notify_capability_grant( $user, 'appointment', $newly_granted );
		}
	}

	/**
	 * Grant audience capabilities to a user
	 *
	 * @since 4.9.3
	 * @param int $user_id WordPress user ID.
	 * @return void
	 */
	public static function grant_audience_capabilities( int $user_id ): void {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		$newly_granted = array();

		foreach ( self::AUDIENCE_CAPABILITIES as $cap ) {
			if ( ! $user->has_cap( $cap ) ) {
				$user->add_cap( $cap, true );
				$newly_granted[] = $cap;
			}
		}

		if ( class_exists( '\FreeFormCertificate\Core\Debug' ) ) {
			\FreeFormCertificate\Core\Debug::log_user_manager(
				'Granted audience capabilities',
				array(
					'user_id'      => $user_id,
					'capabilities' => self::AUDIENCE_CAPABILITIES,
				)
			);
		}

		if ( ! empty( $newly_granted ) ) {
			self::log_and_notify_capability_grant( $user, 'audience', $newly_granted );
		}
	}

	/**
	 * Log capability grant to activity log and send email notification
	 *
	 * @since 4.9.9
	 * @param \WP_User           $user         User who received capabilities.
	 * @param string             $context      Context: 'certificate', 'appointment', 'audience'.
	 * @param array<int, string> $capabilities Newly granted capabilities.
	 * @return void
	 */
	private static function log_and_notify_capability_grant( \WP_User $user, string $context, array $capabilities ): void {
		if ( class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			\FreeFormCertificate\Core\ActivityLog::log_capabilities_granted(
				$user->ID,
				$context,
				$capabilities
			);
		}

		if ( ! \FreeFormCertificate\Settings\SettingsReader::notify_capability_grant_enabled() || empty( $user->user_email ) ) {
			return;
		}

		$site_name     = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		$dashboard_url = get_permalink( get_option( 'ffc_dashboard_page_id' ) );

		$context_labels = array(
			'certificate' => __( 'Certificates', 'ffcertificate' ),
			'appointment' => __( 'Appointments', 'ffcertificate' ),
			'audience'    => __( 'Audience Groups', 'ffcertificate' ),
		);
		$context_label  = $context_labels[ $context ] ?? $context;

		// Subject + body: the effective global (hub override → shipped file default,
		// #965), resolved through the one token map. The dashboard link is a
		// pre-rendered {{dashboard_button}} token (empty when no URL), so an admin
		// can move or drop it from the body. Subject tokens are plain text (a mail
		// header), body scalars are esc_html'd at substitution.
		$dashboard_button = self::ffc_email_button(
			$dashboard_url ? (string) $dashboard_url : '',
			__( 'Go to your dashboard', 'ffcertificate' ),
			array(
				'bg'      => '#2271b1',
				'padding' => '12px 24px',
				'bold'    => true,
			)
		);

		$subject = \FreeFormCertificate\Core\TokenResolver::resolve(
			\FreeFormCertificate\Core\EmailTemplates::effective_body( 'access-granted', 'subject' ),
			array(
				'{{site_name}}'     => $site_name,
				'{{context_label}}' => $context_label,
			)
		);
		$content = \FreeFormCertificate\Core\TokenResolver::resolve(
			\FreeFormCertificate\Core\EmailTemplates::effective_body( 'access-granted', 'body' ),
			array(
				'{{user_name}}'        => esc_html( (string) $user->display_name ),
				'{{context_label}}'    => esc_html( $context_label ),
				'{{site_name}}'        => esc_html( $site_name ),
				'{{dashboard_button}}' => $dashboard_button,
			)
		);

		self::ffc_send_mail(
			$user->user_email,
			$subject,
			self::ffc_email_document( $content, array( 'recipient' => $user->user_email ) ),
			array(),
			\FreeFormCertificate\Core\EmailSource::ACCOUNT
		);
	}

	/**
	 * Check if user has any certificate capabilities
	 *
	 * @since 4.4.0
	 * @param int $user_id WordPress user ID.
	 * @return bool
	 */
	public static function has_certificate_access( int $user_id ): bool {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return false;
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		foreach ( self::CERTIFICATE_CAPABILITIES as $cap ) {
			if ( $user->has_cap( $cap ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if user has any appointment capabilities
	 *
	 * @since 4.4.0
	 * @param int $user_id WordPress user ID.
	 * @return bool
	 */
	public static function has_appointment_access( int $user_id ): bool {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return false;
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		foreach ( self::APPOINTMENT_CAPABILITIES as $cap ) {
			if ( $user->has_cap( $cap ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get all FFC capabilities for a user
	 *
	 * @since 4.4.0
	 * @param int $user_id WordPress user ID.
	 * @return array<string, bool> Associative array of capability => boolean
	 */
	public static function get_user_ffc_capabilities( int $user_id ): array {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return array();
		}

		$capabilities = array();

		foreach ( self::get_all_capabilities() as $cap ) {
			$capabilities[ $cap ] = $user->has_cap( $cap );
		}

		return $capabilities;
	}

	/**
	 * Set a specific FFC capability for a user
	 *
	 * @since 4.4.0
	 * @param int    $user_id    WordPress user ID.
	 * @param string $capability Capability name.
	 * @param bool   $grant      Whether to grant (true) or revoke (false).
	 * @return bool True on success
	 *
	 * @deprecated 6.43.0 Nothing in the plugin calls it (#2089).
	 * @removal    6.45.0
	 */
	public static function set_user_capability( int $user_id, string $capability, bool $grant ): bool {
		_deprecated_function( __METHOD__, '6.43.0' );

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return false;
		}

		$all_ffc_caps = self::get_all_capabilities();

		if ( ! in_array( $capability, $all_ffc_caps, true ) ) {
			return false;
		}

		if ( $grant ) {
			$user->add_cap( $capability, true );
		} else {
			$user->add_cap( $capability, false );
		}

		if ( class_exists( '\FreeFormCertificate\Core\Debug' ) ) {
			\FreeFormCertificate\Core\Debug::log_user_manager(
				'User capability changed',
				array(
					'user_id'    => $user_id,
					'capability' => $capability,
					'granted'    => $grant,
				)
			);
		}

		return true;
	}

	/**
	 * Reset all FFC capabilities for a user to false
	 *
	 * @since 4.4.0
	 * @param int $user_id WordPress user ID.
	 * @return void
	 *
	 * @deprecated 6.43.0 Nothing in the plugin calls it (#2089).
	 * @removal    6.45.0
	 */
	public static function reset_user_ffc_capabilities( int $user_id ): void {
		_deprecated_function( __METHOD__, '6.43.0' );

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		foreach ( self::get_all_capabilities() as $cap ) {
			$user->add_cap( $cap, false );
		}
	}

	/**
	 * Definition map of every FFC role but `ffc_end_user`.
	 *
	 * Each entry is a `slug => array{label: string, caps: list<string>}` so
	 * `register_module_roles()` and `remove_module_roles()` share a single
	 * source of truth. `read` is added implicitly to every role so members
	 * can access wp-admin.
	 *
	 * ONE NAMING PATTERN, FOUR TIERS (#1860)
	 *
	 * A label reads `FFC <Module> [- <Part>] - <Tier>`. The module is named as
	 * the admin menu names it ({@see \FreeFormCertificate\Core\PluginAreas}),
	 * with the Scheduling area split into its two modules, Personal Calendars
	 * and Audience Calendars. A module whose screens split into parts names the
	 * part in every one of its roles: Certificates (Submissions, Forms) and
	 * Personal Calendars (Bookings, Setup). FFC Settings already carries the
	 * prefix, so its roles read "FFC Settings - <Tier>".
	 *
	 * Every module has the same four tiers, each holding the one below it:
	 *
	 *   Viewer        sees the screens, personal data masked;
	 *   Operator      + the module's day-to-day actions;
	 *   Manager       + manage, export and delete, and may reveal personal data;
	 *   Administrator + bypass and module settings, where the module has them
	 *                 (otherwise it equals the Manager).
	 *
	 * The two parts with no day-to-day or administrator action -- Certificates
	 * - Forms and Personal Calendars - Setup -- keep Viewer and Manager only.
	 *
	 * Slugs are stored values and never change: `ffc_appointments_*` is
	 * Personal Calendars - Bookings, `ffc_calendars_*` Personal Calendars -
	 * Setup, `ffc_forms_*` Certificates - Forms and `ffc_audiences_*` Audience
	 * Calendars. `RoleNamingTest` holds the labels and the tiers to this.
	 *
	 * The activity log and the identity-resolution caps
	 * ({@see self::ADMINISTRATOR_ONLY_CAPABILITIES}) belong to
	 * `ffc_administrator` alone.
	 *
	 * @since 6.2.0
	 * @since 6.16.0 Generalized to a uniform viewer/operator/manager ladder.
	 * @since 6.40.0 One label pattern and four tiers in every module (#1860).
	 * @return array<string, array{label: string, caps: list<string>}>
	 */
	public static function module_roles_definition(): array {
		$certificates_viewer   = array( 'ffc_view_certificates' );
		$certificates_operator = array_merge( $certificates_viewer, array( 'ffc_edit_certificates', 'ffc_grade_certificates' ) );
		$certificates_manager  = array_merge( $certificates_operator, array( 'ffc_manage_certificates', 'ffc_export_certificates', 'ffc_import_certificates', 'ffc_delete_certificates', 'ffc_view_certificates_pii' ) );

		$bookings_viewer   = array( 'ffc_view_appointments' );
		$bookings_operator = array_merge( $bookings_viewer, array( 'ffc_manage_appointments' ) );
		$bookings_manager  = array_merge( $bookings_operator, array( 'ffc_export_appointments', 'ffc_delete_appointments', 'ffc_view_appointments_pii' ) );

		$audiences_viewer   = array( 'ffc_view_audiences' );
		$audiences_operator = array_merge( $audiences_viewer, array( 'ffc_import_audiences', 'ffc_checkin_audiences' ) );
		$audiences_manager  = array_merge( $audiences_operator, array( 'ffc_manage_audiences', 'ffc_export_audiences', 'ffc_delete_audiences', 'ffc_view_audiences_pii' ) );

		$rereg_viewer   = array( 'ffc_view_reregistration' );
		$rereg_operator = array_merge( $rereg_viewer, array( 'ffc_export_reregistration' ) );
		$rereg_manager  = array_merge( $rereg_operator, array( 'ffc_manage_reregistration', 'ffc_import_reregistration', 'ffc_delete_reregistration', 'ffc_view_reregistration_pii' ) );

		$recruitment_viewer   = array( 'ffc_view_recruitment', 'ffc_view_recruitment_reasons' );
		$recruitment_operator = array_merge( $recruitment_viewer, array( 'ffc_call_recruitment' ) );
		$recruitment_manager  = array_merge( $recruitment_operator, array( 'ffc_manage_recruitment', 'ffc_import_recruitment', 'ffc_delete_recruitment', 'ffc_manage_recruitment_reasons', 'ffc_view_recruitment_pii' ) );

		$short_urls_viewer   = array( 'ffc_view_url_shortener' );
		$short_urls_operator = array_merge( $short_urls_viewer, array( 'ffc_export_url_shortener' ) );
		$short_urls_manager  = array_merge( $short_urls_operator, array( 'ffc_manage_url_shortener', 'ffc_delete_url_shortener' ) );

		$date_messages_viewer   = array( 'ffc_view_date_messages' );
		$date_messages_operator = array_merge( $date_messages_viewer, array( 'ffc_test_date_messages' ) );
		$date_messages_manager  = array_merge( $date_messages_operator, array( 'ffc_manage_date_messages', 'ffc_view_date_messages_pii', 'ffc_send_date_messages' ) );

		$settings_viewer   = array( 'ffc_view_settings', 'ffc_view_custom_fields', 'ffc_view_forms_api' );
		$settings_operator = array_merge( $settings_viewer, array( 'ffc_test_settings_smtp' ) );
		$settings_manager  = array_merge( $settings_operator, array( 'ffc_manage_settings', 'ffc_manage_email_templates', 'ffc_manage_custom_fields', 'ffc_delete_custom_fields', 'ffc_view_settings_pii' ) );

		return array(
			// ── Cross-module roles ───────────────────────────────────────
			// `ffc_administrator` is the aggregator (GAP F): a full FFC admin
			// that carries *every* FFC capability — the complete admin surface
			// plus the end-user self-service (`own_`) caps — but deliberately
			// NOT `manage_options`, so a site can delegate full plugin
			// administration without handing out WordPress super-admin. Its cap
			// set is the live `get_all_capabilities()` list, so any capability
			// added in a future release is granted to it automatically.
			'ffc_administrator'            => array(
				'label' => __( 'FFC Administrator', 'ffcertificate' ),
				'caps'  => self::get_all_capabilities(),
			),
			// Cross-module read-only: every module's view tier, always masked
			// (no `_pii` cap) and without the activity log, which is the
			// administrator's alone (#1860). The Settings page stays out, as
			// it always has: it is the FFC Settings roles' screen.
			'ffc_readonly'                 => array(
				'label' => __( 'FFC Read-only', 'ffcertificate' ),
				'caps'  => array(
					'ffc_view_certificates',
					'ffc_view_forms',
					'ffc_view_appointments',
					'ffc_view_calendars',
					'ffc_view_audiences',
					'ffc_view_reregistration',
					'ffc_view_recruitment',
					'ffc_view_recruitment_settings',
					'ffc_view_recruitment_reasons',
					'ffc_view_url_shortener',
					'ffc_view_date_messages',
					'ffc_view_custom_fields',
				),
			),

			// ── Certificates - Submissions ───────────────────────────────
			'ffc_certificates_viewer'      => array(
				'label' => __( 'FFC Certificates - Submissions - Viewer', 'ffcertificate' ),
				'caps'  => $certificates_viewer,
			),
			'ffc_certificates_operator'    => array(
				'label' => __( 'FFC Certificates - Submissions - Operator', 'ffcertificate' ),
				'caps'  => $certificates_operator,
			),
			'ffc_certificates_manager'     => array(
				'label' => __( 'FFC Certificates - Submissions - Manager', 'ffcertificate' ),
				'caps'  => $certificates_manager,
			),
			// Same caps as the manager.
			'ffc_certificates_admin'       => array(
				'label' => __( 'FFC Certificates - Submissions - Administrator', 'ffcertificate' ),
				'caps'  => $certificates_manager,
			),

			// ── Certificates - Forms (Viewer and Manager only) ───────────
			'ffc_forms_viewer'             => array(
				'label' => __( 'FFC Certificates - Forms - Viewer', 'ffcertificate' ),
				'caps'  => array( 'ffc_view_forms' ),
			),
			'ffc_forms_manager'            => array(
				'label' => __( 'FFC Certificates - Forms - Manager', 'ffcertificate' ),
				'caps'  => array( 'ffc_view_forms', 'ffc_manage_forms' ),
			),

			// ── Personal Calendars - Bookings ────────────────────────────
			// The bypass is the administrator's alone (#1860): approving and
			// cancelling is the operator's job, booking outside the rules is
			// not. So is the bulk purge of a calendar (#1872).
			'ffc_appointments_viewer'      => array(
				'label' => __( 'FFC Personal Calendars - Bookings - Viewer', 'ffcertificate' ),
				'caps'  => $bookings_viewer,
			),
			'ffc_appointments_operator'    => array(
				'label' => __( 'FFC Personal Calendars - Bookings - Operator', 'ffcertificate' ),
				'caps'  => $bookings_operator,
			),
			'ffc_appointments_manager'     => array(
				'label' => __( 'FFC Personal Calendars - Bookings - Manager', 'ffcertificate' ),
				'caps'  => $bookings_manager,
			),
			'ffc_appointments_admin'       => array(
				'label' => __( 'FFC Personal Calendars - Bookings - Administrator', 'ffcertificate' ),
				'caps'  => array_merge( $bookings_manager, array( 'ffc_bypass_appointments', 'ffc_bypass_appointment_capacity', 'ffc_purge_appointments' ) ),
			),

			// ── Personal Calendars - Setup (Viewer and Manager only) ─────
			'ffc_calendars_viewer'         => array(
				'label' => __( 'FFC Personal Calendars - Setup - Viewer', 'ffcertificate' ),
				'caps'  => array( 'ffc_view_calendars' ),
			),
			'ffc_calendars_manager'        => array(
				'label' => __( 'FFC Personal Calendars - Setup - Manager', 'ffcertificate' ),
				'caps'  => array( 'ffc_view_calendars', 'ffc_manage_calendars' ),
			),

			// ── Audience Calendars ───────────────────────────────────────
			'ffc_audiences_viewer'         => array(
				'label' => __( 'FFC Audience Calendars - Viewer', 'ffcertificate' ),
				'caps'  => $audiences_viewer,
			),
			'ffc_audiences_operator'       => array(
				'label' => __( 'FFC Audience Calendars - Operator', 'ffcertificate' ),
				'caps'  => $audiences_operator,
			),
			'ffc_audiences_manager'        => array(
				'label' => __( 'FFC Audience Calendars - Manager', 'ffcertificate' ),
				'caps'  => $audiences_manager,
			),
			'ffc_audiences_admin'          => array(
				'label' => __( 'FFC Audience Calendars - Administrator', 'ffcertificate' ),
				'caps'  => array_merge( $audiences_manager, array( 'ffc_bypass_audiences' ) ),
			),

			// ── Reregistration ───────────────────────────────────────────
			'ffc_reregistration_viewer'    => array(
				'label' => __( 'FFC Reregistration - Viewer', 'ffcertificate' ),
				'caps'  => $rereg_viewer,
			),
			'ffc_reregistration_operator'  => array(
				'label' => __( 'FFC Reregistration - Operator', 'ffcertificate' ),
				'caps'  => $rereg_operator,
			),
			'ffc_reregistration_manager'   => array(
				'label' => __( 'FFC Reregistration - Manager', 'ffcertificate' ),
				'caps'  => $rereg_manager,
			),
			// Same caps as the manager.
			'ffc_reregistration_admin'     => array(
				'label' => __( 'FFC Reregistration - Administrator', 'ffcertificate' ),
				'caps'  => $rereg_manager,
			),

			// ── Recruitment ──────────────────────────────────────────────
			// The manager is the 6.0.0 role, defined here since #1860 so its
			// tier is declared where every other tier is.
			// `RoleRegistrar::register_recruitment_manager_role()` still
			// creates it first on activation.
			'ffc_recruitment_viewer'       => array(
				'label' => __( 'FFC Recruitment - Viewer', 'ffcertificate' ),
				'caps'  => $recruitment_viewer,
			),
			'ffc_recruitment_operator'     => array(
				'label' => __( 'FFC Recruitment - Operator', 'ffcertificate' ),
				'caps'  => $recruitment_operator,
			),
			self::RECRUITMENT_MANAGER_ROLE => array(
				'label' => __( 'FFC Recruitment - Manager', 'ffcertificate' ),
				'caps'  => $recruitment_manager,
			),
			'ffc_recruitment_admin'        => array(
				'label' => __( 'FFC Recruitment - Administrator', 'ffcertificate' ),
				'caps'  => array_merge( $recruitment_manager, array( 'ffc_view_recruitment_settings', 'ffc_manage_recruitment_settings' ) ),
			),

			// ── Short URLs ───────────────────────────────────────────────
			'ffc_url_shortener_viewer'     => array(
				'label' => __( 'FFC Short URLs - Viewer', 'ffcertificate' ),
				'caps'  => $short_urls_viewer,
			),
			'ffc_url_shortener_operator'   => array(
				'label' => __( 'FFC Short URLs - Operator', 'ffcertificate' ),
				'caps'  => $short_urls_operator,
			),
			'ffc_url_shortener_manager'    => array(
				'label' => __( 'FFC Short URLs - Manager', 'ffcertificate' ),
				'caps'  => $short_urls_manager,
			),
			'ffc_url_shortener_admin'      => array(
				'label' => __( 'FFC Short URLs - Administrator', 'ffcertificate' ),
				'caps'  => $short_urls_manager,
			),

			// ── Date Messages ────────────────────────────────────────────
			'ffc_date_messages_viewer'     => array(
				'label' => __( 'FFC Messages - Viewer', 'ffcertificate' ),
				'caps'  => $date_messages_viewer,
			),
			'ffc_date_messages_operator'   => array(
				'label' => __( 'FFC Messages - Operator', 'ffcertificate' ),
				'caps'  => $date_messages_operator,
			),
			'ffc_date_messages_manager'    => array(
				'label' => __( 'FFC Messages - Manager', 'ffcertificate' ),
				'caps'  => $date_messages_manager,
			),
			'ffc_date_messages_admin'      => array(
				'label' => __( 'FFC Messages - Administrator', 'ffcertificate' ),
				'caps'  => $date_messages_manager,
			),

			// ── FFC Settings ─────────────────────────────────────────────
			'ffc_settings_viewer'          => array(
				'label' => __( 'FFC Settings - Viewer', 'ffcertificate' ),
				'caps'  => $settings_viewer,
			),
			'ffc_settings_operator'        => array(
				'label' => __( 'FFC Settings - Operator', 'ffcertificate' ),
				'caps'  => $settings_operator,
			),
			'ffc_settings_manager'         => array(
				'label' => __( 'FFC Settings - Manager', 'ffcertificate' ),
				'caps'  => $settings_manager,
			),
			'ffc_settings_admin'           => array(
				'label' => __( 'FFC Settings - Administrator', 'ffcertificate' ),
				'caps'  => array_merge( $settings_manager, array( 'ffc_manage_settings_smtp', 'ffc_manage_settings_dangerzone', 'ffc_view_as_user' ) ),
			),
		);
	}
}

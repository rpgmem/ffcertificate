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
	 * Used by `UserCreator::get_or_create_user()` when the candidate is
	 * being linked or created via the recruitment CSV importer (sprint 4) or
	 * a manual admin edit (sprint 9.1). No per-user caps are granted on this
	 * context — candidates rely on the `ffc_user` role's baseline `read` cap
	 * to access their dashboard "Minhas Convocações" section. The
	 * `ffc_manage_recruitment` admin cap is registered separately on plugin
	 * activation (see {@see RoleRegistrar::register_recruitment_manager_role()}).
	 *
	 * @since 6.0.0
	 */
	public const CONTEXT_RECRUITMENT = 'recruitment';

	/**
	 * All certificate-related capabilities.
	 *
	 * @since 4.4.0
	 * @since 6.2.0 Renamed from `view_own_certificates`, `download_own_certificates`,
	 *              `view_certificate_history` (no FFC prefix) to the consistent
	 *              `ffc_*` namespace. Migration in `LegacyCapMigration` rewrites
	 *              old grants on every user + the `ffc_user` role definition.
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
	 *              caps (`ffc_scheduling_bypass`, `ffc_manage_reregistration`,
	 *              `ffc_manage_recruitment`) remain unchanged so any user
	 *              already holding them keeps their access; the new caps
	 *              add scoped delegation paths.
	 */
	public const ADMIN_CAPABILITIES = array(
		// Pre-6.2.0 caps.
		'ffc_scheduling_bypass',
		'ffc_manage_reregistration',
		'ffc_manage_recruitment',

		// Module-management caps (6.2.0). Each replaces a `manage_options`
		// gate at a module-admin entry point so site admins can delegate the
		// module to a dedicated operator without giving full WP admin.
		'ffc_manage_certificates',
		'ffc_export_certificates',
		'ffc_manage_appointments',
		'ffc_manage_audiences',
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

		// Read-only "view" caps — the *só vê* tier of the 3-state permission
		// model (não vê / só vê / vê e edita). Each pairs with a `manage`
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

		// URL shortener domain (GAP B). Its own view/manage pair so the
		// Short URLs admin page is delegable without manage_options.
		'ffc_view_url_shortener',
		'ffc_manage_url_shortener',

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
			case self::CONTEXT_RECRUITMENT:
				// Intentional no-op: recruitment candidates rely on the
				// `ffc_user` role's baseline `read` cap. The admin-side
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

		/* translators: %1$s: site name, %2$s: feature name */
		$subject = sprintf( __( '[%1$s] Access granted: %2$s', 'ffcertificate' ), $site_name, $context_label );

		// Email body → shared configurable chrome (#662 PR-8), like every other email.
		$content = self::ffc_render_email_partial(
			'access-granted',
			array(
				'user_name'     => $user->display_name,
				'context_label' => $context_label,
				'site_name'     => $site_name,
				'dashboard_url' => $dashboard_url ? $dashboard_url : '',
			)
		);

		self::ffc_send_mail(
			$user->user_email,
			$subject,
			self::ffc_email_document( $content, array( 'recipient' => $user->user_email ) )
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
	 */
	public static function set_user_capability( int $user_id, string $capability, bool $grant ): bool {
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
	 */
	public static function reset_user_ffc_capabilities( int $user_id ): void {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		foreach ( self::get_all_capabilities() as $cap ) {
			$user->add_cap( $cap, false );
		}
	}

	/**
	 * Definition map for the 6.2.0 module-manager + recruitment-tier roles.
	 *
	 * Each entry is a `slug => array{label: string, caps: list<string>}` so
	 * `register_module_roles()` and `remove_module_roles()` share a single
	 * source of truth. `read` is added implicitly to every role so members
	 * can access wp-admin.
	 *
	 * Recruitment tier hierarchy (each tier inherits everything from the
	 * tier above it):
	 *
	 *   Auditor (read-only)
	 *     → Operator (Auditor + can call candidates)
	 *       → Manager (Operator + can import CSV + see PII + umbrella cap)
	 *         → Admin (Manager + can edit settings + reasons catalog)
	 *
	 * @since 6.2.0
	 * @return array<string, array{label: string, caps: list<string>}>
	 */
	public static function module_roles_definition(): array {
		return array(
			// ── Cross-module roles ───────────────────────────────────────
			// `ffc_administrator` is the aggregator (GAP F): a full FFC admin
			// that carries *every* FFC capability — the complete admin surface
			// plus the end-user self-service (`own_`) caps — but deliberately
			// NOT `manage_options`, so a site can delegate full plugin
			// administration without handing out WordPress super-admin. Its cap
			// set is the live `get_all_capabilities()` list, so any capability
			// added in a future release is granted to it automatically.
			'ffc_administrator'           => array(
				'label' => __( 'FFC Administrator', 'ffcertificate' ),
				'caps'  => self::get_all_capabilities(),
			),
			// Each manage role also carries its matching `view` cap so the
			// admin menu/tab (gated by a single view-cap string) stays visible
			// to managers — the inline write gates still require the manage cap.
			'ffc_certificate_manager'     => array(
				'label' => __( 'FFC Certificate Manager', 'ffcertificate' ),
				'caps'  => array( 'ffc_view_certificates', 'ffc_manage_certificates', 'ffc_export_certificates', 'ffc_edit_certificates', 'ffc_delete_certificates' ),
			),
			'ffc_self_scheduling_manager' => array(
				'label' => __( 'FFC Self-Scheduling Manager', 'ffcertificate' ),
				'caps'  => array( 'ffc_view_appointments', 'ffc_manage_appointments', 'ffc_delete_appointments', 'ffc_scheduling_bypass', 'ffc_export_appointments' ),
			),
			'ffc_audience_manager'        => array(
				'label' => __( 'FFC Audience Manager', 'ffcertificate' ),
				'caps'  => array( 'ffc_view_audiences', 'ffc_manage_audiences', 'ffc_delete_audiences', 'ffc_export_audiences', 'ffc_import_audiences' ),
			),
			'ffc_reregistration_manager'  => array(
				'label' => __( 'FFC Reregistration Manager', 'ffcertificate' ),
				'caps'  => array( 'ffc_view_reregistration', 'ffc_manage_reregistration', 'ffc_delete_reregistration', 'ffc_export_reregistration' ),
			),
			'ffc_operator'                => array(
				'label' => __( 'FFC Operator (read-only)', 'ffcertificate' ),
				'caps'  => array(
					'ffc_view_certificates',
					'ffc_view_appointments',
					'ffc_view_audiences',
					'ffc_view_reregistration',
					'ffc_view_custom_fields',
					'ffc_view_activity_log',
					'ffc_view_recruitment',
					'ffc_view_recruitment_settings',
					'ffc_view_recruitment_reasons',
					'ffc_view_url_shortener',
				),
			),

			// ── Recruitment tier ─────────────────────────────────────────
			'ffc_recruitment_auditor'     => array(
				'label' => __( 'FFC Recruitment Auditor', 'ffcertificate' ),
				'caps'  => array( 'ffc_view_recruitment', 'ffc_view_recruitment_reasons' ),
			),
			'ffc_recruitment_operator'    => array(
				'label' => __( 'FFC Recruitment Operator', 'ffcertificate' ),
				'caps'  => array( 'ffc_view_recruitment', 'ffc_call_recruitment', 'ffc_view_recruitment_reasons' ),
			),
			// `ffc_recruitment_manager` already exists (6.0.0). It will be
			// upgraded by `register_recruitment_manager_role()` — extra caps
			// are added in 6.2.0 below to fit the new tier model.
			'ffc_recruitment_admin'       => array(
				'label' => __( 'FFC Recruitment Admin', 'ffcertificate' ),
				'caps'  => array(
					'ffc_view_recruitment',
					'ffc_call_recruitment',
					'ffc_import_recruitment',
					'ffc_view_recruitment_pii',
					'ffc_manage_recruitment',
					'ffc_delete_recruitment',
					'ffc_view_recruitment_settings',
					'ffc_manage_recruitment_settings',
					'ffc_view_recruitment_reasons',
					'ffc_manage_recruitment_reasons',
				),
			),
		);
	}
}

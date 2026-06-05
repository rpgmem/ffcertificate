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
	 * activation (see {@see self::register_recruitment_manager_role()}).
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
	 * Map of legacy (pre-6.2.0) cap names → new namespaced names. Consumed
	 * by the migration helper {@see self::migrate_legacy_certificate_caps()}
	 * and by `Loader::ensure_legacy_caps_renamed()` on `plugins_loaded`.
	 *
	 * @since 6.2.0
	 * @return array<string, string>
	 */
	public static function legacy_cap_renames(): array {
		return array(
			'view_own_certificates'     => 'ffc_view_own_certificates',
			'download_own_certificates' => 'ffc_download_own_certificates',
			'view_certificate_history'  => 'ffc_view_own_certificate_history',
		);
	}

	/**
	 * Idempotent migration: rewrites every legacy cap grant on every user
	 * (user-meta `add_cap(true)`) and on the `ffc_user` role to the new
	 * `ffc_*` namespace.
	 *
	 * Strategy: for each `legacy => new` pair,
	 *   1. iterate every user that has the legacy cap, add the new cap, drop
	 *      the legacy cap;
	 *   2. on the `ffc_user` role, if the legacy cap exists, add the new
	 *      cap with the same boolean value and remove the legacy cap.
	 *
	 * Run once per FFC version bump via `Loader::ensure_legacy_caps_renamed()`.
	 *
	 * @since 6.2.0
	 * @return array<string, int> Per-rename count of users migrated.
	 */
	public static function migrate_legacy_certificate_caps(): array {
		$counts = array();
		foreach ( self::legacy_cap_renames() as $legacy => $renamed ) {
			$counts[ $legacy ] = 0;

			// 1. User-meta grants. WP_User_Query has no direct `cap` filter,
			// so we iterate over user IDs and check each — this runs once per
			// version bump so the cost is acceptable.
			$users = get_users( array( 'fields' => 'ID' ) );
			foreach ( $users as $user_id ) {
				$user = get_userdata( (int) $user_id );
				if ( ! $user ) {
					continue;
				}
				if ( isset( $user->caps[ $legacy ] ) && true === $user->caps[ $legacy ] ) {
					$user->add_cap( $renamed, true );
					$user->remove_cap( $legacy );
					++$counts[ $legacy ];
				}
			}

			// 2. ffc_user role definition.
			$role = get_role( 'ffc_user' );
			if ( $role && isset( $role->capabilities[ $legacy ] ) ) {
				$value = (bool) $role->capabilities[ $legacy ];
				$role->add_cap( $renamed, $value );
				$role->remove_cap( $legacy );
			}
		}
		return $counts;
	}

	/**
	 * Taxonomy rename map (old => new) for the plugin-wide capability naming
	 * standard `ffc_<action>_[own_]<domain>[_<qualifier>]`.
	 *
	 * ⚠ The `ffc_view_self_scheduling => ffc_view_own_appointments` entry
	 * **reverses** the historical 4.5.0 migration
	 * ({@see \FreeFormCertificate\Migrations\MigrationRenameCapabilities},
	 * which mapped `ffc_view_own_appointments => ffc_view_self_scheduling`).
	 * It is applied here under a **new** option flag so the two never collide.
	 *
	 * @since 6.9.0
	 * @return array<string, string>
	 */
	public static function taxonomy_cap_renames(): array {
		return array(
			'ffc_view_certificate_history'    => 'ffc_view_own_certificate_history',
			'ffc_view_self_scheduling'        => 'ffc_view_own_appointments',
			'ffc_view_audience_bookings'      => 'ffc_view_own_audience_bookings',
			'ffc_book_appointments'           => 'ffc_book_own_appointments',
			'ffc_manage_self_scheduling'      => 'ffc_manage_appointments',
			'ffc_certificate_update'          => 'ffc_edit_certificates',
			'ffc_manage_user_custom_fields'   => 'ffc_manage_custom_fields',
			'ffc_import_recruitment_csv'      => 'ffc_import_recruitment',
			'ffc_call_recruitment_candidates' => 'ffc_call_recruitment',
			'ffc_read_forms_api'              => 'ffc_view_forms_api',
		);
	}

	/**
	 * Idempotent migration that rewrites every taxonomy-renamed cap grant on
	 * (1) every user's user-meta caps and (2) every role definition (including
	 * `administrator` and the FFC roles), preserving the boolean value.
	 *
	 * Runs once per install via {@see \FreeFormCertificate\Loader} on
	 * `plugins_loaded`, flagged by a dedicated option so it never re-runs and
	 * never collides with the 4.5.0 rename migration.
	 *
	 * @since 6.9.0
	 * @return array<string, int> Per-rename count of users migrated.
	 */
	public static function migrate_taxonomy_renames(): array {
		$renames = self::taxonomy_cap_renames();
		$counts  = array();

		// 1. User-meta grants.
		$users = get_users( array( 'fields' => 'ID' ) );
		foreach ( $renames as $old => $new ) {
			$counts[ $old ] = 0;
			foreach ( $users as $user_id ) {
				$user = get_userdata( (int) $user_id );
				if ( ! $user ) {
					continue;
				}
				if ( isset( $user->caps[ $old ] ) ) {
					$value = (bool) $user->caps[ $old ];
					$user->add_cap( $new, $value );
					$user->remove_cap( $old );
					++$counts[ $old ];
				}
			}
		}

		// 2. Role definitions — administrator + every FFC/custom role.
		$wp_roles = wp_roles();
		foreach ( array_keys( $wp_roles->roles ) as $role_slug ) {
			$role = get_role( $role_slug );
			if ( ! $role ) {
				continue;
			}
			foreach ( $renames as $old => $new ) {
				if ( isset( $role->capabilities[ $old ] ) ) {
					$value = (bool) $role->capabilities[ $old ];
					$role->add_cap( $new, $value );
					$role->remove_cap( $old );
				}
			}
		}

		return $counts;
	}

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
		// already has `ffc_export_certificates` above, and activity-log export
		// stays under its read-only `ffc_view_activity_log` cap.
		'ffc_export_appointments',
		'ffc_export_reregistration',
		'ffc_export_audiences',

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
	);

	/**
	 * Map of `manage` cap => the `delete` cap it seeds on upgrade (GAP E).
	 *
	 * The one-shot migration {@see self::migrate_delete_caps_grant()} grants the
	 * value cap to every user/role that already holds the key cap, so existing
	 * managers keep their delete ability after the destructive tier is split out
	 * of `manage`. To take delete away from a manager, remove the delete cap
	 * from that role/user after the migration has run.
	 *
	 * @since 6.9.0
	 * @return array<string, string>
	 */
	public static function delete_cap_grant_map(): array {
		return array(
			'ffc_manage_certificates'   => 'ffc_delete_certificates',
			'ffc_manage_appointments'   => 'ffc_delete_appointments',
			'ffc_manage_audiences'      => 'ffc_delete_audiences',
			'ffc_manage_reregistration' => 'ffc_delete_reregistration',
			'ffc_manage_custom_fields'  => 'ffc_delete_custom_fields',
			'ffc_manage_recruitment'    => 'ffc_delete_recruitment',
			'ffc_manage_url_shortener'  => 'ffc_delete_url_shortener',
		);
	}

	/**
	 * Idempotent migration that seeds each `ffc_delete_<domain>` cap onto every
	 * user and role that already holds the matching `ffc_manage_<domain>` cap
	 * (GAP E). Preserves current delete behavior when the destructive tier is
	 * introduced; never removes a `manage` cap.
	 *
	 * Runs once per install via {@see \FreeFormCertificate\Loader} on
	 * `plugins_loaded`, flagged by the `ffc_delete_caps_granted_v1` option.
	 *
	 * @since 6.9.0
	 * @return array<string, int> Per-delete-cap count of users seeded.
	 */
	public static function migrate_delete_caps_grant(): array {
		$map    = self::delete_cap_grant_map();
		$counts = array();

		// 1. User-meta grants.
		$users = get_users( array( 'fields' => 'ID' ) );
		foreach ( $map as $manage => $delete ) {
			$counts[ $delete ] = 0;
			foreach ( $users as $user_id ) {
				$user = get_userdata( (int) $user_id );
				if ( ! $user ) {
					continue;
				}
				// Seed only where the manage cap is an explicit grant and the
				// delete cap isn't already present (idempotent).
				if ( isset( $user->caps[ $manage ] ) && true === $user->caps[ $manage ]
					&& ! isset( $user->caps[ $delete ] ) ) {
					$user->add_cap( $delete, true );
					++$counts[ $delete ];
				}
			}
		}

		// 2. Role definitions — administrator + every FFC/custom role.
		$wp_roles = wp_roles();
		foreach ( array_keys( $wp_roles->roles ) as $role_slug ) {
			$role = get_role( $role_slug );
			if ( ! $role ) {
				continue;
			}
			foreach ( $map as $manage => $delete ) {
				if ( isset( $role->capabilities[ $manage ] ) && true === $role->capabilities[ $manage ]
					&& ! isset( $role->capabilities[ $delete ] ) ) {
					$role->add_cap( $delete, true );
				}
			}
		}

		return $counts;
	}

	/**
	 * Map of `manage` cap => the `export` cap it seeds on upgrade (GAP G).
	 *
	 * The one-shot migration {@see self::migrate_export_caps_grant()} grants the
	 * value cap to every user/role that already holds the key cap, so existing
	 * managers keep their bulk-export ability after the export tier is split out
	 * of `manage`. To take export away from a manager, remove the export cap from
	 * that role/user after the migration has run.
	 *
	 * Certificates is intentionally absent: `ffc_export_certificates` predates
	 * this split and has always been a standalone cap (never granted by
	 * `ffc_manage_certificates`), so there is nothing to seed for it.
	 *
	 * @since 6.9.0
	 * @return array<string, string>
	 */
	public static function export_cap_grant_map(): array {
		return array(
			'ffc_manage_appointments'   => 'ffc_export_appointments',
			'ffc_manage_reregistration' => 'ffc_export_reregistration',
			'ffc_manage_audiences'      => 'ffc_export_audiences',
		);
	}

	/**
	 * Idempotent migration that seeds each `ffc_export_<domain>` cap onto every
	 * user and role that already holds the matching `ffc_manage_<domain>` cap
	 * (GAP G). Preserves current export behavior when the export tier is split
	 * out of `manage`; never removes a `manage` cap.
	 *
	 * Runs once per install via {@see \FreeFormCertificate\Loader} on
	 * `plugins_loaded`, flagged by the `ffc_export_caps_granted_v1` option.
	 *
	 * @since 6.9.0
	 * @return array<string, int> Per-export-cap count of users seeded.
	 */
	public static function migrate_export_caps_grant(): array {
		$map    = self::export_cap_grant_map();
		$counts = array();

		// 1. User-meta grants.
		$users = get_users( array( 'fields' => 'ID' ) );
		foreach ( $map as $manage => $export ) {
			$counts[ $export ] = 0;
			foreach ( $users as $user_id ) {
				$user = get_userdata( (int) $user_id );
				if ( ! $user ) {
					continue;
				}
				// Seed only where the manage cap is an explicit grant and the
				// export cap isn't already present (idempotent).
				if ( isset( $user->caps[ $manage ] ) && true === $user->caps[ $manage ]
					&& ! isset( $user->caps[ $export ] ) ) {
					$user->add_cap( $export, true );
					++$counts[ $export ];
				}
			}
		}

		// 2. Role definitions — administrator + every FFC/custom role.
		$wp_roles = wp_roles();
		foreach ( array_keys( $wp_roles->roles ) as $role_slug ) {
			$role = get_role( $role_slug );
			if ( ! $role ) {
				continue;
			}
			foreach ( $map as $manage => $export ) {
				if ( isset( $role->capabilities[ $manage ] ) && true === $role->capabilities[ $manage ]
					&& ! isset( $role->capabilities[ $export ] ) ) {
					$role->add_cap( $export, true );
				}
			}
		}

		return $counts;
	}

	/**
	 * Map of `manage` cap => the `import` cap it seeds on upgrade (GAP H).
	 *
	 * The one-shot migration {@see self::migrate_import_caps_grant()} grants the
	 * value cap to every user/role that already holds the key cap, so existing
	 * managers keep their bulk-import ability after the import tier is enforced
	 * strictly. Covers the newly-split `ffc_import_audiences` and the
	 * `ffc_import_recruitment` cap whose umbrella fallback is removed in 6.9.0
	 * (custom roles relying on `ffc_manage_recruitment` to import keep working).
	 * To take import away from a manager, remove the import cap afterward.
	 *
	 * @since 6.9.0
	 * @return array<string, string>
	 */
	public static function import_cap_grant_map(): array {
		return array(
			'ffc_manage_audiences'   => 'ffc_import_audiences',
			'ffc_manage_recruitment' => 'ffc_import_recruitment',
		);
	}

	/**
	 * Idempotent migration that seeds each `ffc_import_<domain>` cap onto every
	 * user and role that already holds the matching `ffc_manage_<domain>` cap
	 * (GAP H). Preserves current import behavior when the import tier is enforced
	 * strictly; never removes a `manage` cap.
	 *
	 * Runs once per install via {@see \FreeFormCertificate\Loader} on
	 * `plugins_loaded`, flagged by the `ffc_import_caps_granted_v1` option.
	 *
	 * @since 6.9.0
	 * @return array<string, int> Per-import-cap count of users seeded.
	 */
	public static function migrate_import_caps_grant(): array {
		$map    = self::import_cap_grant_map();
		$counts = array();

		// 1. User-meta grants.
		$users = get_users( array( 'fields' => 'ID' ) );
		foreach ( $map as $manage => $import ) {
			$counts[ $import ] = 0;
			foreach ( $users as $user_id ) {
				$user = get_userdata( (int) $user_id );
				if ( ! $user ) {
					continue;
				}
				// Seed only where the manage cap is an explicit grant and the
				// import cap isn't already present (idempotent).
				if ( isset( $user->caps[ $manage ] ) && true === $user->caps[ $manage ]
					&& ! isset( $user->caps[ $import ] ) ) {
					$user->add_cap( $import, true );
					++$counts[ $import ];
				}
			}
		}

		// 2. Role definitions — administrator + every FFC/custom role.
		$wp_roles = wp_roles();
		foreach ( array_keys( $wp_roles->roles ) as $role_slug ) {
			$role = get_role( $role_slug );
			if ( ! $role ) {
				continue;
			}
			foreach ( $map as $manage => $import ) {
				if ( isset( $role->capabilities[ $manage ] ) && true === $role->capabilities[ $manage ]
					&& ! isset( $role->capabilities[ $import ] ) ) {
					$role->add_cap( $import, true );
				}
			}
		}

		return $counts;
	}

	/**
	 * Map of source cap => the recruitment-reasons cap it seeds on upgrade
	 * (GAP I). Unlike the delete/export/import maps (all keyed on a `manage`
	 * cap), reasons split into a *pair*: the read tier is seeded from whoever
	 * could already see the Reasons tab (`ffc_view_recruitment`), and the edit
	 * tier from whoever could already edit reasons via the umbrella
	 * (`ffc_manage_recruitment`). This preserves both read and edit access when
	 * the reasons sub-domain is carved out of the page/umbrella caps.
	 *
	 * @since 6.9.0
	 * @return array<string, string>
	 */
	public static function reasons_cap_grant_map(): array {
		return array(
			'ffc_view_recruitment'   => 'ffc_view_recruitment_reasons',
			'ffc_manage_recruitment' => 'ffc_manage_recruitment_reasons',
		);
	}

	/**
	 * Idempotent migration that seeds the recruitment-reasons caps onto every
	 * user/role that already holds the matching source cap (GAP I). Preserves
	 * current read/edit access when the Reasons tab is moved onto its own strict
	 * 3-state tier; never removes a source cap.
	 *
	 * Runs once per install via {@see \FreeFormCertificate\Loader} on
	 * `plugins_loaded`, flagged by the `ffc_reasons_caps_wired_v1` option.
	 *
	 * @since 6.9.0
	 * @return array<string, int> Per-reasons-cap count of users seeded.
	 */
	public static function migrate_reasons_caps_grant(): array {
		$map    = self::reasons_cap_grant_map();
		$counts = array();

		// 1. User-meta grants.
		$users = get_users( array( 'fields' => 'ID' ) );
		foreach ( $map as $source => $reasons_cap ) {
			$counts[ $reasons_cap ] = 0;
			foreach ( $users as $user_id ) {
				$user = get_userdata( (int) $user_id );
				if ( ! $user ) {
					continue;
				}
				if ( isset( $user->caps[ $source ] ) && true === $user->caps[ $source ]
					&& ! isset( $user->caps[ $reasons_cap ] ) ) {
					$user->add_cap( $reasons_cap, true );
					++$counts[ $reasons_cap ];
				}
			}
		}

		// 2. Role definitions — administrator + every FFC/custom role.
		$wp_roles = wp_roles();
		foreach ( array_keys( $wp_roles->roles ) as $role_slug ) {
			$role = get_role( $role_slug );
			if ( ! $role ) {
				continue;
			}
			foreach ( $map as $source => $reasons_cap ) {
				if ( isset( $role->capabilities[ $source ] ) && true === $role->capabilities[ $source ]
					&& ! isset( $role->capabilities[ $reasons_cap ] ) ) {
					$role->add_cap( $reasons_cap, true );
				}
			}
		}

		return $counts;
	}

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

		/* translators: %s: user display name */
		$message = sprintf( __( 'Hello %s,', 'ffcertificate' ), $user->display_name ) . "\n\n";
		/* translators: %1$s: feature name, %2$s: site name */
		$message .= sprintf( __( 'You now have access to %1$s on %2$s.', 'ffcertificate' ), $context_label, $site_name ) . "\n\n";

		if ( $dashboard_url ) {
			/* translators: %s: dashboard URL */
			$message .= sprintf( __( 'Access your dashboard: %s', 'ffcertificate' ), $dashboard_url ) . "\n\n";
		}

		$message .= __( 'This is an automated message.', 'ffcertificate' ) . "\n";

		wp_mail( $user->user_email, $subject, $message );
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
	 * Register ffc_user role on plugin activation
	 *
	 * @return void
	 */
	public static function register_role(): void {
		$existing_role = get_role( 'ffc_user' );

		if ( $existing_role ) {
			self::upgrade_role( $existing_role );
			return;
		}

		// `ffc_user` only owns the WordPress baseline `read` cap. FFC caps are
		// granted per-user via `grant_*_capabilities()` (user-meta `add_cap(true)`).
		//
		// Historically every FFC cap was added here as `=> false` to make the
		// role's surface explicit, but that breaks multi-role users (e.g. an
		// administrator who is also an `ffc_user` for their own certificates):
		// WP's `WP_User::get_role_caps()` merges role capability maps via
		// `array_merge()`, and an explicit `false` in one role overwrites a
		// `true` from another. An absent key, by contrast, lets the other
		// role's `true` survive. See issue #86.
		add_role(
			'ffc_user',
			__( 'FFC User', 'ffcertificate' ),
			array( 'read' => true )
		);
	}

	/**
	 * Upgrade existing ffc_user role with new capabilities
	 *
	 * @since 4.4.0
	 * @param \WP_Role $role Existing role object.
	 * @return void
	 */
	private static function upgrade_role( \WP_Role $role ): void {
		// Strip every legacy `=> false` entry for FFC caps so multi-role users
		// (e.g. administrator + ffc_user) don't have admin-granted caps masked
		// by ffc_user's explicit denial. New caps are NOT added — absent ≡
		// false for `current_user_can()` on single-role users, while letting
		// `array_merge()` preserve `true` from a peer role for multi-role
		// users. See issue #86 / register_role() for the full rationale.
		foreach ( self::get_all_capabilities() as $cap ) {
			if ( isset( $role->capabilities[ $cap ] ) && false === $role->capabilities[ $cap ] ) {
				$role->remove_cap( $cap );
			}
		}
	}

	/**
	 * Remove ffc_user role on plugin deactivation
	 *
	 * @return void
	 */
	public static function remove_role(): void {
		remove_role( 'ffc_user' );
	}

	/**
	 * Register the dedicated recruitment-manager role on plugin activation.
	 *
	 * Idempotent: if the role already exists, it is upgraded so any newly
	 * introduced caps are added without disturbing other caps the admin may
	 * have manually granted. Granted caps: `read` + `ffc_manage_recruitment`.
	 *
	 * Called from {@see \FreeFormCertificate\Activator::activate()}.
	 *
	 * @since 6.0.0
	 * @return void
	 */
	public static function register_recruitment_manager_role(): void {
		$existing_role = get_role( self::RECRUITMENT_MANAGER_ROLE );

		if ( $existing_role ) {
			// Upgrade path: ensure both caps are present without overwriting
			// any admin-customized capability map.
			if ( ! isset( $existing_role->capabilities['read'] ) ) {
				$existing_role->add_cap( 'read', true );
			}
			if ( ! isset( $existing_role->capabilities['ffc_manage_recruitment'] ) ) {
				$existing_role->add_cap( 'ffc_manage_recruitment', true );
			}
			return;
		}

		add_role(
			self::RECRUITMENT_MANAGER_ROLE,
			__( 'Recruitment Manager', 'ffcertificate' ),
			array(
				'read'                   => true,
				'ffc_manage_recruitment' => true,
			)
		);
	}

	/**
	 * Remove the recruitment-manager role on plugin uninstall.
	 *
	 * Called from `uninstall.php` after the cap-stripping loop has cleared
	 * `ffc_manage_recruitment` from any user that had been granted it
	 * directly.
	 *
	 * @since 6.0.0
	 * @return void
	 */
	public static function remove_recruitment_manager_role(): void {
		remove_role( self::RECRUITMENT_MANAGER_ROLE );
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
	private static function module_roles_definition(): array {
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

	/**
	 * Register every 6.2.0 module-manager + recruitment-tier role.
	 *
	 * Idempotent. Called on activation AND from `RecruitmentLoader` on
	 * `plugins_loaded` so in-place plugin updates self-heal — no need
	 * for a deactivate/reactivate cycle to surface new roles.
	 *
	 * Existing roles are upgraded: missing caps are added; extra caps
	 * an operator manually granted are NOT removed.
	 *
	 * @since 6.2.0
	 * @return void
	 */
	public static function register_module_roles(): void {
		foreach ( self::module_roles_definition() as $slug => $def ) {
			$existing = get_role( $slug );
			if ( $existing ) {
				if ( ! isset( $existing->capabilities['read'] ) ) {
					$existing->add_cap( 'read', true );
				}
				foreach ( $def['caps'] as $cap ) {
					if ( ! isset( $existing->capabilities[ $cap ] ) ) {
						$existing->add_cap( $cap, true );
					}
				}
				continue;
			}

			$caps_map = array( 'read' => true );
			foreach ( $def['caps'] as $cap ) {
				$caps_map[ $cap ] = true;
			}
			add_role( $slug, $def['label'], $caps_map );
		}

		// Upgrade the existing `ffc_recruitment_manager` (6.0.0) to the new
		// tier-2 cap set. Adds the 6.2.0 granular caps without removing the
		// umbrella `ffc_manage_recruitment` cap that was there from launch.
		$existing_manager = get_role( self::RECRUITMENT_MANAGER_ROLE );
		if ( $existing_manager ) {
			$tier_2_caps = array(
				'ffc_view_recruitment',
				'ffc_call_recruitment',
				'ffc_import_recruitment',
				'ffc_view_recruitment_pii',
				'ffc_delete_recruitment',
				// GAP I: reasons split into their own strict tier. A plain
				// Recruitment Manager previously edited reasons via the umbrella;
				// carry both reasons caps explicitly so that keeps working.
				'ffc_view_recruitment_reasons',
				'ffc_manage_recruitment_reasons',
			);
			foreach ( $tier_2_caps as $cap ) {
				if ( ! isset( $existing_manager->capabilities[ $cap ] ) ) {
					$existing_manager->add_cap( $cap, true );
				}
			}
		}
	}

	/**
	 * Remove every 6.2.0 module-manager + recruitment-tier role on
	 * plugin uninstall.
	 *
	 * @since 6.2.0
	 * @return void
	 */
	public static function remove_module_roles(): void {
		foreach ( array_keys( self::module_roles_definition() ) as $slug ) {
			remove_role( $slug );
		}
	}

	/**
	 * Re-translate FFC role labels at every page load.
	 *
	 * WordPress stores role labels verbatim in the `wp_user_roles` option
	 * at the moment of `add_role()`. If translations weren't loaded yet,
	 * the English string gets frozen in the database. On subsequent loads,
	 * `users.php` displays role names via WP's `translate_user_role()`
	 * helper — but that helper resolves the label against the **default**
	 * WP textdomain, not the plugin's textdomain. So plugin-provided role
	 * labels never translate, even after the .po file is loaded.
	 *
	 * Hooking `wp_roles_init` (since WP 4.7) lets us mutate the in-memory
	 * `WP_Roles::$roles` + `WP_Roles::$role_names` arrays after they're
	 * loaded. We re-resolve every FFC role's label through `__()` against
	 * the plugin's textdomain, so users.php always shows the user's locale.
	 *
	 * Hooked from `Loader::register_ffc_roles_safe()` (init:1) AFTER the
	 * role registrations themselves, so the freshly-registered labels also
	 * get re-translated on the very first page load post-upgrade.
	 *
	 * @since 6.2.0
	 * @param \WP_Roles $wp_roles Roles instance.
	 * @return void
	 */
	public static function relabel_ffc_roles( \WP_Roles $wp_roles ): void {
		$labels = self::ffc_managed_role_labels();

		foreach ( $labels as $slug => $label ) {
			if ( isset( $wp_roles->roles[ $slug ] ) ) {
				$wp_roles->roles[ $slug ]['name'] = $label;
			}
			if ( isset( $wp_roles->role_names[ $slug ] ) ) {
				$wp_roles->role_names[ $slug ] = $label;
			}
		}
	}

	/**
	 * Canonical map of every FFC-managed role slug → display label.
	 *
	 * The authoritative set of roles the plugin owns: `ffc_user`, the
	 * recruitment manager, and the 6.2.0 module/recruitment-tier roles. This
	 * is the list the role-capability editor (Settings → User Access) is
	 * allowed to touch — independent of whichever caps a role currently
	 * carries, so a role whose FFC caps were all unchecked still appears.
	 *
	 * @since 6.9.0
	 * @return array<string, string>
	 */
	public static function ffc_managed_role_labels(): array {
		$labels = array(
			'ffc_user'                     => __( 'FFC User', 'ffcertificate' ),
			self::RECRUITMENT_MANAGER_ROLE => __( 'Recruitment Manager', 'ffcertificate' ),
		);
		foreach ( self::module_roles_definition() as $slug => $def ) {
			$labels[ $slug ] = $def['label'];
		}
		return $labels;
	}
}

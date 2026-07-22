<?php
/**
 * CapabilityMigrator
 *
 * One-shot, option-flagged capability migrations — legacy (pre-6.2.0) cap
 * renames, the 6.9.0 taxonomy renames, and the per-tier grant back-fills
 * (delete / export / import / reasons). Extracted from CapabilityManager
 * (#563 Sprint 2): these run once per install via the Loader on
 * `plugins_loaded` and do not belong in the live capability manager.
 *
 * @package FreeFormCertificate\UserDashboard
 */

declare(strict_types=1);

namespace FreeFormCertificate\UserDashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-shot capability migrations.
 */
class CapabilityMigrator {

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
	 * (user-meta `add_cap(true)`) and on the `ffc_end_user` role to the new
	 * `ffc_*` namespace.
	 *
	 * Strategy: for each `legacy => new` pair,
	 *   1. iterate every user that has the legacy cap, add the new cap, drop
	 *      the legacy cap;
	 *   2. on the `ffc_end_user` role, if the legacy cap exists, add the new
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

			// 2. ffc_end_user role definition.
			$role = get_role( 'ffc_end_user' );
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
	 * Map of the `manage` cap => the settings sub-caps it seeds on upgrade (#711).
	 *
	 * The one-shot migration {@see self::migrate_settings_split_caps_grant()}
	 * grants every listed sub-cap to each user/role already holding the key cap,
	 * so existing settings managers keep saving SMTP and running danger-zone
	 * actions after those surfaces are carved out of `ffc_manage_settings`. To
	 * withhold a surface from a manager, remove the sub-cap from that role/user
	 * after the migration has run.
	 *
	 * @since 6.15.0
	 * @return array<string, array<int, string>>
	 */
	public static function settings_split_cap_grant_map(): array {
		return array(
			'ffc_manage_settings' => array(
				'ffc_manage_settings_smtp',
				'ffc_manage_settings_dangerzone',
			),
		);
	}

	/**
	 * Idempotent migration seeding the settings sub-caps (#711) onto every user
	 * and role that already holds `ffc_manage_settings`. Preserves current SMTP /
	 * danger-zone behavior when those sub-caps are split out of the blanket cap;
	 * never removes the source cap.
	 *
	 * Runs once per install via {@see \FreeFormCertificate\Loader} on
	 * `plugins_loaded`, flagged by the `ffc_settings_split_caps_v1` option.
	 *
	 * @since 6.15.0
	 * @return array<string, int> Per-sub-cap count of users seeded.
	 */
	public static function migrate_settings_split_caps_grant(): array {
		$map    = self::settings_split_cap_grant_map();
		$counts = array();

		// 1. User-meta grants.
		$users = get_users( array( 'fields' => 'ID' ) );
		foreach ( $map as $source => $targets ) {
			foreach ( $targets as $target ) {
				if ( ! isset( $counts[ $target ] ) ) {
					$counts[ $target ] = 0;
				}
				foreach ( $users as $user_id ) {
					$user = get_userdata( (int) $user_id );
					if ( ! $user ) {
						continue;
					}
					// Seed only where the source cap is an explicit grant and the
					// sub-cap isn't already present (idempotent).
					if ( isset( $user->caps[ $source ] ) && true === $user->caps[ $source ]
						&& ! isset( $user->caps[ $target ] ) ) {
						$user->add_cap( $target, true );
						++$counts[ $target ];
					}
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
			foreach ( $map as $source => $targets ) {
				foreach ( $targets as $target ) {
					if ( isset( $role->capabilities[ $source ] ) && true === $role->capabilities[ $source ]
						&& ! isset( $role->capabilities[ $target ] ) ) {
						$role->add_cap( $target, true );
					}
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
	 * Map of the source cap => the activity-log export cap it seeds on upgrade
	 * (#711 §5). Unlike the other export caps (which split out of `manage`),
	 * activity-log export historically rode the read-only `ffc_view_activity_log`
	 * cap, so the migration seeds `ffc_export_activity_log` onto every current
	 * `ffc_view_activity_log` holder — preserving their export ability when the
	 * dedicated cap is introduced. To take export away from a viewer, remove the
	 * export cap after the migration has run.
	 *
	 * @since 6.15.0
	 * @return array<string, string>
	 */
	public static function activity_log_export_cap_grant_map(): array {
		return array(
			'ffc_view_activity_log' => 'ffc_export_activity_log',
		);
	}

	/**
	 * Idempotent migration seeding `ffc_export_activity_log` (#711 §5) onto every
	 * user and role that already holds `ffc_view_activity_log`. Never removes the
	 * source cap.
	 *
	 * Runs once per install via {@see \FreeFormCertificate\Loader} on
	 * `plugins_loaded`, flagged by the `ffc_activity_log_export_cap_v1` option.
	 *
	 * @since 6.15.0
	 * @return array<string, int> Per-export-cap count of users seeded.
	 */
	public static function migrate_activity_log_export_cap_grant(): array {
		$map    = self::activity_log_export_cap_grant_map();
		$counts = array();

		// 1. User-meta grants.
		$users = get_users( array( 'fields' => 'ID' ) );
		foreach ( $map as $source => $export ) {
			$counts[ $export ] = 0;
			foreach ( $users as $user_id ) {
				$user = get_userdata( (int) $user_id );
				if ( ! $user ) {
					continue;
				}
				if ( isset( $user->caps[ $source ] ) && true === $user->caps[ $source ]
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
			foreach ( $map as $source => $export ) {
				if ( isset( $role->capabilities[ $source ] ) && true === $role->capabilities[ $source ]
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
	 * Move admins from role-capability grants to the `ffc_administrator` role.
	 *
	 * Historically {@see \FreeFormCertificate\Loader::ensure_admin_capabilities()}
	 * granted the 43 `ADMIN_CAPABILITIES` directly to the native `administrator`
	 * role. This one-shot migration switches to the role model instead:
	 *
	 *   1. Every EXISTING administrator is given the `ffc_administrator` role as
	 *      an additional role (they keep `administrator` for `manage_options`),
	 *      so no FFC access is lost.
	 *   2. The FFC admin caps previously granted to the native `administrator`
	 *      role are stripped, so that role stops carrying plugin caps.
	 *
	 * Administrators created AFTER this migration are NOT auto-elevated — the
	 * `ffc_administrator` role is granted explicitly from then on (per the
	 * agreed model). Order matters: step 1 assigns the role before step 2
	 * strips the caps, so there is no window where an admin lacks access.
	 *
	 * @since 6.16.0
	 * @return array{roles_assigned:int, caps_stripped:int}
	 */
	public static function migrate_admin_role_assignment(): array {
		$counts = array(
			'roles_assigned' => 0,
			'caps_stripped'  => 0,
		);

		// The `ffc_administrator` role is registered on `init:1`, which runs
		// after the `plugins_loaded` migration sequence on the very first
		// request. Self-heal so the back-fill never no-ops against a role that
		// has not been created yet.
		if ( ! get_role( 'ffc_administrator' ) ) {
			RoleRegistrar::register_module_roles();
		}

		// 1. Back-fill: assign `ffc_administrator` to every current admin.
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'fields' => 'ID',
			)
		);
		foreach ( $admins as $admin_id ) {
			$user = get_userdata( (int) $admin_id );
			if ( ! $user ) {
				continue;
			}
			if ( ! in_array( 'ffc_administrator', (array) $user->roles, true ) ) {
				$user->add_role( 'ffc_administrator' );
				++$counts['roles_assigned'];
			}
		}

		// 2. Stop polluting the native `administrator` role: strip the FFC admin
		// caps the old grant added. Admins keep every FFC cap through the
		// `ffc_administrator` role assigned in step 1.
		$admin_role = get_role( 'administrator' );
		if ( $admin_role ) {
			foreach ( CapabilityManager::ADMIN_CAPABILITIES as $cap ) {
				if ( isset( $admin_role->capabilities[ $cap ] ) ) {
					$admin_role->remove_cap( $cap );
					++$counts['caps_stripped'];
				}
			}
		}

		return $counts;
	}

	/**
	 * #739 RBAC-redesign capability renames (grammar / consistency pass).
	 *
	 * Distinct from {@see self::taxonomy_cap_renames()} (already flagged as run
	 * on existing installs) — these ship in a separate one-shot so upgrades pick
	 * them up. Role renames are handled apart, in {@see self::migrate_role_renames()}.
	 *
	 * @since 6.16.0
	 * @return array<string, string>
	 */
	public static function rbac_cap_renames(): array {
		return array(
			'ffc_scheduling_bypass' => 'ffc_bypass_appointments',
		);
	}

	/**
	 * Apply {@see self::rbac_cap_renames()} to user-meta grants + role defs.
	 *
	 * @since 6.16.0
	 * @return array<string, int> Old cap slug => number of user-meta rewrites.
	 */
	public static function migrate_rbac_cap_renames(): array {
		$renames = self::rbac_cap_renames();
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
	 * #739 RBAC-redesign role renames.
	 *
	 * Role slugs (not caps) — the renamed definitions are registered by
	 * {@see RoleRegistrar::register_module_roles()}; this reassigns every user
	 * from the old role to the new one and then removes the orphaned old role,
	 * so no access is lost on upgrade.
	 *
	 * @since 6.16.0
	 * @return array<string, string>
	 */
	public static function role_renames(): array {
		return array(
			'ffc_user'                    => 'ffc_end_user',
			'ffc_operator'                => 'ffc_readonly',
			'ffc_self_scheduling_manager' => 'ffc_appointments_manager',
		);
	}

	/**
	 * Apply {@see self::role_renames()}: reassign users + drop old roles.
	 *
	 * @since 6.16.0
	 * @return array<string, int> Old role slug => number of users reassigned.
	 */
	public static function migrate_role_renames(): array {
		$renames = self::role_renames();

		// Ensure the renamed roles exist before reassigning users onto them.
		// `register_role()` creates `ffc_end_user` (the end-user role);
		// `register_module_roles()` creates the module-manager roles
		// (`ffc_readonly`, `ffc_appointments_manager`, …).
		RoleRegistrar::register_role();
		RoleRegistrar::register_module_roles();

		$counts = array();
		$users  = get_users( array( 'fields' => 'ID' ) );
		foreach ( $renames as $old => $new ) {
			$counts[ $old ] = 0;
			foreach ( $users as $user_id ) {
				$user = get_userdata( (int) $user_id );
				if ( ! $user ) {
					continue;
				}
				if ( in_array( $old, (array) $user->roles, true ) ) {
					$user->add_role( $new );
					$user->remove_role( $old );
					++$counts[ $old ];
				}
			}
			remove_role( $old );
		}

		return $counts;
	}
}

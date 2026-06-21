<?php
/**
 * RoleRegistrar
 *
 * Lifecycle of the FFC roles — register/upgrade/remove the `ffc_user` role,
 * the recruitment-manager role, and the per-module roles, plus role
 * relabeling. Extracted from CapabilityManager (#563 Sprint 2); reads the
 * capability registry (the `*_CAPABILITIES` consts + `module_roles_definition()`)
 * from {@see CapabilityManager}.
 *
 * @package FreeFormCertificate\UserDashboard
 */

declare(strict_types=1);

namespace FreeFormCertificate\UserDashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registrar for FFC role lifecycle.
 */
class RoleRegistrar {

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
		foreach ( CapabilityManager::get_all_capabilities() as $cap ) {
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
		$existing_role = get_role( CapabilityManager::RECRUITMENT_MANAGER_ROLE );

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
			CapabilityManager::RECRUITMENT_MANAGER_ROLE,
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
		remove_role( CapabilityManager::RECRUITMENT_MANAGER_ROLE );
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
		foreach ( CapabilityManager::module_roles_definition() as $slug => $def ) {
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
		$existing_manager = get_role( CapabilityManager::RECRUITMENT_MANAGER_ROLE );
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
		foreach ( array_keys( CapabilityManager::module_roles_definition() ) as $slug ) {
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
			'ffc_user'                                  => __( 'FFC User', 'ffcertificate' ),
			CapabilityManager::RECRUITMENT_MANAGER_ROLE => __( 'Recruitment Manager', 'ffcertificate' ),
		);
		foreach ( CapabilityManager::module_roles_definition() as $slug => $def ) {
			$labels[ $slug ] = $def['label'];
		}
		return $labels;
	}
}

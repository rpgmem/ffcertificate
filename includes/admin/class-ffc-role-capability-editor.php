<?php
/**
 * RoleCapabilityEditor
 *
 * Global-scope counterpart to the per-user capability panel: lets an admin
 * edit which FFC capabilities each FFC *role* grants, from Settings → User
 * Access. Editing a role's definition affects EVERY user holding that role,
 * so the UI carries an explicit impact banner + per-role user counts, and
 * changes are audit-logged.
 *
 * Persistence is per-toggle via AJAX (`WP_Role::add_cap`/`remove_cap`),
 * isolated from the User Access settings form on the same tab. Restricted to
 * {@see CapabilityManager::ffc_managed_role_labels()} (the plugin's own
 * roles) and to caps in the {@see CapabilityCatalog} — core/super roles and
 * non-FFC caps are never touched.
 *
 * Scope split (see #484): the *per-user* panel assigns roles + fine-tunes a
 * single user; this editor changes the role template itself.
 *
 * @package FreeFormCertificate\Admin
 * @since   6.9.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\UserDashboard\CapabilityCatalog;
use FreeFormCertificate\UserDashboard\CapabilityManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Role → capability editor (global role definitions).
 */
final class RoleCapabilityEditor {

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_ajax_ffc_set_role_cap', array( __CLASS__, 'ajax_set_role_cap' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Enqueue assets only on the Settings → User Access tab.
	 *
	 * @param string $hook Admin page hook suffix.
	 * @return void
	 */
	public static function enqueue( string $hook ): void {
		if ( 'ffc_form_page_ffc-settings' !== $hook ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab read-only resolution for conditional asset loading.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( 'user_access' !== $tab ) {
			return;
		}

		$s = \FreeFormCertificate\Core\Utils::asset_suffix();
		wp_enqueue_style( 'ffc-common', FFC_PLUGIN_URL . "assets/css/ffc-common{$s}.css", array(), FFC_VERSION );
		wp_enqueue_style( 'ffc-user-permissions', FFC_PLUGIN_URL . "assets/css/ffc-user-permissions{$s}.css", array( 'ffc-common' ), FFC_VERSION );
		wp_enqueue_script( 'ffc-role-editor', FFC_PLUGIN_URL . "assets/js/ffc-role-editor{$s}.js", array(), FFC_VERSION, true );
		wp_localize_script(
			'ffc-role-editor',
			'ffcRoleEditor',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'ffc_set_role_cap' ),
				'roleCaps' => self::role_caps_map(),
				'i18n'     => array(
					'error' => __( 'Could not update the role. Please reload and try again.', 'ffcertificate' ),
					'saved' => __( 'Saved', 'ffcertificate' ),
				),
			)
		);
	}

	/**
	 * Registered FFC roles with display label + member count.
	 *
	 * @return list<array{slug: string, label: string, users: int}>
	 */
	public static function editable_roles(): array {
		$out = array();
		foreach ( CapabilityManager::ffc_managed_role_labels() as $slug => $label ) {
			if ( null === get_role( $slug ) ) {
				continue;
			}
			$out[] = array(
				'slug'  => (string) $slug,
				'label' => (string) $label,
				'users' => self::count_users( (string) $slug ),
			);
		}
		return $out;
	}

	/**
	 * Map of FFC role slug → the cataloged caps it currently grants.
	 *
	 * @return array<string, list<string>>
	 */
	public static function role_caps_map(): array {
		$catalog = CapabilityCatalog::all_slugs();
		$map     = array();
		foreach ( array_keys( CapabilityManager::ffc_managed_role_labels() ) as $slug ) {
			$role = get_role( (string) $slug );
			if ( null === $role ) {
				continue;
			}
			$granted = array();
			foreach ( $catalog as $cap ) {
				if ( ! empty( $role->capabilities[ $cap ] ) ) {
					$granted[] = $cap;
				}
			}
			$map[ (string) $slug ] = $granted;
		}
		return $map;
	}

	/**
	 * Count users holding a role (0 when WP_User_Query is unavailable).
	 *
	 * @param string $role Role slug.
	 * @return int
	 */
	private static function count_users( string $role ): int {
		if ( ! class_exists( '\WP_User_Query' ) ) {
			return 0;
		}
		$query = new \WP_User_Query(
			array(
				'role'        => $role,
				'number'      => 1,
				'fields'      => 'ID',
				'count_total' => true,
			)
		);
		return (int) $query->get_total();
	}

	/**
	 * Render the editor (included from the User Access tab view, after the
	 * settings form so it is not part of that form's submit).
	 *
	 * @return void
	 */
	public static function render(): void {
		$roles = self::editable_roles();

		echo '<hr class="ffc-role-editor-sep">';
		echo '<h2>' . esc_html__( 'FFC Roles & Capabilities', 'ffcertificate' ) . '</h2>';
		echo '<div class="notice notice-warning inline ffc-role-impact"><p>';
		echo esc_html__( 'Editing a role changes the capabilities for every user holding that role — immediately, and retroactively. Changes save as you toggle and are recorded in the activity log. The role definition is global; to change a single person, use their profile screen instead.', 'ffcertificate' );
		echo '</p></div>';

		if ( empty( $roles ) ) {
			echo '<p>' . esc_html__( 'No FFC roles are registered.', 'ffcertificate' ) . '</p>';
			return;
		}

		$first   = $roles[0]['slug'];
		$granted = self::role_caps_map()[ $first ] ?? array();

		echo '<div class="ffc-cap-panel ffc-role-editor">';

		// Role picker + search.
		echo '<div class="ffc-cap-toolbar">';
		echo '<label class="ffc-role-pick">' . esc_html__( 'Role:', 'ffcertificate' ) . ' ';
		echo '<select class="ffc-role-select" aria-label="' . esc_attr__( 'Select a role to edit', 'ffcertificate' ) . '">';
		foreach ( $roles as $i => $role ) {
			printf(
				'<option value="%1$s"%2$s>%3$s — %4$s</option>',
				esc_attr( $role['slug'] ),
				0 === $i ? ' selected' : '',
				esc_html( $role['label'] ),
				esc_html(
					sprintf(
						/* translators: %d: number of users holding the role */
						_n( '%d user', '%d users', $role['users'], 'ffcertificate' ),
						$role['users']
					)
				)
			);
		}
		echo '</select></label>';
		printf(
			'<input type="search" class="ffc-cap-search" placeholder="%s" aria-label="%s">',
			esc_attr__( 'Search permission or slug…', 'ffcertificate' ),
			esc_attr__( 'Search permissions', 'ffcertificate' )
		);
		echo '</div>';

		self::render_catalog_grid( $granted );

		echo '</div>';
	}

	/**
	 * Render the cataloged capabilities as grouped cards of toggles, checked
	 * for the caps the (initially selected) role grants. The JS swaps the
	 * checked state when the role picker changes and persists each toggle.
	 *
	 * @param array<int, string> $granted Caps granted by the initially-selected role.
	 * @return void
	 */
	private static function render_catalog_grid( array $granted ): void {
		$prev_level = null;
		foreach ( CapabilityCatalog::groups() as $group ) {
			$is_admin = 'admin' === $group['level'];
			$total    = count( $group['caps'] );
			$count    = 0;

			// Section divider between the self-service and administration tiers.
			if ( $group['level'] !== $prev_level ) {
				echo '<h3 class="ffc-cap-section">' . esc_html( CapabilityCatalog::level_section_label( (string) $group['level'] ) ) . '</h3>';
				$prev_level = $group['level'];
			}

			$rows = '';
			foreach ( $group['caps'] as $slug => $meta ) {
				$on = in_array( (string) $slug, $granted, true );
				if ( $on ) {
					++$count;
				}
				$toggle        = AdminUI::get_toggle(
					array(
						// Name is unused (persisted via AJAX, not this form) but
						// AdminUI::get_toggle refuses to render without one.
						'name'        => 'ffc_role_cap_' . $slug,
						'id'          => 'ffc_role_cap_' . $slug,
						'checked'     => $on,
						'label'       => '',
						'input_class' => 'ffc-role-cap',
						'data'        => array( 'ffc-cap-slug' => (string) $slug ),
					)
				);
				$label         = (string) $meta['label'];
				$desc          = (string) $meta['description'];
				$surface_badge = CapabilityCatalog::surface_badge_html( $meta );
				$rows         .= sprintf(
					'<div class="ffc-cap-row" data-ffc-cap-name="%1$s" data-ffc-cap-slug="%2$s">'
						. '<div class="ffc-cap-row-toggle">%3$s</div>'
						. '<div class="ffc-cap-row-text"><span class="ffc-cap-row-name">%4$s%6$s</span>'
						. '<span class="ffc-cap-row-desc">%5$s</span>'
						. '<span class="ffc-cap-slug">%2$s</span></div>'
						. '<span class="ffc-cap-savestate" data-ffc-savestate aria-live="polite"></span>'
						. '</div>',
					esc_attr( strtolower( $label . ' ' . $slug ) ),
					esc_attr( (string) $slug ),
					$toggle, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AdminUI::get_toggle() returns pre-escaped markup.
					esc_html( $label ),
					esc_html( $desc ),
					$surface_badge // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_* in surface_badge().
				);
			}

			// Every group starts collapsed for easier navigation; the live
			// search auto-expands the groups that still have hits.
			printf(
				'<section class="ffc-cap-group is-collapsed" data-ffc-group="%1$s">',
				esc_attr( (string) $group['key'] )
			);
			echo '<button type="button" class="ffc-cap-group-h" aria-expanded="false">';
			echo '<span class="ffc-cap-caret" aria-hidden="true"></span>';
			echo '<span class="ffc-cap-gtitle">' . esc_html( (string) $group['label'] ) . '</span>';
			if ( $is_admin ) {
				echo '<span class="ffc-cap-badge-admin">' . esc_html__( 'admin', 'ffcertificate' ) . '</span>';
			}
			echo '<span class="ffc-cap-gmeta"><span class="ffc-cap-count">' . (int) $count . '</span>/' . (int) $total . '</span>';
			echo '</button>';
			echo '<div class="ffc-cap-group-body">';
			echo $rows; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each dynamic part escaped above.
			echo '</div>';
			echo '</section>';
		}
	}

	/**
	 * AJAX: grant/remove one cataloged capability on one FFC role.
	 *
	 * @return void
	 */
	public static function ajax_set_role_cap(): void {
		check_ajax_referer( 'ffc_set_role_cap', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		$role_slug = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : '';
		$cap       = isset( $_POST['cap'] ) ? sanitize_key( wp_unslash( $_POST['cap'] ) ) : '';
		$grant     = isset( $_POST['grant'] ) && '1' === (string) wp_unslash( $_POST['grant'] );

		if ( ! array_key_exists( $role_slug, CapabilityManager::ffc_managed_role_labels() ) ) {
			wp_send_json_error( array( 'message' => 'role_not_editable' ), 400 );
		}
		if ( ! in_array( $cap, CapabilityCatalog::all_slugs(), true ) ) {
			wp_send_json_error( array( 'message' => 'cap_not_in_catalog' ), 400 );
		}

		$role = get_role( $role_slug );
		if ( null === $role ) {
			wp_send_json_error( array( 'message' => 'role_not_found' ), 404 );
		}

		if ( $grant ) {
			$role->add_cap( $cap, true );
		} else {
			$role->remove_cap( $cap );
		}

		if ( class_exists( '\FreeFormCertificate\Core\Debug' ) ) {
			\FreeFormCertificate\Core\Debug::log_user_manager(
				'Admin edited role capability',
				array(
					'admin_id' => get_current_user_id(),
					'role'     => $role_slug,
					'cap'      => $cap,
					'granted'  => $grant ? 1 : 0,
				)
			);
		}

		wp_send_json_success(
			array(
				'role'    => $role_slug,
				'cap'     => $cap,
				'granted' => $grant,
			)
		);
	}
}

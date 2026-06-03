<?php
/**
 * AdminUserCapabilities
 *
 * Adds FFC capability management to WordPress user edit page.
 * Allows admins to toggle certificate and appointment capabilities per user.
 *
 * @package FreeFormCertificate\Admin
 * @since 4.4.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin User Capabilities.
 */
class AdminUserCapabilities {

	/**
	 * Initialize the class
	 */
	public static function init(): void {
		// Add capability section to user edit page.
		add_action( 'show_user_profile', array( __CLASS__, 'render_capability_fields' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_capability_fields' ) );

		// Save capability changes.
		add_action( 'personal_options_update', array( __CLASS__, 'save_capability_fields' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_capability_fields' ) );

		// Enqueue scripts on user profile pages.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue scripts on user profile pages
	 *
	 * @param string $hook_suffix Admin page hook suffix.
	 */
	public static function enqueue_scripts( string $hook_suffix ): void {
		if ( 'user-edit.php' !== $hook_suffix && 'profile.php' !== $hook_suffix ) {
			return;
		}
		$s = \FreeFormCertificate\Core\Utils::asset_suffix();
		// ffc-common.css carries the .ffc-toggle switch styles reused by the
		// capability rows; ffc-user-permissions.css adds the grouped-card
		// layout, slug chips, origin badges and search/preset toolbar.
		wp_enqueue_style(
			'ffc-common',
			FFC_PLUGIN_URL . "assets/css/ffc-common{$s}.css",
			array(),
			FFC_VERSION
		);
		wp_enqueue_style(
			'ffc-user-permissions',
			FFC_PLUGIN_URL . "assets/css/ffc-user-permissions{$s}.css",
			array( 'ffc-common' ),
			FFC_VERSION
		);
		wp_enqueue_script(
			'ffc-user-capabilities',
			FFC_PLUGIN_URL . "assets/js/ffc-user-capabilities{$s}.js",
			array(),
			FFC_VERSION,
			true
		);
	}

	/**
	 * Render capability management fields on user profile page
	 *
	 * @param \WP_User $user User object.
	 * @return void
	 */
	public static function render_capability_fields( \WP_User $user ): void {
		// Only show for admins.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Don't show for users with manage_options (administrators) — they already.
		// have full FFC access via role-level capabilities.  Showing checkboxes for.
		// admins is confusing and saving can accidentally deny role-level grants.
		if ( user_can( $user->ID, 'manage_options' ) ) {
			return;
		}

		// Only show for users with ffc_user role.
		if ( ! in_array( 'ffc_user', $user->roles, true ) && ! self::has_any_ffc_capability( $user->ID ) ) {
			return;
		}

		// Add nonce.
		wp_nonce_field( 'ffc_user_capabilities', 'ffc_capabilities_nonce' );

		echo '<h2>' . esc_html__( 'FFC Permissions', 'ffcertificate' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Manage which FFC features this user can access. These are granted per user, on top of the role permissions.', 'ffcertificate' ) . '</p>';

		echo '<div class="ffc-cap-panel">';
		self::render_context_summary( $user );
		self::render_toolbar();
		self::render_groups( $user );
		echo '</div>';
	}

	/**
	 * Read-only context: the user's role(s) and audience memberships.
	 *
	 * Neither is edited here by design — the role uses WordPress' native
	 * selector on the same screen, and audience membership is managed on the
	 * dedicated Audiences page. Surfacing them gives the admin the context to
	 * read the per-cap origin badges ("Role" vs "User") correctly.
	 *
	 * @param \WP_User $user User being edited.
	 * @return void
	 */
	private static function render_context_summary( \WP_User $user ): void {
		echo '<div class="ffc-cap-context">';

		// Roles.
		echo '<div class="ffc-cap-context-item">';
		echo '<span class="ffc-cap-context-label">' . esc_html__( 'Role', 'ffcertificate' ) . '</span>';
		echo '<span class="ffc-cap-context-val">';
		$role_labels = self::role_labels( $user );
		if ( empty( $role_labels ) ) {
			echo '<em>' . esc_html__( 'No role', 'ffcertificate' ) . '</em>';
		} else {
			foreach ( $role_labels as $role_label ) {
				echo '<span class="ffc-cap-chip">' . esc_html( $role_label ) . '</span>';
			}
		}
		echo '</span>';
		echo '<span class="ffc-cap-context-hint">' . esc_html__( 'Edit the role in the standard WordPress selector on this page. Permissions inherited from the role are tagged "Role" below.', 'ffcertificate' ) . '</span>';
		echo '</div>';

		// Audiences — editable membership checklist.
		self::render_audience_membership( $user );

		echo '</div>';
	}

	/**
	 * Render the editable audience-membership checklist.
	 *
	 * Lists every *active* audience as a checkbox (pre-checked when the user
	 * is a member). Submitting the profile form syncs membership via
	 * {@see self::sync_audience_membership()}. Only active audiences are
	 * shown, so a membership in an inactive audience is never touched here.
	 *
	 * @param \WP_User $user User being edited.
	 * @return void
	 */
	private static function render_audience_membership( \WP_User $user ): void {
		$all_active = self::active_audiences();
		$member_ids = self::user_audience_ids( $user->ID );

		echo '<div class="ffc-cap-context-item ffc-cap-audiences">';
		echo '<span class="ffc-cap-context-label">' . esc_html__( 'Audiences', 'ffcertificate' ) . '</span>';

		if ( empty( $all_active ) ) {
			echo '<span class="ffc-cap-context-val"><em>' . esc_html__( 'No audiences defined yet.', 'ffcertificate' ) . '</em></span>';
		} else {
			echo '<div class="ffc-cap-audience-list" role="group" aria-label="' . esc_attr__( 'Audience membership', 'ffcertificate' ) . '">';
			foreach ( $all_active as $audience ) {
				$aid     = (int) $audience->id;
				$name    = (string) $audience->name;
				$color   = isset( $audience->color ) ? (string) $audience->color : '';
				$checked = in_array( $aid, $member_ids, true );

				echo '<label class="ffc-cap-aud">';
				echo '<input type="checkbox" name="ffc_audience[]" value="' . esc_attr( (string) $aid ) . '"' . ( $checked ? ' checked' : '' ) . '>';
				if ( '' !== $color ) {
					echo '<span class="ffc-cap-aud-dot" style="--ffc-cap-chip-color:' . esc_attr( $color ) . '"></span>';
				}
				echo '<span class="ffc-cap-aud-name">' . esc_html( $name ) . '</span>';
				echo '</label>';
			}
			echo '</div>';
		}

		printf(
			'<a class="ffc-cap-context-hint" href="%1$s">%2$s</a>',
			esc_url( admin_url( 'admin.php?page=ffc-scheduling-audiences' ) ),
			esc_html__( 'Open the Audiences screen →', 'ffcertificate' )
		);
		echo '</div>';
	}

	/**
	 * Search box + grant/revoke-all preset buttons.
	 *
	 * @return void
	 */
	private static function render_toolbar(): void {
		echo '<div class="ffc-cap-toolbar">';
		printf(
			'<input type="search" class="ffc-cap-search" placeholder="%1$s" aria-label="%2$s">',
			esc_attr__( 'Search permission or slug…', 'ffcertificate' ),
			esc_attr__( 'Search permissions', 'ffcertificate' )
		);
		echo '<span class="ffc-cap-presets">';
		printf( '<button type="button" class="button" data-ffc-preset="all">%s</button>', esc_html__( 'Grant all', 'ffcertificate' ) );
		printf( '<button type="button" class="button" data-ffc-preset="none">%s</button>', esc_html__( 'Revoke all', 'ffcertificate' ) );
		echo '</span>';
		echo '</div>';
	}

	/**
	 * Render every capability group as a collapsible card.
	 *
	 * @param \WP_User $user User being edited.
	 * @return void
	 */
	private static function render_groups( \WP_User $user ): void {
		$user_caps = $user->caps;

		foreach ( \FreeFormCertificate\UserDashboard\CapabilityCatalog::groups() as $group ) {
			$is_admin = 'admin' === $group['level'];
			$total    = count( $group['caps'] );

			// Build the rows first so the header can show a live granted count.
			$rows    = '';
			$granted = 0;
			foreach ( $group['caps'] as $slug => $meta ) {
				$granted_user = ! empty( $user_caps[ $slug ] );
				if ( $granted_user ) {
					++$granted;
				}
				$effective = user_can( $user->ID, $slug );
				$origin    = $granted_user ? 'user' : ( $effective ? 'role' : 'none' );
				$rows     .= self::render_cap_row( (string) $slug, $meta, (string) $group['key'], $granted_user, $origin );
			}

			printf(
				'<section class="ffc-cap-group%1$s" data-ffc-group="%2$s">',
				$is_admin ? ' is-collapsed' : '',
				esc_attr( (string) $group['key'] )
			);

			// Header.
			echo '<button type="button" class="ffc-cap-group-h" aria-expanded="' . ( $is_admin ? 'false' : 'true' ) . '">';
			echo '<span class="ffc-cap-caret" aria-hidden="true"></span>';
			echo '<span class="ffc-cap-gtitle">' . esc_html( (string) $group['label'] ) . '</span>';
			if ( $is_admin ) {
				echo '<span class="ffc-cap-badge-admin">' . esc_html__( 'admin', 'ffcertificate' ) . '</span>';
			}
			echo '<span class="ffc-cap-gmeta"><span class="ffc-cap-count">' . (int) $granted . '</span>/' . (int) $total . '</span>';
			echo '</button>';

			// Body.
			echo '<div class="ffc-cap-group-body">';
			echo $rows; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_cap_row() returns markup whose dynamic parts are individually escaped.
			echo '</div>';

			echo '</section>';
		}
	}

	/**
	 * Render a single capability row: switch + label + description +
	 * copyable slug chip + origin badge.
	 *
	 * @param string                $slug         Capability slug.
	 * @param array<string, string> $meta         Catalog metadata (`label`, `description`).
	 * @param string                $group_key    Owning group key (for JS preset/filter hooks).
	 * @param bool                  $checked      Whether a user-level grant exists.
	 * @param string                $origin       `user` | `role` | `none`.
	 * @return string Markup with every dynamic part escaped.
	 */
	private static function render_cap_row( string $slug, array $meta, string $group_key, bool $checked, string $origin ): string {
		$label = isset( $meta['label'] ) ? (string) $meta['label'] : $slug;
		$desc  = isset( $meta['description'] ) ? (string) $meta['description'] : '';

		$toggle = \FreeFormCertificate\Admin\AdminUI::get_toggle(
			array(
				'name'        => 'ffc_cap_' . $slug,
				'id'          => 'ffc_cap_' . $slug,
				'checked'     => $checked,
				'label'       => '',
				'input_class' => 'ffc-cap-checkbox',
				'data'        => array( 'ffc-cap-group' => $group_key ),
			)
		);

		return sprintf(
			'<div class="ffc-cap-row" data-ffc-cap-name="%1$s" data-ffc-cap-slug="%2$s">'
				. '<div class="ffc-cap-row-toggle">%3$s</div>'
				. '<div class="ffc-cap-row-text">'
				. '<span class="ffc-cap-row-name">%4$s</span>'
				. '<span class="ffc-cap-row-desc">%5$s</span>'
				. '<span class="ffc-cap-slug">%2$s<button type="button" class="ffc-cap-copy" data-ffc-copy="%2$s" aria-label="%6$s" title="%6$s">⧉</button></span>'
				. '</div>'
				. '<span class="ffc-cap-origin ffc-cap-origin--%7$s">%8$s</span>'
				. '</div>',
			esc_attr( strtolower( $label . ' ' . $slug ) ),
			esc_attr( $slug ),
			$toggle, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AdminUI::get_toggle() returns pre-escaped markup.
			esc_html( $label ),
			esc_html( $desc ),
			esc_attr__( 'Copy slug', 'ffcertificate' ),
			esc_attr( $origin ),
			esc_html( self::origin_label( $origin ) )
		);
	}

	/**
	 * Human label for an origin badge.
	 *
	 * @param string $origin `user` | `role` | `none`.
	 * @return string
	 */
	private static function origin_label( string $origin ): string {
		switch ( $origin ) {
			case 'user':
				return __( 'User', 'ffcertificate' );
			case 'role':
				return __( 'Role', 'ffcertificate' );
			default:
				return __( '—', 'ffcertificate' );
		}
	}

	/**
	 * Display labels for the user's role(s), falling back to the raw slug
	 * when the role catalog is unavailable (e.g. in unit tests).
	 *
	 * @param \WP_User $user User being edited.
	 * @return list<string>
	 */
	private static function role_labels( \WP_User $user ): array {
		$roles = $user->roles;
		if ( function_exists( 'wp_roles' ) ) {
			$names = wp_roles()->get_names();
			return array_values(
				array_map(
					static function ( $slug ) use ( $names ) {
						return isset( $names[ $slug ] ) ? (string) $names[ $slug ] : (string) $slug;
					},
					$roles
				)
			);
		}
		return array_values( array_map( 'strval', $roles ) );
	}

	/**
	 * All active audiences, name-ordered, for the membership checklist.
	 *
	 * @return list<\stdClass>
	 */
	private static function active_audiences(): array {
		if ( class_exists( '\FreeFormCertificate\Audience\AudienceRepository' ) ) {
			return \FreeFormCertificate\Audience\AudienceRepository::get_all(
				array(
					'status'  => 'active',
					'orderby' => 'name',
					'order'   => 'ASC',
				)
			);
		}
		return array();
	}

	/**
	 * IDs of the active audiences the user currently belongs to.
	 *
	 * @param int $user_id User ID.
	 * @return array<int>
	 */
	private static function user_audience_ids( int $user_id ): array {
		if ( ! class_exists( '\FreeFormCertificate\Audience\AudienceRepository' ) ) {
			return array();
		}
		return array_map(
			static function ( $audience ) {
				return (int) $audience->id;
			},
			\FreeFormCertificate\Audience\AudienceRepository::get_user_audiences( $user_id )
		);
	}

	/**
	 * Save capability field changes
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function save_capability_fields( int $user_id ): void {
		// Verify nonce.
		if ( ! wp_verify_nonce( \FreeFormCertificate\Core\Utils::get_post_string( 'ffc_capabilities_nonce' ), 'ffc_user_capabilities' ) ) {
			return;
		}

		// Only admins can edit.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Iterate exactly the capabilities the form rendered (the catalog),
		// so a cap that has no checkbox is never silently stripped on save.
		// The catalog is asserted to equal UserManager::get_all_capabilities()
		// by CapabilityCatalogTest, so this stays in lockstep with the registry.
		$all_capabilities = \FreeFormCertificate\UserDashboard\CapabilityCatalog::all_slugs();

		// Get user.
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		// Process each capability.
		foreach ( $all_capabilities as $cap ) {
			$field_name = 'ffc_cap_' . $cap;
			$grant      = isset( $_POST[ $field_name ] ) && '1' === $_POST[ $field_name ];

			if ( $grant ) {
				$user->add_cap( $cap, true );
			} else {
				// remove_cap() removes the user-level override, letting the role's.
				// value prevail.  Using add_cap($cap, false) would explicitly deny.
				// the capability and override role-level grants (e.g. admin role).
				$user->remove_cap( $cap );
			}
		}

		// Audience membership — gathered here (inside the nonce-verified
		// scope) and synced below. Absent key = every box unchecked = remove
		// all (active) memberships; sync_audience_membership() guards the
		// "no audiences rendered" case so it can't wipe on an unrelated save.
		$submitted_audiences = array();
		if ( isset( $_POST['ffc_audience'] ) ) {
			$raw_audiences = wp_unslash( $_POST['ffc_audience'] ); // phpcs:ignore WordPress.Security.ValidatedSanitized.InputNotSanitized -- cast to int below.
			if ( is_array( $raw_audiences ) ) {
				$submitted_audiences = array_map( 'intval', $raw_audiences );
			}
		}
		self::sync_audience_membership( $user_id, $submitted_audiences );

		// Log the change.
		if ( class_exists( '\FreeFormCertificate\Core\Debug' ) ) {
			\FreeFormCertificate\Core\Debug::log_user_manager(
				'Admin updated user capabilities',
				array(
					'user_id'      => $user_id,
					'admin_id'     => get_current_user_id(),
					'capabilities' => \FreeFormCertificate\UserDashboard\UserManager::get_user_ffc_capabilities( $user_id ),
				)
			);
		}
	}

	/**
	 * Sync a user's audience memberships against the submitted checklist.
	 *
	 * Diffs the submitted (active, whitelisted) audience IDs against the
	 * user's current active memberships and applies the minimal set of
	 * add/remove operations. Only active audiences participate, so a
	 * membership in an inactive audience — never shown in the checklist —
	 * is preserved.
	 *
	 * Safety: if there are no active audiences (the checklist wouldn't have
	 * rendered), this returns without removing anything, so it can't wipe
	 * memberships when the audience section wasn't part of the form.
	 *
	 * @param int        $user_id            User being edited.
	 * @param array<int> $submitted_audiences Raw submitted audience IDs.
	 * @return void
	 */
	private static function sync_audience_membership( int $user_id, array $submitted_audiences ): void {
		if ( ! class_exists( '\FreeFormCertificate\Audience\AudienceRepository' ) ) {
			return;
		}

		$active = self::active_audiences();
		if ( empty( $active ) ) {
			return;
		}

		$valid_ids = array_map(
			static function ( $audience ) {
				return (int) $audience->id;
			},
			$active
		);

		// Whitelist the submission against the real active set.
		$submitted = array_values( array_intersect( $submitted_audiences, $valid_ids ) );
		$current   = self::user_audience_ids( $user_id );

		$to_add    = array_diff( $submitted, $current );
		$to_remove = array_diff( $current, $submitted );

		foreach ( $to_add as $audience_id ) {
			\FreeFormCertificate\Audience\AudienceRepository::add_member( (int) $audience_id, $user_id );
		}
		foreach ( $to_remove as $audience_id ) {
			\FreeFormCertificate\Audience\AudienceRepository::remove_member( (int) $audience_id, $user_id );
		}

		if ( ( ! empty( $to_add ) || ! empty( $to_remove ) ) && class_exists( '\FreeFormCertificate\Core\Debug' ) ) {
			\FreeFormCertificate\Core\Debug::log_user_manager(
				'Admin updated user audience membership',
				array(
					'user_id'  => $user_id,
					'admin_id' => get_current_user_id(),
					'added'    => array_values( $to_add ),
					'removed'  => array_values( $to_remove ),
				)
			);
		}
	}

	/**
	 * Check if user has any FFC capability
	 *
	 * @param int $user_id User ID.
	 * @return bool True if user has any FFC capability
	 */
	private static function has_any_ffc_capability( int $user_id ): bool {
		return \FreeFormCertificate\UserDashboard\UserManager::has_certificate_access( $user_id ) ||
				\FreeFormCertificate\UserDashboard\UserManager::has_appointment_access( $user_id );
	}
}

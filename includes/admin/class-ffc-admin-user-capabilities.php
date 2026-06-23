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

		// Role assignment is applied out-of-band via AJAX (one role at a time)
		// rather than on the profile-form submit. This keeps it isolated from
		// WordPress core's `set_role` and from any third-party multi-role
		// plugin that also writes roles on the same submit — avoiding a
		// last-writer-wins conflict over role membership.
		add_action( 'wp_ajax_ffc_toggle_user_role', array( __CLASS__, 'ajax_toggle_user_role' ) );

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
		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();
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

		// The target user: own profile (profile.php) or another user
		// (user-edit.php?user_id=N). Mirrors how core resolves it.
		$target_id = 'user-edit.php' === $hook_suffix
			? ( isset( $_GET['user_id'] ) ? absint( wp_unslash( $_GET['user_id'] ) ) : 0 ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen resolution, mirrors core user-edit.php.
			: get_current_user_id();

		$presets  = self::ffc_preset_roles();
		$assigned = array();
		$user     = $target_id > 0 ? get_userdata( $target_id ) : false;
		if ( $user instanceof \WP_User ) {
			$assigned = array_values( array_intersect( (array) $user->roles, array_keys( $presets ) ) );
		}

		$role_caps = array();
		foreach ( $presets as $slug => $def ) {
			$role_caps[ $slug ] = $def['caps'];
		}

		wp_localize_script(
			'ffc-user-capabilities',
			'ffcUserPerms',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'ffc_toggle_user_role' ),
				'userId'   => $target_id,
				'roleCaps' => $role_caps,
				'assigned' => $assigned,
				'i18n'     => array(
					'error' => __( 'Could not update the role. Please reload and try again.', 'ffcertificate' ),
					'user'  => __( 'User', 'ffcertificate' ),
					'role'  => __( 'Role', 'ffcertificate' ),
					'none'  => __( '—', 'ffcertificate' ),
				),
			)
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

		// Roles — assignable FFC preset chips (applied immediately via AJAX).
		self::render_role_presets( $user );

		// Audiences — editable membership checklist.
		self::render_audience_membership( $user );

		echo '</div>';
	}

	/**
	 * Render the FFC role chips as assignable presets.
	 *
	 * Each chip is a preset that grants a bundle of capabilities; toggling it
	 * assigns/removes the role for *this* user (immediately, via AJAX — see
	 * {@see self::ajax_toggle_user_role()}) and the capability grid below
	 * recomputes live, marking role-granted caps as "Role". The role
	 * *definition* (which caps a role contains) is global and read-only here.
	 *
	 * Non-FFC roles the user holds (e.g. `subscriber`) are shown muted, for
	 * context — they're managed by WordPress / other plugins, never here.
	 *
	 * @param \WP_User $user User being edited.
	 * @return void
	 */
	private static function render_role_presets( \WP_User $user ): void {
		$presets    = self::ffc_preset_roles();
		$user_roles = (array) $user->roles;

		echo '<div class="ffc-cap-context-item ffc-cap-roles">';
		echo '<span class="ffc-cap-context-label">' . esc_html__( 'Roles (presets)', 'ffcertificate' ) . '</span>';

		if ( empty( $presets ) ) {
			echo '<span class="ffc-cap-context-val"><em>' . esc_html__( 'No FFC roles available.', 'ffcertificate' ) . '</em></span>';
		} else {
			echo '<div class="ffc-cap-role-chips" role="group" aria-label="' . esc_attr__( 'FFC roles', 'ffcertificate' ) . '">';
			foreach ( $presets as $slug => $def ) {
				$assigned = in_array( $slug, $user_roles, true );
				printf(
					'<button type="button" class="ffc-cap-role%1$s" data-ffc-role="%2$s" aria-pressed="%3$s">'
						. '<span class="ffc-cap-role-mark" aria-hidden="true"></span>'
						. '<span class="ffc-cap-role-nm">%4$s</span>'
						. '<span class="ffc-cap-role-ct">%5$s</span>'
						. '</button>',
					$assigned ? ' is-on' : '',
					esc_attr( $slug ),
					$assigned ? 'true' : 'false',
					esc_html( $def['label'] ),
					esc_html(
						sprintf(
							/* translators: %d: number of capabilities the role grants */
							_n( '%d cap', '%d caps', count( $def['caps'] ), 'ffcertificate' ),
							count( $def['caps'] )
						)
					)
				);
			}
			echo '</div>';

			// Non-FFC roles shown read-only for context.
			$other = array_values( array_diff( $user_roles, array_keys( $presets ) ) );
			if ( ! empty( $other ) ) {
				echo '<span class="ffc-cap-role-other">' . esc_html__( 'Other roles:', 'ffcertificate' ) . ' ';
				foreach ( $other as $role_slug ) {
					echo '<span class="ffc-cap-chip ffc-cap-chip--muted">' . esc_html( self::role_label( (string) $role_slug ) ) . '</span> ';
				}
				echo '</span>';
			}
		}

		echo '<span class="ffc-cap-context-hint">' . esc_html__( 'Hover a role to see what it grants. Assigning a role applies immediately and affects only this user; it does not send an e-mail.', 'ffcertificate' ) . '</span>';
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
		$user_caps  = $user->caps;
		$role_union = self::user_role_caps_union( $user );

		$prev_level = null;
		foreach ( \FreeFormCertificate\UserDashboard\CapabilityCatalog::groups() as $group ) {
			$is_admin = 'admin' === $group['level'];
			$total    = count( $group['caps'] );

			// Section divider between the self-service and administration tiers.
			if ( $group['level'] !== $prev_level ) {
				echo '<h3 class="ffc-cap-section">' . esc_html( \FreeFormCertificate\UserDashboard\CapabilityCatalog::level_section_label( (string) $group['level'] ) ) . '</h3>';
				$prev_level = $group['level'];
			}

			// Build the rows first so the header can show a live granted count.
			$rows    = '';
			$granted = 0;
			foreach ( $group['caps'] as $slug => $meta ) {
				$granted_user = ! empty( $user_caps[ $slug ] );
				// Role precedence: a cap a role grants is locked/"Role" even if a
				// redundant per-user override also exists (matches the live JS
				// recompute when chips are toggled).
				$by_role = in_array( (string) $slug, $role_union, true );
				$origin  = $by_role ? 'role' : ( $granted_user ? 'user' : 'none' );
				if ( 'none' !== $origin ) {
					++$granted;
				}
				$rows .= self::render_cap_row( (string) $slug, $meta, (string) $group['key'], $granted_user, $origin );
			}

			// Every group starts collapsed for easier navigation; the live
			// search auto-expands the groups that still have hits.
			printf(
				'<section class="ffc-cap-group is-collapsed" data-ffc-group="%1$s">',
				esc_attr( (string) $group['key'] )
			);

			// Header.
			echo '<button type="button" class="ffc-cap-group-h" aria-expanded="false">';
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
		$label         = isset( $meta['label'] ) ? (string) $meta['label'] : $slug;
		$desc          = isset( $meta['description'] ) ? (string) $meta['description'] : '';
		$surface_badge = \FreeFormCertificate\UserDashboard\CapabilityCatalog::surface_badge_html( $meta );

		// Role-granted caps render ON but disabled: a disabled checkbox is not
		// submitted, so the profile-form save never writes a redundant per-user
		// override for something a role already grants — to change it, toggle
		// the role. User-level grants stay enabled and submittable.
		$by_role = 'role' === $origin;
		$toggle  = \FreeFormCertificate\Admin\AdminUI::get_toggle(
			array(
				'name'        => 'ffc_cap_' . $slug,
				'id'          => 'ffc_cap_' . $slug,
				'checked'     => $by_role ? true : $checked,
				'disabled'    => $by_role,
				'label'       => '',
				'class'       => $by_role ? 'ffc-cap-toggle--byrole' : '',
				'input_class' => 'ffc-cap-checkbox',
				'data'        => array( 'ffc-cap-group' => $group_key ),
			)
		);

		return sprintf(
			'<div class="ffc-cap-row" data-ffc-cap-name="%1$s" data-ffc-cap-slug="%2$s" data-ffc-user-granted="%9$s">'
				. '<div class="ffc-cap-row-toggle">%3$s</div>'
				. '<div class="ffc-cap-row-text">'
				. '<span class="ffc-cap-row-name">%4$s%10$s</span><span class="ffc-cap-role-tag" data-ffc-role-tag></span>'
				. '<span class="ffc-cap-row-desc">%5$s</span>'
				. '<span class="ffc-cap-slug">%2$s<button type="button" class="ffc-cap-copy" data-ffc-copy="%2$s" aria-label="%6$s" title="%6$s">⧉</button></span>'
				. '</div>'
				. '<span class="ffc-cap-origin ffc-cap-origin--%7$s" data-ffc-origin>%8$s</span>'
				. '</div>',
			esc_attr( strtolower( $label . ' ' . $slug ) ),
			esc_attr( $slug ),
			$toggle, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AdminUI::get_toggle() returns pre-escaped markup.
			esc_html( $label ),
			esc_html( $desc ),
			esc_attr__( 'Copy slug', 'ffcertificate' ),
			esc_attr( $origin ),
			esc_html( self::origin_label( $origin ) ),
			$checked ? '1' : '0',
			$surface_badge // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped by CapabilityCatalog::surface_badge_html().
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
	 * Display label for a single role slug (falls back to the slug).
	 *
	 * @param string $slug Role slug.
	 * @return string
	 */
	private static function role_label( string $slug ): string {
		if ( function_exists( 'wp_roles' ) ) {
			$names = wp_roles()->get_names();
			if ( isset( $names[ $slug ] ) ) {
				return (string) $names[ $slug ];
			}
		}
		return $slug;
	}

	/**
	 * The FFC "preset" roles: every registered role that grants at least one
	 * cataloged FFC capability and does NOT grant `manage_options` (admins are
	 * excluded — the whole panel is hidden for them anyway).
	 *
	 * Auto-discovered from `wp_roles()` so newly-registered FFC roles appear
	 * without code changes here. Each role is reduced to the subset of caps
	 * that live in the {@see CapabilityCatalog} — that's what the UI can
	 * illuminate; core/other caps a role may carry are irrelevant here.
	 *
	 * @return array<string, array{label: string, caps: list<string>}>
	 */
	private static function ffc_preset_roles(): array {
		if ( ! function_exists( 'wp_roles' ) ) {
			return array();
		}
		$catalog = \FreeFormCertificate\UserDashboard\CapabilityCatalog::all_slugs();
		$names   = wp_roles()->get_names();
		$out     = array();

		foreach ( wp_roles()->roles as $slug => $role ) {
			$caps = isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ? $role['capabilities'] : array();
			// Skip admin-equivalent roles.
			if ( ! empty( $caps['manage_options'] ) ) {
				continue;
			}
			$granted = array();
			foreach ( $catalog as $cap ) {
				if ( ! empty( $caps[ $cap ] ) ) {
					$granted[] = $cap;
				}
			}
			if ( empty( $granted ) ) {
				continue;
			}
			$out[ (string) $slug ] = array(
				'label' => isset( $names[ $slug ] ) ? (string) $names[ $slug ] : (string) $slug,
				'caps'  => $granted,
			);
		}

		return $out;
	}

	/**
	 * Union of cataloged FFC caps granted by the roles the user currently
	 * holds (intersected with the preset role map, so it matches exactly what
	 * the client-side recompute uses).
	 *
	 * @param \WP_User $user User being edited.
	 * @return list<string>
	 */
	private static function user_role_caps_union( \WP_User $user ): array {
		$presets = self::ffc_preset_roles();
		$union   = array();
		foreach ( (array) $user->roles as $slug ) {
			if ( isset( $presets[ $slug ] ) ) {
				foreach ( $presets[ $slug ]['caps'] as $cap ) {
					$union[ $cap ] = true;
				}
			}
		}
		return array_keys( $union );
	}

	/**
	 * All active audiences, name-ordered, for the membership checklist.
	 *
	 * @return list<\stdClass>
	 */
	private static function active_audiences(): array {
		if ( class_exists( '\FreeFormCertificate\Audience\AudienceReader' ) ) {
			return \FreeFormCertificate\Audience\AudienceReader::get_all(
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
		if ( ! class_exists( '\FreeFormCertificate\Audience\AudienceReader' ) ) {
			return array();
		}
		return array_map(
			static function ( $audience ) {
				return (int) $audience->id;
			},
			\FreeFormCertificate\Audience\AudienceReader::get_user_audiences( $user_id )
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
		if ( ! wp_verify_nonce( \FreeFormCertificate\Core\RequestInput::get_post_string( 'ffc_capabilities_nonce' ), 'ffc_user_capabilities' ) ) {
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
					'capabilities' => \FreeFormCertificate\UserDashboard\CapabilityManager::get_user_ffc_capabilities( $user_id ),
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
		if ( ! class_exists( '\FreeFormCertificate\Audience\AudienceReader' ) ) {
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
			\FreeFormCertificate\Audience\AudienceWriter::add_member( (int) $audience_id, $user_id );
		}
		foreach ( $to_remove as $audience_id ) {
			\FreeFormCertificate\Audience\AudienceWriter::remove_member( (int) $audience_id, $user_id );
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
	 * AJAX: assign or remove a single FFC preset role for a user.
	 *
	 * Deliberately isolated from the profile-form submit so it never races
	 * WordPress core's `set_role` or a third-party multi-role plugin that
	 * also writes roles on that submit. Restricted to {@see
	 * self::ffc_preset_roles()} so it can't grant arbitrary roles, refuses to
	 * touch `manage_options` users, and is cap- + nonce-gated. Audited.
	 *
	 * @return void
	 */
	public static function ajax_toggle_user_role(): void {
		check_ajax_referer( 'ffc_toggle_user_role', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$role    = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : '';
		$assign  = isset( $_POST['assign'] ) && '1' === (string) wp_unslash( $_POST['assign'] );

		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user instanceof \WP_User ) {
			wp_send_json_error( array( 'message' => 'user_not_found' ), 404 );
		}

		// Never edit administrators through this panel.
		if ( user_can( $user_id, 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'cannot_edit_admin' ), 409 );
		}

		// Only FFC preset roles may be assigned here.
		$presets = self::ffc_preset_roles();
		if ( ! isset( $presets[ $role ] ) ) {
			wp_send_json_error( array( 'message' => 'role_not_assignable' ), 400 );
		}

		if ( $assign ) {
			$user->add_role( $role );
		} else {
			$user->remove_role( $role );
		}

		if ( class_exists( '\FreeFormCertificate\Core\Debug' ) ) {
			\FreeFormCertificate\Core\Debug::log_user_manager(
				'Admin updated user role assignment',
				array(
					'user_id'  => $user_id,
					'admin_id' => get_current_user_id(),
					'role'     => $role,
					'assigned' => $assign ? 1 : 0,
				)
			);
		}

		wp_send_json_success(
			array(
				'role'     => $role,
				'assigned' => $assign,
				'roles'    => array_values( (array) $user->roles ),
			)
		);
	}

	/**
	 * Check if user has any FFC capability
	 *
	 * @param int $user_id User ID.
	 * @return bool True if user has any FFC capability
	 */
	private static function has_any_ffc_capability( int $user_id ): bool {
		return \FreeFormCertificate\UserDashboard\CapabilityManager::has_certificate_access( $user_id ) ||
				\FreeFormCertificate\UserDashboard\CapabilityManager::has_appointment_access( $user_id );
	}
}

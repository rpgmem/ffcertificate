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
		// ffc-common.css carries the .ffc-toggle switch styles used by the
		// capability fields (render_toggle); it isn't otherwise loaded here.
		wp_enqueue_style(
			'ffc-common',
			FFC_PLUGIN_URL . "assets/css/ffc-common{$s}.css",
			array(),
			FFC_VERSION
		);
		wp_enqueue_script(
			'ffc-user-capabilities',
			FFC_PLUGIN_URL . "assets/js/ffc-user-capabilities{$s}.js",
			array( 'jquery' ),
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

		// Get current capabilities.
		$capabilities = \FreeFormCertificate\UserDashboard\UserManager::get_user_ffc_capabilities( $user->ID );

		// Add nonce.
		wp_nonce_field( 'ffc_user_capabilities', 'ffc_capabilities_nonce' );

		?>
		<h2><?php esc_html_e( 'FFC Permissions', 'ffcertificate' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Manage which FFC features this user can access. Capabilities are checked in addition to role permissions.', 'ffcertificate' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tbody>
				<!-- Certificate Capabilities -->
				<tr>
					<th scope="row"><?php esc_html_e( 'Certificate Permissions', 'ffcertificate' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text">
								<span><?php esc_html_e( 'Certificate Permissions', 'ffcertificate' ); ?></span>
							</legend>

							<?php
							\FreeFormCertificate\Admin\AdminUI::render_toggle(
								array(
									'name'    => 'ffc_cap_ffc_view_own_certificates',
									'id'      => 'ffc_cap_ffc_view_own_certificates',
									'checked' => ! empty( $capabilities['ffc_view_own_certificates'] ),
									'label'   => __( 'View own certificates', 'ffcertificate' ),
								)
							);
							?>
							<br>

							<?php
							\FreeFormCertificate\Admin\AdminUI::render_toggle(
								array(
									'name'    => 'ffc_cap_ffc_download_own_certificates',
									'id'      => 'ffc_cap_ffc_download_own_certificates',
									'checked' => ! empty( $capabilities['ffc_download_own_certificates'] ),
									'label'   => __( 'Download own certificates', 'ffcertificate' ),
								)
							);
							?>
							<br>

							<?php
							\FreeFormCertificate\Admin\AdminUI::render_toggle(
								array(
									'name'    => 'ffc_cap_ffc_view_certificate_history',
									'id'      => 'ffc_cap_ffc_view_certificate_history',
									'checked' => ! empty( $capabilities['ffc_view_certificate_history'] ),
									'label'   => __( 'View certificate history', 'ffcertificate' ),
								)
							);
							?>

							<p class="description">
								<?php esc_html_e( 'Allow access to certificate-related features in the user dashboard.', 'ffcertificate' ); ?>
							</p>
						</fieldset>
					</td>
				</tr>

				<!-- Appointment Capabilities -->
				<tr>
					<th scope="row"><?php esc_html_e( 'Appointment Permissions', 'ffcertificate' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text">
								<span><?php esc_html_e( 'Appointment Permissions', 'ffcertificate' ); ?></span>
							</legend>

							<?php
							\FreeFormCertificate\Admin\AdminUI::render_toggle(
								array(
									'name'    => 'ffc_cap_ffc_book_appointments',
									'id'      => 'ffc_cap_ffc_book_appointments',
									'checked' => ! empty( $capabilities['ffc_book_appointments'] ),
									'label'   => __( 'Book appointments', 'ffcertificate' ),
								)
							);
							?>
							<br>

							<?php
							\FreeFormCertificate\Admin\AdminUI::render_toggle(
								array(
									'name'    => 'ffc_cap_ffc_view_self_scheduling',
									'id'      => 'ffc_cap_ffc_view_self_scheduling',
									'checked' => ! empty( $capabilities['ffc_view_self_scheduling'] ),
									'label'   => __( 'View own appointments', 'ffcertificate' ),
								)
							);
							?>
							<br>

							<?php
							\FreeFormCertificate\Admin\AdminUI::render_toggle(
								array(
									'name'    => 'ffc_cap_ffc_cancel_own_appointments',
									'id'      => 'ffc_cap_ffc_cancel_own_appointments',
									'checked' => ! empty( $capabilities['ffc_cancel_own_appointments'] ),
									'label'   => __( 'Cancel own appointments', 'ffcertificate' ),
								)
							);
							?>
							<br>

							<?php
							\FreeFormCertificate\Admin\AdminUI::render_toggle(
								array(
									'name'    => 'ffc_cap_ffc_scheduling_bypass',
									'id'      => 'ffc_cap_ffc_scheduling_bypass',
									'checked' => ! empty( $capabilities['ffc_scheduling_bypass'] ),
									'label'   => __( 'Scheduling bypass (admin-level access)', 'ffcertificate' ),
								)
							);
							?>
							<span class="description"><?php esc_html_e( 'Allows viewing private calendars, booking past dates, out-of-hours, and blocked dates.', 'ffcertificate' ); ?></span>

							<p class="description">
								<?php esc_html_e( 'Allow access to appointment-related features. Calendar-specific settings also apply.', 'ffcertificate' ); ?>
							</p>
						</fieldset>
					</td>
				</tr>

				<!-- Audience Capabilities -->
				<tr>
					<th scope="row"><?php esc_html_e( 'Audience Permissions', 'ffcertificate' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text">
								<span><?php esc_html_e( 'Audience Permissions', 'ffcertificate' ); ?></span>
							</legend>

							<?php
							\FreeFormCertificate\Admin\AdminUI::render_toggle(
								array(
									'name'    => 'ffc_cap_ffc_view_audience_bookings',
									'id'      => 'ffc_cap_ffc_view_audience_bookings',
									'checked' => ! empty( $capabilities['ffc_view_audience_bookings'] ),
									'label'   => __( 'View audience bookings', 'ffcertificate' ),
								)
							);
							?>
							<span class="description"><?php esc_html_e( 'Allows viewing group/audience bookings in the dashboard.', 'ffcertificate' ); ?></span>

							<p class="description">
								<?php esc_html_e( 'Allow access to audience/group scheduling features.', 'ffcertificate' ); ?>
							</p>
						</fieldset>
					</td>
				</tr>

				<!-- Admin-level Capabilities -->
				<tr>
					<th scope="row"><?php esc_html_e( 'Admin Permissions', 'ffcertificate' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text">
								<span><?php esc_html_e( 'Admin Permissions', 'ffcertificate' ); ?></span>
							</legend>

							<?php
							\FreeFormCertificate\Admin\AdminUI::render_toggle(
								array(
									'name'    => 'ffc_cap_ffc_manage_reregistration',
									'id'      => 'ffc_cap_ffc_manage_reregistration',
									'checked' => ! empty( $capabilities['ffc_manage_reregistration'] ),
									'label'   => __( 'Manage reregistration campaigns', 'ffcertificate' ),
								)
							);
							?>
							<span class="description"><?php esc_html_e( 'Access the Reregistration admin page.', 'ffcertificate' ); ?></span>
						</fieldset>
					</td>
				</tr>

				<!-- Submission editing -->
				<tr>
					<th scope="row"><?php esc_html_e( 'Submission editing', 'ffcertificate' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text">
								<span><?php esc_html_e( 'Submission editing', 'ffcertificate' ); ?></span>
							</legend>

							<?php
							\FreeFormCertificate\Admin\AdminUI::render_toggle(
								array(
									'name'    => 'ffc_cap_ffc_certificate_update',
									'id'      => 'ffc_cap_ffc_certificate_update',
									'checked' => ! empty( $capabilities['ffc_certificate_update'] ),
									'label'   => __( 'Edit submission data on issued certificates', 'ffcertificate' ),
								)
							);
							?>
							<span class="description"><?php esc_html_e( '(Lets the user fix typos / corrections on already-emitted certificates without holding manage_options.)', 'ffcertificate' ); ?></span>
						</fieldset>
					</td>
				</tr>

				<!-- Quick Actions -->
				<tr>
					<th scope="row"><?php esc_html_e( 'Quick Actions', 'ffcertificate' ); ?></th>
					<td>
						<button type="button" class="button" id="ffc-grant-all-caps">
							<?php esc_html_e( 'Grant All', 'ffcertificate' ); ?>
						</button>
						<button type="button" class="button" id="ffc-revoke-all-caps">
							<?php esc_html_e( 'Revoke All', 'ffcertificate' ); ?>
						</button>
						<button type="button" class="button" id="ffc-grant-certificates">
							<?php esc_html_e( 'Grant Certificates Only', 'ffcertificate' ); ?>
						</button>
						<button type="button" class="button" id="ffc-grant-appointments">
							<?php esc_html_e( 'Grant Appointments Only', 'ffcertificate' ); ?>
						</button>
						<!-- Scripts in ffc-user-capabilities.js -->
					</td>
				</tr>
			</tbody>
		</table>
		<?php
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

		// Use centralized capability list from UserManager.
		$all_capabilities = \FreeFormCertificate\UserDashboard\UserManager::get_all_capabilities();

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

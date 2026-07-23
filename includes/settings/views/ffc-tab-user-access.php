<?php
/**
 * User Access Settings View
 *
 * @package FFC
 * @since 3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables scoped to this file

// Get current settings.
$ffcertificate_current_settings = get_option( 'ffc_user_access_settings', array() );

// Defaults.
$ffcertificate_defaults = array(
	'block_wp_admin'    => false,
	'blocked_roles'     => array( 'ffc_end_user' ),
	'redirect_url'      => home_url( '/dashboard' ),
	'redirect_message'  => __( 'You were redirected from the admin panel. Use this dashboard to access your certificates.', 'ffcertificate' ),
	'allow_admin_bar'   => false,
	'bypass_for_admins' => true,
);

$ffcertificate_settings = wp_parse_args( $ffcertificate_current_settings, $ffcertificate_defaults );

// Get all WordPress roles.
$ffcertificate_wp_roles        = wp_roles();
$ffcertificate_available_roles = $ffcertificate_wp_roles->get_names();

// Get dashboard page URL.
$ffcertificate_dashboard_page_id = get_option( 'ffc_dashboard_page_id' );
$ffcertificate_dashboard_url     = $ffcertificate_dashboard_page_id ? get_permalink( $ffcertificate_dashboard_page_id ) : home_url( '/dashboard' );
?>

<div class="wrap ffc-settings-page">
	<form method="post" action="">
		<?php wp_nonce_field( 'ffc_user_access_settings', 'ffc_user_access_nonce' ); ?>

		<!-- wp-admin Blocking -->
		<div class="card">
			<h2 class="ffc-icon-lock"><?php esc_html_e( 'WP-Admin Access Control', 'ffcertificate' ); ?></h2>
			<table class="form-table" role="presentation"><tbody>
				<tr>
					<th scope="row">
						<label for="block_wp_admin">
							<?php esc_html_e( 'Block WP-Admin Access', 'ffcertificate' ); ?>
						</label>
					</th>
					<td>
						<?php
						\FreeFormCertificate\Admin\AdminUI::render_toggle(
							array(
								'name'    => 'block_wp_admin',
								'id'      => 'block_wp_admin',
								'checked' => (bool) $ffcertificate_settings['block_wp_admin'],
								'label'   => __( 'Prevent selected roles from accessing /wp-admin', 'ffcertificate' ),
								'data'    => array( 'ffc-autosave-key' => 'user_access_block_wp_admin' ),
							)
						);
						?>
						<p class="description">
							<?php esc_html_e( 'When enabled, users with selected roles will be redirected when trying to access the WordPress admin panel.', 'ffcertificate' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="blocked_roles">
							<?php esc_html_e( 'Blocked Roles', 'ffcertificate' ); ?>
						</label>
					</th>
					<td>
						<?php
						// Partition the site roles so the list reads as groups
						// instead of one flat wall of ~30 checkboxes: end-user
						// (the one you normally block), core WordPress, third-party,
						// and the FFC administrative ladder (rarely blocked — those
						// roles operate through wp-admin, so they are tucked behind a
						// disclosure with a caveat).
						$ffc_core_roles    = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );
						$ffc_managed_slugs = class_exists( '\FreeFormCertificate\UserDashboard\RoleRegistrar' )
							? array_keys( \FreeFormCertificate\UserDashboard\RoleRegistrar::ffc_managed_role_labels() )
							: array();
						$ffc_blocked       = (array) $ffcertificate_settings['blocked_roles'];

						$ffc_group_enduser = array();
						$ffc_group_core    = array();
						$ffc_group_admin   = array();
						$ffc_group_other   = array();
						foreach ( $ffcertificate_available_roles as $ffc_slug => $ffc_name ) {
							if ( 'ffc_end_user' === $ffc_slug ) {
								$ffc_group_enduser[ $ffc_slug ] = $ffc_name;
							} elseif ( in_array( $ffc_slug, $ffc_managed_slugs, true ) ) {
								$ffc_group_admin[ $ffc_slug ] = $ffc_name;
							} elseif ( in_array( $ffc_slug, $ffc_core_roles, true ) ) {
								$ffc_group_core[ $ffc_slug ] = $ffc_name;
							} else {
								$ffc_group_other[ $ffc_slug ] = $ffc_name;
							}
						}

						// Expand the admin disclosure when one of those roles is
						// already blocked, so a checked box is never hidden.
						$ffc_admin_has_blocked = (bool) array_intersect( array_keys( $ffc_group_admin ), $ffc_blocked );

						$ffc_render_role_cb = function ( $slug, $name ) use ( $ffc_blocked ) {
							?>
							<label class="ffc-checkbox-label">
								<input type="checkbox"
										name="blocked_roles[]"
										value="<?php echo esc_attr( (string) $slug ); ?>"
										<?php checked( in_array( $slug, $ffc_blocked, true ) ); ?>>
								<span><?php echo esc_html( translate_user_role( (string) $name ) ); ?></span>
								<?php if ( 'ffc_end_user' === $slug ) : ?>
									<em>(<?php esc_html_e( 'recommended', 'ffcertificate' ); ?>)</em>
								<?php endif; ?>
							</label>
							<?php
						};
						?>
						<fieldset class="ffc-blocked-roles">
							<?php if ( ! empty( $ffc_group_enduser ) ) : ?>
								<div class="ffc-blocked-roles__group ffc-blocked-roles__group--recommended">
									<p class="ffc-blocked-roles__legend"><?php esc_html_e( 'FFC — end users', 'ffcertificate' ); ?></p>
									<div class="ffc-blocked-roles__grid">
										<?php
										foreach ( $ffc_group_enduser as $ffc_slug => $ffc_name ) {
											$ffc_render_role_cb( $ffc_slug, $ffc_name );
										}
										?>
									</div>
								</div>
							<?php endif; ?>

							<?php if ( ! empty( $ffc_group_core ) ) : ?>
								<div class="ffc-blocked-roles__group">
									<p class="ffc-blocked-roles__legend"><?php esc_html_e( 'WordPress roles', 'ffcertificate' ); ?></p>
									<div class="ffc-blocked-roles__grid">
										<?php
										foreach ( $ffc_group_core as $ffc_slug => $ffc_name ) {
											$ffc_render_role_cb( $ffc_slug, $ffc_name );
										}
										?>
									</div>
								</div>
							<?php endif; ?>

							<?php if ( ! empty( $ffc_group_other ) ) : ?>
								<div class="ffc-blocked-roles__group">
									<p class="ffc-blocked-roles__legend"><?php esc_html_e( 'Other roles', 'ffcertificate' ); ?></p>
									<div class="ffc-blocked-roles__grid">
										<?php
										foreach ( $ffc_group_other as $ffc_slug => $ffc_name ) {
											$ffc_render_role_cb( $ffc_slug, $ffc_name );
										}
										?>
									</div>
								</div>
							<?php endif; ?>

							<?php if ( ! empty( $ffc_group_admin ) ) : ?>
								<details class="ffc-blocked-roles__group ffc-blocked-roles__group--admin"<?php echo $ffc_admin_has_blocked ? ' open' : ''; ?>>
									<summary class="ffc-blocked-roles__legend">
										<?php
										printf(
											/* translators: %d: number of FFC administrative roles */
											esc_html__( 'FFC administrative roles (%d) — rarely blocked', 'ffcertificate' ),
											count( $ffc_group_admin )
										);
										?>
									</summary>
									<p class="description ffc-blocked-roles__hint">
										<?php esc_html_e( 'These roles operate through wp-admin. Block one only if its users should be redirected away from the dashboard — blocking an administrative role locks it out of the screens it is meant to use.', 'ffcertificate' ); ?>
									</p>
									<div class="ffc-blocked-roles__grid">
										<?php
										foreach ( $ffc_group_admin as $ffc_slug => $ffc_name ) {
											$ffc_render_role_cb( $ffc_slug, $ffc_name );
										}
										?>
									</div>
								</details>
							<?php endif; ?>
						</fieldset>
						<p class="description">
							<?php
							printf(
								/* translators: %d: number of roles currently blocked */
								esc_html__( 'Select which roles should be blocked from accessing wp-admin. Currently blocked: %d.', 'ffcertificate' ),
								count( $ffc_blocked )
							);
							?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="bypass_for_admins">
							<?php esc_html_e( 'Bypass for Administrators', 'ffcertificate' ); ?>
						</label>
					</th>
					<td>
						<?php
						\FreeFormCertificate\Admin\AdminUI::render_toggle(
							array(
								'name'    => 'bypass_for_admins',
								'id'      => 'bypass_for_admins',
								'checked' => (bool) $ffcertificate_settings['bypass_for_admins'],
								'label'   => __( 'Allow administrators to bypass the block (recommended)', 'ffcertificate' ),
								'data'    => array( 'ffc-autosave-key' => 'user_access_bypass_for_admins' ),
							)
						);
						?>
						<p class="description">
							<?php esc_html_e( 'Even if an admin has a blocked role, they can still access wp-admin.', 'ffcertificate' ); ?>
						</p>
					</td>
				</tr>
			</tbody></table>
		</div>

		<!-- Redirect Settings -->
		<div class="card">
			<h2 class="ffc-icon-link"><?php esc_html_e( 'Redirect Settings', 'ffcertificate' ); ?></h2>
			<table class="form-table" role="presentation"><tbody>
				<tr>
					<th scope="row">
						<label for="redirect_url">
							<?php esc_html_e( 'Redirect URL', 'ffcertificate' ); ?>
						</label>
					</th>
					<td>
						<input type="url"
								name="redirect_url"
								id="redirect_url"
								value="<?php echo esc_attr( $ffcertificate_settings['redirect_url'] ); ?>"
								class="regular-text"
								placeholder="<?php echo esc_attr( $ffcertificate_dashboard_url ); ?>">
						<p class="description">
							<?php
							printf(
								/* translators: %s: Dashboard page URL */
								esc_html__( 'Where to redirect blocked users. Default: %s', 'ffcertificate' ),
								'<code>' . esc_html( $ffcertificate_dashboard_url ) . '</code>'
							);
							?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="redirect_message">
							<?php esc_html_e( 'Redirect Message', 'ffcertificate' ); ?>
						</label>
					</th>
					<td>
						<textarea name="redirect_message"
									id="redirect_message"
									rows="3"
									class="large-text"><?php echo esc_textarea( $ffcertificate_settings['redirect_message'] ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Message shown to users after being redirected (appears on the dashboard page).', 'ffcertificate' ); ?>
						</p>
					</td>
				</tr>
			</tbody></table>
		</div>

		<!-- Admin Bar -->
		<div class="card">
			<h2 class="ffc-icon-settings"><?php esc_html_e( 'Admin Bar', 'ffcertificate' ); ?></h2>
			<table class="form-table" role="presentation"><tbody>
				<tr>
					<th scope="row">
						<label for="allow_admin_bar">
							<?php esc_html_e( 'Show Admin Bar', 'ffcertificate' ); ?>
						</label>
					</th>
					<td>
						<?php
						\FreeFormCertificate\Admin\AdminUI::render_toggle(
							array(
								'name'    => 'allow_admin_bar',
								'id'      => 'allow_admin_bar',
								'checked' => (bool) $ffcertificate_settings['allow_admin_bar'],
								'label'   => __( 'Show admin bar on frontend for blocked roles', 'ffcertificate' ),
								'data'    => array( 'ffc-autosave-key' => 'user_access_allow_admin_bar' ),
							)
						);
						?>
						<p class="description">
							<?php esc_html_e( 'If unchecked, the WordPress admin bar will be hidden for blocked roles.', 'ffcertificate' ); ?>
						</p>
					</td>
				</tr>
			</tbody></table>
		</div>

		<!-- Info Box -->
		<div class="card ffc-info-card">
			<h2 class="ffc-icon-info"><?php esc_html_e( 'Information', 'ffcertificate' ); ?></h2>
			<p>
				<?php esc_html_e( 'The "FFC End User" role is automatically assigned to users who submit forms with CPF/RF.', 'ffcertificate' ); ?>
			</p>
			<p>
				<?php
				printf(
					/* translators: %s: Shortcode */
					esc_html__( 'Users can access their certificates via the dashboard page using the %s shortcode.', 'ffcertificate' ),
					'<code>[user_dashboard_personal]</code>'
				);
				?>
			</p>
			<p>
				<?php
				if ( $ffcertificate_dashboard_page_id ) {
					printf(
						/* translators: %s: Dashboard page URL */
						esc_html__( 'Dashboard page: %s', 'ffcertificate' ),
						'<a href="' . esc_url( $ffcertificate_dashboard_url ) . '" target="_blank">' . esc_html( $ffcertificate_dashboard_url ) . '</a>'
					);
				} else {
					printf(
						/* translators: %s: Dashboard page slug */
						esc_html__( 'Dashboard page will be created at: %s (activate the plugin to create it)', 'ffcertificate' ),
						'<code>' . esc_html( home_url( '/dashboard' ) ) . '</code>'
					);
				}
				?>
			</p>
		</div>

		<p class="submit">
			<button type="submit" name="save_settings" class="button button-primary">
				<?php esc_html_e( 'Save Changes', 'ffcertificate' ); ?>
			</button>
		</p>
	</form>

	<?php
	// Role → capability editor (global role definitions). Rendered outside
	// the settings <form> above: it persists per-toggle via its own AJAX
	// endpoint, independent of the User Access options save.
	\FreeFormCertificate\Admin\RoleCapabilityEditor::render();
	?>
</div>

<?php
/**
 * Template: Audience admin settings — Audience tab (manage links + visibility settings + badge color).
 * In-scope: $display_mode, $visibility_message, $scheduling_message,
 * $multiple_audiences_color, $this->menu_slug.
 *
 * Extracted verbatim from the matching AudienceAdminSettings method
 * (rpgmem/ffcertificate coverage hygiene); markup byte-identical. The
 * renderer prepares the locals and includes this file (method locals,
 * $this, and self:: siblings resolve in the including method scope).
 *
 * @package FreeFormCertificate\Audience
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
		<div class="card">
			<h2><?php esc_html_e( 'Audience Scheduling Settings', 'ffcertificate' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Settings specific to the audience/group booking system.', 'ffcertificate' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Manage', 'ffcertificate' ); ?></th>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->menu_slug . '-calendars' ) ); ?>" class="button">
								<?php esc_html_e( 'Audience Calendars', 'ffcertificate' ); ?>
							</a>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->menu_slug . '-environments' ) ); ?>" class="button">
								<?php esc_html_e( 'Environments', 'ffcertificate' ); ?>
							</a>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->menu_slug . '-audiences' ) ); ?>" class="button">
								<?php esc_html_e( 'Audiences', 'ffcertificate' ); ?>
							</a>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- Visibility Settings -->
		<form method="post" action="">
			<?php wp_nonce_field( 'ffc_aud_visibility_settings', 'ffc_aud_visibility_nonce' ); ?>
			<input type="hidden" name="ffc_action" value="save_aud_visibility_settings">

			<div class="card">
				<h2><?php esc_html_e( 'Visibility Settings', 'ffcertificate' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Configure how private audience calendars are displayed to non-logged-in visitors. Note: Scheduling is always restricted to authorized members.', 'ffcertificate' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="ffc_aud_private_display_mode"><?php esc_html_e( 'Private Calendar Display', 'ffcertificate' ); ?></label>
							</th>
							<td>
								<select name="ffc_aud_private_display_mode" id="ffc_aud_private_display_mode">
									<option value="show_message" <?php selected( $display_mode, 'show_message' ); ?>>
										<?php esc_html_e( 'Show message', 'ffcertificate' ); ?>
									</option>
									<option value="show_title_message" <?php selected( $display_mode, 'show_title_message' ); ?>>
										<?php esc_html_e( 'Show calendar title + message', 'ffcertificate' ); ?>
									</option>
									<option value="hide" <?php selected( $display_mode, 'hide' ); ?>>
										<?php esc_html_e( 'Hide completely', 'ffcertificate' ); ?>
									</option>
								</select>
								<p class="description"><?php esc_html_e( 'What to show when a private calendar is accessed by a non-logged-in user.', 'ffcertificate' ); ?></p>
							</td>
						</tr>
						<tr class="ffc-aud-message-row" <?php echo 'hide' === $display_mode ? 'style="display:none;"' : ''; ?>>
							<th scope="row">
								<label for="ffc_aud_visibility_message"><?php esc_html_e( 'Visibility Message', 'ffcertificate' ); ?></label>
							</th>
							<td>
								<textarea name="ffc_aud_visibility_message" id="ffc_aud_visibility_message" rows="3" class="large-text"><?php echo esc_textarea( $visibility_message ); ?></textarea>
								<p class="description">
									<?php esc_html_e( 'Shown when the calendar is private and user is not logged in. Use %login_url% for the login link.', 'ffcertificate' ); ?>
								</p>
							</td>
						</tr>
						<tr class="ffc-aud-message-row" <?php echo 'hide' === $display_mode ? 'style="display:none;"' : ''; ?>>
							<th scope="row">
								<label for="ffc_aud_scheduling_message"><?php esc_html_e( 'Scheduling Message', 'ffcertificate' ); ?></label>
							</th>
							<td>
								<textarea name="ffc_aud_scheduling_message" id="ffc_aud_scheduling_message" rows="3" class="large-text"><?php echo esc_textarea( $scheduling_message ); ?></textarea>
								<p class="description">
									<?php esc_html_e( 'Shown when the calendar is public but user is not logged in and tries to book. Use %login_url% for the login link.', 'ffcertificate' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="ffc_aud_multiple_audiences_color"><?php esc_html_e( '"Multiple Audiences" Badge Color', 'ffcertificate' ); ?></label>
							</th>
							<td>
								<input type="color" name="ffc_aud_multiple_audiences_color" id="ffc_aud_multiple_audiences_color"
										value="<?php echo esc_attr( $multiple_audiences_color ? $multiple_audiences_color : '#666666' ); ?>"
										style="width: 50px; height: 30px; padding: 0; border: 1px solid #ccc; cursor: pointer;">
								<span style="margin-left: 8px; color: #666;"><?php echo esc_html( $multiple_audiences_color ? $multiple_audiences_color : '#666666' ); ?></span>
								<p class="description">
									<?php esc_html_e( 'Color for the "Multiple audiences" badge shown in the event list when an event has more than 2 audiences.', 'ffcertificate' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button( __( 'Save Settings', 'ffcertificate' ) ); ?>
			</div>
		</form>

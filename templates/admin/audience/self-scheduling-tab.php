<?php
/**
 * Template: Audience admin settings — Self-Scheduling tab (visibility + business-hours messages).
 * In-scope: $display_mode, $visibility_message, $scheduling_message,
 * $bh_viewing_message, $bh_booking_message.
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
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Template variables scoped to this file (the include runs in the including renderer method scope).
?>
		<div class="card">
			<h2><?php esc_html_e( 'Self-Scheduling Settings', 'ffcertificate' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Settings specific to the personal appointment booking system. Calendar-specific settings (slots, working hours, email templates) are configured on each calendar\'s edit page.', 'ffcertificate' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Manage Calendars', 'ffcertificate' ); ?></th>
						<td>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=ffc_self_scheduling' ) ); ?>" class="button">
								<?php esc_html_e( 'View All Calendars', 'ffcertificate' ); ?>
							</a>
							<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=ffc_self_scheduling' ) ); ?>" class="button">
								<?php esc_html_e( 'Add New Calendar', 'ffcertificate' ); ?>
							</a>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Appointments', 'ffcertificate' ); ?></th>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=ffc-appointments' ) ); ?>" class="button">
								<?php esc_html_e( 'View All Appointments', 'ffcertificate' ); ?>
							</a>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- Visibility Settings -->
		<form method="post" action="">
			<?php wp_nonce_field( 'ffc_ss_visibility_settings', 'ffc_ss_visibility_nonce' ); ?>
			<input type="hidden" name="ffc_action" value="save_ss_visibility_settings">

			<div class="card">
				<h2><?php esc_html_e( 'Visibility Settings', 'ffcertificate' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Configure how private calendars are displayed to non-logged-in visitors.', 'ffcertificate' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="ffc_ss_private_display_mode"><?php esc_html_e( 'Private Calendar Display', 'ffcertificate' ); ?></label>
							</th>
							<td>
								<select name="ffc_ss_private_display_mode" id="ffc_ss_private_display_mode">
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
						<tr class="ffc-ss-message-row" <?php echo 'hide' === $display_mode ? 'style="display:none;"' : ''; ?>>
							<th scope="row">
								<label for="ffc_ss_visibility_message"><?php esc_html_e( 'Visibility Message', 'ffcertificate' ); ?></label>
							</th>
							<td>
								<textarea name="ffc_ss_visibility_message" id="ffc_ss_visibility_message" rows="3" class="large-text"><?php echo esc_textarea( $visibility_message ); ?></textarea>
								<p class="description">
									<?php esc_html_e( 'Shown when the calendar is private and user is not logged in. Use %login_url% for the login link.', 'ffcertificate' ); ?>
								</p>
							</td>
						</tr>
						<tr class="ffc-ss-message-row" <?php echo 'hide' === $display_mode ? 'style="display:none;"' : ''; ?>>
							<th scope="row">
								<label for="ffc_ss_scheduling_message"><?php esc_html_e( 'Scheduling Message', 'ffcertificate' ); ?></label>
							</th>
							<td>
								<textarea name="ffc_ss_scheduling_message" id="ffc_ss_scheduling_message" rows="3" class="large-text"><?php echo esc_textarea( $scheduling_message ); ?></textarea>
								<p class="description">
									<?php esc_html_e( 'Shown when the calendar is public but scheduling is private and user is not logged in. Use %login_url% for the login link.', 'ffcertificate' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button( __( 'Save Settings', 'ffcertificate' ) ); ?>
			</div>
		</form>

		<!-- Business Hours Restriction Messages -->
		<form method="post" action="">
			<?php wp_nonce_field( 'ffc_ss_business_hours_settings', 'ffc_ss_business_hours_nonce' ); ?>
			<input type="hidden" name="ffc_action" value="save_ss_business_hours_settings">

			<div class="card">
				<h2><?php esc_html_e( 'Business Hours Restriction Messages', 'ffcertificate' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Messages shown when a calendar has business hours restrictions enabled (configured per calendar).', 'ffcertificate' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="ffc_ss_business_hours_viewing_message"><?php esc_html_e( 'Viewing Restriction Message', 'ffcertificate' ); ?></label>
							</th>
							<td>
								<textarea name="ffc_ss_business_hours_viewing_message" id="ffc_ss_business_hours_viewing_message" rows="3" class="large-text"><?php echo esc_textarea( $bh_viewing_message ); ?></textarea>
								<p class="description">
									<?php
									/* translators: %hours% is a placeholder token the admin can use in the message */
									esc_html_e( 'Shown when the calendar cannot be viewed outside business hours. Use %hours% for today\'s working hours.', 'ffcertificate' );
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="ffc_ss_business_hours_booking_message"><?php esc_html_e( 'Booking Restriction Message', 'ffcertificate' ); ?></label>
							</th>
							<td>
								<textarea name="ffc_ss_business_hours_booking_message" id="ffc_ss_business_hours_booking_message" rows="3" class="large-text"><?php echo esc_textarea( $bh_booking_message ); ?></textarea>
								<p class="description">
									<?php
									/* translators: %hours% is a placeholder token the admin can use in the message */
									esc_html_e( 'Shown when booking is not allowed outside business hours (calendar is still visible). Use %hours% for today\'s working hours.', 'ffcertificate' );
									?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button( __( 'Save Settings', 'ffcertificate' ) ); ?>
			</div>
		</form>

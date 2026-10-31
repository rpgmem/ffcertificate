<?php
/**
 * Template: Audience admin settings — Self-Scheduling tab (visibility + business-hours messages).
 * In-scope: $display_mode, $visibility_message, $scheduling_message,
 * $bh_viewing_message, $bh_booking_message, $closeout_days.
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
			<h2 class="ffc-icon-calendar"><?php esc_html_e( 'Personal Calendars Settings', 'ffcertificate' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Settings specific to the personal appointment booking system. Calendar-specific settings (slots, working hours, email templates) are configured on each calendar\'s edit page.', 'ffcertificate' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Manage Calendars', 'ffcertificate' ); ?></th>
						<td>
							<?php
							\FreeFormCertificate\Admin\AdminUI::render_button(
								array(
									'label' => __( 'View All Calendars', 'ffcertificate' ),
									'icon'  => 'calendar',
									'href'  => admin_url( 'edit.php?post_type=ffc_self_scheduling' ),
								)
							);
							\FreeFormCertificate\Admin\AdminUI::render_button(
								array(
									'label'    => __( 'Add New Calendar', 'ffcertificate' ),
									'category' => 'create',
									'icon'     => 'plus',
									'href'     => admin_url( 'post-new.php?post_type=ffc_self_scheduling' ),
								)
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Appointments', 'ffcertificate' ); ?></th>
						<td>
							<?php
							\FreeFormCertificate\Admin\AdminUI::render_button(
								array(
									'label' => __( 'View All Appointments', 'ffcertificate' ),
									'icon'  => 'list',
									'href'  => admin_url( 'admin.php?page=ffc-appointments' ),
								)
							);
							?>
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
				<h2 class="ffc-icon-eye"><?php esc_html_e( 'Visibility Settings', 'ffcertificate' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Configure how private calendars are displayed to non-logged-in visitors.', 'ffcertificate' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<?php esc_html_e( 'Private Calendar Display', 'ffcertificate' ); ?>
							</th>
							<td>
								<?php
								\FreeFormCertificate\Admin\AdminUI::render_steps(
									array(
										'name'         => 'ffc_ss_private_display_mode',
										'id'           => 'ffc_ss_private_display_mode',
										'value'        => (string) $display_mode,
										'default'      => 'show_message',
										'legend'       => __( 'Private Calendar Display', 'ffcertificate' ),
										'options'      => array(
											'show_title_message' => __( 'Title and message', 'ffcertificate' ),
											'show_message' => __( 'Message only', 'ffcertificate' ),
											'hide'         => __( 'Hidden', 'ffcertificate' ),
										),
										'descriptions' => array(
											'show_title_message' => __( 'Show calendar title + message', 'ffcertificate' ),
											'show_message' => __( 'Show message', 'ffcertificate' ),
											'hide'         => __( 'Hide completely', 'ffcertificate' ),
										),
									)
								);
								?>
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
				<?php \FreeFormCertificate\Admin\AdminUI::render_save_button( __( 'Save Settings', 'ffcertificate' ), array( 'value' => __( 'Save Settings', 'ffcertificate' ) ) ); ?>
			</div>
		</form>

		<!-- Business Hours Restriction Messages -->
		<form method="post" action="">
			<?php wp_nonce_field( 'ffc_ss_business_hours_settings', 'ffc_ss_business_hours_nonce' ); ?>
			<input type="hidden" name="ffc_action" value="save_ss_business_hours_settings">

			<div class="card">
				<h2 class="ffc-icon-clock"><?php esc_html_e( 'Business Hours Restriction Messages', 'ffcertificate' ); ?></h2>
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
				<?php \FreeFormCertificate\Admin\AdminUI::render_save_button( __( 'Save Settings', 'ffcertificate' ), array( 'value' => __( 'Save Settings', 'ffcertificate' ) ) ); ?>
			</div>
		</form>

		<!-- Old appointment close-out (#2285) -->
		<form method="post" action="">
			<?php wp_nonce_field( 'ffc_ss_closeout_settings', 'ffc_ss_closeout_nonce' ); ?>
			<input type="hidden" name="ffc_action" value="save_ss_closeout_settings">

			<div class="card">
				<h2 class="ffc-icon-calendar-check"><?php esc_html_e( 'Close Old Appointments', 'ffcertificate' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Once a day, appointments whose date passed this many days ago and that nobody closed are closed: a confirmed one is marked completed, a pending one is cancelled, since nobody approved it in time. No e-mail is sent, and an attendance mark already set is kept. The Appointments list offers the same close-out on demand.', 'ffcertificate' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="ffc_ss_closeout_days"><?php esc_html_e( 'Days after the appointment', 'ffcertificate' ); ?></label>
							</th>
							<td>
								<input type="number" name="ffc_ss_closeout_days" id="ffc_ss_closeout_days" class="small-text" required
										min="0"
										max="<?php echo esc_attr( (string) \FreeFormCertificate\SelfScheduling\AppointmentCloseout::MAX_DAYS ); ?>"
										value="<?php echo esc_attr( (string) $closeout_days ); ?>">
								<p class="description">
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: highest accepted value, 2: default value */
											__( 'From 0 to %1$d (default %2$d). 0 turns the close-out off, the daily run and the button alike.', 'ffcertificate' ),
											\FreeFormCertificate\SelfScheduling\AppointmentCloseout::MAX_DAYS,
											\FreeFormCertificate\SelfScheduling\AppointmentCloseout::DEFAULT_DAYS
										)
									);
									?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>
				<?php \FreeFormCertificate\Admin\AdminUI::render_save_button( __( 'Save Settings', 'ffcertificate' ), array( 'value' => __( 'Save Settings', 'ffcertificate' ) ) ); ?>
			</div>
		</form>

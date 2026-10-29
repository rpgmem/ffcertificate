<?php
/**
 * Template: Audience admin settings — General tab (status summary + global holidays).
 * In-scope: $holidays (array), $published (int|string published calendars),
 * $audience_active_count (int active audience schedules), $closed_date_colors
 * (kind => hex, #2202), $this->menu_slug.
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
			<h2 class="ffc-icon-settings"><?php esc_html_e( 'General Settings', 'ffcertificate' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'General scheduling settings that apply to both Personal Calendars and Audience Calendars.', 'ffcertificate' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'ffcertificate' ); ?></th>
						<td>
							<p>
								<strong><?php esc_html_e( 'Personal Calendars:', 'ffcertificate' ); ?></strong>
								<?php
								printf(
									/* translators: %d: number of published calendars */
									esc_html__( '%d published calendar(s)', 'ffcertificate' ),
									(int) $published
								);
								?>
							</p>
							<p>
								<strong><?php esc_html_e( 'Audience:', 'ffcertificate' ); ?></strong>
								<?php
								printf(
									/* translators: %d: number of active schedules */
									esc_html__( '%d active schedule(s)', 'ffcertificate' ),
									(int) $audience_active_count
								);
								?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- Global Holidays -->
		<div class="card">
			<h2 class="ffc-icon-lock"><?php esc_html_e( 'Global Holidays', 'ffcertificate' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Holidays added here will block bookings across all calendars in both scheduling systems. Use per-calendar blocked dates for calendar-specific closures.', 'ffcertificate' ); ?>
			</p>

			<form method="post">
				<?php wp_nonce_field( 'ffc_global_holiday_action', 'ffc_global_holiday_nonce' ); ?>
				<input type="hidden" name="ffc_action" value="add_global_holiday">
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="global_holiday_date"><?php esc_html_e( 'Date', 'ffcertificate' ); ?></label>
							</th>
							<td>
								<input type="date" id="global_holiday_date" name="global_holiday_date" required class="regular-text">
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Type', 'ffcertificate' ); ?></th>
							<td>
								<?php \FreeFormCertificate\Admin\AdminUI::render_segmented( \FreeFormCertificate\Scheduling\ClosedDateKind::control_args( 'global_holiday_kind' ) ); ?>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="global_holiday_description"><?php esc_html_e( 'Description', 'ffcertificate' ); ?></label>
							</th>
							<td>
								<input type="text" id="global_holiday_description" name="global_holiday_description"
										placeholder="<?php esc_attr_e( 'e.g. Christmas, Carnival...', 'ffcertificate' ); ?>"
										class="regular-text">
							</td>
						</tr>
						<tr>
							<th></th>
							<td>
								<?php
								\FreeFormCertificate\Admin\AdminUI::render_button(
									array(
										'label'    => __( 'Add Holiday', 'ffcertificate' ),
										'category' => 'create',
										'icon'     => 'calendar',
										'type'     => 'submit',
									)
								);
								?>
							</td>
						</tr>
					</tbody>
				</table>
			</form>

			<?php if ( ! empty( $holidays ) ) : ?>
				<table class="widefat striped ffc-mt-xl">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'ffcertificate' ); ?></th>
							<th><?php esc_html_e( 'Type', 'ffcertificate' ); ?></th>
							<th><?php esc_html_e( 'Description', 'ffcertificate' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'ffcertificate' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $holidays as $holiday_index => $holiday ) : ?>
							<tr data-ffc-holiday-view="<?php echo esc_attr( 'global-' . $holiday_index ); ?>">
								<td>
									<?php
									// Holiday dates are wall-clock DATE strings (Category B) stored
									// literally as 'Y-m-d' — render without timezone conversion.
									// format_date() would parse them as UTC midnight and re-apply
									// wp_timezone(), rolling a 05/06 holiday back to 04/06 on UTC-3.
									$formatted_holiday = \FreeFormCertificate\Core\DateFormatter::format_wallclock_date( $holiday['date'] );
									echo esc_html( '' !== $formatted_holiday ? $formatted_holiday : $holiday['date'] );
									?>
								</td>
								<td><?php echo \FreeFormCertificate\Scheduling\ClosedDateKind::pill( (string) ( $holiday['kind'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pill() escapes its class and label. ?></td>
								<td><?php echo esc_html( $holiday['description'] ?? '' ); ?></td>
								<td>
									<?php
									// By date, never by position: the list is shown sorted and
									// stored in insertion order, so a position named the wrong
									// day (#2010). The date is unique, the add handler refuses
									// a duplicate.
									$delete_url = wp_nonce_url(
										admin_url( 'admin.php?page=' . $this->menu_slug . '-settings&tab=general&ffc_action=delete_global_holiday&holiday_date=' . rawurlencode( (string) $holiday['date'] ) ),
										'delete_global_holiday_' . $holiday['date'],
										'ffc_global_holiday_nonce'
									);
									\FreeFormCertificate\Admin\AdminUI::render_button(
										array(
											'label'    => __( 'Edit', 'ffcertificate' ),
											'category' => 'neutral',
											'icon'     => 'edit',
											'type'     => 'button',
											'attrs'    => array( 'data-ffc-holiday-edit-open' => 'global-' . $holiday_index ),
										)
									);
									?>
									<a href="<?php echo esc_url( $delete_url ); ?>"
										class="ffc-btn ffc-btn-danger ffc-icon-delete"
										onclick="return confirm('<?php esc_attr_e( 'Remove this holiday?', 'ffcertificate' ); ?>');">
										<?php esc_html_e( 'Delete', 'ffcertificate' ); ?>
									</a>
								</td>
							</tr>
							<?php
							$edit_row = array(
								'key'          => 'global-' . $holiday_index,
								'colspan'      => 4,
								'action'       => 'edit_global_holiday',
								'nonce_action' => 'edit_global_holiday_' . $holiday['date'],
								'nonce_name'   => 'ffc_holiday_edit_nonce',
								'hidden'       => array( 'holiday_original_date' => (string) $holiday['date'] ),
								'date'         => (string) $holiday['date'],
								'kind'         => (string) ( $holiday['kind'] ?? '' ),
								'description'  => (string) ( $holiday['description'] ?? '' ),
							);
							include FFC_PLUGIN_DIR . 'templates/admin/audience/holiday-edit-row.php';
							?>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="description ffc-mt-xl">
					<?php esc_html_e( 'No global holidays configured.', 'ffcertificate' ); ?>
				</p>
			<?php endif; ?>
		</div>

		<!-- Closed-date colours (#2202) -->
		<div class="card">
			<h2 class="ffc-icon-palette"><?php esc_html_e( 'Holiday Colors', 'ffcertificate' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'The color of the holiday and optional holiday labels on the public calendars, in the light theme. The dark theme keeps its own colors, and the text on each label turns white or black to stay readable.', 'ffcertificate' ); ?>
			</p>
			<form method="post">
				<?php wp_nonce_field( 'ffc_closed_date_colors', 'ffc_closed_date_colors_nonce' ); ?>
				<input type="hidden" name="ffc_action" value="save_closed_date_colors">
				<table class="form-table" role="presentation">
					<tbody>
						<?php foreach ( \FreeFormCertificate\Scheduling\ClosedDateKind::all() as $ffc_kind ) : ?>
							<tr>
								<th scope="row">
									<label for="closed_date_color_<?php echo esc_attr( $ffc_kind ); ?>"><?php echo esc_html( \FreeFormCertificate\Scheduling\ClosedDateKind::label( $ffc_kind ) ); ?></label>
								</th>
								<td>
									<?php // The picker the Email Model and Date Messages use (wp-color-picker); its Default button restores the shipped colour. ?>
									<input type="text" name="closed_date_color_<?php echo esc_attr( $ffc_kind ); ?>" id="closed_date_color_<?php echo esc_attr( $ffc_kind ); ?>" class="ffc-closed-date-color"
											value="<?php echo esc_attr( $closed_date_colors[ $ffc_kind ] ); ?>"
											data-default-color="<?php echo esc_attr( \FreeFormCertificate\Scheduling\ClosedDateKind::DEFAULT_COLORS[ $ffc_kind ] ); ?>">
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php \FreeFormCertificate\Admin\AdminUI::render_save_button( __( 'Save Colors', 'ffcertificate' ), array( 'value' => __( 'Save Colors', 'ffcertificate' ) ) ); ?>
			</form>
		</div>

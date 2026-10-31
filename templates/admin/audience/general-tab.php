<?php
/**
 * Template: Audience admin settings — General tab (status summary + global holidays).
 * In-scope: $holidays (array), $published (int|string published calendars),
 * $audience_active_count (int active audience schedules), $this->menu_slug,
 * $ffc_audience_access (bool; false for a Personal Calendars Setup holder
 * without the audience capability, who sees the status and the holidays).
 * The calendar colours and labels card reads CalendarAppearance (#2215).
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
			<?php \FreeFormCertificate\Audience\AudienceClosedDay::render_pending(); ?>

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

		<!-- Calendar colours and labels (#2202, #2215) -->
		<?php
		if ( ! $ffc_audience_access ) {
			return;
		}
		$ffc_appearance      = \FreeFormCertificate\Scheduling\CalendarAppearance::stored();
		$ffc_available_ratio = \FreeFormCertificate\Scheduling\CalendarAppearance::available_contrast( $ffc_appearance['colors']['available'] );
		$ffc_state_hints     = array(
			'available' => __( 'Day border, day number and legend dot. A day cell name, when set, shows on available days with no bookings.', 'ffcertificate' ),
			'booked'    => __( 'The bookings badge and legend dot. A calendar\'s own booking label wins over these names.', 'ffcertificate' ),
			'holiday'   => __( 'Day badge and legend dot.', 'ffcertificate' ),
			'optional'  => __( 'Day badge and legend dot.', 'ffcertificate' ),
			'closed'    => __( 'A day with no opening hours: day badge and legend dot.', 'ffcertificate' ),
		);
		?>
		<div class="card">
			<h2 class="ffc-icon-palette"><?php esc_html_e( 'Calendar Colors and Labels', 'ffcertificate' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'How each state looks and is named on the personal and the audience calendars. Colors apply to the light theme; the dark theme keeps its own, and the text on each color turns white or black to stay readable. An empty name uses the default shown in the field.', 'ffcertificate' ); ?>
			</p>
			<p class="description">
				<?php esc_html_e( 'These are the general names. When a calendar needs its own, set them on the calendar: the Names tab of a personal calendar, or the Calendar names field of an audience calendar. A calendar\'s name, and its choice to show or hide the closed day\'s name, win over these; an empty field there keeps the general one.', 'ffcertificate' ); ?>
			</p>
			<form method="post">
				<?php wp_nonce_field( 'ffc_calendar_appearance', 'ffc_calendar_appearance_nonce' ); ?>
				<input type="hidden" name="ffc_action" value="save_calendar_appearance">
				<table class="widefat striped ffc-calendar-appearance-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'State', 'ffcertificate' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Color', 'ffcertificate' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Legend name', 'ffcertificate' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Day cell name', 'ffcertificate' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Preview', 'ffcertificate' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( \FreeFormCertificate\Scheduling\CalendarAppearance::states() as $ffc_state ) : ?>
							<?php $ffc_has_cell = in_array( $ffc_state, \FreeFormCertificate\Scheduling\CalendarAppearance::CELL_STATES, true ); ?>
							<tr>
								<th scope="row">
									<label for="calendar_color_<?php echo esc_attr( $ffc_state ); ?>"><?php echo esc_html( \FreeFormCertificate\Scheduling\CalendarAppearance::default_legend( $ffc_state ) ); ?></label>
									<p class="description"><?php echo esc_html( $ffc_state_hints[ $ffc_state ] ); ?></p>
								</th>
								<td>
									<?php // The picker the Email Model and Date Messages use (wp-color-picker); its Default button restores the shipped colour. ?>
									<input type="text" name="calendar_color[<?php echo esc_attr( $ffc_state ); ?>]" id="calendar_color_<?php echo esc_attr( $ffc_state ); ?>" class="ffc-calendar-color"
											value="<?php echo esc_attr( $ffc_appearance['colors'][ $ffc_state ] ); ?>"
											data-default-color="<?php echo esc_attr( \FreeFormCertificate\Scheduling\CalendarAppearance::DEFAULT_COLORS[ $ffc_state ] ); ?>">
								</td>
								<td>
									<input type="text" name="calendar_legend[<?php echo esc_attr( $ffc_state ); ?>]" maxlength="40"
											aria-label="<?php echo esc_attr( sprintf( /* translators: %s: calendar state, such as Available */ __( 'Legend name: %s', 'ffcertificate' ), \FreeFormCertificate\Scheduling\CalendarAppearance::default_legend( $ffc_state ) ) ); ?>"
											value="<?php echo esc_attr( $ffc_appearance['legend'][ $ffc_state ] ); ?>"
											placeholder="<?php echo esc_attr( \FreeFormCertificate\Scheduling\CalendarAppearance::default_legend( $ffc_state ) ); ?>">
								</td>
								<td>
									<?php if ( $ffc_has_cell ) : ?>
										<input type="text" name="calendar_cell[<?php echo esc_attr( $ffc_state ); ?>]" maxlength="40"
												aria-label="<?php echo esc_attr( sprintf( /* translators: %s: calendar state, such as Holiday */ __( 'Day cell name: %s', 'ffcertificate' ), \FreeFormCertificate\Scheduling\CalendarAppearance::default_legend( $ffc_state ) ) ); ?>"
												value="<?php echo esc_attr( $ffc_appearance['cell'][ $ffc_state ] ); ?>"
												placeholder="<?php echo esc_attr( \FreeFormCertificate\Scheduling\CalendarAppearance::default_cell( $ffc_state ) ); ?>">
									<?php elseif ( 'booked' === $ffc_state ) : ?>
										<?php // The badge reads "N <name>": one form for a single booking, one for more (#2221). ?>
										<?php
										foreach ( array(
											\FreeFormCertificate\Scheduling\CalendarAppearance::BOOKING_SINGULAR => __( 'Singular', 'ffcertificate' ),
											\FreeFormCertificate\Scheduling\CalendarAppearance::BOOKING_PLURAL => __( 'Plural', 'ffcertificate' ),
										) as $ffc_form => $ffc_form_label ) :
											?>
											<input type="text" name="calendar_cell[<?php echo esc_attr( $ffc_form ); ?>]" maxlength="40" class="ffc-calendar-booking-name"
													aria-label="<?php echo esc_attr( sprintf( /* translators: %s: Singular or Plural */ __( 'Booking badge name (%s)', 'ffcertificate' ), $ffc_form_label ) ); ?>"
													value="<?php echo esc_attr( $ffc_appearance['cell'][ $ffc_form ] ); ?>"
													placeholder="<?php echo esc_attr( \FreeFormCertificate\Scheduling\CalendarAppearance::default_cell( $ffc_form ) ); ?>">
										<?php endforeach; ?>
									<?php else : ?>
										<?php // An available day carries no text unless named here (#2221). ?>
										<input type="text" name="calendar_cell[<?php echo esc_attr( $ffc_state ); ?>]" maxlength="40"
												aria-label="<?php echo esc_attr( sprintf( /* translators: %s: calendar state, such as Holiday */ __( 'Day cell name: %s', 'ffcertificate' ), \FreeFormCertificate\Scheduling\CalendarAppearance::default_legend( $ffc_state ) ) ); ?>"
												value="<?php echo esc_attr( $ffc_appearance['cell'][ $ffc_state ] ); ?>"
												placeholder="<?php esc_attr_e( 'No text', 'ffcertificate' ); ?>">
									<?php endif; ?>
								</td>
								<td>
									<span class="ffc-calendar-state-preview ffc-calendar-state-preview-<?php echo esc_attr( $ffc_state ); ?>">
										<?php
										if ( 'available' === $ffc_state ) {
											echo '15';
											if ( '' !== \FreeFormCertificate\Scheduling\CalendarAppearance::cell( 'available' ) ) {
												echo ' · ' . esc_html( \FreeFormCertificate\Scheduling\CalendarAppearance::cell( 'available' ) );
											}
										} elseif ( 'booked' === $ffc_state ) {
											echo esc_html( '2 ' . \FreeFormCertificate\Scheduling\CalendarAppearance::cell( \FreeFormCertificate\Scheduling\CalendarAppearance::BOOKING_PLURAL ) );
										} else {
											echo esc_html( \FreeFormCertificate\Scheduling\CalendarAppearance::cell( $ffc_state ) );
										}
										?>
									</span>
									<?php if ( 'available' === $ffc_state && null !== $ffc_available_ratio ) : ?>
										<?php if ( $ffc_available_ratio < 4.5 ) : ?>
											<p class="ffc-icon-warning ffc-icon-tone-warning ffc-calendar-contrast">
												<?php
												/* translators: %s: contrast ratio, such as 3.2 */
												echo esc_html( sprintf( __( '%s:1 on white: the day number is hard to read (4.5:1 recommended).', 'ffcertificate' ), number_format_i18n( $ffc_available_ratio, 1 ) ) );
												?>
											</p>
										<?php else : ?>
											<p class="ffc-icon-success ffc-icon-tone-success ffc-calendar-contrast">
												<?php
												/* translators: %s: contrast ratio, such as 5.1 */
												echo esc_html( sprintf( __( '%s:1 on white', 'ffcertificate' ), number_format_i18n( $ffc_available_ratio, 1 ) ) );
												?>
											</p>
										<?php endif; ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php
				\FreeFormCertificate\Admin\AdminUI::render_toggle(
					array(
						'name'        => 'calendar_show_closed_label',
						'checked'     => $ffc_appearance['show_closed_label'],
						/* translators: %s: the closed day's cell name, such as Closed */
						'label'       => sprintf( __( 'Show "%s" in closed day cells', 'ffcertificate' ), \FreeFormCertificate\Scheduling\CalendarAppearance::cell( 'closed' ) ),
						'description' => __( 'When off, a closed day keeps its grey background and no text.', 'ffcertificate' ),
						'class'       => 'ffc-mt-xl',
					)
				);
				?>
				<p class="submit">
					<?php
					\FreeFormCertificate\Admin\AdminUI::render_button(
						array(
							'label'    => __( 'Save Colors and Labels', 'ffcertificate' ),
							'category' => 'primary',
							'icon'     => 'save',
							'type'     => 'submit',
						)
					);
					\FreeFormCertificate\Admin\AdminUI::render_button(
						array(
							'label'    => __( 'Restore Defaults', 'ffcertificate' ),
							'category' => 'caution',
							'icon'     => 'restore',
							'type'     => 'submit',
							'name'     => 'calendar_appearance_reset',
							'value'    => '1',
						)
					);
					?>
				</p>
			</form>
		</div>

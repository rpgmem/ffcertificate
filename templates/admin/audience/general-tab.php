<?php
/**
 * Template: Audience admin settings — General tab (status summary + global holidays).
 * In-scope: $holidays (array), $published (int|string published calendars),
 * $audience_active_count (int active audience schedules), $this->menu_slug.
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
			<h2><?php esc_html_e( 'General Settings', 'ffcertificate' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'General scheduling settings that apply to both Self-Scheduling and Audience systems.', 'ffcertificate' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'ffcertificate' ); ?></th>
						<td>
							<p>
								<strong><?php esc_html_e( 'Self-Scheduling:', 'ffcertificate' ); ?></strong>
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
			<h2><?php esc_html_e( 'Global Holidays', 'ffcertificate' ); ?></h2>
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
								<button type="submit" class="button button-primary">
									<?php esc_html_e( 'Add Holiday', 'ffcertificate' ); ?>
								</button>
							</td>
						</tr>
					</tbody>
				</table>
			</form>

			<?php if ( ! empty( $holidays ) ) : ?>
				<table class="widefat striped ffc-mt-15">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'ffcertificate' ); ?></th>
							<th><?php esc_html_e( 'Description', 'ffcertificate' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'ffcertificate' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $holidays as $index => $holiday ) : ?>
							<tr>
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
								<td><?php echo esc_html( $holiday['description'] ?? '' ); ?></td>
								<td>
									<?php
									$delete_url = wp_nonce_url(
										admin_url( 'admin.php?page=' . $this->menu_slug . '-settings&tab=general&ffc_action=delete_global_holiday&holiday_index=' . $index ),
										'delete_global_holiday_' . $index,
										'ffc_global_holiday_nonce'
									);
									?>
									<a href="<?php echo esc_url( $delete_url ); ?>"
										class="button button-small button-link-delete"
										onclick="return confirm('<?php esc_attr_e( 'Remove this holiday?', 'ffcertificate' ); ?>');">
										<?php esc_html_e( 'Delete', 'ffcertificate' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="description ffc-mt-15">
					<?php esc_html_e( 'No global holidays configured.', 'ffcertificate' ); ?>
				</p>
			<?php endif; ?>
		</div>

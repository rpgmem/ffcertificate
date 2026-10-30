<?php
/**
 * Template: Audience Bookings list (#2243).
 *
 * In-scope (from AudienceAdminBookings::render_page()): $this->menu_slug,
 * $schedule_id, $environment_id, $audience_id, $status_filter, $date_from,
 * $date_to, $search, $total, $pagination, $bookings, $schedules,
 * $schedule_names, $environments, $audience_choices, $audiences, $present,
 * $creators_map, $can_manage, $can_checkin, $attendance, $orderby, $order,
 * $filtered_url.
 *
 * @package FreeFormCertificate\Audience
 */

use FreeFormCertificate\Audience\AudienceAdminBookings;
use FreeFormCertificate\Audience\AudienceBookingRules;
use FreeFormCertificate\Audience\AudienceScheduleRepository;
use FreeFormCertificate\Core\DateFormatter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Keys are the `booking_type` enum stored in the table (`audience` /
// `individual`); the labels match the booking modal's.
$ffc_type_labels = array(
	'audience'   => __( 'Audience', 'ffcertificate' ),
	'individual' => __( 'Custom Users', 'ffcertificate' ),
);
$ffc_env_label   = $schedule_id > 0
	? AudienceScheduleRepository::get_environment_label( $schedule_id )
	: AudienceScheduleRepository::get_environment_label();
?>
<div class="wrap ffc-admin-page ffc-page-scheduling-bookings">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Audience Bookings', 'ffcertificate' ); ?></h1>
	<?php if ( \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_export_audiences' ) ) : ?>
		<?php
		// Batched CSV export (#772): the button drives the shared
		// window.FFCBatchedExport engine through the unified dispatcher —
		// current filters ride along via data-* so the export matches the
		// on-screen query. No admin-post form; the job nonce is localized.
		\FreeFormCertificate\Admin\AdminUI::render_button(
			array(
				'label' => __( 'Export CSV', 'ffcertificate' ),
				'icon'  => 'download',
				'id'    => 'ffc-bookings-export-btn',
				'attrs' => array(
					'data-schedule_id'    => (string) $schedule_id,
					'data-environment_id' => (string) $environment_id,
					'data-audience_id'    => (string) $audience_id,
					'data-status'         => $status_filter,
					'data-date_from'      => $date_from,
					'data-date_to'        => $date_to,
					'data-s'              => $search,
				),
			)
		);
		?>
	<?php endif; ?>
	<hr class="wp-header-end">

	<?php \FreeFormCertificate\Core\AdminNotice::settings_errors( 'ffc_audience' ); ?>

	<!-- Filters -->
	<div class="tablenav top">
		<form method="get" action="" class="ffc-bookings-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( $this->menu_slug ); ?>-bookings">
			<?php // Filtering keeps the chosen sort (#2291). ?>
			<input type="hidden" name="orderby" value="<?php echo esc_attr( $orderby ); ?>">
			<input type="hidden" name="order" value="<?php echo esc_attr( $order ); ?>">

			<select name="schedule_id" id="filter-schedule">
				<option value=""><?php esc_html_e( 'All Schedules', 'ffcertificate' ); ?></option>
				<?php foreach ( $schedules as $ffc_schedule ) : ?>
					<option value="<?php echo esc_attr( $ffc_schedule->id ); ?>" <?php selected( $schedule_id, $ffc_schedule->id ); ?>>
						<?php echo esc_html( $ffc_schedule->name ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<select name="environment_id" id="filter-environment">
				<option value="">
					<?php
					/* translators: %s: environment label (plural) */
					printf( esc_html__( 'All %s', 'ffcertificate' ), esc_html( $ffc_env_label ) );
					?>
				</option>
				<?php foreach ( $environments as $ffc_env ) : ?>
					<option value="<?php echo esc_attr( $ffc_env->id ); ?>" <?php selected( $environment_id, $ffc_env->id ); ?>>
						<?php echo esc_html( $ffc_env->name ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<select name="audience_id" id="filter-audience" aria-label="<?php esc_attr_e( 'Audience', 'ffcertificate' ); ?>">
				<option value=""><?php esc_html_e( 'All Audiences', 'ffcertificate' ); ?></option>
				<?php foreach ( $audience_choices as $ffc_audience ) : ?>
					<option value="<?php echo esc_attr( $ffc_audience->id ); ?>" <?php selected( $audience_id, $ffc_audience->id ); ?>>
						<?php echo esc_html( $ffc_audience->name ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<select name="status">
				<option value=""><?php esc_html_e( 'All Statuses', 'ffcertificate' ); ?></option>
				<option value="active" <?php selected( $status_filter, 'active' ); ?>><?php esc_html_e( 'Active', 'ffcertificate' ); ?></option>
				<option value="cancelled" <?php selected( $status_filter, 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'ffcertificate' ); ?></option>
			</select>

			<input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" aria-label="<?php esc_attr_e( 'From', 'ffcertificate' ); ?>">
			<input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" aria-label="<?php esc_attr_e( 'To', 'ffcertificate' ); ?>">
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Description or number', 'ffcertificate' ); ?>" aria-label="<?php esc_attr_e( 'Search bookings', 'ffcertificate' ); ?>">

			<?php
			\FreeFormCertificate\Admin\AdminUI::render_button(
				array(
					'label' => __( 'Filter', 'ffcertificate' ),
					'icon'  => 'filter',
					'type'  => 'submit',
				)
			);
			\FreeFormCertificate\Admin\AdminUI::render_button(
				array(
					'label' => __( 'Clear', 'ffcertificate' ),
					'icon'  => 'eraser',
					'href'  => admin_url( 'admin.php?page=' . $this->menu_slug . '-bookings' ),
				)
			);
			?>
		</form>
	</div>

	<div class="tablenav top">
		<?php if ( $can_manage ) : ?>
			<div class="alignleft actions bulkactions">
				<?php
				\FreeFormCertificate\Admin\AdminUI::render_button(
					array(
						'label'    => __( 'Cancel selected', 'ffcertificate' ),
						'category' => 'danger',
						'icon'     => 'cross',
						'id'       => 'ffc-bookings-bulk-cancel',
						'disabled' => true,
					)
				);
				?>
			</div>
		<?php endif; ?>
		<?php AudienceAdminBookings::render_pagination( $total, $pagination ); ?>
	</div>

	<!-- Bookings Table -->
	<table class="wp-list-table widefat fixed striped ffc-bookings-table">
		<thead>
			<tr>
				<?php if ( $can_manage ) : ?>
					<td class="manage-column column-cb check-column">
						<input type="checkbox" id="ffc-bookings-select-all" aria-label="<?php esc_attr_e( 'Select all active bookings', 'ffcertificate' ); ?>">
					</td>
				<?php endif; ?>
				<?php
				// Sortable headers in core's list-table markup (#2291).
				foreach ( array(
					array( 'id', __( 'ID', 'ffcertificate' ), 'id' ),
					array( 'booking_date', __( 'Date', 'ffcertificate' ), 'date' ),
					array( 'start_time', __( 'Time', 'ffcertificate' ), 'time' ),
					array( '', __( 'Calendar', 'ffcertificate' ), 'calendar' ),
					array( 'environment', AudienceScheduleRepository::get_environment_label( null, true ), 'environment' ),
					array( 'description', __( 'Description', 'ffcertificate' ), 'description' ),
					array( '', __( 'For', 'ffcertificate' ), 'audiences' ),
					array( '', __( 'Present / reached', 'ffcertificate' ), 'present' ),
					array( 'status', __( 'Status', 'ffcertificate' ), 'status' ),
					array( '', __( 'Created By', 'ffcertificate' ), 'created_by' ),
					array( '', __( 'Actions', 'ffcertificate' ), 'actions' ),
				) as $ffc_header ) {
					echo \FreeFormCertificate\Admin\AdminUI::sortable_column_header( $ffc_header[0], $ffc_header[1], $ffc_header[2], AudienceAdminBookings::SORTABLE, $orderby, $order, $filtered_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sortable_column_header() escapes every value it prints.
				}
				?>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $bookings ) ) : ?>
				<tr>
					<td colspan="<?php echo esc_attr( $can_manage ? '12' : '11' ); ?>"><?php esc_html_e( 'No bookings found.', 'ffcertificate' ); ?></td>
				</tr>
			<?php else : ?>
				<?php foreach ( $bookings as $booking ) : ?>
					<?php
					$ffc_id         = (int) $booking->id;
					$ffc_active     = 'active' === $booking->status;
					$ffc_date_label = AudienceBookingRules::date_label( (string) $booking->booking_date, isset( $booking->end_date ) ? (string) $booking->end_date : null, static fn( string $date ): string => DateFormatter::format_wallclock_date( $date ) );
					$ffc_days       = count( AudienceBookingRules::booked_days( (string) $booking->booking_date, isset( $booking->end_date ) ? (string) $booking->end_date : null, isset( $booking->skipped_dates ) ? (string) $booking->skipped_dates : null ) );
					$ffc_for        = 'audience' === $booking->booking_type
						? implode( ', ', array_map( static fn( $a ): string => (string) $a->name, $audiences[ $ffc_id ] ?? array() ) )
						: '';
					?>
					<tr>
						<?php if ( $can_manage ) : ?>
							<th scope="row" class="check-column">
								<?php if ( $ffc_active ) : ?>
									<input type="checkbox" class="ffc-bookings-select" value="<?php echo esc_attr( (string) $ffc_id ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: booking number */ __( 'Select booking %d', 'ffcertificate' ), $ffc_id ) ); ?>">
								<?php endif; ?>
							</th>
						<?php endif; ?>
						<td><?php echo esc_html( (string) $ffc_id ); ?></td>
						<td><?php echo esc_html( $ffc_date_label ); ?></td>
						<td><?php echo esc_html( AudienceBookingRules::time_label( (string) $booking->start_time, (string) $booking->end_time, AudienceBookingRules::is_span( $booking ), static fn( string $time ): string => DateFormatter::format_wallclock_time( $time ) ) ); ?></td>
						<td><?php echo esc_html( $schedule_names[ (int) ( $booking->schedule_id ?? 0 ) ] ?? '' ); ?></td>
						<td><?php echo esc_html( (string) $booking->environment_name ); ?></td>
						<td><?php echo esc_html( wp_trim_words( (string) $booking->description, 10 ) ); ?></td>
						<td><?php echo esc_html( '' !== $ffc_for ? $ffc_for : ( $ffc_type_labels[ $booking->booking_type ] ?? (string) $booking->booking_type ) ); ?></td>
						<td><?php echo esc_html( sprintf( '%d / %d', (int) ( $present[ $ffc_id ] ?? 0 ), AudienceAdminBookings::reach( $ffc_id ) ) ); ?></td>
						<td>
							<span class="<?php echo esc_attr( $ffc_active ? 'ffc-booking-status-active' : 'ffc-booking-status-cancelled' ); ?>">
								<?php echo $ffc_active ? esc_html__( 'Active', 'ffcertificate' ) : esc_html__( 'Cancelled', 'ffcertificate' ); ?>
							</span>
						</td>
						<td><?php echo esc_html( $creators_map[ (int) $booking->created_by ] ?? __( 'Unknown', 'ffcertificate' ) ); ?></td>
						<td>
							<?php if ( $can_manage ) : ?>
								<a href="#" class="ffc-view-booking" data-booking-id="<?php echo esc_attr( (string) $ffc_id ); ?>">
									<?php esc_html_e( 'View', 'ffcertificate' ); ?>
								</a>
								<?php if ( $ffc_active ) : ?>
									|
									<a href="#" class="ffc-cancel-booking ffc-delete-link" data-booking-id="<?php echo esc_attr( (string) $ffc_id ); ?>" data-days="<?php echo esc_attr( (string) $ffc_days ); ?>" data-series="<?php echo esc_attr( (int) ( $booking->series_id ?? 0 ) > 0 ? '1' : '' ); ?>" data-date-label="<?php echo esc_attr( $ffc_date_label ); ?>">
										<?php esc_html_e( 'Cancel', 'ffcertificate' ); ?>
									</a>
								<?php endif; ?>
							<?php endif; ?>
							<?php if ( $can_checkin && $ffc_active ) : ?>
								<?php echo $can_manage ? '|' : ''; ?>
								<a href="<?php echo esc_url( $attendance->url( $ffc_id ) ); ?>" class="ffc-attendance-link">
									<?php esc_html_e( 'Attendance', 'ffcertificate' ); ?>
								</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<div class="tablenav bottom">
		<?php AudienceAdminBookings::render_pagination( $total, $pagination ); ?>
	</div>
</div>

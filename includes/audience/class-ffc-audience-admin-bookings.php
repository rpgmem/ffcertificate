<?php
/**
 * Audience Admin Bookings
 *
 * Handles the bookings listing page for the audience scheduling system.
 *
 * @package FreeFormCertificate\Audience
 * @since 4.6.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Audience Admin Bookings.
 */
class AudienceAdminBookings {

	/**
	 * Menu slug prefix
	 *
	 * @var string
	 */
	private string $menu_slug;

	/**
	 * Constructor
	 *
	 * @param string $menu_slug Menu slug prefix.
	 */
	public function __construct( string $menu_slug ) {
		$this->menu_slug = $menu_slug;
	}

	/**
	 * Render bookings page
	 *
	 * @return void
	 */
	public function render_page(): void {
		// Get filter parameters.
		$schedule_id    = RequestInput::get_get_int( 'schedule_id' );
		$environment_id = RequestInput::get_get_int( 'environment_id' );
		$status_filter  = \FreeFormCertificate\Core\RequestInput::get_get_string( 'status' );
		$date_from      = \FreeFormCertificate\Core\RequestInput::get_get_string( 'date_from' );
		$date_to        = \FreeFormCertificate\Core\RequestInput::get_get_string( 'date_to' );

		// Build query args.
		$args = array(
			'orderby' => 'booking_date',
			'order'   => 'DESC',
		);

		if ( $schedule_id > 0 ) {
			$args['schedule_id'] = $schedule_id;
		}
		if ( $environment_id > 0 ) {
			$args['environment_id'] = $environment_id;
		}
		if ( ! empty( $status_filter ) ) {
			$args['status'] = $status_filter;
		}
		if ( ! empty( $date_from ) ) {
			$args['start_date'] = $date_from;
		}
		if ( ! empty( $date_to ) ) {
			$args['end_date'] = $date_to;
		}

		// Get bookings.
		$bookings = AudienceBookingReader::get_all( $args );

		// Get schedules for filter.
		$schedules = AudienceScheduleRepository::get_all();

		// Get environments for filter.
		$environments = array();
		if ( $schedule_id > 0 ) {
			$environments = AudienceEnvironmentRepository::get_by_schedule( $schedule_id );
		}

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
							'data-status'         => $status_filter,
							'data-date_from'      => $date_from,
							'data-date_to'        => $date_to,
						),
					)
				);
				?>
			<?php endif; ?>
			<hr class="wp-header-end">

			<?php \FreeFormCertificate\Core\AdminNotice::settings_errors( 'ffc_audience' ); ?>

			<!-- Filters -->
			<div class="tablenav top">
				<form method="get" action="">
					<input type="hidden" name="page" value="<?php echo esc_attr( $this->menu_slug ); ?>-bookings">

					<select name="schedule_id" id="filter-schedule">
						<option value=""><?php esc_html_e( 'All Schedules', 'ffcertificate' ); ?></option>
						<?php foreach ( $schedules as $schedule ) : ?>
							<option value="<?php echo esc_attr( $schedule->id ); ?>" <?php selected( $schedule_id, $schedule->id ); ?>>
								<?php echo esc_html( $schedule->name ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<?php
					$env_filter_label = $schedule_id > 0
						? AudienceScheduleRepository::get_environment_label( $schedule_id )
						: AudienceScheduleRepository::get_environment_label();
					?>
					<select name="environment_id" id="filter-environment">
						<option value="">
							<?php
							/* translators: %s: environment label (plural) */
							printf( esc_html__( 'All %s', 'ffcertificate' ), esc_html( $env_filter_label ) );
							?>
						</option>
						<?php foreach ( $environments as $env ) : ?>
							<option value="<?php echo esc_attr( $env->id ); ?>" <?php selected( $environment_id, $env->id ); ?>>
								<?php echo esc_html( $env->name ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<select name="status">
						<option value=""><?php esc_html_e( 'All Statuses', 'ffcertificate' ); ?></option>
						<option value="active" <?php selected( $status_filter, 'active' ); ?>><?php esc_html_e( 'Active', 'ffcertificate' ); ?></option>
						<option value="cancelled" <?php selected( $status_filter, 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'ffcertificate' ); ?></option>
					</select>

					<input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" placeholder="<?php esc_attr_e( 'From', 'ffcertificate' ); ?>">
					<input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" placeholder="<?php esc_attr_e( 'To', 'ffcertificate' ); ?>">

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

			<!-- Bookings Table -->
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col" class="manage-column column-id"><?php esc_html_e( 'ID', 'ffcertificate' ); ?></th>
						<th scope="col" class="manage-column column-date"><?php esc_html_e( 'Date', 'ffcertificate' ); ?></th>
						<th scope="col" class="manage-column column-time"><?php esc_html_e( 'Time', 'ffcertificate' ); ?></th>
						<th scope="col" class="manage-column column-environment"><?php echo esc_html( AudienceScheduleRepository::get_environment_label( null, true ) ); ?></th>
						<th scope="col" class="manage-column column-description"><?php esc_html_e( 'Description', 'ffcertificate' ); ?></th>
						<th scope="col" class="manage-column column-type"><?php esc_html_e( 'Type', 'ffcertificate' ); ?></th>
						<th scope="col" class="manage-column column-status"><?php esc_html_e( 'Status', 'ffcertificate' ); ?></th>
						<th scope="col" class="manage-column column-created_by"><?php esc_html_e( 'Created By', 'ffcertificate' ); ?></th>
						<th scope="col" class="manage-column column-actions"><?php esc_html_e( 'Actions', 'ffcertificate' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $bookings ) ) : ?>
						<tr>
							<td colspan="9"><?php esc_html_e( 'No bookings found.', 'ffcertificate' ); ?></td>
						</tr>
					<?php else : ?>
						<?php
						// Batch load creator names to avoid N+1 get_userdata() calls.
						$creator_ids  = array_unique(
							array_filter(
								array_map(
									function ( $b ) {
										return (int) $b->created_by;
									},
									$bookings
								)
							)
						);
						$creators_map = array();
						if ( ! empty( $creator_ids ) ) {
							$creator_users = get_users(
								array(
									'include' => $creator_ids,
									'fields'  => array( 'ID', 'display_name' ),
								)
							);
							foreach ( $creator_users as $u ) {
								$creators_map[ (int) $u->ID ] = $u->display_name;
							}
						}
						?>
						<?php
						// View and Cancel call AJAX handlers gated on
						// `ffc_manage_audiences` (AudienceAjaxController, #1769).
						$can_manage = \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_audiences' );
						?>
						<?php foreach ( $bookings as $booking ) : ?>
							<?php
							$creator_name = $creators_map[ (int) $booking->created_by ] ?? __( 'Unknown', 'ffcertificate' );
							$status_class = 'active' === $booking->status ? 'ffc-booking-status-active' : 'ffc-booking-status-cancelled';
							?>
							<tr>
								<td><?php echo esc_html( $booking->id ); ?></td>
								<td><?php echo esc_html( AudienceBookingRules::date_label( (string) $booking->booking_date, isset( $booking->end_date ) ? (string) $booking->end_date : null, static fn( string $date ): string => \FreeFormCertificate\Core\DateFormatter::format_wallclock_date( $date ) ) ); ?></td>
								<td><?php echo esc_html( AudienceBookingRules::time_label( (string) $booking->start_time, (string) $booking->end_time, AudienceBookingRules::is_span( $booking ), static fn( string $time ): string => \FreeFormCertificate\Core\DateFormatter::format_wallclock_time( $time ) ) ); ?></td>
								<td><?php echo esc_html( $booking->environment_name ); ?></td>
								<td><?php echo esc_html( wp_trim_words( $booking->description, 10 ) ); ?></td>
								<td>
									<?php
									// Keys are the `booking_type` enum stored in the
									// table (`audience` / `individual`); the labels
									// match the booking modal's.
									$type_labels = array(
										'audience'   => __( 'Audience', 'ffcertificate' ),
										'individual' => __( 'Custom Users', 'ffcertificate' ),
									);
									echo esc_html( $type_labels[ $booking->booking_type ] ?? $booking->booking_type );
									?>
								</td>
								<td>
									<span class="<?php echo esc_attr( $status_class ); ?>">
										<?php echo 'active' === $booking->status ? esc_html__( 'Active', 'ffcertificate' ) : esc_html__( 'Cancelled', 'ffcertificate' ); ?>
									</span>
								</td>
								<td><?php echo esc_html( $creator_name ); ?></td>
								<td>
									<?php if ( $can_manage ) : ?>
										<a href="#" class="ffc-view-booking" data-booking-id="<?php echo esc_attr( $booking->id ); ?>">
											<?php esc_html_e( 'View', 'ffcertificate' ); ?>
										</a>
										<?php if ( 'active' === $booking->status ) : ?>
											|
											<a href="#" class="ffc-cancel-booking ffc-delete-link" data-booking-id="<?php echo esc_attr( $booking->id ); ?>" data-days="<?php echo esc_attr( (string) count( AudienceBookingRules::booked_days( (string) $booking->booking_date, isset( $booking->end_date ) ? (string) $booking->end_date : null, isset( $booking->skipped_dates ) ? (string) $booking->skipped_dates : null ) ) ); ?>" data-date-label="<?php echo esc_attr( AudienceBookingRules::date_label( (string) $booking->booking_date, isset( $booking->end_date ) ? (string) $booking->end_date : null, static fn( string $date ): string => \FreeFormCertificate\Core\DateFormatter::format_wallclock_date( $date ) ) ); ?>">
												<?php esc_html_e( 'Cancel', 'ffcertificate' ); ?>
											</a>
										<?php endif; ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<p class="description ffc-audience-total-note">
				<?php /* translators: %d: number of bookings */ printf( esc_html__( 'Total: %d bookings', 'ffcertificate' ), count( $bookings ) ); ?>
			</p>
		</div>

		<!-- Styles in ffc-audience-admin.css -->
		<!-- Scripts in ffc-audience-admin.js -->
		<?php
	}
}

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
	 * Bookings per page of the list (#2243).
	 */
	public const PER_PAGE = 50;

	/**
	 * Render bookings page
	 *
	 * The list pages through the bookings that match its filters (#2243): a
	 * calendar, a space, an audience, a status, a date range and a search
	 * over the description or the booking number. The CSV export takes the
	 * same filters, and the ticked bookings can be cancelled together.
	 *
	 * @return void
	 */
	public function render_page(): void {
		// Get filter parameters.
		$schedule_id    = RequestInput::get_get_int( 'schedule_id' );
		$environment_id = RequestInput::get_get_int( 'environment_id' );
		$audience_id    = RequestInput::get_get_int( 'audience_id' );
		$status_filter  = RequestInput::get_get_string( 'status' );
		$date_from      = RequestInput::get_get_string( 'date_from' );
		$date_to        = RequestInput::get_get_string( 'date_to' );
		$search         = RequestInput::get_get_string( 's' );
		$current_page   = max( 1, RequestInput::get_get_int( 'paged', 1 ) );

		$filters = array_filter(
			array(
				'schedule_id'    => $schedule_id,
				'environment_id' => $environment_id,
				'audience_id'    => $audience_id,
				'status'         => $status_filter,
				'start_date'     => $date_from,
				'end_date'       => $date_to,
				'search'         => $search,
			)
		);

		$total        = AudienceBookingReader::count_filtered( $filters );
		$total_pages  = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$current_page = min( $current_page, $total_pages );

		$bookings = AudienceBookingReader::get_all(
			$filters + array(
				'orderby' => 'booking_date',
				'order'   => 'DESC',
				'limit'   => self::PER_PAGE,
				'offset'  => ( $current_page - 1 ) * self::PER_PAGE,
			)
		);

		// Get schedules for filter, and the calendar column.
		$schedules      = AudienceScheduleRepository::get_all();
		$schedule_names = array();
		foreach ( $schedules as $schedule ) {
			$schedule_names[ (int) $schedule->id ] = (string) $schedule->name;
		}

		// Get environments for filter.
		$environments = array();
		if ( $schedule_id > 0 ) {
			$environments = AudienceEnvironmentRepository::get_by_schedule( $schedule_id );
		}

		$audience_choices = AudienceReader::get_all( array( 'orderby' => 'name' ) );

		$booking_ids = array_map( static fn( $b ): int => (int) $b->id, $bookings );
		$audiences   = AudienceBookingReader::get_audiences_for_bookings( $booking_ids );
		$present     = AudienceAttendanceReader::present_counts( $booking_ids );

		// Batch load creator names to avoid N+1 get_userdata() calls.
		$creators_map = array();
		$creator_ids  = array_values( array_unique( array_filter( array_map( static fn( $b ): int => (int) $b->created_by, $bookings ) ) ) );
		if ( ! empty( $creator_ids ) ) {
			foreach ( get_users(
				array(
					'include' => $creator_ids,
					'fields'  => array( 'ID', 'display_name' ),
				)
			) as $creator ) {
				$creators_map[ (int) $creator->ID ] = (string) $creator->display_name;
			}
		}

		$base_url     = admin_url( 'admin.php?page=' . $this->menu_slug . '-bookings' );
		$filtered_url = add_query_arg(
			array_filter(
				array(
					'schedule_id'    => $schedule_id,
					'environment_id' => $environment_id,
					'audience_id'    => $audience_id,
					'status'         => $status_filter,
					'date_from'      => $date_from,
					'date_to'        => $date_to,
					's'              => $search,
				)
			),
			$base_url
		);
		$pagination   = $total_pages > 1
			? (string) paginate_links(
				array(
					'base'      => add_query_arg( 'paged', '%#%', $filtered_url ),
					'format'    => '',
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
					'total'     => $total_pages,
					'current'   => $current_page,
				)
			)
			: '';

		// View and Cancel call AJAX handlers gated on
		// `ffc_manage_audiences` (AudienceAjaxController, #1769).
		$can_manage = \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_audiences' );
		// Attendance opens the booking's check-in screen (#1947).
		$can_checkin = \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( AudienceAdminAttendance::CAP );
		$attendance  = new AudienceAdminAttendance( $this->menu_slug );

		include FFC_PLUGIN_DIR . 'templates/admin/audience/bookings-list.php';
	}

	/**
	 * The count of listed bookings and the page links, above and below the
	 * list (#2243).
	 *
	 * @param int    $total      Bookings matching the filters.
	 * @param string $pagination `paginate_links()` output, '' for one page.
	 * @return void
	 */
	public static function render_pagination( int $total, string $pagination ): void {
		echo '<div class="tablenav-pages"><span class="displaying-num">';
		/* translators: %s: number of bookings listed. */
		echo esc_html( sprintf( _n( '%s booking', '%s bookings', $total, 'ffcertificate' ), number_format_i18n( $total ) ) );
		echo '</span>';
		if ( '' !== $pagination ) {
			echo ' <span class="pagination-links">' . wp_kses_post( $pagination ) . '</span>';
		}
		echo '</div>';
	}

	/**
	 * How many people a booking reaches: its own users and the members of
	 * its audiences, each once.
	 *
	 * @param int $booking_id Booking ID.
	 * @return int
	 */
	public static function reach( int $booking_id ): int {
		return count( AudienceBookingReader::get_all_affected_users( $booking_id ) );
	}
}

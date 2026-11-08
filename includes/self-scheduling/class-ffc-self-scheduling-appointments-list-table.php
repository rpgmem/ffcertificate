<?php
/**
 * Appointments List Table (WP_List_Table).
 *
 * Extracted from includes/self-scheduling/views/appointments-list.php so the
 * class lives under the FreeFormCertificate\SelfScheduling namespace and is
 * autoloaded (it was previously the lone global `FFC_*` plugin class besides
 * the bootstrap autoloader).
 *
 * @package FreeFormCertificate\SelfScheduling
 * @since   4.1.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\SelfScheduling;

use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// WP_List_Table is only loaded on demand in wp-admin; ensure the parent is
// available before this subclass is declared (autoload happens at first use).
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Appointments list table for the self-scheduling admin page.
 */
class AppointmentsListTable extends \WP_List_Table {

	/**
	 * Request `orderby` value → column the query orders by (#1770).
	 *
	 * The allowlist for the request: a value not listed here falls back to
	 * the default order, so nothing from the URL reaches the ORDER BY. The
	 * keys are the values `get_sortable_columns()` puts in the header links.
	 *
	 * @var array<string, string>
	 */
	private const ORDER_COLUMNS = array(
		'id'               => 'id',
		'calendar_id'      => 'calendar_id',
		'appointment_date' => 'appointment_date',
		'status'           => 'status',
		'created_at'       => 'created_at',
	);

	/**
	 * Appointment repository.
	 *
	 * @var \FreeFormCertificate\Repositories\AppointmentRepository
	 */
	private $appointment_repository;

	/**
	 * Calendar repository.
	 *
	 * @var \FreeFormCertificate\Repositories\CalendarRepository
	 */
	private $calendar_repository;

	/**
	 * The ffc_reveal_pii nonce, created on the first row that needs it.
	 *
	 * @var string|null
	 */
	private ?string $reveal_nonce = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'appointment',
				'plural'   => 'appointments',
				'ajax'     => false,
			)
		);

		$this->appointment_repository = new \FreeFormCertificate\Repositories\AppointmentRepository();
		$this->calendar_repository    = new \FreeFormCertificate\Repositories\CalendarRepository();
	}

	/**
	 * Get columns
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'cb'               => '<input type="checkbox" />',
			'id'               => __( 'ID', 'ffcertificate' ),
			'calendar'         => __( 'Calendar', 'ffcertificate' ),
			'name'             => __( 'Name', 'ffcertificate' ),
			'email'            => __( 'Email', 'ffcertificate' ),
			'appointment_date' => __( 'Date', 'ffcertificate' ),
			'time'             => __( 'Time', 'ffcertificate' ),
			'status'           => __( 'Status', 'ffcertificate' ),
			'created_at'       => __( 'Created', 'ffcertificate' ),
			'actions'          => __( 'Actions', 'ffcertificate' ),
		);
	}

	/**
	 * Get sortable columns
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function get_sortable_columns(): array {
		return array(
			'id'               => array( 'id', true ),
			'calendar'         => array( 'calendar_id', false ),
			'appointment_date' => array( 'appointment_date', true ),
			'status'           => array( 'status', false ),
			'created_at'       => array( 'created_at', true ),
		);
	}

	/**
	 * Column default
	 *
	 * @param array<string, mixed> $item        Row data.
	 * @param string               $column_name Column slug.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return esc_html( $item[ $column_name ] ?? '-' );
	}

	/**
	 * Checkbox column
	 *
	 * @param array<string, mixed> $item Row data.
	 */
	public function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="appointment[]" value="%d" />', $item['id'] );
	}

	/**
	 * Bulk actions: no-show for the confirmed appointments whose date has
	 * come (#2244), to the holders of the manage capability.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions(): array {
		$actions = array();
		if ( \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_appointments' ) ) {
			$actions['bulk_no_show'] = __( 'Mark as no-show', 'ffcertificate' );
		}
		// Only for appointments that are over; the others in a selection are
		// skipped and counted in the notice (#2340).
		if ( \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_delete_appointments' ) ) {
			$actions['bulk_delete_permanently'] = __( 'Delete permanently', 'ffcertificate' );
		}
		return $actions;
	}

	/**
	 * ID column.
	 *
	 * @param array<string, mixed> $item Row data.
	 */
	public function column_id( $item ): string {
		return sprintf( '#%d', (int) $item['id'] );
	}

	/**
	 * Actions column (#2486): the row's actions as buttons, always shown,
	 * where they were links that appeared under the ID on hover. The cancel
	 * and delete buttons keep the classes their script binds to.
	 *
	 * @param array<string, mixed> $item Row data.
	 */
	public function column_actions( $item ): string {
		$actions       = array();
		$ffc_page_slug = 'ffc-appointments';
		// 3-state: confirm/cancel are writes — hidden from read-only viewers
		// (the mutation itself is also manage-gated server-side below).
		$ffc_can_edit_appt = \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_appointments' );

		if ( 'pending' === $item['status'] && $ffc_can_edit_appt ) {
			$confirm_url        = wp_nonce_url(
				add_query_arg(
					array(
						'page'        => $ffc_page_slug,
						'ffc_action'  => 'confirm',
						'appointment' => $item['id'],
					),
					admin_url( 'admin.php' )
				),
				'ffc_confirm_appointment_' . $item['id']
			);
			$actions['confirm'] = \FreeFormCertificate\Core\Button::html(
				array(
					'label' => __( 'Confirm', 'ffcertificate' ),
					'icon'  => 'checkmark',
					'href'  => $confirm_url,
				)
			);
		}

		// A waitlisted entry can leave the queue the same way (#1770), and the
		// waitlist promoter promotes nobody when the cancelled row was itself
		// `waitlist`. It is NOT offered Confirm: AppointmentAdminActions confirms
		// only a pending row (#2244), so promotion is the only way out of the
		// queue into the slot.
		if ( $ffc_can_edit_appt && in_array( $item['status'], array( 'pending', 'confirmed', 'waitlist' ), true ) ) {
			$cancel_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'        => $ffc_page_slug,
						'ffc_action'  => 'cancel',
						'appointment' => $item['id'],
					),
					admin_url( 'admin.php' )
				),
				'ffc_cancel_appointment_' . $item['id']
			);
			// Caution, not danger: a cancellation changes the status and keeps the record.
			$actions['cancel'] = \FreeFormCertificate\Core\Button::html(
				array(
					'label'    => __( 'Cancel', 'ffcertificate' ),
					'category' => 'caution',
					'icon'     => 'cross',
					'href'     => '#',
					'class'    => 'ffc-appointment-cancel',
					'attrs'    => array(
						'data-cancel-url'    => $cancel_url,
						// The reason is asked in the plugin's dialog (#2535).
						'data-title'         => __( 'Cancel appointment', 'ffcertificate' ),
						'data-question'      => __( 'Cancel this appointment? The record stays, marked as cancelled.', 'ffcertificate' ),
						'data-confirm-label' => __( 'Cancel appointment', 'ffcertificate' ),
						'data-prompt'        => __( 'Cancellation reason', 'ffcertificate' ),
						'data-hint'          => __( 'At least 5 characters.', 'ffcertificate' ),
					),
				)
			);
		}

		// Attendance (completed / no-show) is marked on a confirmed appointment
		// from its date on, and either mark can be undone back to confirmed.
		$attendance_actions = array();
		$appointment_date   = (string) ( $item['appointment_date'] ?? '' );
		if ( $ffc_can_edit_appt && 'confirmed' === $item['status'] && '' !== $appointment_date && $appointment_date <= wp_date( 'Y-m-d' ) ) {
			$attendance_actions = array(
				'attended' => array( __( 'Attended', 'ffcertificate' ), 'neutral', 'user-check' ),
				'no_show'  => array( __( 'No-show', 'ffcertificate' ), 'neutral', 'user-minus' ),
			);
		} elseif ( $ffc_can_edit_appt && in_array( $item['status'], \FreeFormCertificate\Repositories\AppointmentWriter::ATTENDANCE_STATUSES, true ) ) {
			// Undoing returns the row to confirmed: a status change, so caution.
			$attendance_actions = array( 'undo_attendance' => array( __( 'Undo attendance', 'ffcertificate' ), 'caution', 'restore' ) );
		}
		foreach ( $attendance_actions as $attendance_action => $attendance_button ) {
			$attendance_url                = wp_nonce_url(
				add_query_arg(
					array(
						'page'        => $ffc_page_slug,
						'ffc_action'  => $attendance_action,
						'appointment' => $item['id'],
					),
					admin_url( 'admin.php' )
				),
				'ffc_' . $attendance_action . '_appointment_' . $item['id']
			);
			$actions[ $attendance_action ] = \FreeFormCertificate\Core\Button::html(
				array(
					'label'    => $attendance_button[0],
					'category' => $attendance_button[1],
					'icon'     => $attendance_button[2],
					'href'     => $attendance_url,
				)
			);
		}

		// Delete permanently (#2340): an appointment that is over, for the
		// holders of the delete capability. The script asks first.
		if ( \FreeFormCertificate\SelfScheduling\AppointmentAdminActions::is_deletable( $item )
			&& \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_delete_appointments' ) ) {
			$delete_url        = wp_nonce_url(
				add_query_arg(
					array(
						'page'        => $ffc_page_slug,
						'ffc_action'  => 'delete_permanently',
						'appointment' => $item['id'],
					),
					admin_url( 'admin.php' )
				),
				'ffc_delete_permanently_appointment_' . $item['id']
			);
			$actions['delete'] = \FreeFormCertificate\Core\Button::html(
				array(
					'label'    => __( 'Delete permanently', 'ffcertificate' ),
					'category' => 'danger',
					'icon'     => 'delete',
					'href'     => $delete_url,
					'class'    => 'ffc-appointment-delete',
				)
			);
		}

		// View link: uses just appointment=X (no action parameter to avoid admin_action_view dispatch).
		// The detail view is manage-gated (views/appointments-list.php), so a
		// read-only viewer is not offered a link it would be refused (#1769).
		if ( $ffc_can_edit_appt ) {
			$view_url        = add_query_arg(
				array(
					'page'        => $ffc_page_slug,
					'appointment' => $item['id'],
				),
				admin_url( 'admin.php' )
			);
			$actions['view'] = \FreeFormCertificate\Core\Button::html(
				array(
					'label' => __( 'View', 'ffcertificate' ),
					'icon'  => 'eye',
					'href'  => $view_url,
				)
			);
		}

		// Receipt link — not for cancelled appointments. It never carries the
		// confirmation token (#2244): the token opens the person's own receipt
		// unmasked and cancels the appointment as a guest, so staff get the
		// bare link, which the receipt handler renders masked for them.
		$item_status = $item['status'] ?? 'pending';
		if ( 'cancelled' !== $item_status ) {
			$receipt_url        = \FreeFormCertificate\SelfScheduling\AppointmentReceiptHandler::get_receipt_url( (int) $item['id'] );
			$actions['receipt'] = \FreeFormCertificate\Core\Button::html(
				array(
					'label' => __( 'View Receipt', 'ffcertificate' ),
					'icon'  => 'file',
					'href'  => $receipt_url,
					'attrs' => array( 'target' => '_blank' ),
				)
			);
		}

		// The order a reader scans: open it, then the day's work, then the
		// destructive one last.
		$order  = array( 'view', 'receipt', 'confirm', 'attended', 'no_show', 'undo_attendance', 'cancel', 'delete' );
		$sorted = array();
		foreach ( $order as $key ) {
			if ( isset( $actions[ $key ] ) ) {
				$sorted[] = $actions[ $key ];
			}
		}

		return '<div class="ffc-row-buttons">' . implode( '', $sorted ) . '</div>';
	}

	/**
	 * Calendar column
	 *
	 * @param array<string, mixed> $item Row data.
	 */
	public function column_calendar( $item ): string {
		$calendar = $this->calendar_repository->findById( (int) $item['calendar_id'] );
		if ( $calendar ) {
			$edit_url = admin_url( 'post.php?post=' . $calendar['post_id'] . '&action=edit' );
			$html     = sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html( $calendar['title'] ) );
			// The session, under its calendar (#2405).
			$session = CalendarSessions::name_of_appointment( $calendar, $item );
			if ( '' !== $session ) {
				$html .= '<br><span class="description">' . esc_html( $session ) . '</span>';
			}
			return $html;
		}
		return __( '(Deleted)', 'ffcertificate' );
	}

	/**
	 * Name column
	 *
	 * @param array<string, mixed> $item Row data.
	 */
	public function column_name( $item ): string {
		if ( ! empty( $item['user_id'] ) ) {
			$user = get_user_by( 'id', $item['user_id'] );
			if ( $user ) {
				return esc_html( $user->display_name );
			}
		}
		return esc_html( $item['name'] ?? __( '(Guest)', 'ffcertificate' ) );
	}

	/**
	 * Email column (with decryption support).
	 *
	 * The address is masked for every viewer, as on the appointment detail
	 * view (#1822): whoever may reveal it (the owner and the reveal tier,
	 * #1863) gets a "Reveal" button that fetches the
	 * clear value through the audited ffc_reveal_pii endpoint, so the
	 * plaintext never sits in the list HTML.
	 *
	 * @param array<string, mixed> $item Row data.
	 */
	public function column_email( $item ): string {
		$email = \FreeFormCertificate\Core\Encryption::decrypt_field( $item, 'email' );
		if ( ! $email ) {
			return '-';
		}

		$html = '<span class="ffc-pii-value" data-field="email">'
			. esc_html( \FreeFormCertificate\Core\DocumentFormatter::mask_email( (string) $email ) )
			. '</span>';

		$can_reveal = \FreeFormCertificate\Core\PiiAccessPolicy::can_reveal(
			'ffc_view_appointments_pii',
			isset( $item['user_id'] ) ? (int) $item['user_id'] : null
		);
		if ( ! $can_reveal ) {
			return $html;
		}

		return $html . ' ' . \FreeFormCertificate\Core\PiiReveal::field_button(
			array(
				'type'          => 'appointment',
				'field'         => 'email',
				'submission-id' => (string) ( $item['id'] ?? 0 ),
				'nonce'         => $this->reveal_nonce(),
			)
		);
	}

	/**
	 * The ffc_reveal_pii nonce the list's reveal buttons carry, created once
	 * per render rather than once per row.
	 *
	 * @return string
	 */
	private function reveal_nonce(): string {
		if ( null === $this->reveal_nonce ) {
			$this->reveal_nonce = wp_create_nonce( 'ffc_reveal_pii_nonce' );
		}
		return $this->reveal_nonce;
	}

	/**
	 * Date column, in the site's date format (#2288). The date is wall-clock
	 * (Category B), so no timezone is applied.
	 *
	 * @param array<string, mixed> $item Row data.
	 */
	public function column_appointment_date( $item ): string {
		$date = \FreeFormCertificate\Core\DateFormatter::format_wallclock_date( (string) ( $item['appointment_date'] ?? '' ) );
		return esc_html( '' !== $date ? $date : '-' );
	}

	/**
	 * Created column, in the site's date and time formats (#2288). The value
	 * is a housekeeping DATETIME shown as stored, so no timezone is applied.
	 *
	 * @param array<string, mixed> $item Row data.
	 */
	public function column_created_at( $item ): string {
		$created = \FreeFormCertificate\Core\DateFormatter::format_datetime( (string) ( $item['created_at'] ?? '' ), 'default', ' ', new \DateTimeZone( 'UTC' ) );
		return esc_html( '' !== $created ? $created : '-' );
	}

	/**
	 * Time column
	 *
	 * @param array<string, mixed> $item Row data.
	 */
	public function column_time( $item ): string {
		$start = \FreeFormCertificate\Core\DateFormatter::format_wallclock_time( (string) $item['start_time'] );
		$end   = \FreeFormCertificate\Core\DateFormatter::format_wallclock_time( (string) $item['end_time'] );
		return esc_html( $start . ' - ' . $end );
	}

	/**
	 * Status column
	 *
	 * @param array<string, mixed> $item Row data.
	 */
	public function column_status( $item ): string {
		$status_labels = array(
			'pending'   => '<span class="ffc-appointment-status ffc-appointment-status-pending">' . __( 'Pending', 'ffcertificate' ) . '</span>',
			'confirmed' => '<span class="ffc-appointment-status ffc-appointment-status-confirmed">' . __( 'Confirmed', 'ffcertificate' ) . '</span>',
			'cancelled' => '<span class="ffc-appointment-status ffc-appointment-status-cancelled">' . __( 'Cancelled', 'ffcertificate' ) . '</span>',
			'completed' => '<span class="ffc-appointment-status ffc-appointment-status-completed">' . __( 'Completed', 'ffcertificate' ) . '</span>',
			'no_show'   => '<span class="ffc-appointment-status ffc-appointment-status-noshow">' . __( 'No Show', 'ffcertificate' ) . '</span>',
			'waitlist'  => '<span class="ffc-appointment-status ffc-appointment-status-waitlist">' . __( 'Waitlist', 'ffcertificate' ) . '</span>',
		);

		return $status_labels[ $item['status'] ] ?? esc_html( $item['status'] );
	}

	/**
	 * Prepare items
	 */
	public function prepare_items(): void {
		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		$filters                  = self::request_filters();
		list( $order_by, $order ) = $this->resolve_order();

		$items       = $this->appointment_repository->findForList( $filters, $order_by, $order, $per_page, $offset );
		$total_items = $this->appointment_repository->countForList( $filters );

		$this->items = $items;

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			)
		);

		$this->_column_headers = array(
			$this->get_columns(),
			array(), // Hidden columns.
			$this->get_sortable_columns(),
		);
	}

	/**
	 * The list's filters as the request carries them (#2244), shared with the
	 * Export CSV button so the file holds what the screen shows.
	 *
	 * A date that is not `Y-m-d`, or a status the list does not offer, is
	 * dropped rather than passed on.
	 *
	 * @return array{calendar_id: int, status: string, start_date: string, end_date: string, search: string}
	 */
	public static function request_filters(): array {
		$date   = static function ( string $value ): string {
			return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
		};
		$status = RequestInput::get_get_string( 'status' );

		return array(
			'calendar_id' => RequestInput::get_get_int( 'calendar_id' ),
			'status'      => in_array( $status, array( 'pending', 'confirmed', 'cancelled', 'completed', 'no_show', 'waitlist' ), true ) ? $status : '',
			'start_date'  => $date( RequestInput::get_get_string( 'date_from' ) ),
			'end_date'    => $date( RequestInput::get_get_string( 'date_to' ) ),
			'search'      => RequestInput::get_get_string( 's' ),
		);
	}

	/**
	 * The ORDER BY column and direction the request asks for (#1770).
	 *
	 * `orderby` is resolved through `ORDER_COLUMNS`; anything else — absent,
	 * unknown, or a column name the list does not sort by — gives the default:
	 * the appointment date, newest first, which the repository breaks on the
	 * start time (#2288). The direction collapses to `ASC` / `DESC`.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function resolve_order(): array {
		$requested = RequestInput::get_get_string( 'orderby' );
		if ( ! isset( self::ORDER_COLUMNS[ $requested ] ) ) {
			return array( 'appointment_date', 'DESC' );
		}

		$order = 'asc' === strtolower( RequestInput::get_get_string( 'order' ) ) ? 'ASC' : 'DESC';
		return array( self::ORDER_COLUMNS[ $requested ], $order );
	}

	/**
	 * The bulk bar's extra control (#2486): "Reveal this page", beside Apply.
	 *
	 * The filters left this bar for their own card, {@see self::render_filters()}.
	 *
	 * @param mixed $which Top or bottom.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		$reveal = \FreeFormCertificate\Core\PiiReveal::page_button( 'ffc_view_appointments_pii' );
		if ( '' !== $reveal ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- page_button() escapes every value.
			echo '<div class="alignleft actions">' . $reveal . '</div>';
		}
	}

	/**
	 * The filter card (#2486): calendar, status, period and search, each with
	 * its label, then Filter, Today and Clear.
	 */
	public function render_filters(): void {
		$filters     = self::request_filters();
		$calendar_id = $filters['calendar_id'];
		$status      = $filters['status'];
		$today       = wp_date( 'Y-m-d' );

		// Get all calendars for filter.
		$calendars = $this->calendar_repository->getActiveCalendars();

		$statuses = array(
			'pending'   => __( 'Pending', 'ffcertificate' ),
			'confirmed' => __( 'Confirmed', 'ffcertificate' ),
			'cancelled' => __( 'Cancelled', 'ffcertificate' ),
			'completed' => __( 'Completed', 'ffcertificate' ),
			'no_show'   => __( 'No Show', 'ffcertificate' ),
			'waitlist'  => __( 'Waitlist', 'ffcertificate' ),
		);
		$filtered = $calendar_id > 0 || '' !== $status || '' !== $filters['start_date'] || '' !== $filters['end_date'] || '' !== $filters['search'];
		?>
	<div class="ffc-list-filters">
		<div class="ffc-list-filters__field">
			<label class="ffc-list-filters__label" for="ffc-appointments-calendar"><?php esc_html_e( 'Calendar', 'ffcertificate' ); ?></label>
			<select name="calendar_id" id="ffc-appointments-calendar">
				<option value=""><?php esc_html_e( 'All Calendars', 'ffcertificate' ); ?></option>
				<?php foreach ( $calendars as $calendar ) : ?>
					<option value="<?php echo esc_attr( $calendar['id'] ); ?>" <?php selected( $calendar_id, $calendar['id'] ); ?>>
						<?php echo esc_html( $calendar['title'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="ffc-list-filters__field">
			<label class="ffc-list-filters__label" for="ffc-appointments-status"><?php esc_html_e( 'Status', 'ffcertificate' ); ?></label>
			<select name="status" id="ffc-appointments-status">
				<option value=""><?php esc_html_e( 'All Statuses', 'ffcertificate' ); ?></option>
				<?php foreach ( $statuses as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="ffc-list-filters__field">
			<label class="ffc-list-filters__label" for="ffc-appointments-from"><?php esc_html_e( 'Period', 'ffcertificate' ); ?></label>
			<span class="ffc-list-filters__range">
				<input type="date" name="date_from" id="ffc-appointments-from" value="<?php echo esc_attr( $filters['start_date'] ); ?>" aria-label="<?php esc_attr_e( 'From', 'ffcertificate' ); ?>">
				<span aria-hidden="true">&ndash;</span>
				<input type="date" name="date_to" value="<?php echo esc_attr( $filters['end_date'] ); ?>" aria-label="<?php esc_attr_e( 'To', 'ffcertificate' ); ?>">
			</span>
		</div>

		<div class="ffc-list-filters__field">
			<label class="ffc-list-filters__label" for="ffc-appointments-search"><?php esc_html_e( 'Search', 'ffcertificate' ); ?></label>
			<input type="search" name="s" id="ffc-appointments-search" class="regular-text" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Name, e-mail, CPF/RF or ID', 'ffcertificate' ); ?>">
		</div>

		<div class="ffc-list-filters__actions">
		<?php
		\FreeFormCertificate\Admin\AdminUI::render_button(
			array(
				'label' => __( 'Filter', 'ffcertificate' ),
				'icon'  => 'filter',
				'type'  => 'submit',
				'name'  => 'filter_action',
				'value' => __( 'Filter', 'ffcertificate' ),
			)
		);
		// Today's agenda: one click to the day, keeping the calendar chosen.
		\FreeFormCertificate\Admin\AdminUI::render_button(
			array(
				'label' => __( 'Today', 'ffcertificate' ),
				'icon'  => 'calendar',
				'href'  => add_query_arg(
					array(
						'page'        => 'ffc-appointments',
						'calendar_id' => $calendar_id > 0 ? $calendar_id : false,
						'date_from'   => $today,
						'date_to'     => $today,
						'orderby'     => 'appointment_date',
						'order'       => 'asc',
					),
					admin_url( 'admin.php' )
				),
			)
		);
		if ( $filtered ) {
			\FreeFormCertificate\Admin\AdminUI::render_button(
				array(
					'label' => __( 'Clear', 'ffcertificate' ),
					'icon'  => 'eraser',
					'href'  => add_query_arg( array( 'page' => 'ffc-appointments' ), admin_url( 'admin.php' ) ),
				)
			);
		}
		?>
		</div>
	</div>
		<?php
	}
}

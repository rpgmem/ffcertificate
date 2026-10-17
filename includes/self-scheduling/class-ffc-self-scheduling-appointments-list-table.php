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
	 * ID column
	 *
	 * @param array<string, mixed> $item Row data.
	 */
	public function column_id( $item ): string {
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
			$actions['confirm'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $confirm_url ),
				__( 'Confirm', 'ffcertificate' )
			);
		}

		// A waitlisted entry can leave the queue the same way (#1770): the cancel
		// handler accepts any status, and the waitlist promoter promotes nobody
		// when the cancelled row was itself `waitlist`. It is NOT offered Confirm:
		// `confirm()` does not recount the slot, so confirming a queued entry would
		// overbook it — promotion is the only way out of the queue into the slot.
		if ( $ffc_can_edit_appt && in_array( $item['status'], array( 'pending', 'confirmed', 'waitlist' ), true ) ) {
			$cancel_url        = wp_nonce_url(
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
			$actions['cancel'] = sprintf(
				'<a href="#" class="ffc-appointment-cancel ffc-delete-link" data-cancel-url="%s" data-prompt="%s">%s</a>',
				esc_url( $cancel_url ),
				esc_attr__( 'Please provide a reason for cancellation (minimum 5 characters):', 'ffcertificate' ),
				esc_html__( 'Cancel', 'ffcertificate' )
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
			$actions['view'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $view_url ),
				__( 'View', 'ffcertificate' )
			);
		}

		// Add receipt link (magic link to /valid/ page) - not for cancelled appointments.
		$item_status = $item['status'] ?? 'pending';
		if ( 'cancelled' !== $item_status ) {
			$confirmation_token = $item['confirmation_token'] ?? '';
			if ( ! empty( $confirmation_token ) && class_exists( '\\FreeFormCertificate\\Generators\\MagicLinkHelper' ) ) {
				$receipt_url = \FreeFormCertificate\Generators\MagicLinkHelper::generate_magic_link( $confirmation_token );
			} else {
				$receipt_url = \FreeFormCertificate\SelfScheduling\AppointmentReceiptHandler::get_receipt_url(
					(int) $item['id'],
					$confirmation_token
				);
			}
			$actions['receipt'] = sprintf(
				'<a href="%s" target="_blank">%s</a>',
				esc_url( $receipt_url ),
				__( 'View Receipt', 'ffcertificate' )
			);
		}

		return sprintf( '#%d %s', $item['id'], $this->row_actions( $actions ) );
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
			return sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html( $calendar['title'] ) );
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

		return $html . sprintf(
			' <button type="button" class="button button-small ffc-reveal-pii ffc-icon-eye" data-type="appointment" data-field="email" data-submission-id="%1$s" data-nonce="%2$s">%3$s</button>',
			esc_attr( (string) ( $item['id'] ?? 0 ) ),
			esc_attr( $this->reveal_nonce() ),
			esc_html__( 'Reveal', 'ffcertificate' )
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

		// Get filter parameters.
		$calendar_id = RequestInput::get_get_int( 'calendar_id' );
		$status      = \FreeFormCertificate\Core\RequestInput::get_get_string( 'status' );

		// Build conditions.
		$conditions = array();
		if ( $calendar_id ) {
			$conditions['calendar_id'] = $calendar_id;
		}
		if ( $status ) {
			$conditions['status'] = $status;
		}

		// Get items.
		list( $order_by, $order ) = $this->resolve_order();

		$items       = $this->appointment_repository->findAll( $conditions, $order_by, $order, $per_page, $offset );
		$total_items = $this->appointment_repository->count( $conditions );

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
	 * The ORDER BY column and direction the request asks for (#1770).
	 *
	 * `orderby` is resolved through `ORDER_COLUMNS`; anything else — absent,
	 * unknown, or a column name the list does not sort by — gives the default
	 * `created_at DESC`. The direction collapses to `ASC` / `DESC`.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function resolve_order(): array {
		$requested = RequestInput::get_get_string( 'orderby' );
		if ( ! isset( self::ORDER_COLUMNS[ $requested ] ) ) {
			return array( 'created_at', 'DESC' );
		}

		$order = 'asc' === strtolower( RequestInput::get_get_string( 'order' ) ) ? 'ASC' : 'DESC';
		return array( self::ORDER_COLUMNS[ $requested ], $order );
	}

	/**
	 * Display filters
	 *
	 * @param mixed $which Which.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$calendar_id = RequestInput::get_get_int( 'calendar_id' );
		$status      = \FreeFormCertificate\Core\RequestInput::get_get_string( 'status' );

		// Get all calendars for filter.
		$calendars = $this->calendar_repository->getActiveCalendars();

		?>
	<div class="alignleft actions">
		<select name="calendar_id">
			<option value=""><?php esc_html_e( 'All Calendars', 'ffcertificate' ); ?></option>
			<?php foreach ( $calendars as $calendar ) : ?>
				<option value="<?php echo esc_attr( $calendar['id'] ); ?>" <?php selected( $calendar_id, $calendar['id'] ); ?>>
					<?php echo esc_html( $calendar['title'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<select name="status">
			<option value=""><?php esc_html_e( 'All Statuses', 'ffcertificate' ); ?></option>
			<option value="pending" <?php selected( $status, 'pending' ); ?>><?php esc_html_e( 'Pending', 'ffcertificate' ); ?></option>
			<option value="confirmed" <?php selected( $status, 'confirmed' ); ?>><?php esc_html_e( 'Confirmed', 'ffcertificate' ); ?></option>
			<option value="cancelled" <?php selected( $status, 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'ffcertificate' ); ?></option>
			<option value="completed" <?php selected( $status, 'completed' ); ?>><?php esc_html_e( 'Completed', 'ffcertificate' ); ?></option>
			<option value="no_show" <?php selected( $status, 'no_show' ); ?>><?php esc_html_e( 'No Show', 'ffcertificate' ); ?></option>
			<option value="waitlist" <?php selected( $status, 'waitlist' ); ?>><?php esc_html_e( 'Waitlist', 'ffcertificate' ); ?></option>
		</select>

		<?php submit_button( __( 'Filter', 'ffcertificate' ), '', 'filter_action', false ); ?>
	</div>
		<?php
	}
}

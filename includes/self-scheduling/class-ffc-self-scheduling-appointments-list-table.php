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
		$ffc_can_edit_appt = \FreeFormCertificate\Core\Utils::current_user_can_admin_or( 'ffc_manage_appointments' );

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

		if ( $ffc_can_edit_appt && in_array( $item['status'], array( 'pending', 'confirmed' ), true ) ) {
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
				'<a href="#" class="ffc-appointment-cancel delete-link" data-cancel-url="%s" data-prompt="%s">%s</a>',
				esc_url( $cancel_url ),
				esc_attr__( 'Please provide a reason for cancellation (minimum 5 characters):', 'ffcertificate' ),
				esc_html__( 'Cancel', 'ffcertificate' )
			);
		}

		// View link: uses just appointment=X (no action parameter to avoid admin_action_view dispatch).
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
	 * Email column (with decryption support)
	 *
	 * @param array<string, mixed> $item Row data.
	 */
	public function column_email( $item ): string {
		$email = \FreeFormCertificate\Core\Encryption::decrypt_field( $item, 'email' );
		return $email ? esc_html( $email ) : '-';
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
			'pending'   => '<span class="ffc-status ffc-status-pending">' . __( 'Pending', 'ffcertificate' ) . '</span>',
			'confirmed' => '<span class="ffc-status ffc-status-confirmed">' . __( 'Confirmed', 'ffcertificate' ) . '</span>',
			'cancelled' => '<span class="ffc-status ffc-status-cancelled">' . __( 'Cancelled', 'ffcertificate' ) . '</span>',
			'completed' => '<span class="ffc-status ffc-status-completed">' . __( 'Completed', 'ffcertificate' ) . '</span>',
			'no_show'   => '<span class="ffc-status ffc-status-noshow">' . __( 'No Show', 'ffcertificate' ) . '</span>',
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
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Standard WP_List_Table filter parameters.
		$calendar_id = isset( $_GET['calendar_id'] ) ? absint( wp_unslash( $_GET['calendar_id'] ) ) : 0;
		$status      = \FreeFormCertificate\Core\Utils::get_get_string( 'status' );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Build conditions.
		$conditions = array();
		if ( $calendar_id ) {
			$conditions['calendar_id'] = $calendar_id;
		}
		if ( $status ) {
			$conditions['status'] = $status;
		}

		// Get items.
		$items       = $this->appointment_repository->findAll( $conditions, 'created_at', 'DESC', $per_page, $offset );
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
	 * Display filters
	 *
	 * @param mixed $which Which.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display filter parameters for dropdown selection.
		$calendar_id = isset( $_GET['calendar_id'] ) ? absint( wp_unslash( $_GET['calendar_id'] ) ) : 0;
		$status      = \FreeFormCertificate\Core\Utils::get_get_string( 'status' );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

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
		</select>

		<?php submit_button( __( 'Filter', 'ffcertificate' ), '', 'filter_action', false ); ?>
	</div>
		<?php
	}
}

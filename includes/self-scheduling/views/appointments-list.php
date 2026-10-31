<?php
/**
 * Appointments List View
 *
 * Displays all appointments with filters and export options.
 *
 * @package FreeFormCertificate\SelfScheduling\Views
 * @since 4.1.0
 * @version 5.0.0 - Fixed URLs to use absolute paths and removed action=view to prevent 500 errors
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The list-table class now lives in its own autoloaded file
// (FreeFormCertificate\SelfScheduling\AppointmentsListTable) — it is
// instantiated near the bottom of this view.


// Appointments base URL (used for redirects and back links).
$ffcertificate_appointments_url = add_query_arg( array( 'page' => 'ffc-appointments' ), admin_url( 'admin.php' ) );

// Determine if viewing a specific appointment:
// - appointment=X alone → view.
// - appointment=X + ffc_action=confirm|cancel|attended|no_show|undo_attendance → mutation.
$ffc_self_scheduling_appointment_id = \FreeFormCertificate\Core\RequestInput::get_get_int( 'appointment' );
$ffcertificate_action               = \FreeFormCertificate\Core\RequestInput::get_get_string( 'ffc_action' );

// Close old appointments on demand (#2285) — same run as the daily cron.
if ( 'close_old' === $ffcertificate_action ) {
	if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_appointments' ) ) {
		wp_die( esc_html__( 'You do not have permission to perform this action.', 'ffcertificate' ) );
	}
	check_admin_referer( 'ffc_close_old_appointments' );

	$ffcertificate_closed = \FreeFormCertificate\SelfScheduling\AppointmentCloseout::run( get_current_user_id() );
	set_transient(
		'ffc_admin_notice_' . get_current_user_id(),
		$ffcertificate_closed['failed']
			? array(
				'type'    => 'error',
				'message' => __( 'The close-out stopped on a database error. Appointments changed before it stay changed; run it again.', 'ffcertificate' ),
			)
			: array(
				'type'    => 'success',
				'message' => sprintf(
					/* translators: 1: appointments marked completed, 2: appointments cancelled */
					__( 'Old appointments closed: %1$d marked completed, %2$d pending cancelled.', 'ffcertificate' ),
					$ffcertificate_closed['completed'],
					$ffcertificate_closed['cancelled']
				),
			),
		30
	);
	wp_safe_redirect( $ffcertificate_appointments_url );
	exit;
}

if ( $ffc_self_scheduling_appointment_id > 0 ) {

	// Verify user has admin permissions.
	if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_appointments' ) ) {
		wp_die( esc_html__( 'You do not have permission to perform this action.', 'ffcertificate' ) );
	}

	$ffcertificate_repo = new \FreeFormCertificate\Repositories\AppointmentRepository();

	// Handle mutations (confirm/cancel/attendance) — these redirect and exit.
	// Confirm and Cancel decide in AppointmentAdminActions (#2244); the view
	// only checks the nonce and reports.
	if ( in_array( $ffcertificate_action, array( 'confirm', 'cancel' ), true ) ) {
		check_admin_referer( 'ffc_' . $ffcertificate_action . '_appointment_' . $ffc_self_scheduling_appointment_id );
		$ffcertificate_actions = new \FreeFormCertificate\SelfScheduling\AppointmentAdminActions( $ffcertificate_repo );
		if ( 'confirm' === $ffcertificate_action ) {
			$ffcertificate_result = $ffcertificate_actions->confirm( $ffc_self_scheduling_appointment_id, get_current_user_id() );
			$ffcertificate_done   = __( 'Appointment confirmed successfully.', 'ffcertificate' );
		} else {
			$ffcertificate_cancel_reason = isset( $_GET['reason'] ) ? sanitize_textarea_field( wp_unslash( $_GET['reason'] ) ) : '';
			if ( '' === trim( $ffcertificate_cancel_reason ) ) {
				$ffcertificate_cancel_reason = __( 'Cancelled by admin', 'ffcertificate' );
			}
			$ffcertificate_result = $ffcertificate_actions->cancel( $ffc_self_scheduling_appointment_id, get_current_user_id(), $ffcertificate_cancel_reason );
			$ffcertificate_done   = __( 'Appointment cancelled successfully.', 'ffcertificate' );
		}

		set_transient(
			'ffc_admin_notice_' . get_current_user_id(),
			is_wp_error( $ffcertificate_result )
				? array(
					'type'    => 'error',
					'message' => $ffcertificate_result->get_error_message(),
				)
				: array(
					'type'    => 'success',
					'message' => $ffcertificate_done,
				),
			30
		);

		wp_safe_redirect( $ffcertificate_appointments_url );
		exit;

	} elseif ( in_array( $ffcertificate_action, array( 'attended', 'no_show', 'undo_attendance' ), true ) ) {
		check_admin_referer( 'ffc_' . $ffcertificate_action . '_appointment_' . $ffc_self_scheduling_appointment_id );
		$ffcertificate_appointment = $ffcertificate_repo->findById( $ffc_self_scheduling_appointment_id );
		$ffcertificate_status      = is_array( $ffcertificate_appointment ) ? (string) ( $ffcertificate_appointment['status'] ?? '' ) : '';
		$ffcertificate_notice      = array(
			'type'    => 'error',
			'message' => __( 'Failed to update the appointment status. It may have changed meanwhile; reload the list.', 'ffcertificate' ),
		);

		if ( 'undo_attendance' === $ffcertificate_action ) {
			if ( $ffcertificate_repo->revertAttendance( $ffc_self_scheduling_appointment_id, $ffcertificate_status ) ) {
				$ffcertificate_notice = array(
					'type'    => 'success',
					'message' => __( 'Attendance mark undone. The appointment is confirmed again.', 'ffcertificate' ),
				);
			}
		} elseif ( ! is_array( $ffcertificate_appointment ) ) {
			$ffcertificate_notice['message'] = __( 'Appointment not found.', 'ffcertificate' );
		} elseif ( '' === (string) ( $ffcertificate_appointment['appointment_date'] ?? '' ) || (string) $ffcertificate_appointment['appointment_date'] > wp_date( 'Y-m-d' ) ) {
			// Attendance is a fact about a day that has come; the date is
			// wall-clock, so it is compared with today in the site timezone.
			$ffcertificate_notice['message'] = __( 'Attendance can only be marked from the appointment date on.', 'ffcertificate' );
		} else {
			$ffcertificate_result = 'attended' === $ffcertificate_action
				? $ffcertificate_repo->markCompleted( $ffc_self_scheduling_appointment_id )
				: $ffcertificate_repo->markNoShow( $ffc_self_scheduling_appointment_id );
			if ( $ffcertificate_result ) {
				$ffcertificate_notice = array(
					'type'    => 'success',
					'message' => 'attended' === $ffcertificate_action
						? __( 'Appointment marked as attended.', 'ffcertificate' )
						: __( 'Appointment marked as no-show.', 'ffcertificate' ),
				);
			}
		}

		set_transient( 'ffc_admin_notice_' . get_current_user_id(), $ffcertificate_notice, 30 );
		wp_safe_redirect( $ffcertificate_appointments_url );
		exit;

	} else {
		// Default: View appointment detail.
		try {
			$ffcertificate_appointment = $ffcertificate_repo->findById( $ffc_self_scheduling_appointment_id );
			if ( ! $ffcertificate_appointment ) {
				echo '<div class="wrap ffc-admin-page ffc-page-appointments">';
				\FreeFormCertificate\Core\AdminNotice::render(
					'error',
					esc_html__( 'Appointment not found.', 'ffcertificate' )
				);
				echo '</div>';
				return;
			}

			// Get calendar info.
			$ffcertificate_cal_repo       = new \FreeFormCertificate\Repositories\CalendarRepository();
			$ffcertificate_calendar       = ! empty( $ffcertificate_appointment['calendar_id'] )
				? $ffcertificate_cal_repo->findById( (int) $ffcertificate_appointment['calendar_id'] )
				: null;
			$ffcertificate_calendar_title = $ffcertificate_calendar ? $ffcertificate_calendar['title'] : __( '(Deleted)', 'ffcertificate' );

			// Decrypt sensitive fields.
			$ffcertificate_decrypted = $ffcertificate_appointment;
			if ( class_exists( '\\FreeFormCertificate\\Core\\Encryption' ) ) {
				try {
					$ffcertificate_decrypted = \FreeFormCertificate\Core\Encryption::decrypt_appointment( $ffcertificate_appointment );
				} catch ( \Throwable $decrypt_err ) {
					unset( $decrypt_err ); // Decryption failed — continue with raw (still-encrypted) data.
				}
			}

			// Resolve display values.
			$ffcertificate_email = $ffcertificate_decrypted['email'] ?? '';
			$ffcertificate_phone = $ffcertificate_decrypted['phone'] ?? '';
			$ffcertificate_cpf   = $ffcertificate_decrypted['cpf'] ?? '';
			$ffcertificate_rf    = $ffcertificate_decrypted['rf'] ?? '';

			// #739 §3.3 / #1813 / #1863 — CPF / RF / email start masked for every
			// viewer, as on the submission edit page (#1760). Whoever may reveal
			// (the owner and the reveal tier) gets a button
			// that fetches the clear value through the ffc_reveal_pii endpoint,
			// so the plaintext never sits in this screen's HTML.
			$ffcertificate_can_reveal   = \FreeFormCertificate\Core\PiiAccessPolicy::can_reveal(
				'ffc_view_appointments_pii',
				(int) ( $ffcertificate_appointment['user_id'] ?? 0 )
			);
			$ffcertificate_reveal_nonce = $ffcertificate_can_reveal ? wp_create_nonce( 'ffc_reveal_pii_nonce' ) : '';

			/**
			 * Render one PII cell: a dash when empty, otherwise the masked value
			 * with a "Reveal" button when the viewer may reveal it.
			 *
			 * @param string $field          Field key (cpf | rf | email | phone).
			 * @param string $raw            Raw decrypted value (empty ⇒ dash).
			 * @param string $masked_display Masked placeholder shown to every tier.
			 * @return void
			 */
			$ffcertificate_render_pii = function ( string $field, string $raw, string $masked_display ) use ( $ffcertificate_can_reveal, $ffc_self_scheduling_appointment_id, $ffcertificate_reveal_nonce ) {
				if ( '' === $raw ) {
					echo '-';
					return;
				}
				echo '<span class="ffc-pii-value" data-field="' . esc_attr( $field ) . '">' . esc_html( $masked_display ) . '</span>';
				if ( $ffcertificate_can_reveal ) {
					$ffcertificate_reveal_button = \FreeFormCertificate\Core\PiiReveal::field_button(
						array(
							'type'          => 'appointment',
							'field'         => $field,
							'submission-id' => (string) $ffc_self_scheduling_appointment_id,
							'nonce'         => $ffcertificate_reveal_nonce,
						)
					);
					echo ' ' . $ffcertificate_reveal_button; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- field_button() escapes every value.
				}
			};

			$ffcertificate_name = '';
			if ( ! empty( $ffcertificate_appointment['user_id'] ) ) {
				$ffcertificate_user = get_user_by( 'id', $ffcertificate_appointment['user_id'] );
				if ( $ffcertificate_user ) {
					$ffcertificate_name = $ffcertificate_user->display_name;
				}
			}
			if ( empty( $ffcertificate_name ) ) {
				$ffcertificate_name = $ffcertificate_appointment['name'] ?? __( '(Guest)', 'ffcertificate' );
			}

			// Decode custom data.
			$ffcertificate_custom_data = array();
			if ( ! empty( $ffcertificate_decrypted['custom_data'] ) ) {
				if ( is_array( $ffcertificate_decrypted['custom_data'] ) ) {
					$ffcertificate_custom_data = $ffcertificate_decrypted['custom_data'];
				} else {
					$ffcertificate_decoded_custom = json_decode( $ffcertificate_decrypted['custom_data'], true );
					$ffcertificate_custom_data    = $ffcertificate_decoded_custom ? $ffcertificate_decoded_custom : array();
				}
			} elseif ( ! empty( $ffcertificate_appointment['custom_data'] ) ) {
				$ffcertificate_decoded_appt_custom = json_decode( $ffcertificate_appointment['custom_data'], true );
				$ffcertificate_custom_data         = $ffcertificate_decoded_appt_custom ? $ffcertificate_decoded_appt_custom : array();
			}

			// Status labels.
			$ffcertificate_status_labels = array(
				'pending'   => __( 'Pending', 'ffcertificate' ),
				'confirmed' => __( 'Confirmed', 'ffcertificate' ),
				'cancelled' => __( 'Cancelled', 'ffcertificate' ),
				'completed' => __( 'Completed', 'ffcertificate' ),
				'no_show'   => __( 'No Show', 'ffcertificate' ),
			);
			$ffcertificate_status_text   = $ffcertificate_status_labels[ $ffcertificate_appointment['status'] ] ?? $ffcertificate_appointment['status'];

			// Render detail view.
			?>
			<?php
			// Detail view: the shared boxed sections (`.ffc-boxed`, #1761), each
			// a `.card` headed by its subject's icon, as on every other FFC
			// detail and edit screen. Same rows, same order, same Reveal buttons.
			?>
			<div class="wrap ffc-admin-page ffc-page-appointments ffc-boxed">
				<h1 class="wp-heading-inline">
					<?php
					printf(
						/* translators: %d: appointment ID */
						esc_html__( 'Appointment #%d', 'ffcertificate' ),
						(int) $ffc_self_scheduling_appointment_id
					);
					?>
				</h1>
				<?php
				\FreeFormCertificate\Admin\AdminUI::render_button(
					array(
						'label' => __( 'Back to List', 'ffcertificate' ),
						'icon'  => 'chevron-left',
						'href'  => $ffcertificate_appointments_url,
					)
				);
				?>
				<hr class="wp-header-end">

				<div class="card">
					<h2 class="ffc-icon-calendar"><?php esc_html_e( 'Appointment Details', 'ffcertificate' ); ?></h2>
					<table class="form-table">
						<tr><th><?php esc_html_e( 'Status', 'ffcertificate' ); ?></th><td><span class="ffc-appointment-status ffc-appointment-status-<?php echo esc_attr( 'no_show' === $ffcertificate_appointment['status'] ? 'noshow' : $ffcertificate_appointment['status'] ); ?>"><?php echo esc_html( $ffcertificate_status_text ); ?></span></td></tr>
						<tr><th><?php esc_html_e( 'Calendar', 'ffcertificate' ); ?></th><td><?php echo esc_html( $ffcertificate_calendar_title ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Date', 'ffcertificate' ); ?></th><td><?php echo esc_html( \FreeFormCertificate\Core\DateFormatter::format_wallclock_date( (string) ( $ffcertificate_appointment['appointment_date'] ?? '' ) ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Time', 'ffcertificate' ); ?></th><td><?php echo esc_html( \FreeFormCertificate\Core\DateFormatter::format_wallclock_time( (string) ( $ffcertificate_appointment['start_time'] ?? '' ) ) . ' - ' . \FreeFormCertificate\Core\DateFormatter::format_wallclock_time( (string) ( $ffcertificate_appointment['end_time'] ?? '' ) ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Name', 'ffcertificate' ); ?></th><td><?php echo esc_html( $ffcertificate_name ); ?></td></tr>
						<tr><th><?php esc_html_e( 'E-mail', 'ffcertificate' ); ?></th><td><?php $ffcertificate_render_pii( 'email', (string) $ffcertificate_email, \FreeFormCertificate\Core\DocumentFormatter::mask_email( (string) $ffcertificate_email ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Phone', 'ffcertificate' ); ?></th><td><?php $ffcertificate_render_pii( 'phone', (string) $ffcertificate_phone, \FreeFormCertificate\Core\DocumentFormatter::mask_phone( (string) $ffcertificate_phone ) ); ?></td></tr>
						<?php if ( ! empty( $ffcertificate_cpf ) ) : ?>
						<tr><th><?php esc_html_e( 'CPF', 'ffcertificate' ); ?></th><td><?php $ffcertificate_render_pii( 'cpf', (string) $ffcertificate_cpf, \FreeFormCertificate\Core\DocumentFormatter::mask_cpf( $ffcertificate_cpf ) ); ?></td></tr>
						<?php endif; ?>
						<?php if ( ! empty( $ffcertificate_rf ) ) : ?>
						<tr><th><?php esc_html_e( 'RF', 'ffcertificate' ); ?></th><td><?php $ffcertificate_render_pii( 'rf', (string) $ffcertificate_rf, \FreeFormCertificate\Core\DocumentFormatter::mask_rf( $ffcertificate_rf ) ); ?></td></tr>
						<?php endif; ?>
						<?php if ( ! empty( $ffcertificate_appointment['validation_code'] ) ) : ?>
						<tr><th><?php esc_html_e( 'Validation Code', 'ffcertificate' ); ?></th><td><code><?php echo esc_html( \FreeFormCertificate\Core\DocumentFormatter::format_auth_code( $ffcertificate_appointment['validation_code'], \FreeFormCertificate\Core\DocumentFormatter::PREFIX_APPOINTMENT ) ); ?></code></td></tr>
						<?php endif; ?>
						<?php if ( ! empty( $ffcertificate_appointment['user_notes'] ) ) : ?>
						<tr><th><?php esc_html_e( 'User Notes', 'ffcertificate' ); ?></th><td><?php echo esc_html( $ffcertificate_appointment['user_notes'] ); ?></td></tr>
						<?php endif; ?>
						<?php if ( ! empty( $ffcertificate_appointment['admin_notes'] ) ) : ?>
						<tr><th><?php esc_html_e( 'Admin Notes', 'ffcertificate' ); ?></th><td><?php echo esc_html( $ffcertificate_appointment['admin_notes'] ); ?></td></tr>
						<?php endif; ?>
						<tr><th><?php esc_html_e( 'Created', 'ffcertificate' ); ?></th><td><?php echo esc_html( \FreeFormCertificate\Core\DateFormatter::format_datetime( (string) ( $ffcertificate_appointment['created_at'] ?? '' ), 'default', ' ', new \DateTimeZone( 'UTC' ) ) ); ?></td></tr>
					</table>
				</div>

				<?php if ( ! empty( $ffcertificate_custom_data ) ) : ?>
				<div class="card">
					<h2 class="ffc-icon-list"><?php esc_html_e( 'Custom Fields', 'ffcertificate' ); ?></h2>
					<table class="form-table">
						<?php foreach ( $ffcertificate_custom_data as $ffcertificate_field_key => $ffcertificate_field_val ) : ?>
						<tr>
							<th><?php echo esc_html( ucwords( str_replace( array( '_', '-' ), ' ', $ffcertificate_field_key ) ) ); ?></th>
							<td><?php echo esc_html( is_array( $ffcertificate_field_val ) ? implode( ', ', $ffcertificate_field_val ) : (string) $ffcertificate_field_val ); ?></td>
						</tr>
						<?php endforeach; ?>
					</table>
				</div>
				<?php endif; ?>
			</div>
			<?php
		} catch ( \Throwable $e ) {
			echo '<div class="wrap ffc-admin-page ffc-page-appointments">';
			echo '<h1>' . esc_html__( 'Appointment Details', 'ffcertificate' ) . '</h1>';
			\FreeFormCertificate\Core\AdminNotice::render(
				'error',
				'<strong>' . esc_html__( 'Error loading appointment:', 'ffcertificate' ) . '</strong> ' . esc_html( $e->getMessage() )
			);
			echo '<p>';
			\FreeFormCertificate\Admin\AdminUI::render_button(
				array(
					'label' => __( 'Back to List', 'ffcertificate' ),
					'icon'  => 'chevron-left',
					'href'  => $ffcertificate_appointments_url,
				)
			);
			echo '</p>';
			echo '</div>';
			if ( class_exists( '\\FreeFormCertificate\\Core\\Utils' ) ) {
				\FreeFormCertificate\Core\Debug::log_self_scheduling(
					'Appointment view error',
					array(
						'id'    => $ffc_self_scheduling_appointment_id,
						'error' => $e->getMessage(),
						'file'  => $e->getFile(),
						'line'  => $e->getLine(),
					)
				);
			}
		}

		return; // Stop — don't render the list table.
	}
}

// Display admin notices from transients.
$ffcertificate_admin_notice = get_transient( 'ffc_admin_notice_' . get_current_user_id() );
if ( $ffcertificate_admin_notice && is_array( $ffcertificate_admin_notice ) ) {
	\FreeFormCertificate\Core\AdminNotice::render(
		'error' === $ffcertificate_admin_notice['type'] ? 'error' : 'success',
		esc_html( $ffcertificate_admin_notice['message'] )
	);
	// Delete transient after displaying.
	delete_transient( 'ffc_admin_notice_' . get_current_user_id() );
}

// Create and display table.
$ffcertificate_table = new \FreeFormCertificate\SelfScheduling\AppointmentsListTable();
$ffcertificate_table->prepare_items();

?>
<div class="wrap ffc-admin-page ffc-page-appointments">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Appointments', 'ffcertificate' ); ?></h1>
	<?php
	// Export CSV — batched engine (#772): the button drives the shared
	// window.FFCBatchedExport engine through the unified dispatcher. Only shown
	// to holders of the dedicated export cap (a view-only operator sees the list
	// but cannot bulk-extract the attendee dataset). The current calendar/status
	// filters ride along via data-* so the export matches the on-screen query.
	if ( \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_export_appointments' ) ) :
		$ffc_export_calendar_id = \FreeFormCertificate\Core\RequestInput::get_get_int( 'calendar_id' );
		$ffc_export_status      = \FreeFormCertificate\Core\RequestInput::get_get_string( 'status' );
		?>
		<?php
		\FreeFormCertificate\Admin\AdminUI::render_button(
			array(
				'label' => __( 'Export CSV', 'ffcertificate' ),
				'icon'  => 'download',
				'id'    => 'ffc-appointments-export-btn',
				'attrs' => array(
					'data-calendar_id' => (string) $ffc_export_calendar_id,
					'data-status'      => $ffc_export_status,
				),
			)
		);
	endif;
	?>
	<hr class="wp-header-end">

	<?php
	// Old appointments still open past the close-out window (#2285).
	if ( \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_appointments' ) ) :
		$ffc_closeout_due   = \FreeFormCertificate\SelfScheduling\AppointmentCloseout::due();
		$ffc_closeout_total = $ffc_closeout_due['confirmed'] + $ffc_closeout_due['pending'];
		if ( $ffc_closeout_total > 0 ) :
			$ffc_closeout_days    = \FreeFormCertificate\SelfScheduling\AppointmentCloseout::days();
			$ffc_closeout_message = sprintf(
				/* translators: 1: number of days, 2: confirmed appointments, 3: pending appointments */
				__( 'Appointments dated %1$d or more days ago are still open: %2$d confirmed (will be marked completed) and %3$d pending (will be cancelled). The daily run closes them; you can close them now.', 'ffcertificate' ),
				$ffc_closeout_days,
				$ffc_closeout_due['confirmed'],
				$ffc_closeout_due['pending']
			);
			$ffc_closeout_button = \FreeFormCertificate\Core\Button::html(
				array(
					'label'    => __( 'Close old appointments now', 'ffcertificate' ),
					'category' => 'caution',
					'icon'     => 'calendar-check',
					'href'     => wp_nonce_url(
						add_query_arg(
							array(
								'page'       => 'ffc-appointments',
								'ffc_action' => 'close_old',
							),
							admin_url( 'admin.php' )
						),
						'ffc_close_old_appointments'
					),
					'attrs'    => array(
						/* translators: %d: number of appointments */
						'data-confirm' => sprintf( _n( 'Close %d old appointment? This cannot be undone in bulk.', 'Close %d old appointments? This cannot be undone in bulk.', $ffc_closeout_total, 'ffcertificate' ), $ffc_closeout_total ),
					),
				)
			);
			\FreeFormCertificate\Core\AdminNotice::render(
				'warning',
				esc_html( $ffc_closeout_message ) . ' ' . $ffc_closeout_button,
				array( 'additional_classes' => array( 'inline' ) )
			);
		endif;
	endif;
	?>

	<form method="get">
		<input type="hidden" name="page" value="ffc-appointments" />
		<?php echo \FreeFormCertificate\Core\PiiReveal::page_button( 'ffc_view_appointments_pii' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- page_button() escapes every value. ?>
		<?php $ffcertificate_table->display(); ?>
	</form>
</div>

<!-- Styles in ffc-calendar-admin.css -->

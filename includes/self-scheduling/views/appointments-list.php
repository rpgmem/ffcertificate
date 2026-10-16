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
// - appointment=X + ffc_action=confirm|cancel → mutation.
$ffc_self_scheduling_appointment_id = \FreeFormCertificate\Core\RequestInput::get_get_int( 'appointment' );
$ffcertificate_action               = \FreeFormCertificate\Core\RequestInput::get_get_string( 'ffc_action' );


if ( $ffc_self_scheduling_appointment_id > 0 ) {

	// Verify user has admin permissions.
	if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_appointments' ) ) {
		wp_die( esc_html__( 'You do not have permission to perform this action.', 'ffcertificate' ) );
	}

	$ffcertificate_repo = new \FreeFormCertificate\Repositories\AppointmentRepository();

	// Handle mutations (confirm/cancel) — these redirect and exit.
	if ( 'confirm' === $ffcertificate_action ) {
		check_admin_referer( 'ffc_confirm_appointment_' . $ffc_self_scheduling_appointment_id );
		$ffcertificate_result = $ffcertificate_repo->confirm( $ffc_self_scheduling_appointment_id, get_current_user_id() );

		if ( $ffcertificate_result ) {
			// Send approval notification email with receipt link.
			$ffcertificate_appointment = $ffcertificate_repo->findById( $ffc_self_scheduling_appointment_id );
			if ( $ffcertificate_appointment && ! empty( $ffcertificate_appointment['calendar_id'] ) ) {
				$ffcertificate_cal_repo = new \FreeFormCertificate\Repositories\CalendarRepository();
				$ffcertificate_calendar = $ffcertificate_cal_repo->findById( (int) $ffcertificate_appointment['calendar_id'] );
				if ( $ffcertificate_calendar ) {
					do_action( 'ffcertificate_self_scheduling_appointment_confirmed_email', $ffcertificate_appointment, $ffcertificate_calendar );
				}
			}

			set_transient(
				'ffc_admin_notice_' . get_current_user_id(),
				array(
					'type'    => 'success',
					'message' => __( 'Appointment confirmed successfully.', 'ffcertificate' ),
				),
				30
			);
		} else {
			set_transient(
				'ffc_admin_notice_' . get_current_user_id(),
				array(
					'type'    => 'error',
					'message' => __( 'Failed to confirm appointment.', 'ffcertificate' ),
				),
				30
			);
		}

		wp_safe_redirect( $ffcertificate_appointments_url );
		exit;

	} elseif ( 'cancel' === $ffcertificate_action ) {
		check_admin_referer( 'ffc_cancel_appointment_' . $ffc_self_scheduling_appointment_id );
		$ffcertificate_cancel_reason = isset( $_GET['reason'] ) ? sanitize_textarea_field( wp_unslash( $_GET['reason'] ) ) : __( 'Cancelled by admin', 'ffcertificate' );
		// Snapshot the row before cancelling so the waitlist promoter sees the
		// pre-cancel status/slot (#941 phase 2).
		$ffcertificate_pre_cancel = $ffcertificate_repo->findById( $ffc_self_scheduling_appointment_id );
		$ffcertificate_result     = $ffcertificate_repo->cancel( $ffc_self_scheduling_appointment_id, get_current_user_id(), $ffcertificate_cancel_reason );

		if ( $ffcertificate_result ) {
			// Fire the shared cancellation action so the waitlist FIFO promotion
			// runs on admin cancellations (an admin "rejection") too.
			if ( is_array( $ffcertificate_pre_cancel ) ) {
				do_action( 'ffcertificate_appointment_cancelled', $ffc_self_scheduling_appointment_id, $ffcertificate_pre_cancel, $ffcertificate_cancel_reason, get_current_user_id() );
			}

			set_transient(
				'ffc_admin_notice_' . get_current_user_id(),
				array(
					'type'    => 'success',
					'message' => __( 'Appointment cancelled successfully.', 'ffcertificate' ),
				),
				30
			);
		} else {
			set_transient(
				'ffc_admin_notice_' . get_current_user_id(),
				array(
					'type'    => 'error',
					'message' => __( 'Failed to cancel appointment.', 'ffcertificate' ),
				),
				30
			);
		}

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

			// #739 §3.3 / #1813 — CPF / RF / email start masked for every tier,
			// the unmasked one included, as on the submission edit page (#1760).
			// Whoever may reveal (the unmasked and reveal tiers) gets a button
			// that fetches the clear value through the ffc_reveal_pii endpoint,
			// so the plaintext never sits in this screen's HTML.
			$ffcertificate_can_reveal   = \FreeFormCertificate\Core\PiiAccessPolicy::can_reveal(
				'ffc_view_appointments_pii',
				'ffc_appointments_admin',
				(int) ( $ffcertificate_appointment['user_id'] ?? 0 )
			);
			$ffcertificate_reveal_nonce = $ffcertificate_can_reveal ? wp_create_nonce( 'ffc_reveal_pii_nonce' ) : '';

			/**
			 * Render one PII cell: a dash when empty, otherwise the masked value
			 * with a "Reveal" button when the viewer may reveal it.
			 *
			 * @param string $field          Field key (cpf | rf | email).
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
					printf(
						' <button type="button" class="button button-small ffc-reveal-pii ffc-icon-eye" data-type="appointment" data-field="%1$s" data-submission-id="%2$s" data-nonce="%3$s">%4$s</button>',
						esc_attr( $field ),
						esc_attr( (string) $ffc_self_scheduling_appointment_id ),
						esc_attr( $ffcertificate_reveal_nonce ),
						esc_html__( 'Reveal', 'ffcertificate' )
					);
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
				<a href="<?php echo esc_url( $ffcertificate_appointments_url ); ?>" class="page-title-action"><?php esc_html_e( 'Back to List', 'ffcertificate' ); ?></a>
				<hr class="wp-header-end">

				<div class="card">
					<h2 class="ffc-icon-calendar"><?php esc_html_e( 'Appointment Details', 'ffcertificate' ); ?></h2>
					<table class="form-table">
						<tr><th><?php esc_html_e( 'Status', 'ffcertificate' ); ?></th><td><span class="ffc-status ffc-status-<?php echo esc_attr( $ffcertificate_appointment['status'] ); ?>"><?php echo esc_html( $ffcertificate_status_text ); ?></span></td></tr>
						<tr><th><?php esc_html_e( 'Calendar', 'ffcertificate' ); ?></th><td><?php echo esc_html( $ffcertificate_calendar_title ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Date', 'ffcertificate' ); ?></th><td><?php echo esc_html( $ffcertificate_appointment['appointment_date'] ?? '-' ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Time', 'ffcertificate' ); ?></th><td><?php echo esc_html( ( $ffcertificate_appointment['start_time'] ?? '' ) . ' - ' . ( $ffcertificate_appointment['end_time'] ?? '' ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Name', 'ffcertificate' ); ?></th><td><?php echo esc_html( $ffcertificate_name ); ?></td></tr>
						<tr><th><?php esc_html_e( 'E-mail', 'ffcertificate' ); ?></th><td><?php $ffcertificate_render_pii( 'email', (string) $ffcertificate_email, \FreeFormCertificate\Core\DocumentFormatter::mask_email( (string) $ffcertificate_email ) ); ?></td></tr>
						<tr><th><?php esc_html_e( 'Phone', 'ffcertificate' ); ?></th><td><?php echo esc_html( $ffcertificate_phone ? $ffcertificate_phone : '-' ); ?></td></tr>
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
						<tr><th><?php esc_html_e( 'Created', 'ffcertificate' ); ?></th><td><?php echo esc_html( $ffcertificate_appointment['created_at'] ?? '-' ); ?></td></tr>
						<?php if ( ! empty( $ffcertificate_appointment['confirmation_token'] ) ) : ?>
						<tr><th><?php esc_html_e( 'Confirmation Token', 'ffcertificate' ); ?></th><td><code><?php echo esc_html( $ffcertificate_appointment['confirmation_token'] ); ?></code></td></tr>
						<?php endif; ?>
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
			echo '<p><a href="' . esc_url( $ffcertificate_appointments_url ) . '" class="button">' . esc_html__( 'Back to List', 'ffcertificate' ) . '</a></p>';
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
		<button
			type="button"
			id="ffc-appointments-export-btn"
			class="page-title-action"
			data-calendar_id="<?php echo esc_attr( (string) $ffc_export_calendar_id ); ?>"
			data-status="<?php echo esc_attr( $ffc_export_status ); ?>">
			<?php esc_html_e( 'Export CSV', 'ffcertificate' ); ?>
		</button>	<?php endif; ?>
	<hr class="wp-header-end">

	<form method="get">
		<input type="hidden" name="page" value="ffc-appointments" />
		<?php $ffcertificate_table->display(); ?>
	</form>
</div>

<!-- Styles in ffc-calendar-admin.css -->

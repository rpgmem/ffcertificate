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

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables scoped to this file

// The list-table class now lives in its own autoloaded file
// (FreeFormCertificate\SelfScheduling\AppointmentsListTable) — it is
// instantiated near the bottom of this view.

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nonce verified per-action below via check_admin_referer.

// Appointments base URL (used for redirects and back links).
$ffcertificate_appointments_url = add_query_arg( array( 'page' => 'ffc-appointments' ), admin_url( 'admin.php' ) );

// Determine if viewing a specific appointment:
// - appointment=X alone → view.
// - appointment=X + ffc_action=confirm|cancel → mutation.
$ffc_self_scheduling_appointment_id = isset( $_GET['appointment'] ) ? absint( wp_unslash( $_GET['appointment'] ) ) : 0;
$ffcertificate_action               = \FreeFormCertificate\Core\Utils::get_get_string( 'ffc_action' );

// phpcs:enable WordPress.Security.NonceVerification.Recommended

if ( $ffc_self_scheduling_appointment_id > 0 ) {

	// Verify user has admin permissions.
	if ( ! \FreeFormCertificate\Core\Utils::current_user_can_admin_or( 'ffc_manage_appointments' ) ) {
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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$ffcertificate_cancel_reason = isset( $_GET['reason'] ) ? sanitize_textarea_field( wp_unslash( $_GET['reason'] ) ) : __( 'Cancelled by admin', 'ffcertificate' );
		$ffcertificate_result        = $ffcertificate_repo->cancel( $ffc_self_scheduling_appointment_id, get_current_user_id(), $ffcertificate_cancel_reason );

		if ( $ffcertificate_result ) {
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
				echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'Appointment not found.', 'ffcertificate' ) . '</p></div></div>';
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
			<div class="wrap">
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

				<div id="poststuff">
					<div id="post-body" class="metabox-holder columns-2">
						<div id="post-body-content">
							<div class="postbox">
								<div class="postbox-header"><h2 class="hndle"><?php esc_html_e( 'Appointment Details', 'ffcertificate' ); ?></h2></div>
								<div class="inside">
									<table class="form-table">
										<tr><th><?php esc_html_e( 'Status', 'ffcertificate' ); ?></th><td><span class="ffc-status ffc-status-<?php echo esc_attr( $ffcertificate_appointment['status'] ); ?>"><?php echo esc_html( $ffcertificate_status_text ); ?></span></td></tr>
										<tr><th><?php esc_html_e( 'Calendar', 'ffcertificate' ); ?></th><td><?php echo esc_html( $ffcertificate_calendar_title ); ?></td></tr>
										<tr><th><?php esc_html_e( 'Date', 'ffcertificate' ); ?></th><td><?php echo esc_html( $ffcertificate_appointment['appointment_date'] ?? '-' ); ?></td></tr>
										<tr><th><?php esc_html_e( 'Time', 'ffcertificate' ); ?></th><td><?php echo esc_html( ( $ffcertificate_appointment['start_time'] ?? '' ) . ' - ' . ( $ffcertificate_appointment['end_time'] ?? '' ) ); ?></td></tr>
										<tr><th><?php esc_html_e( 'Name', 'ffcertificate' ); ?></th><td><?php echo esc_html( $ffcertificate_name ); ?></td></tr>
										<tr><th><?php esc_html_e( 'E-mail', 'ffcertificate' ); ?></th><td><?php echo esc_html( $ffcertificate_email ? $ffcertificate_email : '-' ); ?></td></tr>
										<tr><th><?php esc_html_e( 'Phone', 'ffcertificate' ); ?></th><td><?php echo esc_html( $ffcertificate_phone ? $ffcertificate_phone : '-' ); ?></td></tr>
										<?php if ( ! empty( $ffcertificate_cpf ) ) : ?>
										<tr><th><?php esc_html_e( 'CPF', 'ffcertificate' ); ?></th><td><?php echo esc_html( \FreeFormCertificate\Core\DocumentFormatter::format_document( $ffcertificate_cpf ) ); ?></td></tr>
										<?php endif; ?>
										<?php if ( ! empty( $ffcertificate_rf ) ) : ?>
										<tr><th><?php esc_html_e( 'RF', 'ffcertificate' ); ?></th><td><?php echo esc_html( \FreeFormCertificate\Core\DocumentFormatter::format_document( $ffcertificate_rf ) ); ?></td></tr>
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

									<?php if ( ! empty( $ffcertificate_custom_data ) ) : ?>
									<h3><?php esc_html_e( 'Custom Fields', 'ffcertificate' ); ?></h3>
									<table class="form-table">
										<?php foreach ( $ffcertificate_custom_data as $ffcertificate_field_key => $ffcertificate_field_val ) : ?>
										<tr>
											<th><?php echo esc_html( ucwords( str_replace( array( '_', '-' ), ' ', $ffcertificate_field_key ) ) ); ?></th>
											<td><?php echo esc_html( is_array( $ffcertificate_field_val ) ? implode( ', ', $ffcertificate_field_val ) : (string) $ffcertificate_field_val ); ?></td>
										</tr>
										<?php endforeach; ?>
									</table>
									<?php endif; ?>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
			<?php
		} catch ( \Throwable $e ) {
			echo '<div class="wrap">';
			echo '<h1>' . esc_html__( 'Appointment Details', 'ffcertificate' ) . '</h1>';
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Error loading appointment:', 'ffcertificate' ) . '</strong> ' . esc_html( $e->getMessage() ) . '</p></div>';
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
	$ffcertificate_notice_type = 'error' === $ffcertificate_admin_notice['type'] ? 'notice-error' : 'notice-success';
	echo '<div class="notice ' . esc_attr( $ffcertificate_notice_type ) . ' is-dismissible"><p>' . esc_html( $ffcertificate_admin_notice['message'] ) . '</p></div>';
	// Delete transient after displaying.
	delete_transient( 'ffc_admin_notice_' . get_current_user_id() );
}

// Create and display table.
$ffcertificate_table = new \FreeFormCertificate\SelfScheduling\AppointmentsListTable();
$ffcertificate_table->prepare_items();

?>
<div class="wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Appointments', 'ffcertificate' ); ?></h1>
	<a href="#" class="page-title-action"><?php esc_html_e( 'Export CSV', 'ffcertificate' ); ?></a>
	<hr class="wp-header-end">

	<form method="get">
		<input type="hidden" name="page" value="ffc-appointments" />
		<?php $ffcertificate_table->display(); ?>
	</form>
</div>

<!-- Styles in ffc-calendar-admin.css -->

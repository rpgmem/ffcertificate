<?php
/**
 * Verification Response Renderer
 *
 * Renders HTML output for certificate and appointment verification results.
 * Handles field labels, value formatting, and PDF data generation.
 *
 * Extracted from VerificationHandler (M7 refactoring).
 *
 * @package FreeFormCertificate\Frontend
 * @since 4.6.8
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renderer for verification response output.
 */
class VerificationResponseRenderer {

	/**
	 * Format certificate verification response HTML
	 *
	 * @param object               $submission Submission object.
	 * @param array<string, mixed> $data Submission data fields.
	 * @param bool                 $show_download_button Whether to show PDF download button.
	 * @phpstan-param \stdClass&object{form_id: numeric-string, submission_date: numeric-string|int} $submission
	 * @return string HTML output
	 */
	public function format_verification_response( object $submission, array $data, bool $show_download_button = false ): string {
		$form       = get_post( (int) $submission->form_id );
		$form_title = $form ? $form->post_title : __( 'N/A', 'ffcertificate' );
		// `submission_date` is unix UTC int since 6.6.0 (#249 sub-escopo a).
		$date_generated = \FreeFormCertificate\Core\DateFormatter::format_datetime( (int) $submission->submission_date );
		$display_code   = isset( $data['auth_code'] )
			? \FreeFormCertificate\Core\DocumentFormatter::format_auth_code( $data['auth_code'], \FreeFormCertificate\Core\DocumentFormatter::PREFIX_CERTIFICATE )
			: '';

		// Fields to skip (internal/technical).
		$skip_fields = array(
			'auth_code',
			'ticket',
			'fill_date',
			'fill_time',
			'is_edited',
			'edited_at',
			'submission_id',
			'magic_token',
		);

		// Priority fields to show first (in order).
		$priority_fields = array( 'name', 'cpf_rf', 'email', 'program', 'date' );

		// Callbacks for template.
		$get_field_label_callback    = array( $this, 'get_field_label' );
		$format_field_value_callback = array( $this, 'format_field_value' );

		// Schedule exception block (#366 Sprint 8). When the submission
		// row carries either override column, look up the matching
		// audit entry to surface the full "before → after" + operator
		// + ts so the public verification page reads the exception as
		// a legitimate adjustment rather than as tampering.
		$schedule_exception_block = $this->build_schedule_exception_block( $submission );

		// Render template.
		ob_start();
		include FFC_PLUGIN_DIR . 'templates/certificate-preview.php';
		$preview_html = ob_get_clean();
		return $preview_html ? $preview_html : '';
	}

	/**
	 * Build the schedule-exception block payload for the verification
	 * template, or return null when this submission carries no override.
	 *
	 * Source contract:
	 *   - Trigger: either `schedule_start_override` or `schedule_end_override`
	 *     column is non-empty on the submission row. (Both empty → no
	 *     exception ever happened → null fast-path.)
	 *   - Context: the matching `schedule_override_created` row from
	 *     `ffc_activity_log` for this submission. The `ts` field is
	 *     Category A unix UTC int (per CLAUDE.md) and renders via
	 *     `DateFormatter::format_datetime()` — NOT from the activity
	 *     log's `created_at` DATETIME column (housekeeping per the
	 *     "Category A exception" subsection).
	 *
	 * Returns the array consumed by the template, with already-formatted
	 * "before" / "after" range strings + the i18n-friendly operator
	 * label + ts label. Returns null when no override is present so
	 * the template can `if ( ! empty( $schedule_exception_block ) )`
	 * around the new block.
	 *
	 * @param object $submission Submission row as returned by SubmissionHandler.
	 * @phpstan-param \stdClass&object{id?: int|numeric-string, schedule_start_override?: string|null, schedule_end_override?: string|null} $submission
	 * @return array<string, string>|null
	 */
	private function build_schedule_exception_block( object $submission ): ?array {
		$start_override = isset( $submission->schedule_start_override ) ? (string) $submission->schedule_start_override : '';
		$end_override   = isset( $submission->schedule_end_override ) ? (string) $submission->schedule_end_override : '';
		if ( '' === $start_override && '' === $end_override ) {
			return null;
		}

		// Look up the audit row. Activity Log is the source of truth
		// for "before" — admin may have edited class_time_* after the
		// exception, so we don't try to re-resolve baseline from the
		// current form config.
		$submission_id = isset( $submission->id ) ? (int) $submission->id : 0;
		$logs          = \FreeFormCertificate\Core\ActivityLogQuery::get_submission_logs( $submission_id, 50 );
		$row           = null;
		foreach ( $logs as $log ) {
			if ( 'schedule_override_created' === ( $log['action'] ?? '' ) ) {
				$row = $log;
				break;
			}
		}
		if ( null === $row ) {
			return null;
		}

		$context = $row['context'] ?? array();
		if ( is_string( $context ) ) {
			$decoded = json_decode( $context, true );
			$context = is_array( $decoded ) ? $decoded : array();
		}

		$before_start = (string) ( $context['schedule_start_before'] ?? '' );
		$before_end   = (string) ( $context['schedule_end_before'] ?? '' );

		$before = \FreeFormCertificate\Core\DateFormatter::format_schedule( $before_start, $before_end );

		// 6.7.2 — When the operator overrode only ONE end of the
		// schedule (e.g. user left early — only `end` shifted), the
		// "Recorded schedule" line used to render only the changed
		// end ("11h21"), making it look like the participant's
		// entire window was that single moment. Fall back to the
		// matching baseline value for the side that was NOT
		// overridden, so the recorded range always carries both
		// ends — "9h às 11h21" instead of "11h21".
		$after_start = '' !== $start_override ? $start_override : $before_start;
		$after_end   = '' !== $end_override ? $end_override : $before_end;

		$after = \FreeFormCertificate\Core\DateFormatter::format_schedule( $after_start, $after_end );

		$ts       = isset( $context['ts'] ) ? (int) $context['ts'] : 0;
		$ts_label = $ts > 0
			? \FreeFormCertificate\Core\DateFormatter::format_datetime( $ts )
			: '';
		$operator = (string) ( $context['operator_cpf_masked'] ?? '' );

		return array(
			'before_range' => $before,
			'after_range'  => $after,
			'operator'     => $operator,
			'ts_label'     => $ts_label,
		);
	}

	/**
	 * Format appointment verification response HTML
	 *
	 * @param array<string, mixed> $result Appointment search result.
	 * @return string HTML output
	 */
	public function format_appointment_verification_response( array $result ): string {
		$data        = $result['data'];
		$appointment = $result['appointment'];

		// Format date.
		$formatted_date = __( 'N/A', 'ffcertificate' );
		if ( ! empty( $appointment['appointment_date'] ) ) {
			$ts = strtotime( $appointment['appointment_date'] );
			if ( false !== $ts ) {
				$formatted_date = \FreeFormCertificate\Core\DateFormatter::format_date( $ts );
			}
		}

		// Format time.
		$formatted_time = __( 'N/A', 'ffcertificate' );
		if ( ! empty( $appointment['start_time'] ) ) {
			$ts = strtotime( $appointment['start_time'] );
			if ( false !== $ts ) {
				$formatted_time = \FreeFormCertificate\Core\DateFormatter::format_time( $ts );
			}
			if ( ! empty( $appointment['end_time'] ) ) {
				$ts2 = strtotime( $appointment['end_time'] );
				if ( false !== $ts2 ) {
					$formatted_time .= ' - ' . \FreeFormCertificate\Core\DateFormatter::format_time( $ts2 );
				}
			}
		}

		// Format created_at.
		$formatted_created = __( 'N/A', 'ffcertificate' );
		if ( ! empty( $appointment['created_at'] ) ) {
			$ts = strtotime( $appointment['created_at'] );
			if ( false !== $ts ) {
				$formatted_created = \FreeFormCertificate\Core\DateFormatter::format_datetime( $ts );
			}
		}

		// Status labels.
		$status_labels = array(
			'pending'   => __( 'Pending Approval', 'ffcertificate' ),
			'confirmed' => __( 'Confirmed', 'ffcertificate' ),
			'cancelled' => __( 'Cancelled', 'ffcertificate' ),
			'completed' => __( 'Completed', 'ffcertificate' ),
			'no_show'   => __( 'No Show', 'ffcertificate' ),
		);
		$status        = $appointment['status'] ?? 'pending';
		$status_label  = $status_labels[ $status ] ?? $status;

		// Format validation code.
		$display_code = '';
		if ( ! empty( $appointment['validation_code'] ) ) {
			$display_code = \FreeFormCertificate\Core\DocumentFormatter::format_auth_code( $appointment['validation_code'], \FreeFormCertificate\Core\DocumentFormatter::PREFIX_APPOINTMENT );
		}

		// 6.7.4 — `/valid` is a PUBLIC verification page; full CPF surfaced
		// is a privacy leak. Same masking applied for certificates in 6.7.2
		// — extending parity to appointments here. (Appointment render
		// does not surface email — only name/CPF/booked-on — so no email
		// mask needed in this branch.)
		$cpf_rf_display = '';
		if ( ! empty( $data['cpf_rf'] ) ) {
			$cpf_rf_display = \FreeFormCertificate\Core\DocumentFormatter::mask_cpf( (string) $data['cpf_rf'] );
		}

		// Build HTML.
		$html = '<div class="ffc-certificate-preview ffc-appointment-verification">';

		$html .= '<div class="ffc-preview-header">';
		$html .= '<span class="ffc-status-badge success ffc-icon-success">' . esc_html__( 'Appointment Receipt Valid', 'ffcertificate' ) . '</span>';
		$html .= '<br><span class="ffc-appointment-status ffc-status-' . esc_attr( $status ) . '">' . esc_html( $status_label ) . '</span>';
		$html .= '</div>';

		$html .= '<div class="ffc-preview-body">';
		$html .= '<h3>' . esc_html__( 'Appointment Details', 'ffcertificate' ) . '</h3>';

		if ( ! empty( $display_code ) ) {
			$html .= '<div class="ffc-detail-row">';
			$html .= '<span class="label">' . esc_html__( 'Validation Code:', 'ffcertificate' ) . '</span>';
			$html .= '<span class="value code">' . esc_html( $display_code ) . '</span>';
			$html .= '</div>';
		}

		if ( ! empty( $data['calendar_title'] ) ) {
			$html .= '<div class="ffc-detail-row">';
			$html .= '<span class="label">' . esc_html__( 'Event:', 'ffcertificate' ) . '</span>';
			$html .= '<span class="value">' . esc_html( $data['calendar_title'] ) . '</span>';
			$html .= '</div>';
		}

		$html .= '<div class="ffc-detail-row">';
		$html .= '<span class="label">' . esc_html__( 'Date:', 'ffcertificate' ) . '</span>';
		$html .= '<span class="value">' . esc_html( $formatted_date ) . '</span>';
		$html .= '</div>';

		$html .= '<div class="ffc-detail-row">';
		$html .= '<span class="label">' . esc_html__( 'Time:', 'ffcertificate' ) . '</span>';
		$html .= '<span class="value">' . esc_html( $formatted_time ) . '</span>';
		$html .= '</div>';

		$html .= '<hr>';
		$html .= '<h4>' . esc_html__( 'Participant Data:', 'ffcertificate' ) . '</h4>';

		if ( ! empty( $data['name'] ) ) {
			$html .= '<div class="ffc-detail-row">';
			$html .= '<span class="label">' . esc_html__( 'Name:', 'ffcertificate' ) . '</span>';
			$html .= '<span class="value">' . esc_html( $data['name'] ) . '</span>';
			$html .= '</div>';
		}

		if ( ! empty( $cpf_rf_display ) ) {
			$html .= '<div class="ffc-detail-row">';
			$html .= '<span class="label">' . esc_html__( 'CPF/RF:', 'ffcertificate' ) . '</span>';
			$html .= '<span class="value">' . esc_html( $cpf_rf_display ) . '</span>';
			$html .= '</div>';
		}

		$html .= '<div class="ffc-detail-row">';
		$html .= '<span class="label">' . esc_html__( 'Booked on:', 'ffcertificate' ) . '</span>';
		$html .= '<span class="value">' . esc_html( $formatted_created ) . '</span>';
		$html .= '</div>';

		$html .= '</div>'; // .ffc-preview-body

		$html .= '<div class="ffc-preview-actions">';
		$html .= '<button class="ffc-download-btn ffc-download-pdf-btn ffc-icon-download">' . esc_html__( 'Download Receipt (PDF)', 'ffcertificate' ) . '</button>';
		$html .= '</div>';

		$html .= '</div>'; // .ffc-certificate-preview

		return $html;
	}

	/**
	 * Generate appointment PDF data for verification context
	 *
	 * @param array<string, mixed>                         $result Search result array.
	 * @param \FreeFormCertificate\Generators\PdfGenerator $pdf_generator PDF generator instance.
	 * @return array<string, mixed> PDF data array
	 */
	public function generate_appointment_verification_pdf( array $result, \FreeFormCertificate\Generators\PdfGenerator $pdf_generator ): array {
		$appointment = $result['appointment'];
		$calendar    = array( 'title' => $result['data']['calendar_title'] ?? __( 'N/A', 'ffcertificate' ) );

		if ( ! empty( $appointment['calendar_id'] ) ) {
			$calendar_repo = new \FreeFormCertificate\Repositories\CalendarRepository();
			$full_calendar = $calendar_repo->findById( (int) $appointment['calendar_id'] );
			if ( $full_calendar ) {
				$calendar = $full_calendar;
			}
		}

		return $pdf_generator->generate_appointment_pdf_data( $appointment, $calendar );
	}

	/**
	 * Format reregistration verification response HTML.
	 *
	 * @since 4.12.0
	 * @param array<string, mixed> $result Reregistration search result.
	 * @return string HTML output.
	 */
	public function format_reregistration_verification_response( array $result ): string {
		$rereg = $result['reregistration'];

		$submitted_at = __( 'N/A', 'ffcertificate' );
		if ( ! empty( $rereg['submitted_at'] ) ) {
			// `submitted_at` is unix UTC int since 6.6.0 (#249 sub-escopo b).
			$submitted_at = \FreeFormCertificate\Core\DateFormatter::format_datetime( (int) $rereg['submitted_at'] );
		}

		$display_code = ! empty( $rereg['auth_code'] )
			? \FreeFormCertificate\Core\DocumentFormatter::format_auth_code( $rereg['auth_code'], \FreeFormCertificate\Core\DocumentFormatter::PREFIX_REREGISTRATION )
			: '';

		// 6.7.4 — Mask CPF on the public verification page (privacy).
		$cpf_display = '';
		if ( ! empty( $rereg['cpf'] ) ) {
			$cpf_display = \FreeFormCertificate\Core\DocumentFormatter::mask_cpf( (string) $rereg['cpf'] );
		}
		$email_display = '';
		if ( ! empty( $rereg['email'] ) ) {
			$email_display = \FreeFormCertificate\Core\DocumentFormatter::mask_email( (string) $rereg['email'] );
		}

		// Status badge class.
		$status_class = 'info';
		if ( 'approved' === $rereg['status'] ) {
			$status_class = 'success';
		} elseif ( 'rejected' === $rereg['status'] ) {
			$status_class = 'error';
		}

		$html = '<div class="ffc-certificate-preview ffc-reregistration-verification">';

		$html .= '<div class="ffc-preview-header">';
		$html .= '<span class="ffc-status-badge ' . esc_attr( $status_class ) . ' ffc-icon-success">' . esc_html__( 'Reregistration Record Valid', 'ffcertificate' ) . '</span>';
		$html .= '<br><span class="ffc-appointment-status ffc-status-' . esc_attr( $rereg['status'] ) . '">' . esc_html( $rereg['status_label'] ) . '</span>';
		$html .= '</div>';

		$html .= '<div class="ffc-preview-body">';
		$html .= '<h3>' . esc_html( $rereg['title'] ) . '</h3>';

		if ( ! empty( $display_code ) ) {
			$html .= '<div class="ffc-detail-row">';
			$html .= '<span class="label">' . esc_html__( 'Validation Code:', 'ffcertificate' ) . '</span>';
			$html .= '<span class="value code">' . esc_html( $display_code ) . '</span>';
			$html .= '</div>';
		}

		if ( ! empty( $rereg['display_name'] ) ) {
			$html .= '<div class="ffc-detail-row">';
			$html .= '<span class="label">' . esc_html__( 'Name:', 'ffcertificate' ) . '</span>';
			$html .= '<span class="value">' . esc_html( $rereg['display_name'] ) . '</span>';
			$html .= '</div>';
		}

		if ( ! empty( $cpf_display ) ) {
			$html .= '<div class="ffc-detail-row">';
			$html .= '<span class="label">' . esc_html__( 'CPF:', 'ffcertificate' ) . '</span>';
			$html .= '<span class="value">' . esc_html( $cpf_display ) . '</span>';
			$html .= '</div>';
		}

		if ( ! empty( $email_display ) ) {
			$html .= '<div class="ffc-detail-row">';
			$html .= '<span class="label">' . esc_html__( 'Email:', 'ffcertificate' ) . '</span>';
			$html .= '<span class="value">' . esc_html( $email_display ) . '</span>';
			$html .= '</div>';
		}

		$html .= '<div class="ffc-detail-row">';
		$html .= '<span class="label">' . esc_html__( 'Submitted:', 'ffcertificate' ) . '</span>';
		$html .= '<span class="value">' . esc_html( $submitted_at ) . '</span>';
		$html .= '</div>';

		$html .= '</div>'; // .ffc-preview-body

		$html .= '<div class="ffc-preview-actions">';
		$html .= '<button class="ffc-download-btn ffc-download-pdf-btn ffc-icon-download">' . esc_html__( 'Download Ficha (PDF)', 'ffcertificate' ) . '</button>';
		$html .= '</div>';

		$html .= '</div>'; // .ffc-certificate-preview

		return $html;
	}

	/**
	 * Get human-readable field label
	 *
	 * @param string $field_key Field key.
	 * @return string Formatted label
	 */
	public function get_field_label( string $field_key ): string {
		$labels = array(
			'cpf_rf'   => __( 'CPF/RF', 'ffcertificate' ),
			'cpf'      => __( 'CPF', 'ffcertificate' ),
			'rf'       => __( 'RF', 'ffcertificate' ),
			'name'     => __( 'Name', 'ffcertificate' ),
			'email'    => __( 'Email', 'ffcertificate' ),
			'program'  => __( 'Program', 'ffcertificate' ),
			'date'     => __( 'Date', 'ffcertificate' ),
			'rg'       => __( 'RG', 'ffcertificate' ),
			'phone'    => __( 'Phone', 'ffcertificate' ),
			'address'  => __( 'Address', 'ffcertificate' ),
			'city'     => __( 'City', 'ffcertificate' ),
			'state'    => __( 'State', 'ffcertificate' ),
			'zip'      => __( 'ZIP Code', 'ffcertificate' ),
			'course'   => __( 'Course', 'ffcertificate' ),
			'duration' => __( 'Duration', 'ffcertificate' ),
			'hours'    => __( 'Hours', 'ffcertificate' ),
			'grade'    => __( 'Grade', 'ffcertificate' ),
		);

		if ( isset( $labels[ $field_key ] ) ) {
			return $labels[ $field_key ];
		}

		return ucwords( str_replace( array( '_', '-' ), ' ', $field_key ) );
	}

	/**
	 * Format field value for display
	 *
	 * @param string $field_key Field key.
	 * @param mixed  $value Field value.
	 * @return string Formatted value
	 */
	public function format_field_value( string $field_key, $value ): string {
		if ( is_array( $value ) ) {
			return esc_html( implode( ', ', $value ) );
		}

		// 6.7.2 — `/valid` is a PUBLIC verification page: anyone with the
		// auth code (or magic-link token) sees this. Surfacing the full
		// CPF/RF/email of the participant is a privacy leak — the page's
		// purpose is to prove "this certificate exists and belongs to
		// $name", not to dump PII. Mask both before rendering. The masks
		// already exist in DocumentFormatter (`mask_cpf`, `mask_email`).
		if ( in_array( $field_key, array( 'cpf', 'cpf_rf', 'rg' ), true ) && ! empty( $value ) ) {
			return esc_html( \FreeFormCertificate\Core\DocumentFormatter::mask_cpf( (string) $value ) );
		}

		if ( 'email' === $field_key && ! empty( $value ) ) {
			return esc_html( \FreeFormCertificate\Core\DocumentFormatter::mask_email( (string) $value ) );
		}

		return esc_html( (string) $value );
	}
}

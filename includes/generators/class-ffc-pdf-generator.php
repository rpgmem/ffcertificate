<?php
/**
 * PdfGenerator
 *
 * Centralized PDF generation for all contexts:
 * - Form submission (frontend)
 * - Manual verification
 * - Magic link verification
 * - Admin PDF download
 * - Certificate reprint
 *
 * v3.3.0: Added strict types and type hints
 * v3.2.0: Migrated to namespace (Phase 2)
 * v2.9.2: Single source of truth for PDF generation
 * v2.9.14: REFACTORED - Moved generate_html logic from FFC_Email_Handler
 *
 * @package FreeFormCertificate\Generators
 */

declare(strict_types=1);

namespace FreeFormCertificate\Generators;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generator for pdf output.
 */
class PdfGenerator {

	/**
	 * HTML renderer / placeholder processor.
	 *
	 * Owns the HTML-building and placeholder-substitution step that this
	 * data-assembly class delegates to (#589 phase-2 split).
	 *
	 * @var PdfHtmlRenderer
	 */
	private $html_renderer;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->html_renderer = new PdfHtmlRenderer();
	}

	/**
	 * Generate PDF data for any context
	 *
	 * @param int    $submission_id Submission ID.
	 * @param object $submission_handler Submission handler instance.
	 * @phpstan-param \FreeFormCertificate\Submissions\SubmissionHandler $submission_handler
	 * @return array<string, mixed>|\WP_Error PDF data array or error
	 */
	public function generate_pdf_data( int $submission_id, object $submission_handler ) {
		// Get submission.
		$submission = $submission_handler->get_submission( $submission_id );

		if ( ! $submission ) {
			return new WP_Error( 'submission_not_found', __( 'Submission not found.', 'ffcertificate' ) );
		}

		// Convert to array.
		$sub_array = (array) $submission;

		// Rebuild complete data (columns + JSON).
		$data = array(
			'email' => $sub_array['email'],
		);

		if ( ! empty( $sub_array['auth_code'] ) ) {
			$data['auth_code'] = $sub_array['auth_code'];
		}

		if ( ! empty( $sub_array['cpf_rf'] ) ) {
			$data['cpf_rf'] = $sub_array['cpf_rf'];
		}

		// Extra fields from JSON.
		$extra_data = json_decode( $sub_array['data'], true );
		if ( ! is_array( $extra_data ) ) {
			$extra_data = json_decode( wp_unslash( $sub_array['data'] ), true );
		}

		// Merge — columns have priority over JSON fields.
		if ( is_array( $extra_data ) && ! empty( $extra_data ) ) {
			$data = array_merge( $extra_data, $data );
		}

		// Enrich data with submission metadata.
		$data = $this->enrich_submission_data( $data, $sub_array );

		/**
		 * Filters certificate template data before HTML generation.
		 *
		 * @since 4.6.4
		 * @param array $data          Enriched submission data used as template variables.
		 * @param int   $submission_id Submission ID.
		 * @param array $sub_array     Raw submission database row.
		 */
		$data = apply_filters( 'ffcertificate_certificate_data', $data, $submission_id, $sub_array );

		// Get form data (convert form_id to int - wpdb returns strings).
		$form_id      = (int) $sub_array['form_id'];
		$form_title   = get_the_title( $form_id );
		$form_config  = get_post_meta( $form_id, '_ffc_form_config', true );
		$bg_image_url = get_post_meta( $form_id, '_ffc_form_bg', true );

		// `{{schedule}}` / `{{schedule_total}}` (#366 Sprint 7). Resolve
		// the effective wall-clock range — per-submission override wins,
		// then form-level `class_time_*`, then the geofence `time_*`
		// baseline, then empty string. Injecting into `$data` makes
		// the existing template loop substitute them like any other
		// field, so the email pipeline (which also funnels through
		// `generate_html()`) gets the placeholder for free.
		list( $sched_start, $sched_end ) = $this->resolve_effective_schedule( $form_id, $sub_array );
		$data['schedule']                = \FreeFormCertificate\Core\DateFormatter::format_schedule( $sched_start, $sched_end );
		$data['schedule_total']          = \FreeFormCertificate\Core\DateFormatter::format_schedule_total( $sched_start, $sched_end );

		// `submission_date` is unix UTC int since 6.6.0 (#249 sub-escopo a).
		$submission_ts = isset( $sub_array['submission_date'] ) ? (int) $sub_array['submission_date'] : null;
		$html          = $this->html_renderer->generate_html( $data, $form_title, $form_config, $submission_ts );

		/**
		 * Filters the generated certificate HTML before it is returned.
		 *
		 * @since 4.6.4
		 * @param string $html          Generated certificate HTML.
		 * @param array  $data          Template data.
		 * @param int    $submission_id Submission ID.
		 * @param int    $form_id       Form ID.
		 */
		$html = apply_filters( 'ffcertificate_certificate_html', $html, $data, $submission_id, $form_id );

		// Get verification code from submission data.
		$auth_code = isset( $data['auth_code'] ) ? $data['auth_code'] : '';

		// 6.6.11 — standardized filename pattern shared across all FFC PDFs:
		// `{translated-prefix}_{entity_id}_{auth_code}.pdf`. The per-type
		// filter below still fires AFTER the central `ffcertificate_pdf_filename`
		// (applied inside the helper) so existing customizations keep working.
		$filename = \FreeFormCertificate\Core\FilenameHelper::build_pdf_filename( 'certificate', (int) $form_id, (string) $auth_code );

		/**
		 * Filters the certificate PDF filename.
		 *
		 * @since 4.6.4
		 * @param string $filename      Generated filename.
		 * @param string $form_title    Form title.
		 * @param string $auth_code     Authentication code.
		 * @param int    $submission_id Submission ID.
		 */
		$filename = apply_filters( 'ffcertificate_certificate_filename', $filename, $form_title, $auth_code, $submission_id );

		// Log generation.
		\FreeFormCertificate\Core\Debug::log_pdf(
			'PDF data generated',
			array(
				'submission_id' => $submission_id,
				'form_id'       => $form_id,
				'form_title'    => \FreeFormCertificate\Core\Utils::truncate( $form_title, 50 ),
				'auth_code'     => $auth_code,
				'filename'      => $filename,
				'html_length'   => strlen( $html ),
				'has_bg_image'  => ! empty( $bg_image_url ),
			)
		);

		$pdf_data = array(
			'html'          => $html,
			'filename'      => $filename,
			'form_title'    => $form_title,
			'auth_code'     => $auth_code,
			'submission_id' => $submission_id,
			'submission'    => $data,
			'bg_image'      => $bg_image_url,
		);

		/**
		 * Fires after PDF data is fully generated.
		 *
		 * @since 4.6.4
		 * @param array $pdf_data     Complete PDF data array.
		 * @param int   $submission_id Submission ID.
		 */
		do_action( 'ffcertificate_after_pdf_generation', $pdf_data, $submission_id );

		return $pdf_data;
	}

	/**
	 * Enrich submission data with metadata
	 *
	 * @param array<string, mixed> $data Original submission data.
	 * @param array<string, mixed> $submission Submission database row.
	 * @return array<string, mixed> Enriched data
	 */
	private function enrich_submission_data( array $data, array $submission ): array {
		// Add email if missing.
		if ( ! isset( $data['email'] ) && ! empty( $submission['email'] ) ) {
			$data['email'] = $submission['email'];
		}

		// Add formatted date if missing. `submission_date` is unix UTC int
		// since 6.6.0 (#249 sub-escopo a) — DateFormatter::format_date
		// accepts int directly.
		if ( ! isset( $data['fill_date'] ) ) {
			$data['fill_date'] = \FreeFormCertificate\Core\DateFormatter::format_date( (int) $submission['submission_date'], 'pdf' );
		}

		// Add date alias.
		if ( ! isset( $data['date'] ) ) {
			$data['date'] = $data['fill_date'];
		}

		// Add submission ID (convert to int - wpdb returns strings).
		if ( ! isset( $data['submission_id'] ) ) {
			$data['submission_id'] = (int) $submission['id'];
		}

		// Add magic token if exists.
		if ( ! isset( $data['magic_token'] ) && ! empty( $submission['magic_token'] ) ) {
			$data['magic_token'] = $submission['magic_token'];
		}

		return $data;
	}

	/**
	 * Generate QR code for submission magic link
	 *
	 * Thin static delegator kept at this FQN
	 * (`PdfGenerator::generate_magic_link_qr`) for backward compatibility —
	 * the implementation moved to {@see PdfHtmlRenderer::generate_magic_link_qr()}
	 * in the #589 phase-2 split.
	 *
	 * @param int $submission_id Submission ID.
	 * @param int $size QR code size (default: 200).
	 * @return string QR code image URL or data URI
	 */
	public static function generate_magic_link_qr( int $submission_id, int $size = 200 ): string {
		return PdfHtmlRenderer::generate_magic_link_qr( $submission_id, $size );
	}

	/**
	 * Generate PDF data from form submission (for frontend)
	 *
	 * @param array<string, mixed> $submission_data Posted form data.
	 * @param int                  $form_id Form ID.
	 * @param int|null             $submission_date Submission instant (unix UTC seconds since 6.6.0).
	 * @return array<string, mixed>|\WP_Error PDF data array
	 */
	public function generate_pdf_data_from_form( array $submission_data, int $form_id, ?int $submission_date = null ) {
		// Get form data.
		$form_post = get_post( $form_id );
		if ( ! $form_post ) {
			return new WP_Error( 'form_not_found', __( 'Form not found.', 'ffcertificate' ) );
		}

		$form_title   = $form_post->post_title;
		$form_config  = get_post_meta( $form_id, '_ffc_form_config', true );
		$bg_image_url = get_post_meta( $form_id, '_ffc_form_bg', true );

		// Add formatted date.
		if ( $submission_date ) {
			$formatted_date               = \FreeFormCertificate\Core\DateFormatter::format_datetime( $submission_date, 'pdf' );
			$submission_data['fill_date'] = $formatted_date;
			$submission_data['date']      = $formatted_date;
		}

		$html = $this->html_renderer->generate_html( $submission_data, $form_title, $form_config, $submission_date );

		// Get verification code.
		$auth_code = isset( $submission_data['auth_code'] ) ? $submission_data['auth_code'] : '';

		// 6.6.11 — standardized filename pattern (shared helper). See the
		// sibling site in generate_pdf_data() for the full rationale.
		$filename = \FreeFormCertificate\Core\FilenameHelper::build_pdf_filename( 'certificate', (int) $form_id, (string) $auth_code );

		// Log generation.
		\FreeFormCertificate\Core\Debug::log_pdf(
			'PDF data generated from form',
			array(
				'form_id'      => $form_id,
				'form_title'   => \FreeFormCertificate\Core\Utils::truncate( $form_title, 50 ),
				'html_length'  => strlen( $html ),
				'has_bg_image' => ! empty( $bg_image_url ),
			)
		);

		return array(
			'html'       => $html,
			'filename'   => $filename,
			'form_title' => $form_title,
			'submission' => $submission_data,
			'bg_image'   => $bg_image_url,
		);
	}

	/**
	 * Generate PDF data for appointment receipt
	 *
	 * Uses the same HTML→canvas→PDF pipeline as certificates.
	 * Template loaded from plugin default or overridden via filter.
	 *
	 * @since 4.2.0
	 * @param array<string, mixed> $appointment Appointment data array from database.
	 * @param array<string, mixed> $calendar Calendar data array from database.
	 * @return array{html: string, filename: string, form_title: string, bg_image: mixed, type: string} PDF data array (html, template, filename, bg_image)
	 */
	public function generate_appointment_pdf_data( array $appointment, array $calendar ): array {
		$formatted_date = __( 'N/A', 'ffcertificate' );
		if ( ! empty( $appointment['appointment_date'] ) ) {
			$ts = strtotime( $appointment['appointment_date'] );
			if ( false !== $ts ) {
				$formatted_date = \FreeFormCertificate\Core\DateFormatter::format_date( $ts, 'pdf' );
			}
		}

		$formatted_time = __( 'N/A', 'ffcertificate' );
		if ( ! empty( $appointment['start_time'] ) ) {
			$ts = strtotime( $appointment['start_time'] );
			if ( false !== $ts ) {
				$formatted_time = \FreeFormCertificate\Core\DateFormatter::format_time( $ts, 'pdf' );
			}
			if ( ! empty( $appointment['end_time'] ) ) {
				$ts2 = strtotime( $appointment['end_time'] );
				if ( false !== $ts2 ) {
					$formatted_time .= ' - ' . \FreeFormCertificate\Core\DateFormatter::format_time( $ts2, 'pdf' );
				}
			}
		}

		$formatted_created = __( 'N/A', 'ffcertificate' );
		if ( ! empty( $appointment['created_at'] ) ) {
			$ts = strtotime( $appointment['created_at'] );
			if ( false !== $ts ) {
				$formatted_created = \FreeFormCertificate\Core\DateFormatter::format_datetime( $ts, 'pdf' );
			}
		}

		// Decrypt sensitive data if needed.
		$email = \FreeFormCertificate\Core\Encryption::decrypt_field( $appointment, 'email' );

		// CPF/RF: data lives in the split cpf_encrypted / rf_encrypted columns.
		$cpf_rf = \FreeFormCertificate\Core\Encryption::decrypt_field( $appointment, 'cpf', 'cpf_encrypted' );
		if ( '' === $cpf_rf ) {
			$cpf_rf = \FreeFormCertificate\Core\Encryption::decrypt_field( $appointment, 'rf', 'rf_encrypted' );
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

		// Build data array for placeholder replacement.
		$data = array(
			'name'             => $appointment['name'] ?? '',
			'email'            => $email,
			'cpf_rf'           => $cpf_rf,
			'calendar_title'   => $calendar['title'] ?? __( 'N/A', 'ffcertificate' ),
			'appointment_date' => $formatted_date,
			'appointment_time' => $formatted_time,
			'created_at'       => $formatted_created,
			'status'           => $status_label,
			'validation_code'  => ! empty( $appointment['validation_code'] )
				? \FreeFormCertificate\Core\DocumentFormatter::format_auth_code( $appointment['validation_code'], \FreeFormCertificate\Core\DocumentFormatter::PREFIX_APPOINTMENT )
				: '',
			'auth_code'        => ! empty( $appointment['validation_code'] )
				? $appointment['validation_code']
				: '',
			'site_name'        => get_bloginfo( 'name' ),
		);

		// Add magic_token (confirmation_token) for QR code / validation URL.
		if ( ! empty( $appointment['confirmation_token'] ) ) {
			$data['magic_token'] = $appointment['confirmation_token'];
		}

		// Load receipt template.
		$template = $this->html_renderer->get_appointment_receipt_template();

		// Build form_config-like structure for generate_html.
		$form_config = array(
			'pdf_layout' => $template,
		);

		// Generate HTML using the existing generate_html() method.
		//
		// `appointment.created_at` is a DATETIME string (housekeeping
		// column kept as DATETIME per the Category A exception in
		// CLAUDE.md). `generate_html()` expects a unix timestamp
		// (`?int`) for the PDF date stamp — convert here. UTC because
		// `created_at` is auto-set by MySQL `DEFAULT CURRENT_TIMESTAMP`
		// which writes in the DB's timezone (UTC by convention).
		$calendar_title = $calendar['title'] ?? __( 'Appointment Receipt', 'ffcertificate' );
		$created_at_str = isset( $appointment['created_at'] ) ? (string) $appointment['created_at'] : '';
		$created_at_ts  = '' !== $created_at_str ? strtotime( $created_at_str . ' UTC' ) : false;
		$html           = $this->html_renderer->generate_html( $data, $calendar_title, $form_config, false !== $created_at_ts ? $created_at_ts : null );

		// 6.6.11 — standardized filename pattern via the shared helper
		// (`recibo_{calendar_id}_{validation_code}.pdf` in PT-BR sites).
		// Old code used `generate_filename(__('Appointment_Receipt'), …)`
		// which produced locale-dependent prefixes; the helper now resolves
		// the translated prefix internally and supports the central
		// `ffcertificate_pdf_filename` override hook.
		$validation_code = (string) ( $appointment['validation_code'] ?? '' );
		$calendar_id     = (int) ( $calendar['id'] ?? 0 );
		$filename        = \FreeFormCertificate\Core\FilenameHelper::build_pdf_filename( 'appointment_receipt', $calendar_id, $validation_code );

		/**
		 * Filters the appointment receipt PDF filename.
		 *
		 * Paired with `ffcertificate_certificate_filename` and
		 * `ffcertificate_ficha_filename`. New in 6.6.11 — this hook
		 * did not exist in pre-6.6.11 releases.
		 *
		 * @since 6.6.11
		 * @param string               $filename        Generated filename.
		 * @param int                  $calendar_id     Calendar post ID.
		 * @param string               $validation_code Validation code (raw, pre-format).
		 * @param array<string, mixed> $appointment     Appointment row data.
		 */
		$filename = (string) apply_filters( 'ffcertificate_appointment_receipt_filename', $filename, $calendar_id, $validation_code, $appointment );

		// Allow background image customization via filter.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- ffc_ is the plugin prefix
		$bg_image = apply_filters( 'ffcertificate_appointment_receipt_bg_image', '', $appointment, $calendar );

		return array(
			'html'       => $html,
			'filename'   => $filename,
			'form_title' => $calendar_title,
			'bg_image'   => $bg_image,
			'type'       => 'appointment_receipt',
		);
	}

	/**
	 * Resolve the effective wall-clock schedule range for a submission
	 * — the 3-tier hybrid from #366:
	 *
	 *   1. Per-submission override (`schedule_start_override` /
	 *      `schedule_end_override` columns) — if either is set,
	 *      it wins for that side and the other side falls back.
	 *   2. Form-level baseline (`_ffc_geofence_config['class_time_start' / 'class_time_end']`).
	 *   3. Geofence open window (`_ffc_geofence_config['time_start' / 'time_end']`).
	 *
	 * Each side is resolved INDEPENDENTLY. A form with only
	 * `class_time_end` set + a submission with only
	 * `schedule_start_override` set yields effective range
	 * `(override_start, class_time_end)` — picking from whichever
	 * tier has a value on each side.
	 *
	 * @param int                  $form_id   Form post id.
	 * @param array<string, mixed> $sub_array Raw `ffc_submissions` row.
	 * @return array{0: string, 1: string} `[start, end]` HH:MM strings, '' when no value resolves.
	 */
	private function resolve_effective_schedule( int $form_id, array $sub_array ): array {
		$geofence = get_post_meta( $form_id, '_ffc_geofence_config', true );
		if ( ! is_array( $geofence ) ) {
			$geofence = array();
		}

		$start = '';
		if ( ! empty( $sub_array['schedule_start_override'] ) ) {
			$start = (string) $sub_array['schedule_start_override'];
		} elseif ( ! empty( $geofence['class_time_start'] ) ) {
			$start = (string) $geofence['class_time_start'];
		} elseif ( ! empty( $geofence['time_start'] ) ) {
			$start = (string) $geofence['time_start'];
		}

		$end = '';
		if ( ! empty( $sub_array['schedule_end_override'] ) ) {
			$end = (string) $sub_array['schedule_end_override'];
		} elseif ( ! empty( $geofence['class_time_end'] ) ) {
			$end = (string) $geofence['class_time_end'];
		} elseif ( ! empty( $geofence['time_end'] ) ) {
			$end = (string) $geofence['time_end'];
		}

		return array( $start, $end );
	}
}

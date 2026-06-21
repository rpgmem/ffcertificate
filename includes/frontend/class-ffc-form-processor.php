<?php
/**
 * FormProcessor
 * Handles form submission processing, validation, and restriction checks.
 *
 * V2.9.2: Unified PDF generation with FFC_PDF_Generator
 * v2.9.11: Using FFC_Utils for validation and sanitization
 * v2.9.13: Optimized detect_reprint() to use cpf_rf column with fallback
 * v2.10.0: LGPD - Validates consent checkbox (mandatory)
 * v3.3.0: Added strict types and type hints
 * v3.2.0: Migrated to namespace (Phase 2)
 * v4.12.17: Extracted AccessRestrictionChecker and ReprintDetector for SRP compliance.
 *
 * @package FreeFormCertificate\Frontend
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

use FreeFormCertificate\Submissions\SubmissionHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Processor for form operations.
 */
class FormProcessor {

	/**
	 * Submission handler.
	 *
	 * @var SubmissionHandler
	 */
	private $submission_handler;

	/**
	 * Constructor
	 *
	 * @param SubmissionHandler $submission_handler Submission handler.
	 */
	public function __construct( SubmissionHandler $submission_handler ) {
		$this->submission_handler = $submission_handler;

		// AJAX hooks registered in Frontend::register_hooks() to avoid duplicate registration.
	}

	/**
	 * Handle form submission via AJAX
	 */
	public function handle_submission_ajax(): void {
		$ctx = new Submission\SubmissionContext();

		// #563 Sprint 1 — thin orchestrator over the submission pipeline.
		// Stages 1-13 (guards) validate/resolve into the context; stages
		// 14-17 persist the submission, render the PDF and assemble the
		// response. Any stage throws SubmissionRejected carrying the exact
		// wp_send_json_error payload; the single catch emits it. On success
		// SuccessResponder has populated $ctx->response, handed to
		// wp_send_json_success below.
		try {
			( new Submission\ScheduleExceptionGuard() )->apply( $ctx );
			( new Submission\IpRateLimitGuard() )->apply( $ctx );
			( new Submission\NonceGuard() )->apply( $ctx );
			( new Submission\SecurityFieldsGuard() )->apply( $ctx );
			( new Submission\FormConfigResolver() )->apply( $ctx );
			( new Submission\PreflightGuard() )->apply( $ctx );
			( new Submission\FieldSanitizer() )->apply( $ctx );
			( new Submission\DeviceSignalsResolver() )->apply( $ctx );
			( new Submission\GeofenceGuard() )->apply( $ctx );
			( new Submission\RateLimitGuard() )->apply( $ctx );
			( new Submission\AccessRestrictionGuard() )->apply( $ctx );

			( new Submission\SubmissionPersister( $this->submission_handler ) )->apply( $ctx );
			( new Submission\PdfStage( $this->submission_handler ) )->apply( $ctx );
			( new Submission\SuccessResponder( $this->submission_handler ) )->apply( $ctx );
		} catch ( Submission\SubmissionRejected $rejected ) {
			wp_send_json_error( $rejected->get_payload() );
		}

		wp_send_json_success( $ctx->response );
	}
}

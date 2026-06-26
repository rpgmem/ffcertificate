<?php
/**
 * PdfStage — pipeline stage 15 (#563 Sprint 1, PR 1b).
 *
 * Generates the certificate PDF payload for the persisted submission and
 * stores it on the context. Rejects (preserving the legacy payload) when the
 * generator returns a WP_Error.
 *
 * @package FreeFormCertificate\Frontend\Submission
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend\Submission;

use FreeFormCertificate\Submissions\SubmissionHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PDF generation stage.
 */
class PdfStage {

	/**
	 * Submission handler.
	 *
	 * @var SubmissionHandler
	 */
	private $submission_handler;

	/**
	 * Constructor.
	 *
	 * @param SubmissionHandler $submission_handler Submission handler.
	 */
	public function __construct( SubmissionHandler $submission_handler ) {
		$this->submission_handler = $submission_handler;
	}

	/**
	 * Generate the PDF payload onto the context.
	 *
	 * @param SubmissionContext $ctx Submission context.
	 * @throws SubmissionRejected When PDF generation returns a WP_Error.
	 */
	public function apply( SubmissionContext $ctx ): void {
		$pdf_generator = new \FreeFormCertificate\Generators\PdfGenerator();
		$pdf_data      = $pdf_generator->generate_pdf_data(
			$ctx->submission_id,
			$this->submission_handler
		);

		if ( is_wp_error( $pdf_data ) ) {
			throw new SubmissionRejected(
				array(
					'code'    => $pdf_data->get_error_code(),
					'message' => $pdf_data->get_error_message(),
				)
			);
		}

		$ctx->pdf_data = $pdf_data;
	}
}

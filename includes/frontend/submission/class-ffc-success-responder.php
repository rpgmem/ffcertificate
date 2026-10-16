<?php
/**
 * SuccessResponder — pipeline stages 16–17 (#563 Sprint 1, PR 1b).
 *
 * Builds the success message and the final response payload (message, PDF
 * data, rendered success HTML, and the quiz block when applicable) onto the
 * context. The thin orchestrator emits it via wp_send_json_success() — the
 * success-side mirror of the SubmissionRejected → wp_send_json_error() path.
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
 * Success message + response assembly stage.
 */
class SuccessResponder {

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
	 * Assemble the success response onto the context.
	 *
	 * @param SubmissionContext $ctx Submission context.
	 */
	public function apply( SubmissionContext $ctx ): void {
		if ( $ctx->awaiting_grading ) {
			$ctx->response = self::awaiting_grading_response();
			return;
		}

		$form_config = $ctx->form_config;
		$is_reprint  = $ctx->is_reprint;
		$is_quiz     = $ctx->is_quiz;
		$quiz_score  = $ctx->quiz_score;

		// Success message. The auth code is rendered in the success card by
		// templates/submission-success.php, so it is deliberately kept out of
		// $msg to avoid showing the same code twice on reprint.
		$custom_message = isset( $form_config['success_message'] ) ? trim( $form_config['success_message'] ) : '';
		$msg            = $is_reprint
			? __( 'Certificate previously issued (Reprint).', 'ffcertificate' )
			: ( ! empty( $custom_message ) ? $custom_message : __( 'Success!', 'ffcertificate' ) );

		// Quiz passed message.
		if ( $is_quiz && ! $is_reprint && null !== $quiz_score ) {
			$show_score = ( $form_config['quiz_show_score'] ?? '1' ) === '1';
			$msg        = $show_score
				/* translators: %d: quiz score percentage */
				? sprintf( __( 'Congratulations! Score: %d%%. Certificate generated.', 'ffcertificate' ), $quiz_score['percent'] )
				: __( 'Congratulations! Quiz passed. Certificate generated.', 'ffcertificate' );

			$shown = SubmissionPersister::shown_results( $form_config, $quiz_score );
			if ( null !== $shown ) {
				$msg .= ' ' . sprintf(
					/* translators: 1: questions answered right, 2: questions scored. */
					_n( '%1$s of %2$s question answered correctly.', '%1$s of %2$s questions answered correctly.', $shown['question_count'], 'ffcertificate' ),
					number_format_i18n( $shown['correct_count'] ),
					number_format_i18n( $shown['question_count'] )
				);
			}
		}

		$response = array(
			'message'  => $msg,
			'pdf_data' => $ctx->pdf_data,
			'html'     => SuccessHtmlRenderer::generate_success_html(
				$ctx->submission_data,
				$ctx->form_id,
				$ctx->real_submission_date,
				$msg,
				$ctx->submission_id,
				$this->submission_handler
			),
		);

		// Add quiz data to success response.
		if ( $is_quiz && null !== $quiz_score ) {
			$show_score       = ( $form_config['quiz_show_score'] ?? '1' ) === '1';
			$response['quiz'] = array(
				'passed'    => true,
				'score'     => $show_score ? $quiz_score['score'] : null,
				'max_score' => $show_score ? $quiz_score['max_score'] : null,
				'percent'   => $show_score ? $quiz_score['percent'] : null,
			);
		}

		$ctx->response = $response;
	}

	/**
	 * The response to an attempt a person has still to grade (#1732): it
	 * replaces the form, like a certificate does, but carries no PDF. The
	 * html is a system notice, which `ffc-frontend.js` places above the form
	 * title (#1842).
	 *
	 * @return array{message: string, pdf_data: null, html: string, quiz: array{pending: true}}
	 */
	public static function awaiting_grading_response(): array {
		$msg = __( 'Your answers were received. A person will grade the open answers; the result, and the certificate if you pass, will be sent to your email.', 'ffcertificate' );
		return array(
			'message'  => $msg,
			'pdf_data' => null,
			'html'     => \FreeFormCertificate\Core\Notice::render(
				'info',
				'clock',
				$msg,
				'',
				array(
					'role' => 'status',
					'hook' => 'awaiting-grading',
				)
			),
			'quiz'     => array( 'pending' => true ),
		);
	}
}

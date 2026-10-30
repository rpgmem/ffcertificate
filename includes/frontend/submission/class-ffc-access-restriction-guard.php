<?php
/**
 * AccessRestrictionGuard — pipeline stage 13 (#563 Sprint 1).
 *
 * Checks the form's access restrictions (whitelist / denylist / tickets)
 * via AccessRestrictionChecker and stores the result on the context so a
 * later stage can consume a one-use ticket on a successful new submission.
 *
 * @package FreeFormCertificate\Frontend\Submission
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend\Submission;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whitelist / denylist / ticket access gate.
 */
class AccessRestrictionGuard {

	/**
	 * Reject the request when access restrictions deny it.
	 *
	 * @param SubmissionContext $ctx Submission context.
	 * @throws SubmissionRejected When access is denied.
	 */
	public function apply( SubmissionContext $ctx ): void {
		// A spent ticket is checked against the submission's single lookup
		// (#2241); the ticket it is keyed on is the one checked here.
		$restriction_result = \FreeFormCertificate\Frontend\AccessRestrictionChecker::check(
			$ctx->form_config,
			$ctx->val_cpf,
			$ctx->val_ticket,
			$ctx->form_id,
			$ctx->val_password,
			static fn(): bool => \FreeFormCertificate\Frontend\ReprintDetector::for_context( $ctx )['is_reprint']
		);

		if ( ! $restriction_result['allowed'] ) {
			throw new SubmissionRejected( array( 'message' => $restriction_result['message'] ) );
		}

		$ctx->restriction_result = $restriction_result;
	}
}

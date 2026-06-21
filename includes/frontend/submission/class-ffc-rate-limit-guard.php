<?php
/**
 * RateLimitGuard — pipeline stage 12 (#563 Sprint 1).
 *
 * The consolidated rate-limit check (IP + email + CPF + device). Pre-runs
 * ReprintDetector so a legitimate reprint from the same device whitelists
 * the per-device gate (via skip_device). On pass it records the IP / email /
 * CPF attempts and registers a deferred hook to persist the device-signal
 * hashes once the submission row exists (the FK becomes available only
 * after save, which the orchestrator performs in a later stage).
 *
 * @package FreeFormCertificate\Frontend\Submission
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend\Submission;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Consolidated rate-limit gate.
 */
class RateLimitGuard {

	/**
	 * Reject the request when any rate-limit dimension is exceeded.
	 *
	 * @param SubmissionContext $ctx Submission context.
	 * @throws SubmissionRejected When a rate limit is exceeded.
	 */
	public function apply( SubmissionContext $ctx ): void {
		if ( ! class_exists( '\FreeFormCertificate\Security\RateLimiter' ) ) {
			return;
		}

		$ip    = \FreeFormCertificate\Core\RequestInput::get_user_ip();
		$email = $ctx->user_email;
		$cpf   = $ctx->val_cpf;

		// 6.3.10: a successful reprint of an already-issued certificate must
		// not be blocked by the per-device limit — that gate stops the same
		// device creating multiple FRESH submissions for different CPFs, not
		// re-downloading one's own certificate. Pre-run ReprintDetector and
		// whitelist the device check via the existing skip_device flag (same
		// flag the manager bypass uses, so check_all stays untouched).
		if ( ! $ctx->skip_device
			&& '' !== $ctx->val_cpf
			&& class_exists( '\FreeFormCertificate\Frontend\ReprintDetector' ) ) {
			$reprint_preview = \FreeFormCertificate\Frontend\ReprintDetector::detect( $ctx->form_id, $ctx->val_cpf, $ctx->val_ticket );
			if ( ! empty( $reprint_preview['is_reprint'] ) ) {
				$ctx->skip_device = true;
			}
		}

		$rate_check = \FreeFormCertificate\Security\RateLimiter::check_all( $ip, $email, $cpf, $ctx->form_id, $ctx->device_signals, $ctx->skip_device );

		if ( ! $rate_check['allowed'] ) {
			throw new SubmissionRejected(
				array(
					'message'      => $rate_check['message'] ?? 'Rate limit exceeded.',
					'rate_limit'   => true,
					'wait_seconds' => $rate_check['wait_seconds'] ?? 0,
				)
			);
		}

		// Record attempt.
		\FreeFormCertificate\Security\RateLimiter::record_attempt( 'ip', $ip, $ctx->form_id );
		\FreeFormCertificate\Security\RateLimiter::record_attempt( 'email', $email, $ctx->form_id );
		if ( $cpf ) {
			\FreeFormCertificate\Security\RateLimiter::record_attempt( 'cpf', \FreeFormCertificate\Core\DataSanitizer::normalize_cpf_rf( $cpf ), $ctx->form_id );
		}

		// Persist device fingerprint hashes once the submission row has been
		// created (so the submission_id FK is available). Skipped when the
		// manager bypass fired or when no usable signals arrived.
		if ( ! $ctx->skip_device && is_array( $ctx->device_signals ) ) {
			$signals_to_record = $ctx->device_signals;
			$target_form_id    = $ctx->form_id;
			add_action(
				'ffcertificate_after_submission_save',
				static function ( $submission_id, $saved_form_id ) use ( $signals_to_record, $target_form_id ) {
					if ( (int) $saved_form_id !== (int) $target_form_id ) {
						return;
					}
					\FreeFormCertificate\Security\RateLimiter::record_device_signals(
						is_numeric( $submission_id ) ? (int) $submission_id : null,
						(int) $saved_form_id,
						$signals_to_record
					);
				},
				10,
				2
			);
		}
	}
}

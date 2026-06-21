<?php
/**
 * ScheduleExceptionGuard — pipeline stage 1 (#563 Sprint 1).
 *
 * Reads + verifies the hidden schedule-exception token Sprint 5 embeds in
 * the form. A valid payload means an operator staged this submission as an
 * exception; downstream the orchestrator (a) skips the IP rate-limit gate,
 * (b) persists the override TIME columns, (c) emits two audit rows. Run
 * before the IP gate so the operator's bypass takes effect even when the
 * venue's network has saturated the per-IP throttle.
 *
 * The token IS a signed credential (HMAC over the payload + 30 min expiry +
 * form_id binding), so verifying it before the WP nonce check is safe — the
 * token is the auth for the exception path, not the nonce.
 *
 * Never rejects: a missing/invalid token simply means "no exception".
 *
 * @package FreeFormCertificate\Frontend\Submission
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend\Submission;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the schedule-exception token into the submission context.
 */
class ScheduleExceptionGuard {

	/**
	 * Populate the schedule-exception state on the context.
	 *
	 * @param SubmissionContext $ctx Submission context.
	 */
	public function apply( SubmissionContext $ctx ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- token verified via HMAC immediately below; form_id sanitized via absint.
		if ( isset( $_POST['ffc_schedule_exception_token'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- HMAC verifies integrity.
			$token_raw = (string) wp_unslash( $_POST['ffc_schedule_exception_token'] );
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$token_form = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0;

			$payload                         = self::live_exception_payload( $token_raw, $token_form );
			$ctx->schedule_exception_payload = $payload;
			$ctx->has_exception              = null !== $payload;
		}
	}

	/**
	 * Resolve a posted schedule-exception token to its payload IFF it is
	 * still "live" — signature + expiry valid, scoped to the posted form,
	 * and its `jti` not already in the consumed ledger (#Item11). Returns
	 * null otherwise, which the caller treats as "no exception" (no IP-limit
	 * bypass, no override). The authoritative atomic claim still happens at
	 * persist time, so a double-click race resolves to one adjusted cert;
	 * this early check only spares a known-spent token the bypass.
	 *
	 * @param string $token_raw  Raw token from the form body.
	 * @param int    $token_form Posted form id the token must be scoped to.
	 * @return array<string, mixed>|null Verified payload, or null when not live.
	 */
	public static function live_exception_payload( string $token_raw, int $token_form ): ?array {
		$verified = \FreeFormCertificate\Frontend\ScheduleExceptionSession::verify_token( $token_raw );
		if ( null === $verified || $token_form <= 0 || (int) ( $verified['form_id'] ?? 0 ) !== $token_form ) {
			return null;
		}
		$jti = (string) ( $verified['jti'] ?? '' );
		if ( '' === $jti || \FreeFormCertificate\Frontend\ScheduleExceptionSession::is_jti_consumed( $jti ) ) {
			return null;
		}
		return $verified;
	}
}

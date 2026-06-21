<?php
/**
 * SubmissionRejected
 *
 * Single rejection channel for the submission pipeline (#563 Sprint 1).
 * Each entry guard throws this exception carrying the exact array payload
 * the legacy `handle_submission_ajax()` used to hand to
 * `wp_send_json_error()`. The thin orchestrator catches it once and emits
 * that payload, so the external JSON contract (keys, ordering, single exit)
 * stays byte-identical while each gate becomes a pure, testable unit.
 *
 * @package FreeFormCertificate\Frontend\Submission
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend\Submission;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carries a `wp_send_json_error()` payload from a guard to the orchestrator.
 */
class SubmissionRejected extends \Exception {

	/**
	 * The error payload to hand to wp_send_json_error().
	 *
	 * @var array<string, mixed>
	 */
	private array $payload;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $payload Error payload (same shape the
	 *                                      legacy handler passed to
	 *                                      wp_send_json_error()).
	 */
	public function __construct( array $payload ) {
		$this->payload = $payload;
		$message       = isset( $payload['message'] ) && is_string( $payload['message'] ) ? $payload['message'] : '';
		parent::__construct( $message );
	}

	/**
	 * The error payload for wp_send_json_error().
	 *
	 * @return array<string, mixed>
	 */
	public function get_payload(): array {
		return $this->payload;
	}
}

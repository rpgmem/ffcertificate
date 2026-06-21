<?php
/**
 * CpfLimiter — per-CPF rate-limit strategy (#563 Sprint 4, A4).
 *
 * Temporary-block check + month/year submission counts (DB, gated by
 * `check_database`) + an hour abuse threshold that triggers a temporary block.
 * Extracted verbatim from RateLimitChecker::check_cpf_limit().
 *
 * @package FreeFormCertificate\Security
 */

declare(strict_types=1);

namespace FreeFormCertificate\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-CPF submission rate limit.
 */
class CpfLimiter implements RateLimitStrategy {

	/**
	 * Shared support (settings + message formatter).
	 *
	 * @var RateLimitSupport
	 */
	private RateLimitSupport $support;

	/**
	 * Constructor.
	 *
	 * @param RateLimitSupport $support Injected shared support.
	 */
	public function __construct( RateLimitSupport $support ) {
		$this->support = $support;
	}

	/**
	 * Evaluate the per-CPF limit.
	 *
	 * @param string   $identifier CPF/RF document digits.
	 * @param int|null $form_id    Form ID.
	 * @return array{allowed: bool, message?: string, reason?: string, wait_seconds?: int}
	 */
	public function check( string $identifier, ?int $form_id = null ): array {
		$s  = $this->support->settings()['cpf'];
		$cc = \FreeFormCertificate\Core\DataSanitizer::normalize_cpf_rf( $identifier );

		if ( RateLimitRepository::is_temporarily_blocked( 'cpf', $cc, $form_id ) ) {
			return array(
				'allowed'      => false,
				'reason'       => 'cpf_blocked',
				'message'      => __( 'CPF blocked.', 'ffcertificate' ),
				'wait_seconds' => 86400,
			);
		}

		if ( $s['check_database'] ) {
			$mc = RateLimitRepository::get_submission_count( 'cpf', $cc, 'month', $form_id );
			if ( $mc >= $s['max_per_month'] ) {
				return array(
					'allowed'      => false,
					'reason'       => 'cpf_month_limit',
					'message'      => $s['message'],
					'wait_seconds' => 2592000,
				);
			}

			$yc = RateLimitRepository::get_submission_count( 'cpf', $cc, 'year', $form_id );
			if ( $yc >= $s['max_per_year'] ) {
				return array(
					'allowed'      => false,
					'reason'       => 'cpf_year_limit',
					'message'      => $s['message'],
					'wait_seconds' => 31536000,
				);
			}
		}

		$ac = RateLimitRepository::get_count_from_db( 'cpf', $cc, 'hour', $form_id );
		if ( $ac >= $s['block_threshold'] ) {
			RateLimitRepository::block_temporarily( 'cpf', $cc, $form_id, $s['block_duration'] );
			return array(
				'allowed'      => false,
				'reason'       => 'cpf_abuse',
				'message'      => __( 'CPF blocked.', 'ffcertificate' ),
				'wait_seconds' => $s['block_duration'] * 3600,
			);
		}

		return array( 'allowed' => true );
	}
}

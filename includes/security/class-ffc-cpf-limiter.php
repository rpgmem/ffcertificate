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
 *
 * @phpstan-import-type RateLimitVerdict from RateLimitChecker
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
	 * @return RateLimitVerdict
	 */
	public function check( string $identifier, ?int $form_id = null ): array {
		$s  = $this->support->settings()['cpf'];
		$cc = \FreeFormCertificate\Core\DataSanitizer::normalize_cpf_rf( $identifier );

		// Already blocked: the wait is what is left of the block, not a fixed
		// day nor the configured duration from now (#2339).
		$until = RateLimitRepository::blocked_until( 'cpf', $cc, $form_id );
		if ( null !== $until ) {
			$wait = max( 1, $until - time() );
			return array(
				'allowed'      => false,
				'reason'       => 'cpf_blocked',
				'message'      => self::blocked_message( $this->support, $wait ),
				'wait_seconds' => $wait,
			);
		}

		if ( $s['check_database'] ) {
			foreach ( array(
				'month' => array(
					'max'    => (int) $s['max_per_month'],
					'reason' => 'cpf_month_limit',
				),
				'year'  => array(
					'max'    => (int) $s['max_per_year'],
					'reason' => 'cpf_year_limit',
				),
			) as $period => $limit ) {
				$count = RateLimitRepository::get_submission_count( 'cpf', $cc, $period, $form_id );
				if ( $count >= $limit['max'] ) {
					$wait = $this->support->period_wait( RateLimitRepository::oldest_submission_in( 'cpf', $cc, $period, $form_id ), $period );
					return array(
						'allowed'      => false,
						'reason'       => $limit['reason'],
						'message'      => $this->support->format_message(
							$s['message'],
							array(
								'count' => $count,
								'max'   => $limit['max'],
								'wait'  => $wait,
							)
						),
						'wait_seconds' => $wait,
					);
				}
			}
		}

		// "Block after N attempts in X hours" counts over X hours (#2339).
		$hours = max( 1, (int) $s['block_hours'] );
		$ac    = RateLimitRepository::get_hourly_count_since( 'cpf', $cc, $hours, $form_id );
		if ( $ac >= $s['block_threshold'] ) {
			$duration = max( 1, (int) $s['block_duration'] );
			RateLimitRepository::block_temporarily( 'cpf', $cc, $form_id, $duration );
			return array(
				'allowed'      => false,
				'reason'       => 'cpf_abuse',
				'message'      => self::blocked_message( $this->support, $duration * HOUR_IN_SECONDS ),
				'wait_seconds' => $duration * HOUR_IN_SECONDS,
			);
		}

		return array( 'allowed' => true );
	}

	/**
	 * The refusal for a CPF/RF under a temporary block.
	 *
	 * @param RateLimitSupport $support Support.
	 * @param int              $wait    Seconds left of the block.
	 * @return string
	 */
	private static function blocked_message( RateLimitSupport $support, int $wait ): string {
		return $support->format_message(
			__( 'Too many attempts with this CPF/RF. It is blocked for {time}; try again after that.', 'ffcertificate' ),
			array( 'wait' => $wait )
		);
	}
}

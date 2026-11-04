<?php
/**
 * EmailLimiter — per-email rate-limit strategy (#563 Sprint 4, A4).
 *
 * Day / week / month submission counts (DB), gated by the per-type
 * `check_database` toggle. Extracted verbatim from
 * RateLimitChecker::check_email_limit().
 *
 * @package FreeFormCertificate\Security
 */

declare(strict_types=1);

namespace FreeFormCertificate\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-email submission rate limit.
 *
 * @phpstan-import-type RateLimitVerdict from RateLimitChecker
 */
class EmailLimiter implements RateLimitStrategy {

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
	 * Evaluate the per-email limit.
	 *
	 * @param string   $identifier Email address.
	 * @param int|null $form_id    Form ID.
	 * @return RateLimitVerdict
	 */
	public function check( string $identifier, ?int $form_id = null ): array {
		$email = $identifier;
		$s     = $this->support->settings()['email'];
		if ( ! $s['check_database'] ) {
			return array( 'allowed' => true );
		}

		// The minimum interval between two submissions with one e-mail
		// (#2339); 0 is off.
		$interval = max( 0, (int) ( $s['wait_hours'] ?? 0 ) ) * HOUR_IN_SECONDS;
		if ( $interval > 0 ) {
			$latest = RateLimitRepository::latest_submission( 'email', $email, $form_id );
			if ( null !== $latest && $latest + $interval > time() ) {
				$wait = max( 1, $latest + $interval - time() );
				return array(
					'allowed'      => false,
					'reason'       => 'email_interval',
					'message'      => $this->support->format_message(
						__( 'A certificate was issued to this e-mail a moment ago. You can submit again in {time}.', 'ffcertificate' ),
						array( 'wait' => $wait )
					),
					'wait_seconds' => $wait,
				);
			}
		}

		foreach ( array(
			'day'   => array(
				'max'    => (int) $s['max_per_day'],
				'reason' => 'email_day_limit',
			),
			'week'  => array(
				'max'    => (int) $s['max_per_week'],
				'reason' => 'email_week_limit',
			),
			'month' => array(
				'max'    => (int) $s['max_per_month'],
				'reason' => 'email_month_limit',
			),
		) as $period => $limit ) {
			$count = RateLimitRepository::get_submission_count( 'email', $email, $period, $form_id );
			if ( $count >= $limit['max'] ) {
				$wait = $this->support->period_wait( RateLimitRepository::oldest_submission_in( 'email', $email, $period, $form_id ), $period );
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

		return array( 'allowed' => true );
	}
}

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
	 * @return array{allowed: bool, message?: string, reason?: string, wait_seconds?: int}
	 */
	public function check( string $identifier, ?int $form_id = null ): array {
		$email = $identifier;
		$s     = $this->support->settings()['email'];
		if ( ! $s['check_database'] ) {
			return array( 'allowed' => true );
		}

		$dc = RateLimitRepository::get_submission_count( 'email', $email, 'day', $form_id );
		if ( $dc >= $s['max_per_day'] ) {
			return array(
				'allowed'      => false,
				'reason'       => 'email_day_limit',
				'message'      => $this->support->format_message(
					$s['message'],
					array(
						'count' => $dc,
						'time'  => __( '24 hours', 'ffcertificate' ),
					)
				),
				'wait_seconds' => 86400,
			);
		}

		$wc = RateLimitRepository::get_submission_count( 'email', $email, 'week', $form_id );
		if ( $wc >= $s['max_per_week'] ) {
			return array(
				'allowed'      => false,
				'reason'       => 'email_week_limit',
				'message'      => $this->support->format_message(
					$s['message'],
					array(
						'count' => $wc,
						'time'  => __( '1 week', 'ffcertificate' ),
					)
				),
				'wait_seconds' => 604800,
			);
		}

		$mc = RateLimitRepository::get_submission_count( 'email', $email, 'month', $form_id );
		if ( $mc >= $s['max_per_month'] ) {
			return array(
				'allowed'      => false,
				'reason'       => 'email_month_limit',
				'message'      => $this->support->format_message(
					$s['message'],
					array(
						'count' => $mc,
						'time'  => __( '1 month', 'ffcertificate' ),
					)
				),
				'wait_seconds' => 2592000,
			);
		}

		return array( 'allowed' => true );
	}
}

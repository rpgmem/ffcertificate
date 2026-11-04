<?php
/**
 * RateLimitSupport
 *
 * Shared dependencies the per-dimension rate-limit strategies need (#563
 * Sprint 4, A4): the resolved settings array and the message formatter.
 * Injected into each strategy's constructor so the strategies stay pure and
 * unit-testable — pass a fixture settings array in tests, or let it lazily
 * resolve the live settings in production.
 *
 * @package FreeFormCertificate\Security
 */

declare(strict_types=1);

namespace FreeFormCertificate\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared support for rate-limit strategies.
 */
class RateLimitSupport {

	/**
	 * Resolved settings, or null until first accessed.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $settings;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>|null $settings Pre-resolved settings (tests),
	 *                                            or null to resolve lazily from
	 *                                            the live RateLimitChecker config.
	 */
	public function __construct( ?array $settings = null ) {
		$this->settings = $settings;
	}

	/**
	 * The resolved rate-limit settings array.
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		if ( null === $this->settings ) {
			$this->settings = RateLimitChecker::get_settings();
		}
		return $this->settings;
	}

	/**
	 * Interpolate a rate-limit message template.
	 *
	 * One resolution for every limiter (#2339), so a token means the same
	 * thing wherever an administrator writes it:
	 *
	 * - `{time}` is the real time left, from the counter's window or the
	 *   block's expiry, in words ("12 minutes"); "a while" when Show wait time
	 *   is off, since the wait is then not to be told.
	 * - `{count}` is what was counted, `{max}` the limit, `{remaining}` what is
	 *   left of it, never below 0.
	 *
	 * With Show remaining attempts on, a message that names neither `{count}`
	 * nor `{max}` gains "You have used N of M." — on a refusal nothing is
	 * left, so how much was used is what tells the visitor something.
	 *
	 * @param string               $template Message template.
	 * @param array<string, mixed> $data     `count`, `max` and `wait` (seconds) as known.
	 * @return string
	 */
	public function format_message( string $template, array $data ): string {
		if ( '' === trim( $template ) ) {
			$template = __( 'Submission limit reached. Please wait {time} and try again.', 'ffcertificate' );
		}

		$ui    = (array) ( $this->settings()['ui'] ?? array() );
		$count = max( 0, (int) ( $data['count'] ?? 0 ) );
		$max   = max( 0, (int) ( $data['max'] ?? 0 ) );
		$wait  = max( 0, (int) ( $data['wait'] ?? 0 ) );

		if ( ! empty( $ui['show_remaining'] ) && $max > 0 && false === strpos( $template, '{count}' ) && false === strpos( $template, '{max}' ) ) {
			$template = rtrim( $template ) . ' ' . sprintf(
				/* translators: 1: submissions counted, 2: the limit */
				__( 'You have used %1$d of %2$d.', 'ffcertificate' ),
				$count,
				$max
			);
		}

		return str_replace(
			array( '{time}', '{count}', '{max}', '{remaining}' ),
			array(
				self::time_token( $wait, ! empty( $ui['show_wait_time'] ) ),
				(string) $count,
				(string) $max,
				(string) max( 0, $max - $count ),
			),
			$template
		);
	}

	/**
	 * Whether a refused form shows the live countdown (#2339): Countdown
	 * timer on, and Show wait time on too, since the countdown tells the
	 * wait to the second.
	 *
	 * @return bool
	 */
	public function countdown_on(): bool {
		$ui = (array) ( $this->settings()['ui'] ?? array() );
		return ! empty( $ui['countdown_timer'] ) && ! empty( $ui['show_wait_time'] );
	}

	/**
	 * What `{time}` reads: the wait in words, or "a while" when the wait is
	 * not to be shown or is not known.
	 *
	 * @param int  $seconds Seconds left.
	 * @param bool $show    Whether Show wait time is on.
	 * @return string
	 */
	public static function time_token( int $seconds, bool $show ): string {
		if ( ! $show || $seconds <= 0 ) {
			return __( 'a while', 'ffcertificate' );
		}

		return self::wait_words( $seconds );
	}

	/**
	 * A wait in words: seconds under a minute, then minutes, then hours, then
	 * days, each rounded up so the visitor never comes back too early.
	 *
	 * @param int $seconds Seconds left, at least 1.
	 * @return string
	 */
	public static function wait_words( int $seconds ): string {
		$seconds = max( 1, $seconds );
		if ( $seconds < MINUTE_IN_SECONDS ) {
			/* translators: %d: number of seconds */
			return sprintf( _n( '%d second', '%d seconds', $seconds, 'ffcertificate' ), $seconds );
		}

		$minutes = (int) ceil( $seconds / MINUTE_IN_SECONDS );
		if ( $minutes < 60 ) {
			/* translators: %d: number of minutes */
			return sprintf( _n( '%d minute', '%d minutes', $minutes, 'ffcertificate' ), $minutes );
		}

		$hours = (int) ceil( $seconds / HOUR_IN_SECONDS );
		if ( $hours < 48 ) {
			/* translators: %d: number of hours */
			return sprintf( _n( '%d hour', '%d hours', $hours, 'ffcertificate' ), $hours );
		}

		$days = (int) ceil( $seconds / DAY_IN_SECONDS );
		/* translators: %d: number of days */
		return sprintf( _n( '%d day', '%d days', $days, 'ffcertificate' ), $days );
	}

	/**
	 * Seconds until a period limit counts one submission fewer: when the
	 * oldest submission it counts leaves the rolling window.
	 *
	 * @param int|null                    $oldest Unix time of that submission, or null.
	 * @param 'day'|'week'|'month'|'year' $period Period.
	 * @return int Seconds, at least 1; the whole period when unknown.
	 */
	public function period_wait( ?int $oldest, string $period ): int {
		$length = RateLimitRepository::SUBMISSION_WINDOW_DAYS[ $period ] * DAY_IN_SECONDS;
		if ( null === $oldest ) {
			return $length;
		}

		return max( 1, $oldest + $length - time() );
	}

	/**
	 * Seconds until a fixed window of the given length ends.
	 *
	 * @param int $window Window length in seconds.
	 * @return int
	 */
	public static function window_left( int $window ): int {
		$window = max( 1, $window );
		return $window - ( time() % $window );
	}
}

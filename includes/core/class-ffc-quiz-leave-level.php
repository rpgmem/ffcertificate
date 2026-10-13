<?php
/**
 * QuizLeaveLevel
 *
 * How strictly a quiz reacts to the participant leaving it (#1731, #1745).
 * The level is stored in the form's `quiz_reset_on_leave`, which held a
 * checkbox's `0` / `1` before it had three positions: `1` therefore stays
 * Moderate, and an existing form keeps the behaviour it was saved with.
 *
 * - Off: nothing happens.
 * - Moderate: the tab stops being visible (another tab, a minimised browser).
 * - Strict: also the window losing the focus (two windows side by side),
 *   counted only when the loss outlasts the global grace period.
 *
 * @package FreeFormCertificate\Core
 * @since 6.39.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The window-leave levels and the rule that reads them.
 */
final class QuizLeaveLevel {

	/**
	 * No reset.
	 */
	public const OFF = '0';

	/**
	 * Reset when the tab stops being visible.
	 */
	public const MODERATE = '1';

	/**
	 * Reset also when the window loses the focus.
	 */
	public const STRICT = '2';

	/**
	 * The levels, mildest first.
	 *
	 * @var array<int, string>
	 */
	public const LEVELS = array( self::OFF, self::MODERATE, self::STRICT );

	/**
	 * Default grace before a lost focus counts, in milliseconds.
	 */
	public const GRACE_DEFAULT_MS = 1000;

	/**
	 * Smallest grace: every loss counts.
	 */
	public const GRACE_MIN_MS = 0;

	/**
	 * Largest grace.
	 */
	public const GRACE_MAX_MS = 10000;

	/**
	 * A stored or posted level, or Off when it is not one.
	 *
	 * @param mixed $raw Raw value.
	 */
	public static function sanitize( $raw ): string {
		$value = is_scalar( $raw ) ? (string) $raw : '';
		return in_array( $value, self::LEVELS, true ) ? $value : self::OFF;
	}

	/**
	 * The level a form is set to. A form that is not a quiz has none.
	 *
	 * @param array<array-key, mixed> $form_config Form configuration.
	 */
	public static function of( array $form_config ): string {
		if ( '1' !== ( $form_config['quiz_enabled'] ?? '' ) ) {
			return self::OFF;
		}
		return self::sanitize( $form_config['quiz_reset_on_leave'] ?? self::OFF );
	}

	/**
	 * A grace value clamped to its bounds.
	 *
	 * @param int $ms Milliseconds.
	 */
	public static function clamp_grace( int $ms ): int {
		return max( self::GRACE_MIN_MS, min( self::GRACE_MAX_MS, $ms ) );
	}
}

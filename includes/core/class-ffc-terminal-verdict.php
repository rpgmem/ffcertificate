<?php
/**
 * Terminal Verdict
 *
 * The answer a self-service terminal shows for one reading (#2890), in the
 * one shape every attendance target returns, so the terminal and its queue
 * never need to know which module answered.
 *
 * @package FreeFormCertificate\Core
 * @since   6.52.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds terminal verdicts.
 *
 * A verdict is `{status, code, message, name}`, plus whatever a target adds
 * for its own screen (`open`, `time`). `name` is the person's short name and
 * nothing else; a refusal carries no name, so a terminal never says who tried.
 *
 * @phpstan-type Verdict array{status: string, code: string, message: string, name: string, open?: bool, time?: string}
 */
final class TerminalVerdict {

	/**
	 * The reading was recorded.
	 */
	public const RECORDED = 'recorded';

	/**
	 * The person was already recorded; nothing changed.
	 */
	public const ALREADY = 'already';

	/**
	 * The reading was refused.
	 */
	public const REFUSED = 'refused';

	/**
	 * The reading was recorded, and the person still has something to do
	 * before it counts in full: a certificate waiting on data the profile
	 * does not hold (#2891).
	 */
	public const PENDING = 'pending';

	/**
	 * A refusal, with no name in it.
	 *
	 * @param string $code    Reason code.
	 * @param string $message What to do.
	 * @return Verdict
	 */
	public static function refused( string $code, string $message ): array {
		return array(
			'status'  => self::REFUSED,
			'code'    => $code,
			'message' => $message,
			'name'    => '',
		);
	}
}

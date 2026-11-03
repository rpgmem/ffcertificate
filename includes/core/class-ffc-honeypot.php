<?php
/**
 * Honeypot
 *
 * The provider-independent half of every public form's security block: a
 * trap field a person never fills, and a signed render time a submission must
 * be at least {@see self::MIN_SECONDS} older than (#2339).
 *
 * The trap used to be named `ffc_honeypot_trap`, which said what it was to
 * any script that read the markup, and browsers that autofill by field name
 * left it alone only by luck. It now carries a name no autofill heuristic
 * matches. The old name is still refused when filled, because a page cached
 * before the rename still renders it.
 *
 * The stamp is a speed bump, not a wall: a script that waits three seconds
 * passes it. What it removes is the cheapest attack — fetching the form and
 * posting it back at once — and it costs a person nothing. On a cached page
 * the stamp comes with the dynamic fragments, like the captcha, so an hour-old
 * page does not carry an hour-old render time that would never be too fast.
 *
 * Every refusal reads the same, so the message never says which trap fired.
 *
 * @package FreeFormCertificate\Core
 * @since 6.44.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trap field and render-time stamp.
 */
final class Honeypot {

	/**
	 * Name of the trap field: one no autofill heuristic fills.
	 *
	 * @var string
	 */
	public const TRAP_FIELD = 'ffc_hp_x7';

	/**
	 * The trap's name before #2339, still refused when filled.
	 *
	 * @var string
	 */
	public const LEGACY_TRAP_FIELD = 'ffc_honeypot_trap';

	/**
	 * Name of the signed render-time field.
	 *
	 * @var string
	 */
	public const STAMP_FIELD = 'ffc_hp_ts';

	/**
	 * Fewest seconds between rendering a form and submitting it.
	 *
	 * @var int
	 */
	public const MIN_SECONDS = 3;

	/**
	 * A fresh render-time stamp: `<unix time>.<signature>`.
	 *
	 * @return string
	 */
	public static function stamp(): string {
		$now = time();
		return $now . '.' . Captcha\ChallengeSigner::sign( self::payload( $now ) );
	}

	/**
	 * Check the honeypot half of a request.
	 *
	 * @param array<string, mixed> $data Request data (typically `$_POST`).
	 * @return true|string True when the request passes, else the refusal.
	 */
	public static function check( array $data ) {
		$trap = self::check_trap( $data );
		if ( true !== $trap ) {
			return $trap;
		}

		$raw = $data[ self::STAMP_FIELD ] ?? '';
		if ( ! is_string( $raw ) || 1 !== preg_match( '/^(\d{1,12})\.([0-9a-f]{64})$/', $raw, $parts ) ) {
			return self::refusal();
		}

		$rendered = (int) $parts[1];
		if ( ! Captcha\ChallengeSigner::matches( self::payload( $rendered ), $parts[2] ) ) {
			return self::refusal();
		}

		$age = time() - $rendered;
		if ( $age < self::MIN_SECONDS ) {
			return self::refusal();
		}

		return true;
	}

	/**
	 * Check the trap field alone.
	 *
	 * For a form behind a login, which no page cache serves and no
	 * anonymous script reaches: the reregistration form (#2339). The trap is
	 * defence in depth there; the render-time stamp would add a field to
	 * every draft save for nothing a login does not already give.
	 *
	 * @param array<string, mixed> $data Request data.
	 * @return true|string True when the trap is empty, else the refusal.
	 */
	public static function check_trap( array $data ) {
		if ( ! empty( $data[ self::TRAP_FIELD ] ) || ! empty( $data[ self::LEGACY_TRAP_FIELD ] ) ) {
			return self::refusal();
		}

		return true;
	}

	/**
	 * The one message every honeypot refusal shows.
	 *
	 * @return string
	 */
	public static function refusal(): string {
		return \__( 'Your submission could not be accepted. Please wait a moment and try again.', 'ffcertificate' );
	}

	/**
	 * What the stamp's signature covers.
	 *
	 * The leading `hp` keeps it from ever reading as a captcha payload,
	 * which the same signer covers.
	 *
	 * @param int $time Unix time the form was rendered.
	 * @return string
	 */
	private static function payload( int $time ): string {
		return 'hp|' . $time;
	}
}

<?php
/**
 * Hex-color validator + canonicalizer.
 *
 * Single source of truth for accepting `#RGB` / `#RRGGBB` / `#RRGGBBAA`
 * strings and returning a lowercase canonical form. Anything else falls
 * back to the supplied default. Used by every settings sub-key, every
 * per-row color picker, and every place the plugin accepts a color from
 * an operator.
 *
 * Originally implemented three times in the recruitment module
 * (`RecruitmentSettings::sanitize_color`, `RecruitmentAdjutancyRepository::normalize_color`,
 * `RecruitmentReasonRepository::normalize_color`); consolidated here in
 * 6.2.0 so the rules live in one place and additions (e.g. `hsl()`
 * support) only need one edit.
 *
 * @package FreeFormCertificate\Core
 * @since   6.2.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless hex-color normalizer.
 */
final class ColorValidator {

	/** Regex covering the three accepted shapes. */
	private const HEX_PATTERN = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/';

	/**
	 * Normalize raw input to the canonical lowercase hex form.
	 *
	 * Accepts the three shapes WordPress's color picker can emit
	 * (`#RGB`, `#RRGGBB`, `#RRGGBBAA`); anything else returns
	 * `$default`. Non-string values (rare — settings can hand us
	 * `null` or arrays under malformed POSTs) also fall back.
	 *
	 * @param mixed  $value   Raw value.
	 * @param string $default Fallback when input is unusable.
	 * @return string Canonical lowercase hex (e.g. `#abcdef`).
	 */
	public static function normalize( $value, string $default ): string {
		if ( ! is_string( $value ) ) {
			return $default;
		}
		$value = trim( $value );
		if ( '' === $value ) {
			return $default;
		}
		if ( 1 === preg_match( self::HEX_PATTERN, $value ) ) {
			return strtolower( $value );
		}
		return $default;
	}
}

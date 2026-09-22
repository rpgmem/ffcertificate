<?php
/**
 * DocumentFormatter
 *
 * Focused service class for document validation, formatting, and masking.
 * Handles Brazilian CPF, RF, auth codes, phone numbers, and email masking.
 *
 * Extracted from Utils.php (Sprint 30) for single-responsibility compliance.
 *
 * @package FreeFormCertificate\Core
 * @since 4.12.26
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

use FreeFormCertificate\Settings\SettingsReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Document Formatter.
 */
class DocumentFormatter {

	/**
	 * Virtual prefix for certificates (ffc_submissions).
	 *
	 * @since 5.0.0
	 */
	public const PREFIX_CERTIFICATE = 'C';

	/**
	 * Virtual prefix for reregistrations (ffc_reregistration_submissions).
	 *
	 * @since 5.0.0
	 */
	public const PREFIX_REREGISTRATION = 'R';

	/**
	 * Virtual prefix for appointments (ffc_self_scheduling_appointments).
	 *
	 * @since 5.0.0
	 */
	public const PREFIX_APPOINTMENT = 'A';

	/**
	 * Valid auth code prefixes.
	 *
	 * @since 5.0.0
	 */
	private const VALID_PREFIXES = array( 'C', 'R', 'A' );

	/**
	 * Phone validation regex pattern (without delimiters).
	 */
	public const PHONE_REGEX = '^\(?\d{2}\)?\s?\d{4,5}-?\d{4}$';

	/**
	 * Validate CPF (Brazilian tax ID)
	 *
	 * @param string $cpf CPF to validate (with or without formatting).
	 * @return bool True if valid
	 */
	public static function validate_cpf( string $cpf ): bool {
		$cpf = DataSanitizer::normalize_cpf_rf( $cpf );

		if ( strlen( $cpf ) !== 11 ) {
			return false;
		}

		if ( preg_match( '/(\d)\1{10}/', $cpf ) ) {
			return false;
		}

		for ( $t = 9; $t < 11; $t++ ) {
			for ( $d = 0, $c = 0; $c < $t; $c++ ) {
				$d += (int) $cpf[ $c ] * ( ( $t + 1 ) - $c );
			}
			$d = ( ( 10 * $d ) % 11 ) % 10;
			if ( (int) $cpf[ $c ] !== $d ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Validate RF (7-digit registration)
	 *
	 * **Structure only, by default.** Seven digits and nothing else -- which
	 * is what this method has always checked, and why a mistyped RF reaches
	 * storage where a mistyped CPF does not: `validate_cpf()` verifies two
	 * check digits, so it rejects a typo roughly 99 times in 100, while this
	 * accepts almost every one. Measured on the identity audit, that
	 * asymmetry is total -- 32 of 48 RF conflicts are a single digit or an
	 * adjacent transposition apart, against 1 of 25 on CPF (#1345).
	 *
	 * **The RF does have a check digit** ({@see self::rf_check_digit()}), and
	 * enforcing it here would close that gap at the form. It is off unless
	 * something turns it on:
	 *
	 * ```php
	 * add_filter( 'ffc_validate_rf_check_digit', '__return_true' );
	 * ```
	 *
	 * **Off is the deliberate default, and the reason is asymmetric cost.**
	 * The rule is inferred from this install's own data rather than read from
	 * an HR specification, so rejecting on it risks turning away a person
	 * whose RF is genuinely unusual -- and a blocked registration is worse
	 * than a stored typo, which the audit finds later either way. The
	 * classification half needs no such licence and is always available
	 * through {@see self::rf_check_digit_matches()}; this filter is only
	 * about refusing input. The same shape as `ffc_ip_shadow_logging`: a
	 * stronger signal is made available first and made binding separately.
	 *
	 * @since 6.28.2 The `ffc_validate_rf_check_digit` opt-in.
	 * @since 6.28.2 The `validate_rf_check_digit` setting, which is what the
	 *               filter now defaults to.
	 * @param string $rf RF to validate.
	 * @return bool True if valid
	 */
	public static function validate_rf( string $rf ): bool {
		$rf = DataSanitizer::normalize_cpf_rf( $rf );

		if ( strlen( $rf ) !== 7 || ! is_numeric( $rf ) ) {
			return false;
		}

		// THE SETTING IS THE FILTER'S DEFAULT, NOT A SECOND SWITCH
		//
		// The same shape as `ffc_ip_resolver_mode`, whose documentation says
		// it is "normally set from the IP Diagnostics tab": an administrator
		// decides from the screen, and code can still override. Two
		// independent switches would mean an operator turning it on in the
		// admin while a filter silently keeps it off, with nothing on the
		// page saying so.
		$enforce = SettingsReader::get_bool( self::SETTING_CHECK_DIGIT, false );

		/**
		 * Whether `validate_rf()` also requires the check digit to agree.
		 *
		 * @since 6.28.2
		 * @param bool $enforce The `validate_rf_check_digit` setting; false unless an administrator turned it on.
		 */
		if ( ! apply_filters( 'ffc_validate_rf_check_digit', $enforce ) ) {
			return true;
		}

		return self::rf_check_digit_matches( $rf );
	}

	/**
	 * Positional weights of the RF check digit, most significant first.
	 *
	 * @since 6.28.2
	 * @var array<int, int>
	 */
	public const RF_CHECK_WEIGHTS = array( 7, 6, 5, 4, 3, 2 );

	/**
	 * The `ffc_settings` key an administrator flips to enforce the check digit.
	 *
	 * Named identically to the filter it defaults, so the pair is obvious from
	 * either end -- the two are one switch seen from the screen and from code,
	 * never two.
	 *
	 * @since 6.28.2
	 * @var string
	 */
	public const SETTING_CHECK_DIGIT = 'validate_rf_check_digit';

	/**
	 * The check digit the first six digits of an RF imply.
	 *
	 * ```
	 * s  = 7*d0 + 6*d1 + 5*d2 + 4*d3 + 3*d4 + 2*d5
	 * r  = s mod 11
	 * dv = (11 - r) mod 10
	 * ```
	 *
	 * **Why the second modulus is 10 and not 11.** `11 - r` ranges over 1..11,
	 * and neither 10 nor 11 is a digit. Taking it mod 10 sends 11 to 1 and 10
	 * to 0, which is what the data does: `r = 1` yields 0, and `r = 0` and
	 * `r = 10` BOTH yield 1. That collapse is the rule's one blind spot -- a
	 * single-digit error in the body that moves `r` between 0 and 10 produces
	 * the same check digit and passes undetected. It is small and real, and
	 * saying so here is cheaper than rediscovering it from a false negative.
	 *
	 * **Where the rule comes from, because it is not a published spec.** It
	 * was derived from 3,036 distinct RFs decrypted in memory from this
	 * install's own stores (2026-09-20), which 97.40% of them satisfy, and
	 * then reproduced against 96 RFs of independent provenance, which
	 * satisfy it at **100%** and exercise all eleven residues -- including
	 * the two that collapse onto `dv = 1`, which is what a derivation from
	 * one base cannot check on itself. The shortfall on the first base is
	 * therefore stored values that are wrong, not a gap in the rule.
	 * Reasoning and both measurements: issue #1345.
	 *
	 * @since 6.28.2
	 * @param string $rf RF, with or without formatting.
	 * @return int|null Expected check digit 0-9, or null when the input is not
	 *                  a seven-digit RF and there is nothing to compute from.
	 */
	public static function rf_check_digit( string $rf ): ?int {
		$rf = DataSanitizer::normalize_cpf_rf( $rf );

		if ( strlen( $rf ) !== 7 ) {
			return null;
		}

		$sum = 0;
		foreach ( self::RF_CHECK_WEIGHTS as $position => $weight ) {
			$sum += (int) $rf[ $position ] * $weight;
		}

		return ( 11 - ( $sum % 11 ) ) % 10;
	}

	/**
	 * Whether an RF's seventh digit is the one its first six imply.
	 *
	 * Structure is checked first, so this answers false for anything that is
	 * not a seven-digit RF at all -- a caller asking "is this RF consistent"
	 * never has to ask "is it an RF" separately.
	 *
	 * This is the classification half of the check digit and it is always on.
	 * Rejecting a registration is the other half, and it is not: see
	 * {@see self::validate_rf()}.
	 *
	 * @since 6.28.2
	 * @param string $rf RF, with or without formatting.
	 * @return bool True when the check digit agrees with the body.
	 */
	public static function rf_check_digit_matches( string $rf ): bool {
		$rf       = DataSanitizer::normalize_cpf_rf( $rf );
		$expected = self::rf_check_digit( $rf );

		return null !== $expected && (int) $rf[6] === $expected;
	}

	/**
	 * Validate Brazilian phone number.
	 *
	 * @since 4.11.0
	 * @param string $phone Phone string.
	 * @return bool True if valid
	 */
	public static function validate_phone( string $phone ): bool {
		$phone = preg_replace( '/\s+/', '', $phone ) ?? '';
		return (bool) preg_match( '/' . self::PHONE_REGEX . '/', $phone );
	}

	/**
	 * Format CPF with mask
	 *
	 * @param string $cpf CPF to format.
	 * @return string Formatted CPF (XXX.XXX.XXX-XX)
	 */
	public static function format_cpf( string $cpf ): string {
		$cpf = DataSanitizer::normalize_cpf_rf( $cpf );

		if ( strlen( $cpf ) === 11 ) {
			return preg_replace( '/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $cpf ) ?? $cpf;
		}

		return $cpf;
	}

	/**
	 * Format RF with mask
	 *
	 * @param string $rf RF to format.
	 * @return string Formatted RF (XXX.XXX-X)
	 */
	public static function format_rf( string $rf ): string {
		$rf = DataSanitizer::normalize_cpf_rf( $rf );

		if ( strlen( $rf ) === 7 ) {
			return preg_replace( '/(\d{3})(\d{3})(\d{1})/', '$1.$2-$3', $rf ) ?? $rf;
		}

		return $rf;
	}

	/**
	 * Format authentication code with optional virtual prefix.
	 *
	 * @since 5.0.0 Added $prefix parameter.
	 * @param string $code Auth code to format (raw 12-char or already formatted).
	 * @param string $prefix Virtual prefix letter (C, R, A) — not stored in DB.
	 * @return string Formatted code: P-XXXX-XXXX-XXXX (with prefix) or XXXX-XXXX-XXXX (without).
	 */
	public static function format_auth_code( string $code, string $prefix = '' ): string {
		$code = strtoupper( preg_replace( '/[^A-Z0-9]/i', '', $code ) ?? '' );

		if ( strlen( $code ) === 12 ) {
			$formatted = substr( $code, 0, 4 ) . '-' . substr( $code, 4, 4 ) . '-' . substr( $code, 8, 4 );
		} else {
			$formatted = $code;
		}

		if ( '' !== $prefix && in_array( strtoupper( $prefix ), self::VALID_PREFIXES, true ) ) {
			return strtoupper( $prefix ) . '-' . $formatted;
		}

		return $formatted;
	}

	/**
	 * Format any document based on type
	 *
	 * @param string $value Document value.
	 * @param string $type Document type (cpf, rf, auth_code, or 'auto').
	 * @return string Formatted document
	 */
	public static function format_document( string $value, string $type = 'auto' ): string {
		$clean = DataSanitizer::normalize_cpf_rf( $value );
		$len   = strlen( $clean );

		if ( 'auto' === $type ) {
			if ( 11 === $len ) {
				$type = 'cpf';
			} elseif ( 7 === $len ) {
				$type = 'rf';
			} elseif ( 12 === $len ) {
				$type = 'auth_code';
			}
		}

		switch ( $type ) {
			case 'cpf':
				return self::format_cpf( $value );
			case 'rf':
				return self::format_rf( $value );
			case 'auth_code':
				return self::format_auth_code( $value );
			default:
				return $value;
		}
	}

	/**
	 * Mask CPF/RF for privacy
	 *
	 * @since 2.10.0
	 * @param string $value CPF or RF to mask.
	 * @return string Masked document
	 */
	public static function mask_cpf( string $value ): string {
		if ( empty( $value ) ) {
			return '';
		}

		$clean = DataSanitizer::normalize_cpf_rf( $value );

		if ( strlen( $clean ) === 11 ) {
			return substr( $clean, 0, 3 ) . '.***.***-' . substr( $clean, -2 );
		} elseif ( strlen( $clean ) === 7 ) {
			return substr( $clean, 0, 3 ) . '.***-' . substr( $clean, -1 );
		}

		return $value;
	}

	/**
	 * Mask RF (Registro Funcional) for privacy.
	 *
	 * RF is a Brazilian employee/civil-servant registration number whose
	 * length varies by state and agency (typically 4–15 digits). Unlike CPF,
	 * there is no canonical national format, so this mask is length-agnostic:
	 *
	 *   - input < 4 digits → returned unchanged (too short to mask meaningfully)
	 *   - input ≥ 4 digits → first 3 digits, then `***`, then last 1 digit
	 *
	 * Non-digit characters are stripped before masking. The result format is
	 * dot-separated to match the visual style of {@see self::mask_cpf()}:
	 * `XXX.***.X` for typical 7-digit RFs, `XXX.***.X` for longer RFs too.
	 *
	 * Used by the recruitment public shortcode (sprint 11) when the
	 * `rf_masked` column is opted-in via `notice.public_columns_config`, and
	 * by the candidate-self dashboard (sprint 12).
	 *
	 * @since 6.0.0
	 * @param string $value RF to mask (with or without punctuation).
	 * @return string Masked RF, or the original input when too short.
	 */
	public static function mask_rf( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		$clean = DataSanitizer::normalize_cpf_rf( $value );

		if ( strlen( $clean ) < 4 ) {
			return $value;
		}

		return substr( $clean, 0, 3 ) . '.***.' . substr( $clean, -1 );
	}

	/**
	 * Mask email address for privacy
	 *
	 * @since 3.2.0
	 * @param string $email Email address to mask.
	 * @return string Masked email
	 */
	public static function mask_email( string $email ): string {
		if ( empty( $email ) || ! is_email( $email ) ) {
			return $email;
		}

		$parts = explode( '@', $email );
		if ( count( $parts ) !== 2 ) {
			return $email;
		}

		return substr( $parts[0], 0, 1 ) . '***@' . $parts[1];
	}

	/**
	 * Mask one form-field value by its key, for surfaces that must not leak the
	 * PII collected inside a submission's `data` blob (the public `/valid`
	 * verification page and the `/verify` REST endpoint). Returns the raw masked
	 * value — callers that render to HTML escape it themselves.
	 *
	 * PII keys `cpf` / `cpf_rf` / `rg` → {@see mask_cpf()}, `rf` → {@see mask_rf()},
	 * `email` → {@see mask_email()}. Every other key (course, hours, grade, …) is
	 * returned unchanged: masking a legitimate non-PII field would corrupt the
	 * certificate's own content. Empty or non-scalar values pass through as-is.
	 *
	 * @since 6.18.0
	 * @param string $field_key The field key.
	 * @param mixed  $value     The field value.
	 * @return mixed The masked value for PII keys, otherwise the original value.
	 */
	public static function mask_field_value( string $field_key, $value ) {
		if ( empty( $value ) || ! is_scalar( $value ) ) {
			return $value;
		}

		if ( in_array( $field_key, array( 'cpf', 'cpf_rf', 'rg' ), true ) ) {
			return self::mask_cpf( (string) $value );
		}

		if ( 'rf' === $field_key ) {
			return self::mask_rf( (string) $value );
		}

		if ( 'email' === $field_key ) {
			return self::mask_email( (string) $value );
		}

		return $value;
	}

	/**
	 * Parse a potentially prefixed auth code into prefix + raw code.
	 *
	 * Accepts: "C-XXXX-XXXX-XXXX", "CXXXXXXXXXXXX", "XXXX-XXXX-XXXX", "XXXXXXXXXXXX".
	 * Returns: ['prefix' => 'C'|'R'|'A'|'', 'code' => 'XXXXXXXXXXXX']
	 *
	 * @since 5.0.0
	 * @param string $input Raw user input (with or without prefix/dashes).
	 * @return array{prefix: string, code: string}
	 */
	public static function parse_prefixed_code( string $input ): array {
		// Strip whitespace, uppercase.
		$clean = strtoupper( trim( $input ) );

		// Remove all non-alphanumeric chars.
		$alphanumeric = preg_replace( '/[^A-Z0-9]/', '', $clean ) ?? '';

		// 13 chars: first char is a valid prefix letter.
		if ( strlen( $alphanumeric ) === 13 && in_array( $alphanumeric[0], self::VALID_PREFIXES, true ) ) {
			return array(
				'prefix' => $alphanumeric[0],
				'code'   => substr( $alphanumeric, 1 ),
			);
		}

		// 12 chars: no prefix.
		if ( strlen( $alphanumeric ) === 12 ) {
			return array(
				'prefix' => '',
				'code'   => $alphanumeric,
			);
		}

		// Fallback: return as-is (invalid length).
		return array(
			'prefix' => '',
			'code'   => $alphanumeric,
		);
	}

	/**
	 * Clean authentication code (remove special chars, prefix, uppercase).
	 *
	 * Strips the virtual prefix if present, returning only the 12-char code.
	 *
	 * @param string $code Auth code to clean (may include prefix).
	 * @return string Cleaned 12-char code (uppercase alphanumeric only, no prefix).
	 */
	public static function clean_auth_code( string $code ): string {
		$parsed = self::parse_prefixed_code( $code );
		return $parsed['code'];
	}

	/**
	 * Clean identifier (CPF, RF, ticket) - uppercase alphanumeric only
	 *
	 * @param string $value Identifier to clean.
	 * @return string Cleaned identifier
	 */
	public static function clean_identifier( string $value ): string {
		return strtoupper( preg_replace( '/[^a-zA-Z0-9]/', '', $value ) ?? '' );
	}
}

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

		if ( ! self::rf_check_digit_enforced() ) {
			return true;
		}

		return self::rf_check_digit_matches( $rf );
	}

	/**
	 * Whether an RF must also carry a matching check digit: what
	 * {@see validate_rf()} enforces, and what the browser is told so its
	 * check agrees with the server's (#1920).
	 *
	 * THE SETTING IS THE FILTER'S DEFAULT, NOT A SECOND SWITCH. The same shape
	 * as `ffc_ip_resolver_mode`, whose documentation says it is "normally set
	 * from the IP Diagnostics tab": an administrator decides from the screen,
	 * and code can still override. Two independent switches would mean an
	 * operator turning it on in the admin while a filter silently keeps it
	 * off, with nothing on the page saying so.
	 *
	 * @since 6.41.0
	 * @return bool
	 */
	public static function rf_check_digit_enforced(): bool {
		$enforce = SettingsReader::get_bool( self::SETTING_CHECK_DIGIT, false );

		/**
		 * Whether `validate_rf()` also requires the check digit to agree.
		 *
		 * @since 6.28.2
		 * @param bool $enforce The `validate_rf_check_digit` setting; false unless an administrator turned it on.
		 */
		return (bool) apply_filters( 'ffc_validate_rf_check_digit', $enforce );
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

	// ─────────────────────────────────────────────.
	// REDACTION (#1920).
	//
	// One rule per kind of personal data. A caller names the kind, or a
	// field key that {@see kind_for_key()} maps to one, and gets back the
	// one masked shape for that kind. No mask ever returns its input: a
	// value that does not fit its kind's shape falls back to the generic
	// mask, so a malformed stored value never reaches a page in clear.
	// ─────────────────────────────────────────────.

	/**
	 * A CPF, or a CPF/RF field told apart by its length (11 or 7 digits).
	 *
	 * @since 6.41.0
	 */
	public const MASK_CPF = 'cpf';

	/**
	 * A functional registration (RF).
	 *
	 * @since 6.41.0
	 */
	public const MASK_RF = 'rf';

	/**
	 * A national identity card number (CIN, formerly RG).
	 *
	 * @since 6.41.0
	 */
	public const MASK_CIN = 'cin';

	/**
	 * An email address.
	 *
	 * @since 6.41.0
	 */
	public const MASK_EMAIL = 'email';

	/**
	 * A phone number.
	 *
	 * @since 6.41.0
	 */
	public const MASK_PHONE = 'phone';

	/**
	 * A person's name. Never picked by a field key: whether a name is masked
	 * is the screen's decision (#1863), so a caller asks for it by kind.
	 *
	 * @since 6.41.0
	 */
	public const MASK_NAME = 'name';

	/**
	 * A date of birth.
	 *
	 * @since 6.41.0
	 */
	public const MASK_BIRTH_DATE = 'birth_date';

	/**
	 * An IP address.
	 *
	 * @since 6.41.0
	 */
	public const MASK_IP = 'ip';

	/**
	 * Any other personal value.
	 *
	 * @since 6.41.0
	 */
	public const MASK_GENERIC = 'generic';

	/**
	 * Field key => kind: the one list of keys that carry personal data in
	 * every module, read by the masks, the exports and the emails alike.
	 * The keys are stored values (form field names, profile keys,
	 * reregistration `field_key`s), which is why some are Portuguese.
	 */
	private const KEY_KINDS = array(
		'cpf'                 => self::MASK_CPF,
		'cpf_rf'              => self::MASK_CPF,
		'rf'                  => self::MASK_RF,
		'rg'                  => self::MASK_CIN,
		'cin'                 => self::MASK_CIN,
		'email'               => self::MASK_EMAIL,
		'user_email'          => self::MASK_EMAIL,
		'account_email'       => self::MASK_EMAIL,
		'email_institucional' => self::MASK_EMAIL,
		'phone'               => self::MASK_PHONE,
		'telefone'            => self::MASK_PHONE,
		'celular'             => self::MASK_PHONE,
		'birth_date'          => self::MASK_BIRTH_DATE,
		'data_nascimento'     => self::MASK_BIRTH_DATE,
		'ip'                  => self::MASK_IP,
		'user_ip'             => self::MASK_IP,
	);

	/**
	 * Input mask (a custom field's `field_mask` or validation format) =>
	 * kind, so a custom field holding a CPF or a phone under a key of its own
	 * still gets that kind's mask.
	 */
	private const INPUT_MASK_KINDS = array(
		'cpf'   => self::MASK_CPF,
		'rf'    => self::MASK_RF,
		'cin'   => self::MASK_CIN,
		'phone' => self::MASK_PHONE,
		'email' => self::MASK_EMAIL,
	);

	/**
	 * The kind of personal data a field key carries, or null when the key
	 * is not personal.
	 *
	 * @since 6.41.0
	 * @param string $field_key  Field or column key.
	 * @param string $input_mask The field's input mask or format, when it has one.
	 * @return string|null One of the MASK_* kinds.
	 */
	public static function kind_for_key( string $field_key, string $input_mask = '' ): ?string {
		if ( '' !== $input_mask && isset( self::INPUT_MASK_KINDS[ $input_mask ] ) ) {
			return self::INPUT_MASK_KINDS[ $input_mask ];
		}
		return self::KEY_KINDS[ $field_key ] ?? null;
	}

	/**
	 * Mask a value as its kind.
	 *
	 * @since 6.41.0
	 * @param string $kind  One of the MASK_* kinds; an unknown one masks as generic.
	 * @param string $value Clear value.
	 * @return string Masked value, or '' for an empty input.
	 */
	public static function mask( string $kind, string $value ): string {
		switch ( $kind ) {
			case self::MASK_CPF:
				return self::mask_cpf( $value );
			case self::MASK_RF:
				return self::mask_rf( $value );
			case self::MASK_CIN:
				return self::mask_cin( $value );
			case self::MASK_EMAIL:
				return self::mask_email( $value );
			case self::MASK_PHONE:
				return self::mask_phone( $value );
			case self::MASK_NAME:
				return self::mask_name( $value );
			case self::MASK_BIRTH_DATE:
				return self::mask_birth_date( $value );
			case self::MASK_IP:
				return self::mask_ip( $value );
			default:
				return self::mask_generic( $value );
		}
	}

	/**
	 * The shape of a kind's mask with no data in it, for a value that is
	 * shown masked before it is decrypted (the reveal fetches the real one).
	 *
	 * @since 6.41.0
	 * @param string $kind One of the MASK_* kinds.
	 * @return string
	 */
	public static function mask_placeholder( string $kind ): string {
		switch ( $kind ) {
			case self::MASK_CPF:
				return '***.***.***-**';
			case self::MASK_RF:
				return '***.***-*';
			case self::MASK_CIN:
				return '**.***.***-*';
			case self::MASK_EMAIL:
				return '***@***';
			case self::MASK_PHONE:
				return '(**) *****-****';
			case self::MASK_BIRTH_DATE:
				return '**/**/****';
			default:
				return '***';
		}
	}

	/**
	 * The generic mask: the first character, then `***`.
	 *
	 * @since 6.41.0
	 * @param string $value Clear value.
	 * @return string Masked value, or '' for an empty input.
	 */
	public static function mask_generic( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$first = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 1 ) : substr( $value, 0, 1 );
		return $first . '***';
	}

	/**
	 * Mask a CPF (`123.***.***-45`), or an RF entered in a CPF/RF field
	 * (7 digits, `123.***-4`).
	 *
	 * @since 2.10.0
	 * @since 6.41.0 A value of any other length masks as generic instead of being returned.
	 * @param string $value CPF or RF to mask.
	 * @return string Masked document, or '' for an empty input.
	 */
	public static function mask_cpf( string $value ): string {
		if ( '' === trim( $value ) ) {
			return '';
		}

		$clean = DataSanitizer::normalize_cpf_rf( $value );

		if ( strlen( $clean ) === 11 ) {
			return substr( $clean, 0, 3 ) . '.***.***-' . substr( $clean, -2 );
		}
		if ( strlen( $clean ) === 7 ) {
			return self::mask_rf( $clean );
		}

		return self::mask_generic( $value );
	}

	/**
	 * Mask an RF (Registro Funcional): the first three digits and the last,
	 * in the shape it is typed in, `123.***-4`.
	 *
	 * RF length varies by agency, so the shape is the same for any length;
	 * a value under six digits would show most of itself through that shape
	 * and masks as generic instead.
	 *
	 * @since 6.0.0
	 * @since 6.41.0 `123.***-4` (was `123.***.4`); short values mask as generic.
	 * @param string $value RF to mask (with or without punctuation).
	 * @return string Masked RF, or '' for an empty input.
	 */
	public static function mask_rf( string $value ): string {
		if ( '' === trim( $value ) ) {
			return '';
		}

		$clean = DataSanitizer::normalize_cpf_rf( $value );

		if ( strlen( $clean ) < 6 ) {
			return self::mask_generic( $value );
		}

		return substr( $clean, 0, 3 ) . '.***-' . substr( $clean, -1 );
	}

	/**
	 * Mask a CIN or RG: its first two digits, `12.***.***-*`.
	 *
	 * @since 6.41.0
	 * @param string $value CIN/RG to mask.
	 * @return string Masked value, or '' for an empty input.
	 */
	public static function mask_cin( string $value ): string {
		if ( '' === trim( $value ) ) {
			return '';
		}

		$clean = preg_replace( '/[^0-9Xx]/', '', $value ) ?? '';
		if ( strlen( $clean ) < 6 ) {
			return self::mask_generic( $value );
		}

		return substr( $clean, 0, 2 ) . '.***.***-*';
	}

	/**
	 * Mask an email address: the first letter and the domain, `m***@domain`.
	 *
	 * @since 3.2.0
	 * @since 6.41.0 A value that is not an email masks as generic instead of being returned.
	 * @param string $email Email address to mask.
	 * @return string Masked email, or '' for an empty input.
	 */
	public static function mask_email( string $email ): string {
		$email = trim( $email );
		if ( '' === $email ) {
			return '';
		}

		$parts = explode( '@', $email );
		if ( ! is_email( $email ) || count( $parts ) !== 2 ) {
			return self::mask_generic( $email );
		}

		return substr( $parts[0], 0, 1 ) . '***@' . $parts[1];
	}

	/**
	 * Mask a date of birth: the day and month in the site's date format,
	 * the year hidden (`15/03/****` under `d/m/Y`).
	 *
	 * @since 6.41.0
	 * @param string $value Date as stored (`Y-m-d`) or as typed (`d/m/Y`).
	 * @return string Masked date, or '' for an empty input.
	 */
	public static function mask_birth_date( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		// A date that does not round-trip (30 February) is not a date.
		$utc  = new \DateTimeZone( 'UTC' );
		$date = null;
		foreach ( array( 'Y-m-d', 'd/m/Y' ) as $shape ) {
			$parsed = \DateTimeImmutable::createFromFormat( '!' . $shape, $value, $utc );
			if ( false !== $parsed && $parsed->format( $shape ) === $value ) {
				$date = $parsed;
				break;
			}
		}
		if ( null === $date ) {
			return self::mask_placeholder( self::MASK_BIRTH_DATE );
		}

		$format = class_exists( DateFormatter::class ) ? DateFormatter::resolve_date_format() : 'd/m/Y';
		// Every year token becomes escaped asterisks, so the format prints
		// them literally; a format with no year shows day and month only.
		$format = strtr(
			$format,
			array(
				'Y' => '\*\*\*\*',
				'o' => '\*\*\*\*',
				'y' => '\*\*',
			)
		);

		return function_exists( 'wp_date' )
			? (string) wp_date( $format, $date->getTimestamp(), $utc )
			: $date->format( $format );
	}

	/**
	 * Mask an IP address for privacy (#1760).
	 *
	 * IPv4 keeps its first two octets (`192.168.***.***`); IPv6 keeps its
	 * first four groups, the network half, and masks the interface half
	 * (`2001:db8:85a3:0:****:****:****:****`). A compressed IPv6 address is
	 * expanded first, so the four groups kept are always the leading ones.
	 * A value that is not an IP is masked whole rather than returned, so a
	 * malformed stored value never reaches the page in clear.
	 *
	 * @since 6.40.0
	 * @param string $ip IP address.
	 * @return string Masked IP, or '' for an empty input.
	 */
	public static function mask_ip( string $ip ): string {
		$ip = trim( $ip );
		if ( '' === $ip ) {
			return '';
		}

		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$octets = explode( '.', $ip );
			return $octets[0] . '.' . $octets[1] . '.***.***';
		}

		if ( false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = inet_pton( $ip );
			if ( false !== $packed && 16 === strlen( $packed ) ) {
				$groups = array_map(
					static fn( string $pair ): string => dechex( (int) hexdec( $pair ) ),
					str_split( bin2hex( $packed ), 4 )
				);
				return implode( ':', array_slice( $groups, 0, 4 ) ) . ':****:****:****:****';
			}
		}

		return '***';
	}

	/**
	 * Mask a phone number for privacy (#1863).
	 *
	 * Keeps the last two digits and replaces every other digit with `*`,
	 * leaving the punctuation where it was, so `(11) 98765-4321` reads
	 * `(**) *****-**21`. A value with fewer than four digits is masked whole.
	 *
	 * @since 6.40.0
	 * @param string $phone Phone number, in any format.
	 * @return string Masked phone, or '' for an empty input.
	 */
	public static function mask_phone( string $phone ): string {
		$phone = trim( $phone );
		if ( '' === $phone ) {
			return '';
		}

		$digits = (int) preg_match_all( '/[0-9]/', $phone );
		if ( $digits < 4 ) {
			return '***';
		}

		$keep   = 2;
		$seen   = 0;
		$masked = '';
		foreach ( str_split( $phone ) as $char ) {
			if ( ctype_digit( $char ) ) {
				++$seen;
				$masked .= $seen > $digits - $keep ? $char : '*';
			} else {
				$masked .= $char;
			}
		}
		return $masked;
	}

	/**
	 * Mask a person's name for privacy (#1863): each word keeps its first
	 * letter, so `Maria da Silva` reads `M*** d* S***`. Used where a name
	 * travels with other personal data (a birthday), never for the names
	 * every list prints to tell its rows apart.
	 *
	 * @since 6.40.0
	 * @param string $name Name.
	 * @return string Masked name, or '' for an empty input.
	 */
	public static function mask_name( string $name ): string {
		$words = preg_split( '/\s+/u', trim( $name ), -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $words ) || array() === $words ) {
			return '';
		}

		$out = array();
		foreach ( $words as $word ) {
			$first = function_exists( 'mb_substr' ) ? mb_substr( $word, 0, 1 ) : substr( $word, 0, 1 );
			$len   = function_exists( 'mb_strlen' ) ? mb_strlen( $word ) : strlen( $word );
			$out[] = $first . str_repeat( '*', max( 1, min( 3, $len - 1 ) ) );
		}
		return implode( ' ', $out );
	}

	/**
	 * Mask any personal value by its field key (#1863): the mask of the
	 * key's kind ({@see kind_for_key()}), and the generic mask for a key that
	 * names none, so no value passed here ever comes back in clear.
	 *
	 * @since 6.40.0
	 * @since 6.41.0 Reads the one key map; takes the field's input mask.
	 * @param string $field_key  Field key.
	 * @param string $value      Clear value.
	 * @param string $input_mask The field's input mask or format, when it has one.
	 * @return string Masked value, or '' for an empty input.
	 */
	public static function mask_any( string $field_key, string $value, string $input_mask = '' ): string {
		return self::mask( self::kind_for_key( $field_key, $input_mask ) ?? self::MASK_GENERIC, $value );
	}

	/**
	 * Mask one form-field value by its key, for surfaces that must not leak the
	 * personal data collected inside a submission's `data` blob (the public
	 * `/valid` verification page and the `/verify` REST endpoint). A key
	 * {@see kind_for_key()} knows is masked as its kind; every other key
	 * (course, hours, grade, …) is returned unchanged, because masking a
	 * legitimate non-personal field would corrupt the certificate's own
	 * content. Empty or non-scalar values pass through as-is. Callers that
	 * render to HTML escape the result themselves.
	 *
	 * @since 6.18.0
	 * @since 6.41.0 Reads the one key map, so phone, birth date and IP keys are masked too.
	 * @param string $field_key The field key.
	 * @param mixed  $value     The field value.
	 * @return mixed The masked value for personal keys, otherwise the original value.
	 */
	public static function mask_field_value( string $field_key, $value ) {
		if ( empty( $value ) || ! is_scalar( $value ) ) {
			return $value;
		}

		$kind = self::kind_for_key( $field_key );
		return null === $kind ? $value : self::mask( $kind, (string) $value );
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
	 *
	 * @deprecated 6.43.0 Nothing in the plugin calls it (#2089).
	 * @removal    6.45.0
	 */
	public static function clean_identifier( string $value ): string {
		_deprecated_function( __METHOD__, '6.43.0' );

		return strtoupper( preg_replace( '/[^a-zA-Z0-9]/', '', $value ) ?? '' );
	}
}

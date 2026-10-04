<?php
/**
 * Person-name value rules.
 *
 * @package FreeFormCertificate\Core
 * @since   6.33.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one place that splits a full name into WordPress's first and last name,
 * and joins them back.
 *
 * The plugin stores ONE name, the full one a person typed (the profile's
 * `display_name`); WordPress's `first_name` / `last_name` are derived from it.
 * The rule is the one an operator would apply by hand to a Brazilian name: the
 * first name is the first word, the last name is everything after it --
 * "Maria da Silva" is Maria / "da Silva". A one-word name has no last name.
 *
 * Whitespace is normalised on the way in, so "Maria  da Silva " and
 * "Maria da Silva" are the same name, and `join( split( $x ) )` is `$x`
 * normalised.
 */
final class PersonName {

	/**
	 * Collapse runs of whitespace (including non-breaking spaces) and trim.
	 *
	 * @param string $name Any name.
	 * @return string
	 */
	public static function normalize( string $name ): string {
		$collapsed = preg_replace( '/[\s\x{00A0}]+/u', ' ', $name );
		return trim( is_string( $collapsed ) ? $collapsed : $name );
	}

	/**
	 * First word and the rest.
	 *
	 * @param string $full Full name.
	 * @return array{first: string, last: string}
	 */
	public static function split( string $full ): array {
		$parts = explode( ' ', self::normalize( $full ), 2 );

		return array(
			'first' => $parts[0],
			'last'  => $parts[1] ?? '',
		);
	}

	/**
	 * The full name the two parts make.
	 *
	 * @param string $first First name.
	 * @param string $last  Last name.
	 * @return string
	 */
	public static function join( string $first, string $last ): string {
		return self::normalize( $first . ' ' . $last );
	}
}

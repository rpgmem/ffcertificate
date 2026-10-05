<?php
/**
 * QR code payload builders.
 *
 * @package FreeFormCertificate\Generators
 * @since   6.34.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Generators;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns the manual generator's fields into the text a QR code carries (#1563).
 *
 * Every format here is the de-facto one phone cameras act on: `WIFI:` for a
 * network, `mailto:`, `tel:`, `SMSTO:` and `wa.me` for WhatsApp. Nothing is
 * stored: the builder is pure, so the generator stays stateless.
 */
final class QrPayload {

	/** Types the manual generator offers. */
	public const TYPES = array( 'url', 'text', 'wifi', 'email', 'phone', 'sms', 'whatsapp' );

	/** Wi-Fi security types (`nopass` is an open network). */
	public const WIFI_SECURITY = array( 'WPA', 'WEP', 'nopass' );

	/**
	 * Byte-mode capacity of a version-40 code, per error-correction level.
	 * Anything longer cannot be encoded at that level at all.
	 */
	public const CAPACITY = array(
		'L' => 2953,
		'M' => 2331,
		'Q' => 1663,
		'H' => 1273,
	);

	/**
	 * From this version on the modules get small enough that a code printed
	 * at a usual size stops scanning reliably: a warning, not a refusal.
	 */
	public const DENSE_VERSION = 10;

	/**
	 * Build the payload for a type.
	 *
	 * @param string               $type   One of self::TYPES.
	 * @param array<string, mixed> $fields Raw fields for that type.
	 * @return string|WP_Error
	 */
	public static function build( string $type, array $fields ) {
		$get = static function ( string $key ) use ( $fields ): string {
			$value = $fields[ $key ] ?? '';
			return is_scalar( $value ) ? trim( (string) $value ) : '';
		};

		switch ( $type ) {
			case 'url':
				return self::url( $get( 'url' ) );
			case 'text':
				return '' === $get( 'text' ) ? self::missing( __( 'Enter the text to encode.', 'ffcertificate' ) ) : $get( 'text' );
			case 'wifi':
				return self::wifi( $get( 'ssid' ), $get( 'password' ), $get( 'security' ), '' !== $get( 'hidden' ) );
			case 'email':
				return self::email( $get( 'email' ), $get( 'subject' ), $get( 'body' ) );
			case 'phone':
				$number = self::phone_number( $get( 'phone' ) );
				return '' === $number ? self::missing( __( 'Enter a valid phone number.', 'ffcertificate' ) ) : 'tel:' . $number;
			case 'sms':
				$number = self::phone_number( $get( 'phone' ) );
				return '' === $number ? self::missing( __( 'Enter a valid phone number.', 'ffcertificate' ) ) : 'SMSTO:' . $number . ':' . $get( 'message' );
			case 'whatsapp':
				return self::whatsapp( $get( 'phone' ), $get( 'message' ) );
			default:
				return new WP_Error( 'ffc_qr_type', __( 'Unknown QR code type.', 'ffcertificate' ) );
		}
	}

	/**
	 * How full the code is and whether it is getting too dense.
	 *
	 * @param string $payload Encoded text.
	 * @param string $ecc     Error correction level actually used.
	 * @param int    $side    Modules per side of the encoded matrix (0 if it failed).
	 * @return array{bytes: int, capacity: int, percent: int, version: int, dense: bool}
	 */
	public static function usage( string $payload, string $ecc, int $side ): array {
		$bytes    = strlen( $payload );
		$capacity = self::CAPACITY[ $ecc ] ?? self::CAPACITY['M'];
		$version  = $side >= 21 ? (int) ( ( $side - 17 ) / 4 ) : 0;

		return array(
			'bytes'    => $bytes,
			'capacity' => $capacity,
			'percent'  => (int) min( 100, (int) ceil( $bytes * 100 / $capacity ) ),
			'version'  => $version,
			'dense'    => $version >= self::DENSE_VERSION,
		);
	}

	/**
	 * A web address.
	 *
	 * @param string $url Candidate.
	 * @return string|WP_Error
	 */
	private static function url( string $url ) {
		if ( '' !== $url && ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $url ) ) {
			$url = 'https://' . $url;
		}
		$clean = esc_url_raw( $url, array( 'http', 'https' ) );
		if ( '' === $clean || false === wp_http_validate_url( $clean ) ) {
			return self::missing( __( 'Enter a valid web address (http or https).', 'ffcertificate' ) );
		}
		return $clean;
	}

	/**
	 * A Wi-Fi network, in the `WIFI:` format phone cameras join.
	 *
	 * @param string $ssid     Network name.
	 * @param string $password Password.
	 * @param string $security WPA, WEP or nopass.
	 * @param bool   $hidden   Whether the network hides its name.
	 * @return string|WP_Error
	 */
	private static function wifi( string $ssid, string $password, string $security, bool $hidden ) {
		if ( '' === $ssid ) {
			return self::missing( __( 'Enter the network name.', 'ffcertificate' ) );
		}
		$security = in_array( $security, self::WIFI_SECURITY, true ) ? $security : 'WPA';
		if ( 'nopass' !== $security && '' === $password ) {
			return self::missing( __( 'Enter the network password, or choose an open network.', 'ffcertificate' ) );
		}

		$payload = 'WIFI:T:' . $security . ';S:' . self::wifi_escape( $ssid ) . ';';
		if ( 'nopass' !== $security ) {
			$payload .= 'P:' . self::wifi_escape( $password ) . ';';
		}
		if ( $hidden ) {
			$payload .= 'H:true;';
		}
		return $payload . ';';
	}

	/**
	 * Escape a Wi-Fi field: backslash, semicolon, comma, colon and quote are special.
	 *
	 * @param string $value Field.
	 * @return string
	 */
	private static function wifi_escape( string $value ): string {
		return (string) preg_replace( '/([\\\\;,:"])/', '\\\\$1', $value );
	}

	/**
	 * An e-mail draft, as a `mailto:` link.
	 *
	 * @param string $address Recipient.
	 * @param string $subject Subject.
	 * @param string $body    Body.
	 * @return string|WP_Error
	 */
	private static function email( string $address, string $subject, string $body ) {
		$address = \FreeFormCertificate\Core\DataSanitizer::normalize_email( sanitize_email( $address ) );
		if ( '' === $address || ! is_email( $address ) ) {
			return self::missing( __( 'Enter a valid e-mail address.', 'ffcertificate' ) );
		}

		$query = array();
		if ( '' !== $subject ) {
			$query[] = 'subject=' . rawurlencode( $subject );
		}
		if ( '' !== $body ) {
			$query[] = 'body=' . rawurlencode( $body );
		}
		return 'mailto:' . $address . ( array() === $query ? '' : '?' . implode( '&', $query ) );
	}

	/**
	 * A WhatsApp chat link with an optional pre-filled message.
	 *
	 * @param string $phone   Number with country code.
	 * @param string $message Message.
	 * @return string|WP_Error
	 */
	private static function whatsapp( string $phone, string $message ) {
		$digits = ltrim( self::phone_number( $phone ), '+' );
		if ( '' === $digits ) {
			return self::missing( __( 'Enter a valid phone number.', 'ffcertificate' ) );
		}
		return 'https://wa.me/' . $digits . ( '' === $message ? '' : '?text=' . rawurlencode( $message ) );
	}

	/**
	 * A dialable number: digits with an optional leading `+`, 3 to 15 digits.
	 *
	 * @param string $raw As typed, with spaces, dashes or brackets.
	 * @return string '' when it is not a phone number.
	 */
	private static function phone_number( string $raw ): string {
		$plus   = 0 === strpos( ltrim( $raw ), '+' );
		$digits = (string) preg_replace( '/\D+/', '', $raw );
		$length = strlen( $digits );
		return $length >= 3 && $length <= 15 ? ( $plus ? '+' : '' ) . $digits : '';
	}

	/**
	 * A validation error for a missing or malformed field.
	 *
	 * @param string $message Message.
	 * @return WP_Error
	 */
	private static function missing( string $message ): WP_Error {
		return new WP_Error( 'ffc_qr_field', $message );
	}
}

<?php
/**
 * Signed, stateless link to an event's .ics file.
 *
 * @package FreeFormCertificate\Generators
 * @since   6.34.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Generators;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carries a manual QR code's event in the link itself (#1563).
 *
 * The generator stores nothing, so the event travels in the URL as
 * base64url JSON, and an HMAC keyed on the site's salts makes the site serve
 * only the events its own generator signed. Without the signature, anyone
 * could make the site's domain hand out an `.ics` file of their choosing --
 * an invitation that looks like it came from the institution.
 */
final class QrEventLink {

	/** `admin-post.php` action serving the file. */
	public const ACTION = 'ffc_qr_ics';

	/** Hex characters of the signature kept: 128 bits. */
	private const SIGNATURE_LENGTH = 32;

	/** Event keys carried, in order. */
	private const KEYS = array( 'title', 'location', 'description', 'date', 'start', 'end' );

	/**
	 * The download link for an event.
	 *
	 * @param array<string, string> $event Keys of self::KEYS.
	 * @return string
	 */
	public static function url( array $event ): string {
		$data = self::encode( self::pick( $event ) );

		return add_query_arg(
			array(
				'action' => self::ACTION,
				'e'      => $data,
				's'      => self::sign( $data ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * The event a signed link carries, or null when it was not signed here.
	 *
	 * @param string $data      The `e` parameter.
	 * @param string $signature The `s` parameter.
	 * @return array<string, string>|null
	 */
	public static function verify( string $data, string $signature ): ?array {
		if ( '' === $data || ! hash_equals( self::sign( $data ), $signature ) ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- benign: reading back the event this class encoded, after its signature checked.
		$json = base64_decode( strtr( $data, '-_', '+/' ), true );
		if ( false === $json ) {
			return null;
		}
		$event = json_decode( $json, true );

		return is_array( $event ) ? self::pick( $event ) : null;
	}

	/**
	 * Only the known keys, as strings.
	 *
	 * @param array<mixed> $event Event.
	 * @return array<string, string>
	 */
	private static function pick( array $event ): array {
		$out = array();
		foreach ( self::KEYS as $key ) {
			$value       = $event[ $key ] ?? '';
			$out[ $key ] = is_scalar( $value ) ? (string) $value : '';
		}
		return $out;
	}

	/**
	 * Base64url JSON of the event.
	 *
	 * @param array<string, string> $event Event.
	 * @return string
	 */
	private static function encode( array $event ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- benign: carrying the event in a URL, signed below.
		return rtrim( strtr( base64_encode( (string) wp_json_encode( $event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ), '+/', '-_' ), '=' );
	}

	/**
	 * HMAC of the payload, keyed on the site's salts.
	 *
	 * @param string $data Payload.
	 * @return string
	 */
	private static function sign( string $data ): string {
		return substr( hash_hmac( 'sha256', $data, wp_salt( 'auth' ) . '|' . self::ACTION ), 0, self::SIGNATURE_LENGTH );
	}
}

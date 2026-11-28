<?php
/**
 * Audience Terminal Join Code
 *
 * The on-site sign-up code a terminal shows (#2968) and its one verifier.
 *
 * @package FreeFormCertificate\Audience
 * @since 6.50.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\AttendanceTargetRegistry;
use FreeFormCertificate\Core\EcdsaP256;
use FreeFormCertificate\Core\TerminalTargetKey;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a terminal shows to a person without the app, and what the site
 * checks when their phone opens it.
 *
 * The terminal signs the code with its own P-256 key, made in its browser
 * and never sent anywhere; the site keeps only the public half, registered
 * by the terminal over its authenticated channel. So the terminal draws a
 * valid code with no connection, and a copy of the site's tables forges none.
 *
 * The code travels as the `ffc_join` query argument of a site address,
 * base64url of these bytes. Version 1:
 *
 * | Bytes | Field |
 * | --- | --- |
 * | 1 | version, `0x01` |
 * | 4 | terminal id, unsigned big-endian |
 * | 1 | target type ({@see AudiencePresenceCode::TARGETS}) |
 * | 4 | target id, unsigned big-endian |
 * | 4 | issue time, Unix time, unsigned big-endian |
 * | 64 | terminal signature, raw `r || s` |
 *
 * The signature covers {@see self::DOMAIN} followed by every byte before it.
 * The terminal draws a new code every {@see self::ROTATION} seconds; the site
 * accepts one up to a rotation old, give or take {@see self::SKEW} seconds
 * of clock difference. The code carries no personal data.
 */
final class AudienceTerminalJoinCode {

	/**
	 * The query argument the code travels in.
	 */
	public const QUERY_VAR = 'ffc_join';

	/**
	 * The format version.
	 */
	public const VERSION = 1;

	/**
	 * What the signature covers ahead of the body.
	 */
	public const DOMAIN = "FFC-TERMINAL-JOIN\x00";

	/**
	 * Seconds between two codes on the terminal: a photo forwarded elsewhere
	 * dies within a minute.
	 */
	public const ROTATION = 30;

	/**
	 * Clock difference tolerated between the terminal and the site, in
	 * seconds. The terminal corrects its clock by the site's `Date` header
	 * whenever it reaches the site, as it does for presence readings.
	 */
	public const SKEW = 15;

	/**
	 * Bytes before the signature.
	 */
	private const BODY_BYTES = 14;

	/**
	 * Read and verify a code.
	 *
	 * @param string $text The code, as the query argument carried it.
	 * @param int    $now  The site's Unix time.
	 * @return array{terminal_id: int, target_type: string, target_id: int, key: int|string, issued_at: int, title: string}|\WP_Error
	 */
	public static function verify( string $text, int $now ) {
		$parts = self::split( $text );
		if ( null === $parts ) {
			return new \WP_Error( 'ffc_join_malformed', __( 'This is not a sign-up code. Read the code on the terminal again.', 'ffcertificate' ) );
		}

		$terminal = AudienceTerminalReader::terminal( $parts['terminal_id'] );
		$key      = null === $terminal ? '' : self::decode( $terminal['join_public_key'] );
		if ( null === $terminal || null === $key || '' === $key ) {
			return new \WP_Error( 'ffc_join_terminal', __( 'This code comes from a terminal the site does not know. Read the code on the terminal again.', 'ffcertificate' ) );
		}
		if ( ! EcdsaP256::verify( self::DOMAIN . $parts['body'], $parts['signature'], $key ) ) {
			return new \WP_Error( 'ffc_join_signature', __( 'This code was not made by the terminal it names.', 'ffcertificate' ) );
		}
		if ( null !== $terminal['revoked_at'] || $terminal['expires_at'] <= $now ) {
			return new \WP_Error( 'ffc_join_ended', __( 'The terminal that showed this code was ended. Ask the event operator.', 'ffcertificate' ) );
		}
		if ( $parts['issued_at'] > $now + self::SKEW ) {
			return new \WP_Error( 'ffc_join_future', __( 'This code is dated in the future. Read the code on the terminal again.', 'ffcertificate' ) );
		}
		if ( $parts['issued_at'] < $now - self::ROTATION - self::SKEW ) {
			return new \WP_Error( 'ffc_join_stale', __( 'This code expired. Read the code on the terminal again.', 'ffcertificate' ) );
		}

		$target_key = TerminalTargetKey::of( $parts['target_type'], $parts['target_id'] );
		if ( ! AudienceTerminalPairing::held_at( $terminal, $target_key, $parts['issued_at'] ) ) {
			return new \WP_Error( 'ffc_join_other_event', __( 'This code is for an event the terminal does not serve.', 'ffcertificate' ) );
		}
		$resolved = AttendanceTargetRegistry::terminal( $target_key );
		if ( null === $resolved || ! $resolved['target']->terminal_accepts_join( $resolved['id'] ) ) {
			return new \WP_Error( 'ffc_join_not_accepted', __( 'This event does not take sign-up on site. Ask the event operator.', 'ffcertificate' ) );
		}
		if ( ! self::open( $resolved['target'], $resolved['id'], $now ) ) {
			return new \WP_Error( 'ffc_join_closed', __( 'Check-in for this event is not open now. Ask the event operator.', 'ffcertificate' ) );
		}

		return array(
			'terminal_id' => $parts['terminal_id'],
			'target_type' => $parts['target_type'],
			'target_id'   => $parts['target_id'],
			'key'         => $target_key,
			'issued_at'   => $parts['issued_at'],
			'title'       => $resolved['target']->terminal_title( $resolved['id'] ),
		);
	}

	/**
	 * Whether check-in for a target is open at a moment: one of its windows
	 * covers it and the target says reading is open.
	 *
	 * @param \FreeFormCertificate\Core\TerminalTargetInterface $target    The target.
	 * @param int                                               $target_id Id within the type.
	 * @param int                                               $now       Unix time.
	 * @return bool
	 */
	private static function open( $target, int $target_id, int $now ): bool {
		foreach ( $target->terminal_windows( $target_id ) as $window ) {
			if ( $window['opens'] <= $now && $now < $window['closes'] && $target->terminal_entry( $target_id, $window, $now )['open'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A raw public point from its base64url form, or null when it is not one.
	 *
	 * @param string $text Base64url.
	 * @return string|null
	 */
	public static function decode( string $text ): ?string {
		if ( '' === $text ) {
			return '';
		}
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $text ) ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- benign: base64url of a public key or a code.
		$raw = base64_decode( strtr( $text, '-_', '+/' ), true );
		return false === $raw ? null : $raw;
	}

	/**
	 * Base64url of bytes, without padding.
	 *
	 * @param string $raw Bytes.
	 * @return string
	 */
	public static function encode( string $raw ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- benign: base64url of a public key or a code.
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	/**
	 * Split a code into fields, or null when it is not a version 1 code.
	 *
	 * @param string $text Base64url.
	 * @return array{terminal_id: int, target_type: string, target_id: int, issued_at: int, body: string, signature: string}|null
	 */
	private static function split( string $text ): ?array {
		$raw = self::decode( trim( $text ) );
		if ( null === $raw || self::BODY_BYTES + EcdsaP256::SIGNATURE_BYTES !== strlen( $raw ) || self::VERSION !== ord( $raw[0] ) ) {
			return null;
		}
		$type   = ord( $raw[5] );
		$fields = unpack( 'Nterminal', (string) substr( $raw, 1, 4 ) );
		$rest   = unpack( 'Ntarget/Nissued', (string) substr( $raw, 6, 8 ) );
		if ( ! is_array( $fields ) || ! is_array( $rest ) || ! isset( AudiencePresenceCode::TARGETS[ $type ] ) ) {
			return null;
		}
		$terminal_id = (int) $fields['terminal'];
		$target_id   = (int) $rest['target'];
		if ( $terminal_id <= 0 || $target_id <= 0 ) {
			return null;
		}
		return array(
			'terminal_id' => $terminal_id,
			'target_type' => AudiencePresenceCode::TARGETS[ $type ],
			'target_id'   => $target_id,
			'issued_at'   => (int) $rest['issued'],
			'body'        => (string) substr( $raw, 0, self::BODY_BYTES ),
			'signature'   => (string) substr( $raw, self::BODY_BYTES ),
		);
	}
}

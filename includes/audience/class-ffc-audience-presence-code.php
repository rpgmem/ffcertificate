<?php
/**
 * Audience Presence Code
 *
 * The participant's presence QR code and its one verifier (#2742).
 *
 * @package FreeFormCertificate\Audience
 * @since 6.50.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\Base45;
use FreeFormCertificate\Core\EcdsaP256;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What the phone shows and the terminal reads.
 *
 * The text is {@see self::PREFIX} followed by Base45 of these bytes, so the
 * whole code is the QR alphanumeric set. Version 1:
 *
 * | Bytes | Field |
 * | --- | --- |
 * | 1 | version, `0x01` |
 * | 2 + n | attestation ({@see AudiencePresenceAttestation}), length-prefixed, unsigned big-endian |
 * | 1 | target type ({@see self::TARGETS}) |
 * | 4 | target id, unsigned big-endian |
 * | 4 | reading time, Unix time, unsigned big-endian |
 * | 1 + n | ticket ({@see AudiencePresenceTicket}), length-prefixed; length 0 when there is none |
 * | 64 | device signature, raw `r || s` |
 *
 * The device signature covers {@see self::DOMAIN} followed by every byte
 * before it, and is made by the key the attestation names, so a code is
 * valid only from the phone that holds that key. The phone draws a new one
 * every {@see self::ROTATION} seconds; a terminal accepts a reading up to one
 * rotation old, give or take {@see self::SKEW} seconds of clock difference.
 *
 * The short name inside the attestation is the only personal data in a code;
 * a CPF, an RF or an e-mail never is.
 *
 * This class is the one verifier: the server uses it on what a terminal
 * sends, and the terminal path of #2745 calls it too.
 */
final class AudiencePresenceCode {

	/**
	 * The text every code starts with.
	 */
	public const PREFIX = 'FFP1';

	/**
	 * The format version.
	 */
	public const VERSION = 1;

	/**
	 * What the device signature covers ahead of the body.
	 */
	public const DOMAIN = "FFC-PRESENCE-READING\x00";

	/**
	 * Seconds between two codes on the phone.
	 */
	public const ROTATION = 30;

	/**
	 * Clock difference tolerated between the phone and the verifier, in seconds.
	 */
	public const SKEW = 60;

	/**
	 * Attendance target types, by the byte that carries them.
	 *
	 * @var array<int, string>
	 */
	public const TARGETS = array(
		1 => AudienceAttendanceTarget::TYPE,
	);

	/**
	 * Read and verify a code.
	 *
	 * @param string   $text    The scanned text.
	 * @param int      $now     The verifier's Unix time.
	 * @param string[] $revoked Revoked device key ids.
	 * @return array{user_id: int, name: string, key_id: string, target_type: string, target_id: int, read_at: int, ticket: bool}|\WP_Error
	 */
	public static function verify( string $text, int $now, array $revoked = array() ) {
		$malformed = new \WP_Error( 'ffc_presence_code_malformed', __( 'This is not a presence code.', 'ffcertificate' ) );
		$text      = trim( $text );
		if ( 0 !== strpos( $text, self::PREFIX ) ) {
			return $malformed;
		}
		$raw = Base45::decode( substr( $text, strlen( self::PREFIX ) ) );
		if ( null === $raw ) {
			return $malformed;
		}
		$parts = self::split( $raw );
		if ( null === $parts ) {
			return $malformed;
		}

		$attestation = AudiencePresenceAttestation::verify_raw( $parts['attestation'], $now );
		if ( is_wp_error( $attestation ) ) {
			return $attestation;
		}
		if ( ! EcdsaP256::verify( self::DOMAIN . $parts['body'], $parts['signature'], $attestation['public_key'] ) ) {
			return new \WP_Error( 'ffc_presence_code_signature', __( 'This presence code was not made by the phone it names.', 'ffcertificate' ) );
		}
		if ( in_array( $attestation['key_id'], $revoked, true ) ) {
			return new \WP_Error( 'ffc_presence_code_revoked', __( 'This phone was replaced or revoked. Activate the new one in the app.', 'ffcertificate' ) );
		}
		if ( $parts['read_at'] > $now + self::SKEW ) {
			return new \WP_Error( 'ffc_presence_code_future', __( 'This presence code is dated in the future. Check the phone\'s clock.', 'ffcertificate' ) );
		}
		if ( $parts['read_at'] < $now - self::ROTATION - self::SKEW ) {
			return new \WP_Error( 'ffc_presence_code_stale', __( 'This presence code is old. Show the code on the phone again.', 'ffcertificate' ) );
		}

		$ticket = false;
		if ( '' !== $parts['ticket'] ) {
			$read = AudiencePresenceTicket::verify( $parts['ticket'], $now );
			if ( is_wp_error( $read ) ) {
				return $read;
			}
			if ( $read['user_id'] !== $attestation['user_id'] || $read['target_type'] !== $parts['target_type'] || $read['target_id'] !== $parts['target_id'] ) {
				return new \WP_Error( 'ffc_presence_ticket_mismatch', __( 'The event ticket in this presence code is for another person or event.', 'ffcertificate' ) );
			}
			$ticket = true;
		}

		return array(
			'user_id'     => $attestation['user_id'],
			'name'        => $attestation['name'],
			'key_id'      => $attestation['key_id'],
			'target_type' => $parts['target_type'],
			'target_id'   => $parts['target_id'],
			'read_at'     => $parts['read_at'],
			'ticket'      => $ticket,
		);
	}

	/**
	 * Split raw bytes into fields, or null when they are not a version 1 code.
	 *
	 * @param string $raw Bytes.
	 * @return array{attestation: string, target_type: string, target_id: int, read_at: int, ticket: string, body: string, signature: string}|null
	 */
	private static function split( string $raw ): ?array {
		$length = strlen( $raw );
		if ( $length < 3 || self::VERSION !== ord( $raw[0] ) ) {
			return null;
		}
		$size   = unpack( 'n', (string) substr( $raw, 1, 2 ) );
		$size   = is_array( $size ) ? (int) $size[1] : 0;
		$offset = 3 + $size;
		if ( $size < 1 || $length < $offset + 10 ) {
			return null;
		}
		$attestation = (string) substr( $raw, 3, $size );
		$type        = ord( $raw[ $offset ] );
		$fields      = unpack( 'Ntarget/Nread', (string) substr( $raw, $offset + 1, 8 ) );
		$ticket_size = ord( $raw[ $offset + 9 ] );
		$offset     += 10;
		if ( ! is_array( $fields ) || ! isset( self::TARGETS[ $type ] ) || $length !== $offset + $ticket_size + EcdsaP256::SIGNATURE_BYTES ) {
			return null;
		}
		return array(
			'attestation' => $attestation,
			'target_type' => self::TARGETS[ $type ],
			'target_id'   => (int) $fields['target'],
			'read_at'     => (int) $fields['read'],
			'ticket'      => (string) substr( $raw, $offset, $ticket_size ),
			'body'        => (string) substr( $raw, 0, $offset + $ticket_size ),
			'signature'   => (string) substr( $raw, $offset + $ticket_size ),
		);
	}
}

<?php
/**
 * The canonical record a certificate's signature covers.
 *
 * @package FreeFormCertificate\Core
 * @since   6.45.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the exact bytes a certificate signature covers (#2639).
 *
 * A signature is checked by rebuilding the same bytes on the other side: the
 * verification page from the database, the offline verifier from the QR
 * code. One character of difference (a trailing space, an accent stored
 * decomposed, a date written another way) reads as "altered" with no fraud
 * involved, so every value goes through {@see self::normalize()} and every
 * sequence through {@see self::join()}, and nothing here depends on the site's
 * locale, timezone or date format.
 *
 * Two strings come out of a certificate:
 *
 * - the RECORD, never printed, which binds the signature to the database
 *   (`v1|form|submission|code|issued|name|identifier|course|workload|start|end`);
 *   its SHA-256 is the record hash;
 * - the PAYLOAD the QR code carries, full (`v1|key|code|date|name|course|hours|hash`)
 *   or compact (`v1|key|code|date|hash`).
 *
 * The identifier is never in either: the record holds an HMAC of the CPF/RF
 * keyed by the signing key ({@see SigningKeys::identifier_mac()}), so the
 * record hash, which the QR publishes, cannot be reversed by trying every CPF.
 * Layout (template, background, colours) is outside the record on purpose:
 * changing how a certificate looks never invalidates it.
 */
final class CertificateRecord {

	/**
	 * Format version, the first field of every record and payload.
	 */
	public const VERSION = 'v1';

	/**
	 * Field separator.
	 */
	public const SEPARATOR = '|';

	/**
	 * The record's fields, in order. A missing field is the empty string.
	 *
	 * @var list<string>
	 */
	public const RECORD_FIELDS = array(
		'form_id',
		'submission_id',
		'auth_code',
		'issued',
		'name',
		'identifier',
		'course',
		'workload',
		'event_start',
		'event_end',
	);

	/**
	 * The fields a reader compares with the paper, by the label they carry
	 * when the verification page names what changed.
	 *
	 * @return array<string, string>
	 */
	public static function field_labels(): array {
		return array(
			'form_id'       => __( 'Form', 'ffcertificate' ),
			'submission_id' => __( 'Record', 'ffcertificate' ),
			'auth_code'     => __( 'Authentication code', 'ffcertificate' ),
			'issued'        => __( 'Issue date', 'ffcertificate' ),
			'name'          => __( 'Name', 'ffcertificate' ),
			'identifier'    => __( 'CPF/RF', 'ffcertificate' ),
			'course'        => __( 'Course', 'ffcertificate' ),
			'workload'      => __( 'Workload', 'ffcertificate' ),
			'event_start'   => __( 'Event date', 'ffcertificate' ),
			'event_end'     => __( 'Event date', 'ffcertificate' ),
		);
	}

	/**
	 * One value in its canonical form: Unicode NFC where the server can
	 * compose, inner runs of whitespace kept, the ends trimmed.
	 *
	 * @param mixed $value Any scalar.
	 */
	public static function normalize( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$text = (string) $value;
		if ( class_exists( '\Normalizer' ) ) {
			$composed = \Normalizer::normalize( $text, \Normalizer::FORM_C );
			if ( is_string( $composed ) ) {
				$text = $composed;
			}
		}
		return trim( $text );
	}

	/**
	 * A date as `YYYY-MM-DD`, from an instant (unix seconds, read in the site's
	 * timezone, as the certificate prints it) or a date string already in that
	 * shape.
	 *
	 * @param mixed $value Unix seconds or a `YYYY-MM-DD` string.
	 */
	public static function date( $value ): string {
		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) && strlen( $value ) > 8 ) ) {
			// The date the certificate prints: the site's calendar day.
			return function_exists( 'wp_date' ) ? (string) wp_date( 'Y-m-d', (int) $value ) : gmdate( 'Y-m-d', (int) $value );
		}
		$text = self::normalize( $value );
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $text ) ? $text : '';
	}

	/**
	 * Every calendar day an instant falls on somewhere: UTC-12 to UTC+14.
	 *
	 * A payload carries the issue day in the timezone the site had when it
	 * signed. The record carries the instant, so a signature is checked
	 * against these days rather than the one today's timezone gives, and a
	 * timezone change does not turn genuine certificates red (#2696).
	 *
	 * @param mixed $value Unix seconds.
	 * @return list<string> One to three `YYYY-MM-DD` days, earliest first.
	 */
	public static function dates_of_instant( $value ): array {
		if ( ! is_numeric( $value ) ) {
			return array();
		}
		$ts = (int) $value;
		return array_values( array_unique( array( gmdate( 'Y-m-d', $ts - 12 * 3600 ), gmdate( 'Y-m-d', $ts ), gmdate( 'Y-m-d', $ts + 14 * 3600 ) ) ) );
	}

	/**
	 * A workload as a plain integer of hours, read from text such as
	 * "40 horas" or "40h"; '' when it carries no number.
	 *
	 * @param mixed $value The workload as the form stores it.
	 */
	public static function hours( $value ): string {
		$text = self::normalize( $value );
		return 1 === preg_match( '/\d+/', $text, $match ) ? (string) (int) $match[0] : '';
	}

	/**
	 * Join values with the separator, escaping a separator or a backslash
	 * inside a value with a backslash.
	 *
	 * @param string[] $values Already normalized values, in order.
	 */
	public static function join( array $values ): string {
		return implode(
			self::SEPARATOR,
			array_map(
				static fn( string $v ): string => str_replace( array( '\\', self::SEPARATOR ), array( '\\\\', '\\' . self::SEPARATOR ), $v ),
				$values
			)
		);
	}

	/**
	 * Split a joined string back into its values (the inverse of {@see self::join()}).
	 *
	 * @param string $joined A joined string.
	 * @return list<string>
	 */
	public static function split( string $joined ): array {
		$out     = array();
		$current = '';
		$length  = strlen( $joined );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $joined[ $i ];
			if ( '\\' === $char && $i + 1 < $length ) {
				$current .= $joined[ ++$i ];
			} elseif ( self::SEPARATOR === $char ) {
				$out[]   = $current;
				$current = '';
			} else {
				$current .= $char;
			}
		}
		$out[] = $current;
		return $out;
	}

	/**
	 * The record string for a set of fields.
	 *
	 * @param array<string, scalar> $fields Values by {@see self::RECORD_FIELDS} name.
	 */
	public static function record( array $fields ): string {
		$values = array( self::VERSION );
		foreach ( self::RECORD_FIELDS as $name ) {
			$values[] = self::normalize( $fields[ $name ] ?? '' );
		}
		return self::join( $values );
	}

	/**
	 * The record's fields, read back from a record string.
	 *
	 * @param string $record A record string.
	 * @return array<string, string> Empty when the version is not one this code reads.
	 */
	public static function fields_of( string $record ): array {
		$values = self::split( $record );
		if ( self::VERSION !== array_shift( $values ) || count( $values ) !== count( self::RECORD_FIELDS ) ) {
			return array();
		}
		return array_combine( self::RECORD_FIELDS, $values );
	}

	/**
	 * The record hash: SHA-256 of the record, hex.
	 *
	 * @param string $record A record string.
	 */
	public static function hash( string $record ): string {
		return hash( 'sha256', $record );
	}

	/**
	 * The full payload the QR code carries.
	 *
	 * @param string                $key_id Signing key id.
	 * @param array<string, scalar> $fields Record fields.
	 * @param string                $hash   The record hash.
	 * @param string|null           $date   The issue day to write, instead of the site's day for the instant.
	 */
	public static function full_payload( string $key_id, array $fields, string $hash, ?string $date = null ): string {
		return self::join(
			array(
				self::VERSION,
				self::normalize( $key_id ),
				self::normalize( $fields['auth_code'] ?? '' ),
				$date ?? self::date( $fields['issued'] ?? '' ),
				self::normalize( $fields['name'] ?? '' ),
				self::normalize( $fields['course'] ?? '' ),
				self::hours( $fields['workload'] ?? '' ),
				$hash,
			)
		);
	}

	/**
	 * The compact payload: code, date and hash only.
	 *
	 * @param string                $key_id Signing key id.
	 * @param array<string, scalar> $fields Record fields.
	 * @param string                $hash   The record hash.
	 */
	public static function compact_payload( string $key_id, array $fields, string $hash ): string {
		return self::join(
			array(
				self::VERSION,
				self::normalize( $key_id ),
				self::normalize( $fields['auth_code'] ?? '' ),
				self::date( $fields['issued'] ?? '' ),
				$hash,
			)
		);
	}

	/**
	 * Which fields differ between two records' fields.
	 *
	 * @param array<string, string> $signed  Fields as signed.
	 * @param array<string, string> $current Fields as the database holds them now.
	 * @return list<string> Field names, in record order.
	 */
	public static function changed( array $signed, array $current ): array {
		$out = array();
		foreach ( self::RECORD_FIELDS as $name ) {
			if ( self::normalize( $signed[ $name ] ?? '' ) !== self::normalize( $current[ $name ] ?? '' ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}
}

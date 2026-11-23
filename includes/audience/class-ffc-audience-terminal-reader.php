<?php
/**
 * Audience Terminal Reader
 *
 * Read-side of the check-in terminals repository (#2744). Writes live in
 * {@see AudienceTerminalWriter}.
 *
 * @package FreeFormCertificate\Audience
 * @since 6.50.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\ArrayValue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement in this class runs against the plugin's own ffc_audience_terminals and ffc_audience_terminal_pairings tables, which WordPress exposes no API for. Nothing is cached: pairing and authentication read the state at the moment they decide.
/**
 * Read operations for terminals and the pairing codes that create them.
 *
 * @phpstan-type TerminalRow array{id: int, token_hash: string, device_hash: string, label: string, bookings: list<int>, removed: array<int, int>, paired_by: int|null, paired_at: int, expires_at: int, last_seen_at: int|null, queued: int, readings: int, revoked_at: int|null, revoked_by: int|null}
 * @phpstan-type PairingRow array{id: int, code_hash: string, bookings: list<int>, label: string, created_by: int|null, created_at: int, expires_at: int, used_at: int|null, terminal_id: int|null}
 *
 * @since 6.50.0
 */
class AudienceTerminalReader {
	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Terminals table name.
	 *
	 * @return string
	 */
	public static function terminals_table(): string {
		return self::db()->prefix . 'ffc_audience_terminals';
	}

	/**
	 * Pairings table name.
	 *
	 * @return string
	 */
	public static function pairings_table(): string {
		return self::db()->prefix . 'ffc_audience_terminal_pairings';
	}

	/**
	 * A pairing by the keyed hash of its code.
	 *
	 * @param string $code_hash Keyed hash.
	 * @return PairingRow|null
	 */
	public static function pairing_by_hash( string $code_hash ): ?array {
		$wpdb = self::db();
		$row  = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE code_hash = %s LIMIT 1', self::pairings_table(), $code_hash ),
			ARRAY_A
		);
		return is_array( $row ) ? self::shape_pairing( $row ) : null;
	}

	/**
	 * A terminal by id.
	 *
	 * @param int $id Row id.
	 * @return TerminalRow|null
	 */
	public static function terminal( int $id ): ?array {
		$wpdb = self::db();
		$row  = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', self::terminals_table(), $id ),
			ARRAY_A
		);
		return is_array( $row ) ? self::shape_terminal( $row ) : null;
	}

	/**
	 * A terminal by the SHA-256 of its token.
	 *
	 * @param string $token_hash Hash.
	 * @return TerminalRow|null
	 */
	public static function terminal_by_token_hash( string $token_hash ): ?array {
		$wpdb = self::db();
		$row  = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE token_hash = %s LIMIT 1', self::terminals_table(), $token_hash ),
			ARRAY_A
		);
		return is_array( $row ) ? self::shape_terminal( $row ) : null;
	}

	/**
	 * The terminals that may still record attendance, newest first.
	 *
	 * @param int      $now       Unix time.
	 * @param int|null $paired_by Only those this operator paired, or null for all.
	 * @return list<TerminalRow>
	 */
	public static function active_terminals( int $now, ?int $paired_by = null ): array {
		$wpdb = self::db();
		$rows = null === $paired_by
			? $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE revoked_at IS NULL AND expires_at > %d ORDER BY paired_at DESC, id DESC', self::terminals_table(), $now ),
				ARRAY_A
			)
			: $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE revoked_at IS NULL AND expires_at > %d AND paired_by = %d ORDER BY paired_at DESC, id DESC', self::terminals_table(), $now, $paired_by ),
				ARRAY_A
			);
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = self::shape_terminal( $row );
		}
		return $out;
	}

	/**
	 * Every terminal still active, and those that ended (on their own or by
	 * hand) since a moment, newest first (#2748).
	 *
	 * @param int $since Unix time.
	 * @return list<TerminalRow>
	 */
	public static function recent_terminals( int $since ): array {
		$wpdb = self::db();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE ( revoked_at IS NULL AND expires_at >= %d ) OR revoked_at >= %d ORDER BY paired_at DESC, id DESC',
				self::terminals_table(),
				$since,
				$since
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = self::shape_terminal( $row );
		}
		return $out;
	}

	/**
	 * How many active terminals hold a booking.
	 *
	 * @param int $booking_id Booking.
	 * @param int $now        Unix time.
	 * @return int
	 */
	public static function count_active_for_booking( int $booking_id, int $now ): int {
		$count = 0;
		foreach ( self::active_terminals( $now ) as $terminal ) {
			if ( in_array( $booking_id, $terminal['bookings'], true ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Type one raw terminal row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return TerminalRow
	 */
	private static function shape_terminal( array $row ): array {
		return array(
			'id'           => ArrayValue::int( $row, 'id' ),
			'token_hash'   => ArrayValue::string( $row, 'token_hash' ),
			'device_hash'  => ArrayValue::string( $row, 'device_hash' ),
			'label'        => ArrayValue::string( $row, 'label' ),
			'bookings'     => self::ids( ArrayValue::string( $row, 'bookings' ) ),
			'removed'      => self::removals( ArrayValue::string( $row, 'removed' ) ),
			'paired_by'    => self::nullable_int( $row, 'paired_by' ),
			'paired_at'    => ArrayValue::int( $row, 'paired_at' ),
			'expires_at'   => ArrayValue::int( $row, 'expires_at' ),
			'last_seen_at' => self::nullable_int( $row, 'last_seen_at' ),
			'queued'       => ArrayValue::int( $row, 'queued' ),
			'readings'     => ArrayValue::int( $row, 'readings' ),
			'revoked_at'   => self::nullable_int( $row, 'revoked_at' ),
			'revoked_by'   => self::nullable_int( $row, 'revoked_by' ),
		);
	}

	/**
	 * Type one raw pairing row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return PairingRow
	 */
	private static function shape_pairing( array $row ): array {
		return array(
			'id'          => ArrayValue::int( $row, 'id' ),
			'code_hash'   => ArrayValue::string( $row, 'code_hash' ),
			'bookings'    => self::ids( ArrayValue::string( $row, 'bookings' ) ),
			'label'       => ArrayValue::string( $row, 'label' ),
			'created_by'  => self::nullable_int( $row, 'created_by' ),
			'created_at'  => ArrayValue::int( $row, 'created_at' ),
			'expires_at'  => ArrayValue::int( $row, 'expires_at' ),
			'used_at'     => self::nullable_int( $row, 'used_at' ),
			'terminal_id' => self::nullable_int( $row, 'terminal_id' ),
		);
	}

	/**
	 * A JSON list of ids, as positive integers.
	 *
	 * @param string $json JSON text.
	 * @return list<int>
	 */
	public static function ids( string $json ): array {
		$list = json_decode( $json, true );
		$out  = array();
		foreach ( is_array( $list ) ? $list : array() as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$out[] = (int) $id;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * A JSON map of booking id to the moment it left the queue (#2746).
	 *
	 * @param string $json JSON text.
	 * @return array<int, int>
	 */
	public static function removals( string $json ): array {
		$map = json_decode( $json, true );
		$out = array();
		foreach ( is_array( $map ) ? $map : array() as $id => $at ) {
			if ( is_numeric( $id ) && (int) $id > 0 && is_numeric( $at ) && (int) $at > 0 ) {
				$out[ (int) $id ] = (int) $at;
			}
		}
		return $out;
	}

	/**
	 * A nullable integer column.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @param string               $key Column.
	 * @return int|null
	 */
	private static function nullable_int( array $row, string $key ): ?int {
		return null === ( $row[ $key ] ?? null ) ? null : ArrayValue::int( $row, $key );
	}
}

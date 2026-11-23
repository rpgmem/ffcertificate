<?php
/**
 * Audience Terminal Writer
 *
 * Write-side of the check-in terminals repository (#2744). Reads live in
 * {@see AudienceTerminalReader}.
 *
 * @package FreeFormCertificate\Audience
 * @since 6.50.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement in this class runs against the plugin's own ffc_audience_terminals and ffc_audience_terminal_pairings tables, which WordPress exposes no API for; its reader caches nothing.
/**
 * Write operations for terminals and their pairing codes.
 *
 * @since 6.50.0
 */
class AudienceTerminalWriter {
	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Record a pairing code an operator generated.
	 *
	 * @param string $code_hash Keyed hash of the code.
	 * @param int[]  $bookings  Booking ids the terminal will hold.
	 * @param string $label     What the operator called the terminal.
	 * @param int    $by        The operator.
	 * @param int    $now       Unix time.
	 * @param int    $expires   Unix time.
	 * @phpstan-param list<int> $bookings
	 * @return int The new row's id, or 0 on failure.
	 */
	public static function create_pairing( string $code_hash, array $bookings, string $label, int $by, int $now, int $expires ): int {
		$wpdb = self::db();
		$ok   = $wpdb->insert(
			AudienceTerminalReader::pairings_table(),
			array(
				'code_hash'  => $code_hash,
				'label'      => $label,
				'bookings'   => (string) wp_json_encode( $bookings ),
				'created_by' => $by,
				'created_at' => $now,
				'expires_at' => $expires,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%d' )
		);
		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Spend a pairing code. Only one request can: the update names the
	 * conditions that make the code usable, so a second one changes nothing.
	 *
	 * @param int $id  Pairing id.
	 * @param int $now Unix time.
	 * @return bool Whether this call spent it.
	 */
	public static function spend_pairing( int $id, int $now ): bool {
		$wpdb = self::db();
		return self::run(
			$wpdb->prepare(
				'UPDATE %i SET used_at = %d WHERE id = %d AND used_at IS NULL AND expires_at >= %d',
				AudienceTerminalReader::pairings_table(),
				$now,
				$id,
				$now
			)
		) > 0;
	}

	/**
	 * Note which terminal a spent code created.
	 *
	 * @param int $id          Pairing id.
	 * @param int $terminal_id Terminal id.
	 * @return bool
	 */
	public static function link_pairing( int $id, int $terminal_id ): bool {
		$wpdb = self::db();
		return self::run(
			$wpdb->prepare( 'UPDATE %i SET terminal_id = %d WHERE id = %d', AudienceTerminalReader::pairings_table(), $terminal_id, $id )
		) > 0;
	}

	/**
	 * Record a paired terminal.
	 *
	 * @param string   $token_hash  SHA-256 of its token.
	 * @param string   $device_hash Keyed hash of the device id it sent.
	 * @param string   $label       Label.
	 * @param int[]    $bookings    Booking ids it holds.
	 * @param int|null $by          The operator who generated the code.
	 * @param int      $now         Unix time.
	 * @param int      $expires     Unix time.
	 * @phpstan-param list<int> $bookings
	 * @return int The new row's id, or 0 on failure.
	 */
	public static function create_terminal( string $token_hash, string $device_hash, string $label, array $bookings, ?int $by, int $now, int $expires ): int {
		$wpdb = self::db();
		$ok   = $wpdb->insert(
			AudienceTerminalReader::terminals_table(),
			array(
				'token_hash'  => $token_hash,
				'device_hash' => $device_hash,
				'label'       => $label,
				'bookings'    => (string) wp_json_encode( $bookings ),
				'paired_by'   => $by,
				'paired_at'   => $now,
				'expires_at'  => $expires,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%d' )
		);
		return false === $ok ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * End a terminal, if it is still active.
	 *
	 * @param int $id  Terminal id.
	 * @param int $by  Who ended it.
	 * @param int $now Unix time.
	 * @return bool Whether a terminal was ended.
	 */
	public static function revoke( int $id, int $by, int $now ): bool {
		$wpdb = self::db();
		return self::run(
			$wpdb->prepare(
				'UPDATE %i SET revoked_at = %d, revoked_by = %d WHERE id = %d AND revoked_at IS NULL',
				AudienceTerminalReader::terminals_table(),
				$now,
				$by,
				$id
			)
		) > 0;
	}

	/**
	 * Replace the events of an active terminal (#2746).
	 *
	 * @param int             $id       Terminal id.
	 * @param int[]           $bookings Booking ids it holds now.
	 * @param array<int, int> $removed  When each event it no longer holds left.
	 * @param int             $expires  Unix time.
	 * @phpstan-param list<int> $bookings
	 * @return bool Whether an active terminal was changed.
	 */
	public static function set_bookings( int $id, array $bookings, array $removed, int $expires ): bool {
		$wpdb = self::db();
		return self::run(
			$wpdb->prepare(
				'UPDATE %i SET bookings = %s, removed = %s, expires_at = %d WHERE id = %d AND revoked_at IS NULL',
				AudienceTerminalReader::terminals_table(),
				(string) wp_json_encode( $bookings ),
				(string) wp_json_encode( (object) $removed ),
				$expires,
				$id
			)
		) > 0;
	}

	/**
	 * Note that a terminal was heard from.
	 *
	 * @param int $id  Terminal id.
	 * @param int $now Unix time.
	 * @return bool
	 */
	public static function touch( int $id, int $now ): bool {
		$wpdb = self::db();
		return self::run(
			$wpdb->prepare( 'UPDATE %i SET last_seen_at = %d WHERE id = %d', AudienceTerminalReader::terminals_table(), $now, $id )
		) > 0;
	}

	/**
	 * Delete pairing codes that expired before a moment. A spent or expired
	 * code pairs nothing, so the row only takes space.
	 *
	 * @param int $before Unix time.
	 * @return int Rows deleted.
	 */
	public static function purge_pairings( int $before ): int {
		$wpdb = self::db();
		return self::run(
			$wpdb->prepare( 'DELETE FROM %i WHERE expires_at < %d', AudienceTerminalReader::pairings_table(), $before )
		);
	}

	/**
	 * Run one prepared statement.
	 *
	 * @param string|null $sql Prepared SQL.
	 * @return int Rows affected.
	 */
	private static function run( ?string $sql ): int {
		// `prepare()` returns `string|null`, and `query()` takes a string only.
		$rows = is_string( $sql ) ? self::db()->query( $sql ) : false; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepare()'s output, held to test its type.
		return is_int( $rows ) ? $rows : 0;
	}
}

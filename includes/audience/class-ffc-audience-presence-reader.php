<?php
/**
 * Audience Presence Reader
 *
 * Read-side of the presence devices repository (#2741). Writes live in
 * {@see AudiencePresenceWriter}.
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

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement in this class runs against the plugin's own ffc_audience_presence_devices and ffc_audience_presence_requests tables, which WordPress exposes no API for. Nothing is cached: the enrolment rules read the state at the moment they decide.
/**
 * Read operations for presence devices and the requests the rules leave.
 *
 * @phpstan-type DeviceRow array{id: int, user_id: int, device_id: string, key_id: string, public_key: string, signals_hash: string|null, is_change: bool, activated_at: int, revoked_at: int|null, revoked_reason: string|null, revoked_by: int|null, released_at: int|null, released_by: int|null, attested_at: int|null, signals_dismissed_at: int|null, platform: string, browser: string}
 * @phpstan-type RequestRow array{id: int, user_id: int, device_id: string, reason: string, status: string, created_at: int, decided_at: int|null, decided_by: int|null}
 * @phpstan-type SignalGroup array{signals_hash: string, user_ids: list<int>, devices: list<DeviceRow>}
 *
 * @since 6.50.0
 */
class AudiencePresenceReader {
	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Devices table name.
	 *
	 * @return string
	 */
	public static function devices_table(): string {
		return self::db()->prefix . 'ffc_audience_presence_devices';
	}

	/**
	 * Requests table name.
	 *
	 * @return string
	 */
	public static function requests_table(): string {
		return self::db()->prefix . 'ffc_audience_presence_requests';
	}

	/**
	 * The device row carrying a key, active or not.
	 *
	 * @param string $key_id Device key id.
	 * @return DeviceRow|null
	 */
	public static function by_key_id( string $key_id ): ?array {
		$wpdb = self::db();
		$row  = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE key_id = %s LIMIT 1', self::devices_table(), $key_id ),
			ARRAY_A
		);
		return is_array( $row ) ? self::shape( $row ) : null;
	}

	/**
	 * A user's devices, newest first.
	 *
	 * @param int $user_id User.
	 * @return list<DeviceRow>
	 */
	public static function for_user( int $user_id ): array {
		$wpdb = self::db();
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d ORDER BY activated_at DESC, id DESC', self::devices_table(), $user_id ),
			ARRAY_A
		);
		return array_map( array( self::class, 'shape' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Another account the device is reserved to, if any.
	 *
	 * A device stays reserved to an account while it is active there, and for
	 * {@see AudiencePresenceEnrolment::RESERVATION} seconds after it was last
	 * activated or revoked there, unless an administrator released it.
	 *
	 * @param string $device_id Device id.
	 * @param int    $user_id   The account asking, which the device is never reserved against.
	 * @param int    $now       Unix time.
	 * @return int|null The other account's id.
	 */
	public static function reserving_user( string $device_id, int $user_id, int $now ): ?int {
		$reservation = self::reservation( $device_id, $user_id, $now );
		return null === $reservation ? null : $reservation['user_id'];
	}

	/**
	 * The account a phone is reserved to, and until when (#2782): null while
	 * that account still uses it, otherwise the end of the reservation that
	 * started when it stopped.
	 *
	 * @param string $device_id Device id.
	 * @param int    $user_id   The account asking, which the device is never reserved against.
	 * @param int    $now       Unix time.
	 * @return array{user_id: int, until: int|null}|null
	 */
	public static function reservation( string $device_id, int $user_id, int $now ): ?array {
		$wpdb  = self::db();
		$since = $now - AudiencePresenceEnrolment::RESERVATION;
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT user_id, activated_at, revoked_at FROM %i WHERE device_id = %s AND user_id <> %d AND released_at IS NULL'
				. ' AND ( revoked_at IS NULL OR revoked_at >= %d OR activated_at >= %d )'
				. ' ORDER BY activated_at DESC LIMIT 1',
				self::devices_table(),
				$device_id,
				$user_id,
				$since,
				$since
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}
		$revoked = isset( $row['revoked_at'] ) ? (int) $row['revoked_at'] : null;
		return array(
			'user_id' => (int) $row['user_id'],
			'until'   => null === $revoked ? null : max( $revoked, (int) $row['activated_at'] ) + AudiencePresenceEnrolment::RESERVATION,
		);
	}

	/**
	 * How many device changes a user made since a moment.
	 *
	 * @param int $user_id User.
	 * @param int $since   Unix time.
	 * @return int
	 */
	public static function changes_since( int $user_id, int $since ): int {
		$wpdb = self::db();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE user_id = %d AND is_change = 1 AND activated_at >= %d',
				self::devices_table(),
				$user_id,
				$since
			)
		);
	}

	/**
	 * Active devices of other accounts that report the same device signals.
	 *
	 * @param string $signals_hash Keyed hash of the signals.
	 * @param int    $user_id      The account enrolling.
	 * @return list<string> Their device ids.
	 */
	public static function devices_sharing_signals( string $signals_hash, int $user_id ): array {
		$wpdb = self::db();
		$ids  = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT device_id FROM %i WHERE signals_hash = %s AND user_id <> %d AND revoked_at IS NULL LIMIT 20',
				self::devices_table(),
				$signals_hash,
				$user_id
			)
		);
		return array_map( 'strval', $ids );
	}

	/**
	 * Key ids revoked since a moment: what a terminal refuses.
	 *
	 * Only key ids, never a user or a device, so the list carries no personal
	 * data. A key revoked before `$since` needs no entry, because the last
	 * attestation it could carry has expired by then.
	 *
	 * @param int $since Unix time.
	 * @return list<string>
	 */
	public static function revoked_key_ids_since( int $since ): array {
		$wpdb = self::db();
		$ids  = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT key_id FROM %i WHERE revoked_at IS NOT NULL AND revoked_at >= %d ORDER BY revoked_at ASC',
				self::devices_table(),
				$since
			)
		);
		return array_map( 'strval', $ids );
	}

	/**
	 * The key ids revoked within a span: at or after `$since` and at or
	 * before `$until` (#2745). A reading a terminal took offline is checked
	 * against the keys revoked by the moment it was read, so a phone
	 * replaced later does not undo an attendance taken before.
	 *
	 * @param int $since Unix time.
	 * @param int $until Unix time.
	 * @return list<string>
	 */
	public static function revoked_key_ids_between( int $since, int $until ): array {
		$wpdb = self::db();
		$ids  = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT key_id FROM %i WHERE revoked_at IS NOT NULL AND revoked_at >= %d AND revoked_at <= %d ORDER BY revoked_at ASC',
				self::devices_table(),
				$since,
				$until
			)
		);
		return array_map( 'strval', $ids );
	}

	/**
	 * A request in a given state, if any.
	 *
	 * @param int    $user_id   User.
	 * @param string $device_id Device id.
	 * @param string $reason    Refusal reason.
	 * @param string $status    Request status.
	 * @return int|null The request id.
	 */
	public static function request_id( int $user_id, string $device_id, string $reason, string $status ): ?int {
		$wpdb = self::db();
		$id   = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE user_id = %d AND device_id = %s AND reason = %s AND status = %s ORDER BY id DESC LIMIT 1',
				self::requests_table(),
				$user_id,
				$device_id,
				$reason,
				$status
			)
		);
		return null === $id ? null : (int) $id;
	}

	/**
	 * One device row by its id.
	 *
	 * @param int $id Row id.
	 * @return DeviceRow|null
	 */
	public static function by_id( int $id ): ?array {
		$wpdb = self::db();
		$row  = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', self::devices_table(), $id ),
			ARRAY_A
		);
		return is_array( $row ) ? self::shape( $row ) : null;
	}

	/**
	 * Active devices, newest first, one page of them (#2743).
	 *
	 * @param int            $limit    Rows per page.
	 * @param int            $offset   Rows to skip.
	 * @param list<int>|null $user_ids Only these accounts (the screen's search, #2782), or null for every one.
	 * @return list<DeviceRow>
	 */
	public static function active_devices( int $limit, int $offset, ?array $user_ids = null ): array {
		if ( array() === $user_ids ) {
			return array();
		}
		$wpdb = self::db();
		if ( null === $user_ids ) {
			$sql = $wpdb->prepare(
				'SELECT * FROM %i WHERE revoked_at IS NULL ORDER BY activated_at DESC, id DESC LIMIT %d OFFSET %d',
				self::devices_table(),
				max( 1, $limit ),
				max( 0, $offset )
			);
		} else {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The interpolated part is one %d placeholder per account id, filled by prepare().
			$sql = $wpdb->prepare(
				'SELECT * FROM %i WHERE revoked_at IS NULL AND user_id IN (' . implode( ', ', array_fill( 0, count( $user_ids ), '%d' ) ) . ') ORDER BY activated_at DESC, id DESC LIMIT %d OFFSET %d',
				self::devices_table(),
				...array_merge( $user_ids, array( max( 1, $limit ), max( 0, $offset ) ) )
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the prepare() result above.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return array_map( array( self::class, 'shape' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * How many devices are active.
	 *
	 * @param list<int>|null $user_ids Only these accounts, or null for every one.
	 * @return int
	 */
	public static function count_active( ?array $user_ids = null ): int {
		if ( array() === $user_ids ) {
			return 0;
		}
		$wpdb = self::db();
		if ( null === $user_ids ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE revoked_at IS NULL', self::devices_table() )
			);
		}
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The interpolated part is one %d placeholder per account id, filled by prepare().
		$sql = $wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE revoked_at IS NULL AND user_id IN (' . implode( ', ', array_fill( 0, count( $user_ids ), '%d' ) ) . ')',
			self::devices_table(),
			...$user_ids
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the prepare() result above.
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * How many device changes every account made since a moment.
	 *
	 * @param int $since Unix time.
	 * @return int
	 */
	public static function count_changes_since( int $since ): int {
		$wpdb = self::db();
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE is_change = 1 AND activated_at >= %d', self::devices_table(), $since )
		);
	}

	/**
	 * The requests waiting for an administrator, oldest first.
	 *
	 * @return list<RequestRow>
	 */
	public static function pending_requests(): array {
		$wpdb = self::db();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE status = %s ORDER BY created_at ASC, id ASC LIMIT 100',
				self::requests_table(),
				AudiencePresenceWriter::REQUEST_PENDING
			),
			ARRAY_A
		);
		return array_map( array( self::class, 'shape_request' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * One request by its id.
	 *
	 * @param int $id Request id.
	 * @return RequestRow|null
	 */
	public static function request( int $id ): ?array {
		$wpdb = self::db();
		$row  = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', self::requests_table(), $id ),
			ARRAY_A
		);
		return is_array( $row ) ? self::shape_request( $row ) : null;
	}

	/**
	 * Active devices of more than one account that report the same device
	 * signals, and whose alert nobody dismissed (#2743).
	 *
	 * Identical school-issued phones look the same, so a group is a reason
	 * to look, never a refusal.
	 *
	 * @return list<SignalGroup>
	 */
	public static function signal_groups(): array {
		$wpdb    = self::db();
		$rows    = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE revoked_at IS NULL AND signals_hash IS NOT NULL ORDER BY activated_at ASC LIMIT 500',
				self::devices_table()
			),
			ARRAY_A
		);
		$by_hash = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$device                                        = self::shape( $row );
			$by_hash[ (string) $device['signals_hash'] ][] = $device;
		}
		$groups = array();
		foreach ( $by_hash as $hash => $devices ) {
			$users = array_values( array_unique( array_column( $devices, 'user_id' ) ) );
			$fresh = array_filter( $devices, static fn( array $d ): bool => null === $d['signals_dismissed_at'] );
			if ( count( $users ) > 1 && array() !== $fresh ) {
				$groups[] = array(
					'signals_hash' => (string) $hash,
					'user_ids'     => array_map( 'intval', $users ),
					'devices'      => $devices,
				);
			}
		}
		return $groups;
	}

	/**
	 * Type one raw request row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return RequestRow
	 */
	private static function shape_request( array $row ): array {
		return array(
			'id'         => ArrayValue::int( $row, 'id' ),
			'user_id'    => ArrayValue::int( $row, 'user_id' ),
			'device_id'  => ArrayValue::string( $row, 'device_id' ),
			'reason'     => ArrayValue::string( $row, 'reason' ),
			'status'     => ArrayValue::string( $row, 'status' ),
			'created_at' => ArrayValue::int( $row, 'created_at' ),
			'decided_at' => null === ( $row['decided_at'] ?? null ) ? null : ArrayValue::int( $row, 'decided_at' ),
			'decided_by' => null === ( $row['decided_by'] ?? null ) ? null : ArrayValue::int( $row, 'decided_by' ),
		);
	}

	/**
	 * Type one raw device row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return DeviceRow
	 */
	private static function shape( array $row ): array {
		$nullable_int = static function ( string $key ) use ( $row ): ?int {
			return null === ( $row[ $key ] ?? null ) ? null : ArrayValue::int( $row, $key );
		};
		$nullable_str = static function ( string $key ) use ( $row ): ?string {
			return null === ( $row[ $key ] ?? null ) ? null : ArrayValue::string( $row, $key );
		};

		return array(
			'id'                   => ArrayValue::int( $row, 'id' ),
			'user_id'              => ArrayValue::int( $row, 'user_id' ),
			'device_id'            => ArrayValue::string( $row, 'device_id' ),
			'key_id'               => ArrayValue::string( $row, 'key_id' ),
			'public_key'           => ArrayValue::string( $row, 'public_key' ),
			'signals_hash'         => $nullable_str( 'signals_hash' ),
			'is_change'            => 1 === ArrayValue::int( $row, 'is_change' ),
			'activated_at'         => ArrayValue::int( $row, 'activated_at' ),
			'revoked_at'           => $nullable_int( 'revoked_at' ),
			'revoked_reason'       => $nullable_str( 'revoked_reason' ),
			'revoked_by'           => $nullable_int( 'revoked_by' ),
			'released_at'          => $nullable_int( 'released_at' ),
			'released_by'          => $nullable_int( 'released_by' ),
			'attested_at'          => $nullable_int( 'attested_at' ),
			'signals_dismissed_at' => $nullable_int( 'signals_dismissed_at' ),
			'platform'             => (string) $nullable_str( 'platform' ),
			'browser'              => (string) $nullable_str( 'browser' ),
		);
	}
}

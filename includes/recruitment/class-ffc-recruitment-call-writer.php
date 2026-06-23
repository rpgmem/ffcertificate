<?php
/**
 * Call Writer
 *
 * Write-side of the call repository split (#563 backlog, B3). Holds the
 * append-only INSERT, the cancellation stamp, and the (notes-only) UPDATE.
 * Reads live in {@see RecruitmentCallReader}. Callers depend on the reader
 * (reads) and this writer (writes) directly; the delegating façade was retired
 * in #563 B3-A.
 *
 * Calls are append-only history: cancellation does NOT delete the row — it
 * stamps `cancellation_reason` / `cancelled_at` / `cancelled_by` on the
 * existing row. A subsequent re-call for the same classification creates a new
 * row.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.11.3
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Write operations for `ffc_recruitment_call` rows.
 *
 * @since 6.11.3
 */
class RecruitmentCallWriter {

	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Cache group for this repository.
	 *
	 * Must match {@see RecruitmentCallReader::cache_group()} so writes
	 * invalidate the entries reads populate.
	 *
	 * @return string
	 */
	protected static function cache_group(): string {
		return 'ffc_recruitment_call';
	}

	/**
	 * Get the fully-prefixed table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		return self::db()->prefix . 'ffc_recruitment_call';
	}

	/**
	 * Insert a new call row.
	 *
	 * Required keys: `classification_id`, `date_to_assume`, `time_to_assume`,
	 * `created_by`. Optional keys: `out_of_order` (defaults to 0),
	 * `out_of_order_reason`, `notes`, `called_at` (defaults to now).
	 *
	 * Invariant (enforced here, also at the service layer): when
	 * `out_of_order = 1`, `out_of_order_reason` must be a non-empty string.
	 * Returns `false` if the invariant is violated.
	 *
	 * @param array{classification_id: int, date_to_assume: string, time_to_assume: string, created_by: int, out_of_order?: int, out_of_order_reason?: string|null, notes?: string|null, called_at?: int|string} $data Call payload.
	 * @return int|false New call ID or false on failure.
	 */
	public static function create( array $data ) {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$out_of_order        = isset( $data['out_of_order'] ) ? (int) $data['out_of_order'] : 0;
		$out_of_order_reason = $data['out_of_order_reason'] ?? null;

		// Repository-level guard for the §3.6 invariant.
		if ( 1 === $out_of_order && ( ! is_string( $out_of_order_reason ) || '' === trim( $out_of_order_reason ) ) ) {
			return false;
		}

		$now = current_time( 'mysql' );
		// `called_at` is unix UTC int since 6.6.0 (#249 sub-escopo c). The
		// caller may still pass a DATETIME string via legacy code paths;
		// normalise to int here so the repository is the single conversion
		// point.
		$called_at_in = $data['called_at'] ?? null;
		if ( null === $called_at_in ) {
			$called_at_ts = time();
		} elseif ( is_int( $called_at_in ) ) {
			$called_at_ts = $called_at_in;
		} else {
			$parsed       = strtotime( (string) $called_at_in );
			$called_at_ts = false === $parsed ? time() : $parsed;
		}

		$insert = array(
			'classification_id'   => $data['classification_id'],
			'called_at'           => $called_at_ts,
			'date_to_assume'      => $data['date_to_assume'],
			'time_to_assume'      => $data['time_to_assume'],
			'out_of_order'        => $out_of_order,
			'out_of_order_reason' => $out_of_order_reason,
			'notes'               => $data['notes'] ?? null,
			'created_by'          => $data['created_by'],
			'created_at'          => $now,
			'updated_at'          => $now,
		);
		$format = array( '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Insert via wpdb helper.
		$result = $wpdb->insert( $table, $insert, $format );

		if ( ! $result ) {
			return false;
		}

		do_action( 'ffc_recruitment_public_cache_dirty' );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Stamp cancellation columns on an existing call row.
	 *
	 * Idempotent within a single cancellation: the WHERE clause requires
	 * `cancelled_at IS NULL`, so a second cancel attempt on an already
	 * cancelled row returns 0. The state machine (sprint 5) calls this
	 * AFTER {@see RecruitmentClassificationRepository::set_status()} has already moved
	 * the classification back to `empty` atomically, so the two operations
	 * together preserve the audit trail.
	 *
	 * @param int    $id Call ID.
	 * @param string $reason Cancellation reason (mandatory; §5.2).
	 * @param int    $cancelled_by WP user ID who performed the cancel.
	 * @return int Number of rows affected (1 on first cancel, 0 if already cancelled).
	 */
	public static function mark_cancelled( int $id, string $reason, int $cancelled_by ): int {
		$wpdb  = self::db();
		$table = self::get_table_name();

		// `cancelled_at` is unix UTC int since 6.6.0 (#249 sub-escopo d).
		$now          = current_time( 'mysql' );
		$cancelled_at = time();

		$prepared = $wpdb->prepare(
			'UPDATE %i SET cancellation_reason = %s, cancelled_at = %d, cancelled_by = %d, updated_at = %s
              WHERE id = %d AND cancelled_at IS NULL',
			$table,
			$reason,
			$cancelled_at,
			$cancelled_by,
			$now,
			$id
		);

		if ( ! is_string( $prepared ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Conditional UPDATE via wpdb->query for affected-rows return; $prepared came from $wpdb->prepare() on the line above.
		$affected = $wpdb->query( $prepared );

		static::cache_delete( "id_{$id}" );

		$rows = is_int( $affected ) ? $affected : 0;
		if ( $rows > 0 ) {
			do_action( 'ffc_recruitment_public_cache_dirty' );
		}

		return $rows;
	}

	/**
	 * Update mutable, non-history fields on a call row.
	 *
	 * Only `notes` is writable post-creation; cancellation columns go
	 * through {@see self::mark_cancelled()}, and audit columns
	 * (`called_at`, `created_by`, `out_of_order*`) are immutable per the
	 * append-only-history contract.
	 *
	 * @param int                  $id Call ID.
	 * @param array<string, mixed> $data Update payload (only `notes` honored).
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$update = array();
		$format = array();

		if ( array_key_exists( 'notes', $data ) ) {
			$update['notes'] = $data['notes'];
			$format[]        = '%s';
		}

		if ( empty( $update ) ) {
			return false;
		}

		$update['updated_at'] = current_time( 'mysql' );
		$format[]             = '%s';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Update via wpdb helper.
		$result = $wpdb->update( $table, $update, array( 'id' => $id ), $format, array( '%d' ) );

		static::cache_delete( "id_{$id}" );

		return false !== $result;
	}
}

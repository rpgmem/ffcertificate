<?php
/**
 * Reregistration Submission Reader
 *
 * Read-side of the reregistration-submission repository split (#563 backlog,
 * Sprint D2). Holds every SELECT / lookup / derived-read query and the pure
 * status-label helpers. Writes live in {@see ReregistrationSubmissionWriter}.
 * Callers depend on this reader (reads) and the writer (writes) directly; the
 * delegating façade was retired in #563 B3-A.
 *
 * @since   6.11.3
 * @package FreeFormCertificate\Reregistration
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Read queries for reregistration submission records.
 *
 * @since 6.11.3
 *
 * @phpstan-type ReregistrationSubmissionRow \stdClass&object{id: string, reregistration_id: string, user_id: string, status: string, submitted_at: numeric-string|int|null, reviewed_at: numeric-string|int|null, reviewed_by: string|null, notes: string|null, auth_code: string|null, magic_token: string|null, created_at: string, updated_at: string, data?: string|null}
 */
class ReregistrationSubmissionReader {
	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Valid submission status values.
	 */
	public const STATUSES = array( 'pending', 'in_progress', 'submitted', 'approved', 'rejected', 'expired' );

	/**
	 * Cache group for reregistration submission queries.
	 *
	 * Must match {@see ReregistrationSubmissionWriter::cache_group()} so writes
	 * invalidate the entries reads populate.
	 *
	 * @return string
	 */
	protected static function cache_group(): string {
		return 'ffc_rereg_submissions';
	}

	/**
	 * Get human-readable status labels.
	 *
	 * @return array<string, string> Status key => translated label.
	 */
	public static function get_status_labels(): array {
		return array(
			'pending'     => __( 'Pending', 'ffcertificate' ),
			'in_progress' => __( 'In Progress', 'ffcertificate' ),
			'submitted'   => __( 'Submitted — Pending Review', 'ffcertificate' ),
			'approved'    => __( 'Approved', 'ffcertificate' ),
			'rejected'    => __( 'Rejected', 'ffcertificate' ),
			'expired'     => __( 'Expired', 'ffcertificate' ),
		);
	}

	/**
	 * Get a single status label.
	 *
	 * @param string $status Status key.
	 * @return string Translated label (falls back to the key).
	 */
	public static function get_status_label( string $status ): string {
		$labels = self::get_status_labels();
		return $labels[ $status ] ?? $status;
	}

	/**
	 * Get table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		return self::db()->prefix . 'ffc_reregistration_submissions';
	}

	/**
	 * Get a submission by ID.
	 *
	 * @param int $id Submission ID.
	 * @return ReregistrationSubmissionRow|null
	 */
	public static function get_by_id( int $id ): ?object {
		$cached = static::cache_get( "id_{$id}" );
		if ( false !== $cached ) {
			return $cached;
		}

		$wpdb  = self::db();
		$table = self::get_table_name();

		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var ReregistrationSubmissionRow|null $result
		 */
		$result = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id )
		);

		if ( $result ) {
			static::cache_set( "id_{$id}", $result );
		}

		return $result;
	}

	/**
	 * Get a submission by its auth_code.
	 *
	 * @since 4.12.0
	 * @param string $auth_code Cleaned auth code (uppercase, no hyphens).
	 * @return ReregistrationSubmissionRow|null
	 */
	public static function get_by_auth_code( string $auth_code ): ?object {
		if ( empty( $auth_code ) ) {
			return null;
		}

		$wpdb  = self::db();
		$table = self::get_table_name();

		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var ReregistrationSubmissionRow|null $row
		 */
		$row = $wpdb->get_row(
			// 6.7.4 — Include `expired` so a submission that was approved
			// before the campaign closed still surfaces from its auth code.
			// The status flip approved → expired happens for housekeeping
			// when the campaign window ends; the auth code stays valid and
			// the participant must keep the ability to reach the ficha
			// they earned. `rejected` / `pending` / `in_progress` still
			// excluded — those never had a code generated anyway.
			$wpdb->prepare( "SELECT * FROM %i WHERE auth_code = %s AND status IN ('submitted', 'approved', 'expired')", $table, $auth_code )
		);
		return $row;
	}

	/**
	 * Get a submission by its magic_token.
	 *
	 * @since 4.12.0
	 * @param string $token Magic token (64 hex chars).
	 * @return ReregistrationSubmissionRow|null
	 */
	public static function get_by_magic_token( string $token ): ?object {
		if ( empty( $token ) ) {
			return null;
		}

		$wpdb  = self::db();
		$table = self::get_table_name();

		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var ReregistrationSubmissionRow|null $row
		 */
		$row = $wpdb->get_row(
			// 6.7.4 — Same `expired` inclusion as get_by_auth_code() above.
			// Magic links printed on (or emailed about) an approved ficha
			// must keep working after the parent campaign ends.
			$wpdb->prepare( "SELECT * FROM %i WHERE magic_token = %s AND status IN ('submitted', 'approved', 'expired')", $table, $token )
		);
		return $row;
	}

	/**
	 * Get submission for a specific reregistration and user.
	 *
	 * @param int $reregistration_id Reregistration ID.
	 * @param int $user_id           User ID.
	 * @return ReregistrationSubmissionRow|null
	 */
	public static function get_by_reregistration_and_user( int $reregistration_id, int $user_id ): ?object {
		$wpdb  = self::db();
		$table = self::get_table_name();

		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var ReregistrationSubmissionRow|null $row
		 */
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE reregistration_id = %d AND user_id = %d',
				$table,
				$reregistration_id,
				$user_id
			)
		);
		return $row;
	}

	/**
	 * Get all submissions for a user across all reregistrations.
	 *
	 * Joins with reregistrations table to include title and dates.
	 *
	 * @since 4.12.0
	 * @param int $user_id User ID.
	 * @return list<ReregistrationSubmissionRow>
	 */
	public static function get_all_by_user( int $user_id ): array {
		$wpdb        = self::db();
		$table       = self::get_table_name();
		$rereg_table = ReregistrationRepository::get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT s.*, r.title AS reregistration_title, r.start_date, r.end_date, r.status AS reregistration_status
                 FROM %i s
                 INNER JOIN %i r ON s.reregistration_id = r.id
                 WHERE s.user_id = %d
                 ORDER BY r.start_date DESC, s.created_at DESC',
				$table,
				$rereg_table,
				$user_id
			)
		);

		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var list<ReregistrationSubmissionRow>
		 */
		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get submissions for a reregistration with optional filters.
	 *
	 * @param int                  $reregistration_id Reregistration ID.
	 * @param array<string, mixed> $filters { Optional. Query filters.
	 *     @type string $status  Filter by status.
	 *     @type string $search  Search in user display_name or email.
	 *     @type string $orderby Column to order by. Default 'created_at'.
	 *     @type string $order   ASC or DESC. Default 'ASC'.
	 *     @type int    $limit   Max results. Default 0.
	 *     @type int    $offset  Offset. Default 0.
	 * }
	 * @return list<ReregistrationSubmissionRow>
	 */
	public static function get_by_reregistration( int $reregistration_id, array $filters = array() ): array {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$defaults = array(
			'status'  => null,
			'search'  => null,
			'orderby' => 'created_at',
			'order'   => 'ASC',
			'limit'   => 0,
			'offset'  => 0,
		);
		$filters  = wp_parse_args( $filters, $defaults );

		$where  = array( 's.reregistration_id = %d' );
		$values = array( $reregistration_id );

		if ( null !== $filters['status'] ) {
			$where[]  = 's.status = %s';
			$values[] = $filters['status'];
		}

		if ( ! empty( $filters['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[]  = '(u.display_name LIKE %s OR u.user_email LIKE %s)';
			$values[] = $like;
			$values[] = $like;
		}

		$where_clause = 'WHERE ' . implode( ' AND ', $where );

		$allowed_orderby = array( 'created_at', 'submitted_at', 'reviewed_at', 'status' );
		$orderby         = in_array( $filters['orderby'], $allowed_orderby, true ) ? 's.' . $filters['orderby'] : 's.created_at';
		$order           = strtoupper( $filters['order'] ) === 'DESC' ? 'DESC' : 'ASC';
		$limit_clause    = $filters['limit'] > 0 ? sprintf( 'LIMIT %d OFFSET %d', $filters['limit'], $filters['offset'] ) : '';

		$sql = "SELECT s.*, u.display_name AS user_name, u.user_email AS user_email
                FROM %i s
                LEFT JOIN %i u ON s.user_id = u.ID
                {$where_clause}
                ORDER BY {$orderby} {$order}
                {$limit_clause}";

		$prepare_values = array_merge( array( $table, $wpdb->users ), $values );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		/**
		 * Description.
		 *
		 * @phpstan-ignore-next-line argument.type
		 */
		return $wpdb->get_results( $wpdb->prepare( $sql, $prepare_values ) );
	}

	/**
	 * Get statistics for a reregistration.
	 *
	 * @param int $reregistration_id Reregistration ID.
	 * @return array<string, int> Counts keyed by status.
	 */
	public static function get_statistics( int $reregistration_id ): array {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$results_raw = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT status, COUNT(*) as count FROM %i
                WHERE reregistration_id = %d GROUP BY status',
				$table,
				$reregistration_id
			)
		);
		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var list<\stdClass&object{status: string, count: numeric-string}> $results
		 */
		$results = is_array( $results_raw ) ? $results_raw : array();

		$stats = array(
			'total'       => 0,
			'pending'     => 0,
			'in_progress' => 0,
			'submitted'   => 0,
			'approved'    => 0,
			'rejected'    => 0,
			'expired'     => 0,
		);

		foreach ( $results as $row ) {
			$stats[ $row->status ] = (int) $row->count;
			$stats['total']       += (int) $row->count;
		}

		return $stats;
	}

	/**
	 * Get submissions for CSV export.
	 *
	 * @param int                  $reregistration_id Reregistration ID.
	 * @param array<string, mixed> $filters           Optional filters (status, search).
	 * @return list<ReregistrationSubmissionRow>
	 */
	public static function get_for_export( int $reregistration_id, array $filters = array() ): array {
		$filters['limit']  = 0;
		$filters['offset'] = 0;
		return self::get_by_reregistration( $reregistration_id, $filters );
	}

	/**
	 * Stream submissions for CSV export in chunks of $chunk_size rows
	 * to keep memory bounded for large reregistrations. Yields rows
	 * one at a time so the caller can pipe into `Csv::writer->rows()`.
	 *
	 * @since 6.5.0
	 * @param int                  $reregistration_id Reregistration ID.
	 * @param array<string, mixed> $filters           Filters (status, search, orderby, order).
	 * @param int                  $chunk_size        Rows per database round-trip.
	 * @return \Generator<int, ReregistrationSubmissionRow>
	 */
	public static function stream_for_export( int $reregistration_id, array $filters = array(), int $chunk_size = 500 ): \Generator {
		$offset = 0;
		while ( true ) {
			$filters['limit']  = $chunk_size;
			$filters['offset'] = $offset;
			$rows              = self::get_by_reregistration( $reregistration_id, $filters );
			if ( empty( $rows ) ) {
				return;
			}
			foreach ( $rows as $row ) {
				yield $row;
			}
			if ( count( $rows ) < $chunk_size ) {
				return;
			}
			$offset += $chunk_size;
		}
	}

	/**
	 * Count submissions for a reregistration.
	 *
	 * @param int         $reregistration_id Reregistration ID.
	 * @param string|null $status            Optional status filter.
	 * @return int
	 */
	public static function count_by_reregistration( int $reregistration_id, ?string $status = null ): int {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$where  = 'WHERE reregistration_id = %d';
		$values = array( $reregistration_id );

		if ( null !== $status ) {
			$where   .= ' AND status = %s';
			$values[] = $status;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM %i {$where}", array_merge( array( $table ), $values ) )
		);
	}

	/**
	 * Keyset page for the batched CSV export: submissions of one reregistration
	 * with `s.id < $cursor`, newest first (`s.id DESC`), limited to `$size`, with
	 * the user display-name + email joined (like {@see self::get_by_reregistration()}).
	 * Keyset (not LIMIT/OFFSET) so paging stays stable across concurrent inserts
	 * during a long export.
	 *
	 * @since 6.17.0
	 * @param int $reregistration_id Reregistration ID.
	 * @param int $cursor            Exclusive upper-bound id (PHP_INT_MAX on the first page).
	 * @param int $size              Page size.
	 * @return list<ReregistrationSubmissionRow>
	 */
	public static function find_by_cursor_for_export( int $reregistration_id, int $cursor, int $size ): array {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$sql = 'SELECT s.*, u.display_name AS user_name, u.user_email AS user_email
                FROM %i s
                LEFT JOIN %i u ON s.user_id = u.ID
                WHERE s.reregistration_id = %d AND s.id < %d
                ORDER BY s.id DESC
                LIMIT %d';

		$prepare_values = array( $table, $wpdb->users, $reregistration_id, $cursor, $size );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		/**
		 * Prepared with a merged identifier + value list.
		 *
		 * @phpstan-ignore-next-line argument.type
		 */
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $prepare_values ) );
		return is_array( $rows ) ? $rows : array();
	}
}

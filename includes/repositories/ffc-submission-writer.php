<?php
/**
 * Submission Writer
 *
 * Write-side of the submission repository split (#563 backlog, A6). Holds every
 * domain mutation (insert/update overrides with count-cache invalidation, status
 * transitions, bulk ops, cross-form move, delete-by-form, QR-cache clear, edit
 * tracking). Reads live in {@see SubmissionReader}; {@see SubmissionRepository}
 * remains the public façade that delegates to both.
 *
 * Extends AbstractRepository so it reuses the same wpdb binding, table name,
 * cache group and inherited insert/update/clear_cache helpers — the global $wpdb
 * shared across the façade/reader/writer keeps transactions coherent.
 *
 * @package FreeFormCertificate\Repositories
 * @since   6.11.3
 */

declare(strict_types=1);

namespace FreeFormCertificate\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
/**
 * Write operations for submission records.
 */
class SubmissionWriter extends AbstractRepository {

	/**
	 * Transient key for the cached status-count map.
	 *
	 * Kept intentionally short: the count is a handful of integers and the
	 * tabs above the submissions list render it on every admin request, so
	 * even 5 minutes of staleness eliminates a full GROUP BY scan across
	 * potentially hundreds of thousands of rows.
	 */
	private const COUNT_CACHE_KEY = 'ffc_submission_count_by_status';

	/**
	 * Cached column existence checks to avoid repeated INFORMATION_SCHEMA queries
	 *
	 * @since 4.6.13
	 * @var array<string, bool>
	 */
	private static array $column_exists_cache = array();

	/**
	 * Check if a column exists in the submissions table (cached per request)
	 *
	 * @since 4.6.13
	 * @param string $column_name Column name to check.
	 * @return bool
	 */
	private function column_exists( string $column_name ): bool {
		$cache_key = $this->table . '.' . $column_name;
		if ( isset( self::$column_exists_cache[ $cache_key ] ) ) {
			return self::$column_exists_cache[ $cache_key ];
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = (bool) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = %s
                AND TABLE_NAME = %s
                AND COLUMN_NAME = %s',
				DB_NAME,
				$this->table,
				$column_name
			)
		);

		self::$column_exists_cache[ $cache_key ] = $result;
		return $result;
	}

	/**
	 * Get table name.
	 *
	 * @return string
	 */
	protected function get_table_name(): string {
		return SubmissionRepository::get_submissions_table();
	}

	/**
	 * Get cache group.
	 *
	 * @return string
	 */
	protected function get_cache_group(): string {
		return 'ffc_submissions';
	}

	/**
	 * Drop the cached status-count map.
	 *
	 * Called from every write path that can change how many rows fall into
	 * each status (insert, status update, bulk ops, delete).
	 */
	private function invalidate_count_cache(): void {
		\delete_transient( self::COUNT_CACHE_KEY );
	}

	/**
	 * Bulk-clear the `qr_code_cache` column across every submission row
	 * — backs the "Clear QR cache" admin button. Returns the count of
	 * rows that actually held a cached blob (NULL rows are skipped so
	 * the result reflects real invalidations, not the table size).
	 * Issue #340 centralization.
	 *
	 * Flushes the repository's cache group at the end because the bulk
	 * UPDATE bypasses parent::update()'s per-row cache_delete and any
	 * full-row cache entry would otherwise keep returning the stale
	 * `qr_code_cache` blob.
	 *
	 * @since 6.6.2
	 * @return int Number of rows whose cache was dropped.
	 */
	public function clearQrCodeCache(): int {
		$sql = $this->wpdb->prepare( 'UPDATE %i SET qr_code_cache = NULL WHERE qr_code_cache IS NOT NULL', $this->table );
		if ( ! is_string( $sql ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk maintenance write; per-row caches are flushed wholesale via clear_cache() below.
		$rows = $this->wpdb->query( $sql );
		$this->clear_cache();
		return is_int( $rows ) ? $rows : 0;
	}

	/**
	 * Insert a submission row and drop the status-count cache.
	 *
	 * @param array<string, mixed> $data Data.
	 * @return int|false Insert ID on success, false on failure.
	 */
	public function insert( array $data ) {
		$result = parent::insert( $data );
		if ( $result ) {
			$this->invalidate_count_cache();
		}
		return $result;
	}

	/**
	 * Update a submission and drop the status-count cache when status changes.
	 *
	 * @param int                  $id   Record ID.
	 * @param array<string, mixed> $data Data.
	 * @return int|false Rows updated, or false on error.
	 */
	public function update( int $id, array $data ) {
		$result = parent::update( $id, $data );
		if ( $result && array_key_exists( 'status', $data ) ) {
			$this->invalidate_count_cache();
		}
		return $result;
	}

	/**
	 * Update status
	 *
	 * @param int    $id Record ID.
	 * @param string $status Status.
	 * @return int|false
	 */
	public function updateStatus( int $id, string $status ) {
		return $this->update( $id, array( 'status' => $status ) );
	}

	/**
	 * Bulk update status
	 *
	 * @param array<int, int> $ids    Submission IDs.
	 * @param string          $status Status.
	 * @return int|false
	 */
	public function bulkUpdateStatus( array $ids, string $status ) {
		if ( empty( $ids ) ) {
			return 0;
		}

		$safe_ids     = array_map( 'absint', $ids );
		$placeholders = implode( ', ', array_fill( 0, count( $safe_ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders generated via array_fill().
		$query = $this->wpdb->prepare(
			"UPDATE %i SET status = %s WHERE id IN ({$placeholders})",
			$this->table,
			$status,
			...$safe_ids
		);

		if ( ! is_string( $query ) ) {
			return false;
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->query( $query );

		if ( $result ) {
			$this->clear_cache();
			$this->invalidate_count_cache();
		}

		return false === $result ? false : (int) $result;
	}

	/**
	 * Bulk delete
	 *
	 * @param array<int, int> $ids Submission IDs.
	 * @return int|false
	 */
	public function bulkDelete( array $ids ) {
		if ( empty( $ids ) ) {
			return 0;
		}

		$safe_ids     = array_map( 'absint', $ids );
		$placeholders = implode( ', ', array_fill( 0, count( $safe_ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders generated via array_fill().
		$query = $this->wpdb->prepare(
			"DELETE FROM %i WHERE id IN ({$placeholders})",
			$this->table,
			...$safe_ids
		);

		if ( ! is_string( $query ) ) {
			return false;
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->query( $query );

		if ( $result ) {
			$this->clear_cache();
			$this->invalidate_count_cache();
		}

		return false === $result ? false : (int) $result;
	}

	/**
	 * Move submissions to a different form, skipping conflicts.
	 *
	 * A "conflict" is a submission whose identifier (cpf_hash, rf_hash,
	 * email_hash, or non-zero user_id) matches an existing submission already
	 * present in the target form. Conflicts are kept in the original form;
	 * non-conflicts have their `form_id` rewritten in a single bulk UPDATE.
	 *
	 * Source-form filter: rows whose `form_id !== $from_form_id` are silently
	 * skipped (not in `moved`, not in `conflicts`) — this should not happen
	 * via the admin UI since the list table only renders rows for the
	 * filtered form.
	 *
	 * @param int             $from_form_id Source form ID.
	 * @param int             $to_form_id   Target form ID.
	 * @param array<int, int> $ids          Submission IDs.
	 * @return array{moved: list<int>, conflicts: list<int>}
	 */
	public function moveBetweenForms( int $from_form_id, int $to_form_id, array $ids ): array {
		if ( empty( $ids ) || $from_form_id === $to_form_id ) {
			return array(
				'moved'     => array(),
				'conflicts' => array(),
			);
		}

		$safe_ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( empty( $safe_ids ) ) {
			return array(
				'moved'     => array(),
				'conflicts' => array(),
			);
		}

		$placeholders = implode( ', ', array_fill( 0, count( $safe_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders generated via array_fill().
		$select_sql = $this->wpdb->prepare(
			"SELECT id, user_id, email_hash, cpf_hash, rf_hash
			 FROM %i
			 WHERE form_id = %d AND id IN ({$placeholders})",
			array_merge( array( $this->table, $from_form_id ), $safe_ids )
		);
		if ( ! is_string( $select_sql ) ) {
			return array(
				'moved'     => array(),
				'conflicts' => array(),
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results( $select_sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array(
				'moved'     => array(),
				'conflicts' => array(),
			);
		}

		$moved     = array();
		$conflicts = array();
		foreach ( $rows as $row ) {
			if ( $this->hasConflictInForm( $to_form_id, $row ) ) {
				$conflicts[] = (int) $row['id'];
			} else {
				$moved[] = (int) $row['id'];
			}
		}

		if ( ! empty( $moved ) ) {
			$move_placeholders = implode( ', ', array_fill( 0, count( $moved ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders generated via array_fill().
			$update_sql = $this->wpdb->prepare(
				"UPDATE %i SET form_id = %d WHERE id IN ({$move_placeholders})",
				array_merge( array( $this->table, $to_form_id ), $moved )
			);
			if ( is_string( $update_sql ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$this->wpdb->query( $update_sql );
				$this->clear_cache();
				$this->invalidate_count_cache();
			}
		}

		return array(
			'moved'     => $moved,
			'conflicts' => $conflicts,
		);
	}

	/**
	 * Detect whether a submission identifier already exists in a target form.
	 *
	 * Matches on any of cpf_hash / rf_hash / email_hash / user_id (the same
	 * columns covered by the (form_id, hash) indexes), ignoring null/empty
	 * identifiers.
	 *
	 * @param int                  $form_id Target form ID.
	 * @param array<string, mixed> $row     Source row with identifier columns.
	 * @return bool True when at least one row in $form_id matches any populated identifier.
	 */
	private function hasConflictInForm( int $form_id, array $row ): bool {
		$clauses = array();
		$values  = array( $this->table, $form_id );

		if ( ! empty( $row['cpf_hash'] ) ) {
			$clauses[] = 'cpf_hash = %s';
			$values[]  = (string) $row['cpf_hash'];
		}
		if ( ! empty( $row['rf_hash'] ) ) {
			$clauses[] = 'rf_hash = %s';
			$values[]  = (string) $row['rf_hash'];
		}
		if ( ! empty( $row['email_hash'] ) ) {
			$clauses[] = 'email_hash = %s';
			$values[]  = (string) $row['email_hash'];
		}
		$user_id = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;
		if ( $user_id > 0 ) {
			$clauses[] = 'user_id = %d';
			$values[]  = $user_id;
		}

		if ( empty( $clauses ) ) {
			return false;
		}

		$where_clause = implode( ' OR ', $clauses );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Clauses are pre-validated literal column names.
		$sql = $this->wpdb->prepare(
			"SELECT id FROM %i WHERE form_id = %d AND ({$where_clause}) LIMIT 1",
			$values
		);
		if ( ! is_string( $sql ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $this->wpdb->get_var( $sql );
		return null !== $existing;
	}

	/**
	 * Delete by form ID
	 *
	 * @param int $form_id Form ID.
	 * @return int|false
	 */
	public function deleteByFormId( int $form_id ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->delete( $this->table, array( 'form_id' => $form_id ) );

		if ( $result ) {
			$this->clear_cache();
			$this->invalidate_count_cache();
		}

		return $result;
	}

	/**
	 * ✅ NEW v3.0.1: Update submission with edit tracking
	 *
	 * @param int                  $id Submission ID.
	 * @param array<string, mixed> $data Data to update.
	 * @return int|false Number of rows updated or false on error
	 */
	public function updateWithEditTracking( int $id, array $data ) {
		// Check if edited_at column exists (cached per request).
		if ( $this->column_exists( 'edited_at' ) ) {
			// `edited_at` is unix UTC int since 6.6.0 (#249 sub-escopo d).
			$data['edited_at'] = time();

			// Add edited_by if column exists (cached per request).
			if ( $this->column_exists( 'edited_by' ) ) {
				$data['edited_by'] = get_current_user_id();
			}
		}

		return $this->update( $id, $data );
	}
}

<?php
/**
 * Abstract Repository
 * Base class for all repositories
 *
 * @package FreeFormCertificate\Repositories
 * @since 3.0.0
 * @version 4.6.10 - Added transaction support (begin/commit/rollback)
 * @version 3.3.0 - Added strict types and type hints for better code safety
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

declare(strict_types=1);

namespace FreeFormCertificate\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
/**
 * Database repository for abstract records.
 */
abstract class AbstractRepository {

	/**
	 * Wpdb.
	 *
	 * @var \wpdb
	 */
	protected $wpdb;
	/**
	 * Table.
	 *
	 * @var string
	 */
	protected $table;
	/**
	 * Cache group.
	 *
	 * @var string
	 */
	protected $cache_group;
	/**
	 * Cache expiration.
	 *
	 * @var int
	 */
	protected $cache_expiration = 3600;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb        = $wpdb;
		$this->table       = $this->get_table_name();
		$this->cache_group = $this->get_cache_group();
	}

	/**
	 * Get table name.
	 *
	 * @return string
	 */
	abstract protected function get_table_name(): string;
	/**
	 * Get cache group.
	 *
	 * @return string
	 */
	abstract protected function get_cache_group(): string;

	/**
	 * Find by ID
	 *
	 * @param int $id Record ID.
	 * @return array<string, mixed>|null|false
	 */
	public function findById( int $id ) {
		$cache_key = "id_{$id}";
		$cached    = $this->get_cache( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table, $id ),
			ARRAY_A
		);

		if ( $result ) {
			$this->set_cache( $cache_key, $result );
		}

		return $result;
	}

	/**
	 * Find multiple records by IDs in a single query.
	 *
	 * @param array<int, int> $ids Array of integer IDs.
	 * @return array<int, array<string, mixed>> Associative array keyed by ID => row data
	 */
	public function findByIds( array $ids ): array {
		$ids = array_unique( array_filter( array_map( 'intval', $ids ) ) );

		if ( empty( $ids ) ) {
			return array();
		}

		// Check cache first, collect misses.
		$results = array();
		$missing = array();
		foreach ( $ids as $id ) {
			$cached = $this->get_cache( "id_{$id}" );
			if ( false !== $cached ) {
				$results[ $id ] = $cached;
			} else {
				$missing[] = $id;
			}
		}

		// Batch load cache misses.
		if ( ! empty( $missing ) ) {
			$safe_ids     = array_map( 'absint', $missing );
			$placeholders = implode( ',', array_fill( 0, count( $safe_ids ), '%d' ) );
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is %d repeated to match count($safe_ids); Interpolated* is file-disabled above.
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare( "SELECT * FROM %i WHERE id IN ({$placeholders})", $this->table, ...$safe_ids ),
				ARRAY_A
			);
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

			if ( is_array( $rows ) ) {
				foreach ( $rows as $row ) {
					$row_id = (int) $row['id'];
					$this->set_cache( "id_{$row_id}", $row );
					$results[ $row_id ] = $row;
				}
			}
		}

		return $results;
	}

	/**
	 * Find all with conditions
	 *
	 * @param array<string, mixed> $conditions Conditions.
	 * @param string               $order_by   Column to order by.
	 * @param string               $order      Order direction (ASC or DESC).
	 * @param int|null             $limit      Maximum number of results.
	 * @param int                  $offset     Query offset.
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll( array $conditions = array(), string $order_by = 'id', string $order = 'DESC', ?int $limit = null, int $offset = 0 ): array {
		$where    = $this->build_where_clause( $conditions );
		$order_by = $this->sanitize_order_column( $order_by );
		$order    = strtoupper( $order ) === 'ASC' ? 'ASC' : 'DESC';
		if ( $limit ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$results = $this->wpdb->get_results(
				/**
				 * Description.
				 *
				 * @phpstan-ignore-next-line argument.type
				 */
				$this->wpdb->prepare( "SELECT * FROM %i {$where} ORDER BY {$order_by} {$order} LIMIT %d OFFSET %d", $this->table, $limit, $offset ),
				ARRAY_A
			);
			/**
			 * Cast wpdb result to expected shape.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			return is_array( $results ) ? $results : array();
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $this->wpdb->get_results(
			/**
			 * Description.
			 *
			 * @phpstan-ignore-next-line argument.type
			 */
			$this->wpdb->prepare( "SELECT * FROM %i {$where} ORDER BY {$order_by} {$order}", $this->table ),
			ARRAY_A
		);
		/**
		 * Cast wpdb result to expected shape.
		 *
		 * @var array<int, array<string, mixed>>
		 */
		return is_array( $results ) ? $results : array();
	}

	/**
	 * Count rows
	 *
	 * Count.
	 *
	 * Count.
	 *
	 * Count.
	 *
	 * Count.
	 *
	 * Count.
	 *
	 * Count.
	 *
	 * Count.
	 *
	 * Count.
	 *
	 * Count.
	 *
	 * Count.
	 *
	 * @param array<string, mixed> $conditions Conditions.
	 * @return int
	 */
	public function count( array $conditions = array() ): int {
		$where = $this->build_where_clause( $conditions );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		/**
		 * Description.
		 *
		 * @phpstan-ignore-next-line argument.type
		 */
		return (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM %i {$where}", $this->table ) );
	}

	/**
	 * Insert
	 *
	 * Insert.
	 *
	 * Insert.
	 *
	 * Insert.
	 *
	 * Insert.
	 *
	 * Insert.
	 *
	 * @param array<string, mixed> $data Data.
	 * @return int|false Insert ID on success, false on failure
	 */
	public function insert( array $data ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $this->wpdb->insert( $this->table, $data );

		if ( $result ) {
			$this->clear_cache();
			return $this->wpdb->insert_id;
		}

		$this->log_db_error( 'insert' );
		return false;
	}

	/**
	 * Update
	 *
	 * @param int                  $id Record ID.
	 * @param array<string, mixed> $data Data.
	 * @return int|false Number of rows updated, or false on error
	 */
	public function update( int $id, array $data ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->update(
			$this->table,
			$data,
			array( 'id' => $id )
		);

		if ( false !== $result ) {
			$this->clear_cache( "id_{$id}" );
		} else {
			$this->log_db_error( 'update', $id );
		}

		return $result;
	}

	/**
	 * Delete
	 *
	 * @param int $id Record ID.
	 * @return int|false Number of rows deleted, or false on error
	 */
	public function delete( int $id ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->delete( $this->table, array( 'id' => $id ) );

		if ( $result ) {
			$this->clear_cache( "id_{$id}" );
		} elseif ( false === $result ) {
			$this->log_db_error( 'delete', $id );
		}

		return $result;
	}

	/**
	 * Sanitize ORDER BY column name against an allowlist.
	 *
	 * @param string $column Requested column name.
	 * @return string Sanitized column name (defaults to 'id' if not allowed)
	 */
	protected function sanitize_order_column( string $column ): string {
		$allowed = $this->get_allowed_order_columns();
		return in_array( $column, $allowed, true ) ? $column : 'id';
	}

	/**
	 * Get allowed ORDER BY columns. Override in child classes to extend.
	 *
	 * @return array<int, string>
	 */
	protected function get_allowed_order_columns(): array {
		return array( 'id', 'created_at', 'updated_at', 'status' );
	}

	/**
	 * Build WHERE clause
	 *
	 * @param array<string, mixed> $conditions Conditions.
	 * @return string
	 */
	protected function build_where_clause( array $conditions ): string {
		if ( empty( $conditions ) ) {
			return '';
		}

		$allowed_columns = $this->get_allowed_where_columns();

		$where_parts = array();
		foreach ( $conditions as $key => $value ) {
			if ( ! empty( $allowed_columns ) && ! in_array( $key, $allowed_columns, true ) ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $value ), '%s' ) );
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is %s repeated to match count($value).
				$where_parts[] = $this->wpdb->prepare( "%i IN ({$placeholders})", $key, ...$value );
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			} else {
				$where_parts[] = $this->wpdb->prepare( '%i = %s', $key, $value );
			}
		}

		return ! empty( $where_parts ) ? 'WHERE ' . implode( ' AND ', $where_parts ) : '';
	}

	/**
	 * Get allowed WHERE clause columns. Override in child classes.
	 *
	 * @return array<int, string> Empty array means allow all (for backwards compat).
	 */
	protected function get_allowed_where_columns(): array {
		return array();
	}

	/**
	 * Cache methods
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	protected function get_cache( string $key ) {
		return wp_cache_get( $key, $this->cache_group );
	}

	/**
	 * Set cache.
	 *
	 * @param string $key Key.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	protected function set_cache( string $key, $value ): bool {
		return wp_cache_set( $key, $value, $this->cache_group, $this->cache_expiration );
	}

	/**
	 * Clear cache.
	 *
	 * @param string|null $key Key.
	 * @return void
	 */
	protected function clear_cache( ?string $key = null ): void {
		if ( $key ) {
			wp_cache_delete( $key, $this->cache_group );
		} else {
			wp_cache_flush();
		}
	}

	/**
	 * Start a database transaction.
	 *
	 * @since 4.6.10
	 * @return bool
	 */
	public function begin_transaction(): bool {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $this->wpdb->query( 'START TRANSACTION' ) !== false;
	}

	/**
	 * Commit the current transaction.
	 *
	 * @since 4.6.10
	 * @return bool
	 */
	public function commit(): bool {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $this->wpdb->query( 'COMMIT' ) !== false;
	}

	/**
	 * Rollback the current transaction.
	 *
	 * @since 4.6.10
	 * @return bool
	 */
	public function rollback(): bool {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $this->wpdb->query( 'ROLLBACK' ) !== false;
	}

	/**
	 * Log database error from $wpdb->last_error.
	 *
	 * @since 4.6.6
	 * @param string   $operation Operation name (insert, update, delete).
	 * @param int|null $id        Record ID if applicable.
	 */
	protected function log_db_error( string $operation, ?int $id = null ): void {
		if ( empty( $this->wpdb->last_error ) ) {
			return;
		}

		if ( class_exists( '\FreeFormCertificate\Core\Utils' ) ) {
			\FreeFormCertificate\Core\Debug::log_form(
				"Database {$operation} failed",
				array(
					'table' => $this->table,
					'id'    => $id,
					'error' => $this->wpdb->last_error,
				)
			);
		}
	}
}

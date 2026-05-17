<?php
/**
 * Database Helper Trait
 *
 * Shared database schema utilities used across Activator, SelfSchedulingActivator,
 * AudienceActivator and RateLimitActivator.
 *
 * Eliminates duplicated code for:
 * - Table existence checks
 * - Column existence checks (consistent method)
 * - Column migration (add column + optional index)
 * - Index existence checks
 * - Composite index creation
 *
 * @package FreeFormCertificate\Core
 * @since 4.11.2
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait DatabaseHelperTrait {

	/**
	 * Check if a database table exists.
	 *
	 * @param string $table_name Full table name (including prefix).
	 * @return bool
	 */
	protected static function table_exists( string $table_name ): bool {
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;
	}

	/**
	 * Check if a column exists in a table.
	 *
	 * Uses SHOW COLUMNS consistently (avoids INFORMATION_SCHEMA inconsistencies).
	 *
	 * @param string $table_name  Full table name.
	 * @param string $column_name Column name.
	 * @return bool
	 */
	protected static function column_exists( string $table_name, string $column_name ): bool {
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table_name, $column_name ) );
		return ! empty( $result );
	}

	/**
	 * Add a column to a table if it doesn't already exist.
	 *
	 * @param string      $table_name  Full table name.
	 * @param string      $column_name Column name.
	 * @param string      $type        Column type (e.g. 'VARCHAR(255) DEFAULT NULL').
	 * @param string|null $after       Column to place after (optional).
	 * @param string|null $index_name  Index name to create (optional, e.g. 'idx_column').
	 * @return bool True if column was added, false if already existed.
	 */
	protected static function add_column_if_missing(
		string $table_name,
		string $column_name,
		string $type,
		?string $after = null,
		?string $index_name = null
	): bool {
		if ( self::column_exists( $table_name, $column_name ) ) {
			return false;
		}

		global $wpdb;
		$after_sql = $after ? $wpdb->prepare( 'AFTER %i', $after ) : '';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $type is a SQL type definition from trusted internal config.
		$wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN %i {$type} {$after_sql}", $table_name, $column_name ) );

		if ( $index_name ) {
			self::add_index_if_missing( $table_name, $index_name, "({$column_name})" );
		}

		return true;
	}

	/**
	 * Check if an index exists on a table.
	 *
	 * @param string $table_name Full table name.
	 * @param string $index_name Index name (Key_name).
	 * @return bool
	 */
	protected static function index_exists( string $table_name, string $index_name ): bool {
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table_name, $index_name ) );
		return ! empty( $result );
	}

	/**
	 * Add an index to a table if it doesn't already exist.
	 *
	 * @param string $table_name Full table name.
	 * @param string $index_name Index name.
	 * @param string $columns    Column specification (e.g. '(form_id, status)').
	 * @return bool True if index was added, false if already existed.
	 */
	protected static function add_index_if_missing( string $table_name, string $index_name, string $columns ): bool {
		if ( self::index_exists( $table_name, $index_name ) ) {
			return false;
		}

		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $columns is a column specification from trusted internal config.
		$wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD INDEX %i {$columns}", $table_name, $index_name ) );
		return true;
	}

	/**
	 * Add multiple columns to a table from a configuration array.
	 *
	 * Each column config must have a 'type' key, and optionally 'after' and 'index'.
	 *
	 * @param string               $table_name Full table name.
	 * @param array<string, mixed> $columns Columns.
	 * @return int Number of columns added.
	 */
	protected static function add_columns_if_missing( string $table_name, array $columns ): int {
		$added = 0;
		foreach ( $columns as $column_name => $config ) {
			$index_name = isset( $config['index'] ) ? "idx_{$config['index']}" : null;
			if ( self::add_column_if_missing(
				$table_name,
				$column_name,
				$config['type'],
				$config['after'] ?? null,
				$index_name
			) ) {
				++$added;
			}
		}
		return $added;
	}

	/**
	 * Idempotently migrate a DATETIME column to BIGINT UNSIGNED unix UTC
	 * seconds (#249 Category A instants). Used by Sprints a/b/c/d of #249
	 * to keep the per-column migration code DRY.
	 *
	 * Steps (each gated by existence checks so the routine survives a
	 * partial-failure restart):
	 *   1. ADD staging column `<column>_ts BIGINT UNSIGNED` (NOT NULL
	 *      DEFAULT 0 for required columns, DEFAULT NULL otherwise).
	 *   2. PHP backfill: parse the existing DATETIME literal in
	 *      `wp_timezone()` and stash the resulting unix UTC int.
	 *      MySQL's UNIX_TIMESTAMP() respects the session TZ which WP
	 *      doesn't pin, so the PHP path is the only correct one.
	 *   3. DROP every index named in $drop_indexes (composite indexes
	 *      that reference the old DATETIME column must go first).
	 *   4. DROP the old DATETIME column.
	 *   5. RENAME the staging column over it.
	 *   6. Recreate indexes from $recreate_indexes (now against the int
	 *      column). Index name => SQL spec.
	 *
	 * Caller owns the schema-version flag that pins this to one run.
	 *
	 * @param string                $table_name      Full table name.
	 * @param string                $column          Column to migrate.
	 * @param bool                  $nullable        Whether the column allows NULL.
	 *                                               When true the staging column is
	 *                                               also NULL and the backfill filter
	 *                                               uses `IS NOT NULL` instead of `= 0`.
	 * @param array<int, string>    $drop_indexes    Index names to drop before the column rename.
	 * @param array<string, string> $recreate_indexes Index name => `(col, col)` to recreate after rename.
	 * @return void
	 */
	protected static function migrate_datetime_column_to_unix(
		string $table_name,
		string $column,
		bool $nullable = true,
		array $drop_indexes = array(),
		array $recreate_indexes = array()
	): void {
		if ( ! self::table_exists( $table_name ) ) {
			return;
		}

		$has_old      = self::column_exists( $table_name, $column );
		$staging_name = $column . '_ts';
		$has_new      = self::column_exists( $table_name, $staging_name );

		if ( ! $has_new ) {
			if ( ! $has_old ) {
				return; // Fresh schema at 6.6.0+ already has the int column.
			}
			$staging_type = $nullable
				? 'BIGINT UNSIGNED DEFAULT NULL'
				: 'BIGINT UNSIGNED NOT NULL DEFAULT 0';
			self::add_column_if_missing( $table_name, $staging_name, $staging_type, $column );
		}

		if ( $has_old ) {
			global $wpdb;
			$tz               = wp_timezone();
			$where_unmigrated = $nullable
				? "{$column} IS NOT NULL AND {$staging_name} IS NULL"
				: "{$staging_name} = 0";
			do {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where_unmigrated built from trusted internal column names.
				$rows = $wpdb->get_results(
					$wpdb->prepare( "SELECT id, {$column} AS legacy_value FROM %i WHERE {$where_unmigrated} LIMIT 500", $table_name )
				);
				if ( empty( $rows ) ) {
					break;
				}
				foreach ( $rows as $row ) {
					try {
						$dt = new \DateTimeImmutable( (string) $row->legacy_value, $tz );
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$wpdb->update(
							$table_name,
							array( $staging_name => $dt->getTimestamp() ),
							array( 'id' => (int) $row->id ),
							array( '%d' ),
							array( '%d' )
						);
					} catch ( \Exception $e ) {
						unset( $e );
					}
				}
			} while ( count( $rows ) === 500 );

			foreach ( $drop_indexes as $idx ) {
				if ( self::index_exists( $table_name, $idx ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
					$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX %i', $table_name, $idx ) );
				}
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN %i', $table_name, $column ) );
			$rename_type = $nullable ? 'BIGINT UNSIGNED DEFAULT NULL' : 'BIGINT UNSIGNED NOT NULL';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $rename_type is a SQL type definition from trusted internal config.
			$wpdb->query( $wpdb->prepare( "ALTER TABLE %i CHANGE %i %i {$rename_type}", $table_name, $staging_name, $column ) );

			if ( ! empty( $recreate_indexes ) ) {
				self::add_indexes_if_missing( $table_name, $recreate_indexes );
			}
		}
	}

	/**
	 * Add multiple indexes to a table from a configuration array.
	 *
	 * @param string                $table_name Full table name.
	 * @param array<string, string> $indexes   Index name => column specification.
	 * @return int Number of indexes added.
	 */
	protected static function add_indexes_if_missing( string $table_name, array $indexes ): int {
		$added = 0;
		foreach ( $indexes as $index_name => $columns ) {
			if ( self::add_index_if_missing( $table_name, $index_name, $columns ) ) {
				++$added;
			}
		}
		return $added;
	}
}

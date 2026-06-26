<?php
/**
 * Audience Reader
 *
 * Read-side of the audience repository split (#563 backlog, A6). Holds every
 * SELECT / lookup / hierarchy-walk query and the derived read helpers. Writes
 * live in {@see AudienceWriter}. Callers depend on this reader (reads) and the
 * writer (writes) directly; the delegating façade was retired in #563 B3-A.
 *
 * @since   6.11.3
 * @package FreeFormCertificate\Audience
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
/**
 * Read queries for audience records.
 *
 * @since 6.11.3
 *
 * @phpstan-type AudienceRow \stdClass&object{id: numeric-string, name: string, color: string, parent_id: numeric-string|null, status: string, created_by: numeric-string, created_at: string, updated_at: string, allow_self_join?: numeric-string, children?: list<\stdClass>, depth?: int<0, 2>}
 */
class AudienceReader {
	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Cache group for this repository.
	 *
	 * Must match {@see AudienceWriter::cache_group()} so writes invalidate
	 * the entries reads populate.
	 *
	 * @return string
	 */
	protected static function cache_group(): string {
		return 'ffc_audiences';
	}

	/**
	 * Get audiences table name
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		return self::db()->prefix . 'ffc_audiences';
	}

	/**
	 * Get members table name
	 *
	 * @return string
	 */
	public static function get_members_table_name(): string {
		return self::db()->prefix . 'ffc_audience_members';
	}

	/**
	 * List every audience id regardless of status — used by callers
	 * that need to iterate the full set without applying the public
	 * `status='active'` filter that `get_all()` defaults to.
	 *
	 * Issue #340 centralization (extracted from the standard-fields
	 * seeder's `seed_all_existing_audiences()` flow).
	 *
	 * @since 6.6.2
	 * @return list<int>
	 */
	public static function get_all_ids(): array {
		$wpdb  = self::db();
		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Catalog scan; bounded by audience count.
		$ids = $wpdb->get_col(
			$wpdb->prepare( 'SELECT id FROM %i', $table )
		);

		return array_values( array_map( 'intval', $ids ) );
	}

	/**
	 * Audiences (active only) the user is a member of, including only
	 * the columns the public profile chips need: `name`, `color`. Used
	 * by the REST `/me/profile` endpoint — keeping the column list
	 * narrow avoids paying the SELECT * cost when only the badge
	 * surface is rendered.
	 *
	 * Issue #340 centralization.
	 *
	 * @since 6.6.2
	 * @param int $user_id WP user id.
	 * @return list<array{name: string, color: string}>
	 */
	public static function get_user_audience_badges( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		$wpdb          = self::db();
		$table         = self::get_table_name();
		$members_table = self::get_members_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.name, a.color
				FROM %i m
				INNER JOIN %i a ON a.id = m.audience_id
				WHERE m.user_id = %d AND a.status = 'active'
				ORDER BY a.name ASC",
				$members_table,
				$table,
				$user_id
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'name'  => (string) ( $row['name'] ?? '' ),
				'color' => (string) ( $row['color'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Get all audiences
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return list<AudienceRow>
	 */
	public static function get_all( array $args = array() ): array {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$defaults = array(
			'parent_id' => null, // null = all, 0 = only parents, >0 = children of specific parent.
			'status'    => null,
			'orderby'   => 'name',
			'order'     => 'ASC',
			'limit'     => 0,
			'offset'    => 0,
		);
		$args     = wp_parse_args( $args, $defaults );

		$where  = array();
		$values = array();

		if ( null !== $args['parent_id'] ) {
			if ( 0 === $args['parent_id'] ) {
				$where[] = 'parent_id IS NULL';
			} else {
				$where[]  = 'parent_id = %d';
				$values[] = $args['parent_id'];
			}
		}

		if ( $args['status'] ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		$where_clause = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$orderby_sanitized = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] );
		$orderby           = $orderby_sanitized ? $orderby_sanitized : 'name ASC';
		$limit_clause      = $args['limit'] > 0 ? sprintf( 'LIMIT %d OFFSET %d', $args['limit'], $args['offset'] ) : '';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT * FROM %i {$where_clause} ORDER BY {$orderby} {$limit_clause}";

		$prepare_args = array_merge( array( $table ), $values );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		/**
		 * Description.
		 *
		 * @phpstan-ignore-next-line argument.type
		 */
		$sql = $wpdb->prepare( $sql, $prepare_args );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( $sql );
		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var list<AudienceRow>
		 */
		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get audience by ID
	 *
	 * @param int $id Audience ID.
	 * @return AudienceRow|null
	 */
	public static function get_by_id( int $id ): ?object {
		$cached = static::cache_get( "id_{$id}" );
		if ( false !== $cached ) {
			return $cached;
		}

		$wpdb  = self::db();
		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var AudienceRow|null $result
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
	 * Get parent audiences (top-level groups)
	 *
	 * @param string|null $status Optional status filter.
	 * @return list<AudienceRow>
	 */
	public static function get_parents( ?string $status = null ): array {
		return self::get_all(
			array(
				'parent_id' => 0,
				'status'    => $status,
			)
		);
	}

	/**
	 * Get children of a parent audience
	 *
	 * @param int         $parent_id Parent audience ID.
	 * @param string|null $status Optional status filter.
	 * @return list<AudienceRow>
	 */
	public static function get_children( int $parent_id, ?string $status = null ): array {
		return self::get_all(
			array(
				'parent_id' => $parent_id,
				'status'    => $status,
			)
		);
	}

	/**
	 * Get audiences with their children (hierarchical)
	 *
	 * Builds a tree up to 3 levels deep (parent / child / grandchild).
	 *
	 * @param string|null $status Optional status filter.
	 * @return list<AudienceRow> Parents with nested 'children' property
	 */
	public static function get_hierarchical( ?string $status = null ): array {
		$all = self::get_all( array( 'status' => $status ) );

		// Index by id.
		$by_id = array();
		foreach ( $all as $item ) {
			$item->children           = array();
			$by_id[ (int) $item->id ] = $item;
		}

		// Build tree.
		$roots = array();
		foreach ( $by_id as $item ) {
			if ( ! empty( $item->parent_id ) && isset( $by_id[ (int) $item->parent_id ] ) ) {
				$by_id[ (int) $item->parent_id ]->children[] = $item;
			} else {
				$roots[] = $item;
			}
		}

		return $roots;
	}

	/**
	 * Check if a user is a member of an audience
	 *
	 * @param int $audience_id Audience ID.
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_member( int $audience_id, int $user_id ): bool {
		$wpdb  = self::db();
		$table = self::get_members_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE audience_id = %d AND user_id = %d',
				$table,
				$audience_id,
				$user_id
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Get members of an audience
	 *
	 * @param int  $audience_id Audience ID.
	 * @param bool $include_children Whether to include members of child audiences.
	 * @return array<int> User IDs
	 */
	public static function get_members( int $audience_id, bool $include_children = false ): array {
		$wpdb  = self::db();
		$table = self::get_members_table_name();

		$audience_ids = array( $audience_id );

		// Include all descendants if requested.
		if ( $include_children ) {
			$descendant_ids = self::get_descendant_ids( $audience_id );
			$audience_ids   = array_merge( $audience_ids, $descendant_ids );
		}

		$placeholders = implode( ',', array_fill( 0, count( $audience_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Dynamic IN() placeholders built from array_fill above.
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM %i WHERE audience_id IN ({$placeholders})",
				array_merge( array( $table ), $audience_ids )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		return array_map( 'intval', $results );
	}

	/**
	 * Get audiences a user belongs to
	 *
	 * @param int  $user_id User ID.
	 * @param bool $include_parents Whether to include parent audiences (when user is in child).
	 * @return list<AudienceRow>
	 */
	public static function get_user_audiences( int $user_id, bool $include_parents = false ): array {
		$cache_key = 'ffcertificate_user_aud_' . $user_id . '_' . ( $include_parents ? '1' : '0' );
		$cached    = wp_cache_get( $cache_key, 'ffcertificate' );
		if ( is_array( $cached ) ) {
			/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var list<AudienceRow> $cached
		 */
			return $cached;
		}

		$wpdb          = self::db();
		$table         = self::get_table_name();
		$members_table = self::get_members_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$audiences_raw = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT a.* FROM %i a
                INNER JOIN %i m ON a.id = m.audience_id
                WHERE m.user_id = %d AND a.status = \'active\'
                ORDER BY a.name ASC',
				$table,
				$members_table,
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var list<AudienceRow> $audiences
		 */
		$audiences = is_array( $audiences_raw ) ? $audiences_raw : array();

		// Include all ancestor audiences if requested (walks up the full chain).
		if ( $include_parents && ! empty( $audiences ) ) {
			$ancestor_ids = array();
			foreach ( $audiences as $audience ) {
				if ( $audience->parent_id ) {
					$ids          = self::get_ancestor_ids( (int) $audience->parent_id );
					$ancestor_ids = array_merge( $ancestor_ids, $ids );
				}
			}

			if ( ! empty( $ancestor_ids ) ) {
				$ancestor_ids = array_unique( array_map( 'absint', $ancestor_ids ) );
				$placeholders = implode( ',', array_fill( 0, count( $ancestor_ids ), '%d' ) );

				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic IN() placeholders built from array_fill; cached below.
				$parents_raw = $wpdb->get_results(
					$wpdb->prepare( "SELECT * FROM %i WHERE id IN ({$placeholders}) AND status = 'active'", array_merge( array( $table ), $ancestor_ids ) )
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
				/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var list<AudienceRow> $parents
		 */
				$parents = is_array( $parents_raw ) ? $parents_raw : array();

				// Merge and remove duplicates.
				$existing_ids = array_column( $audiences, 'id' );
				foreach ( $parents as $parent ) {
					if ( ! in_array( $parent->id, $existing_ids, true ) ) {
						$audiences[] = $parent;
					}
				}

				// Sort by name.
				usort(
					$audiences,
					function ( $a, $b ) {
						return strcmp( $a->name, $b->name );
					}
				);
			}
		}

		wp_cache_set( $cache_key, $audiences, 'ffcertificate' );

		return $audiences;
	}

	/**
	 * Get member count for an audience
	 *
	 * @param int  $audience_id Audience ID.
	 * @param bool $include_children Whether to include members of child audiences.
	 * @return int
	 */
	public static function get_member_count( int $audience_id, bool $include_children = false ): int {
		return count( self::get_members( $audience_id, $include_children ) );
	}

	/**
	 * Count audiences
	 *
	 * @param array<string, mixed> $args Query arguments (parent_id, status).
	 * @return int
	 */
	public static function count( array $args = array() ): int {
		$args_json = wp_json_encode( $args );
		$cache_key = 'ffcertificate_aud_count_' . md5( $args_json ? $args_json : '' );
		$cached    = wp_cache_get( $cache_key, 'ffcertificate' );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$wpdb  = self::db();
		$table = self::get_table_name();

		$where  = array();
		$values = array();

		if ( isset( $args['parent_id'] ) ) {
			if ( 0 === $args['parent_id'] ) {
				$where[] = 'parent_id IS NULL';
			} else {
				$where[]  = 'parent_id = %d';
				$values[] = $args['parent_id'];
			}
		}

		if ( isset( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		$where_clause = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';

		// Build prepared query with %i for table name.
		$prepare_args = array_merge( array( $table ), $values );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dynamic WHERE clause built from safe %s/%d placeholders; cached below.
		$result = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM %i {$where_clause}", $prepare_args )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		wp_cache_set( $cache_key, $result, 'ffcertificate' );

		return $result;
	}

	/**
	 * Search audiences by name
	 *
	 * @param string $search Search term.
	 * @param int    $limit Max results.
	 * @return list<AudienceRow>
	 */
	public static function search( string $search, int $limit = 10 ): array {
		$cache_key = 'ffcertificate_aud_search_' . md5( $search . '_' . $limit );
		$cached    = wp_cache_get( $cache_key, 'ffcertificate' );
		if ( is_array( $cached ) ) {
			/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var list<AudienceRow> $cached
		 */
			return $cached;
		}

		$wpdb  = self::db();
		$table = self::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results_raw = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i
                WHERE name LIKE %s AND status = 'active'
                ORDER BY name ASC
                LIMIT %d",
				$table,
				'%' . $wpdb->esc_like( $search ) . '%',
				$limit
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var list<AudienceRow> $results
		 */
		$results = is_array( $results_raw ) ? $results_raw : array();

		wp_cache_set( $cache_key, $results, 'ffcertificate' );

		return $results;
	}

	/**
	 * Get all descendant IDs of an audience (children, grandchildren, etc.)
	 *
	 * @param int $audience_id Audience ID.
	 * @return array<int>
	 */
	public static function get_descendant_ids( int $audience_id ): array {
		$children = self::get_children( $audience_id );
		$ids      = array();
		foreach ( $children as $child ) {
			$ids[] = (int) $child->id;
			$ids   = array_merge( $ids, self::get_descendant_ids( (int) $child->id ) );
		}
		return $ids;
	}

	/**
	 * Get ancestor IDs walking up from a given audience ID.
	 *
	 * Returns the ID passed in plus all its parents up to the root.
	 *
	 * @param int $audience_id Starting audience ID.
	 * @return array<int>
	 */
	public static function get_ancestor_ids( int $audience_id ): array {
		$ids      = array( $audience_id );
		$audience = self::get_by_id( $audience_id );
		if ( $audience && ! empty( $audience->parent_id ) ) {
			$ids = array_merge( $ids, self::get_ancestor_ids( (int) $audience->parent_id ) );
		}
		return $ids;
	}

	/**
	 * Get audiences that may serve as parents (3-level hierarchy).
	 *
	 * Returns root audiences and their direct children (depth 0 and 1).
	 * Audiences at depth 2 cannot be parents because that would create a 4th level.
	 * Optionally excludes an audience and its descendants to prevent circular refs.
	 *
	 * @param int $exclude_id Audience ID to exclude (along with descendants).
	 * @return list<AudienceRow> Flat list with a 'depth' property on each item
	 */
	public static function get_possible_parents( int $exclude_id = 0 ): array {
		$exclude_ids = array();
		if ( $exclude_id > 0 ) {
			$exclude_ids[] = $exclude_id;
			$exclude_ids   = array_merge( $exclude_ids, self::get_descendant_ids( $exclude_id ) );
		}

		$parents = self::get_parents(); // depth 0.
		$result  = array();

		foreach ( $parents as $parent ) {
			if ( in_array( (int) $parent->id, $exclude_ids, true ) ) {
				continue;
			}
			$parent->depth = 0;
			$result[]      = $parent;

			// depth-1 children.
			$children = self::get_children( (int) $parent->id );
			foreach ( $children as $child ) {
				if ( in_array( (int) $child->id, $exclude_ids, true ) ) {
					continue;
				}
				$child->depth = 1;
				$result[]     = $child;
			}
		}

		return $result;
	}

	/**
	 * Get the full ancestor chain for an audience (for breadcrumb display).
	 *
	 * Returns ordered array from root to the immediate parent.
	 *
	 * @param int $audience_id Audience ID.
	 * @return list<AudienceRow>
	 */
	public static function get_ancestors( int $audience_id ): array {
		$ancestors = array();
		$current   = self::get_by_id( $audience_id );

		while ( $current && ! empty( $current->parent_id ) ) {
			$parent = self::get_by_id( (int) $current->parent_id );
			if ( ! $parent ) {
				break;
			}
			array_unshift( $ancestors, $parent );
			$current = $parent;
		}

		return $ancestors;
	}
}

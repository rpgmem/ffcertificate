<?php
/**
 * URL Shortener Reader
 *
 * Read-side of the URL shortener repository split (#591 phase-3, Sprint D2).
 * Holds every domain SELECT / lookup / aggregate query for the ffc_short_urls
 * table. Writes live in {@see UrlShortenerWriter}; {@see UrlShortenerRepository}
 * remains the public façade that delegates to both and still exposes the generic
 * CRUD inherited from {@see \FreeFormCertificate\Repositories\AbstractRepository}.
 *
 * Extends AbstractRepository so it reuses the same wpdb binding, table name and
 * cache group as before — the global $wpdb shared across the façade/reader/writer
 * keeps caching and queries coherent.
 *
 * @package FreeFormCertificate\UrlShortener
 * @since 6.11.3
 */

declare(strict_types=1);

namespace FreeFormCertificate\UrlShortener;

use FreeFormCertificate\Repositories\AbstractRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
/**
 * Read queries for url shortener records.
 */
class UrlShortenerReader extends AbstractRepository {

	/**
	 * Get table name.
	 *
	 * @return string
	 */
	protected function get_table_name(): string {
		return $this->wpdb->prefix . 'ffc_short_urls';
	}

	/**
	 * Get cache group.
	 *
	 * @return string
	 */
	protected function get_cache_group(): string {
		return 'ffc_short_urls';
	}

	/**
	 * Find a short URL record by its short code.
	 *
	 * @param string $code The short code (e.g. "abc123").
	 * @return array<string, mixed>|null
	 */
	public function findByShortCode( string $code ): ?array {
		$cache_key = 'code_' . $code;
		$cached    = $this->get_cache( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE short_code = %s', $this->table, $code ),
			ARRAY_A
		);

		if ( $result ) {
			$this->set_cache( $cache_key, $result );
		}

		return $result;
	}

	/**
	 * Find a short URL record by post ID.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return array<string, mixed>|null
	 */
	public function findByPostId( int $post_id ): ?array {
		$cache_key = 'post_' . $post_id;
		$cached    = $this->get_cache( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE post_id = %d AND status = %s ORDER BY id DESC LIMIT 1',
				$this->table,
				$post_id,
				'active'
			),
			ARRAY_A
		);

		if ( $result ) {
			$this->set_cache( $cache_key, $result );
		}

		return $result;
	}

	/**
	 * Check if a short code already exists.
	 *
	 * @param string $code Short code to check.
	 * @return bool
	 */
	public function codeExists( string $code ): bool {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE short_code = %s',
				$this->table,
				$code
			)
		);

		return $count > 0;
	}

	/**
	 * Find paginated short URLs for admin listing.
	 *
	 * @param array<string, mixed> $args {.
	 *     @type int    $per_page  Items per page (default 20).
	 *     @type int    $page      Current page (default 1).
	 *     @type string $orderby   Column to sort by (default 'created_at').
	 *     @type string $order     ASC or DESC (default 'DESC').
	 *     @type string $search    Search term for title/target_url.
	 *     @type string $status    Filter by status (default 'all').
	 * }
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function findPaginated( array $args = array() ): array {
		$defaults = array(
			'per_page' => 20,
			'page'     => 1,
			'orderby'  => 'created_at',
			'order'    => 'DESC',
			'search'   => '',
			'status'   => 'all',
		);
		$args     = wp_parse_args( $args, $defaults );

		// Inline WHERE build kept here (rather than build_export_where) so the
		// query stays a literal-string for the wpdb::prepare() type-check; the
		// helper is used by the export methods, which suppress the check.
		$where_clauses = array();
		$where_values  = array();

		if ( 'all' === $args['status'] ) {
			// "All" excludes trashed items (like WordPress core).
			$where_clauses[] = 'status != %s';
			$where_values[]  = 'trashed';
		} else {
			$where_clauses[] = 'status = %s';
			$where_values[]  = $args['status'];
		}

		if ( ! empty( $args['search'] ) ) {
			$like            = '%' . $this->wpdb->esc_like( $args['search'] ) . '%';
			$where_clauses[] = '(title LIKE %s OR target_url LIKE %s OR short_code LIKE %s)';
			$where_values[]  = $like;
			$where_values[]  = $like;
			$where_values[]  = $like;
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where_clauses );

		$allowed_columns = array( 'id', 'title', 'short_code', 'click_count', 'created_at', 'status' );
		$orderby         = in_array( $args['orderby'], $allowed_columns, true ) ? $args['orderby'] : 'created_at';
		$order           = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

		$offset   = ( max( 1, (int) $args['page'] ) - 1 ) * (int) $args['per_page'];
		$per_page = (int) $args['per_page'];

		// Build count query.
		$count_query = "SELECT COUNT(*) FROM %i {$where_sql}";
		$count_args  = array_merge( array( $this->table ), $where_values );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $this->wpdb->get_var(
			$this->wpdb->prepare( $count_query, ...$count_args ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		// Build items query.
		$items_query = "SELECT * FROM %i {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$items_args  = array_merge( array( $this->table ), $where_values, array( $per_page, $offset ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$items = $this->wpdb->get_results(
			$this->wpdb->prepare( $items_query, ...$items_args ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Build the shared WHERE clause (status + search) used by the admin list
	 * and the CSV export. `status = 'all'` excludes trashed rows.
	 *
	 * @param array<string, mixed> $filters {.
	 *     @type string $status Status filter ('all' excludes trashed).
	 *     @type string $search Search term (title / target_url / short_code).
	 * }
	 * @return array<int, mixed> `[ $where_sql, $where_values ]`.
	 * @phpstan-return array{0: string, 1: array<int, string>}
	 */
	private function build_export_where( array $filters ): array {
		$status = isset( $filters['status'] ) ? (string) $filters['status'] : 'all';
		$search = isset( $filters['search'] ) ? (string) $filters['search'] : '';

		$where_clauses = array();
		$where_values  = array();

		if ( 'all' === $status ) {
			// "All" excludes trashed items (like WordPress core).
			$where_clauses[] = 'status != %s';
			$where_values[]  = 'trashed';
		} else {
			$where_clauses[] = 'status = %s';
			$where_values[]  = $status;
		}

		if ( '' !== $search ) {
			$like            = '%' . $this->wpdb->esc_like( $search ) . '%';
			$where_clauses[] = '(title LIKE %s OR target_url LIKE %s OR short_code LIKE %s)';
			$where_values[]  = $like;
			$where_values[]  = $like;
			$where_values[]  = $like;
		}

		return array( 'WHERE ' . implode( ' AND ', $where_clauses ), $where_values );
	}

	/**
	 * Count rows matching the export filters (status + search).
	 *
	 * @param array<string, mixed> $filters Status + search.
	 * @return int
	 */
	public function countForExport( array $filters ): int {
		list( $where_sql, $where_values ) = $this->build_export_where( $filters );

		$query = "SELECT COUNT(*) FROM %i {$where_sql}";
		$args  = array_merge( array( $this->table ), $where_values );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		/**
		 * The interpolated part is a literal WHERE from build_export_where().
		 *
		 * @phpstan-ignore-next-line argument.type
		 */
		$prepared = $this->wpdb->prepare( $query, ...$args );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->wpdb->get_var( $prepared );
	}

	/**
	 * Keyset page for the batched CSV export: rows with `id < $cursor`, newest
	 * first (`id DESC`), limited to `$size`. Keyset (not LIMIT/OFFSET) so the
	 * paging stays stable across concurrent inserts/deletes during a long export.
	 *
	 * @param array<string, mixed> $filters Status + search.
	 * @param int                  $cursor  Exclusive upper-bound id (PHP_INT_MAX on the first page).
	 * @param int                  $size    Page size.
	 * @return array<int, array<string, mixed>>
	 */
	public function findByCursor( array $filters, int $cursor, int $size ): array {
		list( $where_sql, $where_values ) = $this->build_export_where( $filters );

		$query = "SELECT * FROM %i {$where_sql} AND id < %d ORDER BY id DESC LIMIT %d";
		$args  = array_merge( array( $this->table ), $where_values, array( $cursor, $size ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		/**
		 * The interpolated part is a literal WHERE from build_export_where().
		 *
		 * @phpstan-ignore-next-line argument.type
		 */
		$prepared = $this->wpdb->prepare( $query, ...$args );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results( $prepared, ARRAY_A );

		return $rows ? $rows : array();
	}

	/**
	 * Find short URLs that are candidates for cleanup under the enabled criteria.
	 *
	 * Three independent criteria, OR-combined:
	 *   - `orphaned`:      `post_id` is set but the referenced post no longer exists.
	 *   - `never_clicked`: `click_count = 0` and the row was created more than
	 *                      `$never_clicked_days` ago.
	 *   - `trashed`:       `status = 'trashed'`.
	 *
	 * Each returned row carries `is_orphaned`, `is_never_clicked` and `is_trashed`
	 * flags (computed for every row regardless of which criteria were enabled) so
	 * the caller can label exactly why a row matched. Read-only — never mutates.
	 *
	 * @param array{orphaned?:bool, never_clicked?:bool, trashed?:bool} $criteria Enabled criteria.
	 * @param int                                                       $never_clicked_days Grace window (days) for the never_clicked criterion.
	 * @return array<int, array<string, mixed>> Matching rows (empty when no criteria enabled).
	 */
	public function find_cleanup_candidates( array $criteria, int $never_clicked_days ): array {
		$days = max( 0, $never_clicked_days );

		$orphan_expr  = '( s.post_id IS NOT NULL AND p.ID IS NULL )';
		$nevclk_expr  = '( s.click_count = 0 AND s.created_at < ( NOW() - INTERVAL %d DAY ) )';
		$trashed_expr = "( s.status = 'trashed' )";

		$where = array();
		if ( ! empty( $criteria['orphaned'] ) ) {
			$where[] = $orphan_expr;
		}
		if ( ! empty( $criteria['never_clicked'] ) ) {
			$where[] = $nevclk_expr;
		}
		if ( ! empty( $criteria['trashed'] ) ) {
			$where[] = $trashed_expr;
		}
		if ( empty( $where ) ) {
			return array();
		}

		// Placeholders appear in this order in the string: SELECT %d (is_never_clicked),
		// FROM %i (short_urls), JOIN %i (posts), then WHERE %d only if never_clicked enabled.
		$sql = "SELECT s.id, s.short_code, s.target_url, s.post_id, s.title, s.click_count, s.status, s.created_at,
				{$orphan_expr} AS is_orphaned,
				{$nevclk_expr} AS is_never_clicked,
				{$trashed_expr} AS is_trashed
			FROM %i s
			LEFT JOIN %i p ON p.ID = s.post_id
			WHERE " . implode( ' OR ', $where ) . '
			ORDER BY s.id ASC';

		$args = array( $days, $this->table, $this->wpdb->posts );
		if ( ! empty( $criteria['never_clicked'] ) ) {
			$args[] = $days;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( $sql, ...$args ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get aggregate statistics.
	 *
	 * @return array{total_links: int, active_links: int, total_clicks: int, trashed_links: int}
	 */
	public function getStats(): array {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT
                    SUM(CASE WHEN status != 'trashed' THEN 1 ELSE 0 END) AS total_links,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_links,
                    COALESCE(SUM(CASE WHEN status != 'trashed' THEN click_count ELSE 0 END), 0) AS total_clicks,
                    SUM(CASE WHEN status = 'trashed' THEN 1 ELSE 0 END) AS trashed_links
                FROM %i",
				$this->table
			),
			ARRAY_A
		);

		return array(
			'total_links'   => (int) ( $row['total_links'] ?? 0 ),
			'active_links'  => (int) ( $row['active_links'] ?? 0 ),
			'total_clicks'  => (int) ( $row['total_clicks'] ?? 0 ),
			'trashed_links' => (int) ( $row['trashed_links'] ?? 0 ),
		);
	}

	/**
	 * Read the cached QR PNG payload for a short code. Issue #340
	 * centralization (replaces a raw SELECT in
	 * `UrlShortenerQrHandler::get_qr_cache`).
	 *
	 * @since 6.6.2
	 * @param string $short_code Short URL code.
	 * @return string Base64-encoded PNG, or empty string when no
	 *                cache row exists / column is NULL.
	 */
	public function findQrCacheByShortCode( string $short_code ): string {
		if ( '' === $short_code ) {
			return '';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Narrow single-column lookup.
		$value = $this->wpdb->get_var(
			$this->wpdb->prepare( 'SELECT qr_cache FROM %i WHERE short_code = %s', $this->table, $short_code )
		);
		return is_string( $value ) && '' !== $value ? $value : '';
	}
}

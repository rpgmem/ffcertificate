<?php
declare(strict_types=1);

/**
 * URL Shortener Repository
 *
 * Data access layer for ffc_short_urls table.
 *
 * @since 5.1.0
 * @package FreeFormCertificate\UrlShortener
 */

namespace FreeFormCertificate\UrlShortener;

use FreeFormCertificate\Repositories\AbstractRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UrlShortenerRepository extends AbstractRepository {

    /**
     * @return string
     */
    protected function get_table_name(): string {
        return $this->wpdb->prefix . 'ffc_short_urls';
    }

    /**
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

        if ( $cached !== false ) {
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

        if ( $cached !== false ) {
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
     * Increment the click counter for a short URL.
     *
     * @param int $id Record ID.
     * @return bool
     */
    public function incrementClickCount( int $id ): bool {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $this->wpdb->query(
            $this->wpdb->prepare(
                'UPDATE %i SET click_count = click_count + 1 WHERE id = %d',
                $this->table,
                $id
            )
        );

        if ( $result !== false ) {
            $this->clear_cache();
        }

        return $result !== false;
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
     * @param array<string, mixed> $args {
     *     @type int    $per_page  Items per page (default 20).
     *     @type int    $page      Current page (default 1).
     *     @type string $orderby   Column to sort by (default 'created_at').
     *     @type string $order     ASC or DESC (default 'DESC').
     *     @type string $search    Search term for title/target_url.
     *     @type string $status    Filter by status (default 'all').
     * }
     * @return array{items: array, total: int}
     */
    public function findPaginated( array $args = [] ): array {
        $defaults = [
            'per_page' => 20,
            'page'     => 1,
            'orderby'  => 'created_at',
            'order'    => 'DESC',
            'search'   => '',
            'status'   => 'all',
        ];
        $args = wp_parse_args( $args, $defaults );

        $where_clauses = [];
        $where_values  = [];

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

        $where_sql = '';
        if ( ! empty( $where_clauses ) ) {
            $where_sql = 'WHERE ' . implode( ' AND ', $where_clauses );
        }

        $allowed_columns = [ 'id', 'title', 'short_code', 'click_count', 'created_at', 'status' ];
        $orderby         = in_array( $args['orderby'], $allowed_columns, true ) ? $args['orderby'] : 'created_at';
        $order           = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        $offset   = ( max( 1, (int) $args['page'] ) - 1 ) * (int) $args['per_page'];
        $per_page = (int) $args['per_page'];

        // Build count query
        $count_query = "SELECT COUNT(*) FROM %i {$where_sql}";
        $count_args  = array_merge( [ $this->table ], $where_values );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total = (int) $this->wpdb->get_var(
            $this->wpdb->prepare( $count_query, ...$count_args ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        );

        // Build items query
        $items_query = "SELECT * FROM %i {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $items_args  = array_merge( [ $this->table ], $where_values, [ $per_page, $offset ] );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $items = $this->wpdb->get_results(
            $this->wpdb->prepare( $items_query, ...$items_args ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ARRAY_A
        );

        return [
            'items' => $items ?: [],
            'total' => $total,
        ];
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

        return [
            'total_links'   => (int) ( $row['total_links'] ?? 0 ),
            'active_links'  => (int) ( $row['active_links'] ?? 0 ),
            'total_clicks'  => (int) ( $row['total_clicks'] ?? 0 ),
            'trashed_links' => (int) ( $row['trashed_links'] ?? 0 ),
        ];
    }
}

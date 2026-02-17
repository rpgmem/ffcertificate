<?php
declare(strict_types=1);

/**
 * ActivityLogQuery
 *
 * Handles querying, statistics, and cleanup for the activity log.
 * Extracted from ActivityLog (v4.12.2) for single-responsibility.
 *
 * @since 4.12.2
 * @package FreeFormCertificate\Core
 */

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

class ActivityLogQuery {

    /**
     * Get recent activities with filters
     *
     * @param array $args Query arguments
     * @return array Activities
     */
    public static function get_activities( array $args = array() ): array {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_activity_log';

        $defaults = array(
            'limit'     => 50,
            'offset'    => 0,
            'level'     => null,
            'action'    => null,
            'user_id'   => null,
            'user_ip'   => null,
            'date_from' => null,
            'date_to'   => null,
            'search'    => null,
            'orderby'   => 'created_at',
            'order'     => 'DESC',
        );

        $args = wp_parse_args( $args, $defaults );

        $where = array( '1=1' );

        if ( $args['level'] ) {
            $where[] = $wpdb->prepare( 'level = %s', sanitize_key( $args['level'] ) );
        }
        if ( $args['action'] ) {
            $where[] = $wpdb->prepare( 'action = %s', sanitize_text_field( $args['action'] ) );
        }
        if ( $args['user_id'] ) {
            $where[] = $wpdb->prepare( 'user_id = %d', absint( $args['user_id'] ) );
        }
        if ( $args['user_ip'] ) {
            $where[] = $wpdb->prepare( 'user_ip = %s', sanitize_text_field( $args['user_ip'] ) );
        }
        if ( $args['date_from'] ) {
            $where[] = $wpdb->prepare( 'created_at >= %s', sanitize_text_field( $args['date_from'] ) );
        }
        if ( $args['date_to'] ) {
            $where[] = $wpdb->prepare( 'created_at <= %s', sanitize_text_field( $args['date_to'] ) );
        }
        if ( $args['search'] ) {
            $search  = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
            $where[] = $wpdb->prepare( '(action LIKE %s OR context LIKE %s)', $search, $search );
        }

        $where_clause = implode( ' AND ', $where );

        $allowed_orderby = array( 'id', 'action', 'level', 'user_id', 'user_ip', 'created_at' );
        $orderby = in_array( $args['orderby'], $allowed_orderby ) ? $args['orderby'] : 'created_at';
        $order   = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
        $offset  = absint( $args['offset'] );
        $limit   = absint( $args['limit'] );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE {$where_clause} ORDER BY {$orderby} {$order} LIMIT %d, %d",
                $table_name,
                $offset,
                $limit
            ),
            ARRAY_A
        );

        foreach ( $results as &$result ) {
            $result['context'] = json_decode( $result['context'], true );
            if ( ! is_array( $result['context'] ) ) {
                $result['context'] = array();
            }
        }

        return $results;
    }

    /**
     * Get activity count with filters
     *
     * @param array $args Same as get_activities()
     * @return int Count
     */
    public static function count_activities( array $args = array() ): int {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_activity_log';

        $defaults = array(
            'level'     => null,
            'action'    => null,
            'user_id'   => null,
            'user_ip'   => null,
            'date_from' => null,
            'date_to'   => null,
            'search'    => null,
        );

        $args = wp_parse_args( $args, $defaults );

        $where = array( '1=1' );

        if ( $args['level'] ) {
            $where[] = $wpdb->prepare( 'level = %s', sanitize_key( $args['level'] ) );
        }
        if ( $args['action'] ) {
            $where[] = $wpdb->prepare( 'action = %s', sanitize_text_field( $args['action'] ) );
        }
        if ( $args['user_id'] ) {
            $where[] = $wpdb->prepare( 'user_id = %d', absint( $args['user_id'] ) );
        }
        if ( $args['user_ip'] ) {
            $where[] = $wpdb->prepare( 'user_ip = %s', sanitize_text_field( $args['user_ip'] ) );
        }
        if ( $args['date_from'] ) {
            $where[] = $wpdb->prepare( 'created_at >= %s', sanitize_text_field( $args['date_from'] ) );
        }
        if ( $args['date_to'] ) {
            $where[] = $wpdb->prepare( 'created_at <= %s', sanitize_text_field( $args['date_to'] ) );
        }
        if ( $args['search'] ) {
            $search  = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
            $where[] = $wpdb->prepare( '(action LIKE %s OR context LIKE %s)', $search, $search );
        }

        $where_clause = implode( ' AND ', $where );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM %i WHERE {$where_clause}",
                $table_name
            )
        );
    }

    /**
     * Get statistics
     *
     * @param int $days Number of days to analyze (default: 30)
     * @return array Statistics
     */
    public static function get_stats( int $days = 30 ): array {
        $cache_key = 'ffc_activity_stats_' . $days;
        $cached    = get_transient( $cache_key );
        if ( $cached !== false ) {
            return $cached;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_activity_log';
        $date_from  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total = $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE created_at >= %s',
            $table_name,
            $date_from
        ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $by_level = $wpdb->get_results( $wpdb->prepare(
            'SELECT level, COUNT(*) as count FROM %i WHERE created_at >= %s GROUP BY level',
            $table_name,
            $date_from
        ), ARRAY_A );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $top_actions = $wpdb->get_results( $wpdb->prepare(
            'SELECT action, COUNT(*) as count FROM %i WHERE created_at >= %s GROUP BY action ORDER BY count DESC LIMIT 10',
            $table_name,
            $date_from
        ), ARRAY_A );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $top_users = $wpdb->get_results( $wpdb->prepare(
            'SELECT user_id, COUNT(*) as count FROM %i WHERE created_at >= %s AND user_id > 0 GROUP BY user_id ORDER BY count DESC LIMIT 10',
            $table_name,
            $date_from
        ), ARRAY_A );

        $stats = array(
            'total'       => (int) $total,
            'by_level'    => $by_level,
            'top_actions' => $top_actions,
            'top_users'   => $top_users,
            'period_days' => $days,
        );

        set_transient( $cache_key, $stats, HOUR_IN_SECONDS );

        return $stats;
    }

    /**
     * Get logs for specific submission (LGPD audit trail)
     *
     * @param int $submission_id Submission ID
     * @param int $limit         Maximum number of logs
     * @return array Logs
     */
    public static function get_submission_logs( int $submission_id, int $limit = 100 ): array {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_activity_log';

        $columns = ActivityLog::get_table_columns_cached( $table_name );
        if ( ! in_array( 'submission_id', $columns ) ) {
            return array();
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $logs = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE submission_id = %d ORDER BY created_at DESC LIMIT %d',
                $table_name,
                $submission_id,
                $limit
            ),
            ARRAY_A
        );

        if ( class_exists( '\\FreeFormCertificate\\Core\\Encryption' ) && \FreeFormCertificate\Core\Encryption::is_configured() ) {
            foreach ( $logs as &$log ) {
                if ( ! empty( $log['context_encrypted'] ) ) {
                    $decrypted = \FreeFormCertificate\Core\Encryption::decrypt( $log['context_encrypted'] );
                    if ( $decrypted !== null ) {
                        $log['context_decrypted'] = $decrypted;
                    }
                }
            }
        }

        return $logs;
    }

    /**
     * Clean old logs
     *
     * @param int $days Keep logs from last N days (default: 90)
     * @return int Number of deleted rows
     */
    public static function cleanup( int $days = 90 ): int {
        global $wpdb;
        $table_name  = $wpdb->prefix . 'ffc_activity_log';
        $cutoff_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $deleted = $wpdb->query( $wpdb->prepare(
            'DELETE FROM %i WHERE created_at < %s',
            $table_name,
            $cutoff_date
        ) );

        delete_transient( 'ffc_activity_stats_7' );
        delete_transient( 'ffc_activity_stats_30' );
        delete_transient( 'ffc_activity_stats_90' );

        return (int) $deleted;
    }

    /**
     * Run automatic log cleanup (called by daily cron)
     *
     * @since 4.6.9
     * @return int Number of deleted rows
     */
    public static function run_cleanup(): int {
        $settings       = get_option( 'ffc_settings', array() );
        $retention_days = isset( $settings['activity_log_retention_days'] )
            ? absint( $settings['activity_log_retention_days'] )
            : 90;

        if ( $retention_days <= 0 ) {
            return 0;
        }

        return self::cleanup( $retention_days );
    }
}

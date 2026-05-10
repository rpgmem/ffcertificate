<?php
/**
 * RateLimitStats
 *
 * Aggregate read-only statistics for the rate-limit subsystem. Extracted
 * from {@see RateLimiter} as part of the S4 god-object refactor — pure
 * code movement, no behavior changes.
 *
 * @package FreeFormCertificate\Security
 */

declare(strict_types=1);

namespace FreeFormCertificate\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rate Limit Stats.
 */
final class RateLimitStats {

	/**
	 * Get stats.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_stats(): array {
		global $wpdb;
		$lt = $wpdb->prefix . 'ffc_rate_limit_logs';
		return array(
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			'today'   => $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE action='blocked' AND DATE(created_at)=CURDATE()", $lt ) ),
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			'month'   => $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE action='blocked' AND created_at>=DATE_SUB(NOW(), INTERVAL 30 DAY)", $lt ) ),
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			'by_type' => $wpdb->get_results( $wpdb->prepare( "SELECT type,COUNT(*) as count FROM %i WHERE action='blocked' AND created_at>=DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY type", $lt ), ARRAY_A ),
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			'top_ips' => $wpdb->get_results( $wpdb->prepare( "SELECT identifier,COUNT(*) as count FROM %i WHERE type='ip' AND action='blocked' AND created_at>=DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY identifier ORDER BY count DESC LIMIT 10", $lt ), ARRAY_A ),
		);
	}
}

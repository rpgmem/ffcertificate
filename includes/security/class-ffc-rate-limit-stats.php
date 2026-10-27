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
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dashboard counters over the plugin's own rate-limit log; the numbers exist to be current, and WordPress has no API for that table.
			'today'   => $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE action='blocked' AND DATE(created_at)=CURDATE()", $lt ) ),
			'month'   => $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE action='blocked' AND created_at>=DATE_SUB(NOW(), INTERVAL 30 DAY)", $lt ) ),
			'by_type' => $wpdb->get_results( $wpdb->prepare( "SELECT type,COUNT(*) as count FROM %i WHERE action='blocked' AND created_at>=DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY type", $lt ), ARRAY_A ),
			'top_ips' => self::top_ips( (array) $wpdb->get_results( $wpdb->prepare( "SELECT identifier,COUNT(*) as count,MAX(ip_address_encrypted) as ip_encrypted FROM %i WHERE type='ip' AND action='blocked' AND created_at>=DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY identifier ORDER BY count DESC LIMIT 10", $lt ), ARRAY_A ) ),
		);
	}

	/**
	 * The most blocked callers, each with a masked address to show (#2109).
	 *
	 * A row groups by fingerprint, so the address shown is one the caller
	 * used, decrypted and masked. A row written before addresses were
	 * fingerprinted still carries the address as its identifier and is
	 * masked the same way; a row with neither shows the start of its
	 * fingerprint, which tells callers apart without naming them.
	 *
	 * @param array<int, mixed> $rows Grouped rows.
	 * @return array<int, array{identifier: string, count: int, label: string}>
	 */
	private static function top_ips( array $rows ): array {
		$top = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$identifier = (string) ( $row['identifier'] ?? '' );
			$ciphertext = (string) ( $row['ip_encrypted'] ?? '' );
			$address    = '' !== $ciphertext ? (string) \FreeFormCertificate\Core\Encryption::decrypt( $ciphertext ) : '';
			if ( '' === $address && null !== \FreeFormCertificate\Core\IpAddress::canonical( $identifier ) ) {
				$address = $identifier;
			}

			$top[] = array(
				'identifier' => $identifier,
				'count'      => (int) ( $row['count'] ?? 0 ),
				'label'      => '' !== $address
					? \FreeFormCertificate\Core\DocumentFormatter::mask_ip( $address )
					: substr( $identifier, 0, 12 ),
			);
		}

		return $top;
	}
}

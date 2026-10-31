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
			'top_ips' => self::top_ips( (array) $wpdb->get_results( $wpdb->prepare( "SELECT identifier,COUNT(*) as count,MAX(ip_address_encrypted) as ip_encrypted,MAX(id) as log_id FROM %i WHERE type='ip' AND action='blocked' AND created_at>=DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY identifier ORDER BY count DESC LIMIT 10", $lt ), ARRAY_A ) ),
		);
	}

	/**
	 * The request address of one log row, decrypted, for an audited Reveal
	 * (#2109). The Rate Limit tab shows it masked; this is the only path to
	 * the clear value. A row written before addresses were encrypted still
	 * carries the address in clear, as its `ip_address` or as the identifier
	 * of an `ip` row, until the migration card runs.
	 *
	 * @param int $id Log row ID.
	 * @return string The address, or '' when the row or its address is missing.
	 */
	public static function address_of( int $id ): string {
		global $wpdb;
		if ( $id <= 0 ) {
			return '';
		}

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One row of the plugin's own rate-limit log for an audited reveal; WordPress has no API for that table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT type, identifier, ip_address, ip_address_encrypted FROM %i WHERE id = %d', $wpdb->prefix . 'ffc_rate_limit_logs', $id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return '';
		}

		$ciphertext = \FreeFormCertificate\Core\ArrayValue::string( $row, 'ip_address_encrypted' );
		if ( '' !== $ciphertext ) {
			return (string) \FreeFormCertificate\Core\Encryption::decrypt( $ciphertext );
		}

		foreach ( array( 'ip_address', 'identifier' ) as $column ) {
			$value = \FreeFormCertificate\Core\ArrayValue::string( $row, $column );
			if ( null !== \FreeFormCertificate\Core\IpAddress::canonical( $value ) ) {
				return $value;
			}
		}

		return '';
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
	 * @return array<int, array{log_id: int, identifier: string, count: int, label: string}>
	 */
	private static function top_ips( array $rows ): array {
		$top = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$identifier = \FreeFormCertificate\Core\ArrayValue::string( $row, 'identifier' );
			$ciphertext = \FreeFormCertificate\Core\ArrayValue::string( $row, 'ip_encrypted' );
			$address    = '' !== $ciphertext ? (string) \FreeFormCertificate\Core\Encryption::decrypt( $ciphertext ) : '';
			if ( '' === $address && null !== \FreeFormCertificate\Core\IpAddress::canonical( $identifier ) ) {
				$address = $identifier;
			}

			$top[] = array(
				'log_id'     => \FreeFormCertificate\Core\ArrayValue::int( $row, 'log_id' ),
				'identifier' => $identifier,
				'count'      => \FreeFormCertificate\Core\ArrayValue::int( $row, 'count' ),
				'label'      => '' !== $address
					? \FreeFormCertificate\Core\DocumentFormatter::mask_ip( $address )
					: substr( $identifier, 0, 12 ),
			);
		}

		return $top;
	}
}

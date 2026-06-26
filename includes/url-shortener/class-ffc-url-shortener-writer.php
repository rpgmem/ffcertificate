<?php
/**
 * URL Shortener Writer
 *
 * Write-side of the URL shortener repository split (#591 phase-3, Sprint D2).
 * Holds the domain mutations for the ffc_short_urls table (click-count bump,
 * QR cache persistence). Reads live in {@see UrlShortenerReader};
 * {@see UrlShortenerRepository} remains the public façade that delegates to both.
 *
 * Extends AbstractRepository so it reuses the same wpdb binding, table name,
 * cache group and inherited insert/update/clear_cache helpers — the global $wpdb
 * shared across the façade/reader/writer keeps caching and queries coherent.
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
 * Write operations for url shortener records.
 */
class UrlShortenerWriter extends AbstractRepository {

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
	 * Increment the click counter for a short URL.
	 *
	 * Uses a lightweight UPDATE without cache invalidation since
	 * the click_count field is not needed for redirect resolution.
	 *
	 * @param int $id Record ID.
	 * @return bool
	 */
	public function incrementClickCount( int $id ): bool {
		$sql = $this->wpdb->prepare(
			'UPDATE %i SET click_count = click_count + 1 WHERE id = %d',
			$this->table,
			$id
		);
		if ( ! is_string( $sql ) ) {
			return false;
		}
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->query( $sql );

		return false !== $result;
	}

	/**
	 * Persist the cached QR PNG payload for a short code. Issue #340
	 * centralization (replaces a raw UPDATE in
	 * `UrlShortenerQrHandler::set_qr_cache`).
	 *
	 * @since 6.6.2
	 * @param string $short_code Short URL code.
	 * @param string $base64     Base64-encoded PNG payload to cache.
	 * @return bool True when the UPDATE landed (or matched 0 rows
	 *              cleanly), false on wpdb error.
	 */
	public function setQrCacheForShortCode( string $short_code, string $base64 ): bool {
		if ( '' === $short_code ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single-row update keyed by short_code (UNIQUE).
		$result = $this->wpdb->update(
			$this->table,
			array( 'qr_cache' => $base64 ),
			array( 'short_code' => $short_code ),
			array( '%s' ),
			array( '%s' )
		);
		if ( false === $result ) {
			return false;
		}
		$this->clear_cache();
		return true;
	}
}

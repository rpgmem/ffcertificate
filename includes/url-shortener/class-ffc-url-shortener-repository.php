<?php
/**
 * URL Shortener Repository
 *
 * Data access layer for ffc_short_urls table.
 *
 * Since the #591 phase-3 (Sprint D2) read/write split this class is a thin
 * façade: domain reads live in {@see UrlShortenerReader}, domain writes in
 * {@see UrlShortenerWriter}. The generic CRUD (findById/insert/update/delete/…)
 * inherited from {@see \FreeFormCertificate\Repositories\AbstractRepository}
 * stays here so existing callers that use it directly are unaffected. The
 * façade, reader and writer all bind the same global $wpdb, so caching and
 * queries remain coherent.
 *
 * Design note (#563 B3-A): unlike the static repository façades (retired in
 * B3-A), this instance façade is kept by design — it is the transactional
 * aggregate root (composing reader + writer on the one shared $wpdb, with the
 * inherited generic CRUD), so it must NOT be retired into separate
 * reader/writer call sites.
 *
 * @package FreeFormCertificate\UrlShortener
 * @since 5.1.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\UrlShortener;

use FreeFormCertificate\Repositories\AbstractRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public façade over {@see UrlShortenerReader} + {@see UrlShortenerWriter}.
 */
class UrlShortenerRepository extends AbstractRepository {

	/**
	 * Read-side collaborator.
	 *
	 * @var UrlShortenerReader
	 */
	private UrlShortenerReader $reader;

	/**
	 * Write-side collaborator.
	 *
	 * @var UrlShortenerWriter
	 */
	private UrlShortenerWriter $writer;

	/**
	 * Constructor — wires up the read/write collaborators.
	 */
	public function __construct() {
		parent::__construct();
		$this->reader = new UrlShortenerReader();
		$this->writer = new UrlShortenerWriter();
	}

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

	// ─────────────────────────────────────────────.
	// Reads — delegate to UrlShortenerReader.
	// ─────────────────────────────────────────────.

	/**
	 * Find a short URL record by its short code.
	 *
	 * @param string $code The short code (e.g. "abc123").
	 * @return array<string, mixed>|null
	 */
	public function findByShortCode( string $code ): ?array {
		return $this->reader->findByShortCode( $code );
	}

	/**
	 * Find a short URL record by post ID.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return array<string, mixed>|null
	 */
	public function findByPostId( int $post_id ): ?array {
		return $this->reader->findByPostId( $post_id );
	}

	/**
	 * Check if a short code already exists.
	 *
	 * @param string $code Short code to check.
	 * @return bool
	 */
	public function codeExists( string $code ): bool {
		return $this->reader->codeExists( $code );
	}

	/**
	 * Find paginated short URLs for admin listing.
	 *
	 * @param array<string, mixed> $args Query args (see UrlShortenerReader::findPaginated).
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function findPaginated( array $args = array() ): array {
		return $this->reader->findPaginated( $args );
	}

	/**
	 * Find short URLs that are candidates for cleanup under the enabled criteria.
	 *
	 * @param array{orphaned?:bool, never_clicked?:bool, trashed?:bool} $criteria Enabled criteria.
	 * @param int                                                       $never_clicked_days Grace window (days) for the never_clicked criterion.
	 * @return array<int, array<string, mixed>> Matching rows (empty when no criteria enabled).
	 */
	public function find_cleanup_candidates( array $criteria, int $never_clicked_days ): array {
		return $this->reader->find_cleanup_candidates( $criteria, $never_clicked_days );
	}

	/**
	 * Get aggregate statistics.
	 *
	 * @return array{total_links: int, active_links: int, total_clicks: int, trashed_links: int}
	 */
	public function getStats(): array {
		return $this->reader->getStats();
	}

	/**
	 * Read the cached QR PNG payload for a short code.
	 *
	 * @since 6.6.2
	 * @param string $short_code Short URL code.
	 * @return string Base64-encoded PNG, or empty string when no cache row exists.
	 */
	public function findQrCacheByShortCode( string $short_code ): string {
		return $this->reader->findQrCacheByShortCode( $short_code );
	}

	// ─────────────────────────────────────────────.
	// Writes — delegate to UrlShortenerWriter.
	// ─────────────────────────────────────────────.

	/**
	 * Increment the click counter for a short URL.
	 *
	 * @param int $id Record ID.
	 * @return bool
	 */
	public function incrementClickCount( int $id ): bool {
		return $this->writer->incrementClickCount( $id );
	}

	/**
	 * Persist the cached QR PNG payload for a short code.
	 *
	 * @since 6.6.2
	 * @param string $short_code Short URL code.
	 * @param string $base64     Base64-encoded PNG payload to cache.
	 * @return bool True when the UPDATE landed, false on wpdb error.
	 */
	public function setQrCacheForShortCode( string $short_code, string $base64 ): bool {
		return $this->writer->setQrCacheForShortCode( $short_code, $base64 );
	}
}

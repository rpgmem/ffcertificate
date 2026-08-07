<?php
/**
 * URL Shortener Service
 *
 * Business logic for creating and managing short URLs.
 *
 * @package FreeFormCertificate\UrlShortener
 * @since 5.1.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\UrlShortener;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service class for url shortener operations.
 */
class UrlShortenerService {

	/**
	 * Description.
	 *
	 * @var UrlShortenerRepository
	 */
	private UrlShortenerRepository $repository;

	/**
	 * Base62 character set for short code generation.
	 */
	private const CHARSET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

	/**
	 * Constructor.
	 *
	 * @param UrlShortenerRepository|null $repository Repository.
	 */
	public function __construct( ?UrlShortenerRepository $repository = null ) {
		$this->repository = $repository ?? new UrlShortenerRepository();
	}

	/**
	 * Load plugin settings lazily. Relies on WordPress's internal get_option
	 * cache, so repeated calls within a request are effectively free and
	 * settings changes are reflected immediately.
	 *
	 * @return array<string, mixed>
	 */
	private function get_settings(): array {
		return (array) get_option( 'ffc_settings', array() );
	}

	/**
	 * Create a new short URL.
	 *
	 * @param string   $target_url The destination URL.
	 * @param string   $title      Optional title/label.
	 * @param int|null $post_id    Associated WordPress post ID.
	 * @return array{success: bool, data?: array<string, mixed>, error?: string}
	 */
	public function create_short_url( string $target_url, string $title = '', ?int $post_id = null ): array {
		$target_url = esc_url_raw( $target_url );

		if ( empty( $target_url ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Invalid URL.', 'ffcertificate' ),
			);
		}

		// If post_id provided, check for existing short URL.
		if ( $post_id ) {
			$existing = $this->repository->findByPostId( $post_id );
			if ( $existing ) {
				return array(
					'success' => true,
					'data'    => $existing,
				);
			}
		}

		$code = $this->generate_unique_code();
		$now  = current_time( 'mysql' );

		$data = array(
			'short_code'  => $code,
			'target_url'  => $target_url,
			'post_id'     => $post_id,
			'title'       => sanitize_text_field( $title ),
			'click_count' => 0,
			'created_by'  => get_current_user_id(),
			'created_at'  => $now,
			'updated_at'  => $now,
			'status'      => 'active',
		);

		$id = $this->repository->insert( $data );

		if ( ! $id ) {
			return array(
				'success' => false,
				'error'   => __( 'Failed to create short URL.', 'ffcertificate' ),
			);
		}

		$record = $this->repository->findById( (int) $id );

		if ( ! is_array( $record ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Failed to create short URL.', 'ffcertificate' ),
			);
		}

		return array(
			'success' => true,
			'data'    => $record,
		);
	}

	/**
	 * Generate a unique short code.
	 *
	 * @param int $length Code length (default from settings).
	 * @return string
	 */
	public function generate_unique_code( int $length = 0 ): string {
		if ( $length <= 0 ) {
			$length = $this->get_code_length();
		}

		$charset      = self::CHARSET;
		$charset_len  = strlen( $charset );
		$max_attempts = 10;

		for ( $attempt = 0; $attempt < $max_attempts; $attempt++ ) {
			$code = '';
			for ( $i = 0; $i < $length; $i++ ) {
				$code .= $charset[ random_int( 0, $charset_len - 1 ) ];
			}

			if ( ! $this->repository->codeExists( $code ) ) {
				return $code;
			}
		}

		// Fallback: increase length by 1 on collision.
		return $this->generate_unique_code( $length + 1 );
	}

	/**
	 * Build the full short URL from a code.
	 *
	 * @param string $code The short code.
	 * @return string Full URL (e.g. https://example.com/go/abc123).
	 */
	public function get_short_url( string $code ): string {
		$prefix = $this->get_prefix();
		return home_url( '/' . $prefix . '/' . $code );
	}

	/**
	 * Get the configured URL prefix.
	 *
	 * @return string Prefix without slashes (e.g. "go").
	 */
	public function get_prefix(): string {
		$settings = $this->get_settings();
		$prefix   = $settings['url_shortener_prefix'] ?? 'go';
		return sanitize_title( $prefix );
	}

	/**
	 * Get the configured code length.
	 *
	 * @return int
	 */
	public function get_code_length(): int {
		$settings = $this->get_settings();
		$length   = (int) ( $settings['url_shortener_code_length'] ?? 6 );
		return max( 4, min( 10, $length ) );
	}

	/**
	 * Get the configured redirect type.
	 *
	 * @return int HTTP status code (301, 302, or 307).
	 */
	public function get_redirect_type(): int {
		$settings = $this->get_settings();
		$type     = (int) ( $settings['url_shortener_redirect_type'] ?? 302 );
		return in_array( $type, array( 301, 302, 307 ), true ) ? $type : 302;
	}

	/**
	 * Check if the URL shortener module is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		$settings = $this->get_settings();
		// Cast the STORED value to int rather than strict-comparing against 1:
		// the Modules-tab / autosave path persists this slot as a real bool
		// (`true`), so `1 === true` would be false and wrongly disable the
		// module even with the toggle on. `(int) true === 1` holds for bool,
		// int and the string '1' alike.
		return ! isset( $settings['url_shortener_enabled'] ) || 1 === (int) $settings['url_shortener_enabled'];
	}

	/**
	 * Check if auto-create on publish is enabled.
	 *
	 * @return bool
	 */
	public function is_auto_create_enabled(): bool {
		$settings = $this->get_settings();
		// See is_enabled(): cast the stored value so a bool `true` (autosave)
		// counts as enabled, not just the integer 1.
		return ! isset( $settings['url_shortener_auto_create'] ) || 1 === (int) $settings['url_shortener_auto_create'];
	}

	/**
	 * Get post types that should have the meta box.
	 *
	 * @return array<string>
	 */
	public function get_enabled_post_types(): array {
		$settings   = $this->get_settings();
		$post_types = $settings['url_shortener_post_types'] ?? array( 'post', 'page' );

		if ( is_string( $post_types ) ) {
			$post_types = array_filter( array_map( 'trim', explode( ',', $post_types ) ) );
		}

		return ! empty( $post_types ) ? $post_types : array( 'post', 'page' );
	}

	/**
	 * Get post types whose short URL is exposed publicly as the site's
	 * canonical shortlink (the `rel="shortlink"` `<head>` tag + HTTP `Link:`
	 * header, via the `pre_get_shortlink` filter).
	 *
	 * Unlike {@see get_enabled_post_types()}, this has **no** `post`/`page`
	 * default — the empty array means "expose nothing" (opt-in). Exposure is
	 * a strict subset of the shortened types: the save handler intersects this
	 * with `url_shortener_post_types`, so a stored value never contains a type
	 * that is not also shortened.
	 *
	 * @return array<string>
	 */
	public function get_exposed_post_types(): array {
		$settings   = $this->get_settings();
		$post_types = $settings['url_shortener_expose_post_types'] ?? array();

		if ( is_string( $post_types ) ) {
			$post_types = array_filter( array_map( 'trim', explode( ',', $post_types ) ) );
		}

		return is_array( $post_types ) ? array_values( $post_types ) : array();
	}

	/**
	 * Resolve the effective destination of a short URL record.
	 *
	 * For **post-linked** short URLs (`post_id` set) the destination is derived
	 * from the post's current permalink, so it self-heals when the slug — or the
	 * whole permalink structure — changes (the stored `target_url` captured at
	 * creation would otherwise go stale, #888). Falls back to the stored
	 * `target_url` when the post is gone or has no permalink. **Manual** short
	 * URLs (`post_id` NULL) always use the stored `target_url`.
	 *
	 * Static so the redirect (loader), the admin list and the CSV export all
	 * resolve the destination identically without wiring a service instance.
	 *
	 * @param array<string, mixed> $record Short URL row.
	 * @return string Effective destination URL.
	 */
	public static function resolve_target( array $record ): string {
		$post_id = isset( $record['post_id'] ) ? (int) $record['post_id'] : 0;
		if ( $post_id > 0 ) {
			$permalink = get_permalink( $post_id );
			if ( is_string( $permalink ) && '' !== $permalink ) {
				return $permalink;
			}
		}
		return isset( $record['target_url'] ) ? (string) $record['target_url'] : '';
	}

	/**
	 * Update the destination and title of a **manually-created** short URL.
	 *
	 * The caller (`UrlShortenerAdminPage::ajax_edit_short_url`) enforces the
	 * manual-only rule (`post_id` NULL) — post-linked destinations follow the
	 * page and must not be hand-edited (#888).
	 *
	 * @param int    $id         Record ID.
	 * @param string $target_url New destination URL.
	 * @param string $title      New title.
	 * @return bool
	 */
	public function update_short_url( int $id, string $target_url, string $title ): bool {
		return (bool) $this->repository->update(
			$id,
			array(
				'target_url' => esc_url_raw( $target_url ),
				'title'      => sanitize_text_field( $title ),
				'updated_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Delete a short URL by ID.
	 *
	 * @param int $id Record ID.
	 * @return bool
	 */
	public function delete_short_url( int $id ): bool {
		return (bool) $this->repository->delete( $id );
	}

	/**
	 * Move a short URL to the trash.
	 *
	 * @param int $id Record ID.
	 * @return bool
	 */
	public function trash_short_url( int $id ): bool {
		return (bool) $this->repository->update(
			$id,
			array(
				'status'     => 'trashed',
				'updated_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Restore a short URL from the trash (sets to disabled).
	 *
	 * @param int $id Record ID.
	 * @return bool
	 */
	public function restore_short_url( int $id ): bool {
		return (bool) $this->repository->update(
			$id,
			array(
				'status'     => 'disabled',
				'updated_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Toggle the status of a short URL (active ↔ disabled).
	 *
	 * @param int $id Record ID.
	 * @return bool
	 */
	public function toggle_status( int $id ): bool {
		$record = $this->repository->findById( $id );
		if ( ! $record ) {
			return false;
		}

		$new_status = 'active' === $record['status'] ? 'disabled' : 'active';

		return (bool) $this->repository->update(
			$id,
			array(
				'status'     => $new_status,
				'updated_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Get aggregate statistics.
	 *
	 * @return array{total_links: int, active_links: int, total_clicks: int, trashed_links: int}
	 */
	public function get_stats(): array {
		return $this->repository->getStats();
	}

	/**
	 * Get the repository instance.
	 *
	 * @return UrlShortenerRepository
	 */
	public function get_repository(): UrlShortenerRepository {
		return $this->repository;
	}
}

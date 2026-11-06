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
	 * Characters a generated code is drawn from: upper and lower case and
	 * digits (#2337).
	 *
	 * `short_code` has a binary collation, so `AbC` and `abc` are two codes
	 * ({@see UrlShortenerActivator::SHORT_CODE_COLUMN}).
	 */
	public const CHARSET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

	/**
	 * The longest code the `short_code` column holds.
	 */
	public const MAX_CODE_LENGTH = 10;

	/**
	 * Inserts tried when another request takes the drawn code first.
	 */
	private const INSERT_ATTEMPTS = 3;

	/**
	 * Trashed links deleted per query by Empty Trash.
	 */
	private const EMPTY_TRASH_BATCH = 200;

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
		$target_url = esc_url_raw( $target_url, self::DESTINATION_SCHEMES );

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

		// Instants, in UTC seconds (#2337, Category A).
		$now  = time();
		$data = array(
			'short_code'  => '',
			'target_url'  => $target_url,
			'post_id'     => $post_id,
			'title'       => sanitize_text_field( $title ),
			'click_count' => 0,
			'created_by'  => get_current_user_id(),
			'created_at'  => $now,
			'updated_at'  => $now,
			'status'      => 'active',
		);

		// The UNIQUE key is the real check: a code free when drawn can be
		// taken by another request before the insert, so a refused insert
		// draws again, a bounded number of times (#2337).
		$id = false;
		for ( $attempt = 0; $attempt < self::INSERT_ATTEMPTS && ! $id; $attempt++ ) {
			$data['short_code'] = $this->generate_unique_code();
			if ( '' === $data['short_code'] ) {
				break;
			}
			$id = $this->repository->insert( $data );
			if ( ! $id && ! $this->code_taken( $data['short_code'] ) ) {
				break; // Refused for another reason; drawing again will not help.
			}
		}

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
	 * Generate a code that is neither in use nor retired.
	 *
	 * Ten draws at a length, then one character longer, never past the
	 * column (#2337).
	 *
	 * @param int $length Code length (default from settings).
	 * @return string The code, or '' when none was found up to the column's width.
	 */
	public function generate_unique_code( int $length = 0 ): string {
		if ( $length <= 0 ) {
			$length = $this->get_code_length();
		}

		$last = strlen( self::CHARSET ) - 1;
		for ( ; $length <= self::MAX_CODE_LENGTH; $length++ ) {
			for ( $attempt = 0; $attempt < 10; $attempt++ ) {
				$code = '';
				for ( $i = 0; $i < $length; $i++ ) {
					$code .= self::CHARSET[ random_int( 0, $last ) ];
				}

				if ( ! $this->code_taken( $code ) ) {
					return $code;
				}
			}
		}

		return '';
	}

	/**
	 * Whether a code is in use or retired, so it cannot be issued.
	 *
	 * @param string $code Short code.
	 * @return bool
	 */
	private function code_taken( string $code ): bool {
		return $this->repository->codeExists( $code ) || $this->repository->codeRetired( $code );
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
	 * A short URL that looks like a real one, for a preview drawn before the
	 * real one exists (#1586).
	 *
	 * Always the same code, at the configured length, so the preview has the
	 * length -- and therefore the density -- of the code that will print.
	 *
	 * @return string
	 */
	public function get_example_short_url(): string {
		return $this->get_short_url( substr( str_repeat( 'Ab3dEf9h', 8 ), 0, $this->get_code_length() ) );
	}

	/**
	 * The code of an address that is one of this site's short URLs, or ''.
	 *
	 * The address must sit under the site's short-URL base (host compared
	 * without case or a leading "www.", scheme ignored) and name a code that
	 * exists. Any other page of the site is not a short URL and may be
	 * shortened (#1586).
	 *
	 * @param string $url Address.
	 * @return string
	 */
	public function code_from_short_url( string $url ): string {
		$base   = wp_parse_url( $this->get_short_url( '' ) );
		$target = wp_parse_url( trim( $url ) );
		if ( ! is_array( $base ) || ! is_array( $target ) || empty( $target['host'] ) || empty( $base['host'] ) ) {
			return '';
		}

		$host = static fn( string $h ): string => (string) preg_replace( '/^www\./', '', strtolower( $h ) );
		if ( $host( (string) $target['host'] ) !== $host( (string) $base['host'] ) ) {
			return '';
		}

		$prefix = rtrim( (string) ( $base['path'] ?? '' ), '/' ) . '/';
		$path   = (string) ( $target['path'] ?? '' );
		if ( 0 !== strpos( $path, $prefix ) ) {
			return '';
		}

		$code = trim( substr( $path, strlen( $prefix ) ), '/' );
		if ( '' === $code || false !== strpos( $code, '/' ) ) {
			return '';
		}
		return $this->repository->codeExists( $code ) ? $code : '';
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
	 * Prefixes the site used before the current one (#2337).
	 *
	 * A request under one of them that names a known code is sent on to the
	 * current prefix, so a link or QR printed before a prefix change keeps
	 * working.
	 *
	 * @return array<int, string>
	 */
	public function get_previous_prefixes(): array {
		return self::previous_prefixes_from( $this->get_settings() );
	}

	/**
	 * The previous prefixes a settings array holds, cleaned, without the
	 * current one.
	 *
	 * @param array<string, mixed> $settings `ffc_settings`.
	 * @return array<int, string>
	 */
	public static function previous_prefixes_from( array $settings ): array {
		$stored = $settings['url_shortener_previous_prefixes'] ?? array();
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$current = sanitize_title( (string) ( $settings['url_shortener_prefix'] ?? 'go' ) );
		return array_values(
			array_filter(
				array_unique( array_map( 'sanitize_title', array_map( 'strval', $stored ) ) ),
				static fn( string $prefix ): bool => '' !== $prefix && $prefix !== $current
			)
		);
	}

	/**
	 * Get the configured code length.
	 *
	 * @return int
	 */
	public function get_code_length(): int {
		$settings = $this->get_settings();
		$length   = (int) ( $settings['url_shortener_code_length'] ?? 6 );
		return max( 4, min( self::MAX_CODE_LENGTH, $length ) );
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
	 * Schemes a short URL may send to (#2337, decided in the session): the
	 * QR generator's web schemes plus mailto, tel and sms. Never
	 * `javascript:`, `data:` or `file:`. One list for the generator's shorten
	 * action, the list's edit action, creation and the redirect itself.
	 */
	public const DESTINATION_SCHEMES = array( ...\FreeFormCertificate\Generators\QrPayload::URL_SCHEMES, 'mailto', 'tel', 'sms' );

	/**
	 * The schemes a phone follows reliably out of an HTTP redirect. Any other
	 * accepted scheme gets a warning, never a refusal.
	 */
	public const RECOMMENDED_SCHEMES = array( 'https', 'http' );

	/**
	 * Whether a short URL may send to an address: one of DESTINATION_SCHEMES.
	 *
	 * @param string $url Address.
	 * @return bool
	 */
	public static function is_allowed_destination( string $url ): bool {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		return is_string( $scheme ) && in_array( strtolower( $scheme ), self::DESTINATION_SCHEMES, true )
			&& '' !== esc_url_raw( $url, self::DESTINATION_SCHEMES );
	}

	/**
	 * The scheme of an accepted address that a phone may not follow out of a
	 * redirect, or '' for http(s) (#2337).
	 *
	 * @param string $url Address.
	 * @return string
	 */
	public static function scheme_warning( string $url ): string {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$scheme = is_string( $scheme ) ? strtolower( $scheme ) : '';
		return in_array( $scheme, self::DESTINATION_SCHEMES, true ) && ! in_array( $scheme, self::RECOMMENDED_SCHEMES, true ) ? $scheme : '';
	}

	/**
	 * File extensions whose address makes a browser download a file instead
	 * of showing a page (#2337). Only the path is read: nothing is fetched.
	 */
	public const DOWNLOAD_EXTENSIONS = array( 'pdf', 'zip', 'rar', '7z', 'gz', 'tar', 'exe', 'msi', 'dmg', 'apk', 'iso', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'csv', 'rtf', 'epub' );

	/**
	 * The extension of an address that downloads a file, or '' (#2337).
	 *
	 * Read off the path alone, without a request, so a page that happens to
	 * serve a file under a clean path is not noticed. The warning this feeds
	 * never blocks a save.
	 *
	 * @param string $url Address.
	 * @return string The extension, lowercase and without the dot.
	 */
	public static function download_extension( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}
		$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		return in_array( $extension, self::DOWNLOAD_EXTENSIONS, true ) ? $extension : '';
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
		return false !== $this->repository->update(
			$id,
			array(
				'target_url' => esc_url_raw( $target_url, self::DESTINATION_SCHEMES ),
				'title'      => sanitize_text_field( $title ),
				'updated_at' => time(),
			)
		);
	}

	/**
	 * Delete a short URL by ID and retire its code for good (#2337).
	 *
	 * @param int $id Record ID.
	 * @return bool
	 */
	public function delete_short_url( int $id ): bool {
		$record = $this->repository->findById( $id );
		return is_array( $record ) && $this->repository->retireAndDelete( $record, 'deleted' );
	}

	/**
	 * Delete every trashed link, retiring each code (#2337).
	 *
	 * Batch by batch until the trash is empty, or until a batch deletes
	 * nothing: the loop stops on what it changed, never on what it read,
	 * so a row that cannot be deleted cannot keep it running.
	 *
	 * @return int Links actually deleted.
	 */
	public function empty_trash(): int {
		$deleted = 0;
		do {
			$batch = $this->repository->findTrashedBatch( self::EMPTY_TRASH_BATCH );
			$full  = count( $batch ) === self::EMPTY_TRASH_BATCH;
			$done  = 0;
			foreach ( $batch as $row ) {
				if ( $this->repository->retireAndDelete( $row, 'deleted' ) ) {
					++$done;
				}
			}
			$deleted += $done;
		} while ( $done > 0 && $full );

		return $deleted;
	}

	/**
	 * Move a short URL to the trash.
	 *
	 * @param int $id Record ID.
	 * @return bool
	 */
	public function trash_short_url( int $id ): bool {
		return false !== $this->repository->update(
			$id,
			array(
				'status'     => 'trashed',
				'updated_at' => time(),
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
		return false !== $this->repository->update(
			$id,
			array(
				'status'     => 'disabled',
				'updated_at' => time(),
			)
		);
	}

	/**
	 * Bring a disabled or trashed link back as active, same code (#2337).
	 *
	 * Its clicks and every printed QR keep working, which is why the meta
	 * box offers this instead of a new code when a post's link is off.
	 *
	 * @param int $id Record ID.
	 * @return bool
	 */
	public function reactivate_short_url( int $id ): bool {
		return false !== $this->repository->update(
			$id,
			array(
				'status'     => 'active',
				'updated_at' => time(),
			)
		);
	}

	/**
	 * Give a link a new code in place and retire the old one (#2337).
	 *
	 * The same row takes the code, so clicks, title and target carry over and
	 * nothing goes to the trash. The old code stops answering at once and is
	 * never issued again. A code another request takes first is drawn again.
	 *
	 * @param array<string, mixed> $record The link row.
	 * @return array{success: bool, data?: array<string, mixed>, error?: string}
	 */
	public function regenerate_code( array $record ): array {
		for ( $attempt = 0; $attempt < self::INSERT_ATTEMPTS; $attempt++ ) {
			$code = $this->generate_unique_code();
			if ( '' === $code ) {
				break;
			}
			if ( $this->repository->swapCode( $record, $code ) ) {
				$fresh = $this->repository->findById( (int) $record['id'] );
				if ( is_array( $fresh ) ) {
					return array(
						'success' => true,
						'data'    => $fresh,
					);
				}
				break;
			}
			if ( ! $this->code_taken( $code ) ) {
				break; // Refused for another reason; drawing again will not help.
			}
		}

		return array(
			'success' => false,
			'error'   => __( 'Could not generate a new short code. The short URL is unchanged.', 'ffcertificate' ),
		);
	}

	/**
	 * Switch a short URL on or off (#1904). A trashed or missing link is
	 * left alone: the switch is for live links, and a link leaves the trash
	 * through Restore.
	 *
	 * @param int  $id     Record ID.
	 * @param bool $active Whether the link redirects.
	 * @return bool
	 */
	public function set_active( int $id, bool $active ): bool {
		$record = $this->repository->findById( $id );
		if ( ! $record || 'trashed' === $record['status'] ) {
			return false;
		}

		// 0 rows changed means the link was already in that state, which is
		// what was asked: only a database error is a failure (#2337).
		return false !== $this->repository->update(
			$id,
			array(
				'status'     => $active ? 'active' : 'disabled',
				'updated_at' => time(),
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

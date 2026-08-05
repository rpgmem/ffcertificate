<?php
/**
 * RewriteHtmlImageRefsMigrationStrategy
 *
 * Moves images that stored certificate content references from the legacy
 * `html/` drop-folder into the WordPress Media Library, and rewrites the stored
 * references to point at the new attachments (issue #865). This lets the
 * update-fragile `html/` folder be retired without breaking existing forms.
 *
 * It scans four surfaces for URLs containing the plugin-scoped marker
 * `ffcertificate/html/` (never `ffcertificate/assets/`):
 *   - `_ffc_form_config['pdf_layout']` — the form's certificate HTML,
 *   - `_ffc_form_config['bg_image']`   — the editor background URL,
 *   - `_ffc_form_bg`                   — the top-level background meta the PDF
 *                                        generator actually reads,
 *   - `_ffc_template_html`             — every `ffc_cert_template` pool post.
 *
 * Each distinct legacy file is side-loaded **once** (a local copy → Media
 * Library, cached in the `ffc_html_migrated_media` option so later batches and
 * other posts reuse the same attachment), then every reference to it is
 * rewritten in place. A reference whose file is missing on disk is reported as
 * an error and left untouched.
 *
 * Idempotency + termination follow the {@see ImportLegacyTemplatesMigrationStrategy}
 * pattern: every scanned post is recorded in `ffc_html_rewrite_processed` once
 * visited (whether or not a rewrite happened), so re-runs never re-process it
 * and a post that only carries missing-file references still leaves the pending
 * set — the batch runner always terminates.
 *
 * @package FreeFormCertificate\Migrations\Strategies
 * @since   6.20.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Migrations\Strategies;

use FreeFormCertificate\Admin\CertTemplateCpt;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strategy: rewrite legacy `html/` image references into Media Library attachments.
 */
class RewriteHtmlImageRefsMigrationStrategy implements MigrationStrategyInterface {

	/**
	 * Plugin-scoped path segment that identifies a legacy `html/` reference.
	 * Deliberately includes the plugin slug so `.../ffcertificate/assets/...`
	 * never matches.
	 */
	private const HTML_MARKER = 'ffcertificate/html/';

	/**
	 * Form config post meta key (a plain string literal across the codebase).
	 */
	private const FORM_CONFIG_META = '_ffc_form_config';

	/**
	 * Top-level form background post meta key (read by the PDF generator).
	 */
	private const FORM_BG_META = '_ffc_form_bg';

	/**
	 * Option caching migrated files: file tail → attachment URL (side-load dedup).
	 */
	private const MEDIA_MAP_OPTION = 'ffc_html_migrated_media';

	/**
	 * Option listing already-visited posts as `type:id` (idempotency + termination).
	 */
	private const PROCESSED_OPTION = 'ffc_html_rewrite_processed';

	/**
	 * Absolute path (trailing slash) of the legacy `html/` folder on disk.
	 *
	 * @var string
	 */
	private string $html_dir;

	/**
	 * Constructor.
	 *
	 * @param string|null $html_dir Absolute path to the legacy `html/` folder.
	 *                              Defaults to the plugin's `html/`. Injectable for tests.
	 */
	public function __construct( ?string $html_dir = null ) {
		if ( null === $html_dir ) {
			$html_dir = FFC_PLUGIN_DIR . 'html/';
		}
		$this->html_dir = rtrim( $html_dir, '/' ) . '/';
	}

	/**
	 * Calculate status. A post is "pending" when it still carries the legacy
	 * marker and has not yet been visited. Total is every scanned post.
	 *
	 * @param string               $migration_key Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @return array<string, mixed> Status information.
	 */
	public function calculate_status( string $migration_key, array $migration_config ): array {
		$processed_keys = $this->processed_keys();

		$total   = 0;
		$pending = 0;
		foreach ( $this->collect_targets() as $target ) {
			++$total;
			if ( $this->target_has_marker( $target ) && ! in_array( $this->target_key( $target ), $processed_keys, true ) ) {
				++$pending;
			}
		}

		$migrated = $total - $pending;
		$percent  = ( $total > 0 ) ? ( $migrated / $total ) * 100 : 100;

		return array(
			'total'       => $total,
			'migrated'    => $migrated,
			'pending'     => $pending,
			'percent'     => round( $percent, 2 ),
			'is_complete' => ( 0 === $pending ),
		);
	}

	/**
	 * Rewrite a batch of pending posts.
	 *
	 * @param string               $migration_key Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @param int                  $batch_number Batch number (unused — visited posts leave the pending set).
	 * @return array<string, mixed> Execution result.
	 */
	public function execute( string $migration_key, array $migration_config, int $batch_number = 0 ): array {
		$batch_size = isset( $migration_config['batch_size'] ) ? max( 1, intval( $migration_config['batch_size'] ) ) : 10;

		$processed_keys = $this->processed_keys();
		$map            = $this->media_map();

		$processed   = 0;
		$errors      = array();
		$new_visited = array();

		foreach ( $this->collect_targets() as $target ) {
			$key = $this->target_key( $target );
			if ( in_array( $key, $processed_keys, true ) || ! $this->target_has_marker( $target ) ) {
				continue;
			}
			if ( count( $new_visited ) >= $batch_size ) {
				break;
			}

			// Visit-once regardless of outcome so a missing-file-only post still
			// terminates and its error is reported exactly once.
			$new_visited[] = $key;

			$changed = $this->rewrite_target( $target, $map, $errors );
			if ( $changed ) {
				++$processed;
			}
		}

		if ( ! empty( $new_visited ) ) {
			$this->store_media_map( $map );
			$merged = array_values( array_unique( array_merge( $processed_keys, $new_visited ) ) );
			update_option( self::PROCESSED_OPTION, $merged );
		}

		if ( ! empty( $errors ) ) {
			$this->log_errors( $errors );
		}

		$status   = $this->calculate_status( $migration_key, $migration_config );
		$has_more = $status['pending'] > 0;

		return array(
			'success'   => count( $errors ) === 0,
			'processed' => $processed,
			'has_more'  => $has_more,
			/* translators: %d: number of posts rewritten */
			'message'   => sprintf( __( 'Rewrote html/ image references on %d item(s)', 'ffcertificate' ), $processed ),
			'errors'    => $errors,
		);
	}

	/**
	 * Check whether the migration can run.
	 *
	 * @param string               $migration_key Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @return bool|WP_Error
	 */
	public function can_run( string $migration_key, array $migration_config ) {
		return true;
	}

	/**
	 * Human-readable strategy name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Rewrite html/ Image References', 'ffcertificate' );
	}

	/**
	 * Build the flat list of scan targets: every `ffc_form` (config + bg meta) and
	 * every `ffc_cert_template` pool post (HTML body).
	 *
	 * @return array<int, array<string, mixed>> Each target: {type, id, config?, bg?, html?}.
	 */
	private function collect_targets(): array {
		$targets = array();

		$forms = get_posts(
			array(
				'post_type'        => 'ffc_form',
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);
		foreach ( (array) $forms as $form_id ) {
			$form_id   = (int) $form_id;
			$config    = get_post_meta( $form_id, self::FORM_CONFIG_META, true );
			$targets[] = array(
				'type'   => 'form',
				'id'     => $form_id,
				'config' => is_array( $config ) ? $config : array(),
				'bg'     => (string) get_post_meta( $form_id, self::FORM_BG_META, true ),
			);
		}

		$pool = get_posts(
			array(
				'post_type'        => CertTemplateCpt::POST_TYPE,
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);
		foreach ( (array) $pool as $post_id ) {
			$post_id   = (int) $post_id;
			$targets[] = array(
				'type' => 'pool',
				'id'   => $post_id,
				'html' => (string) get_post_meta( $post_id, CertTemplateCpt::META_HTML, true ),
			);
		}

		return $targets;
	}

	/**
	 * Stable dedup key for a target.
	 *
	 * @param array<string, mixed> $target Target descriptor.
	 * @return string
	 */
	private function target_key( array $target ): string {
		return (string) $target['type'] . ':' . (int) $target['id'];
	}

	/**
	 * The scannable string fields of a target.
	 *
	 * @param array<string, mixed> $target Target descriptor.
	 * @return array<int, string>
	 */
	private function target_strings( array $target ): array {
		if ( 'pool' === $target['type'] ) {
			return array( (string) ( $target['html'] ?? '' ) );
		}
		$config = is_array( $target['config'] ?? null ) ? $target['config'] : array();
		return array(
			(string) ( $config['pdf_layout'] ?? '' ),
			(string) ( $config['bg_image'] ?? '' ),
			(string) ( $target['bg'] ?? '' ),
		);
	}

	/**
	 * Whether any of a target's fields carry the legacy marker.
	 *
	 * @param array<string, mixed> $target Target descriptor.
	 * @return bool
	 */
	private function target_has_marker( array $target ): bool {
		foreach ( $this->target_strings( $target ) as $string ) {
			if ( '' !== $string && false !== strpos( $string, self::HTML_MARKER ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Rewrite one target's fields, side-loading files as needed, and persist the
	 * fields that changed. Missing-file references are recorded in $errors and
	 * left untouched.
	 *
	 * @param array<string, mixed>  $target Target descriptor.
	 * @param array<string, string> $map    File tail → attachment URL cache (by reference).
	 * @param array<int, string>    $errors Error accumulator (by reference).
	 * @return bool True when at least one field was rewritten and persisted.
	 */
	private function rewrite_target( array $target, array &$map, array &$errors ): bool {
		if ( 'pool' === $target['type'] ) {
			$html = (string) ( $target['html'] ?? '' );
			$new  = $this->rewrite_string( $html, $map, $errors, 'template#' . $target['id'] );
			if ( $new !== $html ) {
				update_post_meta( (int) $target['id'], CertTemplateCpt::META_HTML, $new );
				return true;
			}
			return false;
		}

		$changed    = false;
		$config     = is_array( $target['config'] ?? null ) ? $target['config'] : array();
		$new_config = $config;

		foreach ( array( 'pdf_layout', 'bg_image' ) as $key ) {
			$value = (string) ( $config[ $key ] ?? '' );
			if ( '' === $value ) {
				continue;
			}
			$rewritten = $this->rewrite_string( $value, $map, $errors, "form#{$target['id']}:{$key}" );
			if ( $rewritten !== $value ) {
				$new_config[ $key ] = $rewritten;
				$changed            = true;
			}
		}
		if ( $changed ) {
			update_post_meta( (int) $target['id'], self::FORM_CONFIG_META, $new_config );
		}

		$bg = (string) ( $target['bg'] ?? '' );
		if ( '' !== $bg ) {
			$new_bg = $this->rewrite_string( $bg, $map, $errors, "form#{$target['id']}:_ffc_form_bg" );
			if ( $new_bg !== $bg ) {
				update_post_meta( (int) $target['id'], self::FORM_BG_META, $new_bg );
				$changed = true;
			}
		}

		return $changed;
	}

	/**
	 * Rewrite every resolvable legacy URL in a string to its Media Library URL.
	 * Unresolvable (missing-file) URLs are recorded in $errors and left as-is.
	 *
	 * @param string                $content String to rewrite.
	 * @param array<string, string> $map     File tail → attachment URL cache (by reference).
	 * @param array<int, string>    $errors  Error accumulator (by reference).
	 * @param string                $context Human context for error messages.
	 * @return string The rewritten string.
	 */
	private function rewrite_string( string $content, array &$map, array &$errors, string $context ): string {
		foreach ( $this->find_marker_urls( $content ) as $url ) {
			$tail = $this->url_tail( $url );
			if ( null === $tail ) {
				/* translators: 1: reference URL, 2: context */
				$errors[] = sprintf( __( 'Unrecognized html/ reference "%1$s" (%2$s)', 'ffcertificate' ), $url, $context );
				continue;
			}

			if ( ! isset( $map[ $tail ] ) ) {
				$src = $this->html_dir . $tail;
				if ( ! file_exists( $src ) ) {
					/* translators: 1: file path, 2: context */
					$errors[] = sprintf( __( 'Missing html/ file "%1$s" (%2$s)', 'ffcertificate' ), $tail, $context );
					continue;
				}
				$new_url = $this->sideload_resolved_file( $src, basename( $tail ) );
				if ( '' === $new_url ) {
					/* translators: 1: file path, 2: context */
					$errors[] = sprintf( __( 'Failed to import html/ file "%1$s" into the Media Library (%2$s)', 'ffcertificate' ), $tail, $context );
					continue;
				}
				$map[ $tail ] = $new_url;
			}

			$content = str_replace( $url, $map[ $tail ], $content );
		}

		return $content;
	}

	/**
	 * Find all distinct URL tokens containing the legacy marker in a string. A
	 * token is a maximal run of non-delimiter characters, so it works both for an
	 * `<img src="…">` value and a bare URL field.
	 *
	 * @param string $content String to scan.
	 * @return array<int, string> Distinct matched URLs.
	 */
	private function find_marker_urls( string $content ): array {
		if ( '' === $content || false === strpos( $content, self::HTML_MARKER ) ) {
			return array();
		}
		if ( ! preg_match_all( '~[^\s"\'()<>]*ffcertificate/html/[^\s"\'()<>]*~i', $content, $matches ) ) {
			return array();
		}
		return array_values( array_unique( $matches[0] ) );
	}

	/**
	 * Extract the file tail (path after the marker), stripped of any query string
	 * or fragment. Returns null for a traversal-unsafe or empty tail.
	 *
	 * @param string $url Matched URL.
	 * @return string|null
	 */
	private function url_tail( string $url ): ?string {
		$pos = stripos( $url, self::HTML_MARKER );
		if ( false === $pos ) {
			return null;
		}
		$tail = substr( $url, $pos + strlen( self::HTML_MARKER ) );
		$tail = (string) preg_replace( '/[?#].*$/', '', $tail );
		$tail = ltrim( $tail, '/' );

		if ( '' === $tail || false !== strpos( $tail, '..' ) || ! preg_match( '~^[A-Za-z0-9._/\-]+$~', $tail ) ) {
			return null;
		}
		return $tail;
	}

	/**
	 * Already-visited post keys (`type:id`) from the idempotency option.
	 *
	 * @return array<int, string>
	 */
	private function processed_keys(): array {
		$stored = get_option( self::PROCESSED_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		return array_values( array_filter( $stored, 'is_string' ) );
	}

	/**
	 * Read the file-tail → attachment-URL cache option.
	 *
	 * @return array<string, string>
	 */
	private function media_map(): array {
		$stored = get_option( self::MEDIA_MAP_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$map = array();
		foreach ( $stored as $tail => $url ) {
			if ( is_string( $tail ) && is_string( $url ) ) {
				$map[ $tail ] = $url;
			}
		}
		return $map;
	}

	/**
	 * Persist the file-tail → attachment-URL cache option.
	 *
	 * @param array<string, string> $map Cache to store.
	 * @return void
	 */
	private function store_media_map( array $map ): void {
		update_option( self::MEDIA_MAP_OPTION, $map );
	}

	/**
	 * Log a batch's errors to the activity log (best-effort; visibility for
	 * missing/failed files, since the AJAX runner only surfaces status counters).
	 *
	 * @param array<int, string> $errors Error messages.
	 * @return void
	 */
	private function log_errors( array $errors ): void {
		if ( class_exists( '\\FreeFormCertificate\\Core\\ActivityLog' ) ) {
			\FreeFormCertificate\Core\ActivityLog::log(
				'rewrite_html_image_refs_errors',
				\FreeFormCertificate\Core\ActivityLog::LEVEL_WARNING,
				array( 'errors' => $errors )
			);
		}
	}

	/**
	 * Side-load a local file into the Media Library and return its attachment URL.
	 *
	 * Copies the local plugin file to a temp path and hands it to
	 * `media_handle_sideload()` (avoids an HTTP self-request for a file already on
	 * disk). Isolated in a protected method so tests can override the WordPress
	 * media plumbing.
	 *
	 * @param string $src_path Absolute path of the source file.
	 * @param string $basename Desired attachment filename.
	 * @return string The attachment URL, or an empty string on failure.
	 */
	protected function sideload_resolved_file( string $src_path, string $basename ): string {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$tmp = wp_tempnam( $basename );
		if ( ! $tmp ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Copying a bundled plugin file to a WP temp path for media_handle_sideload.
		if ( ! @copy( $src_path, $tmp ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- copy failure is handled via the empty-return path below.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $tmp );
			return '';
		}

		$file_array = array(
			'name'     => $basename,
			'tmp_name' => $tmp,
		);
		$attachment_id = media_handle_sideload( $file_array, 0 );

		if ( is_wp_error( $attachment_id ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $tmp );
			return '';
		}

		$url = wp_get_attachment_url( (int) $attachment_id );
		return is_string( $url ) ? $url : '';
	}
}

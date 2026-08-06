<?php
/**
 * LegacyHtmlRefs
 *
 * Single source of truth for detecting references to the update-fragile legacy
 * `html/` drop-folder inside stored certificate content (issue #865). A plugin
 * update or `rsync --delete` deploy wipes anything the user dropped into
 * `wp-content/plugins/ffcertificate/html/`, so any stored URL pointing there is
 * a latent broken image.
 *
 * The plugin-scoped marker deliberately carries the plugin slug so
 * `.../ffcertificate/assets/...` (a shipped, update-safe asset) never matches —
 * only the user drop-folder does. Three consumers share this definition rather
 * than each carrying its own copy:
 *   - {@see \FreeFormCertificate\Admin\HtmlRefsNotice} (Phase 0 admin notice),
 *   - {@see \FreeFormCertificate\Migrations\Strategies\RewriteHtmlImageRefsMigrationStrategy}
 *     (the migration that side-loads them into the Media Library),
 *   - {@see \FreeFormCertificate\Admin\FormEditorSaveHandler} (the Phase 4
 *     save-time linter).
 *
 * @package FreeFormCertificate\Core
 * @since   6.20.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detection helpers for legacy `html/` image references.
 */
class LegacyHtmlRefs {

	/**
	 * Plugin-scoped path segment that identifies a legacy `html/` reference.
	 * Includes the plugin slug so a shipped `ffcertificate/assets/` URL never
	 * matches.
	 */
	public const MARKER = 'ffcertificate/html/';

	/**
	 * Whether a string contains the legacy marker at all. A cheap `strpos`
	 * fast-path callers use before the fuller {@see find_urls} scan.
	 *
	 * @param string $content String to probe.
	 * @return bool
	 */
	public static function has_marker( string $content ): bool {
		return '' !== $content && false !== strpos( $content, self::MARKER );
	}

	/**
	 * Find every distinct URL token that carries the legacy marker. A token is a
	 * maximal run of non-delimiter characters, so it matches both an
	 * `<img src="…">` attribute value and a bare URL field.
	 *
	 * @param string $content String to scan.
	 * @return array<int, string> Distinct matched URLs (empty when none).
	 */
	public static function find_urls( string $content ): array {
		if ( ! self::has_marker( $content ) ) {
			return array();
		}

		$pattern = '~[^\s"\'()<>]*' . preg_quote( self::MARKER, '~' ) . '[^\s"\'()<>]*~i';
		if ( ! preg_match_all( $pattern, $content, $matches ) ) {
			return array();
		}

		return array_values( array_unique( $matches[0] ) );
	}
}

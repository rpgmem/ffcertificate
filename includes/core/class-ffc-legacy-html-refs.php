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
 * than each carrying its own copy: the `HtmlRefsNotice` (Phase 0 admin notice),
 * the `RewriteHtmlImageRefsMigrationStrategy` (which side-loads the images into
 * the Media Library) and the `FormEditorSaveHandler` (the Phase 4 save-time
 * linter). Their fully-qualified names are intentionally omitted here: Core is
 * a leaf module (depended-upon, never depending), and a `FreeFormCertificate\
 * Admin\…` reference even in a docblock would register a reverse edge in the
 * module-boundary guard.
 *
 * @package FreeFormCertificate\Core
 * @since   6.18.0
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

	/**
	 * Whether the legacy drop-folder still exists on disk.
	 *
	 * The plugin stopped shipping `html/` in 6.23.0 (#1087), so on every install
	 * this is false — but it is a probe rather than a constant on purpose. The
	 * folder was always the ADMIN's: nothing stops one from recreating it and
	 * dropping files back in, and when they do, the surfaces that operate on it
	 * become useful again on their own.
	 *
	 * Two of those surfaces read this to decide whether to exist at all (#1438).
	 * The rewrite migration side-loads the files from here, so with no folder its
	 * repair is impossible while its pending count is not: it reads the DATABASE,
	 * which still names posts pointing at `html/`. That combination is the trap
	 * the probe closes — `execute()` records every target it visits whether or
	 * not the rewrite happened (which is what makes the batch terminate), so a
	 * run with no source files walks every affected post, repairs none and drops
	 * pending to zero. The import migration is the benign inverse: it measures
	 * files on disk, so with none it reports 100% complete, truthfully and for
	 * ever.
	 *
	 * @param string|null $plugin_dir Plugin root to probe. Defaults to
	 *                                `FFC_PLUGIN_DIR`; injectable for tests,
	 *                                the same way both strategies take the
	 *                                folder they read.
	 * @return bool
	 */
	public static function drop_folder_exists( ?string $plugin_dir = null ): bool {
		if ( null === $plugin_dir ) {
			if ( ! defined( 'FFC_PLUGIN_DIR' ) ) {
				return false;
			}
			$plugin_dir = (string) FFC_PLUGIN_DIR;
		}

		return is_dir( rtrim( $plugin_dir, '/' ) . '/html' );
	}
}

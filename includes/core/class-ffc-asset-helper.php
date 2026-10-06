<?php
/**
 * Asset Helper
 *
 * Asset-pipeline helpers extracted from {@see Utils} (#563 Sprint 5 phase 2,
 * B1). Holds the minified-suffix resolver (driven by `SCRIPT_DEBUG`) and the
 * shared dark-mode enqueue, keeping these `wp_enqueue_*` concerns out of the
 * general-purpose `Core\Utils` hub.
 *
 * @package FreeFormCertificate\Core
 * @since   6.12.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless asset-enqueue helpers shared between Frontend and Admin.
 */
final class AssetHelper {

	/**
	 * Get minified asset suffix based on SCRIPT_DEBUG constant
	 *
	 * Returns '.min' when SCRIPT_DEBUG is off (production),
	 * or '' when SCRIPT_DEBUG is on (development).
	 *
	 * @since 4.6.12
	 * @return string '.min' or ''
	 */
	public static function asset_suffix(): string {
		return defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
	}

	/**
	 * On a development install, version the plugin's own assets by file time.
	 *
	 * Every enqueue passes `FFC_VERSION` as `?ver=`, and that constant only
	 * moves at a release. A site running `develop` (the testes server) gets
	 * new files on every merge under the same URL, so a browser keeps the
	 * script it cached and the new behaviour never reaches it: #1593 shipped
	 * an overlay that did not open, and a 4xx reason the stale script could
	 * not read, for exactly that reason. `SCRIPT_DEBUG` is what marks such a
	 * site already (it is what serves the non-minified files), so the
	 * filters are registered only there; production keeps `FFC_VERSION`.
	 *
	 * `script_loader_src` / `style_loader_src` are the one point every
	 * enqueued URL passes through, so the 40-odd enqueue sites need no
	 * change. Default priority 10: nothing else in the plugin filters these.
	 *
	 * @param bool|null $debug Whether this is a development install; null reads `SCRIPT_DEBUG`.
	 */
	public static function register_dev_cache_busting( ?bool $debug = null ): void {
		$debug = $debug ?? ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG );
		if ( ! $debug ) {
			return;
		}
		add_filter( 'script_loader_src', array( self::class, 'version_by_file_time' ) );
		add_filter( 'style_loader_src', array( self::class, 'version_by_file_time' ) );
	}

	/**
	 * Append the file's modification time to the `ver` of a plugin asset.
	 *
	 * URLs outside the plugin, and files that cannot be read, pass unchanged.
	 *
	 * @param mixed $src Asset URL as WordPress is about to print it.
	 * @return mixed The URL with `ver=FFC_VERSION.<mtime>`, or `$src` as given.
	 */
	public static function version_by_file_time( $src ) {
		if ( ! is_string( $src ) || 0 !== strpos( $src, FFC_PLUGIN_URL ) ) {
			return $src;
		}
		$relative = (string) strtok( substr( $src, strlen( FFC_PLUGIN_URL ) ), '?#' );
		$path     = FFC_PLUGIN_DIR . $relative;
		if ( '' === $relative || false !== strpos( $relative, '..' ) || ! is_file( $path ) ) {
			return $src;
		}
		$time = filemtime( $path );
		if ( false === $time ) {
			return $src;
		}
		return add_query_arg( 'ver', FFC_VERSION . '.' . $time, $src );
	}

	/**
	 * Put the design-token palette on the page.
	 *
	 * `ffc-common.css` is where every `--ffc-*` custom property is declared, so
	 * a stylesheet that paints with `var(--ffc-*)` is unusable without it. The
	 * failure is not a fallback to the previous literal — an undeclared custom
	 * property makes the **whole declaration invalid** at compute time, so the
	 * element renders with no background/colour at all, in both themes. That is
	 * strictly worse than the hardcoded hex the tokens replaced, which is why
	 * #1126 (defeito B) pairs every conversion with this call.
	 *
	 * Callers should ALSO list `'ffc-common'` in the dependent handle's `$deps`.
	 * The enqueue here guarantees the palette is present; the declared
	 * dependency is what keeps it in the chain when a CSS-combining cache
	 * plugin flattens the queue — the same reasoning as the `ffc-core` script
	 * dependencies added in 6.6.7 (#367). `tests/Unit/AdminStylesheetTokensTest`
	 * enforces the second half.
	 *
	 * @since 6.24.0
	 * @return void
	 */
	public static function enqueue_common_style(): void {
		$s = self::asset_suffix();

		wp_enqueue_style(
			'ffc-common',
			FFC_PLUGIN_URL . "assets/css/ffc-common{$s}.css",
			array(),
			FFC_VERSION
		);
	}

	/**
	 * Enqueue dark mode script if enabled
	 *
	 * Shared between admin and frontend to avoid duplicate logic.
	 *
	 * @since 4.7.0
	 *
	 * @param bool $always Enqueue even when the mode is `off`. The script is what
	 *                     repaints `<html>` when the Dark Mode select auto-saves,
	 *                     so a screen that can CHANGE the setting needs it loaded
	 *                     in the `off` state too — otherwise switching light → dark
	 *                     has nobody to act on it and only takes effect on the next
	 *                     page load. Public pages pass `false` (the default): they
	 *                     cannot change the setting, so loading a script that would
	 *                     do nothing is a request for nothing.
	 * @return void
	 */
	public static function enqueue_dark_mode( bool $always = false ): void {
		$dark_mode = \FreeFormCertificate\Settings\SettingsReader::get( 'dark_mode', 'off' );

		if ( 'off' === $dark_mode && ! $always ) {
			return;
		}

		$s = self::asset_suffix();
		wp_enqueue_script(
			'ffc-dark-mode',
			FFC_PLUGIN_URL . "assets/js/ffc-dark-mode{$s}.js",
			array(),
			FFC_VERSION,
			false
		);
		wp_localize_script(
			'ffc-dark-mode',
			'ffcDarkMode',
			array(
				'mode' => $dark_mode,
			)
		);
	}
}

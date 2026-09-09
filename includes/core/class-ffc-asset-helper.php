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
	 */
	public static function enqueue_dark_mode(): void {
		$dark_mode = \FreeFormCertificate\Settings\SettingsReader::get( 'dark_mode', 'off' );

		if ( 'off' === $dark_mode ) {
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

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
 * @since   6.11.3
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
	 * Enqueue dark mode script if enabled
	 *
	 * Shared between admin and frontend to avoid duplicate logic.
	 *
	 * @since 4.6.17
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

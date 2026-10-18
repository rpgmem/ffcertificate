<?php
/**
 * AssetRegistry
 *
 * Registers the scripts the plugin's screens share, so an enqueue elsewhere
 * can name a handle without re-declaring where its file lives (#1924).
 *
 * WHY THIS IS NOT IN LOADER ANY MORE
 *
 * `Loader` is the composition root: it wires modules, cron and capability
 * lifecycle. It also registered these scripts, and the registrations drifted
 * the way a second copy always does -- `ffcCoreConfig` was built in four
 * places until #1920 gave it one builder, and on an FFC admin screen it was
 * still localized twice, once here and once by `AdminAssetsManager`. One
 * class registers; everyone else enqueues by handle.
 *
 * Registration only. Nothing here enqueues: each screen and shortcode still
 * decides what it loads.
 *
 * @package FreeFormCertificate\Core
 * @since 6.41.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registration of the shared front-end and admin scripts.
 */
final class AssetRegistry {

	/**
	 * Hook the two registrations.
	 *
	 * Front end at the default priority of `wp_enqueue_scripts`: shortcodes
	 * enqueue while the content renders, well after that hook, so no earlier
	 * slot is needed.
	 *
	 * Admin at priority 1 of `admin_enqueue_scripts`, so the handles are
	 * resolvable before any module's own enqueue runs at the default 10.
	 * Every admin request, not only FFC screens: the URL Shortener metabox
	 * renders on ordinary post and page screens and depends on `ffc-core`,
	 * which used to be registered only when the screen was an FFC one, so the
	 * dependency was dropped in silence and `window.FFC` was undefined there
	 * (6.6.9).
	 *
	 * @return void
	 */
	public static function init(): void {
		\add_action( 'wp_enqueue_scripts', array( self::class, 'register_frontend' ) );
		\add_action( 'admin_enqueue_scripts', array( self::class, 'register_admin' ), 1 );
	}

	/**
	 * Register the scripts shortcodes depend on.
	 *
	 * @return void
	 */
	public static function register_frontend(): void {
		$s = AssetHelper::asset_suffix();

		self::register_core();

		// ffc-core dep: the helpers build their notices with FFC.notice() (#1842)
		// and their field messages with FFC.fieldMessage() (#1844).
		// ffc-field-behaviours dep: the CPF/RF mask and the CPF and RF
		// validators are the ones every screen shares (#1920).
		\wp_register_script( 'ffc-field-behaviours', FFC_PLUGIN_URL . "assets/js/ffc-field-behaviours{$s}.js", array( 'jquery', 'ffc-core' ), FFC_VERSION, true );
		\wp_register_script( 'ffc-rate-limit', FFC_PLUGIN_URL . "assets/js/ffc-frontend-helpers{$s}.js", array( 'jquery', 'ffc-core', 'ffc-field-behaviours' ), FFC_VERSION, true );

		// Captcha widget + glue (#1053). Registered on every front-end request
		// and enqueued by the provider at render time, so it follows the
		// widget onto whichever surface renders it -- the certificate form,
		// the verification page, the public CSV download and the
		// self-scheduling booking form, the last of which is enqueued by a
		// different class entirely.
		Captcha\AltchaCaptcha::register_assets();

		// Dynamic fragments: refresh captcha + nonces on cached pages (v4.12.0).
		\wp_register_script( 'ffc-dynamic-fragments', FFC_PLUGIN_URL . "assets/js/ffc-dynamic-fragments{$s}.js", array(), FFC_VERSION, true );
		\wp_localize_script(
			'ffc-dynamic-fragments',
			'ffcDynamic',
			array(
				'ajaxUrl' => \admin_url( 'admin-ajax.php' ),
			)
		);
	}

	/**
	 * Register `ffc-core` on every admin request.
	 *
	 * A no-op when something registered it first, so its localization is
	 * never printed twice.
	 *
	 * @return void
	 */
	public static function register_admin(): void {
		if ( \wp_script_is( 'ffc-core', 'registered' ) ) {
			return;
		}

		self::register_core();
	}

	/**
	 * `ffc-core` and its configuration: `window.FFC.request()` and the
	 * module registry every other plugin script builds on.
	 *
	 * @return void
	 */
	private static function register_core(): void {
		$s = AssetHelper::asset_suffix();
		\wp_register_script( 'ffc-core', FFC_PLUGIN_URL . "assets/js/ffc-core{$s}.js", array( 'jquery' ), FFC_VERSION, true );
		\wp_localize_script( 'ffc-core', 'ffcCoreConfig', AssetHelper::core_script_config() );
	}
}

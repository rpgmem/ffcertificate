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
 * Registration, plus one way to enqueue what is registered. Each screen and
 * shortcode still decides what it loads; what it no longer decides is where
 * a shared script lives or what it depends on. Those were restated at every
 * site, and the restatements disagreed: four sites asked for
 * `ffc-pdf-generator` with two dependency lists and two paths, and WordPress
 * kept whichever registered first, so the answer depended on which screen
 * ran first (#1931).
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
	 * The scripts more than one screen enqueues: handle => array( path, deps ).
	 *
	 * The path is relative to the plugin directory and carries no suffix;
	 * `{$s}.js` is appended, so every one of them follows `SCRIPT_DEBUG`.
	 * The two vendored bundles are not here, because their file names carry
	 * a version rather than a suffix ({@see self::bundles()}).
	 *
	 * Dependencies are the union a script needs on every screen that loads
	 * it. Where one screen needs a script loaded BEFORE another for its own
	 * reasons, that screen enqueues it first; it is not added here, or every
	 * other screen would load it too.
	 *
	 * @var array<string, array{0: string, 1: list<string>}>
	 */
	private const SCRIPTS = array(
		'ffc-core'                  => array( 'assets/js/ffc-core', array( 'jquery' ) ),
		// ffc-core dep: the helpers build their notices with FFC.notice() (#1842)
		// and their field messages with FFC.fieldMessage() (#1844).
		// ffc-field-behaviours dep: the CPF/RF mask and the CPF and RF
		// validators are the ones every screen shares (#1920).
		'ffc-field-behaviours'      => array( 'assets/js/ffc-field-behaviours', array( 'jquery', 'ffc-core' ) ),
		'ffc-rate-limit'            => array( 'assets/js/ffc-frontend-helpers', array( 'jquery', 'ffc-core', 'ffc-field-behaviours' ) ),
		'ffc-batched-export'        => array( 'assets/js/ffc-batched-export', array( 'jquery', 'ffc-core' ) ),
		'ffc-admin-js'              => array( 'assets/js/ffc-admin', array( 'jquery', 'ffc-core', 'ffc-batched-export' ) ),
		'ffc-admin-pdf'             => array( 'assets/js/ffc-admin-pdf', array( 'jquery', 'ffc-core' ) ),
		'ffc-admin-autosave'        => array( 'assets/js/ffc-admin-autosave', array( 'jquery', 'ffc-core', 'ffc-admin-js' ) ),
		'ffc-admin-sections'        => array( 'assets/js/ffc-admin-sections', array() ),
		'ffc-section-collapse'      => array( 'assets/js/ffc-section-collapse', array( 'jquery' ) ),
		'ffc-email-restore-default' => array( 'assets/js/ffc-email-restore-default', array( 'jquery' ) ),
		'ffc-branding-media'        => array( 'assets/js/ffc-branding-media', array( 'jquery' ) ),
		'ffc-calendar-core'         => array( 'assets/js/ffc-calendar-core', array( 'jquery' ) ),
		'ffc-working-hours'         => array( 'assets/js/ffc-working-hours', array( 'jquery', 'ffc-core' ) ),
		'ffc-audience'              => array( 'assets/js/ffc-audience', array( 'jquery', 'ffc-core' ) ),
		// ffc-core dep: ffc-geofence-frontend.js calls FFC.request().
		'ffc-geofence-frontend'     => array( 'assets/js/ffc-geofence-frontend', array( 'jquery', 'ffc-core' ) ),
		// Validation modules split out of the former monolith. Each extends
		// window.FFCGeofence (Object.assign), so each loads after it.
		'ffc-geofence-datetime'     => array( 'assets/js/ffc-geofence-datetime', array( 'jquery', 'ffc-geofence-frontend' ) ),
		'ffc-geofence-gps'          => array( 'assets/js/ffc-geofence-gps', array( 'jquery', 'ffc-geofence-frontend' ) ),
		'ffc-geofence-preflight'    => array( 'assets/js/ffc-geofence-preflight', array( 'jquery', 'ffc-geofence-frontend' ) ),
		'ffc-qr-raster'             => array( 'assets/js/ffc-qr-raster', array() ),
		'ffc-qr-design'             => array( 'assets/js/ffc-qr-design', array( 'jquery', 'ffc-core' ) ),
		// `ffc-core` carries `window.FFC.request()`, which the QR-download click
		// handler calls; declaring it keeps the load order through JS-combining
		// cache plugins that would otherwise drop it from the chain (#367).
		'ffc-url-shortener-admin'   => array( 'assets/js/ffc-url-shortener-admin', array( 'jquery', 'ffc-core', 'ffc-admin-js', 'ffc-qr-raster' ) ),
		'ffc-pdf-generator'         => array( 'assets/js/ffc-pdf-generator', array( 'jquery', 'html2canvas', 'jspdf' ) ),
	);

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

		self::register_shared();

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
	 * Register the shared scripts on every admin request.
	 *
	 * @return void
	 */
	public static function register_admin(): void {
		self::register_shared();
	}

	/**
	 * Enqueue shared scripts by handle, registering them first.
	 *
	 * Registering here as well as on the hooks is what lets a caller that runs
	 * before `wp_enqueue_scripts` -- a standalone receipt page, a handler
	 * printing its own document -- name a handle without knowing whether the
	 * registration hook has fired. Only the handle and the shared scripts it
	 * depends on are registered, so a dependency is never dropped for being
	 * unknown.
	 *
	 * @param non-empty-string ...$handles Handles from {@see self::SCRIPTS}, or `html2canvas` / `jspdf`.
	 * @return void
	 */
	public static function enqueue_scripts( string ...$handles ): void {
		foreach ( $handles as $handle ) {
			self::register( $handle );
			\wp_enqueue_script( $handle );
		}
	}

	/**
	 * Register every shared script.
	 *
	 * @return void
	 */
	private static function register_shared(): void {
		foreach ( array_keys( self::SCRIPTS + self::bundles() ) as $handle ) {
			self::register( $handle );
		}
	}

	/**
	 * Register one shared script and the shared scripts it depends on.
	 *
	 * `wp_register_script()` refuses a handle that is already registered and
	 * says so by returning false, which is what keeps `ffcCoreConfig` from
	 * being localized twice: it is attached only by the call that registered.
	 *
	 * @param string $handle A handle; one this class does not know is left alone.
	 * @return void
	 */
	private static function register( string $handle ): void {
		$bundles = self::bundles();

		if ( isset( $bundles[ $handle ] ) ) {
			\wp_register_script( $handle, $bundles[ $handle ][0], array(), $bundles[ $handle ][1], true );
			return;
		}

		if ( ! isset( self::SCRIPTS[ $handle ] ) ) {
			return;
		}

		list( $path, $deps ) = self::SCRIPTS[ $handle ];

		foreach ( $deps as $dep ) {
			self::register( $dep );
		}

		$s = AssetHelper::asset_suffix();

		if ( \wp_register_script( $handle, FFC_PLUGIN_URL . $path . "{$s}.js", $deps, FFC_VERSION, true ) && 'ffc-core' === $handle ) {
			\wp_localize_script( 'ffc-core', 'ffcCoreConfig', AssetHelper::core_script_config() );
		}
	}

	/**
	 * The two vendored PDF bundles: handle => array( url, version ).
	 *
	 * The file name carries the version, so bumping the constant without
	 * renaming the file 404s loudly instead of serving the old bundle (#1203).
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	private static function bundles(): array {
		return array(
			'html2canvas' => array( FFC_PLUGIN_URL . 'libs/js/html2canvas-' . FFC_HTML2CANVAS_VERSION . '.min.js', FFC_HTML2CANVAS_VERSION ),
			'jspdf'       => array( FFC_PLUGIN_URL . 'libs/js/jspdf-' . FFC_JSPDF_VERSION . '.umd.min.js', FFC_JSPDF_VERSION ),
		);
	}
}

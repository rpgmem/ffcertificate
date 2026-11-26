<?php
/**
 * AppLook
 *
 * Offers the installable app (Site PWA) this plugin's look: the accent and
 * corners of Settings → Public Design, the light or dark scheme of Dark Mode,
 * and the root class that turns this plugin's screens dark inside the app.
 *
 * Site PWA asks for these through its `site_pwa_identity_sources`,
 * `site_pwa_scheme_sources` and `site_pwa_dark_root_classes` filters, so it
 * never needs to know this plugin's class names (#2873). Without Site PWA the
 * filters never run, and nothing here does either.
 *
 * @package FreeFormCertificate\Shortcodes
 * @since   6.45.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Shortcodes;

use FreeFormCertificate\Core\PublicDesign;
use FreeFormCertificate\Settings\SettingsReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers this plugin as a source of the app's look.
 */
final class AppLook {

	/**
	 * The id both sources carry. It is a stored value: Site PWA keeps it in its
	 * own settings as the source an administrator chose, so it never renames.
	 */
	public const SOURCE_ID = 'ffc';

	/**
	 * The root class this plugin's dark palette hangs off (`:root.ffc-dark-mode`).
	 */
	public const DARK_ROOT_CLASS = 'ffc-dark-mode';

	/**
	 * Dark Mode's values, in the app's words.
	 */
	private const SCHEMES = array(
		'off'  => 'light',
		'on'   => 'dark',
		'auto' => 'auto',
	);

	/**
	 * Hook the three filters.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'site_pwa_identity_sources', array( self::class, 'identity_sources' ) );
		add_filter( 'site_pwa_scheme_sources', array( self::class, 'scheme_sources' ) );
		add_filter( 'site_pwa_dark_root_classes', array( self::class, 'dark_root_classes' ) );
	}

	/**
	 * Add Public Design to the designs the app can take its accent and corners from.
	 *
	 * @param mixed $sources Sources by id.
	 * @return mixed
	 */
	public static function identity_sources( $sources ) {
		if ( ! is_array( $sources ) ) {
			return $sources;
		}
		$sources[ self::SOURCE_ID ] = array(
			'label' => __( 'Free Form Certificate: Public Design', 'ffcertificate' ),
			'read'  => array( self::class, 'identity' ),
		);
		return $sources;
	}

	/**
	 * Public Design's accent and corners, or null while it is off.
	 *
	 * `radius` is the app's cards and `control_radius` its buttons and fields,
	 * which here are the design's card corner and control corner.
	 *
	 * @return array{accent: string, radius: int, control_radius: int}|null
	 */
	public static function identity(): ?array {
		$design = PublicDesign::from_settings();
		if ( ! $design->enabled ) {
			return null;
		}
		return array(
			'accent'         => $design->accent,
			'radius'         => $design->card_radius,
			'control_radius' => $design->radius,
		);
	}

	/**
	 * Add Dark Mode to the choices the app's light or dark theme can follow.
	 *
	 * @param mixed $sources Sources by id.
	 * @return mixed
	 */
	public static function scheme_sources( $sources ) {
		if ( ! is_array( $sources ) ) {
			return $sources;
		}
		$sources[ self::SOURCE_ID ] = array(
			'label' => __( 'Free Form Certificate: Dark Mode', 'ffcertificate' ),
			'read'  => array( self::class, 'scheme' ),
		);
		return $sources;
	}

	/**
	 * Dark Mode as the app reads it: `light`, `dark` or `auto`, or null for a
	 * value it does not know.
	 *
	 * @return string|null
	 */
	public static function scheme(): ?string {
		$mode = SettingsReader::get( 'dark_mode', 'off' );
		return is_string( $mode ) && isset( self::SCHEMES[ $mode ] ) ? self::SCHEMES[ $mode ] : null;
	}

	/**
	 * Add this plugin's dark root class to the ones the app sets when it turns dark,
	 * so the screens this plugin draws inside the app turn dark with it.
	 *
	 * @param mixed $classes Class names.
	 * @return mixed
	 */
	public static function dark_root_classes( $classes ) {
		if ( ! is_array( $classes ) ) {
			return $classes;
		}
		if ( ! in_array( self::DARK_ROOT_CLASS, $classes, true ) ) {
			$classes[] = self::DARK_ROOT_CLASS;
		}
		return $classes;
	}
}

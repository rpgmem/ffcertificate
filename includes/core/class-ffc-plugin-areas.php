<?php
/**
 * PluginAreas
 *
 * The plugin's areas — one per top-level admin menu — with the menu item
 * WordPress draws for each, its icon and its name (#1640, #1641).
 *
 * @package FreeFormCertificate\Core
 * @since   6.35.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One name and one icon per area, read everywhere the area is shown.
 *
 * The admin menu, Settings → Modules and the documentation tree each named
 * and drew the areas their own way: up to fourteen names for one area, and
 * two icons for most. This is the one place both are decided, so the three
 * cannot disagree; `PluginAreasAgreementTest` holds every consumer to it.
 */
final class PluginAreas {

	/**
	 * Each area's admin-menu item and icon, in menu order.
	 *
	 * `menu` is the `id` WordPress gives the area's `<li>` in `#adminmenu` —
	 * `toplevel_page_` plus the menu slug, or `menu-posts-` plus the post type.
	 * The slugs are literals because Core must not reach into the modules; the
	 * guard holds them to each module's own constant. `icon` is a registry
	 * class ({@see Icons::classes()}).
	 *
	 * @var array<string, array{menu: string, icon: string}>
	 */
	private const AREAS = array(
		'certificates'   => array(
			'menu' => 'menu-posts-ffc_form',
			'icon' => 'award',
		),
		'scheduling'     => array(
			'menu' => 'toplevel_page_ffc-scheduling',
			'icon' => 'calendar',
		),
		'reregistration' => array(
			'menu' => 'toplevel_page_ffc-reregistration',
			'icon' => 'user-check',
		),
		'recruitment'    => array(
			'menu' => 'toplevel_page_ffc-recruitment',
			'icon' => 'users',
		),
		'url_shortener'  => array(
			'menu' => 'toplevel_page_ffc-short-urls',
			'icon' => 'link',
		),
		'date_messages'  => array(
			'menu' => 'toplevel_page_ffc-date-messages',
			'icon' => 'email',
		),
		'settings'       => array(
			'menu' => 'toplevel_page_ffc-settings',
			'icon' => 'settings',
		),
	);

	/**
	 * Every area, in menu order.
	 *
	 * @return array<string, array{menu: string, icon: string}>
	 */
	public static function all(): array {
		return self::AREAS;
	}

	/**
	 * The `.ffc-icon-*` class of an area, '' for an unknown one.
	 *
	 * @param string $area An area key.
	 * @return string
	 */
	public static function icon_class( string $area ): string {
		return isset( self::AREAS[ $area ] ) ? 'ffc-icon-' . self::AREAS[ $area ]['icon'] : '';
	}

	/**
	 * The name of an area, translated; '' for an unknown one.
	 *
	 * The name an operator meets in the menu is the name every other screen
	 * uses for the same area. A switch of literals rather than a map, so each
	 * string is a whole literal the translation extractor can read.
	 *
	 * @param string $area An area key.
	 * @return string
	 */
	public static function label( string $area ): string {
		switch ( $area ) {
			case 'certificates':
				return __( 'Certificates', 'ffcertificate' );
			case 'scheduling':
				return __( 'Scheduling', 'ffcertificate' );
			case 'reregistration':
				return __( 'Reregistration', 'ffcertificate' );
			case 'recruitment':
				return __( 'Recruitment', 'ffcertificate' );
			case 'url_shortener':
				return __( 'Short URLs', 'ffcertificate' );
			case 'date_messages':
				return __( 'Date Messages', 'ffcertificate' );
			case 'settings':
				return __( 'FFC Settings', 'ffcertificate' );
		}
		return '';
	}
}

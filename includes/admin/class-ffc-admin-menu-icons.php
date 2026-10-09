<?php
/**
 * AdminMenuIcons
 *
 * Draws the plugin's admin-menu icons from the icon registry (#1640), so a
 * module wears the same icon in the WordPress menu as in the documentation
 * and on Settings → Modules.
 *
 * @package FreeFormCertificate\Admin
 * @since   6.35.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\Icons;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Attaches the registry's menu-icon CSS to every admin screen.
 */
final class AdminMenuIcons {

	/**
	 * Hook the CSS onto every admin screen.
	 *
	 * `admin_enqueue_scripts` at the default priority runs before
	 * `admin_print_styles` prints the core `admin-menu` sheet, so the inline
	 * style lands with it. The menu is drawn on every admin screen, so there
	 * is no screen gate — the same reason `print_menu_separator_css()` uses.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'print_css' ) );
	}

	/**
	 * Attach the menu-icon rules to the core `admin-menu` stylesheet.
	 *
	 * @return void
	 */
	public static function print_css(): void {
		wp_add_inline_style( 'admin-menu', Icons::menu_stylesheet() );
	}
}

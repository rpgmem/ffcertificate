<?php
/**
 * Captcha Settings Tab
 *
 * @package FreeFormCertificate\Settings\Tabs
 * @since   6.23.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Settings\Tabs;

use FreeFormCertificate\Settings\SettingsTab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything about the challenge that guards the public forms (#1053).
 *
 * Its own tab rather than a box on Rate Limit: the captcha now has a strategy
 * to choose and a widget to configure, and the choice carries consequences
 * — one mode cannot run without HTTPS — that need room to be explained rather
 * than a tooltip.
 */
class TabCaptcha extends SettingsTab {

	/**
	 * Init.
	 */
	protected function init(): void {
		$this->tab_id    = 'captcha';
		$this->tab_group = 'security';
		$this->tab_title = __( 'Captcha', 'ffcertificate' );
		// Not the shield: Rate Limit already uses it, and two identical icons
		// in the same group make the sidebar harder to scan than a plain list.
		// The robot is the one glyph that reads as "captcha" rather than as
		// security in general — it is the challenge's own idiom.
		$this->tab_icon  = 'ffc-icon-robot';
		$this->tab_order = 35;

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Show or hide the widget panel as the mode changes.
	 *
	 * Enhancement only — the panel's initial state is rendered server-side,
	 * so the screen is correct before this runs and if it never does.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( ! $this->should_enqueue_on( $hook ) ) {
			return;
		}

		$suffix = \FreeFormCertificate\Core\AssetHelper::asset_suffix();

		wp_enqueue_script(
			'ffc-admin-captcha-settings',
			FFC_PLUGIN_URL . "assets/js/ffc-admin-captcha-settings{$suffix}.js",
			array(),
			FFC_VERSION,
			true
		);
	}

	/**
	 * Render the tab.
	 */
	public function render(): void {
		$view_file = FFC_PLUGIN_DIR . 'includes/settings/views/ffc-tab-captcha.php';

		if ( file_exists( $view_file ) ) {
			$settings = $this;
			include $view_file;
		} else {
			wp_admin_notice(
				esc_html__( 'Captcha settings view file not found.', 'ffcertificate' ),
				array( 'type' => 'error' )
			);
		}
	}
}

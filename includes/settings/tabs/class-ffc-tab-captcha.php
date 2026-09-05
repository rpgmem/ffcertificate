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
		$this->tab_icon  = 'ffc-icon-shield';
		$this->tab_order = 35;
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

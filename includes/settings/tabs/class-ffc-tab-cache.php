<?php
/**
 * Cache & Performance Settings Tab
 *
 * Contains Form Cache and QR Code Cache settings
 *
 * @package FreeFormCertificate\Settings\Tabs
 * @since 4.6.16
 */

declare(strict_types=1);

namespace FreeFormCertificate\Settings\Tabs;

use FreeFormCertificate\Settings\SettingsTab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache layer for tab data.
 */
class TabCache extends SettingsTab {

	/**
	 * Init.
	 */
	protected function init(): void {
		$this->tab_id    = 'cache';
		$this->tab_title = __( 'Cache', 'ffcertificate' );
		$this->tab_icon  = 'ffc-icon-package';
		$this->tab_order = 30;

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue auto-save infrastructure when this tab is active so the
	 * `.ffc-toggle` switches in the view persist inline via the
	 * `ffc_update_setting` endpoint.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( 'ffc_form_page_ffc-settings' !== $hook ) {
			return;
		}
		if ( ! $this->is_active() ) {
			return;
		}
		$this->enqueue_autosave_infra();
	}

	/**
	 * Render.
	 */
	public function render(): void {
		$view_file = FFC_PLUGIN_DIR . 'includes/settings/views/ffc-tab-cache.php';

		if ( file_exists( $view_file ) ) {
			$settings = $this;
			include $view_file;
		} else {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Cache settings view file not found.', 'ffcertificate' );
			echo '</p></div>';
		}
	}
}

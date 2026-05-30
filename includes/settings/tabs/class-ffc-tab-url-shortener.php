<?php
/**
 * URL Shortener Settings Tab
 *
 * @package FreeFormCertificate\Settings\Tabs
 * @since 5.1.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Settings\Tabs;

use FreeFormCertificate\Settings\SettingsTab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tab Url Shortener settings tab.
 */
class TabUrlShortener extends SettingsTab {

	/**
	 * Init.
	 */
	protected function init(): void {
		$this->tab_id    = 'url_shortener';
		$this->tab_title = __( 'URL Shortener', 'ffcertificate' );
		$this->tab_icon  = 'ffc-icon-link';
		$this->tab_order = 35;

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue autosave infra so the two `.ffc-toggle` switches on this tab
	 * (url_shortener_enabled / url_shortener_auto_create) bind to the
	 * incremental settings AJAX endpoint.
	 *
	 * @param string $hook Hook name.
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( 'ffc_form_page_ffc-settings' !== $hook ) {
			return;
		}
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab parameter for conditional script loading.
		if ( 'url_shortener' === $active_tab ) {
			$this->enqueue_autosave_infra();
		}
	}

	/**
	 * Render.
	 */
	public function render(): void {
		$view_file = FFC_PLUGIN_DIR . 'includes/settings/views/ffc-tab-url-shortener.php';

		if ( file_exists( $view_file ) ) {
			$settings = $this;
			include $view_file;
		} else {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'URL Shortener settings view file not found.', 'ffcertificate' );
			echo '</p></div>';
		}
	}
}

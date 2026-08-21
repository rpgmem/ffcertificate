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
		$this->tab_group = 'tools';
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
		if ( ! $this->should_enqueue_on( $hook ) ) {
			return;
		}

		$this->enqueue_autosave_infra();

		$suffix = \FreeFormCertificate\Core\AssetHelper::asset_suffix();
		wp_enqueue_script(
			'ffc-url-shortener-settings',
			FFC_PLUGIN_URL . "assets/js/ffc-url-shortener-settings{$suffix}.js",
			array( 'jquery', 'ffc-core' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			'ffc-url-shortener-settings',
			'ffcUrlShortenerSettings',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'i18n'    => array(
					'working'       => __( 'Generating…', 'ffcertificate' ),
					'done'          => __( 'Done.', 'ffcertificate' ),
					'createdSuffix' => __( 'short URLs created.', 'ffcertificate' ),
					'error'         => __( 'An error occurred.', 'ffcertificate' ),
				),
			)
		);
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
			wp_admin_notice(
				esc_html__( 'URL Shortener settings view file not found.', 'ffcertificate' ),
				array( 'type' => 'error' )
			);
		}
	}
}

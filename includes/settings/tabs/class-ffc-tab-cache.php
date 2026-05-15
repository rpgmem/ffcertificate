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

		// Inline AJAX for the Warm / Clear cache buttons. Depends on
		// ffc-core (FFC.request) + ffc-admin-js (showNotification).
		$s = \FreeFormCertificate\Core\Utils::asset_suffix();
		wp_enqueue_script(
			'ffc-cache-actions',
			FFC_PLUGIN_URL . "assets/js/ffc-cache-actions{$s}.js",
			array( 'jquery', 'ffc-core', 'ffc-admin-js' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			'ffc-cache-actions',
			'ffcCacheActions',
			array(
				// Each action has its own nonce — the endpoint guard
				// calls `check_ajax_referer( $action, 'nonce' )` and the
				// global FFC.config.nonce (ffc_admin_pdf_nonce) doesn't
				// verify against either action.
				'nonces'  => array(
					\FreeFormCertificate\Admin\CacheActionsAjaxEndpoint::ACTION_WARM  => wp_create_nonce( \FreeFormCertificate\Admin\CacheActionsAjaxEndpoint::ACTION_WARM ),
					\FreeFormCertificate\Admin\CacheActionsAjaxEndpoint::ACTION_CLEAR => wp_create_nonce( \FreeFormCertificate\Admin\CacheActionsAjaxEndpoint::ACTION_CLEAR ),
				),
				'strings' => array(
					'working'      => __( 'Working…', 'ffcertificate' ),
					'success'      => __( 'Done.', 'ffcertificate' ),
					'error'        => __( 'Action failed.', 'ffcertificate' ),
					'confirmClear' => __( 'Clear all cache?', 'ffcertificate' ),
				),
			)
		);
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

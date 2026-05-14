<?php
/**
 * Advanced Settings Tab
 *
 * Contains Activity Log, Debug Settings, and Danger Zone
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
 * Tab Advanced settings tab.
 */
class TabAdvanced extends SettingsTab {

	/**
	 * Init.
	 */
	protected function init(): void {
		$this->tab_id    = 'advanced';
		$this->tab_title = __( 'Advanced', 'ffcertificate' );
		$this->tab_icon  = 'ffc-icon-settings';
		$this->tab_order = 70;

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue auto-save infrastructure when this tab is active. Powers
	 * the `.ffc-toggle` switches for the activity-log master flag and
	 * the per-module debug flags.
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
		$view_file = FFC_PLUGIN_DIR . 'includes/settings/views/ffc-tab-advanced.php';

		if ( file_exists( $view_file ) ) {
			$settings = $this;
			include $view_file;
		} else {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Advanced settings view file not found.', 'ffcertificate' );
			echo '</p></div>';
		}
	}
}

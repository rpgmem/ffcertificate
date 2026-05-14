<?php
/**
 * General Settings Tab
 *
 * @package FreeFormCertificate\Settings\Tabs
 * @since 2.10.0
 * @version 4.6.16 - Simplified: debug/activity/danger/cache moved to dedicated tabs
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

declare(strict_types=1);

namespace FreeFormCertificate\Settings\Tabs;

use FreeFormCertificate\Settings\SettingsTab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tab General settings tab.
 */
class TabGeneral extends SettingsTab {

	/**
	 * Init.
	 */
	protected function init(): void {
		$this->tab_id    = 'general';
		$this->tab_title = __( 'General', 'ffcertificate' );
		$this->tab_icon  = 'ffc-icon-settings';
		$this->tab_order = 10;

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue auto-save infrastructure when this tab is active. Powers
	 * the inline persistence on the General tab's text/select/number
	 * fields (date format, dark mode, QR defaults, etc.).
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
		$view_file = FFC_PLUGIN_DIR . 'includes/settings/views/ffc-tab-general.php';

		if ( file_exists( $view_file ) ) {
			$settings = $this;
			include $view_file;
		} else {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'General settings view file not found.', 'ffcertificate' );
			echo '</p></div>';
		}
	}
}

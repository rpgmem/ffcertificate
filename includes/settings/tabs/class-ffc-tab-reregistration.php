<?php
/**
 * Reregistration Settings Tab
 *
 * Hosts the admin-editable Divisão → Setor map used by the reregistration
 * `divisao_setor` dependent-select field.
 *
 * @package FreeFormCertificate\Settings\Tabs
 * @since 6.7.8
 */

declare(strict_types=1);

namespace FreeFormCertificate\Settings\Tabs;

use FreeFormCertificate\Settings\SettingsTab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reregistration settings tab.
 */
class TabReregistration extends SettingsTab {

	/**
	 * Init.
	 */
	protected function init(): void {
		$this->tab_id    = 'reregistration';
		$this->tab_title = __( 'Reregistration', 'ffcertificate' );
		$this->tab_icon  = 'ffc-icon-list';
		$this->tab_order = 55;
	}

	/**
	 * Render.
	 */
	public function render(): void {
		$view_file = FFC_PLUGIN_DIR . 'includes/settings/views/ffc-tab-reregistration.php';

		if ( file_exists( $view_file ) ) {
			$settings = $this;
			include $view_file;
		} else {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Reregistration settings view file not found.', 'ffcertificate' );
			echo '</p></div>';
		}
	}
}

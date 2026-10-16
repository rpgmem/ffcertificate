<?php
/**
 * Documentation Tab
 *
 * @package FreeFormCertificate\Settings\Tabs
 * @since 2.10.0
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace
 */

declare(strict_types=1);

namespace FreeFormCertificate\Settings\Tabs;

use FreeFormCertificate\Settings\SettingsTab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tab Documentation settings tab.
 */
class TabDocumentation extends SettingsTab {

	/**
	 * Init.
	 */
	protected function init(): void {
		$this->tab_id    = 'documentation';
		$this->tab_group = 'system';
		$this->tab_title = __( 'Documentation', 'ffcertificate' );
		$this->tab_icon  = 'ffc-icon-doc';
		$this->tab_order = 90;
	}

	/**
	 * Render.
	 */
	public function render(): void {
		// Include view file.
		$view_file = $this->view_path( 'ffc-tab-documentation.php' );

		if ( file_exists( $view_file ) ) {
			include $view_file;
		} else {
			\FreeFormCertificate\Core\AdminNotice::render(
				'error',
				esc_html__( 'Documentation view file not found.', 'ffcertificate' )
			);
		}
	}
}

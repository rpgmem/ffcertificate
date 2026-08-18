<?php
/**
 * Data Migrations Tab
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
 * Tab Migrations settings tab.
 */
class TabMigrations extends SettingsTab {

	/**
	 * Init.
	 */
	protected function init(): void {
		$this->tab_id    = 'migrations';
		$this->tab_title = __( 'Data Migrations', 'ffcertificate' );
		$this->tab_icon  = 'ffc-icon-sync';
		$this->tab_order = 80;

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue autosave infra so the URL-cleanup criteria toggles on this
	 * tab persist incrementally (no more "lost selection after preview").
	 *
	 * @param string $hook Hook name.
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( ! $this->should_enqueue_on( $hook ) ) {
			return;
		}
		$this->enqueue_autosave_infra();
	}

	/**
	 * Render.
	 */
	public function render(): void {
		// Include view file.
		$view_file = FFC_PLUGIN_DIR . 'includes/settings/views/ffc-tab-migrations.php';

		if ( file_exists( $view_file ) ) {
			include $view_file;
		} else {
			wp_admin_notice(
				esc_html__( 'Migrations view file not found.', 'ffcertificate' ),
				array( 'type' => 'error' )
			);
		}
	}
}

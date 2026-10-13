<?php
/**
 * TabCertificates
 *
 * Settings that decide what every certificate must carry (#1734). The
 * required-tag list lived on the Advanced tab, beside debug flags and the
 * danger zone, where an operator configuring certificates does not look.
 *
 * @package FreeFormCertificate\Settings\Tabs
 * @since 6.39.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Settings\Tabs;

use FreeFormCertificate\Settings\SettingsTab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Certificates settings tab.
 */
class TabCertificates extends SettingsTab {

	/**
	 * Initialize tab properties.
	 */
	protected function init(): void {
		$this->tab_id    = 'certificates';
		$this->tab_group = 'content';
		$this->tab_title = __( 'Certificates', 'ffcertificate' );
		$this->tab_icon  = 'ffc-icon-award';
		$this->tab_order = 14;

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Load the autosave widget on this tab.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( ! $this->should_enqueue_on( $hook ) ) {
			return;
		}
		$this->enqueue_autosave_infra();
	}

	/**
	 * Render the tab.
	 */
	public function render(): void {
		$view_file = FFC_PLUGIN_DIR . 'includes/settings/views/ffc-tab-certificates.php';

		if ( file_exists( $view_file ) ) {
			include $view_file;
		} else {
			wp_admin_notice(
				esc_html__( 'Certificates settings view file not found.', 'ffcertificate' ),
				array( 'type' => 'error' )
			);
		}
	}
}

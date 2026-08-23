<?php
/**
 * SMTP Settings Tab
 *
 * Transport only: the master "enable email sending" switch, the new-user
 * welcome-email controls and the SMTP server configuration. The email chrome
 * (Email Model) and the per-email texts hub live in their own sibling tabs
 * since the #976 split; see {@see TabEmailModel} and {@see TabEmailTexts}.
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
 * Tab S M T P settings tab.
 */
class TabSMTP extends SettingsTab {

	/**
	 * Init.
	 */
	protected function init(): void {
		$this->tab_id    = 'smtp';
		$this->tab_group = 'communication';
		$this->tab_title = __( 'SMTP', 'ffcertificate' );
		$this->tab_icon  = 'ffc-icon-email';
		$this->tab_order = 20;

		// Enqueue scripts for this tab.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue scripts for the SMTP (transport) settings tab.
	 *
	 * @param string $hook Hook name.
	 */
	public function enqueue_scripts( string $hook ): void {
		// Only load on the FFC Settings page with this tab active.
		if ( ! $this->should_enqueue_on( $hook ) ) {
			return;
		}

		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();
		wp_enqueue_script(
			'ffc-smtp-settings',
			FFC_PLUGIN_URL . "assets/js/ffc-smtp-settings{$s}.js",
			array( 'jquery' ),
			FFC_VERSION,
			true
		);
		// Powers the `.ffc-toggle` switch on `disable_all_emails`.
		$this->enqueue_autosave_infra();
	}

	/**
	 * Render.
	 */
	public function render(): void {
		// Include view file.
		$view_file = FFC_PLUGIN_DIR . 'includes/settings/views/ffc-tab-smtp.php';

		if ( file_exists( $view_file ) ) {
			$settings = $this;
			include $view_file;
		} else {
			wp_admin_notice(
				esc_html__( 'SMTP settings view file not found.', 'ffcertificate' ),
				array( 'type' => 'error' )
			);
		}
	}
}

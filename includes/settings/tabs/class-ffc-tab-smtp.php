<?php
/**
 * SMTP Settings Tab
 *
 * @package FreeFormCertificate\Settings\Tabs
 * @since 2.10.0
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
 * Tab S M T P settings tab.
 */
class TabSMTP extends SettingsTab {

	/**
	 * Init.
	 */
	protected function init(): void {
		$this->tab_id    = 'smtp';
		$this->tab_title = __( 'SMTP', 'ffcertificate' );
		$this->tab_icon  = 'ffc-icon-email';
		$this->tab_order = 20;

		// Enqueue scripts for this tab.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue scripts for SMTP settings page
	 *
	 * @param string $hook Hook name.
	 */
	public function enqueue_scripts( string $hook ): void {
		// Only load on settings page with this tab active.
		if ( 'ffc_form_page_ffc-settings' !== $hook ) {
			return;
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab parameter for conditional script loading.
		if ( 'smtp' === $active_tab ) {
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

			// "Email Model" box: color pickers, media uploader, live preview.
			wp_enqueue_media();
			wp_enqueue_style( 'wp-color-picker' );
			wp_enqueue_style(
				'ffc-email-model',
				FFC_PLUGIN_URL . "assets/css/ffc-email-model{$s}.css",
				array(),
				FFC_VERSION
			);
			wp_enqueue_script(
				'ffc-email-model',
				FFC_PLUGIN_URL . "assets/js/ffc-email-model{$s}.js",
				array( 'jquery', 'wp-color-picker' ),
				FFC_VERSION,
				true
			);
			wp_localize_script(
				'ffc-email-model',
				'ffcEmailModel',
				array(
					'defaults'       => \FreeFormCertificate\Core\EmailTemplateOptions::defaults(),
					'fontStacks'     => \FreeFormCertificate\Core\EmailTemplateOptions::font_stacks(),
					'tokens'         => \FreeFormCertificate\Core\EmailTemplateOptions::footer_tokens( array( 'recipient' => 'user@example.com' ) ),
					'siteName'       => get_bloginfo( 'name' ),
					'sampleTitle'    => __( 'Sample email', 'ffcertificate' ),
					'sampleBody'     => __( 'This is how your plugin emails will look with the current model.', 'ffcertificate' ),
					'sampleLink'     => __( 'A sample link', 'ffcertificate' ),
					'chooseLogo'     => __( 'Select image', 'ffcertificate' ),
					'confirmRestore' => __( 'Restore all Email Model fields to their defaults? Unsaved changes will be lost.', 'ffcertificate' ),
				)
			);
		}
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
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'SMTP settings view file not found.', 'ffcertificate' );
			echo '</p></div>';
		}
	}
}

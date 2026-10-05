<?php
/**
 * QR Code Settings Tab
 *
 * @package FreeFormCertificate\Settings\Tabs
 * @since 6.34.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Settings\Tabs;

use FreeFormCertificate\Admin\QrDesignPreviewAjaxEndpoint;
use FreeFormCertificate\Settings\SettingsTab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The global QR code defaults and design (#1563).
 *
 * Holds the generation defaults that used to sit on the General tab -- moved,
 * not mirrored, because `SettingsAutosaveFieldPlacementTest` refuses one
 * autosave key on two tabs -- and the design every applied surface draws
 * with: certificates and short URLs, each switched on separately.
 */
class TabQrCode extends SettingsTab {

	/**
	 * Script handle of the live preview.
	 */
	private const SCRIPT_HANDLE = 'ffc-qr-design';

	/**
	 * Init.
	 */
	protected function init(): void {
		$this->tab_id    = 'qr_code';
		$this->tab_group = 'content';
		$this->tab_title = __( 'QR Code', 'ffcertificate' );
		$this->tab_icon  = 'ffc-icon-phone';
		$this->tab_order = 25;

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue the autosave widget (toggles and defaults) and the preview.
	 *
	 * @param string $hook Hook name.
	 * @return void
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( ! $this->should_enqueue_on( $hook ) ) {
			return;
		}

		$this->enqueue_autosave_infra();

		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			FFC_PLUGIN_URL . "assets/js/ffc-qr-design{$s}.js",
			array( 'jquery', 'ffc-core' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			self::SCRIPT_HANDLE,
			'ffcQrDesign',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => QrDesignPreviewAjaxEndpoint::AJAX_ACTION,
				'nonce'   => wp_create_nonce( QrDesignPreviewAjaxEndpoint::AJAX_ACTION ),
				'i18n'    => array(
					/* translators: %s: contrast ratio, e.g. 3.2 */
					'lowContrast' => __( 'Low contrast (%s:1). Phones may fail to read this code; aim for 4:1 or more.', 'ffcertificate' ),
					'inverted'    => __( 'The modules are lighter than the background. Most readers cannot scan an inverted code.', 'ffcertificate' ),
					'ok'          => __( 'Readable: contrast and colours are fine.', 'ffcertificate' ),
					'error'       => __( 'The preview could not be drawn.', 'ffcertificate' ),
				),
			)
		);
	}

	/**
	 * Render.
	 */
	public function render(): void {
		$view_file = FFC_PLUGIN_DIR . 'includes/settings/views/ffc-tab-qr-code.php';

		if ( file_exists( $view_file ) ) {
			$settings = $this;
			include $view_file;
		} else {
			wp_admin_notice(
				esc_html__( 'QR Code settings view file not found.', 'ffcertificate' ),
				array( 'type' => 'error' )
			);
		}
	}
}

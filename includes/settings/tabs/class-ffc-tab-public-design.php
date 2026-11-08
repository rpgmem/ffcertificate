<?php
/**
 * Public Design Settings Tab
 *
 * @package FreeFormCertificate\Settings\Tabs
 * @since 6.37.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Settings\Tabs;

use FreeFormCertificate\Settings\SettingsTab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The look of the public screens: an accent, borders, hints, errors,
 * corners, the form width and the font (`Core\PublicDesign`).
 */
class TabPublicDesign extends SettingsTab {

	/**
	 * Init.
	 */
	protected function init(): void {
		$this->tab_id    = 'public_design';
		$this->tab_group = 'general';
		$this->tab_title = __( 'Public Design', 'ffcertificate' );
		$this->tab_icon  = 'ffc-icon-palette';
		$this->tab_order = 15;

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue the autosave widget, which saves the switch the moment it is
	 * flipped, and the live contrast check.
	 *
	 * @param string $hook Hook name.
	 * @return void
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( ! $this->should_enqueue_on( $hook ) ) {
			return;
		}
		$this->enqueue_autosave_infra();

		// The live contrast warning and the colour inputs that follow the
		// switch (#2341). `ffc-core` draws the notice.
		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();
		wp_enqueue_script(
			'ffc-admin-public-design',
			FFC_PLUGIN_URL . "assets/js/ffc-admin-public-design{$s}.js",
			array( 'jquery', 'ffc-core' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			'ffc-admin-public-design',
			'ffcPublicDesign',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => \FreeFormCertificate\Admin\PublicDesignCheckEndpoint::ACTION,
				'nonce'   => wp_create_nonce( \FreeFormCertificate\Admin\PublicDesignCheckEndpoint::ACTION ),
			)
		);
	}

	/**
	 * Render.
	 */
	public function render(): void {
		$view_file = $this->view_path( 'ffc-tab-public-design.php' );

		if ( file_exists( $view_file ) ) {
			include $view_file;
		} else {
			\FreeFormCertificate\Core\AdminNotice::render(
				'error',
				esc_html__( 'Public Design settings view file not found.', 'ffcertificate' )
			);
		}
	}
}

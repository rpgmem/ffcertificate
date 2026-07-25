<?php
/**
 * Modules Settings Tab
 *
 * Home for the per-module enable/disable controls. Scaffolded when the
 * Settings surface was promoted to a dedicated top-level menu (it can no
 * longer live inside the `ffc_form` CPT menu, since a "disable the
 * Certificate module" toggle cannot sit inside the very menu it governs).
 * The toggle controls themselves land in a follow-up phase; this phase
 * establishes the tab + its module-agnostic home.
 *
 * @package FreeFormCertificate\Settings\Tabs
 * @since 6.16.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Settings\Tabs;

use FreeFormCertificate\Settings\SettingsTab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Modules settings tab.
 */
class TabModulos extends SettingsTab {

	/**
	 * Init.
	 */
	protected function init(): void {
		$this->tab_id    = 'modulos';
		$this->tab_title = __( 'Modules', 'ffcertificate' );
		$this->tab_icon  = 'ffc-icon-package';
		$this->tab_order = 10;

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue the settings-autosave infra so the per-module `.ffc-toggle`
	 * switches bind to the incremental settings AJAX endpoint.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( 'toplevel_page_ffc-settings' !== $hook ) {
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
		$view_file = FFC_PLUGIN_DIR . 'includes/settings/views/ffc-tab-modulos.php';

		if ( file_exists( $view_file ) ) {
			$settings = $this;
			include $view_file;
		} else {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Modules settings view file not found.', 'ffcertificate' );
			echo '</p></div>';
		}
	}
}

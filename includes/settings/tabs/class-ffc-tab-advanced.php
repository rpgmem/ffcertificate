<?php
/**
 * Advanced Settings Tab
 *
 * Contains Activity Log, Debug Settings, and Danger Zone
 *
 * @package FreeFormCertificate\Settings\Tabs
 * @since 4.6.16
 */

declare(strict_types=1);

namespace FreeFormCertificate\Settings\Tabs;

use FreeFormCertificate\Settings\SettingsTab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tab Advanced settings tab.
 */
class TabAdvanced extends SettingsTab {

	/**
	 * Init.
	 */
	protected function init(): void {
		$this->tab_id    = 'advanced';
		$this->tab_title = __( 'Advanced', 'ffcertificate' );
		$this->tab_icon  = 'ffc-icon-settings';
		$this->tab_order = 70;

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue auto-save infrastructure when this tab is active. Powers
	 * the `.ffc-toggle` switches for the activity-log master flag and
	 * the per-module debug flags.
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

		// FFC_ENCRYPTION_KEY suggestion generator for the Encryption Key
		// Health card (#851). Pure client-side: the key is generated in the
		// browser and never saved or sent. Self-guards when the card doesn't
		// render the suggestion block (already decoupled).
		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();
		wp_enqueue_script(
			'ffc-encryption-key-suggest',
			FFC_PLUGIN_URL . "assets/js/ffc-encryption-key-suggest{$s}.js",
			array(),
			FFC_VERSION,
			true
		);
		$ffc_version = defined( 'FFC_VERSION' ) ? (string) FFC_VERSION : '';
		wp_localize_script(
			'ffc-encryption-key-suggest',
			'ffcEncKeySuggest',
			array(
				'copied'   => __( 'Copied to clipboard.', 'ffcertificate' ),
				'copyFail' => __( 'Press Ctrl/Cmd+C to copy.', 'ffcertificate' ),
				// Per-constant explanatory comment prepended (as a phpdoc block)
				// to the copyable snippet, mirroring the commented WordPress salt
				// block so the pasted define is self-identifying. `\n`-separated
				// lines become ` * …` phpdoc lines client-side.
				'defs'     => array(
					'FFC_ENCRYPTION_KEY' => sprintf(
						/* translators: %s: plugin version. */
						__( "Free Form Certificate — encryption key (FFC_ENCRYPTION_KEY).\nDedicated secret FFC derives its AES-256 at-rest encryption key from, instead\nof the shared WordPress salts, so a WordPress salt rotation can't silently make\nstored CPF / RF / e-mail unreadable. Keep it secret and backed up. Changing it\non a site that already holds data requires a key rotation (WP-Admin → Settings →\nMigrations). Generated for FFC v%s.", 'ffcertificate' ),
						$ffc_version
					),
					'FFC_HASH_SALT'      => sprintf(
						/* translators: %s: plugin version. */
						__( "Free Form Certificate — search-hash salt (FFC_HASH_SALT).\nDedicated secret that salts the blind-index hashes used to look up encrypted\nCPF / RF / e-mail, decoupling them from the shared WordPress salts. Changing it\non a site that already holds data invalidates those hashes until a key rotation\nrebuilds them (WP-Admin → Settings → Migrations). Generated for FFC v%s.", 'ffcertificate' ),
						$ffc_version
					),
				),
			)
		);
	}

	/**
	 * Render.
	 */
	public function render(): void {
		$view_file = FFC_PLUGIN_DIR . 'includes/settings/views/ffc-tab-advanced.php';

		if ( file_exists( $view_file ) ) {
			$settings = $this;
			include $view_file;
		} else {
			wp_admin_notice(
				esc_html__( 'Advanced settings view file not found.', 'ffcertificate' ),
				array( 'type' => 'error' )
			);
		}
	}
}

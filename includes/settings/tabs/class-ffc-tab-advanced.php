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
		$this->tab_group = 'system';
		$this->tab_title = __( 'Advanced', 'ffcertificate' );
		$this->tab_icon  = 'ffc-icon-sliders';
		$this->tab_order = 70;

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue auto-save infrastructure when this tab is active. Powers
	 * the `.ffc-control-toggle` switches for the activity-log master flag and
	 * the per-module debug flags.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( ! $this->should_enqueue_on( $hook ) ) {
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
		// The Danger Zone delete confirmation (#2338): target, count, typed
		// word and the shared countdown, on the plugin's dialog in ffc-core.
		\FreeFormCertificate\Core\AssetRegistry::enqueue_scripts( 'ffc-core' );
		wp_enqueue_script(
			'ffc-danger-zone',
			FFC_PLUGIN_URL . "assets/js/ffc-danger-zone{$s}.js",
			array( 'ffc-core' ),
			FFC_VERSION,
			true
		);
		wp_localize_script( 'ffc-danger-zone', 'ffcDangerZone', self::danger_zone_strings() );

		$ffc_version = defined( 'FFC_VERSION' ) ? (string) FFC_VERSION : '';
		wp_localize_script(
			'ffc-encryption-key-suggest',
			'ffcEncKeySuggest',
			array(
				'copied'   => __( 'Copied to clipboard.', 'ffcertificate' ),
				'copyFail' => __( 'Press Ctrl/Cmd+C to copy.', 'ffcertificate' ),
				// Per-constant explanatory comment prepended (as a phpdoc block) to
				// the copyable snippet, mirroring the commented WordPress salt block
				// so the pasted define is self-identifying. `\n`-separated lines
				// become ` * …` phpdoc lines client-side. Deliberately NOT localized:
				// this text lands inside wp-config.php (a code file), so it stays
				// English on every locale — code comments are English by convention.
				'defs'     => array(
					'FFC_ENCRYPTION_KEY' => sprintf(
						"Free Form Certificate — encryption key (FFC_ENCRYPTION_KEY).\nDedicated secret FFC derives its AES-256 at-rest encryption key from, instead\nof the shared WordPress salts, so a WordPress salt rotation can't silently make\nstored CPF / RF / e-mail unreadable. Keep it secret and backed up. Once records\nare stored under it, treat this exact value as permanent: changing or removing\nit makes those records unreadable until it is restored. Generated for FFC v%s.",
						$ffc_version
					),
					'FFC_HASH_SALT'      => sprintf(
						"Free Form Certificate — search-hash salt (FFC_HASH_SALT).\nDedicated secret that salts the blind-index hashes used to look up encrypted\nCPF / RF / e-mail, decoupling them from the shared WordPress salts. Changing it\non a site that already holds data invalidates those hashes until a key rotation\nrebuilds them (WP-Admin → Settings → Data Migrations). Generated for FFC v%s.",
						$ffc_version
					),
				),
			)
		);
	}

	/**
	 * The words of the Danger Zone delete confirmation (#2338).
	 *
	 * @return array<string, mixed>
	 */
	private static function danger_zone_strings(): array {
		return array(
			'word'       => \FreeFormCertificate\Admin\SettingsSaveHandler::confirm_word(),
			/* translators: %s: the word to type */
			'matchLabel' => __( 'Type %s to confirm', 'ffcertificate' ),
			/* translators: %s: number of submissions */
			'targetAll'  => __( 'Every submission of every form — %s records', 'ffcertificate' ),
			/* translators: 1: form title, 2: number of submissions */
			'targetForm' => __( 'All submissions of %1$s — %2$s records', 'ffcertificate' ),
			'reset'      => __( 'The submission ID counter is reset if the table ends up empty.', 'ffcertificate' ),
			'final'      => __( 'This cannot be undone. The deletion is recorded in the Activity Log with your name.', 'ffcertificate' ),
			'dialog'     => array(
				'seconds'        => \FreeFormCertificate\Core\ConfirmCountdown::SECONDS,
				'title'          => __( 'Delete submissions?', 'ffcertificate' ),
				'intro'          => __( 'This permanently deletes:', 'ffcertificate' ),
				'logged'         => '',
				'agree'          => __( 'Delete', 'ffcertificate' ),
				/* translators: %d: seconds left before the button can be pressed */
				'agreeIn'        => __( 'Delete (%d)', 'ffcertificate' ),
				'cancel'         => __( 'Cancel', 'ffcertificate' ),
				/* translators: %d: seconds before the deletion can be confirmed */
				'countdownStart' => __( 'Check the target and type the word. You can confirm in %d seconds.', 'ffcertificate' ),
				'countdownEnd'   => __( 'You can confirm now.', 'ffcertificate' ),
			),
		);
	}

	/**
	 * Render.
	 */
	public function render(): void {
		$view_file = $this->view_path( 'ffc-tab-advanced.php' );

		if ( file_exists( $view_file ) ) {
			$settings = $this;
			include $view_file;
		} else {
			\FreeFormCertificate\Core\AdminNotice::render(
				'error',
				esc_html__( 'Advanced settings view file not found.', 'ffcertificate' )
			);
		}
	}
}

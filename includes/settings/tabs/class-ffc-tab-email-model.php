<?php
/**
 * Email Model Settings Tab (#976)
 *
 * The shared "chrome" every plugin email is wrapped in — header / body / footer
 * colours, logo, outer container — plus a live preview and a "Send test email"
 * action. Split out of the SMTP tab so transport, chrome and per-email texts
 * each get their own focused screen in the Communication group.
 *
 * The chrome editor's own <form> posts `ffc_email_template[...]` (saved by
 * {@see \FreeFormCertificate\Admin\SettingsSaveHandler}, keyed on its
 * `_ffc_tab=email_model` marker) and the "Send test email" form posts
 * `ffc_send_test_email` (handled by {@see \FreeFormCertificate\Admin\SettingsActionHandler},
 * which redirects back here with `?ffc_test_email=<flag>`); this tab just renders
 * the box + the post-redirect flash.
 *
 * @package FreeFormCertificate\Settings\Tabs
 * @since   6.21.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Settings\Tabs;

use FreeFormCertificate\Settings\SettingsTab;
use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email Model settings tab.
 */
class TabEmailModel extends SettingsTab {

	/**
	 * Init.
	 */
	protected function init(): void {
		$this->tab_id    = 'email_model';
		$this->tab_group = 'communication';
		$this->tab_title = __( 'Email Model', 'ffcertificate' );
		$this->tab_icon  = 'ffc-icon-email';
		$this->tab_order = 21;

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue the Email Model editor assets (color pickers, media uploader, live
	 * preview) — moved here from the SMTP tab in the #976 split.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( ! $this->should_enqueue_on( $hook ) ) {
			return;
		}

		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();

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
				'confirmRestore' => __( 'Restore all Email Model fields to their defaults? Unsaved changes will be lost.', 'ffcertificate' ),
			)
		);
	}

	/**
	 * Render the Email Model tab: the P5 "emails disabled" notice, the
	 * post-redirect flash for the test-email action, then the chrome editor box.
	 *
	 * @return void
	 */
	public function render(): void {
		?>
		<div class="ffc-settings-wrap">
		<?php
		\FreeFormCertificate\Core\EmailDisabledNotice::render();

		// Flash notice for the "Send a test email" action. Display-only read after
		// a nonce-checked POST → redirect (PRG); the value is a fixed allowlisted
		// flag, so no nonce is needed on this GET render.
		$ffc_test_email_flag = RequestInput::get_get_key( 'ffc_test_email' );
		if ( '' !== $ffc_test_email_flag ) {
			$ffc_test_email_notices = array(
				'sent'       => array( 'success', __( 'Test email sent to your account.', 'ffcertificate' ) ),
				'disabled'   => array( 'warning', __( 'Emails are globally disabled, so the test email was not sent. Enable email sending on the SMTP tab and try again.', 'ffcertificate' ) ),
				'no_address' => array( 'error', __( 'Your account has no email address, so the test email could not be sent.', 'ffcertificate' ) ),
				'failed'     => array( 'error', __( 'The test email could not be sent. Check your SMTP settings and try again.', 'ffcertificate' ) ),
			);
			if ( isset( $ffc_test_email_notices[ $ffc_test_email_flag ] ) ) {
				wp_admin_notice(
					esc_html( $ffc_test_email_notices[ $ffc_test_email_flag ][1] ),
					array(
						'type'               => $ffc_test_email_notices[ $ffc_test_email_flag ][0],
						'additional_classes' => array( 'inline' ),
					)
				);
			}
		}

		require FFC_PLUGIN_DIR . 'templates/admin/settings/email-model-box.php';
		?>
		</div>
		<?php
	}
}

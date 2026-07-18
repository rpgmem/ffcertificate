<?php
/**
 * Form Editor Email Metabox Renderer
 *
 * Extracted from FormEditorMetaboxRenderer as part of S3 god-object refactor.
 *
 * @since   3.1.1
 * @package FreeFormCertificate\Admin
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Form Editor Email Metabox Renderer.
 *
 * @since 3.1.1
 */
class FormEditorEmailMetabox {

	/**
	 * Section 4: Email Settings
	 *
	 * @param WP_Post $post The post object.
	 */
	public function render( WP_Post $post ): void {
		$this->enqueue_restore_default_script();

		$config     = get_post_meta( $post->ID, '_ffc_form_config', true );
		$send_email = isset( $config['send_user_email'] ) ? $config['send_user_email'] : '0';
		$subject    = isset( $config['email_subject'] ) ? $config['email_subject'] : \FreeFormCertificate\Core\EmailTemplateDefaults::user_email_subject();
		$body       = isset( $config['email_body'] ) ? (string) $config['email_body'] : '';
		// When the email is enabled but no custom message was written yet, seed
		// the editor with a sensible default so the operator starts from a ready
		// template instead of a blank field. Strip tags / &nbsp; / whitespace
		// with a native preg_replace (no WP function dependency, so every test
		// that renders this metabox doesn't need to stub one) so a cleared
		// TinyMCE body (`<p></p>`) also counts as empty.
		if ( '' === (string) preg_replace( '/<[^>]*>|&nbsp;|\s+/', '', $body ) ) {
			$body = self::default_email_body();
		}
		$collapsed = ( '1' !== (string) $send_email );

		$send_admin      = isset( $config['send_admin_email'] ) ? $config['send_admin_email'] : '0';
		$email_admin     = isset( $config['email_admin'] ) ? (string) $config['email_admin'] : '';
		$admin_collapsed = ( '1' !== (string) $send_admin );
		?>
		<?php \FreeFormCertificate\Core\EmailDisabledNotice::render(); ?>
		<table class="form-table">
			<tr>
				<th><label><?php esc_html_e( 'Send Email to User?', 'ffcertificate' ); ?></label></th>
				<td>
					<?php
					// Hidden sibling holds the unchecked value so save_post
					// always sees the field, mirroring the old select that
					// was always present in the POST body.
					?>
					<input type="hidden" name="ffc_config[send_user_email]" value="0">
					<?php
					\FreeFormCertificate\Admin\AdminUI::render_toggle(
						array(
							'name'    => 'ffc_config[send_user_email]',
							'id'      => 'ffc_config_send_user_email',
							'checked' => '1' === (string) $send_email,
							'label'   => __( 'Send the email to the submitter after a successful submission.', 'ffcertificate' ),
							'data'    => array( 'ffc-autosave-form-key' => 'send_user_email' ),
						)
					);
					?>
				</td>
			</tr>
		</table>
		<?php
		/*
		 * Subject + body + note are wrapped in `.ffc-collapsed-target`
		 * so the generic toggle handler (#238 / Sprint 3) hides them
		 * when `send_user_email` is off. wp_editor() is still invoked
		 * unconditionally — TinyMCE initialises inside the wrapper and
		 * the wrapper's `display:none` collapses it visually without
		 * killing the editor instance.
		 */
		?>
		<div class="ffc-collapsed-target<?php echo $collapsed ? ' ffc-collapsed' : ''; ?>"
			data-ffc-master="ffc_config_send_user_email"
			aria-hidden="<?php echo $collapsed ? 'true' : 'false'; ?>">
		<table class="form-table">
			<tr>
				<th><label><?php esc_html_e( 'Subject', 'ffcertificate' ); ?></label></th>
				<td><input type="text" name="ffc_config[email_subject]" value="<?php echo esc_attr( $subject ); ?>" class="ffc-w100"></td>
			</tr>
			<tr>
				<th><label for="ffc_email_body"><?php esc_html_e( 'Email Body (HTML)', 'ffcertificate' ); ?></label></th>
				<td>
					<?php
					wp_editor(
						(string) $body,
						'ffc_email_body',
						array(
							'textarea_name' => 'ffc_config[email_body]',
							'textarea_rows' => 10,
							'media_buttons' => false,
							'teeny'         => true,
							'tinymce'       => array(
								'toolbar1' => 'bold,italic,underline,bullist,numlist,link,unlink,undo,redo',
								'toolbar2' => '',
							),
							'quicktags'     => array( 'buttons' => 'strong,em,link,ul,ol,li,close' ),
						)
					);
					?>
					<p class="description">
						<?php esc_html_e( 'Placeholders: {{name}}, {{form_title}}, {{auth_code}}, {{date}}. Links use the validation-URL DSL — e.g. {{validation_url link:m>"Download (PDF)"}} for the magic download link, or {{validation_url link:v>v}} for the public /valid page.', 'ffcertificate' ); ?>
					</p>
					<p>
						<button type="button" class="button" id="ffc-restore-default-email-body"><?php esc_html_e( 'Restore Default Text', 'ffcertificate' ); ?></button>
						<span class="description"><?php esc_html_e( 'Replaces the message above with the default template. You can also just clear the field — an empty body falls back to the default when the email is sent.', 'ffcertificate' ); ?></span>
					</p>
				</td>
			</tr>
			<tr>
				<th></th>
				<td>
				<p class="description ffc-mt-15">
				<em><?php esc_html_e( 'Note: When this option is enabled, the email will only be sent when the user submits the form. This will add them to a waiting list and emails will be sent progressively.', 'ffcertificate' ); ?></em>
				</p>
				</td>
			</tr>
		</table>
		</div><!-- /.ffc-collapsed-target -->

		<table class="form-table">
			<tr>
				<th><label><?php esc_html_e( 'Notify Admin on Submission?', 'ffcertificate' ); ?></label></th>
				<td>
					<input type="hidden" name="ffc_config[send_admin_email]" value="0">
					<?php
					\FreeFormCertificate\Admin\AdminUI::render_toggle(
						array(
							'name'    => 'ffc_config[send_admin_email]',
							'id'      => 'ffc_config_send_admin_email',
							'checked' => '1' === (string) $send_admin,
							'label'   => __( 'Send an admin notification email after a successful submission.', 'ffcertificate' ),
							'data'    => array( 'ffc-autosave-form-key' => 'send_admin_email' ),
						)
					);
					?>
				</td>
			</tr>
		</table>
		<div class="ffc-collapsed-target<?php echo $admin_collapsed ? ' ffc-collapsed' : ''; ?>"
			data-ffc-master="ffc_config_send_admin_email"
			aria-hidden="<?php echo $admin_collapsed ? 'true' : 'false'; ?>">
		<table class="form-table">
			<tr>
				<th><label for="ffc_config_email_admin"><?php esc_html_e( 'Admin Recipient(s)', 'ffcertificate' ); ?></label></th>
				<td>
					<input type="text" name="ffc_config[email_admin]" id="ffc_config_email_admin" value="<?php echo esc_attr( $email_admin ); ?>" class="ffc-w100">
					<p class="description">
						<?php esc_html_e( 'Comma-separated email addresses. Leave empty to use the site admin email.', 'ffcertificate' ); ?>
					</p>
				</td>
			</tr>
		</table>
		</div><!-- /.ffc-collapsed-target -->
		<?php
	}

	/**
	 * Enqueue the "Restore Default Text" button wiring for the email body
	 * editor and hand it the default template + a confirmation string.
	 *
	 * The default body is the same one {@see self::default_email_body()} seeds
	 * into an empty editor, so the button and the initial seed always agree.
	 */
	private function enqueue_restore_default_script(): void {
		$suffix = \FreeFormCertificate\Core\AssetHelper::asset_suffix();
		wp_enqueue_script(
			'ffc-form-editor-email-metabox',
			FFC_PLUGIN_URL . "assets/js/ffc-form-editor-email-metabox{$suffix}.js",
			array( 'jquery' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			'ffc-form-editor-email-metabox',
			'ffcEmailBodyDefault',
			array(
				'body'    => self::default_email_body(),
				'confirm' => __( 'Replace the current message with the default template? Your changes will be lost.', 'ffcertificate' ),
			)
		);
	}

	/**
	 * Default user-email body seeded into the editor when a form enables the
	 * email without a message of its own. This is the editable **"email body"**
	 * (greeting, download button, auth code, verification link); the shared,
	 * admin-configurable "Email Model" chrome (header/footer) is added around
	 * it at send time (#662 PR-7). See
	 * {@see \FreeFormCertificate\Core\EmailTemplateDefaults::user_email_body()}.
	 *
	 * @return string Default email body HTML (with placeholders).
	 */
	public static function default_email_body(): string {
		return \FreeFormCertificate\Core\EmailTemplateDefaults::user_email_body();
	}
}

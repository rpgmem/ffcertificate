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
		$config     = get_post_meta( $post->ID, '_ffc_form_config', true );
		$send_email = isset( $config['send_user_email'] ) ? $config['send_user_email'] : '0';
		$subject    = isset( $config['email_subject'] ) ? $config['email_subject'] : __( 'Your Certificate', 'ffcertificate' );
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
		?>
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
						<?php esc_html_e( 'Placeholders such as {{auth_code}} and {{name}} are preserved automatically.', 'ffcertificate' ); ?>
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
		<?php
	}

	/**
	 * Default user-email body (custom message) seeded into the editor when a
	 * form enables the email without a message of its own. Complements the
	 * fixed email chrome (heading, authentication-code card, view/download
	 * button) that {@see \FreeFormCertificate\Integrations\EmailHandler}
	 * already builds — so this is just a friendly intro, not the whole email.
	 *
	 * @return string Default email body HTML (with `{{name}}` placeholder).
	 */
	public static function default_email_body(): string {
		return '<p>' . __( 'Hello {{name}},', 'ffcertificate' ) . '</p>'
			. '<p>' . __( 'Your certificate has been issued. Use the button below to view and download it, and keep your authentication code for future verification.', 'ffcertificate' ) . '</p>';
	}
}

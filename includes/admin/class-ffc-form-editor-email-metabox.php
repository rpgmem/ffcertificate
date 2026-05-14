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
		$body       = isset( $config['email_body'] ) ? $config['email_body'] : '';
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
						)
					);
					?>
				</td>
			</tr>
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
		<?php
	}
}

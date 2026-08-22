<?php
/**
 * Form Editor Email Metabox Renderer
 *
 * Extracted from FormEditorMetaboxRenderer as part of S3 god-object refactor.
 *
 * @since   3.2.0
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
 * @since 3.2.0
 */
class FormEditorEmailMetabox {

	/**
	 * Section 4: Email Settings
	 *
	 * @param WP_Post $post The post object.
	 */
	public function render( WP_Post $post ): void {
		$this->enqueue_restore_default_script();

		$config = get_post_meta( $post->ID, '_ffc_form_config', true );
		if ( ! is_array( $config ) ) {
			$config = array();
		}
		$send_email = isset( $config['send_user_email'] ) ? $config['send_user_email'] : '0';
		// No pre-seed (#964): the subject + body show the stored value as-is. A
		// form that has not written its own copy stays empty and rides the GLOBAL
		// default (Settings → SMTP → Email texts); the operator opts into a
		// per-form Custom copy with the toggle below, which seeds the editor with
		// the current global to edit from.
		$subject = isset( $config['email_subject'] ) ? (string) $config['email_subject'] : '';
		$body    = isset( $config['email_body'] ) ? (string) $config['email_body'] : '';
		// Global vs Custom (migration-free heuristic, #964): a genuinely edited
		// subject OR body (≠ the shipped default) marks this form as a per-form
		// Custom; otherwise it is on the Global and follows the hub. Legacy forms
		// that merely carry the old pre-seeded default therefore open as Global.
		$is_custom = \FreeFormCertificate\Core\EmailTemplates::overrides_global( 'certificate-user', $body, 'body' )
			|| \FreeFormCertificate\Core\EmailTemplates::overrides_global( 'certificate-user', $subject, 'subject' );
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
				<th><label><?php esc_html_e( 'Email content', 'ffcertificate' ); ?></label></th>
				<td>
					<?php
					// UI-only toggle (not persisted; the save handler ignores it).
					// The effective mode is re-derived from the stored text on each
					// load via the same heuristic — see $is_custom above (#964).
					\FreeFormCertificate\Admin\AdminUI::render_toggle(
						array(
							'name'    => 'ffc_email_custom_mode',
							'id'      => 'ffc_email_custom_mode',
							'checked' => $is_custom,
							'label'   => __( 'Write a custom email for this form (instead of the shared global default).', 'ffcertificate' ),
						)
					);
					?>
				</td>
			</tr>
		</table>

		<p class="description ffc-cert-email-global-note"<?php echo $is_custom ? ' style="display:none;"' : ''; ?>>
			<?php esc_html_e( 'This form uses the shared global email text. Turn on the toggle above to write a version just for this form.', 'ffcertificate' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=ffc-settings&tab=smtp' ) ); ?>"><?php esc_html_e( 'Edit the global text', 'ffcertificate' ); ?></a>
		</p>

		<div class="ffc-cert-email-custom-fields"<?php echo $is_custom ? '' : ' style="display:none;"'; ?>>
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
						<button type="button" class="button ffc-email-restore-default" data-editor="ffc_email_body" data-default-key="certificate_body"><?php esc_html_e( 'Restore Default Text', 'ffcertificate' ); ?></button>
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
		</div><!-- /.ffc-cert-email-custom-fields -->
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
	 * Uses the shared `ffc-email-restore-default` script (the same generic
	 * `data-editor` / `data-default-key` button used by the recruitment and
	 * self-scheduling editors) — there is one restore-button implementation
	 * plugin-wide (#673). The default body is the same one
	 * {@see self::default_email_body()} seeds into an empty editor, so the
	 * button and the initial seed always agree.
	 */
	private function enqueue_restore_default_script(): void {
		$suffix = \FreeFormCertificate\Core\AssetHelper::asset_suffix();

		// Resolve the EFFECTIVE global (hub override → shipped default) once — the
		// "Restore Default Text" button and the flip-to-Custom seed both work off
		// the global, so a per-form Custom always starts from what the email would
		// otherwise send (#964).
		$global_body    = \FreeFormCertificate\Core\EmailTemplates::effective_body( 'certificate-user', 'body' );
		$global_subject = \FreeFormCertificate\Core\EmailTemplates::effective_body( 'certificate-user', 'subject' );

		wp_enqueue_script(
			'ffc-email-restore-default',
			FFC_PLUGIN_URL . "assets/js/ffc-email-restore-default{$suffix}.js",
			array( 'jquery' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			'ffc-email-restore-default',
			'ffcEmailRestoreDefaults',
			array(
				'certificate_body' => array(
					'body'    => $global_body,
					'confirm' => __( 'Replace the current message with the global default text? Your changes will be lost.', 'ffcertificate' ),
				),
			)
		);

		// Global/Custom toggle behaviour for the certificate email (#964).
		wp_enqueue_script(
			'ffc-cert-email-mode',
			FFC_PLUGIN_URL . "assets/js/ffc-cert-email-mode{$suffix}.js",
			array( 'jquery' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			'ffc-cert-email-mode',
			'ffcCertEmailGlobal',
			array(
				'subject'      => $global_subject,
				'body'         => $global_body,
				'confirmReset' => __( 'Discard this form’s custom email text and use the shared global default instead?', 'ffcertificate' ),
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

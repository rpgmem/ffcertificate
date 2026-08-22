<?php
/**
 * Global email-body hub — Settings → SMTP (#964).
 *
 * Renders the seven phase-1 emails, each with an editable subject + body (the
 * "email body" only; the shared Email Model chrome wraps it) that overrides the
 * shipped file default GLOBALLY. Editing here writes the `ffc_email_bodies`
 * option via {@see \FreeFormCertificate\Core\EmailTemplates::save_global()} /
 * {@see \FreeFormCertificate\Core\EmailTemplates::clear_global()} from the tab's
 * POST handler.
 *
 * Fields are pre-filled with the EFFECTIVE default (the stored global override
 * when present, otherwise the shipped file default), so the admin always edits
 * the text that is actually in force. "Restore Default Text" reloads the shipped
 * FILE default into the body editor (shared `ffc-email-restore-default.js`); a
 * save whose subject + body both equal the file default clears the override
 * entirely (⇒ the email tracks the shipped wording again).
 *
 * Expects `$ffc_email_hub_catalog` — key => array{label:string, tokens:array<int,string>}.
 * Rendered by {@see \FreeFormCertificate\Settings\Tabs\TabSMTP::render_email_body_hub()},
 * which has already confirmed the `ffc_manage_email_templates` capability.
 *
 * @package FreeFormCertificate\Settings\Views
 * @since   6.21.0
 */

use FreeFormCertificate\Core\EmailTemplates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// $ffc_email_hub_catalog: array<string, array{label:string, tokens:array<int,string>}> — provided by the caller.
?>
<div class="card ffc-email-body-hub">
	<h2 class="ffc-icon-email"><?php esc_html_e( 'Email texts', 'ffcertificate' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Edit the default text of each plugin email once, globally. These are the message bodies only — the shared Email Model above wraps every one with the same header and footer. Leave an email untouched (or use "Restore Default Text") to keep its shipped wording.', 'ffcertificate' ); ?>
	</p>

	<form method="post">
		<?php wp_nonce_field( 'ffc_email_bodies_nonce' ); ?>
		<input type="hidden" name="ffc_save_email_bodies" value="1">

		<?php foreach ( $ffc_email_hub_catalog as $ffc_key => $ffc_meta ) : ?>
			<?php
			$ffc_editor_id   = 'ffc_email_body_' . str_replace( '-', '_', (string) $ffc_key );
			$ffc_cur_subject = EmailTemplates::effective_body( $ffc_key, 'subject' );
			$ffc_cur_body    = EmailTemplates::effective_body( $ffc_key, 'body' );
			?>
			<div class="ffc-email-body-hub__item">
				<h3><?php echo esc_html( $ffc_meta['label'] ); ?></h3>

				<p>
					<label for="<?php echo esc_attr( $ffc_editor_id . '_subject' ); ?>">
						<strong><?php esc_html_e( 'Subject', 'ffcertificate' ); ?></strong>
					</label><br>
					<input type="text"
						id="<?php echo esc_attr( $ffc_editor_id . '_subject' ); ?>"
						name="ffc_email_bodies[<?php echo esc_attr( $ffc_key ); ?>][subject]"
						value="<?php echo esc_attr( $ffc_cur_subject ); ?>"
						class="large-text">
				</p>

				<label for="<?php echo esc_attr( $ffc_editor_id ); ?>">
					<strong><?php esc_html_e( 'Body', 'ffcertificate' ); ?></strong>
				</label>
				<?php
				wp_editor(
					$ffc_cur_body,
					$ffc_editor_id,
					array(
						'textarea_name' => 'ffc_email_bodies[' . $ffc_key . '][body]',
						'textarea_rows' => 8,
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

				<?php if ( ! empty( $ffc_meta['tokens'] ) ) : ?>
					<p class="description">
						<?php esc_html_e( 'Available variables:', 'ffcertificate' ); ?>
						<?php
						$ffc_token_html = array();
						foreach ( $ffc_meta['tokens'] as $ffc_token ) {
							$ffc_token_html[] = '<code>{{' . esc_html( $ffc_token ) . '}}</code>';
						}
						// Each token is individually escaped above; only <code> is allowed through.
						echo wp_kses( implode( ', ', $ffc_token_html ), array( 'code' => array() ) );
						?>
					</p>
				<?php endif; ?>

				<p>
					<button type="button" class="button ffc-email-restore-default"
						data-editor="<?php echo esc_attr( $ffc_editor_id ); ?>"
						data-default-key="<?php echo esc_attr( $ffc_key ); ?>">
						<?php esc_html_e( 'Restore Default Text', 'ffcertificate' ); ?>
					</button>
				</p>
			</div>
		<?php endforeach; ?>

		<?php submit_button( __( 'Save email texts', 'ffcertificate' ) ); ?>
	</form>
</div>
<?php

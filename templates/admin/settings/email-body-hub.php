<?php
/**
 * Global email-body hub — Settings → Email texts (#964, selector #976 B2).
 *
 * Renders every hub email with an editable subject + body (the "email body"
 * only; the shared Email Model chrome wraps it) that overrides the shipped file
 * default GLOBALLY. Editing here writes the `ffc_email_bodies` option via
 * {@see \FreeFormCertificate\Core\EmailTemplates::save_global()} /
 * {@see \FreeFormCertificate\Core\EmailTemplates::clear_global()} from the tab's
 * POST handler.
 *
 * A feature-grouped `<select>` chooses which email to edit: only the selected
 * email's editor is shown, and its TinyMCE is initialized on demand by
 * `ffc-email-texts.js` (wp.editor.initialize / .remove), so the 15 editors no
 * longer all boot at once. Every email's `<textarea>` is still present in the
 * one form, so a single save persists them all; untouched emails post their
 * effective text unchanged (⇒ no-op / cleared when equal to the file default).
 *
 * Fields are pre-filled with the EFFECTIVE default (the stored global override
 * when present, otherwise the shipped file default). "Restore Default Text"
 * reloads the shipped FILE default into the body editor (shared
 * `ffc-email-restore-default.js`, which handles both TinyMCE and raw-textarea
 * modes).
 *
 * Expects `$ffc_email_hub_catalog` (key => array{label, tokens}) and
 * `$ffc_email_hub_groups` (group label => ordered keys), from
 * {@see \FreeFormCertificate\Settings\Tabs\TabEmailTexts::render_email_body_hub()},
 * which has already confirmed the `ffc_manage_email_templates` capability.
 *
 * @package FreeFormCertificate\Settings\Views
 * @since   6.21.0
 */

use FreeFormCertificate\Core\EmailTemplates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// $ffc_email_hub_catalog / $ffc_email_hub_groups are provided by the caller.
$ffc_first_editor = true;
?>
<div class="card ffc-email-body-hub">
	<h2 class="ffc-icon-email"><?php esc_html_e( 'Email texts', 'ffcertificate' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Edit the default text of each plugin email once, globally. These are the message bodies only — the shared Email Model wraps every one with the same header and footer. Pick an email below to edit it; leave one untouched (or use "Restore Default Text") to keep its shipped wording.', 'ffcertificate' ); ?>
	</p>

	<form method="post">
		<?php wp_nonce_field( 'ffc_email_bodies_nonce' ); ?>
		<input type="hidden" name="ffc_save_email_bodies" value="1">

		<p class="ffc-email-texts-picker">
			<label for="ffc-email-texts-select">
				<strong><?php esc_html_e( 'Select an email to edit', 'ffcertificate' ); ?></strong>
			</label><br>
			<select id="ffc-email-texts-select" class="regular-text">
				<?php foreach ( $ffc_email_hub_groups as $ffc_group_label => $ffc_group_keys ) : ?>
					<optgroup label="<?php echo esc_attr( $ffc_group_label ); ?>">
						<?php foreach ( $ffc_group_keys as $ffc_key ) : ?>
							<?php if ( ! isset( $ffc_email_hub_catalog[ $ffc_key ] ) ) { continue; } ?>
							<option value="<?php echo esc_attr( 'ffc_email_body_' . str_replace( '-', '_', (string) $ffc_key ) ); ?>">
								<?php echo esc_html( $ffc_email_hub_catalog[ $ffc_key ]['label'] ); ?>
							</option>
						<?php endforeach; ?>
					</optgroup>
				<?php endforeach; ?>
			</select>
		</p>

		<?php foreach ( $ffc_email_hub_groups as $ffc_group_keys ) : ?>
			<?php foreach ( $ffc_group_keys as $ffc_key ) : ?>
				<?php
				if ( ! isset( $ffc_email_hub_catalog[ $ffc_key ] ) ) {
					continue;
				}
				$ffc_meta        = $ffc_email_hub_catalog[ $ffc_key ];
				$ffc_editor_id   = 'ffc_email_body_' . str_replace( '-', '_', (string) $ffc_key );
				$ffc_cur_subject = EmailTemplates::effective_body( $ffc_key, 'subject' );
				$ffc_cur_body    = EmailTemplates::effective_body( $ffc_key, 'body' );
				?>
				<div class="ffc-email-body-hub__item"
					id="<?php echo esc_attr( $ffc_editor_id . '_item' ); ?>"
					data-editor="<?php echo esc_attr( $ffc_editor_id ); ?>"
					<?php echo $ffc_first_editor ? '' : ' style="display:none;"'; ?>>
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
					<textarea
						id="<?php echo esc_attr( $ffc_editor_id ); ?>"
						name="ffc_email_bodies[<?php echo esc_attr( $ffc_key ); ?>][body]"
						class="ffc-email-texts-body large-text"
						rows="10"><?php echo esc_textarea( $ffc_cur_body ); ?></textarea>

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
				<?php $ffc_first_editor = false; ?>
			<?php endforeach; ?>
		<?php endforeach; ?>

		<?php submit_button( __( 'Save email texts', 'ffcertificate' ) ); ?>
	</form>
</div>
<?php

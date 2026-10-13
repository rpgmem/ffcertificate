<?php
/**
 * Certificates Settings Tab View
 *
 * @package FreeFormCertificate\Settings\Views
 * @since 6.39.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ffcertificate_req_tags = \FreeFormCertificate\Settings\SettingsReader::required_certificate_tags();
?>

<div class="ffc-settings-wrap">

<form method="post">
	<?php wp_nonce_field( 'ffc_settings_action', 'ffc_settings_nonce' ); ?>
	<input type="hidden" name="_ffc_tab" value="certificates">

<div class="card">
	<h2 class="ffc-icon-award"><?php esc_html_e( 'Certificates', 'ffcertificate' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'What every certificate form must carry. The certificate HTML editor\'s colour theme is on the General tab, next to Dark Mode.', 'ffcertificate' ); ?>
	</p>
	<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row">
					<label for="required_certificate_tags"><?php esc_html_e( 'Required Certificate Tags', 'ffcertificate' ); ?></label>
				</th>
				<td>
					<textarea name="ffc_settings[required_certificate_tags]" id="required_certificate_tags" rows="4" class="large-text code" data-ffc-autosave-key="required_certificate_tags" data-ffc-autosave-debounce="800"><?php echo esc_textarea( implode( "\n", $ffcertificate_req_tags ) ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'One tag per line (with braces). Every certificate layout must contain them, and every form must have a field for each one the participant fills in; the form editor highlights those fields and does not let them be renamed or removed. {{auth_code}} is always required — certificate verification depends on it — and the plugin generates it, so it needs no field.', 'ffcertificate' ); ?>
					</p>
				</td>
			</tr>
		</tbody>
	</table>
</div>

	<?php submit_button(); ?>

</form>

</div><!-- .ffc-settings-wrap -->

<?php
/**
 * "Email Model" box — the configurable email chrome editor.
 *
 * Rendered inside the SMTP settings tab (below the Popular SMTP Providers card).
 * Its own <form> posts `ffc_email_template[...]` (a separate option from
 * `ffc_settings`), saved by {@see \FreeFormCertificate\Admin\SettingsSaveHandler}.
 * Guided fields (color pickers, selects, logo uploader) + a client-side live
 * preview + a restore-to-defaults button, all wired by ffc-email-model.js.
 *
 * @package FreeFormCertificate\Settings\Views
 * @since   6.14.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template-scoped locals.

$ffc_model = \FreeFormCertificate\Core\EmailTemplateOptions::all();

/**
 * Field groups. Each field: array( key, type, label ).
 * Types: color | number | select:<optsKey> | logo | textarea.
 */
$ffc_model_groups = array(
	__( 'Header', 'ffcertificate' )    => array(
		array( 'header_bg', 'color', __( 'Background', 'ffcertificate' ) ),
		array( 'header_text_color', 'color', __( 'Text color', 'ffcertificate' ) ),
		array( 'header_alignment', 'align', __( 'Alignment', 'ffcertificate' ) ),
		array( 'header_padding', 'number', __( 'Padding (px)', 'ffcertificate' ) ),
		array( 'header_logo_url', 'logo', __( 'Logo image', 'ffcertificate' ) ),
		array( 'header_logo_max_width', 'number', __( 'Logo max width (px)', 'ffcertificate' ) ),
	),
	__( 'Body', 'ffcertificate' )      => array(
		array( 'body_bg', 'color', __( 'Background', 'ffcertificate' ) ),
		array( 'body_text_color', 'color', __( 'Text color', 'ffcertificate' ) ),
		array( 'body_link_color', 'color', __( 'Link color', 'ffcertificate' ) ),
		array( 'body_font_family', 'font', __( 'Font', 'ffcertificate' ) ),
		array( 'body_font_size', 'number', __( 'Font size (px)', 'ffcertificate' ) ),
		array( 'body_padding', 'number', __( 'Padding (px)', 'ffcertificate' ) ),
		array( 'body_max_width', 'number', __( 'Max width (px)', 'ffcertificate' ) ),
	),
	__( 'Footer', 'ffcertificate' )    => array(
		array( 'footer_bg', 'color', __( 'Background', 'ffcertificate' ) ),
		array( 'footer_text_color', 'color', __( 'Text color', 'ffcertificate' ) ),
		array( 'footer_text', 'textarea', __( 'Text', 'ffcertificate' ) ),
	),
	__( 'Container', 'ffcertificate' ) => array(
		array( 'wrapper_bg', 'color', __( 'Background', 'ffcertificate' ) ),
		array( 'wrapper_border_radius', 'number', __( 'Corner radius (px)', 'ffcertificate' ) ),
		array( 'wrapper_padding', 'number', __( 'Padding (px)', 'ffcertificate' ) ),
	),
);

$ffc_align_options = array(
	'left'   => __( 'Left', 'ffcertificate' ),
	'center' => __( 'Center', 'ffcertificate' ),
	'right'  => __( 'Right', 'ffcertificate' ),
);
$ffc_font_options  = array(
	'system'  => __( 'System', 'ffcertificate' ),
	'serif'   => __( 'Serif', 'ffcertificate' ),
	'mono'    => __( 'Monospace', 'ffcertificate' ),
	'arial'   => 'Arial',
	'georgia' => 'Georgia',
);
?>
<div class="card ffc-email-model-card" id="ffc-email-model">
	<h2 class="ffc-icon-email"><?php esc_html_e( 'Email Model', 'ffcertificate' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Configure the single chrome (header, body, footer and outer container) that wraps every plugin email. The message content is injected into the body automatically.', 'ffcertificate' ); ?>
	</p>

	<div class="ffc-email-model-layout">
		<form method="post" class="ffc-email-model-form">
			<?php wp_nonce_field( 'ffc_settings_action', 'ffc_settings_nonce' ); ?>
			<?php // Distinct tab id so this form does NOT trip the SMTP tab's disable_all_emails / user-creation save blocks. ?>
			<input type="hidden" name="_ffc_tab" value="email_model">

			<?php foreach ( $ffc_model_groups as $ffc_group_label => $ffc_fields ) : ?>
				<fieldset class="ffc-email-model-group">
					<legend><?php echo esc_html( $ffc_group_label ); ?></legend>
					<?php foreach ( $ffc_fields as $ffc_field ) : ?>
						<?php
						list( $ffc_key, $ffc_type, $ffc_label ) = $ffc_field;
						$ffc_name                               = 'ffc_email_template[' . $ffc_key . ']';
						$ffc_id                                 = 'ffc-em-' . $ffc_key;
						$ffc_value                              = (string) ( $ffc_model[ $ffc_key ] ?? '' );
						?>
						<div class="ffc-email-model-row">
							<label for="<?php echo esc_attr( $ffc_id ); ?>"><?php echo esc_html( $ffc_label ); ?></label>
							<?php if ( 'color' === $ffc_type ) : ?>
								<input type="text" class="ffc-em-color" id="<?php echo esc_attr( $ffc_id ); ?>" name="<?php echo esc_attr( $ffc_name ); ?>" value="<?php echo esc_attr( $ffc_value ); ?>" data-ffc-model-field="<?php echo esc_attr( $ffc_key ); ?>" data-default-color="<?php echo esc_attr( $ffc_value ); ?>">
							<?php elseif ( 'number' === $ffc_type ) : ?>
								<input type="number" min="0" class="small-text" id="<?php echo esc_attr( $ffc_id ); ?>" name="<?php echo esc_attr( $ffc_name ); ?>" value="<?php echo esc_attr( $ffc_value ); ?>" data-ffc-model-field="<?php echo esc_attr( $ffc_key ); ?>">
							<?php elseif ( 'align' === $ffc_type ) : ?>
								<select id="<?php echo esc_attr( $ffc_id ); ?>" name="<?php echo esc_attr( $ffc_name ); ?>" data-ffc-model-field="<?php echo esc_attr( $ffc_key ); ?>">
									<?php foreach ( $ffc_align_options as $ffc_ov => $ffc_ol ) : ?>
										<option value="<?php echo esc_attr( $ffc_ov ); ?>" <?php selected( $ffc_ov, $ffc_value ); ?>><?php echo esc_html( $ffc_ol ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php elseif ( 'font' === $ffc_type ) : ?>
								<select id="<?php echo esc_attr( $ffc_id ); ?>" name="<?php echo esc_attr( $ffc_name ); ?>" data-ffc-model-field="<?php echo esc_attr( $ffc_key ); ?>">
									<?php foreach ( $ffc_font_options as $ffc_ov => $ffc_ol ) : ?>
										<option value="<?php echo esc_attr( $ffc_ov ); ?>" <?php selected( $ffc_ov, $ffc_value ); ?>><?php echo esc_html( $ffc_ol ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php elseif ( 'logo' === $ffc_type ) : ?>
								<span class="ffc-em-logo">
									<input type="text" class="regular-text" id="<?php echo esc_attr( $ffc_id ); ?>" name="<?php echo esc_attr( $ffc_name ); ?>" value="<?php echo esc_attr( $ffc_value ); ?>" data-ffc-model-field="<?php echo esc_attr( $ffc_key ); ?>" placeholder="https://…">
									<button type="button" class="button ffc-em-logo-select"><?php esc_html_e( 'Select image', 'ffcertificate' ); ?></button>
									<button type="button" class="button-link ffc-em-logo-clear"><?php esc_html_e( 'Clear', 'ffcertificate' ); ?></button>
								</span>
							<?php elseif ( 'textarea' === $ffc_type ) : ?>
								<textarea class="large-text" rows="2" id="<?php echo esc_attr( $ffc_id ); ?>" name="<?php echo esc_attr( $ffc_name ); ?>" data-ffc-model-field="<?php echo esc_attr( $ffc_key ); ?>"><?php echo esc_textarea( $ffc_value ); ?></textarea>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</fieldset>
			<?php endforeach; ?>

			<p class="description ffc-em-tokens">
				<?php esc_html_e( 'Footer tokens:', 'ffcertificate' ); ?>
				<code>{{site_title}}</code> <code>{{site_url}}</code> <code>{{home_url}}</code>
				<code>{{admin_email}}</code> <code>{{recipient}}</code> <code>{{date}}</code> <code>{{year}}</code>
			</p>

			<p class="ffc-email-model-actions">
				<?php submit_button( __( 'Save Email Model', 'ffcertificate' ), 'primary', 'submit', false ); ?>
				<button type="button" class="button ffc-email-model-restore"><?php esc_html_e( 'Restore Defaults', 'ffcertificate' ); ?></button>
			</p>
		</form>

		<div class="ffc-email-model-preview">
			<h4><?php esc_html_e( 'Live Preview', 'ffcertificate' ); ?></h4>
			<iframe class="ffc-email-model-preview-frame" title="<?php esc_attr_e( 'Email preview', 'ffcertificate' ); ?>"></iframe>
		</div>
	</div>
</div>

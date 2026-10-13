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

$ffcertificate_req_tags   = \FreeFormCertificate\Settings\SettingsReader::required_certificate_tags();
$ffcertificate_req_fields = \FreeFormCertificate\Settings\SettingsReader::required_form_fields();
$ffcertificate_types      = array(
	'text'     => __( 'Text', 'ffcertificate' ),
	'textarea' => __( 'Textarea', 'ffcertificate' ),
	'email'    => __( 'Email', 'ffcertificate' ),
	'number'   => __( 'Number', 'ffcertificate' ),
	'date'     => __( 'Date', 'ffcertificate' ),
);

/**
 * One row of the required-field table.
 *
 * @param string                                       $index Row index, or the template placeholder.
 * @param array{name: string, label: string, type: string} $row   Row values.
 * @param array<string, string>                        $types Type => label.
 */
$ffcertificate_field_row = static function ( string $index, array $row, array $types ): void {
	$locked = 'email' === $row['name'];
	$prefix = 'ffc_settings[required_form_fields][' . $index . ']';
	?>
	<tr class="ffc-required-field-row">
		<td>
			<input type="text" class="regular-text code" name="<?php echo esc_attr( $prefix . '[name]' ); ?>" value="<?php echo esc_attr( $row['name'] ); ?>" pattern="[a-z0-9_]+" aria-label="<?php esc_attr_e( 'Field name', 'ffcertificate' ); ?>" <?php echo $locked ? 'readonly' : ''; ?>>
		</td>
		<td>
			<input type="text" class="regular-text" name="<?php echo esc_attr( $prefix . '[label]' ); ?>" value="<?php echo esc_attr( $row['label'] ); ?>" aria-label="<?php esc_attr_e( 'Label', 'ffcertificate' ); ?>">
		</td>
		<td>
			<?php if ( $locked ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $prefix . '[type]' ); ?>" value="email">
				<select disabled aria-label="<?php esc_attr_e( 'Type', 'ffcertificate' ); ?>"><option><?php echo esc_html( $types['email'] ); ?></option></select>
			<?php else : ?>
				<select name="<?php echo esc_attr( $prefix . '[type]' ); ?>" aria-label="<?php esc_attr_e( 'Type', 'ffcertificate' ); ?>">
					<?php foreach ( $types as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $row['type'], $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>
		</td>
		<td>
			<button type="button" class="button ffc-required-field-remove" <?php disabled( $locked ); ?>><?php esc_html_e( 'Remove', 'ffcertificate' ); ?></button>
		</td>
	</tr>
	<?php
};
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
						<?php esc_html_e( 'One tag per line (with braces). Every certificate layout must contain them. {{auth_code}} is always required — certificate verification depends on it. {{schedule}} is required only of a form that has an Event Schedule.', 'ffcertificate' ); ?>
					</p>
				</td>
			</tr>
		</tbody>
	</table>
</div>

<div class="card">
	<h2 class="ffc-icon-list"><?php esc_html_e( 'Required Form Fields', 'ffcertificate' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Fields every certificate form must have. A new form starts with them; in the form editor their name, type and required flag are locked, and only the label can change. Email is always required: the certificate is sent to it.', 'ffcertificate' ); ?>
	</p>
	<table class="widefat striped ffc-required-fields-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Field name', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Label', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Type', 'ffcertificate' ); ?></th>
				<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Remove', 'ffcertificate' ); ?></span></th>
			</tr>
		</thead>
		<tbody id="ffc-required-fields-rows" data-ffc-next-index="<?php echo esc_attr( (string) count( $ffcertificate_req_fields ) ); ?>">
			<?php
			foreach ( $ffcertificate_req_fields as $ffcertificate_i => $ffcertificate_row ) {
				$ffcertificate_field_row( (string) $ffcertificate_i, $ffcertificate_row, $ffcertificate_types );
			}
			?>
		</tbody>
	</table>
	<template id="ffc-required-field-template">
		<?php
		$ffcertificate_field_row(
			'__index__',
			array(
				'name'  => '',
				'label' => '',
				'type'  => 'text',
			),
			$ffcertificate_types
		);
		?>
	</template>
	<p>
		<button type="button" class="button ffc-icon-plus" id="ffc-required-field-add"><?php esc_html_e( 'Add required field', 'ffcertificate' ); ?></button>
	</p>
	<p class="description">
		<?php esc_html_e( 'The certificate layout prints a field through its name in braces, such as {{cpf_rf}}. A name takes lowercase letters, digits and underscores.', 'ffcertificate' ); ?>
	</p>
</div>

	<?php submit_button(); ?>

</form>

</div><!-- .ffc-settings-wrap -->

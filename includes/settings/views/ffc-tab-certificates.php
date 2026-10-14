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

$ffcertificate_req_rows = \FreeFormCertificate\Settings\RequiredData::rows();
$ffcertificate_types    = array(
	'text'     => __( 'Text', 'ffcertificate' ),
	'textarea' => __( 'Textarea', 'ffcertificate' ),
	'email'    => __( 'Email', 'ffcertificate' ),
	'number'   => __( 'Number', 'ffcertificate' ),
	'date'     => __( 'Date', 'ffcertificate' ),
);

/**
 * One row of the "Required data" table (#1759).
 *
 * A tag the plugin fills in itself has no form field, so its row carries
 * the HTML switch only. The Email row's form switch and the {{auth_code}}
 * row's HTML switch are locked on, and neither row can be removed.
 *
 * @param string                                                                     $index Row index, or the template placeholder.
 * @param array{name: string, label: string, type: string, form: bool, html: bool} $row   Row values.
 * @param array<string, string>                                                      $types Type => label.
 */
$ffcertificate_data_row = static function ( string $index, array $row, array $types ): void {
	$generated = '' !== $row['name'] && \FreeFormCertificate\Settings\RequiredData::is_generated( $row['name'] );
	$is_email  = \FreeFormCertificate\Settings\RequiredData::LOCKED_FORM_FIELD === $row['name'];
	$is_auth   = \FreeFormCertificate\Settings\RequiredData::LOCKED_TAG === $row['name'];
	$prefix    = 'ffc_settings[required_data][' . $index . ']';
	$id_prefix = 'ffc_required_data_' . $index;
	?>
	<tr class="ffc-required-data-row<?php echo $generated ? ' ffc-required-data-generated' : ''; ?>">
		<td>
			<input type="text" class="regular-text code ffc-required-data-name" name="<?php echo esc_attr( $prefix . '[name]' ); ?>" value="<?php echo esc_attr( $row['name'] ); ?>" pattern="[a-z0-9_]+" aria-label="<?php esc_attr_e( 'Field name', 'ffcertificate' ); ?>" <?php echo $is_email || $generated ? 'readonly' : ''; ?>>
		</td>
		<td>
			<?php if ( $generated ) : ?>
				<input type="hidden" class="ffc-required-data-label" name="<?php echo esc_attr( $prefix . '[label]' ); ?>" value="<?php echo esc_attr( $row['label'] ); ?>">
				<span class="description">&mdash;</span>
			<?php else : ?>
				<input type="text" class="regular-text ffc-required-data-label" name="<?php echo esc_attr( $prefix . '[label]' ); ?>" value="<?php echo esc_attr( $row['label'] ); ?>" aria-label="<?php esc_attr_e( 'Label', 'ffcertificate' ); ?>">
			<?php endif; ?>
		</td>
		<td>
			<?php if ( $generated ) : ?>
				<input type="hidden" class="ffc-required-data-type" name="<?php echo esc_attr( $prefix . '[type]' ); ?>" value="text">
				<span class="description"><?php esc_html_e( 'Filled in by the plugin', 'ffcertificate' ); ?></span>
			<?php elseif ( $is_email ) : ?>
				<input type="hidden" class="ffc-required-data-type" name="<?php echo esc_attr( $prefix . '[type]' ); ?>" value="email">
				<select disabled aria-label="<?php esc_attr_e( 'Type', 'ffcertificate' ); ?>"><option><?php echo esc_html( $types['email'] ); ?></option></select>
			<?php else : ?>
				<select class="ffc-required-data-type" name="<?php echo esc_attr( $prefix . '[type]' ); ?>" aria-label="<?php esc_attr_e( 'Type', 'ffcertificate' ); ?>">
					<?php foreach ( $types as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $row['type'], $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>
		</td>
		<td>
			<?php
			if ( $generated ) {
				echo '<span class="description">&mdash;</span>';
			} else {
				\FreeFormCertificate\Admin\AdminUI::render_toggle(
					array(
						'name'        => $prefix . '[form]',
						'id'          => $id_prefix . '_form',
						'checked'     => $row['form'] || $is_email,
						'disabled'    => $is_email,
						'input_class' => 'ffc-required-data-form',
						'aria_label'  => __( 'Required in the form', 'ffcertificate' ),
						'mark'        => true,
					)
				);
			}
			?>
		</td>
		<td>
			<?php
			\FreeFormCertificate\Admin\AdminUI::render_toggle(
				array(
					'name'        => $prefix . '[html]',
					'id'          => $id_prefix . '_html',
					'checked'     => $row['html'] || $is_auth,
					'disabled'    => $is_auth,
					'input_class' => 'ffc-required-data-html',
					'aria_label'  => __( 'Required in the certificate HTML', 'ffcertificate' ),
					'mark'        => true,
				)
			);
			?>
		</td>
		<td>
			<button type="button" class="button ffc-required-data-remove" <?php disabled( $is_email || $is_auth ); ?>><?php esc_html_e( 'Remove', 'ffcertificate' ); ?></button>
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
					<label for="quiz_focus_grace_ms"><?php esc_html_e( 'Quiz focus-loss grace (ms)', 'ffcertificate' ); ?></label>
				</th>
				<td>
					<input type="number" name="ffc_settings[quiz_focus_grace_ms]" id="quiz_focus_grace_ms" value="<?php echo esc_attr( (string) \FreeFormCertificate\Settings\SettingsReader::quiz_focus_grace_ms() ); ?>" min="<?php echo esc_attr( (string) \FreeFormCertificate\Core\QuizLeaveLevel::GRACE_MIN_MS ); ?>" max="<?php echo esc_attr( (string) \FreeFormCertificate\Core\QuizLeaveLevel::GRACE_MAX_MS ); ?>" step="100" class="small-text" data-ffc-autosave-key="quiz_focus_grace_ms" required>
					<p class="description">
						<?php esc_html_e( 'Used by quizzes set to Strict on leaving the window: a loss of focus counts only when it lasts longer than this, so a system notification or a password manager does not clear the answers. 0 counts every loss.', 'ffcertificate' ); ?>
					</p>
				</td>
			</tr>
		</tbody>
	</table>
</div>

<div class="card">
	<h2 class="ffc-icon-list"><?php esc_html_e( 'Required data', 'ffcertificate' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'What every certificate needs, in one table. Required in the form: a new form starts with the field, and in the form editor its name, type and required flag are locked; only the label can change. Required in the certificate HTML: every certificate layout must print the field through its name in braces, such as {{cpf_rf}}. Changes save as you make them.', 'ffcertificate' ); ?>
	</p>
	<table class="widefat striped ffc-required-data-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Field name', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Label', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Type', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Required in the form', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Required in the certificate HTML', 'ffcertificate' ); ?></th>
				<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Remove', 'ffcertificate' ); ?></span></th>
			</tr>
		</thead>
		<tbody id="ffc-required-data-rows" data-ffc-next-index="<?php echo esc_attr( (string) count( $ffcertificate_req_rows ) ); ?>" data-ffc-autosave-key="required_data" data-ffc-autosave-debounce="800">
			<?php
			foreach ( $ffcertificate_req_rows as $ffcertificate_i => $ffcertificate_row ) {
				$ffcertificate_data_row( (string) $ffcertificate_i, $ffcertificate_row, $ffcertificate_types );
			}
			?>
		</tbody>
	</table>
	<template id="ffc-required-data-template">
		<?php
		$ffcertificate_data_row(
			'__index__',
			array(
				'name'  => '',
				'label' => '',
				'type'  => 'text',
				'form'  => true,
				'html'  => false,
			),
			$ffcertificate_types
		);
		?>
	</template>
	<p>
		<button type="button" class="button ffc-icon-plus" id="ffc-required-data-add"><?php esc_html_e( 'Add required data', 'ffcertificate' ); ?></button>
	</p>
	<p class="description">
		<?php esc_html_e( 'A name takes lowercase letters, digits and underscores. Email is always required in the form: the certificate is sent to it. {{auth_code}} is always required in the certificate HTML: verification depends on it. A tag the plugin fills in itself, such as {{schedule}}, has no form field; {{schedule}} is asked only of a form that has an Event Schedule.', 'ffcertificate' ); ?>
	</p>
</div>

	<?php submit_button(); ?>

</form>

</div><!-- .ffc-settings-wrap -->

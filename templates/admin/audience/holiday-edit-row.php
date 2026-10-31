<?php
/**
 * Template: the edit row of a holiday or closed date (#2212).
 *
 * Printed right after the row it edits, hidden until that row's Edit button
 * opens it. It is a real form in one cell spanning the row, so Save posts
 * without any script; `ffc-audience-admin.js` only swaps the two rows and
 * puts the values back on Cancel.
 *
 * In-scope: $edit_row (array) with
 *   - `key` (string, unique on the page: field ids derive from it),
 *   - `colspan` (int),
 *   - `action` (string, the `ffc_action` value),
 *   - `nonce_action` / `nonce_name` (string),
 *   - `hidden` (array<string, string|int>, further hidden fields),
 *   - `date`, `kind`, `description` (string, the stored values).
 *
 * @package FreeFormCertificate\Audience
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ffc_edit_key = (string) $edit_row['key'];
?>
<tr class="ffc-holiday-edit-row" data-ffc-holiday-edit="<?php echo esc_attr( $ffc_edit_key ); ?>" hidden>
	<td colspan="<?php echo esc_attr( (string) (int) $edit_row['colspan'] ); ?>">
		<form method="post" action="" class="ffc-holiday-edit-form">
			<?php wp_nonce_field( (string) $edit_row['nonce_action'], (string) $edit_row['nonce_name'] ); ?>
			<input type="hidden" name="ffc_action" value="<?php echo esc_attr( (string) $edit_row['action'] ); ?>">
			<?php foreach ( (array) $edit_row['hidden'] as $ffc_hidden_name => $ffc_hidden_value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( (string) $ffc_hidden_name ); ?>" value="<?php echo esc_attr( (string) $ffc_hidden_value ); ?>">
			<?php endforeach; ?>
			<div class="ffc-holiday-edit-field">
				<label for="holiday_edit_date_<?php echo esc_attr( $ffc_edit_key ); ?>"><?php esc_html_e( 'Date', 'ffcertificate' ); ?></label>
				<input type="date" id="holiday_edit_date_<?php echo esc_attr( $ffc_edit_key ); ?>" name="holiday_edit_date" value="<?php echo esc_attr( (string) $edit_row['date'] ); ?>" required>
			</div>
			<div class="ffc-holiday-edit-field">
				<?php
				$ffc_kind_args          = \FreeFormCertificate\Scheduling\ClosedDateKind::control_args( 'holiday_edit_kind' );
				$ffc_kind_args['id']    = 'holiday_edit_kind_' . $ffc_edit_key;
				$ffc_kind_args['value'] = \FreeFormCertificate\Scheduling\ClosedDateKind::normalize( (string) $edit_row['kind'] );
				\FreeFormCertificate\Admin\AdminUI::render_segmented( $ffc_kind_args );
				?>
			</div>
			<div class="ffc-holiday-edit-field ffc-holiday-edit-description">
				<label for="holiday_edit_description_<?php echo esc_attr( $ffc_edit_key ); ?>"><?php esc_html_e( 'Description', 'ffcertificate' ); ?></label>
				<input type="text" id="holiday_edit_description_<?php echo esc_attr( $ffc_edit_key ); ?>" name="holiday_edit_description" value="<?php echo esc_attr( (string) $edit_row['description'] ); ?>">
			</div>
			<div class="ffc-holiday-edit-actions">
				<?php
				\FreeFormCertificate\Admin\AdminUI::render_button(
					array(
						'label'    => __( 'Save', 'ffcertificate' ),
						'category' => 'primary',
						'icon'     => 'save',
						'type'     => 'submit',
					)
				);
				\FreeFormCertificate\Admin\AdminUI::render_button(
					array(
						'label'    => __( 'Cancel', 'ffcertificate' ),
						'category' => 'neutral',
						'icon'     => 'cross',
						'type'     => 'button',
						'attrs'    => array( 'data-ffc-holiday-cancel' => $ffc_edit_key ),
					)
				);
				?>
			</div>
		</form>
	</td>
</tr>

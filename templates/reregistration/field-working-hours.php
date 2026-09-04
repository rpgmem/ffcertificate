<?php
/**
 * Template: Reregistration frontend working-hours table.
 *
 * Extracted verbatim from ReregistrationFormRenderer::render_working_hours_field() (coverage
 * hygiene, rpgmem/ffcertificate); markup byte-identical. The renderer
 * prepares the locals and includes this file (method locals + self::
 * sibling renderers resolve in the including method scope).
 *
 * Expected in scope: $field_id, $field_name, $wh_data, $days_labels.
 *
 * @package FreeFormCertificate\Reregistration
 * @since   6.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
		<?php $wh_json = wp_json_encode( $wh_data ); ?>
		<input type="hidden" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $field_name ); ?>" value="<?php echo esc_attr( $wh_json ? $wh_json : '' ); ?>">
		<div class="ffc-working-hours" data-target="<?php echo esc_attr( $field_id ); ?>">
			<table class="ffc-wh-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Day', 'ffcertificate' ); ?></th>
						<th><?php esc_html_e( 'Entry 1', 'ffcertificate' ); ?> <span class="required">*</span></th>
						<th><?php esc_html_e( 'Exit 1', 'ffcertificate' ); ?></th>
						<th><?php esc_html_e( 'Entry 2', 'ffcertificate' ); ?></th>
						<th><?php esc_html_e( 'Exit 2', 'ffcertificate' ); ?> <span class="required">*</span></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $wh_data as $wh_entry ) : ?>
					<tr>
						<td>
							<select class="ffc-wh-day">
								<?php foreach ( $days_labels as $d_num => $d_name ) : ?>
									<option value="<?php echo esc_attr( (string) $d_num ); ?>" <?php selected( $wh_entry['day'] ?? 0, $d_num ); ?>><?php echo esc_html( $d_name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td><input type="time" class="ffc-wh-entry1" value="<?php echo esc_attr( $wh_entry['entry1'] ?? '' ); ?>" required></td>
						<td><input type="time" class="ffc-wh-exit1"  value="<?php echo esc_attr( $wh_entry['exit1'] ?? '' ); ?>"></td>
						<td><input type="time" class="ffc-wh-entry2" value="<?php echo esc_attr( $wh_entry['entry2'] ?? '' ); ?>"></td>
						<td><input type="time" class="ffc-wh-exit2"  value="<?php echo esc_attr( $wh_entry['exit2'] ?? '' ); ?>" required></td>
						<td><button type="button" class="ffc-wh-remove">&times;</button></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<button type="button" class="button ffc-wh-add">+ <?php esc_html_e( 'Add Day', 'ffcertificate' ); ?></button>
		</div>

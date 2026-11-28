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
			<?php
			// Where the table fits, it keeps its columns; where it does not,
			// each day stacks as a card with its labels (#2964), and the
			// region still scrolls sideways as a fallback (#2242).
			?>
			<div class="ffc-wh-scroll" role="region" tabindex="0" aria-label="<?php esc_attr_e( 'Work Hours', 'ffcertificate' ); ?>">
			<table class="ffc-wh-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Day', 'ffcertificate' ); ?></th>
						<th><?php esc_html_e( 'Entry 1', 'ffcertificate' ); ?> <span class="ffc-required" aria-hidden="true">*</span></th>
						<th><?php esc_html_e( 'Exit 1', 'ffcertificate' ); ?></th>
						<th><?php esc_html_e( 'Entry 2', 'ffcertificate' ); ?></th>
						<th><?php esc_html_e( 'Exit 2', 'ffcertificate' ); ?> <span class="ffc-required" aria-hidden="true">*</span></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $wh_data as $wh_entry ) : ?>
					<tr>
						<td data-label="<?php esc_attr_e( 'Day', 'ffcertificate' ); ?>">
							<select class="ffc-wh-day" aria-label="<?php esc_attr_e( 'Day', 'ffcertificate' ); ?>">
								<?php foreach ( $days_labels as $d_num => $d_name ) : ?>
									<option value="<?php echo esc_attr( (string) $d_num ); ?>" <?php selected( $wh_entry['day'] ?? 0, $d_num ); ?>><?php echo esc_html( $d_name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td data-label="<?php esc_attr_e( 'Entry 1', 'ffcertificate' ); ?>"><input type="time" class="ffc-wh-entry1" aria-label="<?php esc_attr_e( 'Entry 1', 'ffcertificate' ); ?>" value="<?php echo esc_attr( $wh_entry['entry1'] ?? '' ); ?>" required></td>
						<td data-label="<?php esc_attr_e( 'Exit 1', 'ffcertificate' ); ?>"><input type="time" class="ffc-wh-exit1" aria-label="<?php esc_attr_e( 'Exit 1', 'ffcertificate' ); ?>"  value="<?php echo esc_attr( $wh_entry['exit1'] ?? '' ); ?>"></td>
						<td data-label="<?php esc_attr_e( 'Entry 2', 'ffcertificate' ); ?>"><input type="time" class="ffc-wh-entry2" aria-label="<?php esc_attr_e( 'Entry 2', 'ffcertificate' ); ?>" value="<?php echo esc_attr( $wh_entry['entry2'] ?? '' ); ?>"></td>
						<td data-label="<?php esc_attr_e( 'Exit 2', 'ffcertificate' ); ?>"><input type="time" class="ffc-wh-exit2" aria-label="<?php esc_attr_e( 'Exit 2', 'ffcertificate' ); ?>"  value="<?php echo esc_attr( $wh_entry['exit2'] ?? '' ); ?>" required></td>
						<td class="ffc-wh-actions">
							<?php
							\FreeFormCertificate\Core\Button::render(
								array(
									'label'     => __( 'Remove day', 'ffcertificate' ),
									'category'  => 'danger',
									'icon'      => 'cross',
									'icon_only' => true,
									'class'     => 'ffc-wh-remove',
								)
							);
							?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<?php
			\FreeFormCertificate\Core\Button::render(
				array(
					'label'    => __( 'Add Day', 'ffcertificate' ),
					'category' => 'create',
					'icon'     => 'plus',
					'class'    => 'ffc-wh-add',
				)
			);
			?>
		</div>

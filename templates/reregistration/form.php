<?php
/**
 * Template: Reregistration frontend form container.
 *
 * Extracted verbatim from ReregistrationFormRenderer::render() (coverage
 * hygiene, rpgmem/ffcertificate); markup byte-identical. The renderer
 * prepares the locals and includes this file (method locals + self::
 * sibling renderers resolve in the including method scope).
 *
 * Expected in scope: $rereg, $end_date, $grouped, $group_labels, $values.
 *
 * @package FreeFormCertificate\Reregistration
 * @since   6.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Template variables scoped to this file (the include runs in the including renderer method scope).
?>
		<div class="ffc-rereg-form-container" data-reregistration-id="<?php echo esc_attr( (string) $rereg->id ); ?>">
			<div class="ffc-rereg-header-bar">
				<div class="ffc-rereg-header-title"><?php echo esc_html__( 'CITY HALL OF SÃO PAULO / DEPARTMENT OF EDUCATION – SME', 'ffcertificate' ); ?></div>
				<div class="ffc-rereg-header-subtitle"><?php echo esc_html__( 'REGIONAL EDUCATION BOARD SÃO MIGUEL – MP', 'ffcertificate' ); ?></div>
			</div>

			<h3><?php echo esc_html( $rereg->title ); ?></h3>
			<p class="ffc-rereg-deadline">
				<?php
				/* translators: %s: end date */
				echo esc_html( sprintf( __( 'Deadline: %s', 'ffcertificate' ), $end_date ) );
				?>
			</p>

			<form id="ffc-rereg-form" novalidate>
				<input type="hidden" name="reregistration_id" value="<?php echo esc_attr( (string) $rereg->id ); ?>">

				<?php
				$group_index = 0;
				$has_ack     = false;
				foreach ( $grouped as $group_key => $group_fields ) {
					++$group_index;
					foreach ( $group_fields as $gf ) {
						if ( 'acknowledgment' === (string) $gf->field_type ) {
							$has_ack = true;
							break;
						}
					}
					$label = $group_labels[ $group_key ] ?? ( '' !== $group_key ? $group_key : __( 'Additional Information', 'ffcertificate' ) );
					self::render_group_fieldset( $group_index, (string) $label, $group_fields, $values );
				}

				// Fallback for audiences seeded before the acknowledgment field
				// existed: render the default notice so the form is never missing
				// its legal block. Seeded audiences render it via the loop above.
				if ( ! $has_ack ) {
					self::render_acknowledgment_fieldset( $group_index + 1 );
				}

				// Honeypot field (defense-in-depth — form already requires login).
				?>
				<div class="ffc-honeypot-field">
					<label><?php esc_html_e( 'Do not fill this field if you are human:', 'ffcertificate' ); ?>
						<input type="text" name="ffc_honeypot_trap" value="" tabindex="-1" autocomplete="off">
					</label>
				</div>

				<div class="ffc-rereg-actions">
					<button type="button" class="button ffc-rereg-draft-btn"><?php esc_html_e( 'Save Draft', 'ffcertificate' ); ?></button>
					<button type="submit" class="button button-primary ffc-rereg-submit-btn"><?php esc_html_e( 'Submit', 'ffcertificate' ); ?></button>
					<button type="button" class="button ffc-rereg-cancel-btn"><?php esc_html_e( 'Cancel', 'ffcertificate' ); ?></button>
					<span class="ffc-rereg-status"></span>
				</div>
			</form>
		</div>

<?php
/**
 * Template: Reregistration frontend checkbox field wrapper.
 *
 * Extracted verbatim from ReregistrationFormRenderer::render_field() (coverage
 * hygiene, rpgmem/ffcertificate); markup byte-identical. The renderer
 * prepares the locals and includes this file (method locals + self::
 * sibling renderers resolve in the including method scope).
 *
 * Expected in scope: $field, $field_id, $field_name, $value, $required, $rules,
 * $error (a message the server already has for the field, or '').
 *
 * @package FreeFormCertificate\Reregistration
 * @since   6.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
			<div class="ffc-rereg-field" data-field-id="<?php echo esc_attr( (string) $field->id ); ?>"
				data-field-key="<?php echo esc_attr( (string) $field->field_key ); ?>">
				<?php self::render_input( $field, $field_id, $field_name, $value, $required, $rules, $error ); ?>
				<?php echo \FreeFormCertificate\Core\FieldMessage::render( $field_id, $error ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() escapes the id, the classes and the text. ?>
			</div>

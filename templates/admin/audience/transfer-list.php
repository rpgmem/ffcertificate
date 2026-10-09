<?php
/**
 * Template: audience transfer list (#1648).
 *
 * Printed by AudienceTransferList::render(); the script fills both columns
 * from the data attributes and posts one hidden input per chosen id.
 *
 * @var string|false $flat_json     Every audience, flattened (JSON).
 * @var string|false $selected_json Chosen ids (JSON).
 * @var string       $field_name    Name of each posted hidden input.
 * @var bool         $required      Whether a submit with nothing chosen is refused.
 *
 * @package FreeFormCertificate\Audience
 * @since   6.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="ffc-transfer-list" data-audiences="<?php echo esc_attr( $flat_json ? $flat_json : '[]' ); ?>" data-selected="<?php echo esc_attr( $selected_json ? $selected_json : '[]' ); ?>" data-field-name="<?php echo esc_attr( $field_name ); ?>"<?php echo $required ? ' data-required="1"' : ''; ?>>
	<div class="ffc-transfer-col ffc-transfer-available">
		<div class="ffc-transfer-header"><?php esc_html_e( 'Available', 'ffcertificate' ); ?></div>
		<input type="text" class="ffc-transfer-search" placeholder="<?php esc_attr_e( 'Filter...', 'ffcertificate' ); ?>">
		<div class="ffc-transfer-items"></div>
	</div>
	<div class="ffc-transfer-actions">
		<button type="button" class="button ffc-transfer-add" title="<?php esc_attr_e( 'Add selected', 'ffcertificate' ); ?>">&rsaquo;</button>
		<button type="button" class="button ffc-transfer-add-all" title="<?php esc_attr_e( 'Add all', 'ffcertificate' ); ?>">&raquo;</button>
		<button type="button" class="button ffc-transfer-remove" title="<?php esc_attr_e( 'Remove selected', 'ffcertificate' ); ?>">&lsaquo;</button>
		<button type="button" class="button ffc-transfer-remove-all" title="<?php esc_attr_e( 'Remove all', 'ffcertificate' ); ?>">&laquo;</button>
	</div>
	<div class="ffc-transfer-col ffc-transfer-selected">
		<div class="ffc-transfer-header"><?php esc_html_e( 'Selected', 'ffcertificate' ); ?></div>
		<div class="ffc-transfer-items"></div>
	</div>
	<div class="ffc-transfer-hidden-inputs"></div>
</div>

<?php
/**
 * Template: Reregistration admin — Audience transfer list.
 *
 * Extracted verbatim from the matching ReregistrationAdminRenderer method
 * (rpgmem/ffcertificate#563 coverage hygiene); markup byte-identical. The
 * renderer prepares the locals and includes this file (method params + locals
 * + self:: sibling renderers resolve in the including method scope).
 *
 * @package FreeFormCertificate\Reregistration
 * @since   6.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- Template variables scoped to this file (the include runs in the including renderer method scope).
?>
		<?php
			$flat_json     = wp_json_encode( $flat );
			$selected_json = wp_json_encode( array_values( $selected_ids ) );
		?>
		<div class="ffc-transfer-list" data-audiences="<?php echo esc_attr( $flat_json ? $flat_json : '' ); ?>" data-selected="<?php echo esc_attr( $selected_json ? $selected_json : '' ); ?>">
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
		<p class="description ffc-transfer-member-count"></p>
		<?php

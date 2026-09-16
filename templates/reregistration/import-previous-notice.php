<?php
/**
 * Template: the offer to import the last approved reregistration.
 *
 * The notice is only included when a source exists, so there is no conditional
 * here: the renderer is what decides. Nothing is fetched until the participant
 * clicks -- the button fires `ffc_import_previous_reregistration`.
 *
 * Expected in scope: $ffc_import_source_title.
 *
 * @package FreeFormCertificate\Reregistration
 * @since   6.25.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
		<div class="ffc-rereg-import-notice" role="status">
			<p>
				<?php
				printf(
					/* translators: %s: title of the previous approved campaign. */
					esc_html__( 'You have an approved reregistration from %s. Do you want to bring those answers into this form?', 'ffcertificate' ),
					'<strong>' . esc_html( $ffc_import_source_title ) . '</strong>'
				);
				?>
			</p>
			<p class="description">
				<?php esc_html_e( 'Review every field afterwards — the data is from a previous cycle and may be out of date.', 'ffcertificate' ); ?>
			</p>
			<button type="button" class="button ffc-rereg-import-btn">
				<?php esc_html_e( 'Bring previous answers', 'ffcertificate' ); ?>
			</button>
			<span class="ffc-rereg-import-status" role="status"></span>
		</div>

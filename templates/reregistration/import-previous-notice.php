<?php
/**
 * Template: oferta de importar o último recadastramento aprovado.
 *
 * O aviso só é incluído quando existe origem, então aqui não há condicional:
 * quem decide é o renderizador. Nada é buscado até o participante clicar --
 * o botão dispara `ffc_import_previous_reregistration`.
 *
 * Esperado em escopo: $ffc_import_source_title.
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

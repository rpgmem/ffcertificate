<?php
/**
 * Form Editor Layout Metabox Renderer
 *
 * Extracted from FormEditorMetaboxRenderer as part of S3 god-object refactor.
 *
 * @since   3.2.0
 * @package FreeFormCertificate\Admin
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Form Editor Layout Metabox Renderer.
 *
 * @since 3.2.0
 */
class FormEditorLayoutMetabox {

	/**
	 * Section 1: Certificate Layout Editor
	 *
	 * @param WP_Post $post The post object.
	 */
	public function render( WP_Post $post ): void {
		$config   = get_post_meta( $post->ID, '_ffc_form_config', true );
		$layout   = isset( $config['pdf_layout'] ) ? $config['pdf_layout'] : '';
		$bg_image = isset( $config['bg_image'] ) ? $config['bg_image'] : '';
		$bg_color = \FreeFormCertificate\Core\FormBackground::normalize_color( (string) ( $config['bg_color'] ?? '' ) );

		// The Load button opens a modal built from the same DB-backed pool
		// (#865), localized as `ffc_ajax.templates`; it is drawn only when the
		// pool has something to offer. A hidden <select> once sat beside it
		// that no script read (#1625).
		$templates = CertTemplateReader::list_for_editor();

		wp_nonce_field( 'ffc_save_form_data', 'ffc_form_nonce' );
		?>
		<table class="form-table">
			<tr>
				<th><label><?php esc_html_e( 'Actions', 'ffcertificate' ); ?></label></th>
				<td>
					<div class="ffc-admin-flex-row ffc-flex-wrap">
						<div class="ffc-action-group">
							<input type="file" id="ffc_import_html_file" accept=".html,.txt" class="ffc-hidden">
							<?php
							\FreeFormCertificate\Admin\AdminUI::render_button(
								array(
									'label' => __( 'Import HTML', 'ffcertificate' ),
									'icon'  => 'upload',
									'id'    => 'ffc_btn_import_html',
								)
							);
							\FreeFormCertificate\Admin\AdminUI::render_button(
								array(
									'label' => __( 'Background Image', 'ffcertificate' ),
									'icon'  => 'image',
									'id'    => 'ffc_btn_media_lib',
								)
							);
							\FreeFormCertificate\Admin\AdminUI::render_button(
								array(
									'label'    => __( 'Insert Image', 'ffcertificate' ),
									'category' => 'create',
									'icon'     => 'image-plus',
									'id'       => 'ffc_btn_insert_image',
								)
							);
							\FreeFormCertificate\Admin\AdminUI::render_button(
								array(
									'label' => __( 'Preview', 'ffcertificate' ),
									'icon'  => 'eye',
									'id'    => 'ffc_btn_preview',
								)
							);
							\FreeFormCertificate\Admin\AdminUI::render_button(
								array(
									'label'    => __( 'Save as model', 'ffcertificate' ),
									'category' => 'create',
									'icon'     => 'template-save',
									'id'       => 'ffc_save_as_model_btn',
								)
							);
							?>
						</div>

						<?php if ( $templates ) : ?>
						<div class="ffc-template-loader">
							<?php
							\FreeFormCertificate\Admin\AdminUI::render_button(
								array(
									'label' => __( 'Load', 'ffcertificate' ),
									'icon'  => 'template-load',
									'id'    => 'ffc_load_template_btn',
								)
							);
							?>
						</div>
						<?php endif; ?>
					</div>
				</td>
			</tr>
			<tr>
				<td colspan="2">
					<label class="ffc-block-label"><strong><?php esc_html_e( 'Certificate HTML Editor', 'ffcertificate' ); ?></strong></label>
					<div class="ffc-code-editor-wrapper">
						<textarea name="ffc_config[pdf_layout]" id="ffc_pdf_layout" class="ffc-w100" rows="12"><?php echo esc_textarea( $layout ); ?></textarea>
					</div>
					<p class="description">
						<?php echo \FreeFormCertificate\Admin\AdminUI::get_required_tags_line(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped where it is built. ?>
					</p>

					<div class="ffc-input-group ffc-mt-xl">
						<label class="ffc-block-label"><strong><?php esc_html_e( 'Background Image URL:', 'ffcertificate' ); ?></strong></label>
						<input type="text" name="ffc_config[bg_image]" id="ffc_bg_image_input" value="<?php echo esc_url( $bg_image ); ?>" class="ffc-w100">
						<?php // Filled in by `ffc-admin-pdf.js` when the "Background Image" button picks an image; empty until then. ?>
						<div id="ffc_bg_image_preview"></div>
					</div>

					<div class="ffc-input-group ffc-mt-xl">
						<label class="ffc-block-label" for="ffc_bg_color_input"><strong><?php esc_html_e( 'Background Color:', 'ffcertificate' ); ?></strong></label>
						<input type="color" name="ffc_config[bg_color]" id="ffc_bg_color_input" value="<?php echo esc_attr( $bg_color ); ?>">
						<p class="description"><?php esc_html_e( 'Painted behind the background image, and alone when there is no image.', 'ffcertificate' ); ?></p>
					</div>
				</td>
			</tr>
		</table>
		<?php
	}
}

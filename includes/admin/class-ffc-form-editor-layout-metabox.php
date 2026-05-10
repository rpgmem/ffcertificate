<?php
/**
 * Form Editor Layout Metabox Renderer
 *
 * Extracted from FormEditorMetaboxRenderer as part of S3 god-object refactor.
 *
 * @since   3.1.1
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
 * @since 3.1.1
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

		$templates_dir = FFC_PLUGIN_DIR . 'html/';
		$templates     = glob( $templates_dir . '*.html' );

		wp_nonce_field( 'ffc_save_form_data', 'ffc_form_nonce' );
		?>
		<table class="form-table">
			<tr>
				<th><label><?php esc_html_e( 'Actions', 'ffcertificate' ); ?></label></th>
				<td>
					<div class="ffc-admin-flex-row ffc-flex-wrap">
						<div class="ffc-action-group">
							<input type="file" id="ffc_import_html_file" accept=".html,.txt" class="ffc-hidden">
							<button type="button" class="button" id="ffc_btn_import_html">
								<?php esc_html_e( 'Import HTML', 'ffcertificate' ); ?>
							</button>
							<button type="button" class="button" id="ffc_btn_media_lib">
								<?php esc_html_e( 'Background Image', 'ffcertificate' ); ?>
							</button>
							<button type="button" class="button button-primary" id="ffc_btn_preview">
								<span class="dashicons dashicons-visibility" style="vertical-align:text-bottom;margin-right:2px;"></span>
								<?php esc_html_e( 'Preview', 'ffcertificate' ); ?>
							</button>
						</div>

						<?php if ( $templates ) : ?>
						<div class="ffc-template-loader">
							<select id="ffc_template_select">
								<option value=""><?php esc_html_e( 'Select Server Template...', 'ffcertificate' ); ?></option>
								<?php
								foreach ( $templates as $tpl ) :
									$filename = basename( $tpl );
									?>
									<option value="<?php echo esc_attr( $filename ); ?>"><?php echo esc_html( $filename ); ?></option>
								<?php endforeach; ?>
							</select>
							<button type="button" id="ffc_load_template_btn" class="button"><?php esc_html_e( 'Load', 'ffcertificate' ); ?></button>
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
						<?php esc_html_e( 'Mandatory Tags:', 'ffcertificate' ); ?> <code>{{auth_code}}</code>, <code>{{name}}</code>, <code>{{cpf_rf}}</code>.
					</p>

					<div class="ffc-input-group ffc-mt15">
						<label class="ffc-block-label"><strong><?php esc_html_e( 'Background Image URL:', 'ffcertificate' ); ?></strong></label>
						<input type="text" name="ffc_config[bg_image]" id="ffc_bg_image_input" value="<?php echo esc_url( $bg_image ); ?>" class="ffc-w100">
					</div>
				</td>
			</tr>
		</table>
		<?php
	}
}

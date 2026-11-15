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

		// The form prints its own copy, or references a template (#2581).
		$linked_id   = \FreeFormCertificate\Core\CertificateTemplateLink::template_id( (int) $post->ID );
		$stored_id   = \FreeFormCertificate\Core\CertificateTemplateLink::stored_template_id( (int) $post->ID );
		$own_hidden  = $linked_id > 0;
		$link_values = \FreeFormCertificate\Core\CertificateTemplateLink::values( (int) $post->ID );

		wp_nonce_field( 'ffc_save_form_data', 'ffc_form_nonce' );
		?>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Layout source', 'ffcertificate' ); ?></th>
				<td>
					<?php
					AdminUI::render_segmented(
						array(
							'name'         => 'ffc_layout_source',
							'id'           => 'ffc_layout_source',
							'legend'       => __( 'Layout source', 'ffcertificate' ),
							'options'      => array(
								'own'      => __( 'Own layout', 'ffcertificate' ),
								'template' => __( 'Template', 'ffcertificate' ),
							),
							'value'        => $linked_id > 0 ? 'template' : 'own',
							'descriptions' => array(
								'own'      => __( 'The form keeps its own copy of the HTML; editing a template does not change it.', 'ffcertificate' ),
								'template' => __( 'The form prints a template from the pool; editing the template changes this form and its certificates.', 'ffcertificate' ),
							),
						)
					);
					if ( $stored_id > 0 && 0 === $linked_id ) {
						\FreeFormCertificate\Core\AdminNotice::callout(
							'warning',
							'<p>' . esc_html__( 'The template this form referenced is no longer available, so it prints its own layout. Choose another template or keep the own layout.', 'ffcertificate' ) . '</p>',
							array( 'classes' => array( 'inline' ) )
						);
					}
					$this->render_template_link( $linked_id, $link_values );
					?>
				</td>
			</tr>
			<tr class="ffc-layout-own"<?php echo $own_hidden ? ' hidden' : ''; ?>>
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
			<tr class="ffc-layout-own"<?php echo $own_hidden ? ' hidden' : ''; ?>>
				<td colspan="2">
					<label class="ffc-block-label"><strong><?php esc_html_e( 'Certificate HTML Editor', 'ffcertificate' ); ?></strong></label>
					<?php
					// Certificates are regenerated from the current layout, so
					// an edit reaches the ones already issued (#2241).
					$issued = 'auto-draft' === $post->post_status ? 0 : ( new \FreeFormCertificate\Repositories\SubmissionRepository() )->countLiveByForm( (int) $post->ID );
					if ( $issued > 0 ) {
						\FreeFormCertificate\Core\AdminNotice::callout(
							'warning',
							'<p>' . esc_html(
								sprintf(
									/* translators: %s: number of certificates the form has issued */
									_n(
										'This form has %s issued certificate; layout changes apply to it the next time it is generated or verified.',
										'This form has %s issued certificates; layout changes apply to them the next time they are generated or verified.',
										$issued,
										'ffcertificate'
									),
									number_format_i18n( $issued )
								)
							) . '</p>',
							array( 'classes' => array( 'ffc-layout-issued-notice', 'inline' ) )
						);
					}
					// The fonts a template can name, or how to install one (#2569).
					\FreeFormCertificate\Core\CertificateFonts::render_notice();
					\FreeFormCertificate\Core\CertificateFonts::render_missing_notice( (string) $layout );
					// A copy of an earlier default model is offered the current one (#2577).
					DefaultLayoutVersions::render_notice( (string) $layout );
					?>
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

	/**
	 * The template picker, and the inputs for each template's declared fields
	 * (#2581). Every template's inputs are printed and only the chosen one's
	 * show, so switching needs no request; the save reads only the chosen one.
	 *
	 * @param int                   $linked_id The template the form references, or 0.
	 * @param array<string, string> $values    The values the form filled.
	 */
	private function render_template_link( int $linked_id, array $values ): void {
		$templates = CertTemplateReader::list_for_editor();
		$ids       = array_map( 'intval', array_column( $templates, 'id' ) );
		if ( $linked_id > 0 && ! in_array( $linked_id, $ids, true ) ) {
			// A hidden template the form already references stays choosable.
			$templates[] = array(
				'id'    => $linked_id,
				'label' => get_the_title( $linked_id ),
			);
		}
		?>
		<div class="ffc-layout-template ffc-mt-md"<?php echo 0 === $linked_id ? ' hidden' : ''; ?>>
			<?php if ( array() === $templates ) : ?>
				<p class="description"><?php esc_html_e( 'The template pool has no certificate template to reference.', 'ffcertificate' ); ?></p>
			<?php else : ?>
				<p>
					<label for="ffc_layout_template_id" class="ffc-block-label"><strong><?php esc_html_e( 'Template', 'ffcertificate' ); ?></strong></label>
					<select name="ffc_layout_template_id" id="ffc_layout_template_id">
						<option value="0"><?php esc_html_e( '— Choose a template —', 'ffcertificate' ); ?></option>
						<?php foreach ( $templates as $template ) : ?>
							<option value="<?php echo esc_attr( (string) $template['id'] ); ?>" <?php selected( (int) $template['id'], $linked_id ); ?>><?php echo esc_html( (string) $template['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<?php
				foreach ( $templates as $template ) {
					$this->render_template_panel( (int) $template['id'], (int) $template['id'] === $linked_id, $values );
				}
				?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * One template's panel: who else uses it, its links and its fields.
	 *
	 * @param int                   $template_id Template post id.
	 * @param bool                  $shown       Whether it is the chosen one.
	 * @param array<string, string> $values      The values the form filled.
	 */
	private function render_template_panel( int $template_id, bool $shown, array $values ): void {
		$fields = \FreeFormCertificate\Core\CertificateTemplateLink::fields( $template_id );
		$forms  = count( \FreeFormCertificate\Core\CertificateTemplateLink::forms_using( $template_id ) );
		$edit   = get_edit_post_link( $template_id, 'raw' );
		?>
		<div class="ffc-layout-template-panel" data-template-id="<?php echo esc_attr( (string) $template_id ); ?>"<?php echo $shown ? '' : ' hidden'; ?>>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: number of forms */
						_n( 'Used by %s form.', 'Used by %s forms.', $forms, 'ffcertificate' ),
						number_format_i18n( $forms )
					)
				);
				?>
			</p>
			<div class="ffc-action-group">
				<?php
				AdminUI::render_button(
					array(
						'label' => __( 'Preview', 'ffcertificate' ),
						'icon'  => 'eye',
						'class' => 'ffc-btn-preview-template',
					)
				);
				if ( is_string( $edit ) && '' !== $edit && current_user_can( 'edit_post', $template_id ) ) {
					AdminUI::render_button(
						array(
							'label' => __( 'Edit template', 'ffcertificate' ),
							'icon'  => 'edit',
							'href'  => $edit,
						)
					);
				}
				?>
			</div>
			<?php if ( array() === $fields ) : ?>
				<p class="description"><?php esc_html_e( 'This template declares no fields for the form to fill.', 'ffcertificate' ); ?></p>
			<?php else : ?>
				<h4><?php esc_html_e( 'Template fields', 'ffcertificate' ); ?></h4>
				<?php
				foreach ( $fields as $field ) {
					$id    = 'ffc_template_value_' . $template_id . '_' . $field['key'];
					$name  = 'ffc_template_values[' . $template_id . '][' . $field['key'] . ']';
					$value = $values[ $field['key'] ] ?? '';
					?>
					<p class="ffc-input-group">
						<label for="<?php echo esc_attr( $id ); ?>" class="ffc-block-label">
							<strong><?php echo esc_html( $field['label'] ); ?></strong>
							<?php if ( $field['required'] ) : ?>
								<?php esc_html_e( '(required)', 'ffcertificate' ); ?>
							<?php endif; ?>
							<code>{{model:<?php echo esc_html( $field['key'] ); ?>}}</code>
						</label>
						<?php if ( 'textarea' === $field['type'] ) : ?>
							<textarea name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $id ); ?>" rows="3" class="ffc-w100" placeholder="<?php echo esc_attr( $field['default'] ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
						<?php else : ?>
							<input type="<?php echo 'date' === $field['type'] ? 'date' : 'text'; ?>" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $id ); ?>" value="<?php echo esc_attr( $value ); ?>" class="<?php echo 'date' === $field['type'] ? '' : 'ffc-w100'; ?>" placeholder="<?php echo esc_attr( $field['default'] ); ?>">
						<?php endif; ?>
					</p>
					<?php
				}
				?>
				<p class="description"><?php esc_html_e( 'An empty field prints the template’s default, shown in grey.', 'ffcertificate' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}

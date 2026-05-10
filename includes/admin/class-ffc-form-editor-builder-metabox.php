<?php
/**
 * Form Editor Builder Metabox Renderer
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
 * Form Editor Builder Metabox Renderer.
 *
 * @since 3.1.1
 */
class FormEditorBuilderMetabox {

	/**
	 * Section 2: Form Builder (Fields)
	 *
	 * @param WP_Post $post The post object.
	 */
	public function render( WP_Post $post ): void {
		$fields = get_post_meta( $post->ID, '_ffc_form_fields', true );

		// Default fields for brand new forms.
		if ( empty( $fields ) && 'auto-draft' === $post->post_status ) {
			$fields = array(
				array(
					'type'     => 'text',
					'label'    => __( 'Full Name', 'ffcertificate' ),
					'name'     => 'name',
					'required' => '1',
					'options'  => '',
				),
				array(
					'type'     => 'email',
					'label'    => __( 'Email', 'ffcertificate' ),
					'name'     => 'email',
					'required' => '1',
					'options'  => '',
				),
				array(
					'type'     => 'text',
					'label'    => __( 'CPF / ID', 'ffcertificate' ),
					'name'     => 'cpf_rf',
					'required' => '1',
					'options'  => '',
				),
			);
		}
		?>
		<div id="ffc-fields-container">
			<?php
			if ( ! empty( $fields ) && is_array( $fields ) ) {
				foreach ( $fields as $index => $field ) {
					$this->render_field_row( $index, $field );
				}
			}
			?>
		</div>
		<div>
		<p class="description">
		<?php esc_html_e( 'Minimal fields (Tag):', 'ffcertificate' ); ?> <code>name</code>, <code>email</code>, <code>cpf_rf</code>.
		</p>
		</div>
		<div class="ffc-builder-actions ffc-mt20">
			<button type="button" class="button button-primary ffc-add-field">
				<span class="dashicons dashicons-plus-alt"></span>
				<?php esc_html_e( 'Add New Field', 'ffcertificate' ); ?>
			</button>
		</div>

		<div class="ffc-field-template ffc-hidden">
			<?php $this->render_field_row( 'TEMPLATE', array() ); ?>
		</div>
		<?php
	}
	/**
	 * Helper: Renders a field row in the builder
	 *
	 * @param int|string           $index Field index.
	 * @param array<string, mixed> $field Field data.
	 */
	public function render_field_row( $index, array $field ): void {
		$index     = (string) $index;
		$type      = isset( $field['type'] ) ? $field['type'] : 'text';
		$label     = isset( $field['label'] ) ? $field['label'] : '';
		$name      = isset( $field['name'] ) ? $field['name'] : '';
		$req       = isset( $field['required'] ) ? $field['required'] : '';
		$opts      = isset( $field['options'] ) ? $field['options'] : '';
		$content   = isset( $field['content'] ) ? $field['content'] : '';
		$embed_url = isset( $field['embed_url'] ) ? $field['embed_url'] : '';
		$points    = isset( $field['points'] ) ? $field['points'] : '';

		$is_info               = 'info' === $type;
		$is_embed              = 'embed' === $type;
		$is_display_only       = $is_info || $is_embed;
		$options_visible_class = ( 'select' === $type || 'radio' === $type ) ? '' : 'ffc-hidden';
		?>
		<div class="ffc-field-row" data-index="<?php echo esc_attr( $index ); ?>">
			<div class="ffc-field-row-header">
				<span class="ffc-sort-handle">
					<span class="dashicons dashicons-menu"></span>
					<span class="ffc-field-title"><strong>
					<?php
					if ( $is_info ) {
						esc_html_e( 'Info Block', 'ffcertificate' );
					} elseif ( $is_embed ) {
						esc_html_e( 'Embed', 'ffcertificate' );
					} else {
						esc_html_e( 'Field', 'ffcertificate' );
					}
					?>
					</strong></span>
				</span>
				<button type="button" class="button button-link-delete ffc-remove-field"><?php esc_html_e( 'Remove', 'ffcertificate' ); ?></button>
			</div>

			<div class="ffc-field-row-grid">
				<div class="ffc-grid-item">
					<label>
					<?php
					if ( $is_info ) {
						esc_html_e( 'Title (optional)', 'ffcertificate' );
					} elseif ( $is_embed ) {
						esc_html_e( 'Caption (optional)', 'ffcertificate' );
					} else {
						esc_html_e( 'Label', 'ffcertificate' );
					}
					?>
					</label>
					<input type="text" name="ffc_fields[<?php echo esc_attr( $index ); ?>][label]" value="<?php echo esc_attr( $label ); ?>" class="ffc-w100">
				</div>
				<div class="ffc-grid-item ffc-standard-row<?php echo $is_display_only ? ' ffc-hidden' : ''; ?>">
					<label><?php esc_html_e( 'Variable Name (Tag)', 'ffcertificate' ); ?></label>
					<input type="text" name="ffc_fields[<?php echo esc_attr( $index ); ?>][name]" value="<?php echo esc_attr( $name ); ?>" placeholder="<?php esc_attr_e( 'ex: course_name', 'ffcertificate' ); ?>" class="ffc-w100">
				</div>
				<div class="ffc-grid-item">
					<label><?php esc_html_e( 'Type', 'ffcertificate' ); ?></label>
					<select name="ffc_fields[<?php echo esc_attr( $index ); ?>][type]" class="ffc-field-type-selector ffc-w100">
						<option value="text" <?php selected( $type, 'text' ); ?>><?php esc_html_e( 'Text', 'ffcertificate' ); ?></option>
						<option value="email" <?php selected( $type, 'email' ); ?>><?php esc_html_e( 'Email', 'ffcertificate' ); ?></option>
						<option value="number" <?php selected( $type, 'number' ); ?>><?php esc_html_e( 'Number', 'ffcertificate' ); ?></option>
						<option value="date" <?php selected( $type, 'date' ); ?>><?php esc_html_e( 'Date', 'ffcertificate' ); ?></option>
						<option value="textarea" <?php selected( $type, 'textarea' ); ?>><?php esc_html_e( 'Textarea', 'ffcertificate' ); ?></option>
						<option value="select" <?php selected( $type, 'select' ); ?>><?php esc_html_e( 'Select (Combobox)', 'ffcertificate' ); ?></option>
						<option value="radio" <?php selected( $type, 'radio' ); ?>><?php esc_html_e( 'Radio Box', 'ffcertificate' ); ?></option>
						<option value="hidden" <?php selected( $type, 'hidden' ); ?>><?php esc_html_e( 'Hidden Field', 'ffcertificate' ); ?></option>
						<option value="info" <?php selected( $type, 'info' ); ?>><?php esc_html_e( 'Info Block', 'ffcertificate' ); ?></option>
						<option value="embed" <?php selected( $type, 'embed' ); ?>><?php esc_html_e( 'Embed (Media)', 'ffcertificate' ); ?></option>
					</select>
				</div>
				<div class="ffc-grid-item ffc-flex-center ffc-standard-row<?php echo $is_display_only ? ' ffc-hidden' : ''; ?>">
					<label class="ffc-req-label">
						<input type="checkbox" name="ffc_fields[<?php echo esc_attr( $index ); ?>][required]" value="1" <?php checked( $req, '1' ); ?>>
						<?php esc_html_e( 'Required?', 'ffcertificate' ); ?>
					</label>
				</div>
			</div>

			<div class="ffc-content-field<?php echo $is_info ? '' : ' ffc-hidden'; ?>">
				<p class="description ffc-options-desc">
					<?php esc_html_e( 'Content (supports <b>, <i>, <a>, <p>, <ul>, <ol>):', 'ffcertificate' ); ?>
				</p>
				<textarea name="ffc_fields[<?php echo esc_attr( $index ); ?>][content]" rows="4" class="ffc-w100"><?php echo esc_textarea( $content ); ?></textarea>
			</div>

			<div class="ffc-embed-field<?php echo $is_embed ? '' : ' ffc-hidden'; ?>">
				<p class="description ffc-options-desc">
					<?php esc_html_e( 'Media URL (YouTube, Vimeo, image, or audio):', 'ffcertificate' ); ?>
				</p>
				<input type="url" name="ffc_fields[<?php echo esc_attr( $index ); ?>][embed_url]" value="<?php echo esc_url( $embed_url ); ?>" placeholder="https://www.youtube.com/watch?v=..." class="ffc-w100">
			</div>

			<div class="ffc-options-field <?php echo esc_attr( $options_visible_class ); ?>">
				<p class="description ffc-options-desc">
					<?php esc_html_e( 'Options (separate with commas):', 'ffcertificate' ); ?>
				</p>
				<input type="text" name="ffc_fields[<?php echo esc_attr( $index ); ?>][options]" value="<?php echo esc_attr( $opts ); ?>" placeholder="<?php esc_attr_e( 'Ex: Option 1, Option 2, Option 3', 'ffcertificate' ); ?>" class="ffc-w100">
				<div class="ffc-quiz-points ffc-hidden">
					<p class="description ffc-options-desc ffc-mt5">
						<?php esc_html_e( 'Points per option (same order, separate with commas):', 'ffcertificate' ); ?>
					</p>
					<input type="text" name="ffc_fields[<?php echo esc_attr( $index ); ?>][points]" value="<?php echo esc_attr( $points ); ?>" placeholder="<?php esc_attr_e( 'Ex: 0, 10, 0', 'ffcertificate' ); ?>" class="ffc-w100">
				</div>
			</div>
		</div>
		<?php
	}
}

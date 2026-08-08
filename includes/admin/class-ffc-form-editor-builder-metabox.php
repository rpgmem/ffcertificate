<?php
/**
 * Form Editor Builder Metabox Renderer
 *
 * Extracted from FormEditorMetaboxRenderer as part of S3 god-object refactor.
 *
 * @since   3.2.0
 * @package FreeFormCertificate\Admin
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\LabelSorter;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Form Editor Builder Metabox Renderer.
 *
 * @since 3.2.0
 */
class FormEditorBuilderMetabox {

	/**
	 * Canonical certificate form field types — the union consumed by both
	 * entry paths (this server-rendered row `<select>` and the JS field
	 * builder), so a type saved through one path is always editable in the
	 * other. Slugs are the stored key; labels come from
	 * {@see self::field_type_labels()}.
	 *
	 * @var array<int, string>
	 */
	public const FIELD_TYPES = array(
		'text',
		'email',
		'number',
		'date',
		'textarea',
		'select',
		'radio',
		'checkbox',
		'info',
		'embed',
		'hidden',
	);

	/**
	 * Display / non-input types pinned to the end of the type `<select>`
	 * (alphabetized among themselves), after the input types. `hidden` stores
	 * a value but has no visible control, so it sits with the display block.
	 *
	 * @var array<int, string>
	 */
	public const TRAILING_TYPES = array(
		'info',
		'embed',
		'hidden',
	);

	/**
	 * Human-facing, translated labels for each certificate field type. The
	 * single source of truth shared by the server-rendered `<select>` and the
	 * JS builder (localized via `ffc_ajax.fieldTypes`), so both render the same
	 * label per slug. Kept in sync with `FIELD_TYPES` by
	 * `FormEditorFieldTypesTest`.
	 *
	 * @return array<string, string> slug => translated label.
	 */
	public static function field_type_labels(): array {
		return array(
			'text'     => __( 'Text', 'ffcertificate' ),
			'email'    => __( 'Email', 'ffcertificate' ),
			'number'   => __( 'Number', 'ffcertificate' ),
			'date'     => __( 'Date', 'ffcertificate' ),
			'textarea' => __( 'Textarea', 'ffcertificate' ),
			'select'   => __( 'Dropdown Select', 'ffcertificate' ),
			'radio'    => __( 'Radio Buttons', 'ffcertificate' ),
			'checkbox' => __( 'Checkbox', 'ffcertificate' ),
			'info'     => __( 'Info Block', 'ffcertificate' ),
			'embed'    => __( 'Embed (Media)', 'ffcertificate' ),
			'hidden'   => __( 'Hidden Field', 'ffcertificate' ),
		);
	}

	/**
	 * Field types ordered for display: input types alphabetized by their
	 * translated label, then the display-only block (`info`/`embed`/`hidden`)
	 * pinned last and alphabetized among themselves. Ordering follows the
	 * active locale via {@see LabelSorter}. Returns slug => translated label.
	 *
	 * @return array<string, string> slug => translated label, in display order.
	 */
	public static function field_types_ordered(): array {
		$labels = self::field_type_labels();

		// The tail is emitted by LabelSorter::sort() in the given order, so
		// alphabetize the display block among itself first (by translated
		// label) before pinning it.
		$tail = self::TRAILING_TYPES;
		usort(
			$tail,
			static function ( string $a, string $b ) use ( $labels ): int {
				return LabelSorter::compare_labels( $labels[ $a ], $labels[ $b ] );
			}
		);

		return LabelSorter::sort(
			$labels,
			static function ( string $label ): string {
				return $label;
			},
			array(),
			$tail
		);
	}

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
		<div class="ffc-builder-actions ffc-mt-20">
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
		$options_visible_class = ( 'select' === $type || 'radio' === $type || 'checkbox' === $type ) ? '' : 'ffc-hidden';
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
						<?php foreach ( self::field_types_ordered() as $type_slug => $type_label ) : ?>
							<option value="<?php echo esc_attr( $type_slug ); ?>" <?php selected( $type, $type_slug ); ?>><?php echo esc_html( $type_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="ffc-grid-item ffc-flex-center ffc-standard-row<?php echo $is_display_only ? ' ffc-hidden' : ''; ?>">
					<?php
					// Keep the .ffc-field-required class on the inner checkbox —
					// ffc-admin-field-builder.js reads its state via
					// `$row.find('.ffc-field-required').is(':checked')` when
					// serialising the field row to JSON for save_post.
					\FreeFormCertificate\Admin\AdminUI::render_toggle(
						array(
							'name'        => 'ffc_fields[' . $index . '][required]',
							'id'          => 'ffc_field_required_' . $index,
							'checked'     => '1' === (string) $req,
							'label'       => __( 'Required?', 'ffcertificate' ),
							'class'       => 'ffc-req-label',
							'input_class' => 'ffc-field-required',
						)
					);
					?>
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
					<p class="description ffc-options-desc ffc-mt-5">
						<?php esc_html_e( 'Points per option (same order, separate with commas):', 'ffcertificate' ); ?>
					</p>
					<input type="text" name="ffc_fields[<?php echo esc_attr( $index ); ?>][points]" value="<?php echo esc_attr( $points ); ?>" placeholder="<?php esc_attr_e( 'Ex: 0, 10, 0', 'ffcertificate' ); ?>" class="ffc-w100">
				</div>
			</div>
		</div>
		<?php
	}
}

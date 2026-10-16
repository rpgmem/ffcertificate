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

use FreeFormCertificate\Core\FormField;
use FreeFormCertificate\Core\LabelSorter;
use FreeFormCertificate\Core\QuizQuestion;
use FreeFormCertificate\Settings\SettingsReader;
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

		// A brand new form starts with the fields every form must have, as
		// configured on Settings → Certificates (#1741).
		if ( empty( $fields ) && 'auto-draft' === $post->post_status ) {
			$fields = array();
			foreach ( SettingsReader::required_form_fields() as $required ) {
				$fields[] = array(
					'type'     => $required['type'],
					'label'    => $required['label'],
					'name'     => $required['name'],
					'required' => '1',
				);
			}
		}
		$missing = is_array( $fields ) ? $this->missing_required_rows( $fields ) : SettingsReader::required_form_fields();
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
			<?php esc_html_e( 'Required fields (Tag):', 'ffcertificate' ); ?>
			<?php echo implode( ', ', array_map( static fn( string $name ): string => '<code>' . esc_html( $name ) . '</code>', FormEditorSaveValidator::required_field_names() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each name is escaped above. ?>.
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=ffc-settings&tab=certificates' ) ); ?>"><?php esc_html_e( 'Settings → Certificates', 'ffcertificate' ); ?></a>
		</p>
		<?php if ( array() !== $missing ) : ?>
			<?php
			\FreeFormCertificate\Core\AdminNotice::callout(
				'warning',
				'<p>' . esc_html__( 'This form lacks required fields:', 'ffcertificate' ) . ' '
					. implode( ', ', array_map( static fn( array $row ): string => '<code>' . esc_html( $row['name'] ) . '</code>', $missing ) ) . '</p>'
					. '<p><button type="button" class="button ffc-add-required-fields" data-ffc-required-fields="' . esc_attr( (string) wp_json_encode( $missing ) ) . '">'
					. '<span class="ffc-icon-plus" aria-hidden="true"></span> '
					. esc_html__( 'Add the required fields', 'ffcertificate' ) . '</button></p>',
				array( 'classes' => array( 'ffc-required-fields-missing' ) )
			);
			?>
		<?php endif; ?>
		</div>
		<div class="ffc-builder-actions ffc-mt-2xl">
			<button type="button" class="button button-primary ffc-add-field">
				<span class="ffc-icon-plus" aria-hidden="true"></span>
				<?php esc_html_e( 'Add New Field', 'ffcertificate' ); ?>
			</button>
		</div>

		<div class="ffc-field-template ffc-hidden">
			<?php $this->render_field_row( 'TEMPLATE', array() ); ?>
		</div>
		<?php
	}

	/**
	 * Required-field rows the saved fields do not answer (#1741).
	 *
	 * @param array<int|string, mixed> $fields Saved fields.
	 * @return array<int, array{name: string, label: string, type: string}>
	 */
	private function missing_required_rows( array $fields ): array {
		$names = array();
		foreach ( $fields as $field ) {
			if ( is_array( $field ) && is_scalar( $field['name'] ?? null ) ) {
				$names[] = (string) $field['name'];
			}
		}
		$missing = array();
		foreach ( SettingsReader::required_form_fields() as $row ) {
			$found = false;
			foreach ( $names as $name ) {
				$found = $found || FormEditorSaveValidator::satisfies( $name, $row['name'] );
			}
			if ( ! $found ) {
				$missing[] = $row;
			}
		}
		return $missing;
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
		$choices   = FormField::choices( $field );
		$content   = isset( $field['content'] ) ? $field['content'] : '';
		$embed_url = isset( $field['embed_url'] ) ? $field['embed_url'] : '';
		$points    = isset( $field['points'] ) ? (string) $field['points'] : '';
		$correct   = QuizQuestion::correct( $field );

		$option_points = QuizQuestion::option_points( $field );
		$fixed         = isset( $field['default_value'] ) ? $field['default_value'] : '';

		$is_info               = 'info' === $type;
		$is_embed              = 'embed' === $type;
		$is_display_only       = $is_info || $is_embed;
		$options_visible_class = ( 'select' === $type || 'radio' === $type || 'checkbox' === $type ) ? '' : 'ffc-hidden';
		// A field the required-field table calls for keeps its name and type
		// and cannot be removed (#1734, #1741).
		$is_locked = ! $is_display_only && FormEditorSaveValidator::is_required_field( (string) $name );
		if ( $is_locked ) {
			// The configured type wins, as it does on save (#1741).
			$required_row          = FormEditorSaveValidator::required_field( (string) $name );
			$type                  = null !== $required_row ? $required_row['type'] : $type;
			$options_visible_class = 'ffc-hidden';
		}
		$lock_id = 'ffc_field_lock_' . $index;
		?>
		<div class="ffc-field-row<?php echo $is_locked ? ' ffc-field-row-required-tag' : ''; ?>" data-index="<?php echo esc_attr( $index ); ?>">
			<div class="ffc-field-row-header">
				<span class="ffc-sort-handle">
					<span class="ffc-icon-grip" aria-hidden="true"></span>
					<?php // The row is named by its type (#1756), which cannot change once the row exists. ?>
					<span class="ffc-field-title"><strong><?php echo esc_html( self::field_type_labels()[ (string) $type ] ?? __( 'Field', 'ffcertificate' ) ); ?></strong></span>
					<?php if ( $is_locked ) : ?>
						<span class="ffc-required-tag-badge" id="<?php echo esc_attr( $lock_id ); ?>"><span class="ffc-icon-lock" aria-hidden="true"></span> <?php esc_html_e( 'Required field', 'ffcertificate' ); ?></span>
					<?php endif; ?>
				</span>
				<button type="button" class="button button-link-delete ffc-remove-field"<?php echo $is_locked ? ' disabled aria-describedby="' . esc_attr( $lock_id ) . '"' : ''; ?>><?php esc_html_e( 'Remove', 'ffcertificate' ); ?></button>
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
					<input type="text" name="ffc_fields[<?php echo esc_attr( $index ); ?>][name]" value="<?php echo esc_attr( $name ); ?>" placeholder="<?php esc_attr_e( 'ex: course_name', 'ffcertificate' ); ?>" class="ffc-w100"<?php echo $is_locked ? ' readonly aria-describedby="' . esc_attr( $lock_id ) . '"' : ''; ?>>
				</div>
				<?php
				// The type is chosen once, from the Add New Field menu, and is
				// not edited afterwards (#1756): a submission already stored
				// under this field holds values of its type. It travels in a
				// hidden input, and the save handler keeps the stored type of a
				// field it already knows.
				?>
				<input type="hidden" class="ffc-field-type-selector" name="ffc_fields[<?php echo esc_attr( $index ); ?>][type]" value="<?php echo esc_attr( (string) $type ); ?>">
				<div class="ffc-grid-item ffc-flex-center ffc-standard-row<?php echo $is_display_only ? ' ffc-hidden' : ''; ?>">
					<?php
					// A locked field is always required; its toggle is shown on
					// and disabled, and the save handler stores it as required.
					\FreeFormCertificate\Admin\AdminUI::render_toggle(
						array(
							'name'        => 'ffc_fields[' . $index . '][required]',
							'id'          => 'ffc_field_required_' . $index,
							'checked'     => $is_locked || '1' === (string) $req,
							'disabled'    => $is_locked,
							'label'       => __( 'Required?', 'ffcertificate' ),
							'class'       => 'ffc-req-label',
							'input_class' => 'ffc-field-required',
						)
					);
					?>
				</div>
			</div>
			<p class="description ffc-type-desc" aria-live="polite"><?php echo esc_html( self::field_type_descriptions()[ $type ] ?? '' ); ?></p>

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

			<div class="ffc-hidden-value-field<?php echo 'hidden' === $type ? '' : ' ffc-hidden'; ?>">
				<p class="description ffc-options-desc">
					<?php esc_html_e( 'Value submitted with every entry (the visitor cannot change it):', 'ffcertificate' ); ?>
				</p>
				<input type="text" name="ffc_fields[<?php echo esc_attr( $index ); ?>][default_value]" value="<?php echo esc_attr( $fixed ); ?>" class="ffc-w100">
			</div>

			<div class="ffc-options-field <?php echo esc_attr( $options_visible_class ); ?>">
				<p class="description ffc-options-desc">
					<?php esc_html_e( 'Options (one per row):', 'ffcertificate' ); ?>
				</p>
				<ol class="ffc-choice-list">
					<?php foreach ( ( array() === $choices ? array( '' ) : $choices ) as $choice_i => $choice ) : ?>
						<?php $is_correct = '' !== $choice && in_array( $choice, $correct, true ); ?>
						<li class="ffc-choice-row">
							<span class="ffc-choice-handle ffc-icon-grip" aria-hidden="true"></span>
							<input type="text" name="ffc_fields[<?php echo esc_attr( $index ); ?>][choices][]" value="<?php echo esc_attr( $choice ); ?>" class="ffc-choice-input" aria-label="<?php esc_attr_e( 'Option', 'ffcertificate' ); ?>">
							<?php // The mark posts as an aligned 0/1 so the server can pair it with its option; the visible box drives it. ?>
							<input type="hidden" name="ffc_fields[<?php echo esc_attr( $index ); ?>][choice_correct][]" value="<?php echo $is_correct ? '1' : '0'; ?>" class="ffc-choice-correct-value">
							<label class="ffc-choice-quiz ffc-choice-correct" data-ffc-quiz-mode="correct">
								<input type="checkbox" class="ffc-choice-correct-input"<?php checked( $is_correct ); ?>>
								<?php echo esc_html( _x( 'Correct', 'quiz answer', 'ffcertificate' ) ); ?>
							</label>
							<label class="ffc-choice-quiz ffc-choice-points" data-ffc-quiz-mode="points">
								<span class="screen-reader-text"><?php esc_html_e( 'Points', 'ffcertificate' ); ?></span>
								<input type="number" name="ffc_fields[<?php echo esc_attr( $index ); ?>][choice_points][]" value="<?php echo esc_attr( isset( $option_points[ $choice_i ] ) && '' !== $points ? (string) $option_points[ $choice_i ] : '' ); ?>" step="1" class="small-text ffc-choice-points-input" placeholder="0">
								<span aria-hidden="true"><?php esc_html_e( 'pts', 'ffcertificate' ); ?></span>
							</label>
							<button type="button" class="button-link ffc-choice-remove" aria-label="<?php esc_attr_e( 'Remove option', 'ffcertificate' ); ?>"><span class="ffc-icon-cross" aria-hidden="true"></span></button>
						</li>
					<?php endforeach; ?>
				</ol>
				<button type="button" class="button ffc-choice-add"><span class="ffc-icon-plus" aria-hidden="true"></span> <?php esc_html_e( 'Add option', 'ffcertificate' ); ?></button>
				<div class="ffc-field-settings">
					<?php
					\FreeFormCertificate\Admin\AdminUI::render_toggle(
						array(
							'name'    => 'ffc_fields[' . $index . '][shuffle]',
							'id'      => 'ffc_field_shuffle_' . $index,
							'checked' => '1' === (string) ( $field['shuffle'] ?? '' ),
							'label'   => __( 'Shuffle the options for each participant', 'ffcertificate' ),
						)
					);
					?>
					<label class="ffc-field-setting<?php echo 'checkbox' === $type ? '' : ' ffc-hidden'; ?>" data-ffc-types="checkbox">
						<?php esc_html_e( 'Maximum selections', 'ffcertificate' ); ?>
						<input type="number" name="ffc_fields[<?php echo esc_attr( $index ); ?>][max_select]" value="<?php echo esc_attr( (string) ( $field['max_select'] ?? '' ) ); ?>" min="1" step="1" class="small-text">
						<span class="description"><?php esc_html_e( 'Empty means no limit. Once it is reached, the other boxes are disabled.', 'ffcertificate' ); ?></span>
					</label>
				</div>
			</div>

			<div class="ffc-field-settings<?php echo in_array( $type, FormField::LENGTH_TYPES, true ) ? '' : ' ffc-hidden'; ?>" data-ffc-types="text textarea">
				<label class="ffc-field-setting">
					<?php esc_html_e( 'Minimum characters', 'ffcertificate' ); ?>
					<input type="number" name="ffc_fields[<?php echo esc_attr( $index ); ?>][min_length]" value="<?php echo esc_attr( (string) ( $field['min_length'] ?? '' ) ); ?>" min="0" step="1" class="small-text">
				</label>
				<label class="ffc-field-setting">
					<?php esc_html_e( 'Maximum characters', 'ffcertificate' ); ?>
					<input type="number" name="ffc_fields[<?php echo esc_attr( $index ); ?>][max_length]" value="<?php echo esc_attr( (string) ( $field['max_length'] ?? '' ) ); ?>" min="1" step="1" class="small-text">
				</label>
				<span class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: longest single-line text answer, 2: longest textarea answer. */
							__( 'Empty means no minimum, and the server ceiling for the maximum: %1$s characters for Text, %2$s for Textarea. The participant sees the limits you set.', 'ffcertificate' ),
							number_format_i18n( FormField::TEXT_MAX_LENGTH ),
							number_format_i18n( FormField::TEXTAREA_MAX_LENGTH )
						)
					);
					?>
				</span>
			</div>

			<div class="ffc-field-settings<?php echo 'number' === $type ? '' : ' ffc-hidden'; ?>" data-ffc-types="number">
				<label class="ffc-field-setting">
					<?php esc_html_e( 'Minimum', 'ffcertificate' ); ?>
					<input type="number" name="ffc_fields[<?php echo esc_attr( $index ); ?>][min]" value="<?php echo esc_attr( 'number' === $type ? FormField::min( $field ) : '' ); ?>" step="any" class="small-text ffc-range-number" data-ffc-bound="min">
				</label>
				<label class="ffc-field-setting">
					<?php esc_html_e( 'Maximum', 'ffcertificate' ); ?>
					<input type="number" name="ffc_fields[<?php echo esc_attr( $index ); ?>][max]" value="<?php echo esc_attr( 'number' === $type ? FormField::max( $field ) : '' ); ?>" step="any" class="small-text ffc-range-number" data-ffc-bound="max">
				</label>
				<label class="ffc-field-setting">
					<?php esc_html_e( 'Decimal places', 'ffcertificate' ); ?>
					<select name="ffc_fields[<?php echo esc_attr( $index ); ?>][decimals]">
						<?php for ( $places = 0; $places <= FormField::MAX_DECIMALS; $places++ ) : ?>
							<option value="<?php echo esc_attr( (string) $places ); ?>" <?php selected( FormField::decimals( $field ), $places ); ?>><?php echo esc_html( number_format_i18n( $places ) ); ?></option>
						<?php endfor; ?>
					</select>
				</label>
				<span class="description"><?php esc_html_e( 'Empty means no limit. The participant sees the range.', 'ffcertificate' ); ?></span>
			</div>

			<div class="ffc-field-settings<?php echo 'date' === $type ? '' : ' ffc-hidden'; ?>" data-ffc-types="date">
				<label class="ffc-field-setting">
					<?php esc_html_e( 'Earliest date', 'ffcertificate' ); ?>
					<input type="date" name="ffc_fields[<?php echo esc_attr( $index ); ?>][min_date]" value="<?php echo esc_attr( 'date' === $type ? FormField::min( $field ) : '' ); ?>" data-ffc-bound="min">
				</label>
				<label class="ffc-field-setting">
					<?php esc_html_e( 'Latest date', 'ffcertificate' ); ?>
					<input type="date" name="ffc_fields[<?php echo esc_attr( $index ); ?>][max_date]" value="<?php echo esc_attr( 'date' === $type ? FormField::max( $field ) : '' ); ?>" data-ffc-bound="max">
				</label>
				<span class="description"><?php esc_html_e( 'Empty means no limit. The participant sees the range.', 'ffcertificate' ); ?></span>
			</div>

			<?php $this->render_quiz_block( $index, $field, $type ); ?>
		</div>
		<?php
	}

	/**
	 * The field's quiz settings, shown while quiz mode is on (#1730).
	 *
	 * @param string               $index Field index.
	 * @param array<string, mixed> $field Field data.
	 * @param string               $type  Field type.
	 */
	private function render_quiz_block( string $index, array $field, string $type ): void {
		$base   = 'ffc_fields[' . $index . ']';
		$scored = QuizQuestion::is_scored( $field ) || '1' === (string) ( $field['quiz_scored'] ?? '' );
		$mode   = QuizQuestion::mode( $field );
		$text   = QuizQuestion::text_mode( $field );
		$is_num = 'number' === $type;
		?>
		<div class="ffc-field-quiz ffc-hidden" data-ffc-quiz-types="<?php echo esc_attr( implode( ' ', QuizQuestion::SCORABLE_TYPES ) ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $base ); ?>[quiz_block]" value="1">
			<div class="ffc-field-quiz-head">
				<?php
				\FreeFormCertificate\Admin\AdminUI::render_toggle(
					array(
						'name'        => $base . '[quiz_scored]',
						'id'          => 'ffc_field_quiz_scored_' . $index,
						'checked'     => $scored,
						'label'       => __( 'Score this question', 'ffcertificate' ),
						'input_class' => 'ffc-quiz-scored',
					)
				);
				?>
				<span class="description"><?php esc_html_e( 'Off: the question is left out of the count and of the points the percentage is taken from.', 'ffcertificate' ); ?></span>
			</div>
			<div class="ffc-field-quiz-body ffc-field-settings">
				<label class="ffc-field-setting" data-ffc-types="select radio checkbox">
					<?php esc_html_e( 'How to score', 'ffcertificate' ); ?>
					<select name="<?php echo esc_attr( $base ); ?>[quiz_mode]" class="ffc-quiz-mode">
						<option value="<?php echo esc_attr( QuizQuestion::MODE_CORRECT ); ?>" <?php selected( $mode, QuizQuestion::MODE_CORRECT ); ?>><?php esc_html_e( 'Correct option(s)', 'ffcertificate' ); ?></option>
						<option value="<?php echo esc_attr( QuizQuestion::MODE_POINTS ); ?>" <?php selected( $mode, QuizQuestion::MODE_POINTS ); ?>><?php esc_html_e( 'Points per option', 'ffcertificate' ); ?></option>
					</select>
				</label>
				<label class="ffc-field-setting" data-ffc-types="text textarea">
					<?php esc_html_e( 'How to score', 'ffcertificate' ); ?>
					<select name="<?php echo esc_attr( $base ); ?>[quiz_text]" class="ffc-quiz-text">
						<option value="<?php echo esc_attr( QuizQuestion::TEXT_NONE ); ?>" <?php selected( $text, QuizQuestion::TEXT_NONE ); ?>><?php esc_html_e( 'No points', 'ffcertificate' ); ?></option>
						<option value="<?php echo esc_attr( QuizQuestion::TEXT_ANSWERED ); ?>" <?php selected( $text, QuizQuestion::TEXT_ANSWERED ); ?>><?php esc_html_e( 'Points when answered', 'ffcertificate' ); ?></option>
						<option value="<?php echo esc_attr( QuizQuestion::TEXT_MANUAL ); ?>" <?php selected( $text, QuizQuestion::TEXT_MANUAL ); ?>><?php esc_html_e( 'Manual grading', 'ffcertificate' ); ?></option>
					</select>
				</label>
				<label class="ffc-field-setting" data-ffc-types="number">
					<?php esc_html_e( 'Correct from', 'ffcertificate' ); ?>
					<input type="number" name="<?php echo esc_attr( $base ); ?>[quiz_answer_min]" value="<?php echo esc_attr( $is_num ? QuizQuestion::answer_min( $field ) : '' ); ?>" step="any" class="small-text">
				</label>
				<label class="ffc-field-setting" data-ffc-types="number">
					<?php esc_html_e( 'to', 'ffcertificate' ); ?>
					<input type="number" name="<?php echo esc_attr( $base ); ?>[quiz_answer_max]" value="<?php echo esc_attr( $is_num ? QuizQuestion::answer_max( $field ) : '' ); ?>" step="any" class="small-text">
				</label>
				<label class="ffc-field-setting" data-ffc-types="date">
					<?php esc_html_e( 'Correct from', 'ffcertificate' ); ?>
					<input type="date" name="<?php echo esc_attr( $base ); ?>[quiz_answer_min_date]" value="<?php echo esc_attr( 'date' === $type ? QuizQuestion::answer_min( $field ) : '' ); ?>">
				</label>
				<label class="ffc-field-setting" data-ffc-types="date">
					<?php esc_html_e( 'to', 'ffcertificate' ); ?>
					<input type="date" name="<?php echo esc_attr( $base ); ?>[quiz_answer_max_date]" value="<?php echo esc_attr( 'date' === $type ? QuizQuestion::answer_max( $field ) : '' ); ?>">
				</label>
				<label class="ffc-field-setting ffc-quiz-points-setting">
					<?php esc_html_e( 'Points', 'ffcertificate' ); ?>
					<input type="number" name="<?php echo esc_attr( $base ); ?>[quiz_points]" value="<?php echo esc_attr( (string) QuizQuestion::points( $field ) ); ?>" min="0" step="1" class="small-text">
				</label>
				<p class="description ffc-quiz-help" data-ffc-quiz-help="correct"><?php esc_html_e( 'Tick the correct option(s) beside each option. A checkbox scores only when every correct option is ticked and no other.', 'ffcertificate' ); ?></p>
				<p class="description ffc-quiz-help" data-ffc-quiz-help="points"><?php esc_html_e( 'Give each option its points beside it. A checkbox adds the boxes ticked, never below zero; a wrong box can be worth negative points.', 'ffcertificate' ); ?></p>
				<p class="description ffc-quiz-help" data-ffc-quiz-help="manual"><?php esc_html_e( 'A person grades the answer, from 0 to the points. A submission with such an answer waits as "Awaiting Grading"; the certificate, or the result, is emailed once it is graded.', 'ffcertificate' ); ?></p>
				<p class="description ffc-quiz-help" data-ffc-quiz-help="range"><?php esc_html_e( 'An answer within the range, bounds included, earns the points. Set both bounds to the same value for one exact answer; leave one empty for an open range.', 'ffcertificate' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * One line per field type, shown under the Type select, saying what the
	 * participant gets and what the field is for (#1729).
	 *
	 * @return array<string, string> slug => translated description.
	 */
	public static function field_type_descriptions(): array {
		return array(
			'text'     => sprintf(
				/* translators: %s: longest answer, in characters. */
				__( 'One line, up to %s characters. For names, roles, codes.', 'ffcertificate' ),
				number_format_i18n( FormField::TEXT_MAX_LENGTH )
			),
			'textarea' => sprintf(
				/* translators: %s: longest answer, in characters. */
				__( 'Several lines, up to %s characters. For explanations and comments.', 'ffcertificate' ),
				number_format_i18n( FormField::TEXTAREA_MAX_LENGTH )
			),
			'email'    => __( 'An email address, checked for format.', 'ffcertificate' ),
			'number'   => __( 'A number, with an optional range and decimal places.', 'ffcertificate' ),
			'date'     => __( 'A date picked from a calendar, with an optional range.', 'ffcertificate' ),
			'select'   => __( 'A drop-down list: the participant picks one option.', 'ffcertificate' ),
			'radio'    => __( 'Every option visible: the participant picks exactly one.', 'ffcertificate' ),
			'checkbox' => __( 'Every option visible: the participant ticks one or more. "Required" means at least one.', 'ffcertificate' ),
			'info'     => __( 'Text shown on the form. Collects nothing.', 'ffcertificate' ),
			'embed'    => __( 'A video, image or audio shown on the form. Collects nothing.', 'ffcertificate' ),
			'hidden'   => __( 'A fixed value saved with every submission. The participant does not see it.', 'ffcertificate' ),
		);
	}
}

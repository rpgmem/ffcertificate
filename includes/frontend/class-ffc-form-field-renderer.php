<?php
/**
 * FormFieldRenderer
 *
 * The participant-facing markup of one input field of a certificate form:
 * the control, the limits the administrator set (as attributes the browser
 * enforces and as a sentence the participant reads), and the hooks the
 * form-fields script reads to count characters, cap selections and shuffle
 * options (#1729).
 *
 * The server enforces every limit again in `Submission\FieldRulesGuard`; the
 * attributes here are a courtesy, never the guard.
 *
 * @package FreeFormCertificate\Frontend
 * @since 6.39.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

use FreeFormCertificate\Core\DateFormatter;
use FreeFormCertificate\Core\FormField;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Markup for one input field (not info, embed or hidden).
 */
final class FormFieldRenderer {

	/**
	 * Characters in a masked CPF (`000.000.000-00`), the longer of the two.
	 */
	private const CPF_RF_MASKED_LENGTH = 14;

	/**
	 * Render the field.
	 *
	 * @param array<string, mixed> $field Stored field.
	 * @param string               $type  Type to render (the CPF/RF field renders as `tel`).
	 */
	public function render( array $field, string $type ): string {
		$name    = (string) ( $field['name'] ?? '' );
		$label   = (string) ( $field['label'] ?? '' );
		$default = (string) ( $field['default_value'] ?? '' );
		$is_req  = ! empty( $field['required'] );
		$hint    = $this->hint( $field );
		$hint_id = $name . '-hint';

		ob_start();

		if ( in_array( $type, array( 'radio', 'checkbox' ), true ) ) {
			$this->render_group( $field, $type, $name, $label, $default, $is_req, $hint, $hint_id );
		} else {
			?>
			<div class="ffc-form-field">
				<label for="<?php echo esc_attr( $name ); ?>">
					<?php echo esc_html( $label ); ?>
					<?php if ( $is_req ) : ?>
						<span class="ffc-required" aria-hidden="true">*</span>
					<?php endif; ?>
				</label>
				<?php
				$counter = $this->render_control( $field, $type, $name, $default, $is_req, '' === $hint ? '' : $hint_id );
				$this->render_foot( $name, $hint, $hint_id, $counter );
				?>
			</div>
			<?php
		}

		$html = ob_get_clean();
		return $html ? $html : '';
	}

	/**
	 * A radio or checkbox group: one `fieldset`, its label as the legend.
	 *
	 * A checkbox posts `name[]`, so the answer arrives as a list. HTML
	 * `required` on a box would demand that box; "at least one" is the
	 * script's to say and the server's to enforce, so a required group
	 * carries `data-ffc-required` instead.
	 *
	 * @param array<string, mixed> $field   Stored field.
	 * @param string               $type    `radio` or `checkbox`.
	 * @param string               $name    Field name.
	 * @param string               $label   Field label.
	 * @param string               $default Default value.
	 * @param bool                 $is_req  Whether an answer is required.
	 * @param string               $hint    Limit sentence, or ''.
	 * @param string               $hint_id Hint element id.
	 */
	private function render_group( array $field, string $type, string $name, string $label, string $default, bool $is_req, string $hint, string $hint_id ): void {
		$is_checkbox = 'checkbox' === $type;
		$max_select  = FormField::max_select( $field );
		$attrs       = '';
		if ( $is_checkbox && $is_req ) {
			$attrs .= ' data-ffc-required="1"';
		}
		if ( $max_select > 0 ) {
			$attrs .= ' data-ffc-max-select="' . esc_attr( (string) $max_select ) . '"';
		}
		if ( '' !== $hint ) {
			$attrs .= ' aria-describedby="' . esc_attr( $hint_id ) . '"';
		}
		?>
		<fieldset class="ffc-form-field ffc-choice-field ffc-choice-<?php echo esc_attr( $type ); ?>"<?php echo $attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every value is escaped while $attrs is built above. ?>>
			<legend class="ffc-field-legend">
				<?php echo esc_html( $label ); ?>
				<?php if ( $is_req ) : ?>
					<span class="ffc-required" aria-hidden="true">*</span>
				<?php endif; ?>
			</legend>
			<div class="ffc-radio-group<?php echo $is_checkbox ? ' ffc-checkbox-group' : ''; ?>"<?php echo FormField::shuffles( $field ) ? ' data-ffc-shuffle="1"' : ''; ?>>
				<?php foreach ( FormField::choices( $field ) as $choice ) : ?>
					<label>
						<input type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( $is_checkbox ? $name . '[]' : $name ); ?>" value="<?php echo esc_attr( $choice ); ?>"
						<?php
						if ( ! $is_checkbox && $is_req ) {
							echo ' required aria-required="true"';
						}
						checked( $default, $choice );
						?>
						>
						<?php echo esc_html( $choice ); ?>
					</label>
				<?php endforeach; ?>
			</div>
			<?php
			$this->render_foot( $name, $hint, $hint_id, $is_checkbox && $max_select > 0 ? '' : null );
			?>
		</fieldset>
		<?php
	}

	/**
	 * The control of a single-control field.
	 *
	 * @param array<string, mixed> $field     Stored field.
	 * @param string               $type      Type to render.
	 * @param string               $name      Field name.
	 * @param string               $default   Default value.
	 * @param bool                 $is_req    Whether an answer is required.
	 * @param string               $describer Id of the hint, or ''.
	 * @return string|null The counter's first reading for a counted field, or null.
	 */
	private function render_control( array $field, string $type, string $name, string $default, bool $is_req, string $describer ): ?string {
		$common = sprintf( ' name="%1$s" id="%1$s"', esc_attr( $name ) );
		if ( $is_req ) {
			$common .= ' required aria-required="true"';
		}
		if ( '' !== $describer ) {
			$common .= ' aria-describedby="' . esc_attr( $describer ) . '"';
		}

		$max_length = FormField::max_length( $field );
		$min_length = FormField::effective_min_length( $field );
		// The CPF/RF field is masked to at most 000.000.000-00 (#1734).
		if ( 'cpf_rf' === $name ) {
			$max_length = self::CPF_RF_MASKED_LENGTH;
			$common    .= ' inputmode="numeric" autocomplete="off"';
		}
		if ( FormField::is_full_name( $field ) ) {
			$common .= ' data-ffc-full-name="1" autocomplete="name"';
		}
		if ( $max_length > 0 ) {
			$common .= ' maxlength="' . esc_attr( (string) $max_length ) . '"';
		}
		if ( $min_length > 0 ) {
			$common .= ' minlength="' . esc_attr( (string) $min_length ) . '"';
		}

		if ( 'textarea' === $type ) {
			printf(
				'<textarea class="ffc-input ffc-textarea"%1$s rows="4">%2$s</textarea>',
				$common, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every value is escaped while $common is built above.
				esc_textarea( $default )
			);
			return $this->length_counter( $max_length );
		}

		if ( 'select' === $type ) {
			printf(
				'<select class="ffc-input"%1$s%2$s>',
				$common, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every value is escaped while $common is built above.
				FormField::shuffles( $field ) ? ' data-ffc-shuffle="1"' : ''
			);
			printf( '<option value="">%s</option>', esc_html__( 'Select...', 'ffcertificate' ) );
			foreach ( FormField::choices( $field ) as $choice ) {
				printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $choice ), selected( $default, $choice, false ), esc_html( $choice ) );
			}
			echo '</select>';
			return null;
		}

		if ( in_array( $type, FormField::RANGE_TYPES, true ) ) {
			$min = FormField::min( $field );
			$max = FormField::max( $field );
			if ( '' !== $min ) {
				$common .= ' min="' . esc_attr( $min ) . '"';
			}
			if ( '' !== $max ) {
				$common .= ' max="' . esc_attr( $max ) . '"';
			}
			if ( 'number' === $type ) {
				$common .= ' step="' . esc_attr( FormField::step( $field ) ) . '"';
			}
		}

		printf(
			'<input class="ffc-input" type="%1$s"%2$s value="%3$s">',
			esc_attr( $type ),
			$common, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every value is escaped while $common is built above.
			esc_attr( $default )
		);
		return 'text' === $type ? $this->length_counter( $max_length ) : null;
	}

	/**
	 * The first reading of the live "n / max" counter under a text or
	 * textarea, or null when the field has no limit.
	 *
	 * @param int $max_length Longest answer accepted.
	 */
	private function length_counter( int $max_length ): ?string {
		return $max_length > 0 ? '0 / ' . number_format_i18n( $max_length ) : null;
	}

	/**
	 * The row under a field: the hint on the left, the counter on the right
	 * (#1844). A message the script shows goes between the control and this
	 * row.
	 *
	 * The counter is `aria-hidden`: the hint states the limit, and a message
	 * says so when the limit is reached. `data-ffc-counter-for` is how the
	 * form-fields script finds it, so a message inserted after the control
	 * cannot come between the two.
	 *
	 * @param string      $name    Field name, the control's id.
	 * @param string      $hint    Limit sentence, or ''.
	 * @param string      $hint_id Hint element id.
	 * @param string|null $counter The counter's first reading ('' when the
	 *                             script writes it); null for no counter.
	 */
	private function render_foot( string $name, string $hint, string $hint_id, ?string $counter ): void {
		if ( '' === $hint && null === $counter ) {
			return;
		}
		echo '<div class="ffc-field-foot">';
		if ( '' !== $hint ) {
			printf( '<p class="ffc-field-hint" id="%s">%s</p>', esc_attr( $hint_id ), esc_html( $hint ) );
		}
		if ( null !== $counter ) {
			printf(
				'<span class="ffc-field-counter" aria-hidden="true" data-ffc-counter-for="%1$s">%2$s</span>',
				esc_attr( $name ),
				esc_html( $counter )
			);
		}
		echo '</div>';
	}

	/**
	 * The sentence announcing the limits the administrator set, or ''.
	 *
	 * Only a configured limit is announced; the server's ceiling for a text
	 * field is shown by the counter alone.
	 *
	 * @param array<string, mixed> $field Stored field.
	 */
	public function hint( array $field ): string {
		$type = FormField::type( $field );

		if ( 'checkbox' === $type ) {
			$max = FormField::max_select( $field );
			if ( $max <= 0 ) {
				return '';
			}
			return sprintf(
				/* translators: %s: most options the participant may tick. */
				_n( 'Choose up to %s option.', 'Choose up to %s options.', $max, 'ffcertificate' ),
				number_format_i18n( $max )
			);
		}

		if ( in_array( $type, FormField::LENGTH_TYPES, true ) ) {
			if ( ! FormField::has_configured_length( $field ) ) {
				return FormField::is_full_name( $field ) ? __( 'First and last name.', 'ffcertificate' ) : '';
			}
			$min        = FormField::min_length( $field );
			$max        = FormField::max_length( $field );
			$configured = $max < FormField::length_ceiling( $type );
			if ( $min > 0 && $configured ) {
				/* translators: 1: fewest characters, 2: most characters. */
				return sprintf( __( 'Between %1$s and %2$s characters.', 'ffcertificate' ), number_format_i18n( $min ), number_format_i18n( $max ) );
			}
			if ( $min > 0 ) {
				/* translators: %s: fewest characters. */
				return sprintf( __( 'At least %s characters.', 'ffcertificate' ), number_format_i18n( $min ) );
			}
			/* translators: %s: most characters. */
			return sprintf( __( 'Up to %s characters.', 'ffcertificate' ), number_format_i18n( $max ) );
		}

		if ( in_array( $type, FormField::RANGE_TYPES, true ) ) {
			$min = $this->display_bound( $type, FormField::min( $field ) );
			$max = $this->display_bound( $type, FormField::max( $field ) );
			if ( '' !== $min && '' !== $max ) {
				/* translators: 1: lowest accepted value, 2: highest accepted value. */
				return sprintf( __( 'Between %1$s and %2$s.', 'ffcertificate' ), $min, $max );
			}
			if ( '' !== $min ) {
				return 'date' === $type
					/* translators: %s: earliest accepted date. */
					? sprintf( __( 'From %s.', 'ffcertificate' ), $min )
					/* translators: %s: lowest accepted number. */
					: sprintf( __( '%s or more.', 'ffcertificate' ), $min );
			}
			if ( '' !== $max ) {
				return 'date' === $type
					/* translators: %s: latest accepted date. */
					? sprintf( __( 'Until %s.', 'ffcertificate' ), $max )
					/* translators: %s: highest accepted number. */
					: sprintf( __( '%s or less.', 'ffcertificate' ), $max );
			}
		}

		return '';
	}

	/**
	 * A bound as the participant reads it.
	 *
	 * A date is a wall-clock date with no time zone, so it is formatted in
	 * UTC: formatting it in the site's zone would show the day before.
	 *
	 * @param string $type  `number` or `date`.
	 * @param string $value Stored bound.
	 */
	private function display_bound( string $type, string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		if ( 'date' === $type ) {
			return DateFormatter::format_date( $value, 'default', 'UTC' );
		}
		return number_format_i18n( (float) $value, FormField::decimals_in( $value ) );
	}
}

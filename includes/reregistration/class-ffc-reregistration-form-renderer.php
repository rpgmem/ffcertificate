<?php
/**
 * Reregistration Form Renderer
 *
 * Renders the user-facing reregistration form HTML using a fully dynamic
 * field system driven by wp_ffc_custom_fields. Every field shown in the
 * form — including the formerly hardcoded "standard" fields (Personal Data,
 * Contacts, Schedule, Accumulation, Union) — is now a row in
 * wp_ffc_custom_fields, grouped by the field_group column.
 *
 * Only the Acknowledgment (Ciência) fieldset is still hardcoded, since it
 * is a fixed legal notice that must stay immutable at the bottom of the
 * form.
 *
 * Supported field types (via render_field dispatcher):
 * - text, number, date, textarea
 * - select, dependent_select, checkbox
 * - working_hours (interactive table component)
 *
 * Sensitive values (is_sensitive=1) are transparently decrypted via the
 * Encryption helper before being rendered back into the form.
 *
 * @package FreeFormCertificate\Reregistration
 * @since 4.13.0 Fully dynamic field system
 * @since 4.12.8 Extracted from ReregistrationFrontend
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

use FreeFormCertificate\UserDashboard\UserManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renderer for reregistration form output.
 *
 * @phpstan-import-type ReregistrationRow from ReregistrationRepository
 * @phpstan-import-type ReregistrationSubmissionRow from ReregistrationSubmissionRepository
 * @phpstan-import-type CustomFieldRow from CustomFieldRepository
 */
class ReregistrationFormRenderer {

	/**
	 * Form field name prefix used for all dynamic fields.
	 *
	 * The submission is collected as $_POST['fields'][$field_key].
	 */
	private const FIELD_NAME_ROOT = 'fields';

	/**
	 * HTML id prefix for generated inputs.
	 */
	private const FIELD_ID_PREFIX = 'ffc_rereg_field_';

	/**
	 * Render the reregistration form HTML.
	 *
	 * @param object $rereg      Reregistration object.
	 * @phpstan-param ReregistrationRow $rereg
	 * @param object $submission Submission object.
	 * @phpstan-param ReregistrationSubmissionRow $submission
	 * @param int    $user_id    User ID.
	 * @return string HTML.
	 */
	public static function render( object $rereg, object $submission, int $user_id ): string {
		$user = get_userdata( $user_id );

		// Collect fields from all audiences linked to this reregistration.
		$audience_ids = ReregistrationRepository::get_audience_ids( (int) $rereg->id );
		$fields       = self::collect_fields_for_audiences( $audience_ids );

		// Pre-populate values: saved draft first, else profile/user_meta.
		$saved_data   = $submission->data ? json_decode( $submission->data, true ) : array();
		$saved_values = is_array( $saved_data['fields'] ?? null ) ? $saved_data['fields'] : array();

		$values = self::build_field_values( $fields, $saved_values, $user_id, $user );

		$end_ts   = strtotime( $rereg->end_date );
		$end_date = wp_date( get_option( 'date_format' ), false === $end_ts ? null : $end_ts );

		// Group fields by field_group, preserving sort order.
		$grouped      = self::group_fields( $fields );
		$group_labels = ReregistrationStandardFieldsSeeder::get_group_labels();

		ob_start();
		?>
		<div class="ffc-rereg-form-container" data-reregistration-id="<?php echo esc_attr( (string) $rereg->id ); ?>">
			<div class="ffc-rereg-header-bar">
				<div class="ffc-rereg-header-title"><?php echo esc_html__( 'CITY HALL OF SÃO PAULO / DEPARTMENT OF EDUCATION – SME', 'ffcertificate' ); ?></div>
				<div class="ffc-rereg-header-subtitle"><?php echo esc_html__( 'REGIONAL EDUCATION BOARD SÃO MIGUEL – MP', 'ffcertificate' ); ?></div>
			</div>

			<h3><?php echo esc_html( $rereg->title ); ?></h3>
			<p class="ffc-rereg-deadline">
				<?php
				/* translators: %s: end date */
				echo esc_html( sprintf( __( 'Deadline: %s', 'ffcertificate' ), $end_date ) );
				?>
			</p>

			<form id="ffc-rereg-form" novalidate>
				<input type="hidden" name="reregistration_id" value="<?php echo esc_attr( (string) $rereg->id ); ?>">

				<?php
				$group_index = 0;
				foreach ( $grouped as $group_key => $group_fields ) {
					++$group_index;
					$label = $group_labels[ $group_key ] ?? ( '' !== $group_key ? $group_key : __( 'Additional Information', 'ffcertificate' ) );
					self::render_group_fieldset( $group_index, (string) $label, $group_fields, $values );
				}

				self::render_acknowledgment_fieldset( $group_index + 1 );

				// Honeypot field (defense-in-depth — form already requires login).
				?>
				<div class="ffc-honeypot-field">
					<label><?php esc_html_e( 'Do not fill this field if you are human:', 'ffcertificate' ); ?></label>
					<input type="text" name="ffc_honeypot_trap" value="" tabindex="-1" autocomplete="off">
				</div>

				<div class="ffc-rereg-actions">
					<button type="button" class="button ffc-rereg-draft-btn"><?php esc_html_e( 'Save Draft', 'ffcertificate' ); ?></button>
					<button type="submit" class="button button-primary ffc-rereg-submit-btn"><?php esc_html_e( 'Submit', 'ffcertificate' ); ?></button>
					<button type="button" class="button ffc-rereg-cancel-btn"><?php esc_html_e( 'Cancel', 'ffcertificate' ); ?></button>
					<span class="ffc-rereg-status"></span>
				</div>
			</form>
		</div>
		<?php
		$output = ob_get_clean();
		return $output ? $output : '';
	}

	/**
	 * Collect active fields across a list of audiences, deduplicating by ID.
	 *
	 * @param array<int> $audience_ids Audience IDs.
	 * @return list<CustomFieldRow>
	 */
	private static function collect_fields_for_audiences( array $audience_ids ): array {
		$all  = array();
		$seen = array();

		foreach ( $audience_ids as $aud_id ) {
			$fields = CustomFieldRepository::get_by_audience_with_parents( (int) $aud_id, true );
			foreach ( $fields as $field ) {
				$id = (int) $field->id;
				if ( ! isset( $seen[ $id ] ) ) {
					$seen[ $id ] = true;
					$all[]       = $field;
				}
			}
		}

		return $all;
	}

	/**
	 * Group fields by field_group. Groups appear in the order they first
	 * show up in the sorted field list.
	 *
	 * @param array<object> $fields Fields to group.
	 * @phpstan-param list<CustomFieldRow> $fields
	 * @return array<string, list<CustomFieldRow>>
	 */
	private static function group_fields( array $fields ): array {
		$grouped = array();
		foreach ( $fields as $field ) {
			$group = isset( $field->field_group ) ? (string) $field->field_group : '';
			if ( ! isset( $grouped[ $group ] ) ) {
				$grouped[ $group ] = array();
			}
			$grouped[ $group ][] = $field;
		}
		return $grouped;
	}

	/**
	 * Build the initial value map for each field.
	 *
	 * Resolution order:
	 *  1. Draft data from current submission (already field_key-indexed)
	 *  2. Extended user profile (sensitive keys decrypted)
	 *  3. Field default from field_options.default
	 *
	 * @param array<object>        $fields       Field definitions.
	 * @phpstan-param list<CustomFieldRow> $fields
	 * @param array<string, mixed> $saved_values Previously saved values.
	 * @param int                  $user_id      User ID.
	 * @param \WP_User|false       $user         WP user object (or false).
	 * @return array<string, mixed> field_key => value
	 */
	private static function build_field_values( array $fields, array $saved_values, int $user_id, $user ): array {
		// Compute profile-key / sensitive-key lookup tables.
		$profile_keys   = array();
		$sensitive_keys = array();
		foreach ( $fields as $field ) {
			if ( ! empty( $field->field_profile_key ) ) {
				$profile_keys[] = (string) $field->field_profile_key;
			}
			if ( ! empty( $field->is_sensitive ) && ! empty( $field->field_profile_key ) ) {
				$sensitive_keys[] = (string) $field->field_profile_key;
			}
		}

		$profile = array();
		if ( $user_id > 0 && class_exists( '\FreeFormCertificate\UserDashboard\UserManager' ) ) {
			$profile = UserManager::get_extended_profile( $user_id, $profile_keys, $sensitive_keys );
		}

		$values = array();
		foreach ( $fields as $field ) {
			$key = (string) $field->field_key;

			// 1. Draft data wins.
			if ( array_key_exists( $key, $saved_values ) ) {
				$values[ $key ] = $saved_values[ $key ];
				continue;
			}

			// 2. Profile sync value.
			if ( ! empty( $field->field_profile_key ) ) {
				$pkey = (string) $field->field_profile_key;
				if ( isset( $profile[ $pkey ] ) && '' !== $profile[ $pkey ] ) {
					$values[ $key ] = $profile[ $pkey ];
					continue;
				}
			}

			// 3. Fallback to user email for institutional_email field shape.
			if ( $user && 'email_institucional' === $key && ! empty( $user->user_email ) ) {
				$values[ $key ] = $user->user_email;
				continue;
			}

			// 4. Default from field_options.
			$options = self::decode_options( $field );
			if ( isset( $options['default'] ) ) {
				$values[ $key ] = $options['default'];
				continue;
			}

			$values[ $key ] = '';
		}

		return $values;
	}

	/**
	 * Render a single fieldset for a group of fields.
	 *
	 * @param int                  $index         1-based index shown in the legend.
	 * @param string               $label         Translated group label.
	 * @param array<object>        $fields        Field definitions in the group.
	 * @phpstan-param list<CustomFieldRow> $fields
	 * @param array<string, mixed> $values field_key => value.
	 */
	private static function render_group_fieldset( int $index, string $label, array $fields, array $values ): void {
		if ( empty( $fields ) ) {
			return;
		}
		?>
		<fieldset class="ffc-rereg-fieldset">
			<legend><?php echo esc_html( sprintf( '%d. %s', $index, $label ) ); ?></legend>
			<?php
			foreach ( $fields as $field ) {
				$key   = (string) $field->field_key;
				$value = $values[ $key ] ?? '';
				self::render_field( $field, $value );
			}
			?>
		</fieldset>
		<?php
	}

	/**
	 * Render a single dynamic field wrapped in its label/error markup.
	 *
	 * @param object $field Field definition.
	 * @phpstan-param CustomFieldRow $field
	 * @param mixed  $value Current value (already decrypted for sensitive fields).
	 */
	private static function render_field( object $field, $value ): void {
		$field_id   = self::FIELD_ID_PREFIX . (int) $field->id;
		$field_name = self::FIELD_NAME_ROOT . '[' . (string) $field->field_key . ']';
		$required   = ! empty( $field->is_required );
		$rules      = self::decode_rules( $field );

		// Checkbox renders its own label inline.
		if ( 'checkbox' === $field->field_type ) {
			?>
			<div class="ffc-rereg-field" data-field-id="<?php echo esc_attr( (string) $field->id ); ?>"
				data-field-key="<?php echo esc_attr( (string) $field->field_key ); ?>">
				<?php self::render_input( $field, $field_id, $field_name, $value, $required, $rules ); ?>
				<span class="ffc-field-error" role="alert"></span>
			</div>
			<?php
			return;
		}
		?>
		<div class="ffc-rereg-field" data-field-id="<?php echo esc_attr( (string) $field->id ); ?>"
			data-field-key="<?php echo esc_attr( (string) $field->field_key ); ?>"
			data-format="<?php echo esc_attr( $rules['format'] ?? '' ); ?>"
			data-regex="<?php echo esc_attr( $rules['custom_regex'] ?? '' ); ?>"
			data-regex-msg="<?php echo esc_attr( $rules['custom_regex_message'] ?? '' ); ?>">
			<label for="<?php echo esc_attr( $field_id ); ?>">
				<?php echo esc_html( (string) $field->field_label ); ?>
				<?php
				if ( $required ) :
					?>
					<span class="required">*</span><?php endif; ?>
			</label>

			<?php self::render_input( $field, $field_id, $field_name, $value, $required, $rules ); ?>

			<span class="ffc-field-error" role="alert"></span>
		</div>
		<?php
	}

	/**
	 * Render the bare input element for a field (without label/wrapper).
	 *
	 * @param object               $field      Field definition.
	 * @phpstan-param CustomFieldRow $field
	 * @param string               $field_id   HTML id.
	 * @param string               $field_name HTML name.
	 * @param mixed                $value      Current value.
	 * @param bool                 $required   Whether the field is required.
	 * @param array<string, mixed> $rules     Validation rules.
	 */
	private static function render_input( object $field, string $field_id, string $field_name, $value, bool $required, array $rules ): void {
		$mask = isset( $field->field_mask ) ? (string) $field->field_mask : '';
		if ( '' === $mask && ! empty( $rules['format'] ) ) {
			$mask = (string) $rules['format'];
		}
		$mask_attr = '' !== $mask ? ' data-mask="' . esc_attr( $mask ) . '"' : '';
		$req_attr  = $required ? ' required' : '';

		switch ( (string) $field->field_type ) {
			case 'textarea':
				printf(
					'<textarea id="%s" name="%s" rows="3"%s>%s</textarea>',
					esc_attr( $field_id ),
					esc_attr( $field_name ),
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded safe attribute string.
					$req_attr,
					esc_textarea( is_scalar( $value ) ? (string) $value : '' )
				);
				break;

			case 'select':
				$choices = CustomFieldRepository::get_field_choices( $field );
				printf(
					'<select id="%s" name="%s"%s>',
					esc_attr( $field_id ),
					esc_attr( $field_name ),
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded safe attribute string.
					$req_attr
				);
				echo '<option value="">' . esc_html__( 'Select', 'ffcertificate' ) . '</option>';
				foreach ( $choices as $choice ) {
					printf(
						'<option value="%s" %s>%s</option>',
						esc_attr( (string) $choice ),
						selected( (string) $value, (string) $choice, false ),
						esc_html( (string) $choice )
					);
				}
				echo '</select>';
				break;

			case 'dependent_select':
				self::render_dependent_select_field( $field, $field_id, $field_name, is_scalar( $value ) ? (string) $value : null );
				break;

			case 'checkbox':
				printf(
					'<label class="ffc-checkbox-label"><input type="checkbox" id="%s" name="%s" value="1" %s> %s%s</label>',
					esc_attr( $field_id ),
					esc_attr( $field_name ),
					checked( (string) $value, '1', false ),
					esc_html( (string) $field->field_label ),
					$required ? ' <span class="required">*</span>' : ''
				);
				break;

			case 'number':
				printf(
					'<input type="number" id="%s" name="%s" value="%s"%s%s>',
					esc_attr( $field_id ),
					esc_attr( $field_name ),
					esc_attr( is_scalar( $value ) ? (string) $value : '' ),
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded safe attribute string.
					$req_attr,
					$mask_attr // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above
				);
				break;

			case 'date':
				printf(
					'<input type="date" id="%s" name="%s" value="%s"%s>',
					esc_attr( $field_id ),
					esc_attr( $field_name ),
					esc_attr( is_scalar( $value ) ? (string) $value : '' ),
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded safe attribute string.
					$req_attr
				);
				break;

			case 'working_hours':
				self::render_working_hours_field( $field_id, $field_name, is_scalar( $value ) ? (string) $value : null );
				break;

			default: // 'text' and any unknown type
				printf(
					'<input type="text" id="%s" name="%s" value="%s"%s%s>',
					esc_attr( $field_id ),
					esc_attr( $field_name ),
					esc_attr( is_scalar( $value ) ? (string) $value : '' ),
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded safe attribute string.
					$req_attr,
					$mask_attr // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above
				);
				break;
		}
	}

	/**
	 * Render a dependent select field (cascade of two selects).
	 *
	 * @param object      $field      Field definition.
	 * @phpstan-param CustomFieldRow $field
	 * @param string      $field_id   Hidden input id.
	 * @param string      $field_name Hidden input name.
	 * @param string|null $value      Current JSON-encoded {parent,child} value.
	 */
	private static function render_dependent_select_field( object $field, string $field_id, string $field_name, ?string $value ): void {
		$groups       = CustomFieldRepository::get_dependent_choices( $field );
		$opts         = self::decode_options( $field );
		$parent_label = $opts['parent_label'] ?? __( 'Category', 'ffcertificate' );
		$child_label  = $opts['child_label'] ?? __( 'Subcategory', 'ffcertificate' );

		$decoded = null !== $value && '' !== $value ? json_decode( $value, true ) : null;
		$parent  = is_array( $decoded ) && isset( $decoded['parent'] ) ? (string) $decoded['parent'] : '';
		$child   = is_array( $decoded ) && isset( $decoded['child'] ) ? (string) $decoded['child'] : '';
		?>
		<input type="hidden" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $field_name ); ?>"
				value="
				<?php
				$dep_json = wp_json_encode(
					array(
						'parent' => $parent,
						'child'  => $child,
					)
				);
				echo esc_attr( $dep_json ? $dep_json : '' );
				?>
						">
		<div class="ffc-dependent-select" data-target="<?php echo esc_attr( $field_id ); ?>">
			<div class="ffc-rereg-row ffc-rereg-row-2">
				<div class="ffc-rereg-field">
					<label><?php echo esc_html( (string) $parent_label ); ?></label>
					<select class="ffc-dep-parent">
						<option value=""><?php esc_html_e( 'Select', 'ffcertificate' ); ?></option>
						<?php foreach ( array_keys( $groups ) as $group ) : ?>
							<option value="<?php echo esc_attr( (string) $group ); ?>" <?php selected( $parent, (string) $group ); ?>><?php echo esc_html( (string) $group ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="ffc-rereg-field">
					<label><?php echo esc_html( (string) $child_label ); ?></label>
					<select class="ffc-dep-child">
						<option value=""><?php esc_html_e( 'Select', 'ffcertificate' ); ?></option>
						<?php
						if ( '' !== $parent && isset( $groups[ $parent ] ) ) {
							foreach ( $groups[ $parent ] as $child_opt ) {
								printf(
									'<option value="%s" %s>%s</option>',
									esc_attr( (string) $child_opt ),
									selected( $child, (string) $child_opt, false ),
									esc_html( (string) $child_opt )
								);
							}
						}
						?>
					</select>
				</div>
			</div>
			<script type="application/json" class="ffc-dep-groups"><?php echo wp_json_encode( $groups ); ?></script>
		</div>
		<?php
	}

	/**
	 * Render a working_hours field (interactive table + hidden JSON input).
	 *
	 * @param string      $field_id   HTML id for the hidden input.
	 * @param string      $field_name HTML name for the hidden input.
	 * @param string|null $value      Current JSON-encoded working hours array or null.
	 */
	private static function render_working_hours_field( string $field_id, string $field_name, ?string $value ): void {
		$wh_data = null;
		if ( is_string( $value ) && '' !== $value ) {
			$decoded = json_decode( $value, true );
			if ( is_array( $decoded ) && ! empty( $decoded ) ) {
				$wh_data = $decoded;
			}
		}

		if ( null === $wh_data ) {
			$wh_data = ReregistrationFieldOptions::get_default_working_hours();
		}

		$days_labels = array(
			0 => __( 'Sunday', 'ffcertificate' ),
			1 => __( 'Monday', 'ffcertificate' ),
			2 => __( 'Tuesday', 'ffcertificate' ),
			3 => __( 'Wednesday', 'ffcertificate' ),
			4 => __( 'Thursday', 'ffcertificate' ),
			5 => __( 'Friday', 'ffcertificate' ),
			6 => __( 'Saturday', 'ffcertificate' ),
		);
		?>
		<?php $wh_json = wp_json_encode( $wh_data ); ?>
		<input type="hidden" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $field_name ); ?>" value="<?php echo esc_attr( $wh_json ? $wh_json : '' ); ?>">
		<div class="ffc-working-hours" data-target="<?php echo esc_attr( $field_id ); ?>">
			<table class="ffc-wh-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Day', 'ffcertificate' ); ?></th>
						<th><?php esc_html_e( 'Entry 1', 'ffcertificate' ); ?> <span class="required">*</span></th>
						<th><?php esc_html_e( 'Exit 1', 'ffcertificate' ); ?></th>
						<th><?php esc_html_e( 'Entry 2', 'ffcertificate' ); ?></th>
						<th><?php esc_html_e( 'Exit 2', 'ffcertificate' ); ?> <span class="required">*</span></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $wh_data as $wh_entry ) : ?>
					<tr>
						<td>
							<select class="ffc-wh-day">
								<?php foreach ( $days_labels as $d_num => $d_name ) : ?>
									<option value="<?php echo esc_attr( (string) $d_num ); ?>" <?php selected( $wh_entry['day'] ?? 0, $d_num ); ?>><?php echo esc_html( $d_name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td><input type="time" class="ffc-wh-entry1" value="<?php echo esc_attr( $wh_entry['entry1'] ?? '' ); ?>" required></td>
						<td><input type="time" class="ffc-wh-exit1"  value="<?php echo esc_attr( $wh_entry['exit1'] ?? '' ); ?>"></td>
						<td><input type="time" class="ffc-wh-entry2" value="<?php echo esc_attr( $wh_entry['entry2'] ?? '' ); ?>"></td>
						<td><input type="time" class="ffc-wh-exit2"  value="<?php echo esc_attr( $wh_entry['exit2'] ?? '' ); ?>" required></td>
						<td><button type="button" class="ffc-wh-remove">&times;</button></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<button type="button" class="button ffc-wh-add">+ <?php esc_html_e( 'Add Day', 'ffcertificate' ); ?></button>
		</div>
		<?php
	}

	/**
	 * Render the acknowledgment fieldset (fixed legal notice).
	 *
	 * @param int $index Fieldset index shown in the legend.
	 */
	private static function render_acknowledgment_fieldset( int $index ): void {
		?>
		<!-- TERMO DE CIÊNCIA -->
		<fieldset class="ffc-rereg-fieldset">
			<legend><?php echo esc_html( sprintf( '%d. %s', $index, __( 'Acknowledgment', 'ffcertificate' ) ) ); ?></legend>

			<div class="ffc-rereg-termo-text">
				<p><?php echo esc_html__( 'I, working at the Regional Education Board of São Miguel – DRE-MP, declare that I am aware of the guidelines for the current year:', 'ffcertificate' ); ?></p>
				<ol>
					<li><strong><?php echo esc_html__( 'Family Declaration (WEB):', 'ffcertificate' ); ?></strong> <?php echo esc_html__( 'The Family Declaration must be completed during the employee\'s birthday month, through the website:', 'ffcertificate' ); ?> <a href="https://www.declaracaofamilia.iprem.prefeitura.sp.gov.br/Login" target="_blank" rel="noopener noreferrer">https://www.declaracaofamilia.iprem.prefeitura.sp.gov.br/Login</a>. <?php echo esc_html__( 'Afterward, it must be printed and delivered to the Personnel Records Department for filing;', 'ffcertificate' ); ?></li>
					<li><strong><?php echo esc_html__( 'Transportation Benefit Re-registration:', 'ffcertificate' ); ?></strong> <?php echo esc_html__( 'The same guidelines apply to those entitled to the benefit. The re-registration must be completed during the birthday month, and the employee must complete the Transportation Benefit re-registration BEFORE the annual re-registration (proof of life);', 'ffcertificate' ); ?></li>
					<li><strong><?php echo esc_html__( 'Annual Re-registration (Proof of Life):', 'ffcertificate' ); ?></strong> <?php echo esc_html__( 'The same guidelines apply. Note that an ID card issued more than 10 years ago will not be accepted, and the employee must obtain a new document before completing the re-registration;', 'ffcertificate' ); ?></li>
					<li><strong><?php echo esc_html__( 'Asset Declaration (SISPATRI):', 'ffcertificate' ); ?></strong> <?php echo esc_html__( 'The same guidelines apply. It must be completed after the Federal Revenue deadline, from the 1st to the 30th of June, through the website:', 'ffcertificate' ); ?> <a href="https://controladoriageralbens.prefeitura.sp.gov.br/PaginasPublicas/login.aspx" target="_blank" rel="noopener noreferrer">https://controladoriageralbens.prefeitura.sp.gov.br/PaginasPublicas/login.aspx</a>;</li>
					<li><strong><?php echo esc_html__( '13th Salary Advance:', 'ffcertificate' ); ?></strong> <?php echo esc_html__( 'The request may be filled out and delivered to the HR Unit from the 1st business day of the year to which the advance refers, regardless of the employee\'s birthday month.', 'ffcertificate' ); ?></li>
					<li><strong><?php echo esc_html__( 'Submission of Medical/Dental Certificates with Leave Request from 1 (one) day:', 'ffcertificate' ); ?></strong> <?php echo esc_html__( 'We reiterate that any leave request for health treatment (personal or family member) must be immediately reported to the supervisor, with presentation of the medical/dental certificate. Then, the documentation must be delivered to the Personnel Records Department IN PERSON or digitized to the email:', 'ffcertificate' ); ?> <a href="mailto:rhvidafuncionaldremp@sme.prefeitura.sp.gov.br">rhvidafuncionaldremp@sme.prefeitura.sp.gov.br</a>. <?php echo esc_html__( 'Important: The Personnel Records Department and the Supervisor are not responsible for certificates left in the attendance book or in the folder designated exclusively for Schedule Declarations, as well as those delivered outside the legal deadline for scheduling a medical examination, if applicable.', 'ffcertificate' ); ?></li>
				</ol>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Decode the field_options JSON column into an array.
	 *
	 * @param object $field Field definition.
	 * @phpstan-param CustomFieldRow $field
	 * @return array<string, mixed>
	 */
	private static function decode_options( object $field ): array {
		$options = $field->field_options ?? null;
		if ( is_string( $options ) && '' !== $options ) {
			$options = json_decode( $options, true );
		}
		return is_array( $options ) ? $options : array();
	}

	/**
	 * Decode the validation_rules JSON column into an array.
	 *
	 * @param object $field Field definition.
	 * @phpstan-param CustomFieldRow $field
	 * @return array<string, mixed>
	 */
	private static function decode_rules( object $field ): array {
		$rules = $field->validation_rules ?? null;
		if ( is_string( $rules ) && '' !== $rules ) {
			$rules = json_decode( $rules, true );
		}
		return is_array( $rules ) ? $rules : array();
	}
}

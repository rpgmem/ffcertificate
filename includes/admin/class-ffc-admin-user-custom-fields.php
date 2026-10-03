<?php
/**
 * Admin User Custom Fields
 *
 * Adds a "Custom Data" section to the WordPress user edit screen showing
 * custom fields from all audiences the user belongs to.
 *
 * @package FreeFormCertificate\Admin
 * @since 4.11.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Reregistration\CustomFieldReader;
use FreeFormCertificate\Reregistration\CustomFieldWriter;
use FreeFormCertificate\Audience\AudienceReader;
use FreeFormCertificate\UserDashboard\UserManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin User Custom Fields.
 *
 * @phpstan-import-type CustomFieldRow from CustomFieldReader
 */
class AdminUserCustomFields {

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'show_user_profile', array( __CLASS__, 'render_section' ), 30 );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_section' ), 30 );
		add_action( 'personal_options_update', array( __CLASS__, 'save_section' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_section' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_save_notice' ) );
	}

	/**
	 * Enqueue working hours component on user profile pages.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( string $hook ): void {
		if ( 'user-edit.php' !== $hook && 'profile.php' !== $hook ) {
			return;
		}

		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();

		// Both sheets paint through `var(--ffc-*)` since #1126 (defeito B).
		\FreeFormCertificate\Core\AssetHelper::enqueue_common_style();
		wp_enqueue_style( 'ffc-working-hours', FFC_PLUGIN_URL . "assets/css/ffc-working-hours{$s}.css", array( 'ffc-common' ), FFC_VERSION );
		wp_enqueue_style( 'ffc-custom-fields-admin', FFC_PLUGIN_URL . "assets/css/ffc-custom-fields-admin{$s}.css", array( 'ffc-common' ), FFC_VERSION );
		wp_enqueue_script( 'ffc-working-hours', FFC_PLUGIN_URL . "assets/js/ffc-working-hours{$s}.js", array( 'jquery' ), FFC_VERSION, true );
		// `ffc-core` supplies `FFC.setRequiredWithin()`, which the collapse
		// script uses to carry `required` with a section's visibility (#1120).
		// Without it a collapsed section holding a required field blocks the
		// profile save against a control nobody can see.
		wp_enqueue_script( 'ffc-core', FFC_PLUGIN_URL . "assets/js/ffc-core{$s}.js", array( 'jquery' ), FFC_VERSION, true );
		wp_enqueue_script( 'ffc-custom-fields-collapse', FFC_PLUGIN_URL . "assets/js/ffc-custom-fields-collapse{$s}.js", array( 'jquery', 'ffc-core' ), FFC_VERSION, true );
		// The same masks, dependent selects and dual-post toggle the
		// reregistration form uses, so a field behaves alike on both screens.
		wp_enqueue_script( 'ffc-field-behaviours', FFC_PLUGIN_URL . "assets/js/ffc-field-behaviours{$s}.js", array( 'jquery', 'ffc-core' ), FFC_VERSION, true );
		wp_enqueue_script( 'ffc-admin-user-fields', FFC_PLUGIN_URL . "assets/js/ffc-admin-user-fields{$s}.js", array( 'jquery', 'ffc-field-behaviours' ), FFC_VERSION, true );
		wp_localize_script(
			'ffc-admin-user-fields',
			'ffcAdminUserFields',
			array(
				'strings' => array(
					// Must equal the option the dual-post field stores, which is
					// translated at seed time from the same source string.
					'dualPostShowValue' => __( 'I hold', 'ffcertificate' ),
					'select'            => __( 'Select', 'ffcertificate' ),
					'invalidCpf'        => __( 'Invalid CPF.', 'ffcertificate' ),
					'invalidEmail'      => __( 'Invalid email.', 'ffcertificate' ),
					'invalidPhone'      => __( 'Invalid phone number.', 'ffcertificate' ),
					'invalidFormat'     => __( 'Invalid format.', 'ffcertificate' ),
				),
			)
		);
		wp_localize_script(
			'ffc-working-hours',
			'ffcWorkingHours',
			array(
				// #1128 — the submit guard names the missing cell, so the two
				// required labels and the message travel with the day names.
				'strings' => array(
					'entry1'     => __( 'Entry 1', 'ffcertificate' ),
					'exit2'      => __( 'Exit 2', 'ffcertificate' ),
					/* translators: %s: comma-separated list of missing time labels. */
					'incomplete' => __( 'This row has a time but is missing: %s. Fill it in, or clear the row to remove the day.', 'ffcertificate' ),
				),
				'days'    => array(
					array(
						'value' => 0,
						'label' => __( 'Sunday', 'ffcertificate' ),
					),
					array(
						'value' => 1,
						'label' => __( 'Monday', 'ffcertificate' ),
					),
					array(
						'value' => 2,
						'label' => __( 'Tuesday', 'ffcertificate' ),
					),
					array(
						'value' => 3,
						'label' => __( 'Wednesday', 'ffcertificate' ),
					),
					array(
						'value' => 4,
						'label' => __( 'Thursday', 'ffcertificate' ),
					),
					array(
						'value' => 5,
						'label' => __( 'Friday', 'ffcertificate' ),
					),
					array(
						'value' => 6,
						'label' => __( 'Saturday', 'ffcertificate' ),
					),
				),
			)
		);
	}

	/**
	 * Render the custom fields section on user profile page.
	 *
	 * @param \WP_User $user User object.
	 * @return void
	 */
	public static function render_section( \WP_User $user ): void {
		$audiences = AudienceReader::get_user_audiences( $user->ID );
		if ( empty( $audiences ) ) {
			return;
		}

		$user_data          = CustomFieldReader::get_user_data( $user->ID );
		$profile            = self::profile_values( $user->ID, CustomFieldReader::get_all_for_user( $user->ID, true ) );
		$rendered_field_ids = array();

		?>
		<h2><?php esc_html_e( 'FFC Custom Data', 'ffcertificate' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Custom fields from audience memberships. Fields are grouped by audience.', 'ffcertificate' ); ?></p>

		<?php wp_nonce_field( 'ffc_save_user_custom_fields', 'ffc_user_custom_fields_nonce' ); ?>

		<div id="ffc-user-custom-fields">
		<?php foreach ( $audiences as $audience ) : ?>
			<?php
			$fields = CustomFieldReader::get_by_audience_with_parents( (int) $audience->id, true );
			if ( empty( $fields ) ) {
				continue;
			}
			$section_id = 'ffc-cf-section-' . $audience->id;
			?>

			<div class="ffc-cf-section">
				<h3 class="ffc-audience-section-heading ffc-cf-toggle" data-target="<?php echo esc_attr( $section_id ); ?>" role="button" tabindex="0" aria-expanded="true">
					<span class="ffc-cf-toggle-icon dashicons dashicons-arrow-down-alt2"></span>
					<span class="ffc-color-dot" style="--ffc-color: <?php echo esc_attr( $audience->color ); ?>;"></span>
					<?php echo esc_html( $audience->name ); ?>
					<span class="ffc-cf-field-count"><?php echo esc_html( (string) count( $fields ) ); ?></span>
				</h3>

				<div id="<?php echo esc_attr( $section_id ); ?>" class="ffc-cf-section-body">
					<table class="form-table" role="presentation">
						<tbody>
							<?php foreach ( $fields as $field ) : ?>
								<?php
								// Avoid rendering same field twice (shared parent).
								if ( isset( $rendered_field_ids[ (int) $field->id ] ) ) {
									continue;
								}
								$rendered_field_ids[ (int) $field->id ] = true;

								$field_key  = 'field_' . $field->id;
								$value      = empty( $field->field_profile_key )
									? ( $user_data[ $field_key ] ?? '' )
									: ( $profile[ (string) $field->field_profile_key ] ?? '' );
								$input_name = 'ffc_cf_' . $field->id;
								$rules      = self::decode_json( $field->validation_rules ?? null );
								?>
								<tr data-field-key="<?php echo esc_attr( (string) $field->field_key ); ?>"
									data-format="<?php echo esc_attr( is_string( $rules['format'] ?? null ) ? $rules['format'] : '' ); ?>"
									data-regex="<?php echo esc_attr( is_string( $rules['custom_regex'] ?? null ) ? $rules['custom_regex'] : '' ); ?>"
									data-regex-msg="<?php echo esc_attr( is_string( $rules['custom_regex_message'] ?? null ) ? $rules['custom_regex_message'] : '' ); ?>">
									<th scope="row">
										<label for="<?php echo esc_attr( $input_name ); ?>">
											<?php echo esc_html( $field->field_label ); ?>
											<?php if ( ! empty( $field->is_required ) ) : ?>
												<span class="ffc-required">*</span>
											<?php endif; ?>
										</label>
										<?php if ( (int) $field->source_audience_id !== (int) $audience->id ) : ?>
											<br><small class="description">
												<?php
												/* translators: %s: parent audience name */
												echo esc_html( sprintf( __( 'Inherited from %s', 'ffcertificate' ), $field->source_audience_name ) );
												?>
											</small>
										<?php endif; ?>
									</th>
									<td>
										<?php self::render_field_input( $field, $input_name, $value ); ?>
										<?php
										$options = $field->field_options;
										if ( is_string( $options ) ) {
											$options = json_decode( $options, true );
										}
										if ( ! empty( $options['help_text'] ) ) :
											?>
											<p class="description"><?php echo esc_html( $options['help_text'] ); ?></p>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		<?php endforeach; ?>
		</div>
		<?php
		// Collapsible-section wiring lives in assets/js/ffc-custom-fields-collapse.js
		// (enqueued in enqueue_assets()); the markup above carries the data-target ids.
	}

	/**
	 * Render a single field input based on its type.
	 *
	 * @param object $field      Field definition.
	 * @param string $input_name HTML input name.
	 * @param mixed  $value      Current value.
	 * @phpstan-param CustomFieldRow $field
	 * @return void
	 */
	private static function render_field_input( object $field, string $input_name, $value ): void {
		/*
		 * The definition already carries `is_required`, and until #1120 the
		 * only thing that read it was the asterisk beside the label — no
		 * input emitted the attribute and `save_section()` never checked the
		 * flag, so the screen promised something neither end enforced.
		 *
		 * Two types are deliberately excluded. A `checkbox` would have to be
		 * *ticked* to satisfy `required`, which is a different promise from
		 * "this field must be filled in" and would change what existing
		 * profiles are allowed to save — the reregistration renderer draws
		 * the asterisk and skips the attribute for exactly this reason, and
		 * this follows it. And `working_hours` posts through a hidden input,
		 * which is barred from constraint validation outright; its own two
		 * `required` time inputs are what enforce it there.
		 */
		$ffc_required = empty( $field->is_required ) ? '' : ' required';
		$ffc_mask     = self::mask_for( $field );

		switch ( $field->field_type ) {
			case 'textarea':
				?>
				<textarea name="<?php echo esc_attr( $input_name ); ?>" id="<?php echo esc_attr( $input_name ); ?>" rows="4" cols="50" class="regular-text"<?php echo esc_attr( $ffc_required ); ?>><?php echo esc_textarea( (string) $value ); ?></textarea>
				<?php
				break;

			case 'select':
				$choices = CustomFieldReader::get_field_choices( $field );
				?>
				<select name="<?php echo esc_attr( $input_name ); ?>" id="<?php echo esc_attr( $input_name ); ?>"<?php echo esc_attr( $ffc_required ); ?>>
					<option value=""><?php esc_html_e( '&mdash; Select &mdash;', 'ffcertificate' ); ?></option>
					<?php foreach ( $choices as $choice ) : ?>
						<option value="<?php echo esc_attr( $choice ); ?>" <?php selected( $value, $choice ); ?>>
							<?php echo esc_html( $choice ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php
				break;

			case 'dependent_select':
				self::render_dependent_select( $field, $input_name, $value );
				break;

			case 'checkbox':
				?>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $input_name ); ?>" id="<?php echo esc_attr( $input_name ); ?>" value="1" <?php checked( ! empty( $value ) ); ?>>
					<?php echo esc_html( $field->field_label ); ?>
				</label>
				<?php
				break;

			case 'number':
				?>
				<input type="number" name="<?php echo esc_attr( $input_name ); ?>" id="<?php echo esc_attr( $input_name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" class="regular-text"<?php echo esc_attr( $ffc_required ); ?> data-mask="<?php echo esc_attr( $ffc_mask ); ?>">
				<?php
				break;

			case 'date':
				?>
				<input type="date" name="<?php echo esc_attr( $input_name ); ?>" id="<?php echo esc_attr( $input_name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" class="regular-text"<?php echo esc_attr( $ffc_required ); ?>>
				<?php
				break;

			case 'working_hours':
				$wh_data = is_string( $value ) ? json_decode( $value, true ) : $value;
				if ( ! is_array( $wh_data ) || empty( $wh_data ) ) {
					$wh_data = array();
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
				<?php $wh_data_json = wp_json_encode( $wh_data ); ?>
				<input type="hidden" name="<?php echo esc_attr( $input_name ); ?>" id="<?php echo esc_attr( $input_name ); ?>" value="<?php echo esc_attr( $wh_data_json ? $wh_data_json : '' ); ?>">
				<div class="ffc-working-hours" data-target="<?php echo esc_attr( $input_name ); ?>">
					<table class="widefat ffc-wh-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Day', 'ffcertificate' ); ?></th>
								<th><?php esc_html_e( 'Entry 1', 'ffcertificate' ); ?> <span class="ffc-required">*</span></th>
								<th><?php esc_html_e( 'Exit 1', 'ffcertificate' ); ?></th>
								<th><?php esc_html_e( 'Entry 2', 'ffcertificate' ); ?></th>
								<th><?php esc_html_e( 'Exit 2', 'ffcertificate' ); ?> <span class="ffc-required">*</span></th>
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
								<td><input type="time" class="ffc-wh-exit1" value="<?php echo esc_attr( $wh_entry['exit1'] ?? '' ); ?>"></td>
								<td><input type="time" class="ffc-wh-entry2" value="<?php echo esc_attr( $wh_entry['entry2'] ?? '' ); ?>"></td>
								<td><input type="time" class="ffc-wh-exit2" value="<?php echo esc_attr( $wh_entry['exit2'] ?? '' ); ?>" required></td>
								<td><button type="button" class="button button-small ffc-wh-remove">&times;</button></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p><button type="button" class="button ffc-wh-add">+ <?php esc_html_e( 'Add Day', 'ffcertificate' ); ?></button></p>
				</div>
				<?php
				break;

			case 'text':
			default:
				?>
				<input type="text" name="<?php echo esc_attr( $input_name ); ?>" id="<?php echo esc_attr( $input_name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" class="regular-text"<?php echo esc_attr( $ffc_required ); ?> data-mask="<?php echo esc_attr( $ffc_mask ); ?>">
				<?php
				break;
		}
	}

	/**
	 * The input mask a field asks for: its own `field_mask`, else the format
	 * of its validation rules -- the same fallback the reregistration form
	 * uses, so a field masks alike on both screens.
	 *
	 * @param object $field Field definition.
	 * @phpstan-param CustomFieldRow $field
	 * @return string Mask name, or '' for none.
	 */
	public static function mask_for( object $field ): string {
		$mask = isset( $field->field_mask ) ? (string) $field->field_mask : '';
		if ( '' === $mask ) {
			$rules = self::decode_json( $field->validation_rules ?? null );
			$mask  = is_string( $rules['format'] ?? null ) ? $rules['format'] : '';
		}
		return $mask;
	}

	/**
	 * Render a dependent select through the reregistration form's own
	 * template, so the two screens draw the same cascade.
	 *
	 * @param object $field      Field definition.
	 * @phpstan-param CustomFieldRow $field
	 * @param string $input_name Hidden input name and id.
	 * @param mixed  $value      Stored `{"parent":…,"child":…}` JSON.
	 * @return void
	 */
	private static function render_dependent_select( object $field, string $input_name, $value ): void {
		$groups       = CustomFieldReader::get_dependent_choices( $field );
		$opts         = self::decode_json( $field->field_options );
		$parent_label = is_string( $opts['parent_label'] ?? null ) ? $opts['parent_label'] : __( 'Category', 'ffcertificate' );
		$child_label  = is_string( $opts['child_label'] ?? null ) ? $opts['child_label'] : __( 'Subcategory', 'ffcertificate' );
		$decoded      = is_string( $value ) ? self::decode_json( $value ) : array();
		$parent       = is_string( $decoded['parent'] ?? null ) ? $decoded['parent'] : '';
		$child        = is_string( $decoded['child'] ?? null ) ? $decoded['child'] : '';
		$field_id     = $input_name;
		$field_name   = $input_name;

		include FFC_PLUGIN_DIR . 'templates/reregistration/field-dependent-select.php';
	}

	/**
	 * Decode a JSON column into an array; anything else is an empty one.
	 *
	 * @param mixed $json JSON string, array or null.
	 * @return array<string, mixed>
	 */
	private static function decode_json( $json ): array {
		if ( is_string( $json ) && '' !== $json ) {
			$json = json_decode( $json, true );
		}
		return is_array( $json ) ? $json : array();
	}

	/**
	 * A posted dependent select, checked against the field's own choices.
	 *
	 * The hidden input carries JSON the browser assembled, so it is decoded,
	 * checked by the validator the reregistration form uses, and re-encoded
	 * from the two validated strings -- never stored as posted.
	 *
	 * @param object $field Field definition.
	 * @phpstan-param CustomFieldRow $field
	 * @param string $raw   Posted value.
	 * @return string|null Canonical JSON, '' when both halves are empty, null when invalid.
	 */
	public static function clean_dependent_select( object $field, string $raw ): ?string {
		$decoded = self::decode_json( trim( $raw ) );
		$parent  = is_string( $decoded['parent'] ?? null ) ? sanitize_text_field( $decoded['parent'] ) : '';
		$child   = is_string( $decoded['child'] ?? null ) ? sanitize_text_field( $decoded['child'] ) : '';

		if ( '' === $parent && '' === $child ) {
			return '';
		}

		$pair = array(
			'parent' => $parent,
			'child'  => $child,
		);
		if ( true !== \FreeFormCertificate\Reregistration\CustomFieldValidator::validate( $field, $pair ) ) {
			return null;
		}

		$json = wp_json_encode( $pair );
		return false === $json ? null : $json;
	}

	/**
	 * Save custom field data from user profile page.
	 *
	 * @param int $user_id User ID being saved.
	 * @return void
	 */
	public static function save_section( int $user_id ): void {
		// Verify nonce.
		if ( ! wp_verify_nonce( \FreeFormCertificate\Core\RequestInput::get_post_string( 'ffc_user_custom_fields_nonce' ), 'ffc_save_user_custom_fields' ) ) {
			return;
		}

		// Check permissions.
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		// Get all fields for this user.
		$fields = CustomFieldReader::get_all_for_user( $user_id, true );
		if ( empty( $fields ) ) {
			return;
		}

		$data           = array();
		$seen_ids       = array();
		$existing       = CustomFieldReader::get_user_data( $user_id );
		$profile        = self::profile_values( $user_id, $fields );
		$profile_patch  = array();
		$sensitive_keys = array();
		$kept           = array();
		$incomplete     = array();

		foreach ( $fields as $field ) {
			// Avoid processing same field twice.
			if ( isset( $seen_ids[ (int) $field->id ] ) ) {
				continue;
			}
			$seen_ids[ (int) $field->id ] = true;

			$input_name = 'ffc_cf_' . $field->id;
			$field_key  = 'field_' . $field->id;

			if ( 'checkbox' === $field->field_type ) {
				$data[ $field_key ] = isset( $_POST[ $input_name ] ) ? 1 : 0;
			} elseif ( 'working_hours' === $field->field_type ) {
				/*
				 * The old guard here was `isset( $entry['entry1'], $entry['exit2'] )`,
				 * and `isset( '' )` is true — so a row with an empty time passed it
				 * and was stored (#1128). `WorkingHours::sanitize()` is now the one
				 * place that decides, shared with the reregistration form so the two
				 * cannot drift apart again.
				 */
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized inside WorkingHours::sanitize().
				$raw_value = isset( $_POST[ $input_name ] ) ? wp_unslash( $_POST[ $input_name ] ) : '[]';
				$result    = \FreeFormCertificate\Core\WorkingHours::sanitize( is_string( $raw_value ) ? $raw_value : '[]' );

				$data[ $field_key ] = $result['json'];

				foreach ( $result['incomplete'] as $ffc_row ) {
					$incomplete[] = array(
						'label'   => (string) $field->field_label,
						'day'     => $ffc_row['day'],
						'missing' => $ffc_row['missing'],
					);
				}
			} elseif ( 'dependent_select' === $field->field_type ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Decoded, validated and re-encoded in clean_dependent_select().
				$raw_value = isset( $_POST[ $input_name ] ) ? wp_unslash( $_POST[ $input_name ] ) : '';
				$clean     = self::clean_dependent_select( $field, is_string( $raw_value ) ? $raw_value : '' );

				$pkey   = empty( $field->field_profile_key ) ? '' : (string) $field->field_profile_key;
				$stored = '' === $pkey ? ( $existing[ $field_key ] ?? '' ) : ( $profile[ $pkey ] ?? '' );
				$stored = is_scalar( $stored ) ? (string) $stored : '';

				// A pair outside the field's choices, or a required field
				// arriving empty, keeps what was stored rather than erasing it.
				if ( null === $clean || ( '' === $clean && ! empty( $field->is_required ) && '' !== $stored ) ) {
					if ( '' === $pkey && '' !== $stored ) {
						$data[ $field_key ] = $stored;
					}
					$kept[] = (string) $field->field_label;
					continue;
				}

				// `divisao_setor` lives on the profile, like any other
				// profile-mapped field (#1538).
				if ( '' !== $pkey ) {
					$profile_patch[ $pkey ] = $clean;
					if ( ! empty( $field->is_sensitive ) ) {
						$sensitive_keys[] = $pkey;
					}
					continue;
				}

				$data[ $field_key ] = $clean;
			} else {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked via isset; sanitized via sanitize_text_field/sanitize_textarea_field below.
				$raw_value = isset( $_POST[ $input_name ] ) ? wp_unslash( $_POST[ $input_name ] ) : '';
				$clean     = 'textarea' === $field->field_type
					? sanitize_textarea_field( $raw_value )
					: sanitize_text_field( $raw_value );

				/*
				 * An empty value on a required field is "not supplied", never
				 * "clear it" (#1120, the shape #1114 fixed on the Rate Limit
				 * tab). The `required` attribute stops this in the browser,
				 * but the browser is not the guard: a direct POST, a client
				 * with JS off, or a field the markup cannot mark required all
				 * reach here. Keeping the stored value means the worst case is
				 * an edit that did not take, announced below — not a value
				 * silently erased.
				 */
				// A profile-mapped field lives on the profile, not in the
				// snapshot (#1538): the reregistration form reads it from
				// there and `store_user_snapshot()` never writes it, so
				// saving it into the snapshot stored a copy nothing read --
				// in plaintext, for the sensitive ones.
				if ( ! empty( $field->field_profile_key ) ) {
					$pkey = (string) $field->field_profile_key;
					if ( '' === $clean && ! empty( $field->is_required ) && '' !== (string) ( $profile[ $pkey ] ?? '' ) ) {
						$kept[] = (string) $field->field_label;
						continue;
					}
					$profile_patch[ $pkey ] = $clean;
					if ( ! empty( $field->is_sensitive ) ) {
						$sensitive_keys[] = $pkey;
					}
					continue;
				}

				if ( '' === $clean && ! empty( $field->is_required ) && '' !== (string) ( $existing[ $field_key ] ?? '' ) ) {
					$data[ $field_key ] = (string) $existing[ $field_key ];
					$kept[]             = (string) $field->field_label;
					continue;
				}

				$data[ $field_key ] = $clean;
			}
		}

		CustomFieldWriter::save_user_data( $user_id, $data );

		if ( ! empty( $profile_patch ) ) {
			UserManager::update_extended_profile( $user_id, $profile_patch, $sensitive_keys );
		}

		if ( ! empty( $kept ) ) {
			set_transient( 'ffc_cf_required_kept_' . get_current_user_id(), $kept, MINUTE_IN_SECONDS );
		}

		if ( ! empty( $incomplete ) ) {
			set_transient( 'ffc_cf_wh_incomplete_' . get_current_user_id(), $incomplete, MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Current profile values of every profile-mapped field, decrypted.
	 *
	 * The same read the reregistration form pre-fills from, so the two
	 * screens cannot show different values for one field.
	 *
	 * @param int           $user_id User ID.
	 * @param array<object> $fields  Field definitions.
	 * @phpstan-param list<CustomFieldRow> $fields
	 * @return array<string, mixed> profile_key => value.
	 */
	private static function profile_values( int $user_id, array $fields ): array {
		$keys      = array();
		$sensitive = array();
		foreach ( $fields as $field ) {
			if ( empty( $field->field_profile_key ) ) {
				continue;
			}
			$keys[] = (string) $field->field_profile_key;
			if ( ! empty( $field->is_sensitive ) ) {
				$sensitive[] = (string) $field->field_profile_key;
			}
		}

		if ( empty( $keys ) ) {
			return array();
		}

		return UserManager::get_extended_profile( $user_id, array_values( array_unique( $keys ) ), $sensitive );
	}

	/**
	 * Tell the operator which required fields kept their previous value.
	 *
	 * `personal_options_update` / `edit_user_profile_update` run before the
	 * redirect, so there is no screen left to write on — the notice has to
	 * ride a transient into the next request, the way the form editor already
	 * surfaces its save errors. Keyed on the *editor*, not the user being
	 * edited, so two administrators never read each other's notice.
	 *
	 * @return void
	 */
	public static function render_save_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'profile', 'user-edit' ), true ) ) {
			return;
		}

		self::render_kept_notice();
		self::render_incomplete_hours_notice();
	}

	/**
	 * "These fields arrived empty, or with a choice outside the list, and kept their previous value."
	 *
	 * @return void
	 */
	private static function render_kept_notice(): void {
		$key  = 'ffc_cf_required_kept_' . get_current_user_id();
		$kept = get_transient( $key );
		if ( empty( $kept ) || ! is_array( $kept ) ) {
			return;
		}
		delete_transient( $key );

		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: comma-separated list of custom field labels. */
					__( 'These fields arrived empty or with an invalid choice and kept their previous value: %s', 'ffcertificate' ),
					implode( ', ', array_map( 'strval', $kept ) )
				)
			)
		);
	}

	/**
	 * "This working-hours row was incomplete, so it was not saved."
	 *
	 * Names the day and the missing time rather than saying the field failed:
	 * the row lives inside a section the operator may have collapsed, so a
	 * generic message would leave them hunting for which of seven rows to fix
	 * (#1128).
	 *
	 * @return void
	 */
	private static function render_incomplete_hours_notice(): void {
		$key  = 'ffc_cf_wh_incomplete_' . get_current_user_id();
		$rows = get_transient( $key );
		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return;
		}
		delete_transient( $key );

		$lines = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$missing = array_map(
				array( \FreeFormCertificate\Core\WorkingHours::class, 'key_label' ),
				is_array( $row['missing'] ?? null ) ? $row['missing'] : array()
			);

			$lines[] = sprintf(
				/* translators: 1: custom field label, 2: weekday name, 3: comma-separated list of missing time labels. */
				__( '%1$s — %2$s: missing %3$s', 'ffcertificate' ),
				(string) ( $row['label'] ?? '' ),
				\FreeFormCertificate\Core\WorkingHours::day_label( (int) ( $row['day'] ?? 0 ) ),
				implode( ', ', $missing )
			);
		}

		if ( empty( $lines ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s</p><ul style="list-style:disc;margin-left:2em"><li>%s</li></ul></div>',
			esc_html__( 'These working-hours rows were incomplete and were not saved. The first entry and the last exit are required; the middle shift is optional.', 'ffcertificate' ),
			implode( '</li><li>', array_map( 'esc_html', $lines ) )
		);
	}
}

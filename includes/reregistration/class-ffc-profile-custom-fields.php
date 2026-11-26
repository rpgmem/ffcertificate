<?php
/**
 * Profile Custom Fields
 *
 * The custom fields of the audiences a user belongs to, as a profile shows
 * and saves them: the WordPress user screen and the dashboard's Profile tab
 * (#2850) read and write them here, so the two cannot drift apart.
 *
 * @package FreeFormCertificate\Reregistration
 * @since 6.52.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

use FreeFormCertificate\Audience\AudienceReader;
use FreeFormCertificate\Core\DateFormatter;
use FreeFormCertificate\Core\DocumentFormatter;
use FreeFormCertificate\Core\PiiAccessPolicy;
use FreeFormCertificate\UserDashboard\UserManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read, display and save a user's audience custom fields.
 *
 * @phpstan-import-type CustomFieldRow from CustomFieldReader
 * @phpstan-type ProfileFieldView array{id: int, label: string, type: string, required: bool, display: string, masked: bool, read_only: string, inherited_from: string}
 * @phpstan-type ProfileFieldSection array{audience_id: int, name: string, color: string, fields: list<ProfileFieldView>}
 * @phpstan-type ProfileFieldSaveResult array{errors: array<int, string>, kept: list<string>, incomplete: list<array{label: string, day: mixed, missing: mixed}>}
 */
final class ProfileCustomFields {

	/**
	 * The audience module's PII capability, which decides who may reveal a
	 * sensitive custom field of another user (#1863).
	 */
	public const PII_CAP = 'ffc_view_audiences_pii';

	/**
	 * Field types whose stored value is free text a mask can stand in for.
	 * A select, a checkbox or the working-hours grid carry no identifier.
	 */
	public const MASKABLE_TYPES = array( 'text', 'textarea', 'number', 'date' );

	/**
	 * Profile keys the dashboard's fixed Profile form already edits. A custom
	 * field mapped to one is shown read-only there, so one value is never
	 * edited in two places on one screen.
	 */
	public const DASHBOARD_OWNED_KEYS = array( 'display_name', 'phone', 'department', 'organization', 'notes', 'birth_date' );

	/**
	 * Read-only reason: an identity key (CPF, RF), changed by an administrator.
	 */
	public const READ_ONLY_IDENTITY = 'identity';

	/**
	 * Read-only reason: a key the dashboard's fixed form edits.
	 */
	public const READ_ONLY_OWNED = 'owned';

	/**
	 * The user's active fields, each once, in audience order.
	 *
	 * @param int $user_id User ID.
	 * @return list<object>
	 * @phpstan-return list<CustomFieldRow>
	 */
	public static function fields( int $user_id ): array {
		return CustomFieldReader::get_all_for_user( $user_id, true );
	}

	/**
	 * Whether a field holds personal data a mask can stand in for: a
	 * sensitive field of a free-text type.
	 *
	 * @param object $field Field definition.
	 * @phpstan-param CustomFieldRow $field
	 * @return bool
	 */
	public static function is_maskable( object $field ): bool {
		return ! empty( $field->is_sensitive ) && in_array( (string) $field->field_type, self::MASKABLE_TYPES, true );
	}

	/**
	 * The input mask a field asks for: its own `field_mask`, else the format
	 * of its validation rules -- the same fallback the reregistration form
	 * uses, so a field masks alike on every screen.
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
	 * Why a field is read-only on the dashboard, or '' when it is editable.
	 *
	 * @param object $field Field definition.
	 * @phpstan-param CustomFieldRow $field
	 * @return string One of the READ_ONLY_* constants, or ''.
	 */
	public static function read_only_reason( object $field ): string {
		$pkey = (string) ( $field->field_profile_key ?? '' );
		if ( '' === $pkey ) {
			return '';
		}
		if ( in_array( $pkey, ReregistrationDataProcessor::IDENTITY_PROFILE_KEYS, true ) ) {
			return self::READ_ONLY_IDENTITY;
		}
		return in_array( $pkey, self::DASHBOARD_OWNED_KEYS, true ) ? self::READ_ONLY_OWNED : '';
	}

	/**
	 * The stored value of every field, by field id: the profile for a
	 * profile-mapped field, the custom-field snapshot otherwise.
	 *
	 * @param int           $user_id User ID.
	 * @param array<object> $fields  Field definitions.
	 * @phpstan-param list<CustomFieldRow> $fields
	 * @return array<int, mixed>
	 */
	public static function stored_values( int $user_id, array $fields ): array {
		$user_data = CustomFieldReader::get_user_data( $user_id );
		$profile   = self::profile_values( $user_id, $fields );
		$values    = array();
		foreach ( $fields as $field ) {
			$values[ (int) $field->id ] = empty( $field->field_profile_key )
				? ( $user_data[ 'field_' . $field->id ] ?? '' )
				: ( $profile[ (string) $field->field_profile_key ] ?? '' );
		}
		return $values;
	}

	/**
	 * Current profile values of every profile-mapped field, decrypted.
	 *
	 * The same read the reregistration form pre-fills from, so the screens
	 * cannot show different values for one field.
	 *
	 * @param int           $user_id User ID.
	 * @param array<object> $fields  Field definitions.
	 * @phpstan-param list<CustomFieldRow> $fields
	 * @return array<string, mixed> profile_key => value.
	 */
	public static function profile_values( int $user_id, array $fields ): array {
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
	 * The clear values of a user's sensitive, maskable fields, by field id:
	 * what a Reveal may serve (#1863).
	 *
	 * @param int $user_id User ID.
	 * @return array<int, string> Field ID => clear value; empty values left out.
	 */
	public static function sensitive_values( int $user_id ): array {
		$fields = self::fields( $user_id );
		$stored = self::stored_values( $user_id, $fields );

		$values = array();
		foreach ( $fields as $field ) {
			if ( ! self::is_maskable( $field ) ) {
				continue;
			}
			$value = $stored[ (int) $field->id ] ?? '';
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				$values[ (int) $field->id ] = (string) $value;
			}
		}
		return $values;
	}

	/**
	 * One field's value as a person reads it.
	 *
	 * @param object $field Field definition.
	 * @phpstan-param CustomFieldRow $field
	 * @param mixed  $value Stored value.
	 * @return string
	 */
	public static function display_value( object $field, $value ): string {
		switch ( (string) $field->field_type ) {
			case 'checkbox':
				return ! empty( $value ) ? __( 'Yes', 'ffcertificate' ) : __( 'No', 'ffcertificate' );

			case 'dependent_select':
				$pair  = is_string( $value ) ? self::decode_json( $value ) : array();
				$parts = array_filter(
					array(
						is_string( $pair['parent'] ?? null ) ? $pair['parent'] : '',
						is_string( $pair['child'] ?? null ) ? $pair['child'] : '',
					),
					static fn( string $part ): bool => '' !== $part
				);
				return implode( ' / ', $parts );

			case 'working_hours':
				return self::working_hours_summary( $value );

			case 'date':
				$text = is_scalar( $value ) ? (string) $value : '';
				if ( '' === $text ) {
					return '';
				}
				$formatted = DateFormatter::format_wallclock_date( $text );
				return '' === $formatted ? $text : $formatted;

			default:
				return is_scalar( $value ) ? (string) $value : '';
		}
	}

	/**
	 * A working-hours grid in one line: each day with its time spans.
	 *
	 * @param mixed $value Stored JSON or decoded list.
	 * @return string
	 */
	private static function working_hours_summary( $value ): string {
		$rows = is_string( $value ) ? json_decode( $value, true ) : $value;
		if ( ! is_array( $rows ) ) {
			return '';
		}
		$days  = array(
			0 => __( 'Sunday', 'ffcertificate' ),
			1 => __( 'Monday', 'ffcertificate' ),
			2 => __( 'Tuesday', 'ffcertificate' ),
			3 => __( 'Wednesday', 'ffcertificate' ),
			4 => __( 'Thursday', 'ffcertificate' ),
			5 => __( 'Friday', 'ffcertificate' ),
			6 => __( 'Saturday', 'ffcertificate' ),
		);
		$lines = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$spans = array();
			foreach ( array( array( 'entry1', 'exit1' ), array( 'entry2', 'exit2' ) ) as $pair ) {
				$from = is_string( $row[ $pair[0] ] ?? null ) ? $row[ $pair[0] ] : '';
				$to   = is_string( $row[ $pair[1] ] ?? null ) ? $row[ $pair[1] ] : '';
				if ( '' !== $from || '' !== $to ) {
					$spans[] = trim( $from . '–' . $to, '–' );
				}
			}
			$day     = $days[ (int) ( $row['day'] ?? 0 ) ] ?? '';
			$lines[] = trim( $day . ' ' . implode( ', ', $spans ) );
		}
		return implode( '; ', $lines );
	}

	/**
	 * The dashboard's read view: one section per audience, each field once,
	 * sensitive free-text values masked (#1863, #1897).
	 *
	 * @param int $user_id User ID.
	 * @return list<ProfileFieldSection>
	 */
	public static function sections( int $user_id ): array {
		$audiences = AudienceReader::get_user_audiences( $user_id );
		if ( empty( $audiences ) ) {
			return array();
		}

		$fields   = self::fields( $user_id );
		$stored   = self::stored_values( $user_id, $fields );
		$sections = array();
		$seen     = array();

		foreach ( $audiences as $audience ) {
			$views = array();
			foreach ( CustomFieldReader::get_by_audience_with_parents( (int) $audience->id, true ) as $field ) {
				$id = (int) $field->id;
				if ( isset( $seen[ $id ] ) || in_array( (string) $field->field_type, CustomFieldReader::DISPLAY_ONLY_TYPES, true ) ) {
					continue;
				}
				$seen[ $id ] = true;
				$value       = $stored[ $id ] ?? '';
				$display     = self::display_value( $field, $value );
				$masked      = self::is_maskable( $field ) && '' !== $display;
				if ( $masked ) {
					$display = DocumentFormatter::mask_any( (string) $field->field_key, is_scalar( $value ) ? (string) $value : '', self::mask_for( $field ) );
				}
				$views[] = array(
					'id'             => $id,
					'label'          => (string) $field->field_label,
					'type'           => (string) $field->field_type,
					'required'       => ! empty( $field->is_required ),
					'display'        => $display,
					'masked'         => $masked,
					'read_only'      => self::read_only_reason( $field ),
					'inherited_from' => (int) ( $field->source_audience_id ?? $audience->id ) !== (int) $audience->id ? (string) ( $field->source_audience_name ?? '' ) : '',
				);
			}
			if ( empty( $views ) ) {
				continue;
			}
			$sections[] = array(
				'audience_id' => (int) $audience->id,
				'name'        => (string) $audience->name,
				'color'       => (string) ( $audience->color ?? '' ),
				'fields'      => $views,
			);
		}

		return $sections;
	}

	/**
	 * The edit form's fields as HTML, drawn by the reregistration form's own
	 * renderer so masks, dependent selects and working hours behave alike.
	 * A sensitive value is drawn masked and disabled until it is revealed;
	 * a read-only field shows its value.
	 *
	 * @param int $user_id User ID.
	 * @return string HTML; '' when the user has no fields.
	 */
	public static function form_html( int $user_id ): string {
		$sections = self::sections( $user_id );
		if ( empty( $sections ) ) {
			return '';
		}

		$by_id = array();
		foreach ( self::fields( $user_id ) as $field ) {
			$by_id[ (int) $field->id ] = $field;
		}
		$stored = self::stored_values( $user_id, array_values( $by_id ) );

		ob_start();
		foreach ( $sections as $section ) {
			include FFC_PLUGIN_DIR . 'templates/user-dashboard/profile-custom-fields-section.php';
		}
		$html = ob_get_clean();
		return false === $html ? '' : $html;
	}

	/**
	 * One field of the edit form as HTML, with its clear value: what a
	 * Reveal swaps in for the masked one.
	 *
	 * @param object $field Field definition.
	 * @phpstan-param CustomFieldRow $field
	 * @param mixed  $value Clear stored value.
	 * @return string
	 */
	public static function field_html( object $field, $value ): string {
		ob_start();
		ReregistrationFormRenderer::render_field( $field, $value );
		$html = ob_get_clean();
		return false === $html ? '' : $html;
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
		if ( true !== CustomFieldValidator::validate( $field, $pair ) ) {
			return null;
		}

		$json = wp_json_encode( $pair );
		return false === $json ? null : $json;
	}

	/**
	 * Save posted values onto a user's fields.
	 *
	 * Two callers, two shapes. The WordPress user screen posts an HTML form
	 * (`$form_post`): an unticked checkbox is absent and means off, and a
	 * field absent for any other reason is cleared, except a masked
	 * sensitive one, which was disabled and keeps its value. The dashboard
	 * posts JSON: an absent field keeps its value, every value is validated
	 * first (`$validate`), and any error refuses the whole save.
	 *
	 * Either way an empty required field keeps what is stored, a
	 * profile-mapped field writes the profile, and the rest the snapshot.
	 *
	 * @param int               $user_id   User ID.
	 * @param array<int, mixed> $posted    Field ID => raw (unslashed) value.
	 * @param bool              $form_post Whether the values come from an HTML form post.
	 * @param bool              $validate  Whether to validate and refuse on error.
	 * @param array<int, true>  $skip      Field IDs never written (read-only).
	 * @return ProfileFieldSaveResult
	 */
	public static function save( int $user_id, array $posted, bool $form_post, bool $validate = false, array $skip = array() ): array {
		$result = array(
			'errors'     => array(),
			'kept'       => array(),
			'incomplete' => array(),
		);

		$fields = self::fields( $user_id );
		if ( empty( $fields ) ) {
			return $result;
		}

		$existing       = CustomFieldReader::get_user_data( $user_id );
		$profile        = self::profile_values( $user_id, $fields );
		$data           = array();
		$profile_patch  = array();
		$sensitive_keys = array();
		$tier           = null;

		foreach ( $fields as $field ) {
			$id   = (int) $field->id;
			$type = (string) $field->field_type;
			if ( isset( $skip[ $id ] ) || in_array( $type, CustomFieldReader::DISPLAY_ONLY_TYPES, true ) ) {
				continue;
			}

			$field_key = 'field_' . $id;
			$pkey      = empty( $field->field_profile_key ) ? '' : (string) $field->field_profile_key;
			$present   = array_key_exists( $id, $posted );
			$raw       = $present ? $posted[ $id ] : null;

			if ( ! $present ) {
				if ( ! $form_post ) {
					continue;
				}
				if ( 'checkbox' === $type ) {
					$data[ $field_key ] = 0;
					continue;
				}
				// A masked sensitive field nobody revealed was disabled, so it
				// did not post (#1863): keep what is stored.
				if ( self::is_maskable( $field ) ) {
					$tier = $tier ?? PiiAccessPolicy::resolve( self::PII_CAP, $user_id );
					if ( PiiAccessPolicy::TIER_UNMASKED !== $tier ) {
						if ( '' === $pkey && isset( $existing[ $field_key ] ) ) {
							$data[ $field_key ] = $existing[ $field_key ];
						}
						continue;
					}
				}
				$raw = 'working_hours' === $type ? '[]' : '';
			}

			if ( 'checkbox' === $type ) {
				$data[ $field_key ] = in_array( $raw, array( 1, '1', true, 'true', 'on' ), true ) ? 1 : 0;
				continue;
			}

			if ( 'working_hours' === $type ) {
				$sanitized = \FreeFormCertificate\Core\WorkingHours::sanitize( is_string( $raw ) ? $raw : '[]' );

				$data[ $field_key ] = $sanitized['json'];
				foreach ( $sanitized['incomplete'] as $row ) {
					$result['incomplete'][] = array(
						'label'   => (string) $field->field_label,
						'day'     => $row['day'],
						'missing' => $row['missing'],
					);
				}
				continue;
			}

			$stored = '' === $pkey ? ( $existing[ $field_key ] ?? '' ) : ( $profile[ $pkey ] ?? '' );
			$stored = is_scalar( $stored ) ? (string) $stored : '';

			if ( 'dependent_select' === $type ) {
				$clean = self::clean_dependent_select( $field, is_string( $raw ) ? $raw : '' );
				if ( null === $clean && $validate ) {
					/* translators: %s: field label */
					$result['errors'][ $id ] = sprintf( __( '%s has an invalid choice.', 'ffcertificate' ), (string) $field->field_label );
					continue;
				}
				// A pair outside the field's choices, or a required field
				// arriving empty, keeps what was stored rather than erasing it.
				if ( null === $clean || ( '' === $clean && ! empty( $field->is_required ) && '' !== $stored ) ) {
					if ( '' === $pkey && '' !== $stored ) {
						$data[ $field_key ] = $stored;
					}
					$result['kept'][] = (string) $field->field_label;
					continue;
				}
			} else {
				$text  = is_scalar( $raw ) ? (string) $raw : '';
				$clean = 'textarea' === $type ? sanitize_textarea_field( $text ) : sanitize_text_field( $text );

				// A masked value posted back is the mask, not a new value, and
				// never overwrites the stored one (#1863, #1897).
				if ( self::is_maskable( $field ) && '' !== $clean && false !== strpos( $clean, '*' ) ) {
					continue;
				}

				if ( $validate && '' !== $clean ) {
					$check = CustomFieldValidator::validate( $field, $clean );
					if ( is_wp_error( $check ) ) {
						$result['errors'][ $id ] = $check->get_error_message();
						continue;
					}
				}

				/*
				 * An empty value on a required field is "not supplied", never
				 * "clear it" (#1120, the shape #1114 fixed on the Rate Limit
				 * tab). The `required` attribute stops this in the browser,
				 * but the browser is not the guard.
				 */
				if ( '' === $clean && ! empty( $field->is_required ) && '' !== $stored ) {
					if ( '' === $pkey ) {
						$data[ $field_key ] = $stored;
					}
					$result['kept'][] = (string) $field->field_label;
					continue;
				}
			}

			// A profile-mapped field lives on the profile, not in the
			// snapshot (#1538): the reregistration form reads it from there.
			if ( '' !== $pkey ) {
				$profile_patch[ $pkey ] = $clean;
				if ( ! empty( $field->is_sensitive ) ) {
					$sensitive_keys[] = $pkey;
				}
				continue;
			}

			$data[ $field_key ] = $clean;
		}

		if ( ! empty( $result['errors'] ) ) {
			return $result;
		}

		// The form save always rewrites the snapshot, as it always has; the
		// dashboard writes it only when one of its fields changed.
		if ( $form_post || ! empty( $data ) ) {
			CustomFieldWriter::save_user_data( $user_id, $data );
		}

		if ( ! empty( $profile_patch ) ) {
			UserManager::update_extended_profile( $user_id, $profile_patch, $sensitive_keys );
		}

		return $result;
	}

	/**
	 * The field IDs the dashboard never writes: the read-only ones.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, true>
	 */
	public static function dashboard_read_only_ids( int $user_id ): array {
		$ids = array();
		foreach ( self::fields( $user_id ) as $field ) {
			if ( '' !== self::read_only_reason( $field ) ) {
				$ids[ (int) $field->id ] = true;
			}
		}
		return $ids;
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
}

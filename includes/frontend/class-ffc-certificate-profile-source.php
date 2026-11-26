<?php
/**
 * CertificateProfileSource
 *
 * Where a certificate form's field takes its value when a terminal issues
 * the certificate from the participant's profile (#2891).
 *
 * @package FreeFormCertificate\Frontend
 * @since 6.52.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

use FreeFormCertificate\Core\FormField;
use FreeFormCertificate\Reregistration\CustomFieldReader;
use FreeFormCertificate\Reregistration\ProfileCustomFields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The "Fill from profile" choice of a form field.
 *
 * Name, e-mail and CPF/RF are always filled from the profile and need no
 * choice. Any other field may name a field of the global profile list,
 * stored as `field:<field_key>` in the field's `profile_source`, and a
 * checkbox may be accepted by the reading itself (`consent`): showing the
 * QR at the terminal is the participant's acceptance.
 *
 * Only the global list is offered, because it is the one list every
 * audience asks; a field an audience adds for itself is still read when it
 * shares the key.
 */
final class CertificateProfileSource {

	/** A checkbox the reading of the QR accepts. */
	public const CONSENT = 'consent';

	/** Prefix of a profile field source. */
	public const FIELD_PREFIX = 'field:';

	/** Field types a profile field can fill. */
	public const FILLABLE_TYPES = array( 'text', 'email', 'number', 'date', 'textarea', 'select', 'radio', 'checkbox' );

	/**
	 * The profile fields an editor may pick, as source => label.
	 *
	 * @return array<string, string>
	 */
	public static function options(): array {
		$options = array();
		foreach ( CustomFieldReader::get_by_audience_with_parents( 0, true ) as $field ) {
			$key = (string) $field->field_key;
			if ( '' === $key || isset( $options[ self::FIELD_PREFIX . $key ] ) ) {
				continue;
			}
			$options[ self::FIELD_PREFIX . $key ] = (string) $field->field_label;
		}
		return $options;
	}

	/**
	 * A posted source, kept only when it fits the field's type.
	 *
	 * The field key is sanitised as a key and not checked against the
	 * global list, so a field removed from the list later reads as empty
	 * rather than rewriting the form.
	 *
	 * @param string $source Posted value.
	 * @param string $type   Field type.
	 * @return string The source, or ''.
	 */
	public static function sanitize( string $source, string $type ): string {
		if ( ! in_array( $type, self::FILLABLE_TYPES, true ) ) {
			return '';
		}
		if ( self::CONSENT === $source ) {
			return 'checkbox' === $type ? self::CONSENT : '';
		}
		if ( 0 !== strpos( $source, self::FIELD_PREFIX ) ) {
			return '';
		}
		$key = sanitize_key( substr( $source, strlen( self::FIELD_PREFIX ) ) );
		return '' === $key ? '' : self::FIELD_PREFIX . $key;
	}

	/**
	 * A field's source, or ''.
	 *
	 * @param array<string, mixed> $field Stored form field.
	 * @return string
	 */
	public static function of( array $field ): string {
		$source = isset( $field['profile_source'] ) && is_string( $field['profile_source'] ) ? $field['profile_source'] : '';
		$type   = isset( $field['type'] ) && is_string( $field['type'] ) ? $field['type'] : 'text';
		return self::sanitize( $source, $type );
	}

	/**
	 * The value a field takes from the profile values, or null when it has
	 * none.
	 *
	 * @param array<string, mixed>  $field   Stored form field.
	 * @param array<string, string> $profile Values by source, from {@see self::values()}.
	 * @return string|list<string>|null
	 */
	public static function value_for( array $field, array $profile ) {
		$source = self::of( $field );
		if ( self::CONSENT === $source ) {
			$choices = FormField::choices( $field );
			return array() === $choices ? null : $choices;
		}
		if ( '' === $source ) {
			return null;
		}
		$value = $profile[ $source ] ?? '';
		if ( '' === $value ) {
			return null;
		}
		// A choice takes the profile value only when it is one of the
		// field's options; anything else is left for the person to pick.
		$type = isset( $field['type'] ) && is_string( $field['type'] ) ? $field['type'] : 'text';
		if ( in_array( $type, FormField::CHOICE_TYPES, true ) ) {
			if ( ! in_array( $value, FormField::choices( $field ), true ) ) {
				return null;
			}
			return 'checkbox' === $type ? array( $value ) : $value;
		}
		return $value;
	}

	/**
	 * What a person's profile holds for the field sources a form names, as
	 * the person reads it.
	 *
	 * The fields read are the person's own, global ones included, so a
	 * value lives where the profile screen shows it.
	 *
	 * @param int                        $user_id Account id.
	 * @param list<array<string, mixed>> $fields  Form fields.
	 * @return array<string, string> Source => value; empty values left out.
	 */
	public static function values( int $user_id, array $fields ): array {
		$wanted = array();
		foreach ( $fields as $field ) {
			$source = self::of( $field );
			if ( '' !== $source && self::CONSENT !== $source ) {
				$wanted[ substr( $source, strlen( self::FIELD_PREFIX ) ) ] = true;
			}
		}
		if ( array() === $wanted ) {
			return array();
		}

		$definitions = array();
		foreach ( array_merge( CustomFieldReader::get_all_for_user( $user_id, true ), CustomFieldReader::get_by_audience_with_parents( 0, true ) ) as $definition ) {
			$definitions[ (int) $definition->id ] = $definition;
		}
		$definitions = array_values( $definitions );
		$stored      = ProfileCustomFields::stored_values( $user_id, $definitions );

		$values = array();
		foreach ( $definitions as $definition ) {
			$key = (string) $definition->field_key;
			if ( ! isset( $wanted[ $key ] ) || isset( $values[ self::FIELD_PREFIX . $key ] ) ) {
				continue;
			}
			$raw = $stored[ (int) $definition->id ] ?? '';
			if ( '' === $raw || array() === $raw ) {
				continue;
			}
			// A date keeps its stored Y-m-d, the shape the form posts; any
			// other value is read as the profile screen shows it.
			$value = 'date' === (string) $definition->field_type && is_scalar( $raw )
				? trim( (string) $raw )
				: trim( ProfileCustomFields::display_value( $definition, $raw ) );
			if ( '' !== $value ) {
				$values[ self::FIELD_PREFIX . $key ] = $value;
			}
		}
		return $values;
	}
}

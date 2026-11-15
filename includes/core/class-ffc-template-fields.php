<?php
/**
 * TemplateFields
 *
 * Fields a certificate template declares and each form that references it
 * fills (#2581), printed as `{{model:<key>}}`.
 *
 * @package FreeFormCertificate\Core
 * @since   6.45.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Declaration, sanitising and token replacement of template-declared fields.
 *
 * A declaration is a list of rows `{key, label, type, required, default}`
 * stored on the template. The values live on each form, keyed by `key`. The
 * `model:` prefix keeps these tokens apart from the form's own fields, which
 * print as `{{<name>}}` and already use names such as `unidade`.
 *
 * @phpstan-type TemplateField array{key: string, label: string, type: string, required: bool, default: string}
 */
final class TemplateFields {

	/**
	 * The field types a template can declare.
	 */
	public const TYPES = array( 'text', 'textarea', 'date' );

	/**
	 * How many fields one template may declare.
	 */
	public const MAX_FIELDS = 20;

	/**
	 * `{{model:key}}`, and `{{model:key:format=…}}` for a date.
	 */
	private const TOKEN_PATTERN = '/\{\{model:([a-z0-9_]+)(?::format=([^}]*))?\}\}/';

	/**
	 * A clean declaration from posted or stored rows: rows without a usable key
	 * are dropped, a repeated key keeps its first row.
	 *
	 * @param mixed $rows Raw rows.
	 * @return array<int, array{key: string, label: string, type: string, required: bool, default: string}>
	 */
	public static function sanitize( $rows ): array {
		$clean = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) || count( $clean ) >= self::MAX_FIELDS ) {
				continue;
			}
			$key = str_replace( '-', '_', sanitize_key( ArrayValue::string( $row, 'key' ) ) );
			if ( '' === $key || isset( $clean[ $key ] ) ) {
				continue;
			}
			$type    = ArrayValue::string( $row, 'type', 'text' );
			$type    = in_array( $type, self::TYPES, true ) ? $type : 'text';
			$label   = sanitize_text_field( ArrayValue::string( $row, 'label' ) );
			$default = self::sanitize_value( $type, ArrayValue::string( $row, 'default' ) );

			$clean[ $key ] = array(
				'key'      => $key,
				'label'    => '' !== $label ? $label : $key,
				'type'     => $type,
				'required' => ! empty( $row['required'] ),
				'default'  => $default,
			);
		}
		return array_values( $clean );
	}

	/**
	 * One value as its field type stores it.
	 *
	 * @param string $type  Field type.
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function sanitize_value( string $type, string $value ): string {
		switch ( $type ) {
			case 'textarea':
				return sanitize_textarea_field( $value );
			case 'date':
				return EventDate::valid( $value );
			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * The values a form posted for a declaration, each sanitised by its type.
	 * Keys the declaration does not name are dropped.
	 *
	 * @param array<int, array{key: string, label: string, type: string, required: bool, default: string}> $fields Declaration.
	 * @param mixed                                                                                        $raw    Posted values.
	 * @return array<string, string>
	 */
	public static function sanitize_values( array $fields, $raw ): array {
		$raw    = is_array( $raw ) ? $raw : array();
		$values = array();
		foreach ( $fields as $field ) {
			$values[ $field['key'] ] = self::sanitize_value( $field['type'], ArrayValue::string( $raw, $field['key'] ) );
		}
		return $values;
	}

	/**
	 * Labels of the required fields that have neither a value nor a default.
	 *
	 * @param array<int, array{key: string, label: string, type: string, required: bool, default: string}> $fields Declaration.
	 * @param array<string, string>                                                                        $values The form's values.
	 * @return array<int, string>
	 */
	public static function missing_required( array $fields, array $values ): array {
		$missing = array();
		foreach ( $fields as $field ) {
			if ( $field['required'] && '' === self::value_of( $field, $values ) ) {
				$missing[] = $field['label'];
			}
		}
		return $missing;
	}

	/**
	 * Replace every `{{model:…}}` token the declaration knows. A token naming a
	 * key the template does not declare stays visible, so a typo is noticed.
	 *
	 * @param string                                                                                       $layout Layout HTML.
	 * @param array<int, array{key: string, label: string, type: string, required: bool, default: string}> $fields Declaration.
	 * @param array<string, string>                                                                        $values The form's values; a missing or empty one prints the default.
	 * @return string
	 */
	public static function replace_tokens( string $layout, array $fields, array $values ): string {
		if ( false === strpos( $layout, '{{model:' ) ) {
			return $layout;
		}
		$by_key = array();
		foreach ( $fields as $field ) {
			$by_key[ $field['key'] ] = $field;
		}
		return (string) preg_replace_callback(
			self::TOKEN_PATTERN,
			static function ( array $m ) use ( $by_key, $values ): string {
				if ( ! isset( $by_key[ $m[1] ] ) ) {
					return $m[0];
				}
				$field  = $by_key[ $m[1] ];
				$value  = self::value_of( $field, $values );
				$format = isset( $m[2] ) ? html_entity_decode( (string) $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
				switch ( $field['type'] ) {
					case 'date':
						return esc_html( EventDate::format_one( $value, $format ) );
					case 'textarea':
						return nl2br( esc_html( $value ), false );
					default:
						return esc_html( $value );
				}
			},
			$layout
		);
	}

	/**
	 * Keys of the `{{model:…}}` tokens a layout uses.
	 *
	 * @param string $layout Layout HTML.
	 * @return array<int, string>
	 */
	public static function used_keys( string $layout ): array {
		preg_match_all( self::TOKEN_PATTERN, $layout, $m );
		return array_values( array_unique( $m[1] ) );
	}

	/**
	 * A field's value on a form, or its default.
	 *
	 * @param array{key: string, label: string, type: string, required: bool, default: string} $field  Field.
	 * @param array<string, string>                                                            $values The form's values.
	 */
	private static function value_of( array $field, array $values ): string {
		$value = isset( $values[ $field['key'] ] ) ? trim( (string) $values[ $field['key'] ] ) : '';
		return '' !== $value ? $value : $field['default'];
	}
}

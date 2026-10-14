<?php
/**
 * RequiredData
 *
 * The "Required data" table on Settings → Certificates (#1759): one row per
 * piece of data a certificate needs, with a switch for "required in the
 * form" and one for "required in the certificate HTML".
 *
 * @package FreeFormCertificate\Settings
 * @since 6.40.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Settings;

use FreeFormCertificate\Core\ArrayValue;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Store, sanitiser and one-shot migration of the `required_data` table.
 *
 * Two settings described what a certificate requires until #1759:
 * `required_form_fields` (a name/label/type builder) and
 * `required_certificate_tags` (a textarea of `{{tag}}` tokens). They
 * overlapped on `name` and `cpf_rf` and were edited apart. One table now
 * holds both, and {@see SettingsReader::required_form_fields()} and
 * {@see SettingsReader::required_certificate_tags()} derive from it, so
 * every consumer keeps reading the shape it always read.
 *
 * The two legacy keys stay in the option and stay readable: until
 * {@see self::maybe_migrate()} has built the table, the readers fall back
 * to them, and the migration builds the table from exactly what those
 * fallbacks return, so the switch-over changes nothing a form sees.
 */
final class RequiredData {

	/**
	 * Key of the table inside `ffc_settings`.
	 */
	public const KEY = 'required_data';

	/**
	 * Option flag of the one-shot migration from the two legacy keys.
	 */
	public const MIGRATION_FLAG = 'ffc_required_data_v1';

	/**
	 * The field every form must carry: the certificate is sent to it.
	 */
	public const LOCKED_FORM_FIELD = 'email';

	/**
	 * The tag every layout must carry: verification depends on it.
	 */
	public const LOCKED_TAG = 'auth_code';

	/**
	 * Whether the plugin fills this tag in itself, so no form field can
	 * provide it and the row only has an HTML switch.
	 *
	 * @param string $name Variable name, without braces.
	 */
	public static function is_generated( string $name ): bool {
		return in_array( $name, SettingsReader::GENERATED_TAGS, true ) || 0 === strpos( $name, 'qr_code' );
	}

	/**
	 * The stored table, or null when it was never built.
	 *
	 * An empty or unusable stored value reads as "never built" so the
	 * readers fall back to the legacy keys rather than to a table that
	 * requires nothing.
	 *
	 * @return array<int, array{name: string, label: string, type: string, form: bool, html: bool}>|null
	 */
	public static function stored(): ?array {
		$raw = SettingsReader::get( 'required_data' );
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$rows = array();
		foreach ( $raw as $row ) {
			$row = self::stored_row( $row );
			if ( null !== $row && ! isset( $rows[ $row['name'] ] ) ) {
				$rows[ $row['name'] ] = $row;
			}
		}
		return array() === $rows ? null : array_values( $rows );
	}

	/**
	 * The table the Certificates tab shows: the stored one, or the one the
	 * migration would build from the legacy keys.
	 *
	 * @return array<int, array{name: string, label: string, type: string, form: bool, html: bool}>
	 */
	public static function rows(): array {
		return self::stored() ?? self::from_legacy();
	}

	/**
	 * The table built from the two legacy keys, through the readers' own
	 * legacy paths: a union by name, the form switch on for every row the
	 * field list required and the HTML switch on for every tag the tag
	 * list required. Form fields come first, in their order, then the
	 * tags no field provides, in theirs.
	 *
	 * A tag that is not a plain `{{name}}` cannot be a row and is left out.
	 *
	 * @return array<int, array{name: string, label: string, type: string, form: bool, html: bool}>
	 */
	public static function from_legacy(): array {
		$rows = array();
		foreach ( SettingsReader::legacy_form_fields() as $field ) {
			$rows[ $field['name'] ] = array(
				'name'  => $field['name'],
				'label' => $field['label'],
				'type'  => $field['type'],
				'form'  => true,
				'html'  => false,
			);
		}
		foreach ( SettingsReader::legacy_certificate_tags() as $tag ) {
			if ( 1 !== preg_match( '/^\{\{([a-z0-9_]+)\}\}$/i', $tag, $m ) ) {
				continue;
			}
			$name = strtolower( $m[1] );
			if ( isset( $rows[ $name ] ) ) {
				$rows[ $name ]['html'] = true;
				continue;
			}
			$rows[ $name ] = array(
				'name'  => $name,
				'label' => $name,
				'type'  => 'text',
				'form'  => false,
				'html'  => true,
			);
		}
		return array_values( $rows );
	}

	/**
	 * Build the table from the legacy keys, once (#1759).
	 *
	 * Flagged by {@see self::MIGRATION_FLAG}. A table already stored, by a
	 * Save or an autosave that reached the option first, is never
	 * overwritten. Runs on `init` rather than with the other one-shots on
	 * `plugins_loaded`, because the default labels it may write are
	 * translated.
	 *
	 * The `init` callback; {@see self::migrate()} does the work and says
	 * how much it did.
	 */
	public static function maybe_migrate(): void {
		self::migrate();
	}

	/**
	 * The migration itself, flag check included.
	 *
	 * @return int Rows written: 0 when the flag was set or a table already existed.
	 */
	public static function migrate(): int {
		if ( '1' === get_option( self::MIGRATION_FLAG, '' ) ) {
			return 0;
		}

		$written = 0;
		if ( null === self::stored() ) {
			$rows                  = self::from_legacy();
			$settings              = SettingsReader::all();
			$settings[ self::KEY ] = $rows;
			$written               = count( $rows );
			update_option( SettingsReader::OPTION_KEY, $settings );
		}

		update_option( self::MIGRATION_FLAG, '1', true );
		return $written;
	}

	/**
	 * The posted table as it is stored — from the tab's Save button (an
	 * array of rows) and from the autosave (the same rows, decoded).
	 *
	 * A name is sanitised as a field key: lowercase letters, digits and
	 * underscores, a hyphen read as an underscore. A row whose name comes
	 * out empty is a row still being typed and is skipped; a duplicate name
	 * keeps the first row. A type outside the allowlist becomes Text. A
	 * generated tag never carries the form switch, Email always does and
	 * `{{auth_code}}` always carries the HTML switch; both rows are added
	 * when absent, so a stored table always requires them.
	 *
	 * @param array<array-key, mixed> $rows Raw rows.
	 * @return array<int, array{name: string, label: string, type: string, form: bool, html: bool}>|null Null when a row is not a row.
	 */
	public static function sanitize( array $rows ): ?array {
		$clean = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				return null;
			}
			$name = str_replace( '-', '_', sanitize_key( ArrayValue::string( $row, 'name' ) ) );
			if ( '' === $name || isset( $clean[ $name ] ) ) {
				continue;
			}
			$type           = sanitize_key( ArrayValue::string( $row, 'type', 'text' ) );
			$label          = sanitize_text_field( ArrayValue::string( $row, 'label' ) );
			$clean[ $name ] = self::normalise(
				array(
					'name'  => $name,
					'label' => '' !== $label ? $label : $name,
					'type'  => in_array( $type, SettingsReader::REQUIRED_FIELD_TYPES, true ) ? $type : 'text',
					'form'  => ArrayValue::bool( $row, 'form' ),
					'html'  => ArrayValue::bool( $row, 'html' ),
				)
			);
		}

		if ( ! isset( $clean[ self::LOCKED_FORM_FIELD ] ) ) {
			$clean[ self::LOCKED_FORM_FIELD ] = self::normalise(
				array(
					'name'  => self::LOCKED_FORM_FIELD,
					'label' => __( 'Email', 'ffcertificate' ),
					'type'  => 'email',
					'form'  => true,
					'html'  => false,
				)
			);
		}
		if ( ! isset( $clean[ self::LOCKED_TAG ] ) ) {
			$clean[ self::LOCKED_TAG ] = self::normalise(
				array(
					'name'  => self::LOCKED_TAG,
					'label' => self::LOCKED_TAG,
					'type'  => 'text',
					'form'  => false,
					'html'  => true,
				)
			);
		}

		return array_values( $clean );
	}

	/**
	 * Decode and sanitise the autosaved table: a JSON list of rows.
	 *
	 * @param mixed $raw Raw POST value (already unslashed).
	 * @return array<int, array{name: string, label: string, type: string, form: bool, html: bool}>|null Null on anything but a list of rows.
	 */
	public static function sanitize_json( $raw ): ?array {
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || ! array_is_list( $decoded ) ) {
			return null;
		}
		return self::sanitize( $decoded );
	}

	/**
	 * One stored row, or null when it names no usable field.
	 *
	 * @param mixed $row Stored row.
	 * @return array{name: string, label: string, type: string, form: bool, html: bool}|null
	 */
	private static function stored_row( $row ): ?array {
		if ( ! is_array( $row ) ) {
			return null;
		}
		$name = strtolower( ArrayValue::string( $row, 'name' ) );
		if ( 1 !== preg_match( '/^[a-z0-9_]+$/', $name ) ) {
			return null;
		}
		$type = ArrayValue::string( $row, 'type', 'text' );
		return self::normalise(
			array(
				'name'  => $name,
				'label' => '' !== ArrayValue::string( $row, 'label' ) ? ArrayValue::string( $row, 'label' ) : $name,
				'type'  => in_array( $type, SettingsReader::REQUIRED_FIELD_TYPES, true ) ? $type : 'text',
				'form'  => ArrayValue::bool( $row, 'form' ),
				'html'  => ArrayValue::bool( $row, 'html' ),
			)
		);
	}

	/**
	 * Apply the three locks to a row.
	 *
	 * @param array{name: string, label: string, type: string, form: bool, html: bool} $row Row.
	 * @return array{name: string, label: string, type: string, form: bool, html: bool}
	 */
	private static function normalise( array $row ): array {
		if ( self::is_generated( $row['name'] ) ) {
			$row['form'] = false;
		}
		if ( self::LOCKED_FORM_FIELD === $row['name'] ) {
			$row['form'] = true;
			$row['type'] = 'email';
		}
		if ( self::LOCKED_TAG === $row['name'] ) {
			$row['html'] = true;
		}
		return $row;
	}
}

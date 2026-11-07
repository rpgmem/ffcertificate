<?php
/**
 * Custom Field Writer
 *
 * Write-side of the custom-field repository split (#563 backlog, A6). Holds every
 * INSERT / UPDATE / DELETE and the write-only helpers (key generation, cache
 * invalidation). Reads live in {@see CustomFieldReader}. Callers depend on the
 * reader (reads) and this writer (writes) directly; the delegating façade was
 * retired in #563 B3-A.
 *
 * @since   6.12.0
 * @package FreeFormCertificate\Reregistration
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$field_id} is an int cast and {$where} a fragment assembled in this class; table names go through %i and every value through a placeholder.

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement in this class runs against one of the plugin's own ffc_* tables, which WordPress exposes no API for. Caching is decided per read at the repository layer, not per statement (#1042).
/**
 * Write operations for audience-specific custom field definitions.
 *
 * @since 6.12.0
 *
 * @phpstan-import-type CustomFieldRow from CustomFieldReader
 */
class CustomFieldWriter {
	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Cache group for custom field queries.
	 *
	 * Must match {@see CustomFieldReader::cache_group()} so writes invalidate
	 * the entries reads populate.
	 *
	 * @return string
	 */
	protected static function cache_group(): string {
		return 'ffc_custom_fields';
	}

	/**
	 * Get table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		return self::db()->prefix . 'ffc_custom_fields';
	}

	/**
	 * Whether a field may be bound to this profile key (#2242). No key is
	 * always allowed; a key is allowed when the profile map says it is
	 * bindable, which refuses `user_email` and every other `wp_users` key.
	 * Checked here as well as in the editor's AJAX, so no other caller can
	 * write such a binding either.
	 *
	 * @param mixed $key Profile key, or null for no binding.
	 * @return bool
	 */
	private static function profile_key_allowed( $key ): bool {
		if ( null === $key || '' === $key ) {
			return true;
		}
		return is_scalar( $key ) && \FreeFormCertificate\UserDashboard\UserProfileFieldMap::is_bindable( sanitize_key( (string) $key ) );
	}

	/**
	 * Create a custom field.
	 *
	 * @param array<string, mixed> $data Field data.
	 * @return int|false Field ID or false on failure.
	 */
	public static function create( array $data ) {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$defaults = array(
			'audience_id'       => 0,
			'field_key'         => '',
			'field_label'       => '',
			'field_type'        => 'text',
			'field_group'       => '',
			'field_source'      => 'custom',
			'field_profile_key' => null,
			'field_mask'        => null,
			'is_sensitive'      => 0,
			'field_options'     => null,
			'validation_rules'  => null,
			'sort_order'        => 0,
			'is_required'       => 0,
			'is_active'         => 1,
		);
		$data     = wp_parse_args( $data, $defaults );

		if ( ! self::profile_key_allowed( $data['field_profile_key'] ) ) {
			return false;
		}

		// Validate field type.
		if ( ! in_array( $data['field_type'], CustomFieldReader::FIELD_TYPES, true ) ) {
			$data['field_type'] = 'text';
		}

		// Validate field source.
		if ( ! in_array( $data['field_source'], array( 'standard', 'custom' ), true ) ) {
			$data['field_source'] = 'custom';
		}

		// Auto-generate field_key from label if empty.
		if ( empty( $data['field_key'] ) ) {
			$data['field_key'] = self::generate_field_key( $data['field_label'] );
		}

		// Ensure field_key uniqueness within audience.
		$data['field_key'] = self::ensure_unique_key( $data['field_key'], (int) $data['audience_id'] );

		$insert_data = array(
			'audience_id'       => (int) $data['audience_id'],
			'field_key'         => sanitize_key( $data['field_key'] ),
			'field_label'       => sanitize_text_field( $data['field_label'] ),
			'field_type'        => $data['field_type'],
			'field_group'       => sanitize_text_field( (string) $data['field_group'] ),
			'field_source'      => $data['field_source'],
			'field_profile_key' => null !== $data['field_profile_key'] ? sanitize_key( (string) $data['field_profile_key'] ) : null,
			'field_mask'        => null !== $data['field_mask'] ? sanitize_text_field( (string) $data['field_mask'] ) : null,
			'is_sensitive'      => (int) $data['is_sensitive'],
			'field_options'     => is_string( $data['field_options'] ) ? $data['field_options'] : wp_json_encode( $data['field_options'] ),
			'validation_rules'  => is_string( $data['validation_rules'] ) ? $data['validation_rules'] : wp_json_encode( $data['validation_rules'] ),
			'sort_order'        => (int) $data['sort_order'],
			'is_required'       => (int) $data['is_required'],
			'is_active'         => (int) $data['is_active'],
		);

		$result = $wpdb->insert(
			$table,
			$insert_data,
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d' )
		);

		if ( $result ) {
			self::invalidate_sensitive_registry_cache();
		}

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Drop an `is_sensitive` demotion from an update payload (#1509).
	 *
	 * Reads the STORED value rather than trusting the caller: a payload that
	 * sets the flag to what it already is asks for nothing and must not be
	 * reported as a refusal.
	 *
	 * @param int                  $field_id Field ID.
	 * @param array<string, mixed> $data     Update payload.
	 * @return array<string, mixed> The payload, without a demotion.
	 */
	private static function keep_sensitive_once_set( int $field_id, array $data ): array {
		if ( ! array_key_exists( 'is_sensitive', $data ) ) {
			return $data;
		}

		// Only a demotion is refused; setting it, or re-setting it, is fine.
		if ( ! empty( $data['is_sensitive'] ) ) {
			return $data;
		}

		$current = CustomFieldReader::get_by_id( $field_id );

		if ( ! $current || empty( $current->is_sensitive ) ) {
			// It was already off. Nothing is being demoted.
			return $data;
		}

		unset( $data['is_sensitive'] );

		if ( class_exists( '\\FreeFormCertificate\\Core\\ActivityLog' ) ) {
			\FreeFormCertificate\Core\ActivityLog::log(
				'custom_field_sensitive_demotion_refused',
				\FreeFormCertificate\Core\ActivityLog::LEVEL_WARNING,
				array(
					'field_id'  => $field_id,
					'field_key' => (string) ( $current->field_key ?? '' ),
					'reason'    => 'Clearing is_sensitive would abandon ciphertext already stored under this field.',
				)
			);
		}

		return $data;
	}

	/**
	 * The properties a field keeps once anybody answered it (#2242).
	 *
	 * Answers are stored by key, read by type, encrypted by sensitivity and
	 * copied to the profile by binding; changing any of them loses what was
	 * answered. Label, help text, options, group, order, required and active
	 * stay editable.
	 *
	 * @var list<string>
	 */
	public const ANSWER_LOCKED_PROPERTIES = array( 'field_key', 'field_type', 'is_sensitive', 'field_profile_key' );

	/**
	 * Drop changes to locked properties of an answered field from a payload.
	 *
	 * Like {@see self::keep_sensitive_once_set()}, the refused keys are
	 * dropped and the rest of the save applies; the editor draws these
	 * controls locked, so only a stale or crafted request reaches here. The
	 * refusal is logged.
	 *
	 * @param int                  $field_id Field ID.
	 * @param array<string, mixed> $data     Update payload.
	 * @return array<string, mixed> The payload, without locked changes.
	 */
	private static function keep_locked_once_answered( int $field_id, array $data ): array {
		$asked = array_intersect_key( $data, array_flip( self::ANSWER_LOCKED_PROPERTIES ) );
		if ( empty( $asked ) ) {
			return $data;
		}

		$current = CustomFieldReader::get_by_id( $field_id );
		if ( ! $current ) {
			return $data;
		}

		$changed = array();
		foreach ( $asked as $key => $value ) {
			if ( self::locked_value( $key, $value ) !== self::locked_value( $key, $current->$key ?? null ) ) {
				$changed[] = $key;
			}
		}

		if ( empty( $changed ) || ! CustomFieldReader::has_answers( $current ) ) {
			return $data;
		}

		foreach ( $changed as $key ) {
			unset( $data[ $key ] );
		}

		if ( class_exists( '\\FreeFormCertificate\\Core\\ActivityLog' ) ) {
			\FreeFormCertificate\Core\ActivityLog::log(
				'custom_field_locked_change_refused',
				\FreeFormCertificate\Core\ActivityLog::LEVEL_WARNING,
				array(
					'field_id'   => $field_id,
					'field_key'  => (string) ( $current->field_key ?? '' ),
					'properties' => $changed,
				)
			);
		}

		return $data;
	}

	/**
	 * A locked property's value, normalised for comparison.
	 *
	 * @param string $key   Property.
	 * @param mixed  $value Value as posted or stored.
	 * @return string
	 */
	private static function locked_value( string $key, $value ): string {
		if ( 'is_sensitive' === $key ) {
			return empty( $value ) ? '0' : '1';
		}
		$value = is_scalar( $value ) ? (string) $value : '';
		return 'field_type' === $key ? sanitize_text_field( $value ) : sanitize_key( $value );
	}

	/**
	 * Update a custom field.
	 *
	 * `is_sensitive` IS A ONE-WAY DOOR, and the demotion is dropped here (#1509).
	 *
	 * Turning the flag ON is legitimate and must stay possible: it is how an
	 * administrator starts protecting a field, and the values already stored in
	 * plaintext are the reader's problem, not a reason to refuse.
	 *
	 * Turning it OFF is not the inverse of that. It does not un-encrypt anything
	 * — it abandons the ciphertext already written, which then reaches a CSV
	 * export, a generated record and the public verification page as if it were
	 * the person's CPF. The setting would be promising an operation the system
	 * cannot perform, so it is not offered.
	 *
	 * THE DEMOTION IS DROPPED, NOT THE WHOLE SAVE, and that is a deliberate
	 * choice between two imperfect options. Refusing the update would discard
	 * the label or ordering the operator edited in the same request, with no
	 * message to say why. Dropping just this key applies everything else and the
	 * screen re-renders with the box still ticked, which is the feedback. It is
	 * recorded in the activity log so the refusal is auditable rather than
	 * merely invisible.
	 *
	 * THE READER STILL DOES NOT TRUST THE FLAG, and that is not redundancy: this
	 * prevents new occurrences, while {@see RecordGenerator::decrypt_field_values()}
	 * heals the installs that already demoted a field before this existed. A
	 * write-time guard cannot reach state that is already wrong.
	 *
	 * @since 6.31.0 Refuses to clear `is_sensitive`.
	 * @param int                  $field_id Field ID.
	 * @param array<string, mixed> $data     Update data.
	 * @return bool
	 */
	public static function update( int $field_id, array $data ): bool {
		$wpdb  = self::db();
		$table = self::get_table_name();

		// Remove non-updatable fields.
		unset( $data['id'], $data['created_at'] );

		if ( empty( $data ) ) {
			return false;
		}

		if ( array_key_exists( 'field_profile_key', $data ) && ! self::profile_key_allowed( $data['field_profile_key'] ) ) {
			return false;
		}

		$data = self::keep_sensitive_once_set( $field_id, $data );
		$data = self::keep_locked_once_answered( $field_id, $data );

		if ( empty( $data ) ) {
			// The demotion was the only thing asked for, so there is nothing
			// left to write. Reported as a success: the field is in the state
			// the caller wanted for every key it is allowed to change.
			return true;
		}

		$update_data = array();
		$format      = array();

		$field_formats = array(
			'audience_id'       => '%d',
			'field_key'         => '%s',
			'field_label'       => '%s',
			'field_type'        => '%s',
			'field_group'       => '%s',
			'field_source'      => '%s',
			'field_profile_key' => '%s',
			'field_mask'        => '%s',
			'is_sensitive'      => '%d',
			'field_options'     => '%s',
			'validation_rules'  => '%s',
			'sort_order'        => '%d',
			'is_required'       => '%d',
			'is_active'         => '%d',
		);

		foreach ( $data as $key => $value ) {
			if ( ! isset( $field_formats[ $key ] ) ) {
				continue;
			}

			// Encode JSON fields.
			if ( in_array( $key, array( 'field_options', 'validation_rules' ), true ) && ! is_string( $value ) ) {
				$value = wp_json_encode( $value );
			}

			// Sanitize text fields.
			if ( in_array( $key, array( 'field_key', 'field_profile_key' ), true ) ) {
				$value = is_scalar( $value ) ? sanitize_key( (string) $value ) : null;
			} elseif ( in_array( $key, array( 'field_label', 'field_type', 'field_group', 'field_mask', 'field_source' ), true ) ) {
				$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : null;
			}

			// Validate field type.
			if ( 'field_type' === $key && ! in_array( $value, CustomFieldReader::FIELD_TYPES, true ) ) {
				$value = 'text';
			}

			// Validate field source.
			if ( 'field_source' === $key && ! in_array( $value, array( 'standard', 'custom' ), true ) ) {
				$value = 'custom';
			}

			$update_data[ $key ] = $value;
			$format[]            = $field_formats[ $key ];
		}

		if ( empty( $update_data ) ) {
			return false;
		}

		$result = $wpdb->update(
			$table,
			$update_data,
			array( 'id' => $field_id ),
			$format,
			array( '%d' )
		);

		static::cache_delete( "id_{$field_id}" );

		// Changes to is_sensitive or is_active must reset the registry's
		// dynamic cache so ActivityLog picks the new set on next call.
		if ( false !== $result && ( array_key_exists( 'is_sensitive', $update_data ) || array_key_exists( 'is_active', $update_data ) || array_key_exists( 'field_key', $update_data ) ) ) {
			self::invalidate_sensitive_registry_cache();
		}

		return false !== $result;
	}

	/**
	 * Delete a custom field definition.
	 *
	 * Standard fields (field_source='standard') cannot be deleted — only
	 * deactivated. This is enforced to preserve the field seeding invariant.
	 * Nor can a field anybody answered (#2242).
	 *
	 * Note: This only removes the field definition. User data in wp_usermeta
	 * remains as orphaned keys in the JSON — this is by design so data can
	 * be recovered if the field is re-created.
	 *
	 * @param int $field_id Field ID.
	 * @return bool
	 */
	public static function delete( int $field_id ): bool {
		$field = CustomFieldReader::get_by_id( $field_id );
		if ( $field && isset( $field->field_source ) && 'standard' === $field->field_source ) {
			return false;
		}

		// An answered field is deactivated, never deleted (#2242): its
		// answers stay readable in review, the Record and the export.
		if ( $field && CustomFieldReader::has_answers( $field ) ) {
			return false;
		}

		$wpdb  = self::db();
		$table = self::get_table_name();

		$result = $wpdb->delete( $table, array( 'id' => $field_id ), array( '%d' ) );

		// The audiences' changes to the field go with it (#2502): they point
		// at a field that no longer exists.
		if ( false !== $result ) {
			$wpdb->delete( $table, array( 'overrides_field_id' => $field_id ), array( '%d' ) );
		}

		static::cache_delete( "id_{$field_id}" );

		if ( false !== $result && $field && ! empty( $field->is_sensitive ) ) {
			self::invalidate_sensitive_registry_cache();
		}

		return false !== $result;
	}

	/**
	 * Deactivate a field (hide but preserve data).
	 *
	 * @param int $field_id Field ID.
	 * @return bool
	 */
	public static function deactivate( int $field_id ): bool {
		$result = self::update( $field_id, array( 'is_active' => 0 ) );

		static::cache_delete( "id_{$field_id}" );

		return $result;
	}

	/**
	 * Reactivate a previously deactivated field.
	 *
	 * @param int $field_id Field ID.
	 * @return bool
	 */
	public static function reactivate( int $field_id ): bool {
		$result = self::update( $field_id, array( 'is_active' => 1 ) );

		static::cache_delete( "id_{$field_id}" );

		return $result;
	}

	/**
	 * Reorder fields by updating sort_order in batch.
	 *
	 * @param array<int> $field_ids Ordered array of field IDs.
	 * @return bool
	 */
	public static function reorder( array $field_ids ): bool {
		$wpdb  = self::db();
		$table = self::get_table_name();

		foreach ( $field_ids as $index => $field_id ) {
			$wpdb->update(
				$table,
				array( 'sort_order' => $index ),
				array( 'id' => (int) $field_id ),
				array( '%d' ),
				array( '%d' )
			);
		}

		return true;
	}

	/**
	 * Update only the field_group of a field.
	 *
	 * @param int    $field_id Field ID.
	 * @param string $group    New group key.
	 * @return bool
	 *
	 * @deprecated 6.43.0 Nothing in the plugin calls it (#2089).
	 * @removal    6.45.0
	 */
	public static function update_field_group( int $field_id, string $group ): bool {
		_deprecated_function( __METHOD__, '6.43.0' );

		return self::update( $field_id, array( 'field_group' => $group ) );
	}

	/**
	 * Save custom field data for a user.
	 *
	 * Merges with existing data (does not overwrite unrelated fields).
	 *
	 * @param int                  $user_id User ID.
	 * @param array<string, mixed> $data    Associative array of field_{id} => value.
	 * @return bool
	 */
	public static function save_user_data( int $user_id, array $data ): bool {
		$existing = CustomFieldReader::get_user_data( $user_id );
		$merged   = array_merge( $existing, $data );

		return (bool) update_user_meta( $user_id, CustomFieldReader::USER_META_KEY, $merged );
	}

	/**
	 * Set a single field value for a user.
	 *
	 * @param int   $user_id  User ID.
	 * @param int   $field_id Field ID.
	 * @param mixed $value    Field value.
	 * @return bool
	 */
	public static function set_user_field_value( int $user_id, int $field_id, $value ): bool {
		return self::save_user_data( $user_id, array( 'field_' . $field_id => $value ) );
	}

	/**
	 * INSERT a standard / dynamic field row. Wraps `$wpdb->insert()` so
	 * the seeder doesn't have to spell out the column→format mapping.
	 * Returns the new row id, or `false` on failure.
	 *
	 * Issue #340 centralization.
	 *
	 * @since 6.6.2
	 * @param array<string, mixed> $data Column => value map.
	 * @return int|false
	 */
	public static function insert_row( array $data ) {
		global $wpdb;
		$table = self::get_table_name();

		$formats = array();
		foreach ( array_keys( $data ) as $column ) {
			$formats[] = self::COLUMN_FORMATS[ $column ] ?? '%s';
		}

		$result = $wpdb->insert( $table, $data, $formats );
		return false === $result ? false : (int) $wpdb->insert_id;
	}

	/**
	 * The `$wpdb` format of each column a row is written with.
	 *
	 * @var array<string, string>
	 */
	private const COLUMN_FORMATS = array(
		'audience_id'        => '%d',
		'overrides_field_id' => '%d',
		'field_key'          => '%s',
		'field_label'        => '%s',
		'field_type'         => '%s',
		'field_group'        => '%s',
		'field_source'       => '%s',
		'field_profile_key'  => '%s',
		'field_mask'         => '%s',
		'is_sensitive'       => '%d',
		'field_options'      => '%s',
		'validation_rules'   => '%s',
		'sort_order'         => '%d',
		'is_required'        => '%d',
		'is_active'          => '%d',
		'is_hidden'          => '%d',
	);

	/**
	 * Start changing an inherited field in one audience (#2502): a row that
	 * points at the field and carries its current values, which the
	 * audience then edits. The other audiences keep inheriting.
	 *
	 * @param int    $audience_id Audience the change belongs to.
	 * @param object $field       The field as the audience inherits it, from {@see CustomFieldReader::resolve()}.
	 * @param bool   $hidden      Hide the field in the audience instead of changing it.
	 * @phpstan-param CustomFieldRow $field
	 * @return int|false The change row's id, or false.
	 */
	public static function override( int $audience_id, object $field, bool $hidden = false ) {
		if ( $audience_id <= 0 || (int) $field->id <= 0 ) {
			return false;
		}
		$existing = self::override_row( $audience_id, (int) $field->id );
		if ( null !== $existing ) {
			if ( $hidden && empty( $existing->is_hidden ) ) {
				self::update_override( (int) $existing->id, array( 'is_hidden' => 1 ) );
			}
			return (int) $existing->id;
		}

		$row = array(
			'audience_id'        => $audience_id,
			'overrides_field_id' => (int) $field->id,
			'field_key'          => (string) $field->field_key,
			'field_type'         => (string) $field->field_type,
			'field_source'       => (string) $field->field_source,
			'field_profile_key'  => $field->field_profile_key,
			'field_mask'         => $field->field_mask,
			'is_sensitive'       => (int) $field->is_sensitive,
			'is_hidden'          => $hidden ? 1 : 0,
		);
		foreach ( CustomFieldReader::OVERRIDABLE_COLUMNS as $column ) {
			$row[ $column ] = $field->{$column};
		}
		return self::insert_row( $row );
	}

	/**
	 * Show again a field an audience hid (#2502). A change row that only
	 * hid the field -- its values equal what the audience would inherit --
	 * is deleted, so the field inherits again; one that also changed the
	 * field keeps the change.
	 *
	 * @param int $override_id The change row's id.
	 * @return bool
	 */
	public static function unhide( int $override_id ): bool {
		$row = CustomFieldReader::get_by_id( $override_id );
		if ( null === $row || (int) ( $row->overrides_field_id ?? 0 ) <= 0 ) {
			return false;
		}
		$inherited = self::inherited_above( $row );
		if ( null !== $inherited && self::same_values( $row, $inherited ) ) {
			return self::revert( $override_id );
		}
		return self::update_override( $override_id, array( 'is_hidden' => 0 ) );
	}

	/**
	 * Drop an audience's change to a field, so it inherits again (#2502).
	 * Only a change row is deleted this way; a field's definition never is.
	 *
	 * @param int $override_id The change row's id.
	 * @return bool
	 */
	public static function revert( int $override_id ): bool {
		$row = CustomFieldReader::get_by_id( $override_id );
		if ( null === $row || (int) ( $row->overrides_field_id ?? 0 ) <= 0 ) {
			return false;
		}
		$wpdb   = self::db();
		$result = $wpdb->delete( self::get_table_name(), array( 'id' => $override_id ), array( '%d' ) );
		static::cache_delete( "id_{$override_id}" );
		return false !== $result;
	}

	/**
	 * Write the columns a change may carry; anything else in `$data` is
	 * ignored, because the key, type and sensitivity follow the field the
	 * change points at.
	 *
	 * @param int                  $override_id The change row's id.
	 * @param array<string, mixed> $data        Column => value.
	 * @return bool
	 */
	public static function update_override( int $override_id, array $data ): bool {
		$allowed = array_merge( CustomFieldReader::OVERRIDABLE_COLUMNS, array( 'is_hidden' ) );
		$data    = array_intersect_key( $data, array_flip( $allowed ) );
		if ( empty( $data ) ) {
			return false;
		}
		// A change is always active: an audience that stops asking a field
		// hides it, which is what the readers of active rows can see.
		if ( array_key_exists( 'is_active', $data ) ) {
			if ( empty( $data['is_active'] ) ) {
				$data['is_hidden'] = 1;
			}
			$data['is_active'] = 1;
		}
		foreach ( array( 'field_options', 'validation_rules' ) as $json ) {
			if ( array_key_exists( $json, $data ) && null !== $data[ $json ] && ! is_string( $data[ $json ] ) ) {
				$data[ $json ] = wp_json_encode( $data[ $json ] );
			}
		}
		if ( isset( $data['field_label'] ) ) {
			$data['field_label'] = sanitize_text_field( (string) $data['field_label'] );
		}
		if ( isset( $data['field_group'] ) ) {
			$data['field_group'] = sanitize_text_field( (string) $data['field_group'] );
		}

		$formats = array();
		foreach ( array_keys( $data ) as $column ) {
			$formats[] = self::COLUMN_FORMATS[ $column ];
		}
		$wpdb   = self::db();
		$result = $wpdb->update( self::get_table_name(), $data, array( 'id' => $override_id ), $formats, array( '%d' ) );
		static::cache_delete( "id_{$override_id}" );
		return false !== $result;
	}

	/**
	 * An audience's change row for a field, if it has one.
	 *
	 * @param int $audience_id Audience ID.
	 * @param int $field_id    The field's id.
	 * @return CustomFieldRow|null
	 */
	private static function override_row( int $audience_id, int $field_id ): ?object {
		$wpdb = self::db();
		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var CustomFieldRow|null $row
		 */
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE audience_id = %d AND overrides_field_id = %d LIMIT 1',
				self::get_table_name(),
				$audience_id,
				$field_id
			)
		);
		return $row ? $row : null;
	}

	/**
	 * The field as the change row's audience would inherit it without the
	 * change: resolved at the parent, or the global list.
	 *
	 * @param object $row Change row.
	 * @phpstan-param CustomFieldRow $row
	 * @return CustomFieldRow|null
	 */
	private static function inherited_above( object $row ): ?object {
		$audience = \FreeFormCertificate\Audience\AudienceReader::get_by_id( (int) $row->audience_id );
		$parent   = $audience && ! empty( $audience->parent_id ) ? (int) $audience->parent_id : 0;
		foreach ( CustomFieldReader::resolve( $parent ) as $field ) {
			if ( (int) $field->id === (int) $row->overrides_field_id ) {
				return $field;
			}
		}
		return null;
	}

	/**
	 * Whether a change row carries the same values as the field it changes.
	 *
	 * @param object $row       Change row.
	 * @param object $inherited The field as inherited.
	 * @return bool
	 */
	private static function same_values( object $row, object $inherited ): bool {
		foreach ( CustomFieldReader::OVERRIDABLE_COLUMNS as $column ) {
			if ( (string) ( $row->{$column} ?? '' ) !== (string) ( $inherited->{$column} ?? '' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Generate a field key from a label.
	 *
	 * @param string $label Field label.
	 * @return string
	 */
	private static function generate_field_key( string $label ): string {
		$key       = sanitize_title( $label );
		$key       = str_replace( '-', '_', $key );
		$key       = preg_replace( '/[^a-z0-9_]/', '', $key ) ?? '';
		$truncated = substr( $key, 0, 100 );
		return '' !== $truncated ? $truncated : 'field';
	}

	/**
	 * Ensure a field key is unique within an audience.
	 *
	 * @param string $key         Desired key.
	 * @param int    $audience_id Audience ID.
	 * @param int    $exclude_id  Field ID to exclude from check (for updates).
	 * @return string Unique key.
	 */
	private static function ensure_unique_key( string $key, int $audience_id, int $exclude_id = 0 ): string {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$original_key = $key;
		$counter      = 1;

		while ( true ) {
			$where  = 'WHERE audience_id = %d AND field_key = %s';
			$values = array( $audience_id, $key );

			if ( $exclude_id > 0 ) {
				$where   .= ' AND id != %d';
				$values[] = $exclude_id;
			}

			$exists = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM %i {$where}", array_merge( array( $table ), $values ) )
			);

			if ( 0 === $exists ) {
				break;
			}

			$key = $original_key . '_' . $counter;
			++$counter;
		}

		return $key;
	}

	/**
	 * Invalidate SensitiveFieldRegistry's dynamic cache.
	 *
	 * Called whenever a row in wp_ffc_custom_fields is created, changed or
	 * deleted in a way that may alter the set of is_sensitive=1 keys, so
	 * ActivityLog's payload inspection sees the latest state on next call.
	 *
	 * @return void
	 */
	private static function invalidate_sensitive_registry_cache(): void {
		if ( class_exists( \FreeFormCertificate\Core\SensitiveFieldRegistry::class ) ) {
			\FreeFormCertificate\Core\SensitiveFieldRegistry::invalidate_dynamic_cache();
		}
	}
}

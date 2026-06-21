<?php
/**
 * Custom Field Writer
 *
 * Write-side of the custom-field repository split (#563 backlog, A6). Holds every
 * INSERT / UPDATE / DELETE and the write-only helpers (key generation, cache
 * invalidation). Reads live in {@see CustomFieldReader}; {@see CustomFieldRepository}
 * remains the public façade that delegates to both.
 *
 * @since   6.11.3
 * @package FreeFormCertificate\Reregistration
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Write operations for audience-specific custom field definitions.
 *
 * @since 6.11.3
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

		// Validate field type.
		if ( ! in_array( $data['field_type'], CustomFieldRepository::FIELD_TYPES, true ) ) {
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
	 * Update a custom field.
	 *
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
				$value = null !== $value ? sanitize_key( (string) $value ) : null;
			} elseif ( in_array( $key, array( 'field_label', 'field_type', 'field_group', 'field_mask', 'field_source' ), true ) ) {
				$value = null !== $value ? sanitize_text_field( (string) $value ) : null;
			}

			// Validate field type.
			if ( 'field_type' === $key && ! in_array( $value, CustomFieldRepository::FIELD_TYPES, true ) ) {
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

		$wpdb  = self::db();
		$table = self::get_table_name();

		$result = $wpdb->delete( $table, array( 'id' => $field_id ), array( '%d' ) );

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
	 */
	public static function update_field_group( int $field_id, string $group ): bool {
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

		return (bool) update_user_meta( $user_id, CustomFieldRepository::USER_META_KEY, $merged );
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single-row insert; format hints below kept in sync with the schema.
		$result = $wpdb->insert(
			$table,
			$data,
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d' )
		);
		return false === $result ? false : (int) $wpdb->insert_id;
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

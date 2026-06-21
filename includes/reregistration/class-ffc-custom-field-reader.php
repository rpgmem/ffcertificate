<?php
/**
 * Custom Field Reader
 *
 * Read-side of the custom-field repository split (#563 backlog, A6). Holds every
 * SELECT / lookup / derived-read query and the pure field-introspection helpers.
 * Writes live in {@see CustomFieldWriter}; {@see CustomFieldRepository} remains
 * the public façade that delegates to both.
 *
 * @since   6.11.3
 * @package FreeFormCertificate\Reregistration
 *
 * @phpstan-import-type CustomFieldRow from CustomFieldRepository
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

use FreeFormCertificate\Audience\AudienceRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Read queries for audience-specific custom field definitions.
 *
 * @since 6.11.3
 *
 * @phpstan-import-type CustomFieldRow from CustomFieldRepository
 */
class CustomFieldReader {
	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Cache group for custom field queries.
	 *
	 * Must match {@see CustomFieldWriter::cache_group()} so writes invalidate
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
	 * Get a single field by ID.
	 *
	 * @param int $field_id Field ID.
	 * @return CustomFieldRow|null
	 */
	public static function get_by_id( int $field_id ): ?object {
		$cached = static::cache_get( "id_{$field_id}" );
		if ( false !== $cached ) {
			return $cached;
		}

		$wpdb  = self::db();
		$table = self::get_table_name();

		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var CustomFieldRow|null $result
		 */
		$result = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $field_id )
		);

		if ( $result ) {
			static::cache_set( "id_{$field_id}", $result );
		}

		return $result;
	}

	/**
	 * Get fields for a specific audience.
	 *
	 * @param int  $audience_id Audience ID.
	 * @param bool $active_only Only return active fields.
	 * @return list<CustomFieldRow>
	 */
	public static function get_by_audience( int $audience_id, bool $active_only = true ): array {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$where  = 'WHERE audience_id = %d';
		$values = array( $audience_id );

		if ( $active_only ) {
			$where .= ' AND is_active = 1';
		}

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i {$where} ORDER BY sort_order ASC, id ASC",
				array_merge( array( $table ), $values )
			)
		);
		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var list<CustomFieldRow>
		 */
		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get fields for an audience including inherited fields from parent audiences.
	 *
	 * Walks up the hierarchy and collects all fields, ordered by hierarchy level
	 * (parent fields first, then child fields), each group sorted by sort_order.
	 *
	 * @param int  $audience_id Audience ID.
	 * @param bool $active_only Only return active fields.
	 * @return list<CustomFieldRow> Fields with added 'source_audience_id' and 'source_audience_name' properties.
	 */
	public static function get_by_audience_with_parents( int $audience_id, bool $active_only = true ): array {
		$audience = AudienceRepository::get_by_id( $audience_id );
		if ( ! $audience ) {
			return array();
		}

		// Collect audience IDs from bottom to top (child → parent).
		$audience_chain = array();
		$current        = $audience;
		while ( $current ) {
			$audience_chain[] = $current;
			if ( ! empty( $current->parent_id ) ) {
				$current = AudienceRepository::get_by_id( (int) $current->parent_id );
			} else {
				$current = null;
			}
		}

		// Reverse to get top-down order (parent → child).
		$audience_chain = array_reverse( $audience_chain );

		$all_fields = array();
		foreach ( $audience_chain as $aud ) {
			$fields = self::get_by_audience( (int) $aud->id, $active_only );
			foreach ( $fields as $field ) {
				$field->source_audience_id   = (int) $aud->id;
				$field->source_audience_name = $aud->name;
				$all_fields[]                = $field;
			}
		}

		return $all_fields;
	}

	/**
	 * Get all fields for a user based on their audience memberships.
	 *
	 * @param int  $user_id    User ID.
	 * @param bool $active_only Only return active fields.
	 * @return list<CustomFieldRow> Fields grouped conceptually, with source_audience_* properties.
	 */
	public static function get_all_for_user( int $user_id, bool $active_only = true ): array {
		$audiences = AudienceRepository::get_user_audiences( $user_id );
		if ( empty( $audiences ) ) {
			return array();
		}

		$all_fields = array();
		$seen_ids   = array();

		foreach ( $audiences as $audience ) {
			$fields = self::get_by_audience_with_parents( (int) $audience->id, $active_only );
			foreach ( $fields as $field ) {
				// Avoid duplicates when user belongs to sibling audiences sharing a parent.
				if ( ! isset( $seen_ids[ (int) $field->id ] ) ) {
					$seen_ids[ (int) $field->id ] = true;
					$all_fields[]                 = $field;
				}
			}
		}

		return $all_fields;
	}

	/**
	 * Get field count for an audience.
	 *
	 * @param int  $audience_id Audience ID.
	 * @param bool $active_only Only count active fields.
	 * @return int
	 */
	public static function count_by_audience( int $audience_id, bool $active_only = true ): int {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$where  = 'WHERE audience_id = %d';
		$values = array( $audience_id );

		if ( $active_only ) {
			$where .= ' AND is_active = 1';
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM %i {$where}", array_merge( array( $table ), $values ) )
		);
	}

	/**
	 * Get fields for an audience grouped by field_group.
	 *
	 * Preserves sort_order within groups. Groups appear in the order they
	 * are first encountered in the sort sequence, so that drag-and-drop
	 * ordering in the admin UI determines both field and group order.
	 *
	 * @param int  $audience_id Audience ID.
	 * @param bool $active_only Only return active fields.
	 * @return array<string, array<object>> Map of group_key => fields[].
	 */
	public static function get_by_audience_grouped( int $audience_id, bool $active_only = true ): array {
		$fields  = self::get_by_audience( $audience_id, $active_only );
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
	 * Get the ordered list of groups for an audience.
	 *
	 * Groups are ordered by the minimum sort_order of their fields.
	 *
	 * @param int  $audience_id Audience ID.
	 * @param bool $active_only Only consider active fields.
	 * @return array<string> Ordered group keys.
	 */
	public static function get_groups_for_audience( int $audience_id, bool $active_only = true ): array {
		$grouped = self::get_by_audience_grouped( $audience_id, $active_only );
		return array_keys( $grouped );
	}

	/**
	 * Get fields that map to a user profile key (field_profile_key IS NOT NULL).
	 *
	 * Used by the data processor to sync reregistration data back to the
	 * WordPress user profile upon approval.
	 *
	 * @param int  $audience_id Audience ID.
	 * @param bool $active_only Only active fields.
	 * @return list<CustomFieldRow>
	 */
	public static function get_profile_fields( int $audience_id, bool $active_only = true ): array {
		$fields = self::get_by_audience( $audience_id, $active_only );
		return array_values(
			array_filter(
				$fields,
				static function ( $field ) {
					return ! empty( $field->field_profile_key );
				}
			)
		);
	}

	/**
	 * Get sensitive (is_sensitive=1) fields for an audience.
	 *
	 * Used by the data processor to know which values must be encrypted
	 * before persistence and decrypted on read.
	 *
	 * @param int  $audience_id Audience ID.
	 * @param bool $active_only Only active fields.
	 * @return list<CustomFieldRow>
	 */
	public static function get_sensitive_fields( int $audience_id, bool $active_only = true ): array {
		$fields = self::get_by_audience( $audience_id, $active_only );
		return array_values(
			array_filter(
				$fields,
				static function ( $field ) {
					return ! empty( $field->is_sensitive );
				}
			)
		);
	}

	/**
	 * Get the set of sensitive field_keys for an audience.
	 *
	 * Convenient lookup form used in hot paths (validation, encryption).
	 *
	 * @param int  $audience_id Audience ID.
	 * @param bool $active_only Only active fields.
	 * @return array<string> field_keys of sensitive fields.
	 */
	public static function get_sensitive_keys_for_audience( int $audience_id, bool $active_only = true ): array {
		$fields = self::get_sensitive_fields( $audience_id, $active_only );
		return array_map(
			static function ( $field ) {
				return (string) $field->field_key;
			},
			$fields
		);
	}

	/**
	 * Get fields for an audience indexed by field_key.
	 *
	 * Used by the data processor and renderer to perform O(1) field lookups.
	 *
	 * @param int  $audience_id Audience ID.
	 * @param bool $active_only Only active fields.
	 * @return array<string, object>
	 */
	public static function get_by_audience_keyed( int $audience_id, bool $active_only = true ): array {
		$fields = self::get_by_audience( $audience_id, $active_only );
		$keyed  = array();
		foreach ( $fields as $field ) {
			$keyed[ (string) $field->field_key ] = $field;
		}
		return $keyed;
	}

	/**
	 * Get a single field by (audience_id, field_key).
	 *
	 * @param int    $audience_id Audience ID.
	 * @param string $field_key   Field key.
	 * @return CustomFieldRow|null
	 */
	public static function get_by_key( int $audience_id, string $field_key ): ?object {
		$wpdb  = self::db();
		$table = self::get_table_name();

		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var CustomFieldRow|null $result
		 */
		$result = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE audience_id = %d AND field_key = %s LIMIT 1',
				$table,
				$audience_id,
				$field_key
			)
		);

		return $result ? $result : null;
	}

	/**
	 * Get custom field data for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array<string, mixed> Associative array of field_id => value.
	 */
	public static function get_user_data( int $user_id ): array {
		$data = get_user_meta( $user_id, CustomFieldRepository::USER_META_KEY, true );
		if ( empty( $data ) || ! is_array( $data ) ) {
			return array();
		}
		return $data;
	}

	/**
	 * Get a single field value for a user.
	 *
	 * @param int $user_id  User ID.
	 * @param int $field_id Field ID.
	 * @return mixed|null Field value or null if not set.
	 */
	public static function get_user_field_value( int $user_id, int $field_id ) {
		$data = self::get_user_data( $user_id );
		$key  = 'field_' . $field_id;
		return $data[ $key ] ?? null;
	}

	/**
	 * Validate a field value against its definition.
	 *
	 * @param object $field Field definition object.
	 * @param mixed  $value Value to validate.
	 * @phpstan-param CustomFieldRow $field
	 * @return true|\WP_Error True if valid, WP_Error with message if invalid.
	 */
	public static function validate_field_value( object $field, $value ) {
		return CustomFieldValidator::validate( $field, $value );
	}

	/**
	 * Get grouped choices for a dependent_select field.
	 *
	 * Expected field_options format:
	 *   {"groups": {"Parent Label": ["Child 1", "Child 2"], ...},
	 *    "parent_label": "Divisão", "child_label": "Setor"}
	 *
	 * @param object $field Field definition.
	 * @phpstan-param CustomFieldRow $field
	 * @return array<string, array<string>> Parent => [children].
	 */
	public static function get_dependent_choices( object $field ): array {
		$options = $field->field_options;
		if ( is_string( $options ) ) {
			$options = json_decode( $options, true );
		}
		return is_array( $options ) && isset( $options['groups'] ) && is_array( $options['groups'] ) ? $options['groups'] : array();
	}

	/**
	 * Get choices for a select field.
	 *
	 * @param object $field Field definition.
	 * @phpstan-param CustomFieldRow $field
	 * @return array<string>
	 */
	public static function get_field_choices( object $field ): array {
		$options = $field->field_options;
		if ( is_string( $options ) ) {
			$options = json_decode( $options, true );
		}
		return is_array( $options ) && isset( $options['choices'] ) && is_array( $options['choices'] ) ? $options['choices'] : array();
	}

	/**
	 * Get validation rules for a field.
	 *
	 * @param object $field Field definition.
	 * @phpstan-param CustomFieldRow $field
	 * @return array<string, mixed>
	 */
	public static function get_validation_rules( object $field ): array {
		$rules = $field->validation_rules;
		if ( is_string( $rules ) ) {
			$rules = json_decode( $rules, true );
		}
		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * List every `field_key` that currently has `is_sensitive = 1`
	 * AND `is_active = 1`. Used by
	 * {@see \FreeFormCertificate\Core\SensitiveFieldRegistry::dynamic_sensitive_keys()}
	 * to grow its sensitivity allow-list with admin-flagged fields.
	 *
	 * Centralizes a query that lived inline in the registry (issue #340).
	 * Guards the table-existence via SHOW TABLES so a fresh install or
	 * a test environment without the table degrades to an empty list
	 * instead of throwing.
	 *
	 * @since 6.6.2
	 * @return list<string> Field keys (unique, alphabetical not enforced).
	 */
	public static function list_sensitive_field_keys(): array {
		global $wpdb;
		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table-existence guard.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return array();
		}

		// Column-existence guard: installs that pre-date the dynamic-fields
		// migration (#249) carry the table without the `is_sensitive` column.
		// SettingsTab callers can trigger this read during activity-log
		// writes long before that migration runs, so detect the older shape
		// and degrade to an empty list rather than emit a "Unknown column"
		// query into debug.log on every settings save.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'is_sensitive' ) );
		if ( empty( $columns ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Catalog read; caching handled at the caller (SensitiveFieldRegistry).
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT field_key FROM %i WHERE is_sensitive = 1 AND is_active = 1',
				$table
			)
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $field_key ) {
			if ( is_string( $field_key ) && '' !== $field_key ) {
				$out[] = $field_key;
			}
		}
		return $out;
	}

	/**
	 * Return the set of `field_key` values already present for an
	 * audience — used by the standard-fields seeder to skip rows that
	 * were inserted in a previous run (the seeder is idempotent).
	 *
	 * Issue #340 centralization.
	 *
	 * @since 6.6.2
	 * @param int $audience_id Audience ID.
	 * @return array<string, bool> Map of `field_key => true` for O(1) lookup.
	 */
	public static function existing_field_keys_for_audience( int $audience_id ): array {
		if ( $audience_id <= 0 ) {
			return array();
		}
		global $wpdb;
		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Indexed scan by audience_id.
		$rows = $wpdb->get_col(
			$wpdb->prepare( 'SELECT field_key FROM %i WHERE audience_id = %d', $table, $audience_id )
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$keys = array();
		foreach ( $rows as $field_key ) {
			$key = (string) $field_key;
			if ( '' !== $key ) {
				$keys[ $key ] = true;
			}
		}
		return $keys;
	}
}

<?php
/**
 * Custom Field Repository
 *
 * Handles database operations for audience-specific custom field definitions.
 * Field data for users is stored in wp_usermeta as JSON (key: ffc_custom_fields_data).
 *
 * Since the #563 backlog read/write split (A6) this class is a thin façade:
 * reads live in {@see CustomFieldReader}, writes in {@see CustomFieldWriter}.
 * It is kept as the public entry point so the ~38 existing call sites and the
 * public constants below need no change.
 *
 * Tech-debt (#563 B3): migrate call sites to depend on CustomFieldReader /
 * CustomFieldWriter directly (read vs write at the call site), then retire this
 * delegating façade. Tracked under the modular-monolith roadmap.
 *
 * @since   4.11.0
 * @package FreeFormCertificate\Reregistration
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public façade over {@see CustomFieldReader} + {@see CustomFieldWriter}.
 *
 * @since 4.11.0
 *
 * @phpstan-type CustomFieldRow \stdClass&object{id: string, audience_id: string, field_key: string, field_label: string, field_type: string, field_group: string, field_source: string, field_profile_key: string|null, field_mask: string|null, is_sensitive: string, field_options: string|null, validation_rules: string|null, sort_order: string, is_required: string, is_active: string, created_at: string, updated_at: string, source_audience_id?: string, source_audience_name?: string}
 */
class CustomFieldRepository {

	/**
	 * Supported field types.
	 */
	public const FIELD_TYPES = array(
		'text',
		'number',
		'date',
		'select',
		'dependent_select',
		'checkbox',
		'textarea',
		'working_hours',
		'acknowledgment',
	);

	/**
	 * Display-only field types — they render static content (no user input),
	 * so they're skipped during value collection, validation and persistence.
	 * Their content lives in `field_options` (e.g. `acknowledgment` → `html`).
	 */
	public const DISPLAY_ONLY_TYPES = array(
		'acknowledgment',
	);

	/**
	 * Built-in validation formats.
	 */
	public const VALIDATION_FORMATS = array(
		'cpf',
		'email',
		'phone',
		'custom_regex',
	);

	/**
	 * User meta key for storing custom field data.
	 */
	public const USER_META_KEY = 'ffc_custom_fields_data';

	/**
	 * Get table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		return CustomFieldReader::get_table_name();
	}

	// ─────────────────────────────────────────────.
	// Reads — delegate to CustomFieldReader.
	// ─────────────────────────────────────────────.

	/**
	 * Get a single field by ID.
	 *
	 * @param int $field_id Field ID.
	 * @return CustomFieldRow|null
	 */
	public static function get_by_id( int $field_id ): ?object {
		return CustomFieldReader::get_by_id( $field_id );
	}

	/**
	 * Get fields for a specific audience.
	 *
	 * @param int  $audience_id Audience ID.
	 * @param bool $active_only Only return active fields.
	 * @return list<CustomFieldRow>
	 */
	public static function get_by_audience( int $audience_id, bool $active_only = true ): array {
		return CustomFieldReader::get_by_audience( $audience_id, $active_only );
	}

	/**
	 * Get fields for an audience including inherited fields from parent audiences.
	 *
	 * @param int  $audience_id Audience ID.
	 * @param bool $active_only Only return active fields.
	 * @return list<CustomFieldRow>
	 */
	public static function get_by_audience_with_parents( int $audience_id, bool $active_only = true ): array {
		return CustomFieldReader::get_by_audience_with_parents( $audience_id, $active_only );
	}

	/**
	 * Get all fields for a user based on their audience memberships.
	 *
	 * @param int  $user_id     User ID.
	 * @param bool $active_only Only return active fields.
	 * @return list<CustomFieldRow>
	 */
	public static function get_all_for_user( int $user_id, bool $active_only = true ): array {
		return CustomFieldReader::get_all_for_user( $user_id, $active_only );
	}

	/**
	 * Get field count for an audience.
	 *
	 * @param int  $audience_id Audience ID.
	 * @param bool $active_only Only count active fields.
	 * @return int
	 */
	public static function count_by_audience( int $audience_id, bool $active_only = true ): int {
		return CustomFieldReader::count_by_audience( $audience_id, $active_only );
	}

	/**
	 * Get fields for an audience grouped by field_group.
	 *
	 * @param int  $audience_id Audience ID.
	 * @param bool $active_only Only return active fields.
	 * @return array<string, array<object>>
	 */
	public static function get_by_audience_grouped( int $audience_id, bool $active_only = true ): array {
		return CustomFieldReader::get_by_audience_grouped( $audience_id, $active_only );
	}

	/**
	 * Get the ordered list of groups for an audience.
	 *
	 * @param int  $audience_id Audience ID.
	 * @param bool $active_only Only consider active fields.
	 * @return array<string>
	 */
	public static function get_groups_for_audience( int $audience_id, bool $active_only = true ): array {
		return CustomFieldReader::get_groups_for_audience( $audience_id, $active_only );
	}

	/**
	 * Get fields that map to a user profile key.
	 *
	 * @param int  $audience_id Audience ID.
	 * @param bool $active_only Only active fields.
	 * @return list<CustomFieldRow>
	 */
	public static function get_profile_fields( int $audience_id, bool $active_only = true ): array {
		return CustomFieldReader::get_profile_fields( $audience_id, $active_only );
	}

	/**
	 * Get sensitive (is_sensitive=1) fields for an audience.
	 *
	 * @param int  $audience_id Audience ID.
	 * @param bool $active_only Only active fields.
	 * @return list<CustomFieldRow>
	 */
	public static function get_sensitive_fields( int $audience_id, bool $active_only = true ): array {
		return CustomFieldReader::get_sensitive_fields( $audience_id, $active_only );
	}

	/**
	 * Get the set of sensitive field_keys for an audience.
	 *
	 * @param int  $audience_id Audience ID.
	 * @param bool $active_only Only active fields.
	 * @return array<string>
	 */
	public static function get_sensitive_keys_for_audience( int $audience_id, bool $active_only = true ): array {
		return CustomFieldReader::get_sensitive_keys_for_audience( $audience_id, $active_only );
	}

	/**
	 * Get fields for an audience indexed by field_key.
	 *
	 * @param int  $audience_id Audience ID.
	 * @param bool $active_only Only active fields.
	 * @return array<string, object>
	 */
	public static function get_by_audience_keyed( int $audience_id, bool $active_only = true ): array {
		return CustomFieldReader::get_by_audience_keyed( $audience_id, $active_only );
	}

	/**
	 * Get a single field by (audience_id, field_key).
	 *
	 * @param int    $audience_id Audience ID.
	 * @param string $field_key   Field key.
	 * @return CustomFieldRow|null
	 */
	public static function get_by_key( int $audience_id, string $field_key ): ?object {
		return CustomFieldReader::get_by_key( $audience_id, $field_key );
	}

	/**
	 * Get custom field data for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array<string, mixed>
	 */
	public static function get_user_data( int $user_id ): array {
		return CustomFieldReader::get_user_data( $user_id );
	}

	/**
	 * Get a single field value for a user.
	 *
	 * @param int $user_id  User ID.
	 * @param int $field_id Field ID.
	 * @return mixed|null
	 */
	public static function get_user_field_value( int $user_id, int $field_id ) {
		return CustomFieldReader::get_user_field_value( $user_id, $field_id );
	}

	/**
	 * Validate a field value against its definition.
	 *
	 * @param object $field Field definition object.
	 * @param mixed  $value Value to validate.
	 * @phpstan-param CustomFieldRow $field
	 * @return true|\WP_Error
	 */
	public static function validate_field_value( object $field, $value ) {
		return CustomFieldReader::validate_field_value( $field, $value );
	}

	/**
	 * Get grouped choices for a dependent_select field.
	 *
	 * @param object $field Field definition.
	 * @phpstan-param CustomFieldRow $field
	 * @return array<string, array<string>>
	 */
	public static function get_dependent_choices( object $field ): array {
		return CustomFieldReader::get_dependent_choices( $field );
	}

	/**
	 * Get choices for a select field.
	 *
	 * @param object $field Field definition.
	 * @phpstan-param CustomFieldRow $field
	 * @return array<string>
	 */
	public static function get_field_choices( object $field ): array {
		return CustomFieldReader::get_field_choices( $field );
	}

	/**
	 * Get validation rules for a field.
	 *
	 * @param object $field Field definition.
	 * @phpstan-param CustomFieldRow $field
	 * @return array<string, mixed>
	 */
	public static function get_validation_rules( object $field ): array {
		return CustomFieldReader::get_validation_rules( $field );
	}

	/**
	 * List every `field_key` with `is_sensitive = 1` AND `is_active = 1`.
	 *
	 * @since 6.6.2
	 * @return list<string>
	 */
	public static function list_sensitive_field_keys(): array {
		return CustomFieldReader::list_sensitive_field_keys();
	}

	/**
	 * Return the set of `field_key` values already present for an audience.
	 *
	 * @since 6.6.2
	 * @param int $audience_id Audience ID.
	 * @return array<string, bool>
	 */
	public static function existing_field_keys_for_audience( int $audience_id ): array {
		return CustomFieldReader::existing_field_keys_for_audience( $audience_id );
	}

	// ─────────────────────────────────────────────.
	// Writes — delegate to CustomFieldWriter.
	// ─────────────────────────────────────────────.

	/**
	 * Create a custom field.
	 *
	 * @param array<string, mixed> $data Field data.
	 * @return int|false Field ID or false on failure.
	 */
	public static function create( array $data ) {
		return CustomFieldWriter::create( $data );
	}

	/**
	 * Update a custom field.
	 *
	 * @param int                  $field_id Field ID.
	 * @param array<string, mixed> $data     Update data.
	 * @return bool
	 */
	public static function update( int $field_id, array $data ): bool {
		return CustomFieldWriter::update( $field_id, $data );
	}

	/**
	 * Delete a custom field definition.
	 *
	 * @param int $field_id Field ID.
	 * @return bool
	 */
	public static function delete( int $field_id ): bool {
		return CustomFieldWriter::delete( $field_id );
	}

	/**
	 * Deactivate a field (hide but preserve data).
	 *
	 * @param int $field_id Field ID.
	 * @return bool
	 */
	public static function deactivate( int $field_id ): bool {
		return CustomFieldWriter::deactivate( $field_id );
	}

	/**
	 * Reactivate a previously deactivated field.
	 *
	 * @param int $field_id Field ID.
	 * @return bool
	 */
	public static function reactivate( int $field_id ): bool {
		return CustomFieldWriter::reactivate( $field_id );
	}

	/**
	 * Reorder fields by updating sort_order in batch.
	 *
	 * @param array<int> $field_ids Ordered array of field IDs.
	 * @return bool
	 */
	public static function reorder( array $field_ids ): bool {
		return CustomFieldWriter::reorder( $field_ids );
	}

	/**
	 * Update only the field_group of a field.
	 *
	 * @param int    $field_id Field ID.
	 * @param string $group    New group key.
	 * @return bool
	 */
	public static function update_field_group( int $field_id, string $group ): bool {
		return CustomFieldWriter::update_field_group( $field_id, $group );
	}

	/**
	 * Save custom field data for a user.
	 *
	 * @param int                  $user_id User ID.
	 * @param array<string, mixed> $data    Associative array of field_{id} => value.
	 * @return bool
	 */
	public static function save_user_data( int $user_id, array $data ): bool {
		return CustomFieldWriter::save_user_data( $user_id, $data );
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
		return CustomFieldWriter::set_user_field_value( $user_id, $field_id, $value );
	}

	/**
	 * INSERT a standard / dynamic field row.
	 *
	 * @since 6.6.2
	 * @param array<string, mixed> $data Column => value map.
	 * @return int|false
	 */
	public static function insert_row( array $data ) {
		return CustomFieldWriter::insert_row( $data );
	}
}

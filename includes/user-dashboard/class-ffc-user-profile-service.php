<?php
/**
 * UserProfileService
 *
 * Single entry point for reading and writing the *current* profile of an
 * FFC user. Consolidates access across the three canonical storage
 * layers: wp_users, wp_ffc_user_profiles, and wp_usermeta keyed by
 * ffc_user_ prefix. Encryption and hashing for sensitive fields are
 * applied transparently according to UserProfileFieldMap.
 *
 * The service is intentionally stateless and dependency-free on WP
 * capabilities: callers validate capability (e.g. current_user_can) and
 * pick an appropriate ViewPolicy; the service only audits and routes.
 * This keeps the surface testable and leaves authorization policy with
 * the caller (REST controllers, exporters, admin pages) where it
 * belongs.
 *
 * Phase 1 scope: individual read/write. Bulk streaming for CSV / LGPD
 * exports arrives in Phase 3.
 *
 * @package FreeFormCertificate\UserDashboard
 * @since 5.5.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\UserDashboard;

use FreeFormCertificate\Core\ActivityLog;
use FreeFormCertificate\Core\DocumentFormatter;
use FreeFormCertificate\Core\Encryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralized service for user-profile reads and writes.
 */
final class UserProfileService {

	/**
	 * Table name cache so the class plays nice with wpdb mocks.
	 *
	 * @var string|null
	 */
	private static ?string $profile_table = null;

	/**
	 * Per-call overrides for fields that are not registered in the
	 * static UserProfileFieldMap. Populated by write() and consumed by
	 * resolve_spec() / resolve_hash_meta_key() inside the helpers.
	 * Cleared at the end of the write() call via try/finally so it
	 * never leaks between requests.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $runtime_overrides = array();

	/**
	 * Read a subset of a user's profile applying a view policy.
	 *
	 * Returns an associative array keyed by requested field. Unknown keys
	 * are silently dropped. Sensitive fields are decrypted only when the
	 * policy is FULL; MASKED returns the human-safe mask; HASHED_ONLY
	 * returns the stored lookup hash (null when unavailable).
	 *
	 * A FULL read that touches at least one sensitive field emits one
	 * `user_profile_read_full` audit entry carrying the requester, the
	 * target user id, and the list of fields — never the values.
	 *
	 * @param int                $user_id WordPress user ID.
	 * @param array<int, string> $fields  Requested field keys.
	 * @param ViewPolicy         $policy  Visibility for sensitive fields.
	 * @return array<string, mixed>
	 */
	public static function read( int $user_id, array $fields, ViewPolicy $policy = ViewPolicy::MASKED ): array {
		if ( $user_id <= 0 || empty( $fields ) ) {
			return array();
		}

		$fields = array_values( array_unique( array_filter( $fields, 'is_string' ) ) );
		$fields = array_values( array_filter( $fields, array( UserProfileFieldMap::class, 'has' ) ) );
		if ( empty( $fields ) ) {
			return array();
		}

		$result = array();

		foreach ( UserProfileFieldMap::group_by_storage( $fields ) as $storage => $keys ) {
			switch ( $storage ) {
				case UserProfileFieldMap::STORAGE_WP_USER:
					$result += self::read_wp_user( $user_id, $keys );
					break;
				case UserProfileFieldMap::STORAGE_PROFILE_TABLE:
					$result += self::read_profile_table( $user_id, $keys );
					break;
				case UserProfileFieldMap::STORAGE_USERMETA:
					$result += self::read_usermeta( $user_id, $keys, $policy );
					break;
			}
		}

		// Apply MASKED / FULL / HASHED_ONLY to sensitive fields that were
		// read from the profile table or wp_users (the usermeta path
		// already applied policy inline because it also decides whether
		// to decrypt).
		foreach ( $fields as $field_key ) {
			if ( ! array_key_exists( $field_key, $result ) ) {
				$result[ $field_key ] = null;
			}
		}

		if ( ViewPolicy::FULL === $policy && self::touches_sensitive( $fields ) ) {
			self::audit_full_read( $user_id, $fields );
		}

		return $result;
	}

	/**
	 * Apply a partial patch to a user's profile.
	 *
	 * Unknown keys are silently dropped unless $extra_descriptors
	 * supplies an inline spec. Sensitive values are encrypted and their
	 * lookup hash is written when the resolved descriptor is hashable.
	 * Mirror targets (e.g. wp_users.display_name) are synced last.
	 *
	 * $extra_descriptors lets callers write fields that are not part of
	 * the static UserProfileFieldMap — notably dynamic reregistration
	 * custom fields, where is_sensitive and meta_key are only known at
	 * runtime. Each descriptor must follow the same shape as FIELDS in
	 * UserProfileFieldMap (storage, meta_key/column, sensitive, etc.).
	 *
	 * Returns true if at least one field was persisted successfully.
	 *
	 * @param int                                 $user_id          WordPress user ID.
	 * @param array<string, mixed>                $patch            Field key => new value.
	 * @param array<string, array<string, mixed>> $extra_descriptors Inline descriptors for unregistered fields.
	 * @return bool
	 */
	public static function write( int $user_id, array $patch, array $extra_descriptors = array() ): bool {
		if ( $user_id <= 0 || empty( $patch ) ) {
			return false;
		}

		self::$runtime_overrides = $extra_descriptors;

		try {
			$filtered = array();
			foreach ( $patch as $key => $value ) {
				if ( is_string( $key ) && null !== self::resolve_spec( $key ) ) {
					$filtered[ $key ] = $value;
				}
			}
			if ( empty( $filtered ) ) {
				return false;
			}

			// Group by storage using the resolved spec (which may come
			// from the static map or from $extra_descriptors).
			$by_storage = array();
			foreach ( array_keys( $filtered ) as $field_key ) {
				$spec = self::resolve_spec( $field_key );
				if ( null === $spec || empty( $spec['storage'] ) ) {
					continue;
				}
				$by_storage[ $spec['storage'] ][] = $field_key;
			}

			$success = false;

			foreach ( $by_storage as $storage => $keys ) {
				$slice = array();
				foreach ( $keys as $k ) {
					$slice[ $k ] = $filtered[ $k ];
				}
				switch ( $storage ) {
					case UserProfileFieldMap::STORAGE_WP_USER:
						$success = self::write_wp_user( $user_id, $slice ) || $success;
						break;
					case UserProfileFieldMap::STORAGE_PROFILE_TABLE:
						$success = self::write_profile_table( $user_id, $slice ) || $success;
						break;
					case UserProfileFieldMap::STORAGE_USERMETA:
						$success = self::write_usermeta( $user_id, $slice ) || $success;
						break;
				}
			}

			// Mirrors: wp_users.display_name follows profile_table.display_name.
			self::apply_mirrors( $user_id, $filtered );

			return $success;
		} finally {
			self::$runtime_overrides = array();
		}
	}

	/**
	 * Resolve a field descriptor, preferring per-call overrides over the
	 * static UserProfileFieldMap.
	 *
	 * @param string $field_key Logical field key.
	 * @return array<string, mixed>|null
	 */
	private static function resolve_spec( string $field_key ): ?array {
		if ( isset( self::$runtime_overrides[ $field_key ] ) && is_array( self::$runtime_overrides[ $field_key ] ) ) {
			return self::$runtime_overrides[ $field_key ];
		}
		return UserProfileFieldMap::get( $field_key );
	}

	/**
	 * Resolve the hash meta key for a hashable usermeta field under the
	 * current override context. Mirrors UserProfileFieldMap::hash_meta_key
	 * but respects runtime overrides.
	 *
	 * @param string $field_key Logical field key.
	 * @return string|null
	 */
	private static function resolve_hash_meta_key( string $field_key ): ?string {
		$spec = self::resolve_spec( $field_key );
		if ( null === $spec
			|| UserProfileFieldMap::STORAGE_USERMETA !== ( $spec['storage'] ?? null )
			|| empty( $spec['hashable'] )
			|| empty( $spec['meta_key'] )
		) {
			return null;
		}
		return $spec['meta_key'] . '_hash';
	}

	// ==================================================================
	// Read helpers
	// ==================================================================

	/**
	 * Read from wp_users.
	 *
	 * @param int                $user_id WordPress user ID.
	 * @param array<int, string> $fields  Field keys known to map to STORAGE_WP_USER.
	 * @return array<string, mixed>
	 */
	private static function read_wp_user( int $user_id, array $fields ): array {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array();
		}
		$out = array();
		foreach ( $fields as $field ) {
			$spec = UserProfileFieldMap::get( $field );
			if ( null === $spec || empty( $spec['column'] ) ) {
				continue;
			}
			$prop          = $spec['column'];
			$out[ $field ] = isset( $user->$prop ) ? $user->$prop : null;
		}
		return $out;
	}

	/**
	 * Read from wp_ffc_user_profiles.
	 *
	 * @param int                $user_id WordPress user ID.
	 * @param array<int, string> $fields  Field keys known to map to STORAGE_PROFILE_TABLE.
	 * @return array<string, mixed>
	 */
	private static function read_profile_table( int $user_id, array $fields ): array {
		global $wpdb;
		$table = self::profile_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d LIMIT 1', $table, $user_id ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			$out = array();
			foreach ( $fields as $f ) {
				$out[ $f ] = null;
			}
			return $out;
		}

		$out = array();
		foreach ( $fields as $field ) {
			$spec = UserProfileFieldMap::get( $field );
			if ( null === $spec || empty( $spec['column'] ) ) {
				continue;
			}
			$col           = $spec['column'];
			$out[ $field ] = array_key_exists( $col, $row ) ? $row[ $col ] : null;
		}
		return $out;
	}

	/**
	 * Read from wp_usermeta, applying ViewPolicy to sensitive fields.
	 *
	 * @param int                $user_id WordPress user ID.
	 * @param array<int, string> $fields  Field keys known to map to STORAGE_USERMETA.
	 * @param ViewPolicy         $policy  Visibility policy.
	 * @return array<string, mixed>
	 */
	private static function read_usermeta( int $user_id, array $fields, ViewPolicy $policy ): array {
		$out = array();
		foreach ( $fields as $field ) {
			$spec = UserProfileFieldMap::get( $field );
			if ( null === $spec || empty( $spec['meta_key'] ) ) {
				continue;
			}

			$sensitive = ! empty( $spec['sensitive'] );

			if ( $sensitive && ViewPolicy::HASHED_ONLY === $policy ) {
				$hash_key      = UserProfileFieldMap::hash_meta_key( $field );
				$out[ $field ] = null !== $hash_key ? get_user_meta( $user_id, $hash_key, true ) : null;
				continue;
			}

			$raw = get_user_meta( $user_id, $spec['meta_key'], true );
			if ( '' === $raw || null === $raw ) {
				$out[ $field ] = '';
				continue;
			}

			if ( ! $sensitive ) {
				$out[ $field ] = $raw;
				continue;
			}

			$plain = class_exists( Encryption::class ) ? Encryption::decrypt( (string) $raw ) : null;
			$plain = null !== $plain ? $plain : '';

			if ( ViewPolicy::FULL === $policy ) {
				$out[ $field ] = $plain;
				continue;
			}

			$out[ $field ] = self::mask( $plain, $spec['masker'] ?? null );
		}
		return $out;
	}

	// ==================================================================
	// Write helpers
	// ==================================================================

	/**
	 * Write to wp_users via wp_update_user.
	 *
	 * @param int                  $user_id WordPress user ID.
	 * @param array<string, mixed> $slice   Fields known to target wp_users.
	 * @return bool
	 */
	private static function write_wp_user( int $user_id, array $slice ): bool {
		$payload = array( 'ID' => $user_id );
		foreach ( $slice as $field => $value ) {
			$spec = self::resolve_spec( $field );
			if ( null === $spec || empty( $spec['column'] ) ) {
				continue;
			}
			$payload[ $spec['column'] ] = $value;
		}
		if ( count( $payload ) <= 1 ) {
			return false;
		}
		$result = wp_update_user( $payload );
		return ! ( $result instanceof \WP_Error );
	}

	/**
	 * Write to wp_ffc_user_profiles (upsert).
	 *
	 * @param int                  $user_id WordPress user ID.
	 * @param array<string, mixed> $slice   Fields known to target the profile table.
	 * @return bool
	 */
	private static function write_profile_table( int $user_id, array $slice ): bool {
		global $wpdb;
		$table = self::profile_table_name();

		$data = array();
		foreach ( $slice as $field => $value ) {
			$spec = self::resolve_spec( $field );
			if ( null === $spec || empty( $spec['column'] ) ) {
				continue;
			}
			$data[ $spec['column'] ] = $value;
		}
		if ( empty( $data ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT user_id FROM %i WHERE user_id = %d', $table, $user_id )
		);

		if ( $exists > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$affected = $wpdb->update( $table, $data, array( 'user_id' => $user_id ) );
			return false !== $affected;
		}

		$data['user_id'] = $user_id;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert( $table, $data );
		return false !== $inserted;
	}

	/**
	 * Write to wp_usermeta, encrypting sensitive values and writing the
	 * lookup hash for hashable fields.
	 *
	 * @param int                  $user_id WordPress user ID.
	 * @param array<string, mixed> $slice   Fields known to target usermeta.
	 * @return bool
	 */
	private static function write_usermeta( int $user_id, array $slice ): bool {
		$any = false;
		foreach ( $slice as $field => $value ) {
			$spec = self::resolve_spec( $field );
			if ( null === $spec || empty( $spec['meta_key'] ) ) {
				continue;
			}

			$meta_key  = $spec['meta_key'];
			$sensitive = ! empty( $spec['sensitive'] );

			$scalar = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
			if ( false === $scalar || null === $scalar ) {
				$scalar = '';
			}

			if ( '' === $scalar ) {
				delete_user_meta( $user_id, $meta_key );
				if ( $sensitive ) {
					$hash_key = self::resolve_hash_meta_key( $field );
					if ( null !== $hash_key ) {
						delete_user_meta( $user_id, $hash_key );
					}
				}
				$any = true;
				continue;
			}

			if ( ! $sensitive ) {
				update_user_meta( $user_id, $meta_key, sanitize_text_field( $scalar ) );
				$any = true;
				continue;
			}

			if ( ! class_exists( Encryption::class ) ) {
				continue;
			}

			$encrypted = Encryption::encrypt( $scalar );
			if ( null === $encrypted ) {
				continue;
			}
			update_user_meta( $user_id, $meta_key, $encrypted );

			$hash_key = self::resolve_hash_meta_key( $field );
			if ( null !== $hash_key ) {
				$hash = Encryption::hash( $scalar );
				if ( null !== $hash ) {
					update_user_meta( $user_id, $hash_key, $hash );
				}
			}

			$any = true;
		}
		return $any;
	}

	/**
	 * Synchronize mirror targets after the primary write completed.
	 *
	 * Only `display_name` has a mirror in Phase 1 (profile_table →
	 * wp_users). Implemented generically so future fields can declare
	 * mirrors without touching this method.
	 *
	 * @param int                  $user_id  WordPress user ID.
	 * @param array<string, mixed> $filtered Patch restricted to known fields.
	 * @return void
	 */
	private static function apply_mirrors( int $user_id, array $filtered ): void {
		foreach ( $filtered as $field => $value ) {
			$spec = self::resolve_spec( $field );
			if ( null === $spec || empty( $spec['mirrors'] ) || ! is_array( $spec['mirrors'] ) ) {
				continue;
			}
			foreach ( $spec['mirrors'] as $mirror ) {
				if ( ! is_array( $mirror ) ) {
					continue;
				}
				if ( UserProfileFieldMap::STORAGE_WP_USER === ( $mirror['storage'] ?? null )
					&& ! empty( $mirror['column'] )
				) {
					wp_update_user(
						array(
							'ID'              => $user_id,
							$mirror['column'] => $value,
						)
					);
				}
			}
		}
	}

	// ==================================================================
	// Utilities
	// ==================================================================

	/**
	 * Mask a plaintext value using the symbolic masker identifier.
	 *
	 * @param string      $plain  Plain value (empty yields empty).
	 * @param string|null $masker Masker identifier from the field map.
	 * @return string
	 */
	private static function mask( string $plain, ?string $masker ): string {
		if ( '' === $plain ) {
			return '';
		}
		if ( 'cpf' === $masker && class_exists( DocumentFormatter::class ) ) {
			// mask_cpf returns string; empty output means the input was
			// not a CPF-shaped value — fall through to the generic mask.
			$masked = DocumentFormatter::mask_cpf( $plain );
			if ( '' !== $masked ) {
				return $masked;
			}
		}
		// Fallback mask: keep the first and last characters, replace the
		// middle with asterisks. Never returns the plaintext.
		$len = strlen( $plain );
		if ( $len <= 2 ) {
			return str_repeat( '*', $len );
		}
		return $plain[0] . str_repeat( '*', $len - 2 ) . $plain[ $len - 1 ];
	}

	/**
	 * Whether any of the requested fields is flagged sensitive.
	 *
	 * @param array<int, string> $fields Field keys.
	 * @return bool
	 */
	private static function touches_sensitive( array $fields ): bool {
		foreach ( $fields as $field ) {
			if ( UserProfileFieldMap::is_sensitive( $field ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Emit the FULL-read audit entry. Non-sensitive reads are not logged
	 * to keep the audit trail focused on PII access.
	 *
	 * @param int                $user_id Target user ID.
	 * @param array<int, string> $fields  Requested fields (values never logged).
	 * @return void
	 */
	private static function audit_full_read( int $user_id, array $fields ): void {
		if ( ! class_exists( ActivityLog::class ) ) {
			return;
		}
		ActivityLog::log(
			'user_profile_read_full',
			// String literal so the call site stays ergonomic under
			// alias-mocking in tests; kept identical in value to
			// ActivityLog::LEVEL_INFO = 'info'.
			'info',
			array(
				'target_user_id' => $user_id,
				'fields'         => $fields,
			),
			function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			0
		);
	}

	/**
	 * Resolve the ffc_user_profiles table name once per request.
	 *
	 * @return string
	 */
	private static function profile_table_name(): string {
		if ( null === self::$profile_table ) {
			global $wpdb;
			self::$profile_table = $wpdb->prefix . 'ffc_user_profiles';
		}
		return self::$profile_table;
	}
}

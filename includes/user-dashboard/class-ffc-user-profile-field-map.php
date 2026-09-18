<?php
/**
 * UserProfileFieldMap
 *
 * Declarative registry of fields that describe a *current* FFC user:
 * where each field lives on disk, whether it is sensitive, whether it
 * can be looked up by hash, and how FULL / MASKED views differ.
 *
 * Sibling of SensitiveFieldRegistry — both are declarative, but the
 * registry is per write context (submission vs appointment), while
 * this map is per user-profile field. A future UserProfileService uses
 * the map to route reads and writes across the three canonical storage
 * layers (WordPress users table, ffc_user_profiles table, wp_usermeta
 * with the ffc_user_ prefix).
 *
 * Scope: only statically-known profile fields. Dynamic reregistration
 * custom fields (flagged `is_sensitive = 1` in wp_ffc_custom_fields)
 * are not registered here — they are surfaced on demand through a
 * separate adapter layer when the service needs them.
 *
 * @package FreeFormCertificate\UserDashboard
 * @since 6.0.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\UserDashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Field map for the user profile domain.
 */
final class UserProfileFieldMap {

	/**
	 * Storage: a column on the WordPress users table.
	 */
	public const STORAGE_WP_USER = 'wp_user';

	/**
	 * Storage: a column on wp_ffc_user_profiles.
	 */
	public const STORAGE_PROFILE_TABLE = 'profile_table';

	/**
	 * Storage: a row in wp_usermeta under a fixed meta key.
	 */
	public const STORAGE_USERMETA = 'usermeta';

	/**
	 * Usermeta key prefix for extended profile fields. Kept in sync with
	 * UserManager::EXTENDED_META_PREFIX so a registry lookup resolves to
	 * the same meta row the legacy path writes.
	 */
	private const EXTENDED_META_PREFIX = 'ffc_user_';

	/**
	 * Field descriptors keyed by logical field key.
	 *
	 * Shape per entry:
	 *   - storage:       one of STORAGE_* (required).
	 *   - column:        column name for STORAGE_WP_USER / STORAGE_PROFILE_TABLE.
	 *   - meta_key:      full meta_key for STORAGE_USERMETA.
	 *   - sensitive:     bool — the service encrypts on write, decrypts on read.
	 *   - hashable:      bool — service also writes a lookup hash (only
	 *                    meaningful when storage is usermeta).
	 *   - hash_column:   column on wp_ffc_user_profiles that holds that hash.
	 *                    Required whenever hashable is true.
	 *   - mirrors:       list of secondary write targets. The primary location is
	 *                    canonical for reads; mirrors exist to keep legacy code
	 *                    paths (e.g. wp_users.display_name) in sync.
	 *   - masker:        optional symbolic name of the MASKED transform; 'cpf'
	 *                    delegates to DocumentFormatter::mask_cpf. Omit for fields
	 *                    whose MASKED view is the FULL view (non-sensitive).
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const FIELDS = array(
		// WP core.
		'user_email'   => array(
			'storage'   => self::STORAGE_WP_USER,
			'column'    => 'user_email',
			'sensitive' => false,
		),

		// Profile table columns. display_name mirrors back to wp_users so
		// the WordPress display name stays coherent with the FFC profile.
		'display_name' => array(
			'storage'   => self::STORAGE_PROFILE_TABLE,
			'column'    => 'display_name',
			'sensitive' => false,
			'mirrors'   => array(
				array(
					'storage' => self::STORAGE_WP_USER,
					'column'  => 'display_name',
				),
			),
		),
		'phone'        => array(
			'storage'   => self::STORAGE_PROFILE_TABLE,
			'column'    => 'phone',
			'sensitive' => false,
		),
		'department'   => array(
			'storage'   => self::STORAGE_PROFILE_TABLE,
			'column'    => 'department',
			'sensitive' => false,
		),
		'organization' => array(
			'storage'   => self::STORAGE_PROFILE_TABLE,
			'column'    => 'organization',
			'sensitive' => false,
		),
		'notes'        => array(
			'storage'   => self::STORAGE_PROFILE_TABLE,
			'column'    => 'notes',
			'sensitive' => false,
		),
		'preferences'  => array(
			'storage'   => self::STORAGE_PROFILE_TABLE,
			'column'    => 'preferences',
			'sensitive' => false,
		),

		// Sensitive user-meta fields. CPF and RF are hashable, so the
		// service also writes a deterministic lookup hash -- into the
		// `hash_column` on ffc_user_profiles, NOT beside the ciphertext
		// (#1313 PR 2; see hash_column() for why the two stores split).
		// RG is encrypted but not looked up by hash.
		'cpf'          => array(
			'storage'     => self::STORAGE_USERMETA,
			'meta_key'    => self::EXTENDED_META_PREFIX . 'cpf',
			'sensitive'   => true,
			'hashable'    => true,
			'hash_column' => 'cpf_hash',
			'masker'      => 'cpf',
		),
		'rf'           => array(
			'storage'     => self::STORAGE_USERMETA,
			'meta_key'    => self::EXTENDED_META_PREFIX . 'rf',
			'sensitive'   => true,
			'hashable'    => true,
			'hash_column' => 'rf_hash',
			'masker'      => 'cpf',
		),
		'rg'           => array(
			'storage'   => self::STORAGE_USERMETA,
			'meta_key'  => self::EXTENDED_META_PREFIX . 'rg',
			'sensitive' => true,
			'hashable'  => false,
		),
		'jornada'      => array(
			'storage'   => self::STORAGE_USERMETA,
			'meta_key'  => self::EXTENDED_META_PREFIX . 'jornada',
			'sensitive' => false,
		),
	);

	/**
	 * Get the descriptor for a field key. Null when not registered.
	 *
	 * @param string $field_key Logical field key.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $field_key ): ?array {
		return self::FIELDS[ $field_key ] ?? null;
	}

	/**
	 * Whether the map knows this field.
	 *
	 * @param string $field_key Logical field key.
	 * @return bool
	 */
	public static function has( string $field_key ): bool {
		return isset( self::FIELDS[ $field_key ] );
	}

	/**
	 * All registered field keys.
	 *
	 * @return array<int, string>
	 */
	public static function field_keys(): array {
		return array_keys( self::FIELDS );
	}

	/**
	 * Subset of field keys flagged sensitive.
	 *
	 * @return array<int, string>
	 */
	public static function sensitive_field_keys(): array {
		$keys = array();
		foreach ( self::FIELDS as $key => $spec ) {
			if ( ! empty( $spec['sensitive'] ) ) {
				$keys[] = $key;
			}
		}
		return $keys;
	}

	/**
	 * Whether a specific field is sensitive.
	 *
	 * @param string $field_key Logical field key.
	 * @return bool
	 */
	public static function is_sensitive( string $field_key ): bool {
		$spec = self::FIELDS[ $field_key ] ?? null;
		return null !== $spec && ! empty( $spec['sensitive'] );
	}

	/**
	 * Column on wp_ffc_user_profiles that holds the lookup hash for a
	 * hashable usermeta field, or null when the field is not hashable.
	 *
	 * WHY THE HASH AND THE CIPHERTEXT LIVE IN DIFFERENT STORES (#1313)
	 *
	 * They answer different questions. The ciphertext is an ATTRIBUTE of one
	 * user: it is read by user id and never searched, which is exactly what
	 * wp_usermeta is indexed for. The hash is an INDEX: the question asked of
	 * it is "which user carries this identifier", and wp_usermeta indexes
	 * `user_id` and `meta_key` and never `meta_value` -- so asking it there is
	 * a scan of every row of the key, on a table that also holds every other
	 * plugin's meta. On ffc_user_profiles it is one indexed column on a table
	 * with one row per user.
	 *
	 * The split is therefore by ACCESS PATTERN, which is the ordinary shape of
	 * an index sitting beside a record, not a store that drifted in two. What
	 * keeps the two in step is that a single write path produces both.
	 *
	 * @param string $field_key Logical field key.
	 * @return string|null
	 */
	public static function hash_column( string $field_key ): ?string {
		$spec = self::FIELDS[ $field_key ] ?? null;
		if ( null === $spec
			|| self::STORAGE_USERMETA !== $spec['storage']
			|| empty( $spec['hashable'] )
			|| empty( $spec['hash_column'] )
		) {
			return null;
		}
		return (string) $spec['hash_column'];
	}

	/**
	 * LEGACY location of the lookup hash: the usermeta key it was written to
	 * before #1313 PR 2 moved it to {@see self::hash_column()}.
	 *
	 * Nothing writes it any more. It survives because the backfill in
	 * `UserDashboardActivator` has to know where to read the existing value
	 * from, and because naming it here is what lets a guard check that the
	 * backfill and the map agree rather than each carrying its own literal.
	 *
	 * @param string $field_key Logical field key.
	 * @return string|null
	 */
	public static function legacy_hash_meta_key( string $field_key ): ?string {
		$spec = self::FIELDS[ $field_key ] ?? null;
		// Every registered descriptor carries 'storage'; usermeta entries
		// always carry 'meta_key'. Only 'hashable' is optional, so that is
		// the single gate we test here on top of the basics.
		if ( null === $spec
			|| self::STORAGE_USERMETA !== $spec['storage']
			|| empty( $spec['hashable'] )
		) {
			return null;
		}
		return $spec['meta_key'] . '_hash';
	}

	/**
	 * Group field keys by storage kind.
	 *
	 * Useful for the service to decide how many distinct reads it needs
	 * per call (one per storage layer rather than one per field).
	 *
	 * @param array<int, string> $field_keys Input keys (unknown keys are dropped).
	 * @return array<string, array<int, string>> Map of storage_kind => [field_key, ...].
	 */
	public static function group_by_storage( array $field_keys ): array {
		$out = array();
		foreach ( $field_keys as $key ) {
			if ( ! isset( self::FIELDS[ $key ] ) ) {
				continue;
			}
			$storage = self::FIELDS[ $key ]['storage'];
			if ( ! isset( $out[ $storage ] ) ) {
				$out[ $storage ] = array();
			}
			$out[ $storage ][] = $key;
		}
		return $out;
	}
}

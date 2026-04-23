<?php
/**
 * SensitiveFieldRegistry
 *
 * Declarative registry of fields treated as sensitive per write context.
 * Consolidates policy that was previously duplicated across SubmissionHandler
 * and AppointmentRepository, where each site carried its own hard-coded list
 * of fields to encrypt and hash.
 *
 * Adding or removing a sensitive field is now a single edit to the FIELDS
 * map instead of a hunt across three files. The encrypted/hash column names
 * per field remain visible in one place, so storage schema and encryption
 * policy stay aligned.
 *
 * Activity log encryption is intentionally out of scope: it gates by action
 * name, not by field, and belongs to its own concern.
 *
 * @package FreeFormCertificate\Core
 * @since 5.4.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sensitive Field Registry.
 */
final class SensitiveFieldRegistry {

	/**
	 * Context key: wp_ffc_submissions write path.
	 */
	public const CONTEXT_SUBMISSION = 'submission';

	/**
	 * Context key: wp_ffc_self_scheduling_appointments write path.
	 */
	public const CONTEXT_APPOINTMENT = 'appointment';

	/**
	 * Field descriptors per context.
	 *
	 * Each entry maps a logical field key (e.g. "email") to the columns that
	 * must be populated when the plaintext is encrypted.
	 *
	 *   - encrypted_column: string|null  Column for ciphertext. Null = no
	 *                                    ciphertext stored (hash-only).
	 *   - hash_column:      string|null  Column for Encryption::hash lookup.
	 *                                    Null = no lookup hash stored.
	 *
	 * @var array<string, array<string, array{encrypted_column: ?string, hash_column: ?string}>>
	 */
	private const FIELDS = array(
		self::CONTEXT_SUBMISSION  => array(
			'email'   => array(
				'encrypted_column' => 'email_encrypted',
				'hash_column'      => 'email_hash',
			),
			'cpf'     => array(
				'encrypted_column' => 'cpf_encrypted',
				'hash_column'      => 'cpf_hash',
			),
			'rf'      => array(
				'encrypted_column' => 'rf_encrypted',
				'hash_column'      => 'rf_hash',
			),
			'user_ip' => array(
				'encrypted_column' => 'user_ip_encrypted',
				'hash_column'      => null,
			),
			'data'    => array(
				'encrypted_column' => 'data_encrypted',
				'hash_column'      => null,
			),
			'ticket'  => array(
				'encrypted_column' => null,
				'hash_column'      => 'ticket_hash',
			),
		),
		self::CONTEXT_APPOINTMENT => array(
			'email'       => array(
				'encrypted_column' => 'email_encrypted',
				'hash_column'      => 'email_hash',
			),
			'cpf'         => array(
				'encrypted_column' => 'cpf_encrypted',
				'hash_column'      => 'cpf_hash',
			),
			'rf'          => array(
				'encrypted_column' => 'rf_encrypted',
				'hash_column'      => 'rf_hash',
			),
			'phone'       => array(
				'encrypted_column' => 'phone_encrypted',
				'hash_column'      => null,
			),
			'custom_data' => array(
				'encrypted_column' => 'custom_data_encrypted',
				'hash_column'      => null,
			),
			'user_ip'     => array(
				'encrypted_column' => 'user_ip_encrypted',
				'hash_column'      => null,
			),
		),
	);

	/**
	 * All field specs for a context.
	 *
	 * @param string $context One of the CONTEXT_* constants.
	 * @return array<string, array{encrypted_column: ?string, hash_column: ?string}>
	 */
	public static function fields_for( string $context ): array {
		return self::FIELDS[ $context ] ?? array();
	}

	/**
	 * Whether a logical field is declared sensitive in a given context.
	 *
	 * @param string $context Context key.
	 * @param string $field_key Logical field key.
	 * @return bool
	 */
	public static function has( string $context, string $field_key ): bool {
		return isset( self::FIELDS[ $context ][ $field_key ] );
	}

	/**
	 * Encrypt and hash a batch of plaintext values into their column map.
	 *
	 * Empty or null values are skipped silently. When encryption is not
	 * configured (no keys in wp-config), the returned array is empty and
	 * the caller is expected to fall back to plaintext storage.
	 *
	 * @param string               $context Context key.
	 * @param array<string, mixed> $values  Plaintext values keyed by logical field.
	 * @return array<string, string|null> Columns to merge into the insert row.
	 */
	public static function encrypt_fields( string $context, array $values ): array {
		if ( ! class_exists( Encryption::class ) || ! Encryption::is_configured() ) {
			return array();
		}

		$out = array();

		foreach ( self::fields_for( $context ) as $field_key => $spec ) {
			if ( ! array_key_exists( $field_key, $values ) ) {
				continue;
			}
			$value = $values[ $field_key ];
			if ( null === $value || '' === $value ) {
				continue;
			}

			$plain = is_string( $value ) ? $value : (string) $value;

			if ( ! empty( $spec['encrypted_column'] ) ) {
				$out[ $spec['encrypted_column'] ] = Encryption::encrypt( $plain );
			}
			if ( ! empty( $spec['hash_column'] ) ) {
				$out[ $spec['hash_column'] ] = Encryption::hash( $plain );
			}
		}

		return $out;
	}

	/**
	 * Logical keys whose plaintext must be removed from a row before insert
	 * to avoid LGPD leaks.
	 *
	 * @param string $context Context key.
	 * @return list<string>
	 */
	public static function plaintext_keys( string $context ): array {
		return array_keys( self::fields_for( $context ) );
	}

	/**
	 * In-memory cache of the union of all static sensitive keys.
	 *
	 * @var array<string, bool>|null
	 */
	private static $universal_static_cache = null;

	/**
	 * Cache object key used for the dynamic (is_sensitive=1) set.
	 */
	private const DYNAMIC_CACHE_KEY = 'ffc_sensitive_field_keys_dynamic';

	/**
	 * Cache group for wp_cache_* of the dynamic set.
	 */
	private const DYNAMIC_CACHE_GROUP = 'ffc_sensitive_fields';

	/**
	 * Union of all static sensitive field keys across every context.
	 *
	 * Unlike fields_for(), this collapses per-context entries into a single
	 * flat set useful for payload inspection ("does this blob contain any
	 * field we consider sensitive?"). Cached in-memory per request.
	 *
	 * @return array<string, bool> Map of field_key => true for O(1) lookup.
	 */
	public static function universal_sensitive_keys(): array {
		if ( null !== self::$universal_static_cache ) {
			return self::$universal_static_cache;
		}

		$keys = array();
		foreach ( self::FIELDS as $fields ) {
			foreach ( array_keys( $fields ) as $key ) {
				$keys[ $key ] = true;
			}
		}
		self::$universal_static_cache = $keys;
		return $keys;
	}

	/**
	 * Dynamic sensitive field keys configured by admins via
	 * wp_ffc_custom_fields.is_sensitive = 1.
	 *
	 * Cached via the WP object cache. Invalidate with
	 * invalidate_dynamic_cache() when the custom fields table changes.
	 *
	 * @return array<string, bool> Map of field_key => true.
	 */
	public static function dynamic_sensitive_keys(): array {
		$cached = wp_cache_get( self::DYNAMIC_CACHE_KEY, self::DYNAMIC_CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'ffc_custom_fields';
		$keys  = array();

		// The table may not exist (fresh install, tests), so guard the query.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists === $table ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT DISTINCT field_key FROM %i WHERE is_sensitive = 1 AND is_active = 1',
					$table
				)
			);
			foreach ( (array) $rows as $field_key ) {
				if ( is_string( $field_key ) && '' !== $field_key ) {
					$keys[ $field_key ] = true;
				}
			}
		}

		wp_cache_set( self::DYNAMIC_CACHE_KEY, $keys, self::DYNAMIC_CACHE_GROUP );
		return $keys;
	}

	/**
	 * Drop the dynamic key cache. Call whenever wp_ffc_custom_fields is
	 * created, updated or deleted so the next read reflects the change.
	 *
	 * @return void
	 */
	public static function invalidate_dynamic_cache(): void {
		wp_cache_delete( self::DYNAMIC_CACHE_KEY, self::DYNAMIC_CACHE_GROUP );
	}

	/**
	 * Whether the payload contains any key classified as sensitive.
	 *
	 * Recursively descends into nested arrays so a wrapper like
	 * [ 'fields' => [ 'cpf' => '...' ] ] is correctly flagged. Matching is
	 * exact on the key name — callers are expected to log using canonical
	 * field keys ('cpf', not 'cpf_aluno') or risk a false negative.
	 *
	 * @param array<int|string, mixed> $payload Payload to inspect.
	 * @return bool
	 */
	public static function contains_sensitive( array $payload ): bool {
		if ( empty( $payload ) ) {
			return false;
		}

		$sensitive = self::universal_sensitive_keys() + self::dynamic_sensitive_keys();
		if ( empty( $sensitive ) ) {
			return false;
		}

		return self::walk_for_sensitive( $payload, $sensitive );
	}

	/**
	 * Depth-first scan for any array key that belongs to the sensitive set.
	 *
	 * @param array<int|string, mixed> $node Current node.
	 * @param array<string, bool>      $sensitive Lookup table.
	 * @return bool
	 */
	private static function walk_for_sensitive( array $node, array $sensitive ): bool {
		foreach ( $node as $key => $value ) {
			if ( is_string( $key ) && isset( $sensitive[ $key ] ) ) {
				return true;
			}
			if ( is_array( $value ) && self::walk_for_sensitive( $value, $sensitive ) ) {
				return true;
			}
		}
		return false;
	}
}

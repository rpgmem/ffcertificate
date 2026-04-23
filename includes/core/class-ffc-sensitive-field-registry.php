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
}

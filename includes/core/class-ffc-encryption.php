<?php
/**
 * Encryption
 *
 * Centralized encryption/decryption for sensitive data (LGPD compliance)
 *
 * Features:
 * - AES-256-CBC encryption
 * - SHA-256 hashing for searchable fields
 * - WordPress keys as encryption base
 * - Unique IV per record
 * - Batch operations support
 *
 * @package FreeFormCertificate\Core
 * @since 2.10.0
 * @version 3.3.0 - Added strict types and type hints for better code safety
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encryption.
 */
class Encryption {

	/**
	 * Encryption method
	 */
	const CIPHER = 'AES-256-CBC';

	/**
	 * IV length for AES-256-CBC
	 */
	const IV_LENGTH = 16;

	/**
	 * HMAC algorithm used to authenticate ciphertexts (v2+).
	 */
	const HMAC_ALGO = 'sha256';

	/**
	 * HMAC length in bytes for the configured algorithm.
	 */
	const HMAC_LENGTH = 32;

	/**
	 * V2 ciphertext prefix. Anything encrypted before this version is decoded by the legacy path.
	 */
	const V2_PREFIX = 'v2:';

	/**
	 * Encrypt a value
	 *
	 * Produces an authenticated (encrypt-then-MAC) ciphertext in the format
	 * "v2:" . base64( HMAC || IV || CIPHERTEXT ). Legacy "v1" ciphertexts
	 * (base64( IV || CIPHERTEXT ), without HMAC) remain decryptable by decrypt().
	 *
	 * @param string $value Plain text value.
	 * @return string|null Encrypted value (base64) or null on failure
	 */
	public static function encrypt( string $value ): ?string {
		if ( empty( $value ) ) {
			return null;
		}

		try {
			$enc_key = self::get_encryption_key();
			$mac_key = self::get_hmac_key();

			// Generate unique IV.
			$iv = random_bytes( self::IV_LENGTH );

			// Encrypt.
			$encrypted = openssl_encrypt(
				$value,
				self::CIPHER,
				$enc_key,
				OPENSSL_RAW_DATA,
				$iv
			);

			if ( false === $encrypted ) {
				\FreeFormCertificate\Core\Utils::debug_log(
					'Encryption failed',
					array(
						'value_length' => strlen( $value ),
					)
				);
				return null;
			}

			// Authenticate IV + ciphertext with HMAC (encrypt-then-MAC).
			$hmac = hash_hmac( self::HMAC_ALGO, $iv . $encrypted, $mac_key, true );

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- benign: binary ciphertext encoding for safe string transport.
			return self::V2_PREFIX . base64_encode( $hmac . $iv . $encrypted );

		} catch ( \Exception $e ) {
			\FreeFormCertificate\Core\Utils::debug_log(
				'Encryption exception',
				array(
					'error' => $e->getMessage(),
				)
			);
			return null;
		}
	}

	/**
	 * Decrypt a value
	 *
	 * Accepts both v2 authenticated ciphertexts ("v2:base64(HMAC||IV||CT)")
	 * and legacy v1 ciphertexts ("base64(IV||CT)", without HMAC).
	 *
	 * Any failure to decrypt a non-empty ciphertext (malformed envelope,
	 * HMAC mismatch, openssl error, exception) is reported to ActivityLog
	 * as a "decrypt_failure" warning so that silent callers — many use
	 * `decrypt(...) ?? ''` or filter null silently — do not hide tampering
	 * or key-rotation breakage. The log carries only metadata, never the
	 * ciphertext or plaintext.
	 *
	 * @param string $encrypted Encrypted value.
	 * @return string|null Decrypted value or null on failure
	 */
	public static function decrypt( string $encrypted ): ?string {
		if ( empty( $encrypted ) ) {
			// Empty input is not a failure; skip audit entry.
			return null;
		}

		$result = self::decrypt_internal( $encrypted );

		if ( null === $result ) {
			self::log_decrypt_failure( $encrypted );
		}

		return $result;
	}

	/**
	 * Internal decrypt implementation.
	 *
	 * Extracted so the public entry point can uniformly audit failures
	 * without threading an ActivityLog call through five return-null paths.
	 *
	 * @param string $encrypted Encrypted value.
	 * @return string|null
	 */
	private static function decrypt_internal( string $encrypted ): ?string {
		try {
			$enc_key = self::get_encryption_key();

			if ( 0 === strpos( $encrypted, self::V2_PREFIX ) ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- benign: decoding our own ciphertext envelope.
				$data = base64_decode( substr( $encrypted, strlen( self::V2_PREFIX ) ), true );
				if ( false === $data || strlen( $data ) < self::HMAC_LENGTH + self::IV_LENGTH ) {
					\FreeFormCertificate\Core\Utils::debug_log( 'v2 decode failed' );
					return null;
				}

				$hmac           = substr( $data, 0, self::HMAC_LENGTH );
				$iv             = substr( $data, self::HMAC_LENGTH, self::IV_LENGTH );
				$encrypted_data = substr( $data, self::HMAC_LENGTH + self::IV_LENGTH );

				$expected_hmac = hash_hmac( self::HMAC_ALGO, $iv . $encrypted_data, self::get_hmac_key(), true );
				if ( ! hash_equals( $expected_hmac, $hmac ) ) {
					\FreeFormCertificate\Core\Utils::debug_log( 'v2 HMAC mismatch' );
					return null;
				}
			} else {
				// Legacy (v1) path: base64( IV || CIPHERTEXT ), no authentication.
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- benign: decoding our own legacy ciphertext envelope.
				$data = base64_decode( $encrypted, true );
				if ( false === $data || strlen( $data ) < self::IV_LENGTH ) {
					\FreeFormCertificate\Core\Utils::debug_log( 'Base64 decode failed' );
					return null;
				}
				$iv             = substr( $data, 0, self::IV_LENGTH );
				$encrypted_data = substr( $data, self::IV_LENGTH );
			}

			// Decrypt.
			$decrypted = openssl_decrypt(
				$encrypted_data,
				self::CIPHER,
				$enc_key,
				OPENSSL_RAW_DATA,
				$iv
			);

			if ( false === $decrypted ) {
				\FreeFormCertificate\Core\Utils::debug_log( 'Decryption failed' );
				return null;
			}

			return $decrypted;

		} catch ( \Exception $e ) {
			\FreeFormCertificate\Core\Utils::debug_log(
				'Decryption exception',
				array(
					'error' => $e->getMessage(),
				)
			);
			return null;
		}
	}

	/**
	 * Record a decrypt_failure audit entry. No-op when the WordPress
	 * runtime is unavailable (unit tests, early bootstrap) or when
	 * ActivityLog is disabled in settings.
	 *
	 * The context is intentionally metadata-only (length + v2 flag) so
	 * this logging can never leak plaintext, and the payload contains no
	 * keys classified as sensitive, so it will never recurse back into
	 * Encryption::encrypt via the ActivityLog sensitivity gate.
	 *
	 * @param string $ciphertext Original ciphertext (inspected for metadata only).
	 * @return void
	 */
	private static function log_decrypt_failure( string $ciphertext ): void {
		if ( ! function_exists( 'get_option' ) ) {
			return;
		}
		if ( ! class_exists( ActivityLog::class ) ) {
			return;
		}

		try {
			if ( ! ActivityLog::is_enabled() ) {
				return;
			}
			ActivityLog::log(
				'decrypt_failure',
				ActivityLog::LEVEL_WARNING,
				array(
					'ciphertext_length' => strlen( $ciphertext ),
					'v2_prefix'         => 0 === strpos( $ciphertext, self::V2_PREFIX ),
				),
				0,
				0
			);
		} catch ( \Throwable $e ) {
			// Never let logging failure propagate up into decryption callers.
			unset( $e );
		}
	}

	/**
	 * Generate hash for searchable field
	 *
	 * Uses SHA-256 for consistent, searchable hashes
	 *
	 * @param string $value Value to hash.
	 * @return string|null SHA-256 hash or null if empty
	 */
	public static function hash( string $value ): ?string {
		if ( empty( $value ) ) {
			return null;
		}

		// Get hash salt.
		$salt = self::get_hash_salt();

		// Generate SHA-256 hash.
		return hash( 'sha256', $value . $salt );
	}

	/**
	 * Encrypt submission data (batch helper)
	 *
	 * Encrypts all sensitive fields in a submission array
	 *
	 * @param array<string, mixed> $submission Submission data.
	 * @return array<string, mixed> Encrypted data with hash fields
	 */
	public static function encrypt_submission( array $submission ): array {
		$encrypted = array();

		// Email.
		if ( ! empty( $submission['email'] ) ) {
			$encrypted['email_encrypted'] = self::encrypt( $submission['email'] );
			$encrypted['email_hash']      = self::hash( $submission['email'] );
		}

		// CPF/RF — split columns only; legacy cpf_rf_encrypted/cpf_rf_hash no longer written.
		// Callers should encrypt into cpf_encrypted/cpf_hash or rf_encrypted/rf_hash directly.

		// IP Address.
		if ( ! empty( $submission['user_ip'] ) ) {
			$encrypted['user_ip_encrypted'] = self::encrypt( $submission['user_ip'] );
		}

		// JSON Data.
		if ( ! empty( $submission['data'] ) ) {
			$encrypted['data_encrypted'] = self::encrypt( $submission['data'] );
		}

		return $encrypted;
	}

	/**
	 * Decrypt submission data (batch helper)
	 *
	 * Decrypts all encrypted fields in a submission array
	 *
	 * @param array<string, mixed> $submission Submission data with encrypted fields.
	 * @return array<string, mixed> Decrypted data
	 */
	public static function decrypt_submission( array $submission ): array {
		$decrypted = $submission; // Keep all fields.

		// Email (try encrypted first, fallback to plain).
		if ( ! empty( $submission['email_encrypted'] ) ) {
			$decrypted['email'] = self::decrypt( $submission['email_encrypted'] );
		}

		// CPF/RF — decrypt split columns, then legacy fallback.
		$cpf_val = ! empty( $submission['cpf_encrypted'] ) ? self::decrypt( $submission['cpf_encrypted'] ) : null;
		$rf_val  = ! empty( $submission['rf_encrypted'] ) ? self::decrypt( $submission['rf_encrypted'] ) : null;

		if ( ! empty( $cpf_val ) ) {
			$decrypted['cpf']    = $cpf_val;
			$decrypted['cpf_rf'] = $cpf_val;
		} elseif ( ! empty( $rf_val ) ) {
			$decrypted['rf']     = $rf_val;
			$decrypted['cpf_rf'] = $rf_val;
		}

		// IP Address.
		if ( ! empty( $submission['user_ip_encrypted'] ) ) {
			$decrypted['user_ip'] = self::decrypt( $submission['user_ip_encrypted'] );
		}

		// JSON Data (try encrypted first, fallback to plain).
		if ( ! empty( $submission['data_encrypted'] ) ) {
			$decrypted['data'] = self::decrypt( $submission['data_encrypted'] );
		}

		return $decrypted;
	}

	/**
	 * Decrypt a single field with encrypted-first + plain fallback.
	 *
	 * Eliminates the repeated pattern across CSV exporters, REST controllers
	 * and email handlers:
	 *   if (!empty($row['field_encrypted'])) { decrypt(...) }
	 *   elseif (!empty($row['field'])) { $row['field']; }
	 *
	 * @since 4.11.2
	 * @param array<string, mixed> $row            Row data.
	 * @param string               $field          Plain-text field name (e.g. 'email').
	 * @param string               $encrypted_key  Encrypted field name. Defaults to "{$field}_encrypted".
	 * @return string Decrypted value, plain fallback, or empty string.
	 */
	public static function decrypt_field( array $row, string $field, string $encrypted_key = '' ): string {
		if ( '' === $encrypted_key ) {
			$encrypted_key = $field . '_encrypted';
		}

		if ( ! empty( $row[ $encrypted_key ] ) ) {
			$decrypted = self::decrypt( $row[ $encrypted_key ] );
			if ( null !== $decrypted ) {
				return $decrypted;
			}
		}

		return (string) ( $row[ $field ] ?? '' );
	}

	/**
	 * Decrypt appointment data (batch helper for appointments).
	 *
	 * Similar to decrypt_submission() but for the appointment table schema.
	 *
	 * @since 4.11.2
	 * @param array<string, mixed> $appointment Appointment row data with encrypted fields.
	 * @return array<string, mixed> Row with plain-text fields populated.
	 */
	public static function decrypt_appointment( array $appointment ): array {
		$decrypted = $appointment;

		if ( ! empty( $appointment['email_encrypted'] ) ) {
			$decrypted['email'] = self::decrypt( $appointment['email_encrypted'] ) ?? '';
		}

		// CPF/RF — decrypt split columns.
		$cpf_val = ! empty( $appointment['cpf_encrypted'] ) ? self::decrypt( $appointment['cpf_encrypted'] ) : null;
		$rf_val  = ! empty( $appointment['rf_encrypted'] ) ? self::decrypt( $appointment['rf_encrypted'] ) : null;

		if ( ! empty( $cpf_val ) ) {
			$decrypted['cpf']    = $cpf_val;
			$decrypted['cpf_rf'] = $cpf_val;
		} elseif ( ! empty( $rf_val ) ) {
			$decrypted['rf']     = $rf_val;
			$decrypted['cpf_rf'] = $rf_val;
		}

		if ( ! empty( $appointment['phone_encrypted'] ) ) {
			$decrypted['phone'] = self::decrypt( $appointment['phone_encrypted'] ) ?? ( $appointment['phone'] ?? '' );
		}
		if ( ! empty( $appointment['user_ip_encrypted'] ) ) {
			$decrypted['user_ip'] = self::decrypt( $appointment['user_ip_encrypted'] ) ?? '';
		}
		if ( ! empty( $appointment['custom_data_encrypted'] ) ) {
			$decrypted['custom_data'] = self::decrypt( $appointment['custom_data_encrypted'] ) ?? ( $appointment['custom_data'] ?? '' );
		}

		return $decrypted;
	}

	/**
	 * Get encryption key
	 *
	 * Derives key from WordPress constants (SECURE_AUTH_KEY, etc)
	 *
	 * @return string 32-byte encryption key
	 */
	private static function get_encryption_key(): string {
		// Check if custom key defined.
		if ( defined( 'FFC_ENCRYPTION_KEY' ) && strlen( FFC_ENCRYPTION_KEY ) >= 32 ) {
			return substr( FFC_ENCRYPTION_KEY, 0, 32 );
		}

		// Derive from WordPress keys.
		$base_keys = array(
			defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '',
			defined( 'LOGGED_IN_KEY' ) ? LOGGED_IN_KEY : '',
			defined( 'NONCE_KEY' ) ? NONCE_KEY : '',
		);

		// Combine and hash.
		$combined = implode( '|', $base_keys );

		// Use PBKDF2 for key derivation. The static salt and 10k iteration count are retained
		// to keep existing ciphertexts decryptable without a data-migration step. Entropy is
		// already provided by the underlying WordPress secret keys, so the PBKDF2 workload
		// here is only a defense against accidental entropy loss rather than password cracking.
		$key = hash_pbkdf2( 'sha256', $combined, 'ffc-encryption-salt', 10000, 32, true );

		return $key;
	}

	/**
	 * Get HMAC key for ciphertext authentication.
	 *
	 * Derived from the same material as the encryption key but through a distinct PBKDF2
	 * salt so that the MAC key is cryptographically independent from the encryption key.
	 *
	 * @return string 32-byte HMAC key
	 */
	private static function get_hmac_key(): string {
		if ( defined( 'FFC_ENCRYPTION_KEY' ) && strlen( FFC_ENCRYPTION_KEY ) >= 32 ) {
			return hash_hmac( 'sha256', 'ffc-hmac-key', FFC_ENCRYPTION_KEY, true );
		}

		$base_keys = array(
			defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '',
			defined( 'LOGGED_IN_KEY' ) ? LOGGED_IN_KEY : '',
			defined( 'NONCE_KEY' ) ? NONCE_KEY : '',
		);
		$combined  = implode( '|', $base_keys );

		return hash_pbkdf2( 'sha256', $combined, 'ffc-hmac-salt', 10000, 32, true );
	}

	/**
	 * Get hash salt
	 *
	 * Derives salt from WordPress constants for consistent hashing
	 *
	 * @return string Hash salt
	 */
	private static function get_hash_salt(): string {
		// Check if custom salt defined.
		if ( defined( 'FFC_HASH_SALT' ) ) {
			return FFC_HASH_SALT;
		}

		// Derive from WordPress keys.
		$base_keys = array(
			defined( 'AUTH_KEY' ) ? AUTH_KEY : '',
			defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '',
		);

		return implode( '|', $base_keys );
	}

	/**
	 * Test encryption/decryption
	 *
	 * Utility method for testing encryption setup
	 *
	 * @return array<string, mixed> Test results
	 */
	public static function test(): array {
		$test_value = 'Test Value 123!@#';

		$encrypted = self::encrypt( $test_value );
		$decrypted = ( null !== $encrypted ) ? self::decrypt( $encrypted ) : null;
		$hash      = self::hash( $test_value );

		return array(
			'original'         => $test_value,
			'encrypted'        => $encrypted,
			'encrypted_length' => ( null !== $encrypted ) ? strlen( $encrypted ) : 0,
			'decrypted'        => $decrypted,
			'hash'             => $hash,
			'hash_length'      => ( null !== $hash ) ? strlen( $hash ) : 0,
			'match'            => $decrypted === $test_value,
			'key_source'       => defined( 'FFC_ENCRYPTION_KEY' ) ? 'Custom' : 'WordPress Keys',
		);
	}

	/**
	 * Check if encryption is configured
	 *
	 * @return bool True if encryption keys available
	 */
	public static function is_configured(): bool {
		// Check if WordPress keys exist.
		if ( ! defined( 'SECURE_AUTH_KEY' ) || empty( SECURE_AUTH_KEY ) ) {
			return false;
		}

		if ( ! defined( 'LOGGED_IN_KEY' ) || empty( LOGGED_IN_KEY ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Get encryption info (for admin display)
	 *
	 * @return array<string, mixed> Encryption configuration info
	 */
	public static function get_info(): array {
		return array(
			'configured'     => self::is_configured(),
			'cipher'         => self::CIPHER,
			'iv_length'      => self::IV_LENGTH,
			'key_source'     => defined( 'FFC_ENCRYPTION_KEY' ) ? 'Custom (FFC_ENCRYPTION_KEY)' : 'WordPress Keys (SECURE_AUTH_KEY + LOGGED_IN_KEY + NONCE_KEY)',
			'hash_algorithm' => 'SHA-256',
			'key_derivation' => 'PBKDF2 (10000 iterations)',
			'authentication' => 'HMAC-SHA256 (v2 ciphertexts; legacy v1 still decryptable)',
		);
	}
}

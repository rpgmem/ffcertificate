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
 * @since 2.10.0
 * @version 3.3.0 - Added strict types and type hints for better code safety
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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
     * Encrypt a value
     *
     * @param string $value Plain text value
     * @return string|null Encrypted value (base64) or null on failure
     */
    public static function encrypt( string $value ): ?string {
        if ( empty( $value ) || $value === null ) {
            return null;
        }
        
        try {
            // Get encryption key
            $key = self::get_encryption_key();
            
            // Generate unique IV
            $iv = openssl_random_pseudo_bytes( self::IV_LENGTH );
            
            // Encrypt
            $encrypted = openssl_encrypt(
                $value,
                self::CIPHER,
                $key,
                OPENSSL_RAW_DATA,
                $iv
            );
            
            if ( $encrypted === false ) {
                \FreeFormCertificate\Core\Utils::debug_log( 'Encryption failed', array(
                    'value_length' => strlen( $value )
                ) );
                return null;
            }
            
            // Prepend IV to encrypted data and encode
            return base64_encode( $iv . $encrypted );
            
        } catch ( \Exception $e ) {
            \FreeFormCertificate\Core\Utils::debug_log( 'Encryption exception', array(
                'error' => $e->getMessage()
            ) );
            return null;
        }
    }
    
    /**
     * Decrypt a value
     *
     * @param string $encrypted Encrypted value (base64)
     * @return string|null Decrypted value or null on failure
     */
    public static function decrypt( string $encrypted ): ?string {
        if ( empty( $encrypted ) || $encrypted === null ) {
            return null;
        }
        
        try {
            // Get encryption key
            $key = self::get_encryption_key();
            
            // Decode from base64
            $data = base64_decode( $encrypted, true );
            
            if ( $data === false ) {
                \FreeFormCertificate\Core\Utils::debug_log( 'Base64 decode failed' );
                return null;
            }
            
            // Extract IV (first 16 bytes)
            $iv = substr( $data, 0, self::IV_LENGTH );
            
            // Extract encrypted data (rest)
            $encrypted_data = substr( $data, self::IV_LENGTH );
            
            // Decrypt
            $decrypted = openssl_decrypt(
                $encrypted_data,
                self::CIPHER,
                $key,
                OPENSSL_RAW_DATA,
                $iv
            );
            
            if ( $decrypted === false ) {
                \FreeFormCertificate\Core\Utils::debug_log( 'Decryption failed' );
                return null;
            }
            
            return $decrypted;
            
        } catch ( \Exception $e ) {
            \FreeFormCertificate\Core\Utils::debug_log( 'Decryption exception', array(
                'error' => $e->getMessage()
            ) );
            return null;
        }
    }
    
    /**
     * Generate hash for searchable field
     *
     * Uses SHA-256 for consistent, searchable hashes
     *
     * @param string $value Value to hash
     * @return string|null SHA-256 hash or null if empty
     */
    public static function hash( string $value ): ?string {
        if ( empty( $value ) || $value === null ) {
            return null;
        }
        
        // Get hash salt
        $salt = self::get_hash_salt();
        
        // Generate SHA-256 hash
        return hash( 'sha256', $value . $salt );
    }
    
    /**
     * Encrypt submission data (batch helper)
     *
     * Encrypts all sensitive fields in a submission array
     *
     * @param array $submission Submission data
     * @return array Encrypted data with hash fields
     */
    public static function encrypt_submission( array $submission ): array {
        $encrypted = array();
        
        // Email
        if ( ! empty( $submission['email'] ) ) {
            $encrypted['email_encrypted'] = self::encrypt( $submission['email'] );
            $encrypted['email_hash'] = self::hash( $submission['email'] );
        }
        
        // CPF/RF
        if ( ! empty( $submission['cpf_rf'] ) ) {
            $encrypted['cpf_rf_encrypted'] = self::encrypt( $submission['cpf_rf'] );
            $encrypted['cpf_rf_hash'] = self::hash( $submission['cpf_rf'] );
        }
        
        // IP Address
        if ( ! empty( $submission['user_ip'] ) ) {
            $encrypted['user_ip_encrypted'] = self::encrypt( $submission['user_ip'] );
        }
        
        // JSON Data
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
     * @param array $submission Submission data with encrypted fields
     * @return array Decrypted data
     */
    public static function decrypt_submission( array $submission ): array {
        $decrypted = $submission; // Keep all fields
        
        // Email (try encrypted first, fallback to plain)
        if ( ! empty( $submission['email_encrypted'] ) ) {
            $decrypted['email'] = self::decrypt( $submission['email_encrypted'] );
        }
        
        // CPF/RF (try encrypted first, fallback to plain)
        if ( ! empty( $submission['cpf_rf_encrypted'] ) ) {
            $decrypted['cpf_rf'] = self::decrypt( $submission['cpf_rf_encrypted'] );
        }
        
        // IP Address (try encrypted first, fallback to plain)
        if ( ! empty( $submission['user_ip_encrypted'] ) ) {
            $decrypted['user_ip'] = self::decrypt( $submission['user_ip_encrypted'] );
        }
        
        // JSON Data (try encrypted first, fallback to plain)
        if ( ! empty( $submission['data_encrypted'] ) ) {
            $decrypted['data'] = self::decrypt( $submission['data_encrypted'] );
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
        // Check if custom key defined
        if ( defined( 'FFC_ENCRYPTION_KEY' ) && strlen( FFC_ENCRYPTION_KEY ) >= 32 ) {
            return substr( FFC_ENCRYPTION_KEY, 0, 32 );
        }
        
        // Derive from WordPress keys
        $base_keys = array(
            defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '',
            defined( 'LOGGED_IN_KEY' ) ? LOGGED_IN_KEY : '',
            defined( 'NONCE_KEY' ) ? NONCE_KEY : ''
        );
        
        // Combine and hash
        $combined = implode( '|', $base_keys );
        
        // Use PBKDF2 for key derivation
        $key = hash_pbkdf2( 'sha256', $combined, 'ffc-encryption-salt', 10000, 32, true );
        
        return $key;
    }
    
    /**
     * Get hash salt
     *
     * Derives salt from WordPress constants for consistent hashing
     *
     * @return string Hash salt
     */
    private static function get_hash_salt(): string {
        // Check if custom salt defined
        if ( defined( 'FFC_HASH_SALT' ) ) {
            return FFC_HASH_SALT;
        }
        
        // Derive from WordPress keys
        $base_keys = array(
            defined( 'AUTH_KEY' ) ? AUTH_KEY : '',
            defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : ''
        );
        
        return implode( '|', $base_keys );
    }
    
    /**
     * Test encryption/decryption
     *
     * Utility method for testing encryption setup
     *
     * @return array Test results
     */
    public static function test(): array {
        $test_value = 'Test Value 123!@#';
        
        $encrypted = self::encrypt( $test_value );
        $decrypted = self::decrypt( $encrypted );
        $hash = self::hash( $test_value );
        
        return array(
            'original' => $test_value,
            'encrypted' => $encrypted,
            'encrypted_length' => strlen( $encrypted ),
            'decrypted' => $decrypted,
            'hash' => $hash,
            'hash_length' => strlen( $hash ),
            'match' => $decrypted === $test_value,
            'key_source' => defined( 'FFC_ENCRYPTION_KEY' ) ? 'Custom' : 'WordPress Keys'
        );
    }
    
    /**
     * Check if encryption is configured
     *
     * @return bool True if encryption keys available
     */
    public static function is_configured(): bool {
        // Check if WordPress keys exist
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
     * @return array Encryption configuration info
     */
    public static function get_info(): array {
        return array(
            'configured' => self::is_configured(),
            'cipher' => self::CIPHER,
            'iv_length' => self::IV_LENGTH,
            'key_source' => defined( 'FFC_ENCRYPTION_KEY' ) ? 'Custom (FFC_ENCRYPTION_KEY)' : 'WordPress Keys (SECURE_AUTH_KEY + LOGGED_IN_KEY + NONCE_KEY)',
            'hash_algorithm' => 'SHA-256',
            'key_derivation' => 'PBKDF2 (10000 iterations)'
        );
    }
}
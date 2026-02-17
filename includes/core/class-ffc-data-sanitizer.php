<?php
declare(strict_types=1);

/**
 * DataSanitizer
 *
 * Focused service class for recursive data sanitization and
 * Brazilian name normalization.
 *
 * Extracted from Utils.php (Sprint 31) for single-responsibility compliance.
 *
 * @since 4.12.27
 * @package FreeFormCertificate\Core
 */

namespace FreeFormCertificate\Core;

if (!defined('ABSPATH')) exit;

class DataSanitizer {

    /**
     * Recursively sanitize data (arrays or strings)
     *
     * @since 2.9.11
     * @param mixed $data Data to sanitize (array or string)
     * @return mixed Sanitized data
     */
    public static function recursive_sanitize( $data ) {
        if ( is_array( $data ) ) {
            $sanitized = array();
            foreach ( $data as $key => $value ) {
                $sanitized[ sanitize_key( $key ) ] = self::recursive_sanitize( $value );
            }
            return $sanitized;
        }
        return wp_kses( $data, Utils::get_allowed_html_tags() );
    }

    /**
     * Normalize Brazilian name with proper capitalization
     *
     * Capitalizes the first letter of each word, except for common
     * Portuguese connectives (prepositions) which remain lowercase.
     *
     * Examples:
     * - "ALEX PEREIRA DA SILVA" -> "Alex Pereira da Silva"
     * - "maria dos santos e oliveira" -> "Maria dos Santos e Oliveira"
     * - "JOAO DE SOUZA FILHO" -> "Joao de Souza Filho"
     *
     * @since 4.3.0
     * @param string $name Name to normalize
     * @return string Normalized name
     */
    public static function normalize_brazilian_name( string $name ): string {
        if ( empty( $name ) ) {
            return '';
        }

        // Brazilian Portuguese connectives that should remain lowercase
        $connectives = array(
            'da', 'das', 'de', 'do', 'dos',  // Most common
            'e',                              // "and" between names
            'di', 'du',                       // Italian/French origin
        );

        // Convert entire string to lowercase first
        // Use mb functions for proper UTF-8 handling (accented chars like a, e, c)
        $name = mb_strtolower( trim( $name ), 'UTF-8' );

        // Split into words
        $words = preg_split( '/\s+/', $name );

        $normalized_words = array();
        foreach ( $words as $word ) {
            if ( empty( $word ) ) {
                continue;
            }

            // Check if word is a connective (case-insensitive comparison)
            if ( in_array( mb_strtolower( $word, 'UTF-8' ), $connectives, true ) ) {
                // Keep connective lowercase
                $normalized_words[] = mb_strtolower( $word, 'UTF-8' );
            } else {
                // Capitalize first letter
                $normalized_words[] = mb_strtoupper( mb_substr( $word, 0, 1, 'UTF-8' ), 'UTF-8' )
                    . mb_substr( $word, 1, null, 'UTF-8' );
            }
        }

        // Handle edge case: if first word is a connective, capitalize it anyway
        // (Names shouldn't start with lowercase connective)
        if ( ! empty( $normalized_words ) && in_array( $normalized_words[0], $connectives, true ) ) {
            $normalized_words[0] = mb_strtoupper( mb_substr( $normalized_words[0], 0, 1, 'UTF-8' ), 'UTF-8' )
                . mb_substr( $normalized_words[0], 1, null, 'UTF-8' );
        }

        return implode( ' ', $normalized_words );
    }
}

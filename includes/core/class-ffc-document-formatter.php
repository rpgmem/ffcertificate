<?php
declare(strict_types=1);

/**
 * DocumentFormatter
 *
 * Focused service class for document validation, formatting, and masking.
 * Handles Brazilian CPF, RF, auth codes, phone numbers, and email masking.
 *
 * Extracted from Utils.php (Sprint 30) for single-responsibility compliance.
 *
 * @since 4.12.26
 * @package FreeFormCertificate\Core
 */

namespace FreeFormCertificate\Core;

if (!defined('ABSPATH')) exit;

class DocumentFormatter {

    /**
     * Virtual prefix for certificates (ffc_submissions).
     * @since 4.13.0
     */
    public const PREFIX_CERTIFICATE = 'C';

    /**
     * Virtual prefix for reregistrations (ffc_reregistration_submissions).
     * @since 4.13.0
     */
    public const PREFIX_REREGISTRATION = 'R';

    /**
     * Virtual prefix for appointments (ffc_self_scheduling_appointments).
     * @since 4.13.0
     */
    public const PREFIX_APPOINTMENT = 'A';

    /**
     * Valid auth code prefixes.
     * @since 4.13.0
     */
    private const VALID_PREFIXES = array( 'C', 'R', 'A' );

    /**
     * Phone validation regex pattern (without delimiters).
     */
    public const PHONE_REGEX = '^\(?\d{2}\)?\s?\d{4,5}-?\d{4}$';

    /**
     * Validate CPF (Brazilian tax ID)
     *
     * @param string $cpf CPF to validate (with or without formatting)
     * @return bool True if valid
     */
    public static function validate_cpf(string $cpf): bool {
        $cpf = preg_replace('/\D/', '', $cpf);

        if (strlen($cpf) != 11) {
            return false;
        }

        if (preg_match('/(\d)\1{10}/', $cpf)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            for ($d = 0, $c = 0; $c < $t; $c++) {
                $d += (int) $cpf[$c] * (($t + 1) - $c);
            }
            $d = ((10 * $d) % 11) % 10;
            if ((int) $cpf[$c] != $d) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate RF (7-digit registration)
     *
     * @param string $rf RF to validate
     * @return bool True if valid
     */
    public static function validate_rf(string $rf): bool {
        $rf = preg_replace('/\D/', '', $rf);
        return strlen($rf) === 7 && is_numeric($rf);
    }

    /**
     * Validate Brazilian phone number.
     *
     * @since 4.11.0
     * @param string $phone Phone string.
     * @return bool True if valid
     */
    public static function validate_phone(string $phone): bool {
        $phone = preg_replace('/\s+/', '', $phone);
        return (bool) preg_match('/^\(?\d{2}\)?\s?\d{4,5}-?\d{4}$/', $phone);
    }

    /**
     * Format CPF with mask
     *
     * @param string $cpf CPF to format
     * @return string Formatted CPF (XXX.XXX.XXX-XX)
     */
    public static function format_cpf(string $cpf): string {
        $cpf = preg_replace('/\D/', '', $cpf);

        if (strlen($cpf) === 11) {
            return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $cpf);
        }

        return $cpf;
    }

    /**
     * Format RF with mask
     *
     * @param string $rf RF to format
     * @return string Formatted RF (XXX.XXX-X)
     */
    public static function format_rf(string $rf): string {
        $rf = preg_replace('/\D/', '', $rf);

        if (strlen($rf) === 7) {
            return preg_replace('/(\d{3})(\d{3})(\d{1})/', '$1.$2-$3', $rf);
        }

        return $rf;
    }

    /**
     * Format authentication code with optional virtual prefix.
     *
     * @since 4.13.0 Added $prefix parameter.
     * @param string $code Auth code to format (raw 12-char or already formatted).
     * @param string $prefix Virtual prefix letter (C, R, A) — not stored in DB.
     * @return string Formatted code: P-XXXX-XXXX-XXXX (with prefix) or XXXX-XXXX-XXXX (without).
     */
    public static function format_auth_code(string $code, string $prefix = ''): string {
        $code = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code));

        if (strlen($code) === 12) {
            $formatted = substr($code, 0, 4) . '-' . substr($code, 4, 4) . '-' . substr($code, 8, 4);
        } else {
            $formatted = $code;
        }

        if ($prefix !== '' && in_array(strtoupper($prefix), self::VALID_PREFIXES, true)) {
            return strtoupper($prefix) . '-' . $formatted;
        }

        return $formatted;
    }

    /**
     * Format any document based on type
     *
     * @param string $value Document value
     * @param string $type Document type (cpf, rf, auth_code, or 'auto')
     * @return string Formatted document
     */
    public static function format_document(string $value, string $type = 'auto'): string {
        $clean = preg_replace('/\D/', '', $value);
        $len = strlen($clean);

        if ($type === 'auto') {
            if ($len === 11) {
                $type = 'cpf';
            } elseif ($len === 7) {
                $type = 'rf';
            } elseif ($len === 12) {
                $type = 'auth_code';
            }
        }

        switch ($type) {
            case 'cpf':
                return self::format_cpf($value);
            case 'rf':
                return self::format_rf($value);
            case 'auth_code':
                return self::format_auth_code($value);
            default:
                return $value;
        }
    }

    /**
     * Mask CPF/RF for privacy
     *
     * @since 2.9.17
     * @param string $value CPF or RF to mask
     * @return string Masked document
     */
    public static function mask_cpf(string $value): string {
        if (empty($value)) {
            return '';
        }

        $clean = preg_replace('/[^0-9]/', '', $value);

        if (strlen($clean) === 11) {
            return substr($clean, 0, 3) . '.***.***-' . substr($clean, -2);
        } elseif (strlen($clean) === 7) {
            return substr($clean, 0, 3) . '.***-' . substr($clean, -1);
        }

        return $value;
    }

    /**
     * Mask email address for privacy
     *
     * @since 3.2.0
     * @param string $email Email address to mask
     * @return string Masked email
     */
    public static function mask_email(string $email): string {
        if (empty($email) || !is_email($email)) {
            return $email;
        }

        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return $email;
        }

        return substr($parts[0], 0, 1) . '***@' . $parts[1];
    }

    /**
     * Parse a potentially prefixed auth code into prefix + raw code.
     *
     * Accepts: "C-XXXX-XXXX-XXXX", "CXXXXXXXXXXXX", "XXXX-XXXX-XXXX", "XXXXXXXXXXXX".
     * Returns: ['prefix' => 'C'|'R'|'A'|'', 'code' => 'XXXXXXXXXXXX']
     *
     * @since 4.13.0
     * @param string $input Raw user input (with or without prefix/dashes).
     * @return array{prefix: string, code: string}
     */
    public static function parse_prefixed_code(string $input): array {
        // Strip whitespace, uppercase
        $clean = strtoupper(trim($input));

        // Remove all non-alphanumeric chars
        $alphanumeric = preg_replace('/[^A-Z0-9]/', '', $clean);

        // 13 chars: first char is a valid prefix letter
        if (strlen($alphanumeric) === 13 && in_array($alphanumeric[0], self::VALID_PREFIXES, true)) {
            return array(
                'prefix' => $alphanumeric[0],
                'code'   => substr($alphanumeric, 1),
            );
        }

        // 12 chars: no prefix
        if (strlen($alphanumeric) === 12) {
            return array(
                'prefix' => '',
                'code'   => $alphanumeric,
            );
        }

        // Fallback: return as-is (invalid length)
        return array(
            'prefix' => '',
            'code'   => $alphanumeric,
        );
    }

    /**
     * Clean authentication code (remove special chars, prefix, uppercase).
     *
     * Strips the virtual prefix if present, returning only the 12-char code.
     *
     * @param string $code Auth code to clean (may include prefix).
     * @return string Cleaned 12-char code (uppercase alphanumeric only, no prefix).
     */
    public static function clean_auth_code(string $code): string {
        $parsed = self::parse_prefixed_code($code);
        return $parsed['code'];
    }

    /**
     * Clean identifier (CPF, RF, ticket) - uppercase alphanumeric only
     *
     * @param string $value Identifier to clean
     * @return string Cleaned identifier
     */
    public static function clean_identifier(string $value): string {
        return strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $value));
    }
}

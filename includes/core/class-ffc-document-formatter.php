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
     * Format authentication code
     *
     * @param string $code Auth code to format
     * @return string Formatted code (XXXX-XXXX-XXXX)
     */
    public static function format_auth_code(string $code): string {
        $code = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code));

        if (strlen($code) === 12) {
            return substr($code, 0, 4) . '-' . substr($code, 4, 4) . '-' . substr($code, 8, 4);
        }

        return $code;
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
     * Clean authentication code (remove special chars, uppercase)
     *
     * @param string $code Auth code to clean
     * @return string Cleaned code (uppercase alphanumeric only)
     */
    public static function clean_auth_code(string $code): string {
        return strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $code));
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

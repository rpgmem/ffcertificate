<?php
declare(strict_types=1);

/**
 * AccessRestrictionChecker
 *
 * Extracted from FormProcessor (Sprint 16 refactoring).
 * Validates form access rules: password, denylist, allowlist, and ticket.
 *
 * @since 4.12.17
 */

namespace FreeFormCertificate\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AccessRestrictionChecker {

    /**
     * Check if submission passes restriction rules
     *
     * Validation order: Password → Denylist (priority) → Allowlist → Ticket (consumed)
     *
     * @param array $form_config Form configuration
     * @param string $val_cpf CPF/RF from form (already cleaned)
     * @param string $val_ticket Ticket from form
     * @param int $form_id Form ID (needed for ticket consumption)
     * @return array ['allowed' => bool, 'message' => string, 'is_ticket' => bool]
     */
    public static function check( array $form_config, string $val_cpf, string $val_ticket, int $form_id ): array {
        $restrictions = isset($form_config['restrictions']) ? $form_config['restrictions'] : array();

        // Clean CPF/RF (remove any mask)
        $clean_cpf = preg_replace('/\D/', '', $val_cpf);

        // ========================================
        // 1. PASSWORD CHECK (if active)
        // ========================================
        if (!empty($restrictions['password']) && $restrictions['password'] == '1') {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_submission_ajax() caller.
            $password = isset($_POST['ffc_password']) ? trim(sanitize_text_field(wp_unslash($_POST['ffc_password']))) : '';
            $valid_password = isset($form_config['validation_code']) ? $form_config['validation_code'] : '';

            if (empty($password)) {
                return array(
                    'allowed' => false,
                    'message' => __('Password is required.', 'ffcertificate'),
                    'is_ticket' => false
                );
            }

            if ($password !== $valid_password) {
                return array(
                    'allowed' => false,
                    'message' => __('Incorrect password.', 'ffcertificate'),
                    'is_ticket' => false
                );
            }
        }

        // ========================================
        // 2. DENYLIST CHECK (if active - HAS PRIORITY)
        // ========================================
        if (!empty($restrictions['denylist']) && $restrictions['denylist'] == '1') {
            $denied_raw = isset($form_config['denied_users_list']) ? $form_config['denied_users_list'] : '';
            $denied_list = array_filter(array_map('trim', explode("\n", $denied_raw)));

            // Clean masks from denylist before comparing
            $denied_clean = array_map(function($d) {
                return preg_replace('/\D/', '', $d);
            }, $denied_list);

            if (in_array($clean_cpf, $denied_clean)) {
                return array(
                    'allowed' => false,
                    'message' => __('Your CPF/RF is blocked.', 'ffcertificate'),
                    'is_ticket' => false
                );
            }
        }

        // ========================================
        // 3. ALLOWLIST CHECK (if active)
        // ========================================
        if (!empty($restrictions['allowlist']) && $restrictions['allowlist'] == '1') {
            $allowed_raw = isset($form_config['allowed_users_list']) ? $form_config['allowed_users_list'] : '';
            $allowed_list = array_filter(array_map('trim', explode("\n", $allowed_raw)));

            // Clean masks from allowlist before comparing
            $allowed_clean = array_map(function($a) {
                return preg_replace('/\D/', '', $a);
            }, $allowed_list);

            if (!in_array($clean_cpf, $allowed_clean)) {
                return array(
                    'allowed' => false,
                    'message' => __('Your CPF/RF is not authorized.', 'ffcertificate'),
                    'is_ticket' => false
                );
            }
        }

        // ========================================
        // 4. TICKET CHECK (if active - CONSUMED)
        // ========================================
        if (!empty($restrictions['ticket']) && $restrictions['ticket'] == '1') {
            $ticket = strtoupper(trim($val_ticket));

            if (empty($ticket)) {
                return array(
                    'allowed' => false,
                    'message' => __('Ticket code is required.', 'ffcertificate'),
                    'is_ticket' => false
                );
            }

            $tickets_raw = isset($form_config['generated_codes_list']) ? $form_config['generated_codes_list'] : '';
            $tickets = array_filter(array_map(function($t) {
                return strtoupper(trim($t));
            }, explode("\n", $tickets_raw)));

            if (!in_array($ticket, $tickets)) {
                return array(
                    'allowed' => false,
                    'message' => __('Invalid or already used ticket.', 'ffcertificate'),
                    'is_ticket' => false
                );
            }

            // Consume ticket (remove from list)
            $tickets = array_diff($tickets, array($ticket));
            $form_config['generated_codes_list'] = implode("\n", $tickets);
            update_post_meta($form_id, '_ffc_form_config', $form_config);

            return array(
                'allowed' => true,
                'message' => '',
                'is_ticket' => true
            );
        }

        // ========================================
        // NO RESTRICTIONS ACTIVE - ALLOW
        // ========================================
        return array(
            'allowed' => true,
            'message' => '',
            'is_ticket' => false
        );
    }

    /**
     * Remove used ticket from form configuration
     *
     * @param int $form_id Form ID
     * @param string $ticket Ticket code to consume
     */
    public static function consume_ticket( int $form_id, string $ticket ): void {
        $current_config = get_post_meta( $form_id, '_ffc_form_config', true );
        $current_raw_codes = isset( $current_config['generated_codes_list'] ) ? $current_config['generated_codes_list'] : '';
        $current_list = array_filter( array_map( 'trim', explode( "\n", $current_raw_codes ) ) );
        $updated_list = array_diff( $current_list, array( $ticket ) );
        $current_config['generated_codes_list'] = implode( "\n", $updated_list );
        update_post_meta( $form_id, '_ffc_form_config', $current_config );
    }
}

<?php
declare(strict_types=1);

/**
 * AJAX Helper Trait
 *
 * Shared AJAX utilities used across AdminAjax, AudienceLoader,
 * and AppointmentAjaxHandler.
 *
 * Eliminates duplicated code for:
 * - Nonce verification with multiple action fallbacks
 * - Permission checks with JSON error responses
 * - POST parameter sanitization helpers
 *
 * @since 4.11.2
 * @package FreeFormCertificate\Core
 */

namespace FreeFormCertificate\Core;

if (!defined('ABSPATH')) {
    exit;
}

trait AjaxTrait {

    /**
     * Verify AJAX nonce from POST data.
     *
     * Each AJAX handler must use a single, specific nonce action.
     * Sends wp_send_json_error() and dies if verification fails.
     *
     * @since 4.11.2
     * @since 5.1.1 Accepts only a single nonce action (array fallback removed for security).
     *
     * @param string $action Nonce action name to verify.
     * @param string $field  POST field name containing the nonce value.
     * @return void Dies with JSON error if nonce is invalid.
     */
    protected function verify_ajax_nonce(string $action, string $field = 'nonce'): void {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- isset() existence check only; nonce verified immediately inside.
        if (!isset($_POST[$field])) {
            wp_send_json_error(array('message' => __('Security check failed. Please reload the page.', 'ffcertificate')));
        }

        $nonce_value = sanitize_text_field(wp_unslash($_POST[$field]));

        if (!wp_verify_nonce($nonce_value, $action)) {
            wp_send_json_error(array('message' => __('Security check failed. Please reload the page.', 'ffcertificate')));
        }
    }

    /**
     * Check that the current user has admin-level permission.
     *
     * Sends wp_send_json_error() and dies if check fails.
     *
     * @param string $capability Capability to check (default: 'manage_options').
     * @return void Dies with JSON error if permission denied.
     */
    protected function check_ajax_permission(string $capability = 'manage_options'): void {
        if ($capability === 'manage_options' && class_exists('\FreeFormCertificate\Core\Utils')) {
            if (!Utils::current_user_can_manage()) {
                wp_send_json_error(array('message' => __('Permission denied.', 'ffcertificate')));
            }
            return;
        }

        if (!current_user_can($capability)) {
            wp_send_json_error(array('message' => __('Permission denied.', 'ffcertificate')));
        }
    }

    /**
     * Get a sanitized string parameter from POST data.
     *
     * @param string $key     POST parameter name.
     * @param string $default Default value if missing.
     * @return string Sanitized value.
     */
    protected function get_post_param(string $key, string $default = ''): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by the calling method.
        return isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : $default;
    }

    /**
     * Get an integer parameter from POST data.
     *
     * @param string $key     POST parameter name.
     * @param int    $default Default value if missing.
     * @return int Sanitized integer value.
     */
    protected function get_post_int(string $key, int $default = 0): int {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by the calling method.
        return isset($_POST[$key]) ? absint(wp_unslash($_POST[$key])) : $default;
    }

    /**
     * Get a sanitized array parameter from POST data.
     *
     * @param string $key POST parameter name.
     * @return array<string> Sanitized string values, or empty array.
     */
    protected function get_post_array(string $key): array {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Missing -- is_array() is a type check; sanitize_text_field() applied to each element. Nonce verified by the calling method.
        if (!isset($_POST[$key]) || !is_array($_POST[$key])) {
            return array();
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by the calling method.
        return array_map('sanitize_text_field', wp_unslash($_POST[$key]));
    }

    /**
     * Handle uncaught exceptions in AJAX handlers.
     *
     * Logs the error via Utils::debug_log() and sends a generic JSON error
     * to prevent internal details from leaking to the frontend.
     *
     * @since 5.1.1
     *
     * @param \Throwable $e The caught exception.
     * @return void Dies with JSON error.
     */
    protected function handle_ajax_exception(\Throwable $e): void {
        if (class_exists('\FreeFormCertificate\Core\Utils')) {
            Utils::debug_log('AJAX error', array(
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ));
        }
        wp_send_json_error(array(
            'code'    => 'ffc_internal_error',
            'message' => __('An unexpected error occurred.', 'ffcertificate'),
        ));
    }
}

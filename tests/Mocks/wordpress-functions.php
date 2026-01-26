<?php
/**
 * Mock WordPress Functions for Testing
 *
 * Provides minimal WordPress function mocks for unit testing
 *
 * @package FreeFormCertificate
 * @subpackage Tests
 */

// Prevent direct access
if (!defined('FFC_TESTS_DIR')) {
    exit;
}

/**
 * Mock WordPress functions used by the plugin
 */

if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text;
    }
}

if (!function_exists('_e')) {
    function _e($text, $domain = 'default') {
        echo $text;
    }
}

if (!function_exists('_x')) {
    function _x($text, $context, $domain = 'default') {
        return $text;
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default') {
        return esc_html(__($text, $domain));
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr__')) {
    function esc_attr__($text, $domain = 'default') {
        return esc_attr(__($text, $domain));
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url) {
        return filter_var($url, FILTER_SANITIZE_URL);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return strip_tags($str);
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        $key = strtolower($key);
        return preg_replace('/[^a-z0-9_\-]/', '', $key);
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value) {
        return stripslashes_deep($value);
    }
}

if (!function_exists('stripslashes_deep')) {
    function stripslashes_deep($value) {
        return is_array($value) ? array_map('stripslashes_deep', $value) : stripslashes($value);
    }
}

if (!function_exists('wp_kses_post')) {
    function wp_kses_post($data) {
        return strip_tags($data, '<p><a><strong><em><ul><ol><li><br><h1><h2><h3><h4><h5><h6>');
    }
}

if (!function_exists('wp_kses')) {
    function wp_kses($string, $allowed_html, $allowed_protocols = []) {
        if (is_array($allowed_html)) {
            // Extract allowed tags
            $allowed_tags = '<' . implode('><', array_keys($allowed_html)) . '>';
            return strip_tags($string, $allowed_tags);
        }
        return strip_tags($string);
    }
}

if (!function_exists('is_admin')) {
    function is_admin() {
        return false;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($capability) {
        return true;
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id() {
        return 1;
    }
}

if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action = -1) {
        return true;
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1) {
        return 'test_nonce_' . md5($action);
    }
}

if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
        return true;
    }
}

if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
        return true;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) {
        return $value;
    }
}

if (!function_exists('do_action')) {
    function do_action($hook) {
        return null;
    }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        return $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value) {
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($option) {
        return true;
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        private $errors = [];
        private $error_data = [];

        public function __construct($code = '', $message = '', $data = '') {
            if (empty($code)) {
                return;
            }
            $this->errors[$code][] = $message;
            if (!empty($data)) {
                $this->error_data[$code] = $data;
            }
        }

        public function add($code, $message, $data = '') {
            $this->errors[$code][] = $message;
            if (!empty($data)) {
                $this->error_data[$code] = $data;
            }
        }

        public function get_error_code() {
            $codes = array_keys($this->errors);
            return empty($codes) ? '' : $codes[0];
        }

        public function get_error_message($code = '') {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            if (!isset($this->errors[$code])) {
                return '';
            }
            return $this->errors[$code][0];
        }

        public function get_error_messages($code = '') {
            if (empty($code)) {
                return array_reduce($this->errors, 'array_merge', []);
            }
            return isset($this->errors[$code]) ? $this->errors[$code] : [];
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return ($thing instanceof WP_Error);
    }
}

if (!function_exists('is_email')) {
    function is_email($email, $deprecated = false) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}

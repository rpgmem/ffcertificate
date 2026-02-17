<?php
declare(strict_types=1);

/**
 * User Certificates REST Controller
 *
 * Handles:
 *   GET /user/certificates – Current user's certificates
 *
 * @since 4.12.7  Extracted from UserDataRestController
 * @package FreeFormCertificate\API
 */

namespace FreeFormCertificate\API;

if (!defined('ABSPATH')) exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

class UserCertificatesRestController {

    use UserContextTrait;

    /**
     * API namespace
     */
    private string $namespace;

    public function __construct(string $namespace) {
        $this->namespace = $namespace;
    }

    /**
     * Register routes
     */
    public function register_routes(): void {
        register_rest_route($this->namespace, '/user/certificates', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array($this, 'get_user_certificates'),
            'permission_callback' => 'is_user_logged_in',
        ));
    }

    /**
     * GET /user/certificates
     *
     * @since 3.1.0
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_user_certificates($request) {
        try {
            $ctx = $this->resolve_user_context($request);
            $user_id = $ctx['user_id'];

            if (!$user_id) {
                return new \WP_Error(
                    'not_logged_in',
                    __('You must be logged in to view certificates', 'ffcertificate'),
                    array('status' => 401)
                );
            }

            if (!$this->user_has_capability('view_own_certificates', $user_id, $ctx['is_view_as'])) {
                return new \WP_Error(
                    'capability_denied',
                    __('You do not have permission to view certificates', 'ffcertificate'),
                    array('status' => 403)
                );
            }

            global $wpdb;

            if (!class_exists('\FreeFormCertificate\Core\Utils')) {
                return new \WP_Error('missing_class', __('FFC_Utils class not found', 'ffcertificate'), array('status' => 500));
            }

            $table = \FreeFormCertificate\Core\Utils::get_submissions_table();

            $settings = get_option('ffc_settings', array());
            $date_format = $settings['date_format'] ?? 'F j, Y';

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $submissions = $wpdb->get_results($wpdb->prepare(
                "SELECT s.*, p.post_title as form_title
                 FROM %i s
                 LEFT JOIN %i p ON s.form_id = p.ID
                 WHERE s.user_id = %d
                 AND s.status != 'trash'
                 ORDER BY s.submission_date DESC",
                $table,
                $wpdb->posts,
                $user_id
            ), ARRAY_A);

            // Check per-capability permissions for the target user
            $can_download = $this->user_has_capability('download_own_certificates', $user_id, $ctx['is_view_as']);
            $can_view_history = $this->user_has_capability('view_certificate_history', $user_id, $ctx['is_view_as']);

            $certificates = array();

            foreach ($submissions as $submission) {
                $email_plain = \FreeFormCertificate\Core\Encryption::decrypt_field($submission, 'email');
                $email_display = ($email_plain !== '') ? \FreeFormCertificate\Core\Utils::mask_email($email_plain) : '';

                $verification_page_id = get_option('ffc_verification_page_id');
                $verification_url = $verification_page_id ? get_permalink((int) $verification_page_id) : home_url('/valid');

                $magic_link = '';
                if (!empty($submission['magic_token'])) {
                    $magic_link = add_query_arg('token', $submission['magic_token'], $verification_url);
                }

                $auth_code_formatted = '';
                if (!empty($submission['auth_code'])) {
                    $auth_code_formatted = \FreeFormCertificate\Core\Utils::format_auth_code($submission['auth_code']);
                }

                $date_formatted = '';
                if (!empty($submission['submission_date'])) {
                    $timestamp = strtotime($submission['submission_date']);
                    $date_formatted = ($timestamp !== false) ? date_i18n($date_format, $timestamp) : $submission['submission_date'];
                }

                $certificates[] = array(
                    'id' => (int) ($submission['id'] ?? 0),
                    'form_id' => (int) ($submission['form_id'] ?? 0),
                    'form_title' => $submission['form_title'] ?? __('Unknown Form', 'ffcertificate'),
                    'submission_date' => $date_formatted ?: '',
                    'submission_date_raw' => $submission['submission_date'] ?? '',
                    'consent_given' => !empty($submission['consent_given']),
                    'email' => $email_display,
                    'auth_code' => $auth_code_formatted,
                    'magic_link' => $can_download ? $magic_link : '',
                    'pdf_url' => $can_download ? $magic_link : '',
                );
            }

            // When view_certificate_history is disabled, keep only the most recent per form
            if (!$can_view_history && !empty($certificates)) {
                $seen_forms = array();
                $filtered = array();
                foreach ($certificates as $cert) {
                    if (!isset($seen_forms[$cert['form_id']])) {
                        $seen_forms[$cert['form_id']] = true;
                        $filtered[] = $cert;
                    }
                }
                $certificates = $filtered;
            }

            return rest_ensure_response(array(
                'certificates' => $certificates,
                'total' => count($certificates),
            ));

        } catch (\Exception $e) {
            if (class_exists('\FreeFormCertificate\Core\Utils')) {
                \FreeFormCertificate\Core\Utils::debug_log('get_user_certificates error', array(
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ));
            }
            return new \WP_Error(
                'get_certificates_error',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }
}

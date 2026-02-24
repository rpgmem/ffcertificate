<?php
declare(strict_types=1);

/**
 * User Download REST Controller
 *
 * Handles:
 *   POST /user/download-pdf – Generate PDF data for a document by magic token
 *
 * This endpoint replaces the admin-ajax.php ffc_verify_magic_token call
 * for the dashboard download flow, using the REST API infrastructure
 * which is more reliable across different server configurations.
 *
 * @since 4.13.1
 * @package FreeFormCertificate\API
 */

namespace FreeFormCertificate\API;

if (!defined('ABSPATH')) exit;

class UserDownloadRestController {

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
        register_rest_route($this->namespace, '/user/download-pdf', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array($this, 'download_pdf'),
            'permission_callback' => 'is_user_logged_in',
            'args' => array(
                'token' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));
    }

    /**
     * POST /user/download-pdf
     *
     * Accepts a magic token and returns PDF data for client-side rendering.
     * Uses the same verification logic as ffc_verify_magic_token but through
     * the REST API, which is more reliable for authenticated users.
     *
     * @since 4.13.1
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function download_pdf($request) {
        $token = $request->get_param('token');

        if (empty($token)) {
            return new \WP_Error(
                'missing_token',
                __('Token is required.', 'ffcertificate'),
                array('status' => 400)
            );
        }

        // Rate limiting
        $user_ip = \FreeFormCertificate\Core\Utils::get_user_ip();
        $rate_check = \FreeFormCertificate\Security\RateLimiter::check_verification($user_ip);
        if (!$rate_check['allowed']) {
            return new \WP_Error(
                'rate_limited',
                __('Too many attempts. Please try again later.', 'ffcertificate'),
                array('status' => 429)
            );
        }

        // Validate token format
        if (!\FreeFormCertificate\Generators\MagicLinkHelper::is_valid_token($token)) {
            return new \WP_Error(
                'invalid_token',
                __('Invalid token format.', 'ffcertificate'),
                array('status' => 400)
            );
        }

        // Look up the document by token
        $submission_handler = new \FreeFormCertificate\Submissions\SubmissionHandler();
        $verification_handler = new \FreeFormCertificate\Frontend\VerificationHandler($submission_handler);
        $result = $verification_handler->verify_by_magic_token($token);

        if (!$result['found']) {
            $error_msg = isset($result['error']) && $result['error'] === 'rate_limited'
                ? __('Too many attempts. Please try again in 1 minute.', 'ffcertificate')
                : __('Document not found or invalid link.', 'ffcertificate');

            return new \WP_Error(
                'not_found',
                $error_msg,
                array('status' => 404)
            );
        }

        // Generate PDF data
        $pdf_generator = new \FreeFormCertificate\Generators\PdfGenerator();

        if (!empty($result['type']) && $result['type'] === 'appointment' && !empty($result['appointment'])) {
            $renderer = new \FreeFormCertificate\Frontend\VerificationResponseRenderer();
            $pdf_data = $renderer->generate_appointment_verification_pdf($result, $pdf_generator);
        } elseif (!empty($result['type']) && $result['type'] === 'reregistration') {
            $pdf_data = \FreeFormCertificate\Reregistration\FichaGenerator::generate_ficha_data(
                (int) $result['reregistration']['submission_id']
            );
        } else {
            $pdf_data = $pdf_generator->generate_pdf_data(
                (int) $result['submission']->id,
                $submission_handler
            );
        }

        if (is_wp_error($pdf_data)) {
            return new \WP_Error(
                'pdf_error',
                $pdf_data->get_error_message(),
                array('status' => 500)
            );
        }

        return rest_ensure_response(array(
            'pdf_data' => $pdf_data,
        ));
    }
}

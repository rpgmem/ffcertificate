<?php
declare(strict_types=1);

/**
 * Submission REST Controller
 *
 * Handles submission-related REST API endpoints:
 *   GET  /submissions      – List submissions (admin)
 *   GET  /submissions/{id} – Get single submission (admin)
 *   POST /verify           – Verify certificate by auth code
 *
 * @since 4.6.1
 * @package FreeFormCertificate\API
 */

namespace FreeFormCertificate\API;

use FreeFormCertificate\Repositories\SubmissionRepository;

if (!defined('ABSPATH')) exit;

class SubmissionRestController {

    /**
     * API namespace
     */
    private string $namespace;

    /**
     * Submission repository
     */
    private ?SubmissionRepository $submission_repository;

    /**
     * Constructor
     *
     * @param string                    $namespace             API namespace.
     * @param SubmissionRepository|null $submission_repository Submission repository instance.
     */
    public function __construct(string $namespace, ?SubmissionRepository $submission_repository) {
        $this->namespace = $namespace;
        $this->submission_repository = $submission_repository;
    }

    /**
     * Register routes
     */
    public function register_routes(): void {
        // GET /submissions - List submissions (admin only)
        register_rest_route($this->namespace, '/submissions', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array($this, 'get_submissions'),
            'permission_callback' => array($this, 'check_admin_permission'),
            'args' => array(
                'page' => array(
                    'default' => 1,
                    'sanitize_callback' => 'absint',
                ),
                'per_page' => array(
                    'default' => 20,
                    'sanitize_callback' => 'absint',
                ),
                'status' => array(
                    'default' => 'publish',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'search' => array(
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));

        // GET /submissions/{id} - Get single submission (admin only)
        register_rest_route($this->namespace, '/submissions/(?P<id>\d+)', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array($this, 'get_submission'),
            'permission_callback' => array($this, 'check_admin_permission'),
            'args' => array(
                'id' => array(
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    },
                ),
            ),
        ));

        // POST /verify - Verify certificate by auth code
        register_rest_route($this->namespace, '/verify', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array($this, 'verify_certificate'),
            'permission_callback' => '__return_true',
            'args' => array(
                'auth_code' => array(
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function($param) {
                        return strlen($param) >= 12;
                    },
                ),
            ),
        ));
    }

    /**
     * GET /submissions
     * List submissions with pagination (admin only)
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_submissions($request) {
        try {
            if (!$this->submission_repository) {
                return new \WP_Error(
                    'repository_not_found',
                    'Submission repository not available',
                    array('status' => 500)
                );
            }

            $page = $request->get_param('page');
            $per_page = $request->get_param('per_page');
            $status = $request->get_param('status');
            $search = $request->get_param('search');

            $args = array(
                'page' => $page,
                'per_page' => $per_page,
                'status' => $status,
                'search' => $search,
                'orderby' => 'id',
                'order' => 'DESC'
            );

            $result = $this->submission_repository->findPaginated($args);

            $submissions = array();
            foreach ($result['items'] as $item) {
                $data = array();
                if (!empty($item['data'])) {
                    $data = json_decode($item['data'], true);
                }

                $submissions[] = array(
                    'id' => (int) $item['id'],
                    'form_id' => (int) $item['form_id'],
                    'auth_code' => \FreeFormCertificate\Core\Utils::format_auth_code($item['auth_code']),
                    'submission_date' => $item['submission_date'],
                    'status' => $item['status'],
                    'email' => !empty($item['email']) ? $item['email'] : null,
                    'cpf_rf' => !empty($item['cpf_rf']) ? \FreeFormCertificate\Core\Utils::mask_cpf($item['cpf_rf']) : null,
                    'data' => $data,
                );
            }

            $response = array(
                'items' => $submissions,
                'total' => $result['total'],
                'pages' => $result['pages'],
                'current_page' => $page,
                'per_page' => $per_page,
            );

            return rest_ensure_response($response);

        } catch (\Exception $e) {
            $this->log_rest_error( 'get_submissions', $e );
            return new \WP_Error(
                'ffc_internal_error',
                __( 'An unexpected error occurred.', 'ffcertificate' ),
                array( 'status' => 500 )
            );
        }
    }

    /**
     * GET /submissions/{id}
     * Get single submission (admin only)
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_submission($request) {
        try {
            $submission_id = $request->get_param('id');

            if (!$this->submission_repository) {
                return new \WP_Error(
                    'repository_not_found',
                    'Submission repository not available',
                    array('status' => 500)
                );
            }

            $submission = $this->submission_repository->findById($submission_id);

            if (!$submission) {
                return new \WP_Error(
                    'submission_not_found',
                    'Submission not found',
                    array('status' => 404)
                );
            }

            $data = array();
            if (!empty($submission['data'])) {
                $data = json_decode($submission['data'], true);
            }

            // Decrypt if encrypted
            $data = $this->decrypt_submission_data($submission, $data);

            $form = get_post((int) $submission['form_id']);
            $form_title = $form ? $form->post_title : 'Unknown Form';

            $response = array(
                'id' => (int) $submission['id'],
                'form_id' => (int) $submission['form_id'],
                'form_title' => $form_title,
                'auth_code' => \FreeFormCertificate\Core\Utils::format_auth_code($submission['auth_code']),
                'submission_date' => $submission['submission_date'],
                'status' => $submission['status'],
                'email' => !empty($submission['email']) ? $submission['email'] : null,
                'cpf_rf' => !empty($submission['cpf_rf']) ? $submission['cpf_rf'] : null,
                'data' => $data,
            );

            return rest_ensure_response($response);

        } catch (\Exception $e) {
            $this->log_rest_error( 'get_submission', $e );
            return new \WP_Error(
                'ffc_internal_error',
                __( 'An unexpected error occurred.', 'ffcertificate' ),
                array( 'status' => 500 )
            );
        }
    }

    /**
     * POST /verify
     * Verify certificate by auth code
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function verify_certificate($request) {
        try {
            $auth_code = $request->get_param('auth_code');
            $auth_code = \FreeFormCertificate\Core\Utils::clean_auth_code($auth_code);

            if (!$this->submission_repository) {
                return new \WP_Error(
                    'repository_not_found',
                    'Submission repository not available',
                    array('status' => 500)
                );
            }

            $submission = $this->submission_repository->findByAuthCode($auth_code);

            if (!$submission) {
                return new \WP_Error(
                    'certificate_not_found',
                    'Certificate not found. Please check the authentication code.',
                    array('status' => 404)
                );
            }

            if (isset($submission['status']) && $submission['status'] === 'trash') {
                return new \WP_Error(
                    'certificate_deleted',
                    'This certificate has been deleted.',
                    array('status' => 410)
                );
            }

            $data = array();
            if (!empty($submission['data'])) {
                $data = json_decode($submission['data'], true);
            }

            $data = $this->decrypt_submission_data($submission, $data);

            $form = get_post((int) $submission['form_id']);
            $form_title = $form ? $form->post_title : 'Unknown Form';

            $response = array(
                'valid' => true,
                'auth_code' => \FreeFormCertificate\Core\Utils::format_auth_code($auth_code),
                'certificate' => array(
                    'id' => (int) $submission['id'],
                    'form_id' => (int) $submission['form_id'],
                    'form_title' => $form_title,
                    'submission_date' => $submission['submission_date'],
                    'status' => $submission['status'],
                    'data' => $data,
                ),
                'message' => __( 'Certificate is valid and authentic.', 'ffcertificate' )
            );

            if (!empty($submission['email'])) {
                $response['certificate']['email'] = $submission['email'];
            }

            if (!empty($submission['cpf_rf'])) {
                $response['certificate']['cpf_rf'] = \FreeFormCertificate\Core\Utils::mask_cpf($submission['cpf_rf']);
            }

            return rest_ensure_response($response);

        } catch (\Exception $e) {
            $this->log_rest_error( 'verify_certificate', $e );
            return new \WP_Error(
                'ffc_internal_error',
                __( 'An unexpected error occurred.', 'ffcertificate' ),
                array( 'status' => 500 )
            );
        }
    }

    /**
     * Check admin permission
     */
    public function check_admin_permission(): bool {
        return current_user_can('edit_posts');
    }

    /**
     * Decrypt submission data if encrypted
     *
     * @since 3.2.0
     * @param array $submission Submission data array
     * @param array $data Existing data array to merge into
     * @return array Merged data array with decrypted values
     */
    private function decrypt_submission_data(array $submission, array $data = array()): array {
        if (empty($submission['data_encrypted'])) {
            return $data;
        }

        if (!class_exists('\FreeFormCertificate\Core\Encryption')) {
            return $data;
        }

        try {
            $decrypted = \FreeFormCertificate\Core\Encryption::decrypt($submission['data_encrypted']);
            $decrypted_data = json_decode($decrypted, true);

            if (is_array($decrypted_data)) {
                return array_merge($data, $decrypted_data);
            }
        } catch (\Exception $e) {
            if (class_exists('\FreeFormCertificate\Core\Debug')) {
                \FreeFormCertificate\Core\Debug::log_rest_api('Decryption failed', $e->getMessage());
            }
        }

        return $data;
    }

    /**
     * Log REST API error without exposing details to clients.
     *
     * @since 4.6.6
     * @param string     $context Action that caused the error.
     * @param \Exception $e       The exception.
     */
    private function log_rest_error( string $context, \Exception $e ): void {
        if ( class_exists( '\FreeFormCertificate\Core\Utils' ) ) {
            \FreeFormCertificate\Core\Utils::debug_log( "REST API error: {$context}", array(
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ) );
        }
    }
}

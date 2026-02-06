<?php
declare(strict_types=1);

/**
 * RestController
 *
 * Base controller for all REST API endpoints
 * Namespace: /wp-json/ffc/v1/
 *
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 * @since 3.0.0
 */

namespace FreeFormCertificate\API;

use FreeFormCertificate\Repositories\FormRepository;
use FreeFormCertificate\Repositories\SubmissionRepository;

if (!defined('ABSPATH')) exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

class RestController {

    /**
     * API namespace
     */
    private string $namespace = 'ffc/v1';

    /**
     * Repositories
     */
    private ?FormRepository $form_repository = null;
    private ?SubmissionRepository $submission_repository = null;

    /**
     * Constructor
     */
    public function __construct() {
        // Initialize repositories
        $this->form_repository = new FormRepository();
        $this->submission_repository = new SubmissionRepository();
        
        // Register REST routes
        add_action('rest_api_init', array($this, 'register_routes'));

        // Suppress PHP notices/warnings in REST API responses to prevent JSON corruption
        add_action('rest_api_init', array($this, 'suppress_rest_api_notices'));
    }

    /**
     * Suppress PHP notices in REST API to prevent JSON corruption
     * Fixes: parsererror when notices are output before JSON
     */
    public function suppress_rest_api_notices(): void {
        // Only suppress in REST API context
        if (defined('REST_REQUEST') && REST_REQUEST) {
            // Start output buffering to catch any stray output
            if (!ob_get_level()) {
                ob_start();
            }

            // Set error reporting to only show errors, not warnings/notices
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting
            error_reporting(E_ERROR | E_PARSE);

            // Clean output buffer before sending response
            add_filter('rest_pre_serve_request', function($served, $result, $request, $server) {
                // Clean any output that was buffered (like PHP notices)
                if (ob_get_level()) {
                    ob_clean();
                }
                return $served;
            }, 10, 4);
        }
    }

    /**
     * Register all REST routes
     */
    public function register_routes(): void {
        
        // GET /forms - List all published forms
        register_rest_route($this->namespace, '/forms', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array($this, 'get_forms'),
            'permission_callback' => '__return_true', // Public endpoint
            'args' => array(
                'limit' => array(
                    'default' => -1,
                    'sanitize_callback' => 'absint',
                ),
            ),
        ));
        
        // GET /forms/{id} - Get single form
        register_rest_route($this->namespace, '/forms/(?P<id>\d+)', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array($this, 'get_form'),
            'permission_callback' => '__return_true', // Public endpoint
            'args' => array(
                'id' => array(
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    },
                ),
            ),
        ));
        
        // POST /forms/{id}/submit - Submit a form
        register_rest_route($this->namespace, '/forms/(?P<id>\d+)/submit', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array($this, 'submit_form'),
            'permission_callback' => '__return_true', // Public endpoint with rate limiting
            'args' => array(
                'id' => array(
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    },
                ),
            ),
        ));
        
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
            'permission_callback' => '__return_true', // Public endpoint
            'args' => array(
                'auth_code' => array(
                    'required' => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function($param) {
                        // Auth code format: XXXX-XXXX-XXXX (12 chars + 2 dashes)
                        return strlen($param) >= 12;
                    },
                ),
            ),
        ));

        // GET /user/certificates - Get current user's certificates (v3.1.0)
        register_rest_route($this->namespace, '/user/certificates', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array($this, 'get_user_certificates'),
            'permission_callback' => 'is_user_logged_in', // Requires logged in user
        ));

        // GET /user/profile - Get current user's profile data (v3.1.0)
        register_rest_route($this->namespace, '/user/profile', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array($this, 'get_user_profile'),
            'permission_callback' => 'is_user_logged_in', // Requires logged in user
        ));

        // GET /user/appointments - Get current user's appointments (v4.1.0)
        register_rest_route($this->namespace, '/user/appointments', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array($this, 'get_user_appointments'),
            'permission_callback' => 'is_user_logged_in', // Requires logged in user
        ));

        // GET /calendars - List all active calendars (v4.1.0)
        register_rest_route($this->namespace, '/calendars', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array($this, 'get_calendars'),
            'permission_callback' => '__return_true', // Public endpoint
        ));

        // GET /calendars/{id} - Get calendar details (v4.1.0)
        register_rest_route($this->namespace, '/calendars/(?P<id>\d+)', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array($this, 'get_calendar'),
            'permission_callback' => '__return_true', // Public endpoint
            'args' => array(
                'id' => array(
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    },
                ),
            ),
        ));

        // GET /calendars/{id}/slots - Get available time slots (v4.1.0)
        register_rest_route($this->namespace, '/calendars/(?P<id>\d+)/slots', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array($this, 'get_calendar_slots'),
            'permission_callback' => '__return_true', // Public endpoint
            'args' => array(
                'id' => array(
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    },
                ),
                'date' => array(
                    'required' => true,
                    'validate_callback' => function($param) {
                        return (bool) strtotime($param);
                    },
                ),
            ),
        ));

        // POST /calendars/{id}/appointments - Create appointment (v4.1.0)
        register_rest_route($this->namespace, '/calendars/(?P<id>\d+)/appointments', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array($this, 'create_appointment'),
            'permission_callback' => '__return_true', // Public endpoint with validation
            'args' => array(
                'id' => array(
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    },
                ),
            ),
        ));

        // GET /appointments/{id} - Get appointment details (v4.1.0)
        register_rest_route($this->namespace, '/appointments/(?P<id>\d+)', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array($this, 'get_appointment'),
            'permission_callback' => array($this, 'check_appointment_access'),
            'args' => array(
                'id' => array(
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    },
                ),
            ),
        ));

        // DELETE /appointments/{id} - Cancel appointment (v4.1.0)
        register_rest_route($this->namespace, '/appointments/(?P<id>\d+)', array(
            'methods' => \WP_REST_Server::DELETABLE,
            'callback' => array($this, 'cancel_appointment'),
            'permission_callback' => array($this, 'check_appointment_access'),
            'args' => array(
                'id' => array(
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    },
                ),
            ),
        ));

        // GET /user/audience-bookings - Get bookings where current user is affected (v4.5.0)
        register_rest_route($this->namespace, '/user/audience-bookings', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array($this, 'get_user_audience_bookings'),
            'permission_callback' => 'is_user_logged_in',
        ));
    }
    
    /**
     * GET /forms
     * List all published forms
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_forms($request) {
        try {
            $limit = $request->get_param('limit');
            
            if (!$this->form_repository) {
                return new \WP_Error(
                    'repository_not_found',
                    __('Form repository not available', 'wp-ffcertificate'),
                    array('status' => 500)
                );
            }
            
            $forms = $this->form_repository->findPublished($limit);
            
            // Format response
            $response = array();
            foreach ($forms as $form) {
                $response[] = array(
                    'id' => $form->ID,
                    'title' => $form->post_title,
                    'status' => $form->post_status,
                    'date' => $form->post_date,
                    'modified' => $form->post_modified,
                    'link' => get_permalink($form->ID),
                    'config' => $this->form_repository->getConfig($form->ID),
                );
            }
            
            return rest_ensure_response($response);
            
        } catch (\Exception $e) {
            return new \WP_Error(
                'get_forms_error',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }
    
    /**
     * GET /forms/{id}
     * Get single form details
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_form($request) {
        try {
            $form_id = $request->get_param('id');
            
            $form = get_post($form_id);
            
            if (!$form || $form->post_type !== 'ffc_form') {
                return new \WP_Error(
                    'form_not_found',
                    __('Form not found', 'wp-ffcertificate'),
                    array('status' => 404)
                );
            }
            
            if ($form->post_status !== 'publish') {
                return new \WP_Error(
                    'form_not_published',
                    __('Form is not published', 'wp-ffcertificate'),
                    array('status' => 403)
                );
            }
            
            $response = array(
                'id' => $form->ID,
                'title' => $form->post_title,
                'status' => $form->post_status,
                'date' => $form->post_date,
                'modified' => $form->post_modified,
                'config' => $this->form_repository->getConfig($form_id),
                'fields' => $this->form_repository->getFields($form_id),
                'background' => $this->form_repository->getBackground($form_id),
            );
            
            return rest_ensure_response($response);
            
        } catch (\Exception $e) {
            return new \WP_Error(
                'get_form_error',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }
    
    /**
     * POST /forms/{id}/submit
     * Submit a form via API
     * 
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response|\WP_Error Response with submission data or error
     */
    public function submit_form($request) {
        try {
            $form_id = $request->get_param('id');
            
            // Get all parameters from request body
            $params = $request->get_json_params();
            
            if (empty($params)) {
                return new \WP_Error(
                    'no_data',
                    'No data provided in request body',
                    array('status' => 400)
                );
            }
            
            // Verify form exists and is published
            $form = get_post($form_id);
            
            if (!$form || $form->post_type !== 'ffc_form') {
                return new \WP_Error(
                    'form_not_found',
                    'Form not found',
                    array('status' => 404)
                );
            }
            
            if ($form->post_status !== 'publish') {
                return new \WP_Error(
                    'form_not_published',
                    'Form is not published',
                    array('status' => 403)
                );
            }
            
            // Get form configuration and fields
            $form_config = $this->form_repository->getConfig($form_id);
            $form_fields = $this->form_repository->getFields($form_id);
            
            // Sanitize submission data using FFC_Utils
            $submission_data = \FreeFormCertificate\Core\Utils::recursive_sanitize($params);
            
            // Validate required fields
            $validation_errors = $this->validate_required_fields($submission_data, $form_fields);
            if (!empty($validation_errors)) {
                return new \WP_Error(
                    'validation_failed',
                    'Validation failed: ' . implode(', ', $validation_errors),
                    array('status' => 400, 'errors' => $validation_errors)
                );
            }
            
            // Validate CPF if present
            if (!empty($submission_data['cpf_rf'])) {
                $cpf = preg_replace('/[^0-9]/', '', $submission_data['cpf_rf']);
                
                if (strlen($cpf) === 11) {
                    // Validate CPF using FFC_Utils
                    if (class_exists('\FreeFormCertificate\Core\Utils') && !\FreeFormCertificate\Core\Utils::validate_cpf($cpf)) {
                        return new \WP_Error(
                            'invalid_cpf',
                            'Invalid CPF. Please check the number and try again.',
                            array('status' => 400)
                        );
                    }
                } elseif (strlen($cpf) === 7) {
                    // Validate RF
                    if (class_exists('\FreeFormCertificate\Core\Utils') && !\FreeFormCertificate\Core\Utils::validate_rf($cpf)) {
                        return new \WP_Error(
                            'invalid_rf',
                            'Invalid RF. Must contain only numbers.',
                            array('status' => 400)
                        );
                    }
                } else {
                    return new \WP_Error(
                        'invalid_cpf_rf',
                        'CPF/RF must be exactly 7 or 11 digits',
                        array('status' => 400)
                    );
                }
                
                $submission_data['cpf_rf'] = $cpf;
            }
            
            // Validate email if present
            if (!empty($submission_data['email'])) {
                if (!is_email($submission_data['email'])) {
                    return new \WP_Error(
                        'invalid_email',
                        'Invalid email address',
                        array('status' => 400)
                    );
                }
            }
            
            // Rate limiting check
            if (class_exists('\FreeFormCertificate\Security\RateLimiter')) {
                $rate_limiter = new \FreeFormCertificate\Security\RateLimiter();
                
                // Check IP rate limit using FFC_Utils
                $ip = \FreeFormCertificate\Core\Utils::get_user_ip();
                if (!$rate_limiter->check_limit('ip', $ip)) {
                    return new \WP_Error(
                        'rate_limit_exceeded',
                        'Too many requests. Please try again later.',
                        array('status' => 429)
                    );
                }
                
                // Check email rate limit
                if (!empty($submission_data['email'])) {
                    if (!$rate_limiter->check_limit('email', $submission_data['email'])) {
                        return new \WP_Error(
                            'rate_limit_exceeded',
                            'Too many submissions from this email. Please try again later.',
                            array('status' => 429)
                        );
                    }
                }
                
                // Check CPF rate limit
                if (!empty($submission_data['cpf_rf'])) {
                    if (!$rate_limiter->check_limit('cpf', $submission_data['cpf_rf'])) {
                        return new \WP_Error(
                            'rate_limit_exceeded',
                            'Too many submissions with this CPF/RF. Please try again later.',
                            array('status' => 429)
                        );
                    }
                }
            }
            
            // Use FFC_Submission_Handler to process submission
            if (!class_exists('\FreeFormCertificate\Submissions\SubmissionHandler')) {
                return new \WP_Error(
                    'handler_not_found',
                    'Submission handler not available',
                    array('status' => 500)
                );
            }
            
            $handler = new \FreeFormCertificate\Submissions\SubmissionHandler();
            
            // Process the submission
            $result = $handler->process_submission($form_id, $submission_data);
            
            if (is_wp_error($result)) {
                return $result;
            }
            
            // Build success response
            $response = array(
                'success' => true,
                'submission_id' => $result['submission_id'],
                'auth_code' => \FreeFormCertificate\Core\Utils::format_auth_code($result['auth_code']),
                'message' => 'Form submitted successfully',
            );
            
            // Add PDF URL if available
            if (!empty($result['pdf_url'])) {
                $response['pdf_url'] = $result['pdf_url'];
            }
            
            // Add validation URL
            $response['validation_url'] = home_url('/validate-certificate/');
            
            return rest_ensure_response($response);
            
        } catch (\Exception $e) {
            return new \WP_Error(
                'submission_error',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }
    
    /**
     * GET /submissions
     * List submissions with pagination (admin only)
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response|\WP_Error Response with submissions list or error
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
            
            // Get parameters
            $page = $request->get_param('page');
            $per_page = $request->get_param('per_page');
            $status = $request->get_param('status');
            $search = $request->get_param('search');
            
            // Build query args
            $args = array(
                'page' => $page,
                'per_page' => $per_page,
                'status' => $status,
                'search' => $search,
                'orderby' => 'id',
                'order' => 'DESC'
            );
            
            // Get paginated results from repository
            $result = $this->submission_repository->findPaginated($args);
            
            // Format submissions
            $submissions = array();
            foreach ($result['items'] as $item) {
                // Decode data
                $data = array();
                if (!empty($item['data'])) {
                    $data = json_decode($item['data'], true);
                }
                
                // Build submission object (convert IDs to int - wpdb returns strings)
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
            
            // Build response
            $response = array(
                'items' => $submissions,
                'total' => $result['total'],
                'pages' => $result['pages'],
                'current_page' => $page,
                'per_page' => $per_page,
            );
            
            return rest_ensure_response($response);
            
        } catch (\Exception $e) {
            return new \WP_Error(
                'get_submissions_error',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }
    
    /**
     * GET /submissions/{id}
     * Get single submission (admin only)
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response|\WP_Error Response with submission data or error
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
            
            // Find submission by ID
            $submission = $this->submission_repository->findById($submission_id);
            
            if (!$submission) {
                return new \WP_Error(
                    'submission_not_found',
                    'Submission not found',
                    array('status' => 404)
                );
            }
            
            // Decode data
            $data = array();
            if (!empty($submission['data'])) {
                $data = json_decode($submission['data'], true);
            }

            // Decrypt if encrypted
            $data = $this->decrypt_submission_data($submission, $data);

            // Get form details (convert form_id to int - wpdb returns strings)
            $form = get_post((int) $submission['form_id']);
            $form_title = $form ? $form->post_title : 'Unknown Form';

            // Build response (convert IDs to int for API consistency)
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
            return new \WP_Error(
                'get_submission_error',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }
    
    /**
     * POST /verify
     * Verify certificate by auth code
     *
     * @param \WP_REST_Request $request Request object
     * @return \WP_REST_Response|\WP_Error Response with submission data or error
     */
    public function verify_certificate($request) {
        try {
            $auth_code = $request->get_param('auth_code');
            
            // Clean auth code using FFC_Utils
            $auth_code = \FreeFormCertificate\Core\Utils::clean_auth_code($auth_code);
            
            if (!$this->submission_repository) {
                return new \WP_Error(
                    'repository_not_found',
                    'Submission repository not available',
                    array('status' => 500)
                );
            }
            
            // Find submission by auth code
            $submission = $this->submission_repository->findByAuthCode($auth_code);
            
            if (!$submission) {
                return new \WP_Error(
                    'certificate_not_found',
                    'Certificate not found. Please check the authentication code.',
                    array('status' => 404)
                );
            }
            
            // Check if submission is published (not trashed)
            if (isset($submission['status']) && $submission['status'] === 'trash') {
                return new \WP_Error(
                    'certificate_deleted',
                    'This certificate has been deleted.',
                    array('status' => 410) // 410 Gone
                );
            }
            
            // Decode submission data
            $data = array();
            if (!empty($submission['data'])) {
                $data = json_decode($submission['data'], true);
            }

            // Decrypt data if encrypted
            $data = $this->decrypt_submission_data($submission, $data);

            // Get form details (convert form_id to int - wpdb returns strings)
            $form = get_post((int) $submission['form_id']);
            $form_title = $form ? $form->post_title : 'Unknown Form';

            // Build response (convert IDs to int for API consistency)
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
                'message' => 'Certificate is valid and authentic.'
            );
            
            // Add email if available (hashed or plain)
            if (!empty($submission['email'])) {
                $response['certificate']['email'] = $submission['email'];
            }
            
            // Add CPF/RF if available (masked for privacy)
            if (!empty($submission['cpf_rf'])) {
                $response['certificate']['cpf_rf'] = \FreeFormCertificate\Core\Utils::mask_cpf($submission['cpf_rf']);
            }
            
            return rest_ensure_response($response);
            
        } catch (\Exception $e) {
            return new \WP_Error(
                'verify_error',
                $e->getMessage(),
                array('status' => 500)
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
     * Validate required fields
     *
     * @param array $data Submission data
     * @param array $fields Form fields configuration
     * @return array Array of validation errors
     */
    private function validate_required_fields(array $data, array $fields): array {
        $errors = array();
        
        if (empty($fields) || !is_array($fields)) {
            return $errors;
        }
        
        foreach ($fields as $field) {
            // Check if field is required
            if (isset($field['required']) && $field['required']) {
                $field_name = isset($field['name']) ? $field['name'] : '';
                
                // Check if field exists in data and is not empty
                if (empty($field_name) || !isset($data[$field_name]) || trim($data[$field_name]) === '') {
                    $field_label = isset($field['label']) ? $field['label'] : $field_name;
                    $errors[] = $field_label . ' is required';
                }
            }
        }
        
        return $errors;
    }

    /**
     * GET /user/certificates
     * Get current user's certificates
     *
     * @since 3.1.0
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_user_certificates($request) {
        try {
            $user_id = get_current_user_id();

            if (!$user_id) {
                return new \WP_Error(
                    'not_logged_in',
                    __('You must be logged in to view certificates', 'wp-ffcertificate'),
                    array('status' => 401)
                );
            }

            // Check for admin view-as mode
            $view_as_user_id = $request->get_param('viewAsUserId');
            if ($view_as_user_id && current_user_can('manage_options')) {
                $user_id = absint($view_as_user_id);
            }

            // Check capability (admin always has access)
            if (!current_user_can('manage_options') && !current_user_can('view_own_certificates')) {
                return new \WP_Error(
                    'capability_denied',
                    __('You do not have permission to view certificates', 'wp-ffcertificate'),
                    array('status' => 403)
                );
            }

            global $wpdb;

            // Safety check for FFC_Utils
            if (!class_exists('\FreeFormCertificate\Core\Utils')) {
                return new \WP_Error('missing_class', 'FFC_Utils class not found', array('status' => 500));
            }

            $table = \FreeFormCertificate\Core\Utils::get_submissions_table();

            // Get date format from settings
            $settings = get_option('ffc_settings', array());
            $date_format = $settings['date_format'] ?? 'F j, Y';

            // Get all submissions for this user by user_id
            // Note: Email-based search not possible with encrypted emails
            // (each encryption produces different result by design)
            // User must run the "Link Users" migration to associate old certificates
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $submissions = $wpdb->get_results($wpdb->prepare(
                "SELECT s.*, p.post_title as form_title
                 FROM {$table} s
                 LEFT JOIN {$wpdb->posts} p ON s.form_id = p.ID
                 WHERE s.user_id = %d
                 AND s.status != 'trash'
                 ORDER BY s.submission_date DESC",
                $user_id
            ), ARRAY_A);

            // Format response
            $certificates = array();

            foreach ($submissions as $submission) {
                // Decrypt email
                $email_display = '';
                if (!empty($submission['email_encrypted'])) {
                    try {
                        $email_plain = \FreeFormCertificate\Core\Encryption::decrypt($submission['email_encrypted']);
                        // Check if decrypt returned valid string before masking
                        $email_display = ($email_plain && is_string($email_plain)) ? \FreeFormCertificate\Core\Utils::mask_email($email_plain) : '';
                    } catch (\Exception $e) {
                        $email_display = __('Error decrypting', 'wp-ffcertificate');
                    }
                } elseif (!empty($submission['email'])) {
                    // Fallback to plain email if not encrypted
                    $email_display = \FreeFormCertificate\Core\Utils::mask_email($submission['email']);
                }

                // Get verification page URL (convert option to int)
                $verification_page_id = get_option('ffc_verification_page_id');
                $verification_url = $verification_page_id ? get_permalink((int) $verification_page_id) : home_url('/valid');

                // Build magic link
                $magic_link = '';
                if (!empty($submission['magic_token'])) {
                    $magic_link = add_query_arg('token', $submission['magic_token'], $verification_url);
                }

                // Format auth code
                $auth_code_formatted = '';
                if (!empty($submission['auth_code'])) {
                    $auth_code_formatted = \FreeFormCertificate\Core\Utils::format_auth_code($submission['auth_code']);
                }

                // Format date using plugin settings (with safety check for strtotime)
                $date_formatted = '';
                if (!empty($submission['submission_date'])) {
                    $timestamp = strtotime($submission['submission_date']);
                    // Ensure timestamp is valid before passing to date_i18n
                    $date_formatted = ($timestamp !== false) ? date_i18n($date_format, $timestamp) : $submission['submission_date'];
                }

                // Convert IDs to int for API consistency (wpdb returns strings)
                $certificates[] = array(
                    'id' => (int) ($submission['id'] ?? 0),
                    'form_id' => (int) ($submission['form_id'] ?? 0),
                    'form_title' => $submission['form_title'] ?? __('Unknown Form', 'wp-ffcertificate'),
                    'submission_date' => $date_formatted ?: '',
                    'submission_date_raw' => $submission['submission_date'] ?? '',
                    'consent_given' => !empty($submission['consent_given']),
                    'email' => $email_display,
                    'auth_code' => $auth_code_formatted,
                    'magic_link' => $magic_link,
                    'pdf_url' => $magic_link,  // Same as magic_link
                );
            }

            return rest_ensure_response(array(
                'certificates' => $certificates,
                'total' => count($certificates),
            ));

        } catch (\Exception $e) {
            // Log the error for debugging
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

    /**
     * GET /user/profile
     * Get current user's profile data
     *
     * @since 3.1.0
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_user_profile($request) {
        try {
            $user_id = get_current_user_id();

            if (!$user_id) {
                return new \WP_Error(
                    'not_logged_in',
                    __('You must be logged in to view profile', 'wp-ffcertificate'),
                    array('status' => 401)
                );
            }

            // Check for admin view-as mode
            $view_as_user_id = $request->get_param('viewAsUserId');
            if ($view_as_user_id && current_user_can('manage_options')) {
                $user_id = absint($view_as_user_id);
            }

            // Load User Manager if needed
            if (!class_exists('\FreeFormCertificate\UserDashboard\UserManager')) {
                $user_manager_file = FFC_PLUGIN_DIR . 'includes/user-dashboard/class-ffc-user-manager.php';
                if (file_exists($user_manager_file)) {
                    require_once $user_manager_file;
                }
            }

            $user = get_user_by('id', $user_id);

            if (!$user) {
                return new \WP_Error(
                    'user_not_found',
                    __('User not found', 'wp-ffcertificate'),
                    array('status' => 404)
                );
            }

            // Get all CPF/RF values (masked)
            $cpfs_masked = array();
            if (class_exists('\FreeFormCertificate\UserDashboard\UserManager')) {
                $cpfs_masked = \FreeFormCertificate\UserDashboard\UserManager::get_user_cpfs_masked($user_id);
            }

            // Get all emails used
            $emails = array();
            if (class_exists('\FreeFormCertificate\UserDashboard\UserManager')) {
                $emails = \FreeFormCertificate\UserDashboard\UserManager::get_user_emails($user_id);
            }

            // Get all names used in submissions
            $names = array();
            if (class_exists('\FreeFormCertificate\UserDashboard\UserManager')) {
                $names = \FreeFormCertificate\UserDashboard\UserManager::get_user_names($user_id);
            }

            // Format member since date
            $member_since = '';
            if (!empty($user->user_registered)) {
                $settings = get_option('ffc_settings', array());
                $date_format = $settings['date_format'] ?? 'F j, Y';
                $timestamp = strtotime($user->user_registered);
                $member_since = ($timestamp !== false) ? date_i18n($date_format, $timestamp) : '';
            }

            return rest_ensure_response(array(
                'user_id' => $user_id,
                'display_name' => $user->display_name,
                'names' => $names,
                'email' => $user->user_email,
                'emails' => $emails,
                'cpf_masked' => !empty($cpfs_masked) ? $cpfs_masked[0] : __('Not found', 'wp-ffcertificate'),
                'cpfs_masked' => $cpfs_masked,
                'member_since' => $member_since,
                'roles' => $user->roles,
            ));

        } catch (\Exception $e) {
            return new \WP_Error(
                'get_profile_error',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }

    /**
     * GET /user/appointments
     * Get current user's appointments
     *
     * @since 4.1.0
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_user_appointments($request) {
        try {
            $user_id = get_current_user_id();

            if (!$user_id) {
                return new \WP_Error(
                    'not_logged_in',
                    __('You must be logged in to view appointments', 'wp-ffcertificate'),
                    array('status' => 401)
                );
            }

            // Check for admin view-as mode
            $view_as_user_id = $request->get_param('viewAsUserId');
            if ($view_as_user_id && current_user_can('manage_options')) {
                $user_id = absint($view_as_user_id);
            }

            // Check capability (admin always has access)
            if (!current_user_can('manage_options') && !current_user_can('ffc_view_self_scheduling')) {
                return new \WP_Error(
                    'capability_denied',
                    __('You do not have permission to view appointments', 'wp-ffcertificate'),
                    array('status' => 403)
                );
            }

            // Get appointment repository
            if (!class_exists('\FreeFormCertificate\Repositories\AppointmentRepository')) {
                return new \WP_Error(
                    'repository_not_found',
                    __('Appointment repository not available', 'wp-ffcertificate'),
                    array('status' => 500)
                );
            }

            // Check if calendar repository exists
            if (!class_exists('\FreeFormCertificate\Repositories\CalendarRepository')) {
                return new \WP_Error(
                    'calendar_repository_not_found',
                    __('Calendar repository not available', 'wp-ffcertificate'),
                    array('status' => 500)
                );
            }

            $appointment_repository = new \FreeFormCertificate\Repositories\AppointmentRepository();
            $calendar_repository = new \FreeFormCertificate\Repositories\CalendarRepository();

            // Get all appointments for this user
            $appointments = $appointment_repository->findByUserId($user_id);

            // Ensure appointments is an array
            if (!is_array($appointments)) {
                $appointments = array();
            }

            // Use WordPress date-only format (no time)
            $date_format = get_option('date_format', 'F j, Y');

            // Format response
            $appointments_formatted = array();

            foreach ($appointments as $appointment) {
                // Skip invalid appointments
                if (!is_array($appointment) || empty($appointment['id'])) {
                    continue;
                }

                // Get calendar info
                $calendar_title = __('Unknown Calendar', 'wp-ffcertificate');
                $calendar = null;
                if (!empty($appointment['calendar_id'])) {
                    try {
                        $calendar = $calendar_repository->findById((int)$appointment['calendar_id']);
                        if ($calendar && isset($calendar['title'])) {
                            $calendar_title = $calendar['title'];
                        }
                    } catch (\Exception $e) {
                        // Calendar not found or error - use default
                    }
                }

                // Format date
                $date_formatted = '';
                if (!empty($appointment['appointment_date'])) {
                    $timestamp = strtotime($appointment['appointment_date']);
                    $date_formatted = ($timestamp !== false) ? date_i18n($date_format, $timestamp) : $appointment['appointment_date'];
                }

                // Format time
                $time_formatted = '';
                if (!empty($appointment['start_time'])) {
                    $time_timestamp = strtotime($appointment['start_time']);
                    $time_formatted = ($time_timestamp !== false) ? date_i18n('H:i', $time_timestamp) : $appointment['start_time'];
                }

                // Decrypt email if encrypted
                $email_display = '';
                if (!empty($appointment['email_encrypted'])) {
                    try {
                        if (class_exists('\FreeFormCertificate\Core\Encryption')) {
                            $email_plain = \FreeFormCertificate\Core\Encryption::decrypt($appointment['email_encrypted']);
                            $email_display = ($email_plain && is_string($email_plain)) ? $email_plain : '';
                        }
                    } catch (\Exception $e) {
                        $email_display = '';
                    }
                } elseif (!empty($appointment['email'])) {
                    $email_display = $appointment['email'];
                }

                // Format end time
                $end_time_formatted = '';
                if (!empty($appointment['end_time'])) {
                    $end_timestamp = strtotime($appointment['end_time']);
                    $end_time_formatted = ($end_timestamp !== false) ? date_i18n('H:i', $end_timestamp) : '';
                }

                // Status badge
                $status_labels = array(
                    'pending' => __('Pending', 'wp-ffcertificate'),
                    'confirmed' => __('Confirmed', 'wp-ffcertificate'),
                    'cancelled' => __('Cancelled', 'wp-ffcertificate'),
                    'completed' => __('Completed', 'wp-ffcertificate'),
                    'no_show' => __('No Show', 'wp-ffcertificate'),
                );

                $status = $appointment['status'] ?? 'pending';

                // Generate receipt URL
                $receipt_url = '';
                if (class_exists('\FreeFormCertificate\SelfScheduling\AppointmentReceiptHandler')) {
                    $receipt_url = \FreeFormCertificate\SelfScheduling\AppointmentReceiptHandler::get_receipt_url(
                        (int) $appointment['id'],
                        $appointment['confirmation_token'] ?? ''
                    );
                }

                // Determine if user can cancel this appointment
                $can_cancel = false;
                if (in_array($status, ['pending', 'confirmed'])) {
                    // Admins can always cancel
                    if (current_user_can('manage_options')) {
                        $can_cancel = true;
                    }
                    // Regular users: check calendar settings
                    elseif ($calendar && is_array($calendar)) {
                        // Check if calendar allows cancellation
                        if (!empty($calendar['allow_cancellation'])) {
                            // Check cancellation deadline
                            $can_cancel = true;
                            if (!empty($calendar['cancellation_min_hours']) && $calendar['cancellation_min_hours'] > 0) {
                                $appointment_time = strtotime($appointment['appointment_date'] . ' ' . $appointment['start_time']);
                                $deadline = $appointment_time - ($calendar['cancellation_min_hours'] * 3600);

                                // If current time is past the deadline, cannot cancel
                                if (current_time('timestamp') > $deadline) {
                                    $can_cancel = false;
                                }
                            }
                        }
                    }
                }

                $appointments_formatted[] = array(
                    'id' => (int) $appointment['id'],
                    'calendar_id' => (int) ($appointment['calendar_id'] ?? 0),
                    'calendar_title' => $calendar_title,
                    'appointment_date' => $date_formatted,
                    'appointment_date_raw' => $appointment['appointment_date'] ?? '',
                    'start_time' => $time_formatted,
                    'start_time_raw' => $appointment['start_time'] ?? '',
                    'end_time' => $end_time_formatted,
                    'status' => $status,
                    'status_label' => $status_labels[$status] ?? $status,
                    'name' => $appointment['name'] ?? '',
                    'email' => $email_display,
                    'phone' => $appointment['phone'] ?? '',
                    'user_notes' => $appointment['user_notes'] ?? '',
                    'created_at' => $appointment['created_at'] ?? '',
                    'can_cancel' => $can_cancel,
                    'receipt_url' => $receipt_url,
                );
            }

            return rest_ensure_response(array(
                'appointments' => $appointments_formatted,
                'total' => count($appointments_formatted),
            ));

        } catch (\Exception $e) {
            // Log the error for debugging
            if (class_exists('\FreeFormCertificate\Core\Utils')) {
                \FreeFormCertificate\Core\Utils::debug_log('get_user_appointments error', array(
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ));
            }

            return new \WP_Error(
                'get_appointments_error',
                /* translators: %s: error message */
                sprintf(__('Error loading appointments: %s', 'wp-ffcertificate'), $e->getMessage()),
                array('status' => 500)
            );
        }
    }

    /**
     * GET /calendars
     * List all active calendars
     *
     * @since 4.1.0
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_calendars($request) {
        try {
            if (!class_exists('\FreeFormCertificate\Repositories\CalendarRepository')) {
                return new \WP_Error(
                    'repository_not_found',
                    __('Calendar repository not available', 'wp-ffcertificate'),
                    array('status' => 500)
                );
            }

            $calendar_repository = new \FreeFormCertificate\Repositories\CalendarRepository();

            // Get all active calendars
            $calendars = $calendar_repository->findAll(
                array('status' => 'active'),
                'title',
                'ASC'
            );

            $calendars_formatted = array();
            foreach ($calendars as $calendar) {
                $calendars_formatted[] = array(
                    'id' => (int) $calendar['id'],
                    'title' => $calendar['title'],
                    'description' => $calendar['description'] ?? '',
                    'requires_approval' => (bool) $calendar['requires_approval'],
                    'require_login' => (bool) $calendar['require_login'],
                    'allow_cancellation' => (bool) $calendar['allow_cancellation'],
                    'slot_duration' => (int) $calendar['slot_duration'],
                    'advance_booking_min' => (int) $calendar['advance_booking_min'],
                    'advance_booking_max' => (int) $calendar['advance_booking_max'],
                );
            }

            return rest_ensure_response(array(
                'calendars' => $calendars_formatted,
                'total' => count($calendars_formatted),
            ));

        } catch (\Exception $e) {
            return new \WP_Error(
                'get_calendars_error',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }

    /**
     * GET /calendars/{id}
     * Get calendar details
     *
     * @since 4.1.0
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_calendar($request) {
        try {
            $calendar_id = $request->get_param('id');

            if (!class_exists('\FreeFormCertificate\Repositories\CalendarRepository')) {
                return new \WP_Error(
                    'repository_not_found',
                    __('Calendar repository not available', 'wp-ffcertificate'),
                    array('status' => 500)
                );
            }

            $calendar_repository = new \FreeFormCertificate\Repositories\CalendarRepository();
            $calendar = $calendar_repository->getWithWorkingHours($calendar_id);

            if (!$calendar) {
                return new \WP_Error(
                    'calendar_not_found',
                    __('Calendar not found', 'wp-ffcertificate'),
                    array('status' => 404)
                );
            }

            if ($calendar['status'] !== 'active') {
                return new \WP_Error(
                    'calendar_inactive',
                    __('Calendar is not active', 'wp-ffcertificate'),
                    array('status' => 403)
                );
            }

            return rest_ensure_response(array(
                'id' => (int) $calendar['id'],
                'title' => $calendar['title'],
                'description' => $calendar['description'] ?? '',
                'requires_approval' => (bool) $calendar['requires_approval'],
                'require_login' => (bool) $calendar['require_login'],
                'allow_cancellation' => (bool) $calendar['allow_cancellation'],
                'cancellation_min_hours' => (int) $calendar['cancellation_min_hours'],
                'slot_duration' => (int) $calendar['slot_duration'],
                'slot_interval' => (int) $calendar['slot_interval'],
                'max_appointments_per_slot' => (int) $calendar['max_appointments_per_slot'],
                'advance_booking_min' => (int) $calendar['advance_booking_min'],
                'advance_booking_max' => (int) $calendar['advance_booking_max'],
                'working_hours' => $calendar['working_hours'] ?? array(),
            ));

        } catch (\Exception $e) {
            return new \WP_Error(
                'get_calendar_error',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }

    /**
     * GET /calendars/{id}/slots
     * Get available time slots for a date
     *
     * @since 4.1.0
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_calendar_slots($request) {
        try {
            $calendar_id = $request->get_param('id');
            $date = $request->get_param('date');

            if (!class_exists('\FreeFormCertificate\SelfScheduling\AppointmentHandler')) {
                return new \WP_Error(
                    'handler_not_found',
                    __('Appointment handler not available', 'wp-ffcertificate'),
                    array('status' => 500)
                );
            }

            $appointment_handler = new \FreeFormCertificate\SelfScheduling\AppointmentHandler();
            $slots = $appointment_handler->get_available_slots($calendar_id, $date);

            if (is_wp_error($slots)) {
                return $slots;
            }

            return rest_ensure_response(array(
                'slots' => $slots,
                'date' => $date,
                'calendar_id' => $calendar_id,
            ));

        } catch (\Exception $e) {
            return new \WP_Error(
                'get_slots_error',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }

    /**
     * POST /calendars/{id}/appointments
     * Create a new appointment
     *
     * @since 4.1.0
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function create_appointment($request) {
        try {
            $calendar_id = $request->get_param('id');
            $params = $request->get_json_params();

            if (empty($params)) {
                return new \WP_Error(
                    'no_data',
                    __('No data provided in request body', 'wp-ffcertificate'),
                    array('status' => 400)
                );
            }

            // Required fields
            $required_fields = array('date', 'time', 'name', 'email');
            foreach ($required_fields as $field) {
                if (empty($params[$field])) {
                    return new \WP_Error(
                        'missing_field',
                        /* translators: %s: field name */
                        sprintf(__('Missing required field: %s', 'wp-ffcertificate'), $field),
                        array('status' => 400)
                    );
                }
            }

            // Validate email
            if (!is_email($params['email'])) {
                return new \WP_Error(
                    'invalid_email',
                    __('Invalid email address', 'wp-ffcertificate'),
                    array('status' => 400)
                );
            }

            // Build appointment data
            $appointment_data = array(
                'calendar_id' => $calendar_id,
                'appointment_date' => sanitize_text_field($params['date']),
                'start_time' => sanitize_text_field($params['time']),
                'name' => sanitize_text_field($params['name']),
                'email' => sanitize_email($params['email']),
                'phone' => isset($params['phone']) ? sanitize_text_field($params['phone']) : '',
                'user_notes' => isset($params['notes']) ? sanitize_textarea_field($params['notes']) : '',
                'custom_data' => isset($params['custom_data']) ? $params['custom_data'] : array(),
                'consent_given' => isset($params['consent']) ? 1 : 0,
                'consent_text' => isset($params['consent_text']) ? sanitize_textarea_field($params['consent_text']) : '',
                'user_ip' => \FreeFormCertificate\Core\Utils::get_user_ip(),
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : ''
            );

            // Add user ID if logged in
            if (is_user_logged_in()) {
                $appointment_data['user_id'] = get_current_user_id();
            }

            // Process appointment
            if (!class_exists('\FreeFormCertificate\SelfScheduling\AppointmentHandler')) {
                return new \WP_Error(
                    'handler_not_found',
                    __('Appointment handler not available', 'wp-ffcertificate'),
                    array('status' => 500)
                );
            }

            $appointment_handler = new \FreeFormCertificate\SelfScheduling\AppointmentHandler();
            $result = $appointment_handler->process_appointment($appointment_data);

            if (is_wp_error($result)) {
                return $result;
            }

            return rest_ensure_response(array(
                'success' => true,
                'message' => __('Appointment booked successfully!', 'wp-ffcertificate'),
                'appointment_id' => $result['appointment_id'],
                'requires_approval' => $result['requires_approval'],
            ));

        } catch (\Exception $e) {
            return new \WP_Error(
                'create_appointment_error',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }

    /**
     * GET /appointments/{id}
     * Get appointment details
     *
     * @since 4.1.0
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_appointment($request) {
        try {
            $appointment_id = $request->get_param('id');

            if (!class_exists('\FreeFormCertificate\Repositories\AppointmentRepository')) {
                return new \WP_Error(
                    'repository_not_found',
                    __('Appointment repository not available', 'wp-ffcertificate'),
                    array('status' => 500)
                );
            }

            $appointment_repository = new \FreeFormCertificate\Repositories\AppointmentRepository();
            $appointment = $appointment_repository->findById($appointment_id);

            if (!$appointment) {
                return new \WP_Error(
                    'appointment_not_found',
                    __('Appointment not found', 'wp-ffcertificate'),
                    array('status' => 404)
                );
            }

            // Get calendar info
            $calendar_repository = new \FreeFormCertificate\Repositories\CalendarRepository();
            $calendar = $calendar_repository->findById($appointment['calendar_id']);

            // Decrypt email if encrypted
            $email_display = '';
            if (!empty($appointment['email_encrypted'])) {
                try {
                    $email_plain = \FreeFormCertificate\Core\Encryption::decrypt($appointment['email_encrypted']);
                    $email_display = ($email_plain && is_string($email_plain)) ? $email_plain : '';
                } catch (\Exception $e) {
                    $email_display = '';
                }
            } elseif (!empty($appointment['email'])) {
                $email_display = $appointment['email'];
            }

            return rest_ensure_response(array(
                'id' => (int) $appointment['id'],
                'calendar_id' => (int) $appointment['calendar_id'],
                'calendar_title' => $calendar['title'] ?? '',
                'appointment_date' => $appointment['appointment_date'],
                'start_time' => $appointment['start_time'],
                'end_time' => $appointment['end_time'],
                'status' => $appointment['status'],
                'name' => $appointment['name'],
                'email' => $email_display,
                'phone' => $appointment['phone'] ?? '',
                'user_notes' => $appointment['user_notes'] ?? '',
                'created_at' => $appointment['created_at'],
            ));

        } catch (\Exception $e) {
            return new \WP_Error(
                'get_appointment_error',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }

    /**
     * DELETE /appointments/{id}
     * Cancel an appointment
     *
     * @since 4.1.0
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function cancel_appointment($request) {
        try {
            $appointment_id = $request->get_param('id');
            $params = $request->get_json_params();
            $reason = isset($params['reason']) ? sanitize_textarea_field($params['reason']) : '';

            if (!class_exists('\FreeFormCertificate\SelfScheduling\AppointmentHandler')) {
                return new \WP_Error(
                    'handler_not_found',
                    __('Appointment handler not available', 'wp-ffcertificate'),
                    array('status' => 500)
                );
            }

            $appointment_handler = new \FreeFormCertificate\SelfScheduling\AppointmentHandler();

            // For REST API, we don't use token-based auth, rely on user ownership
            $result = $appointment_handler->cancel_appointment($appointment_id, '', $reason);

            if (is_wp_error($result)) {
                return $result;
            }

            return rest_ensure_response(array(
                'success' => true,
                'message' => __('Appointment cancelled successfully', 'wp-ffcertificate'),
            ));

        } catch (\Exception $e) {
            return new \WP_Error(
                'cancel_appointment_error',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }

    /**
     * Check appointment access permission
     *
     * @since 4.1.0
     * @param \WP_REST_Request $request
     * @return bool
     */
    public function check_appointment_access($request): bool {
        $appointment_id = $request->get_param('id');

        // Admin can always access
        if (current_user_can('manage_options')) {
            return true;
        }

        // User must be logged in
        if (!is_user_logged_in()) {
            return false;
        }

        // User can only access their own appointments
        if (!class_exists('\FreeFormCertificate\Repositories\AppointmentRepository')) {
            return false;
        }

        $appointment_repository = new \FreeFormCertificate\Repositories\AppointmentRepository();
        $appointment = $appointment_repository->findById($appointment_id);

        if (!$appointment) {
            return false;
        }

        return (int) $appointment['user_id'] === get_current_user_id();
    }

    /**
     * GET /user/audience-bookings
     * Get bookings where current user is affected (member of an audience or individually selected)
     *
     * @since 4.5.0
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_user_audience_bookings($request) {
        try {
            $user_id = get_current_user_id();

            if (!$user_id) {
                return new \WP_Error(
                    'not_logged_in',
                    __('You must be logged in to view bookings', 'wp-ffcertificate'),
                    array('status' => 401)
                );
            }

            // Check for admin view-as mode
            $view_as_user_id = $request->get_param('viewAsUserId');
            if ($view_as_user_id && current_user_can('manage_options')) {
                $user_id = absint($view_as_user_id);
            }

            // Check capability (admin always has access)
            if (!current_user_can('manage_options') && !current_user_can('ffc_view_audience_bookings')) {
                return new \WP_Error(
                    'capability_denied',
                    __('You do not have permission to view audience bookings', 'wp-ffcertificate'),
                    array('status' => 403)
                );
            }

            global $wpdb;

            // Use WordPress date-only format
            $date_format = get_option('date_format', 'F j, Y');

            // Get bookings where user is affected (directly or via audience membership)
            $bookings_table = $wpdb->prefix . 'ffc_audience_bookings';
            $users_table = $wpdb->prefix . 'ffc_audience_booking_users';
            $booking_audiences_table = $wpdb->prefix . 'ffc_audience_booking_audiences';
            $members_table = $wpdb->prefix . 'ffc_audience_members';
            $audience_names_table = $wpdb->prefix . 'ffc_audience_audiences';
            $environments_table = $wpdb->prefix . 'ffc_audience_environments';
            $schedules_table = $wpdb->prefix . 'ffc_audience_schedules';

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $bookings = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT b.*, e.name as environment_name, s.name as schedule_name
                 FROM {$bookings_table} b
                 LEFT JOIN {$users_table} bu ON b.id = bu.booking_id
                 LEFT JOIN {$booking_audiences_table} ba ON b.id = ba.booking_id
                 LEFT JOIN {$members_table} am ON ba.audience_id = am.audience_id
                 LEFT JOIN {$environments_table} e ON b.environment_id = e.id
                 LEFT JOIN {$schedules_table} s ON e.schedule_id = s.id
                 WHERE (bu.user_id = %d OR am.user_id = %d)
                 ORDER BY b.booking_date DESC, b.start_time DESC",
                $user_id,
                $user_id
            ), ARRAY_A);

            // Ensure bookings is an array
            if (!is_array($bookings)) {
                $bookings = array();
            }

            // Format response
            $bookings_formatted = array();

            foreach ($bookings as $booking) {
                // Get audiences for this booking
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $audiences = $wpdb->get_results($wpdb->prepare(
                    "SELECT a.name, a.color
                     FROM {$booking_audiences_table} ba
                     INNER JOIN {$audience_names_table} a ON ba.audience_id = a.id
                     WHERE ba.booking_id = %d",
                    $booking['id']
                ), ARRAY_A);

                // Format date
                $date_formatted = '';
                if (!empty($booking['booking_date'])) {
                    $timestamp = strtotime($booking['booking_date']);
                    $date_formatted = ($timestamp !== false) ? date_i18n($date_format, $timestamp) : $booking['booking_date'];
                }

                // Format time
                $time_formatted = '';
                if (!empty($booking['start_time'])) {
                    $time_timestamp = strtotime($booking['start_time']);
                    $time_formatted = ($time_timestamp !== false) ? date_i18n('H:i', $time_timestamp) : $booking['start_time'];
                }

                // Format end time
                $end_time_formatted = '';
                if (!empty($booking['end_time'])) {
                    $end_timestamp = strtotime($booking['end_time']);
                    $end_time_formatted = ($end_timestamp !== false) ? date_i18n('H:i', $end_timestamp) : '';
                }

                // Status badge
                $status_labels = array(
                    'active' => __('Confirmed', 'wp-ffcertificate'),
                    'cancelled' => __('Cancelled', 'wp-ffcertificate'),
                );

                $status = $booking['status'] ?? 'active';

                // Determine if booking is upcoming or past
                $is_past = strtotime($booking['booking_date']) < strtotime('today');

                $bookings_formatted[] = array(
                    'id' => (int) $booking['id'],
                    'environment_id' => (int) ($booking['environment_id'] ?? 0),
                    'environment_name' => $booking['environment_name'] ?? __('Unknown', 'wp-ffcertificate'),
                    'schedule_name' => $booking['schedule_name'] ?? '',
                    'booking_date' => $date_formatted,
                    'booking_date_raw' => $booking['booking_date'] ?? '',
                    'start_time' => $time_formatted,
                    'end_time' => $end_time_formatted,
                    'description' => $booking['description'] ?? '',
                    'status' => $status,
                    'status_label' => $status_labels[$status] ?? $status,
                    'is_past' => $is_past,
                    'audiences' => array_map(function($a) {
                        return array(
                            'name' => $a['name'],
                            'color' => $a['color'] ?? '#2271b1',
                        );
                    }, $audiences ?: array()),
                );
            }

            return rest_ensure_response(array(
                'bookings' => $bookings_formatted,
                'total' => count($bookings_formatted),
            ));

        } catch (\Exception $e) {
            // Log the error for debugging
            if (class_exists('\FreeFormCertificate\Core\Utils')) {
                \FreeFormCertificate\Core\Utils::debug_log('get_user_audience_bookings error', array(
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ));
            }

            return new \WP_Error(
                'get_audience_bookings_error',
                /* translators: %s: error message */
                sprintf(__('Error loading audience bookings: %s', 'wp-ffcertificate'), $e->getMessage()),
                array('status' => 500)
            );
        }
    }

    /**
     * Decrypt submission data if encrypted
     *
     * Merges decrypted data with existing data array.
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
            // Log error but continue with non-encrypted data
            if (class_exists('\FreeFormCertificate\Core\Debug')) {
                \FreeFormCertificate\Core\Debug::log_rest_api('Decryption failed', $e->getMessage());
            }
        }

        return $data;
    }
}
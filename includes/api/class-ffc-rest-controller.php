<?php
declare(strict_types=1);

/**
 * FFC REST API Controller
 *
 * Base controller for all REST API endpoints
 * Namespace: /wp-json/ffc/v1/
 *
 * @version 3.3.0 - Added strict types and type hints
 * @since 3.0.0
 */

if (!defined('ABSPATH')) exit;

class FFC_REST_Controller {

    /**
     * API namespace
     */
    private string $namespace = 'ffc/v1';

    /**
     * Repositories
     */
    private ?FFC_Form_Repository $form_repository = null;
    private ?FFC_Submission_Repository $submission_repository = null;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Load repositories if not already loaded
        $this->load_repositories();
        
        // Initialize repositories
        if (class_exists('FFC_Form_Repository')) {
            $this->form_repository = new FFC_Form_Repository();
        }
        
        if (class_exists('FFC_Submission_Repository')) {
            $this->submission_repository = new FFC_Submission_Repository();
        }
        
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
     * Load repository classes
     */
    private function load_repositories(): void {
        $repo_files = array(
            'ffc-abstract-repository.php',
            'ffc-form-repository.php',
            'ffc-submission-repository.php'
        );

        foreach ($repo_files as $file) {
            $path = FFC_PLUGIN_DIR . 'includes/repositories/' . $file;
            if (file_exists($path)) {
                require_once $path;
            }
        }
    }
    
    /**
     * Register all REST routes
     */
    public function register_routes(): void {
        
        // GET /forms - List all published forms
        register_rest_route($this->namespace, '/forms', array(
            'methods' => WP_REST_Server::READABLE,
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
            'methods' => WP_REST_Server::READABLE,
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
            'methods' => WP_REST_Server::CREATABLE,
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
            'methods' => WP_REST_Server::READABLE,
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
            'methods' => WP_REST_Server::READABLE,
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
            'methods' => WP_REST_Server::CREATABLE,
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
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_user_certificates'),
            'permission_callback' => 'is_user_logged_in', // Requires logged in user
        ));

        // GET /user/profile - Get current user's profile data (v3.1.0)
        register_rest_route($this->namespace, '/user/profile', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'get_user_profile'),
            'permission_callback' => 'is_user_logged_in', // Requires logged in user
        ));
    }
    
    /**
     * GET /forms
     * List all published forms
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_forms($request) {
        try {
            $limit = $request->get_param('limit');
            
            if (!$this->form_repository) {
                return new WP_Error(
                    'repository_not_found',
                    __('Form repository not available', 'ffc'),
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
            
        } catch (Exception $e) {
            return new WP_Error(
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
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_form($request) {
        try {
            $form_id = $request->get_param('id');
            
            $form = get_post($form_id);
            
            if (!$form || $form->post_type !== 'ffc_form') {
                return new WP_Error(
                    'form_not_found',
                    __('Form not found', 'ffc'),
                    array('status' => 404)
                );
            }
            
            if ($form->post_status !== 'publish') {
                return new WP_Error(
                    'form_not_published',
                    __('Form is not published', 'ffc'),
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
            
        } catch (Exception $e) {
            return new WP_Error(
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
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response with submission data or error
     */
    public function submit_form($request) {
        try {
            $form_id = $request->get_param('id');
            
            // Get all parameters from request body
            $params = $request->get_json_params();
            
            if (empty($params)) {
                return new WP_Error(
                    'no_data',
                    'No data provided in request body',
                    array('status' => 400)
                );
            }
            
            // Verify form exists and is published
            $form = get_post($form_id);
            
            if (!$form || $form->post_type !== 'ffc_form') {
                return new WP_Error(
                    'form_not_found',
                    'Form not found',
                    array('status' => 404)
                );
            }
            
            if ($form->post_status !== 'publish') {
                return new WP_Error(
                    'form_not_published',
                    'Form is not published',
                    array('status' => 403)
                );
            }
            
            // Get form configuration and fields
            $form_config = $this->form_repository->getConfig($form_id);
            $form_fields = $this->form_repository->getFields($form_id);
            
            // Sanitize submission data using FFC_Utils
            $submission_data = FFC_Utils::recursive_sanitize($params);
            
            // Validate required fields
            $validation_errors = $this->validate_required_fields($submission_data, $form_fields);
            if (!empty($validation_errors)) {
                return new WP_Error(
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
                    if (class_exists('FFC_Utils') && !FFC_Utils::validate_cpf($cpf)) {
                        return new WP_Error(
                            'invalid_cpf',
                            'Invalid CPF. Please check the number and try again.',
                            array('status' => 400)
                        );
                    }
                } elseif (strlen($cpf) === 7) {
                    // Validate RF
                    if (class_exists('FFC_Utils') && !FFC_Utils::validate_rf($cpf)) {
                        return new WP_Error(
                            'invalid_rf',
                            'Invalid RF. Must contain only numbers.',
                            array('status' => 400)
                        );
                    }
                } else {
                    return new WP_Error(
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
                    return new WP_Error(
                        'invalid_email',
                        'Invalid email address',
                        array('status' => 400)
                    );
                }
            }
            
            // Rate limiting check
            if (class_exists('FFC_Rate_Limiter')) {
                $rate_limiter = new FFC_Rate_Limiter();
                
                // Check IP rate limit using FFC_Utils
                $ip = FFC_Utils::get_user_ip();
                if (!$rate_limiter->check_limit('ip', $ip)) {
                    return new WP_Error(
                        'rate_limit_exceeded',
                        'Too many requests. Please try again later.',
                        array('status' => 429)
                    );
                }
                
                // Check email rate limit
                if (!empty($submission_data['email'])) {
                    if (!$rate_limiter->check_limit('email', $submission_data['email'])) {
                        return new WP_Error(
                            'rate_limit_exceeded',
                            'Too many submissions from this email. Please try again later.',
                            array('status' => 429)
                        );
                    }
                }
                
                // Check CPF rate limit
                if (!empty($submission_data['cpf_rf'])) {
                    if (!$rate_limiter->check_limit('cpf', $submission_data['cpf_rf'])) {
                        return new WP_Error(
                            'rate_limit_exceeded',
                            'Too many submissions with this CPF/RF. Please try again later.',
                            array('status' => 429)
                        );
                    }
                }
            }
            
            // Use FFC_Submission_Handler to process submission
            if (!class_exists('FFC_Submission_Handler')) {
                return new WP_Error(
                    'handler_not_found',
                    'Submission handler not available',
                    array('status' => 500)
                );
            }
            
            $handler = new FFC_Submission_Handler();
            
            // Process the submission
            $result = $handler->process_submission($form_id, $submission_data);
            
            if (is_wp_error($result)) {
                return $result;
            }
            
            // Build success response
            $response = array(
                'success' => true,
                'submission_id' => $result['submission_id'],
                'auth_code' => FFC_Utils::format_auth_code($result['auth_code']),
                'message' => 'Form submitted successfully',
            );
            
            // Add PDF URL if available
            if (!empty($result['pdf_url'])) {
                $response['pdf_url'] = $result['pdf_url'];
            }
            
            // Add validation URL
            $response['validation_url'] = home_url('/validate-certificate/');
            
            return rest_ensure_response($response);
            
        } catch (Exception $e) {
            return new WP_Error(
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
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response with submissions list or error
     */
    public function get_submissions($request) {
        try {
            if (!$this->submission_repository) {
                return new WP_Error(
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
                    'auth_code' => FFC_Utils::format_auth_code($item['auth_code']),
                    'submission_date' => $item['submission_date'],
                    'status' => $item['status'],
                    'email' => !empty($item['email']) ? $item['email'] : null,
                    'cpf_rf' => !empty($item['cpf_rf']) ? FFC_Utils::mask_cpf($item['cpf_rf']) : null,
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
            
        } catch (Exception $e) {
            return new WP_Error(
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
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response with submission data or error
     */
    public function get_submission($request) {
        try {
            $submission_id = $request->get_param('id');
            
            if (!$this->submission_repository) {
                return new WP_Error(
                    'repository_not_found',
                    'Submission repository not available',
                    array('status' => 500)
                );
            }
            
            // Find submission by ID
            $submission = $this->submission_repository->findById($submission_id);
            
            if (!$submission) {
                return new WP_Error(
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
                'auth_code' => FFC_Utils::format_auth_code($submission['auth_code']),
                'submission_date' => $submission['submission_date'],
                'status' => $submission['status'],
                'email' => !empty($submission['email']) ? $submission['email'] : null,
                'cpf_rf' => !empty($submission['cpf_rf']) ? $submission['cpf_rf'] : null,
                'data' => $data,
            );
            
            return rest_ensure_response($response);
            
        } catch (Exception $e) {
            return new WP_Error(
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
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response|WP_Error Response with submission data or error
     */
    public function verify_certificate($request) {
        try {
            $auth_code = $request->get_param('auth_code');
            
            // Clean auth code using FFC_Utils
            $auth_code = FFC_Utils::clean_auth_code($auth_code);
            
            if (!$this->submission_repository) {
                return new WP_Error(
                    'repository_not_found',
                    'Submission repository not available',
                    array('status' => 500)
                );
            }
            
            // Find submission by auth code
            $submission = $this->submission_repository->findByAuthCode($auth_code);
            
            if (!$submission) {
                return new WP_Error(
                    'certificate_not_found',
                    'Certificate not found. Please check the authentication code.',
                    array('status' => 404)
                );
            }
            
            // Check if submission is published (not trashed)
            if (isset($submission['status']) && $submission['status'] === 'trash') {
                return new WP_Error(
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
                'auth_code' => FFC_Utils::format_auth_code($auth_code),
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
                $response['certificate']['cpf_rf'] = FFC_Utils::mask_cpf($submission['cpf_rf']);
            }
            
            return rest_ensure_response($response);
            
        } catch (Exception $e) {
            return new WP_Error(
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
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_user_certificates($request) {
        try {
            $user_id = get_current_user_id();

            if (!$user_id) {
                return new WP_Error(
                    'not_logged_in',
                    __('You must be logged in to view certificates', 'ffc'),
                    array('status' => 401)
                );
            }

            // Check for admin view-as mode
            $view_as_user_id = $request->get_param('viewAsUserId');
            if ($view_as_user_id && current_user_can('manage_options')) {
                $user_id = absint($view_as_user_id);
            }

            global $wpdb;

            // Safety check for FFC_Utils
            if (!class_exists('FFC_Utils')) {
                return new WP_Error('missing_class', 'FFC_Utils class not found', array('status' => 500));
            }

            $table = FFC_Utils::get_submissions_table();

            // Get date format from settings
            $settings = get_option('ffc_settings', array());
            $date_format = $settings['date_format'] ?? 'F j, Y';

            // Get all submissions for this user (includes both CPF and RF)
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
                        $email_plain = FFC_Encryption::decrypt($submission['email_encrypted']);
                        // Check if decrypt returned valid string before masking
                        $email_display = ($email_plain && is_string($email_plain)) ? FFC_Utils::mask_email($email_plain) : '';
                    } catch (Exception $e) {
                        $email_display = __('Error decrypting', 'ffc');
                    }
                } elseif (!empty($submission['email'])) {
                    // Fallback to plain email if not encrypted
                    $email_display = FFC_Utils::mask_email($submission['email']);
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
                    $auth_code_formatted = FFC_Utils::format_auth_code($submission['auth_code']);
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
                    'form_title' => $submission['form_title'] ?? __('Unknown Form', 'ffc'),
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

        } catch (Exception $e) {
            // Log the error for debugging
            if (class_exists('FFC_Utils')) {
                FFC_Utils::debug_log('get_user_certificates error', array(
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ));
            }
            return new WP_Error(
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
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_user_profile($request) {
        try {
            $user_id = get_current_user_id();

            if (!$user_id) {
                return new WP_Error(
                    'not_logged_in',
                    __('You must be logged in to view profile', 'ffc'),
                    array('status' => 401)
                );
            }

            // Check for admin view-as mode
            $view_as_user_id = $request->get_param('viewAsUserId');
            if ($view_as_user_id && current_user_can('manage_options')) {
                $user_id = absint($view_as_user_id);
            }

            // Load User Manager if needed
            if (!class_exists('FFC_User_Manager')) {
                $user_manager_file = FFC_PLUGIN_DIR . 'includes/user-dashboard/class-ffc-user-manager.php';
                if (file_exists($user_manager_file)) {
                    require_once $user_manager_file;
                }
            }

            $user = get_user_by('id', $user_id);

            if (!$user) {
                return new WP_Error(
                    'user_not_found',
                    __('User not found', 'ffc'),
                    array('status' => 404)
                );
            }

            // Get CPF/RF (masked)
            $cpf_masked = '';
            if (class_exists('FFC_User_Manager')) {
                $cpf_masked = FFC_User_Manager::get_user_cpf_masked($user_id);
            }

            // Get all emails used
            $emails = array();
            if (class_exists('FFC_User_Manager')) {
                $emails = FFC_User_Manager::get_user_emails($user_id);
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
                'email' => $user->user_email,
                'emails' => $emails,
                'cpf_masked' => $cpf_masked ?? __('Not found', 'ffc'),
                'member_since' => $member_since,
                'roles' => $user->roles,
            ));

        } catch (Exception $e) {
            return new WP_Error(
                'get_profile_error',
                $e->getMessage(),
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

        if (!class_exists('FFC_Encryption')) {
            return $data;
        }

        try {
            $decrypted = FFC_Encryption::decrypt($submission['data_encrypted']);
            $decrypted_data = json_decode($decrypted, true);

            if (is_array($decrypted_data)) {
                return array_merge($data, $decrypted_data);
            }
        } catch (Exception $e) {
            // Log error but continue with non-encrypted data
            if (class_exists('FFC_Debug')) {
                FFC_Debug::log_rest_api('Decryption failed', $e->getMessage());
            }
        }

        return $data;
    }
}
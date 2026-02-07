<?php
declare(strict_types=1);

/**
 * Form REST Controller
 *
 * Handles form-related REST API endpoints:
 *   GET  /forms          – List published forms
 *   GET  /forms/{id}     – Get single form
 *   POST /forms/{id}/submit – Submit a form
 *
 * @since 4.6.1
 * @package FreeFormCertificate\API
 */

namespace FreeFormCertificate\API;

use FreeFormCertificate\Repositories\FormRepository;

if (!defined('ABSPATH')) exit;

class FormRestController {

    /**
     * API namespace
     */
    private string $namespace;

    /**
     * Form repository
     */
    private ?FormRepository $form_repository;

    /**
     * Constructor
     *
     * @param string              $namespace       API namespace.
     * @param FormRepository|null $form_repository Form repository instance.
     */
    public function __construct(string $namespace, ?FormRepository $form_repository) {
        $this->namespace = $namespace;
        $this->form_repository = $form_repository;
    }

    /**
     * Register routes
     */
    public function register_routes(): void {
        // GET /forms - List all published forms
        register_rest_route($this->namespace, '/forms', array(
            'methods' => \WP_REST_Server::READABLE,
            'callback' => array($this, 'get_forms'),
            'permission_callback' => '__return_true',
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
            'permission_callback' => '__return_true',
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
            'permission_callback' => '__return_true',
            'args' => array(
                'id' => array(
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    },
                ),
            ),
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
                    __('Form repository not available', 'ffcertificate'),
                    array('status' => 500)
                );
            }
            
            $forms = $this->form_repository->findPublished($limit);
            
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
                    __('Form not found', 'ffcertificate'),
                    array('status' => 404)
                );
            }
            
            if ($form->post_status !== 'publish') {
                return new \WP_Error(
                    'form_not_published',
                    __('Form is not published', 'ffcertificate'),
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
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function submit_form($request) {
        try {
            $form_id = $request->get_param('id');
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
            
            // Sanitize submission data
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
                    if (class_exists('\FreeFormCertificate\Core\Utils') && !\FreeFormCertificate\Core\Utils::validate_cpf($cpf)) {
                        return new \WP_Error(
                            'invalid_cpf',
                            'Invalid CPF. Please check the number and try again.',
                            array('status' => 400)
                        );
                    }
                } elseif (strlen($cpf) === 7) {
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
                
                $ip = \FreeFormCertificate\Core\Utils::get_user_ip();
                if (!$rate_limiter->check_limit('ip', $ip)) {
                    return new \WP_Error(
                        'rate_limit_exceeded',
                        'Too many requests. Please try again later.',
                        array('status' => 429)
                    );
                }
                
                if (!empty($submission_data['email'])) {
                    if (!$rate_limiter->check_limit('email', $submission_data['email'])) {
                        return new \WP_Error(
                            'rate_limit_exceeded',
                            'Too many submissions from this email. Please try again later.',
                            array('status' => 429)
                        );
                    }
                }
                
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
            
            // Use SubmissionHandler to process submission
            if (!class_exists('\FreeFormCertificate\Submissions\SubmissionHandler')) {
                return new \WP_Error(
                    'handler_not_found',
                    'Submission handler not available',
                    array('status' => 500)
                );
            }
            
            $handler = new \FreeFormCertificate\Submissions\SubmissionHandler();
            $result = $handler->process_submission($form_id, $submission_data);
            
            if (is_wp_error($result)) {
                return $result;
            }
            
            $response = array(
                'success' => true,
                'submission_id' => $result['submission_id'],
                'auth_code' => \FreeFormCertificate\Core\Utils::format_auth_code($result['auth_code']),
                'message' => 'Form submitted successfully',
            );
            
            if (!empty($result['pdf_url'])) {
                $response['pdf_url'] = $result['pdf_url'];
            }
            
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
            if (isset($field['required']) && $field['required']) {
                $field_name = isset($field['name']) ? $field['name'] : '';
                
                if (empty($field_name) || !isset($data[$field_name]) || trim($data[$field_name]) === '') {
                    $field_label = isset($field['label']) ? $field['label'] : $field_name;
                    $errors[] = $field_label . ' is required';
                }
            }
        }
        
        return $errors;
    }
}

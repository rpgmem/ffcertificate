<?php
declare(strict_types=1);

/**
 * VerificationHandler
 * Handles certificate verification and authenticity checks.
 *
 * v2.8.0: Added magic link verification with rate limiting
 * v2.9.0: Added QR Code support in verification
 * v2.9.2: Unified PDF generation with FFC_PDF_Generator, removed prepare_pdf_data()
 * v2.10.0: ENCRYPTION - Auto-decryption via Submission Handler + Access logging
 * v3.3.0: Added strict types and type hints
 * v3.2.0: Migrated to namespace (Phase 2)
 */

namespace FreeFormCertificate\Frontend;

use FreeFormCertificate\Submissions\SubmissionHandler;
use FreeFormCertificate\Repositories\SubmissionRepository;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class VerificationHandler {

    private $submission_handler;
    private $email_handler;

    /**
     * Constructor
     *
     * @param SubmissionHandler|null $submission_handler Submission handler dependency
     * @param mixed $email_handler Email handler for PDF generation
     */
    public function __construct( ?SubmissionHandler $submission_handler = null, $email_handler = null ) {
        $this->submission_handler = $submission_handler;
        $this->email_handler = $email_handler;
    }

    /**
     * Search for certificate by authentication code
     *
     * v2.9.15: RECONSTRUÇÃO - Combina colunas + JSON
     */
    /**
     * Search for certificate - uses Repository
     */
    private function search_certificate( string $auth_code ): array {
        $repository = new SubmissionRepository();
        $clean_code = \FreeFormCertificate\Core\Utils::clean_auth_code($auth_code);

        if (empty($clean_code)) {
            return [
                'found' => false,
                'submission' => null,
                'data' => []
            ];
        }

        $submission = $repository->findByAuthCode($clean_code);

        // Fallback: search appointments by validation_code
        if (!$submission) {
            return $this->search_appointment_by_code( $clean_code );
        }

        // Decrypt
        $submission = $this->submission_handler->decrypt_submission_data($submission);

        // Rebuild data
        $data = [
            'email' => $submission['email'],
        ];

        if (!empty($submission['auth_code'])) {
            $data['auth_code'] = $submission['auth_code'];
        }

        if (!empty($submission['cpf_rf'])) {
            $data['cpf_rf'] = $submission['cpf_rf'];
        }

        $extra_data = json_decode($submission['data'], true);
        if (!is_array($extra_data)) {
            $extra_data = json_decode(stripslashes($submission['data']), true);
        }

        if (is_array($extra_data) && !empty($extra_data)) {
            $data = array_merge($extra_data, $data);
        }

        return [
            'found' => true,
            'submission' => (object) $submission,
            'data' => $data
        ];
    }

    /**
     * Search for appointment by validation code
     *
     * @since 4.2.0
     * @param string $code Cleaned validation code (no hyphens)
     * @return array Result array with 'found', 'submission', 'data', 'type'
     */
    private function search_appointment_by_code( string $code ): array {
        if ( ! class_exists( '\\FreeFormCertificate\\Repositories\\AppointmentRepository' ) ) {
            return array( 'found' => false, 'submission' => null, 'data' => array() );
        }

        $repo = new \FreeFormCertificate\Repositories\AppointmentRepository();
        $appointment = $repo->findByValidationCode( $code );

        if ( ! $appointment ) {
            return array( 'found' => false, 'submission' => null, 'data' => array() );
        }

        return $this->build_appointment_result( $appointment );
    }

    /**
     * Search for appointment by confirmation token (magic link)
     *
     * @since 4.2.0
     * @param string $token Confirmation token (64 hex chars)
     * @return array Result array with 'found', 'submission', 'data', 'type'
     */
    private function search_appointment_by_token( string $token ): array {
        if ( ! class_exists( '\\FreeFormCertificate\\Repositories\\AppointmentRepository' ) ) {
            return array( 'found' => false, 'submission' => null, 'data' => array() );
        }

        $repo = new \FreeFormCertificate\Repositories\AppointmentRepository();
        $appointment = $repo->findByConfirmationToken( $token );

        if ( ! $appointment ) {
            return array( 'found' => false, 'submission' => null, 'data' => array() );
        }

        return $this->build_appointment_result( $appointment );
    }

    /**
     * Build result array from appointment data
     *
     * @since 4.2.0
     * @param array $appointment Appointment data from database
     * @return array Standardized result array
     */
    private function build_appointment_result( array $appointment ): array {
        // Decrypt sensitive fields
        $email = $appointment['email'] ?? '';
        if ( empty( $email ) && ! empty( $appointment['email_encrypted'] ) ) {
            if ( class_exists( '\\FreeFormCertificate\\Core\\Encryption' ) ) {
                try {
                    $email = \FreeFormCertificate\Core\Encryption::decrypt( $appointment['email_encrypted'] );
                } catch ( \Exception $e ) {
                    $email = '';
                }
            }
        }

        $cpf_rf = $appointment['cpf_rf'] ?? '';
        if ( empty( $cpf_rf ) && ! empty( $appointment['cpf_rf_encrypted'] ) ) {
            if ( class_exists( '\\FreeFormCertificate\\Core\\Encryption' ) ) {
                try {
                    $cpf_rf = \FreeFormCertificate\Core\Encryption::decrypt( $appointment['cpf_rf_encrypted'] );
                } catch ( \Exception $e ) {
                    $cpf_rf = '';
                }
            }
        }

        // Build data array
        $data = array(
            'name'            => $appointment['name'] ?? '',
            'email'           => $email,
            'cpf_rf'          => $cpf_rf,
            'auth_code'       => $appointment['validation_code'] ?? '',
            'calendar_title'  => '',
            'appointment_date' => $appointment['appointment_date'] ?? '',
            'start_time'      => $appointment['start_time'] ?? '',
            'end_time'        => $appointment['end_time'] ?? '',
            'status'          => $appointment['status'] ?? 'pending',
            'magic_token'     => $appointment['confirmation_token'] ?? '',
        );

        // Get calendar title
        if ( ! empty( $appointment['calendar_id'] ) ) {
            $calendar_repo = new \FreeFormCertificate\Repositories\CalendarRepository();
            $calendar = $calendar_repo->findById( (int) $appointment['calendar_id'] );
            if ( $calendar ) {
                $data['calendar_title'] = $calendar['title'] ?? '';
            }
        }

        // Build a pseudo-submission object for compatibility with format methods
        $pseudo_submission = array(
            'id'              => $appointment['id'],
            'form_id'         => 0,
            'submission_date' => $appointment['created_at'] ?? '',
            'auth_code'       => $appointment['validation_code'] ?? '',
            'email'           => $email,
            'cpf_rf'          => $cpf_rf,
            'data'            => '{}',
            'magic_token'     => $appointment['confirmation_token'] ?? '',
        );

        return array(
            'found'      => true,
            'submission' => (object) $pseudo_submission,
            'data'       => $data,
            'type'       => 'appointment',
            'appointment' => $appointment,
            'magic_token' => $appointment['confirmation_token'] ?? '',
        );
    }

    /**
     * Verify certificate by magic token
     *
     * This method bypasses captcha/honeypot validation and is used
     * when accessing certificates via magic links.
     *
     * @since 2.8.0 Magic Links feature
     * @param string $token Magic token (32 hex characters)
     * @return array Result array with 'found', 'submission', 'data', 'magic_token'
     */
    public function verify_by_magic_token( string $token ): array {
        // Debug logging
        \FreeFormCertificate\Core\Utils::debug_log( 'Magic token verification started', array(
            'token_preview' => substr( $token, 0, 8 ) . '...',
            'token_length' => strlen( $token )
        ) );

        if ( ! \FreeFormCertificate\Generators\MagicLinkHelper::is_valid_token( $token ) ) {
            \FreeFormCertificate\Core\Utils::debug_log( 'Magic token invalid format' );
            return array(
                'found' => false,
                'submission' => null,
                'data' => array(),
                'error' => 'invalid_token_format',
                'magic_token' => ''
            );
        }

        // Rate limiting check
        $user_ip = \FreeFormCertificate\Core\Utils::get_user_ip();
        $rate_check = \FreeFormCertificate\Security\RateLimiter::check_verification( $user_ip );
        if ( ! $rate_check['allowed'] ) {
            \FreeFormCertificate\Core\Utils::debug_log( 'Magic token rate limited' );
            return array(
                'found' => false,
                'submission' => null,
                'data' => array(),
                'error' => 'rate_limited',
                'magic_token' => ''
            );
        }

        // Get submission by token
        $submission = $this->submission_handler->get_submission_by_token( $token );

        \FreeFormCertificate\Core\Utils::debug_log( 'Magic token lookup result', array(
            'found' => !empty( $submission ),
            'submission_id' => $submission ? $submission['id'] : null
        ) );

        // Fallback: search appointments by confirmation_token
        if ( ! $submission ) {
            $appointment_result = $this->search_appointment_by_token( $token );
            if ( $appointment_result['found'] ) {
                return $appointment_result;
            }

            return array(
                'found' => false,
                'submission' => null,
                'data' => array(),
                'magic_token' => ''
            );
        }

        // v2.10.0: Log access for LGPD compliance
        if ( class_exists( '\\FreeFormCertificate\\Core\\ActivityLog' ) ) {
            \FreeFormCertificate\Core\ActivityLog::log_data_accessed(
                (int) $submission['id'],  // Convert to int (wpdb returns strings)
                array(
                    'method' => 'magic_link',
                    'token' => substr( $token, 0, 8 ) . '...',
                    'ip' => \FreeFormCertificate\Core\Utils::get_user_ip()
                )
            );
        }

        // v2.9.16: REBUILD complete data (columns + JSON)
        // v2.10.0: NOTE - Automatic decryption
        // Data already comes decrypted from Submission Handler.
        // The get_submission_by_token() method calls decrypt_submission_data()
        // internally, so the fields email, cpf_rf, user_ip, data are already
        // in plain text here. No need to decrypt again.

        // Step 1: Required fields from columns
        $data = array();
        
        if ( ! empty( $submission['email'] ) ) {
            $data['email'] = $submission['email'];
        }
        
        if ( ! empty( $submission['auth_code'] ) ) {
            $data['auth_code'] = $submission['auth_code'];
        }
        
        if ( ! empty( $submission['cpf_rf'] ) ) {
            $data['cpf_rf'] = $submission['cpf_rf'];
        }
        
        // Passo 2: Campos extras do JSON
        $extra_data = json_decode( $submission['data'], true );
        if ( ! is_array( $extra_data ) ) {
            $extra_data = json_decode( stripslashes( $submission['data'] ), true );
        }
        
        // Step 3: Merge (columns have priority over JSON)
        if ( is_array( $extra_data ) && ! empty( $extra_data ) ) {
            $data = array_merge( $extra_data, $data );
        }

        // Ensure magic_token exists (fallback for old submissions)
        $magic_token = $submission['magic_token'];
        if ( empty( $magic_token ) ) {
            $magic_token = $this->submission_handler->ensure_magic_token( (int) $submission['id'] );
        }

        return array(
            'found' => true,
            'submission' => (object) $submission,
            'data' => is_array( $data ) ? $data : array(),
            'magic_token' => $magic_token
        );
    }

    /**
     * Format verification response HTML
     *
     * ✅ v2.9.7 ENHANCED: Shows more fields with better formatting
     * ✅ v3.1.0: Refactored to use template file
     */
    private function format_verification_response( object $submission, array $data, bool $show_download_button = false ): string {
        $form = get_post( $submission->form_id );
        $form_title = $form ? $form->post_title : __( 'N/A', 'ffcertificate' );
        $date_generated = date_i18n(
            get_option('date_format') . ' ' . get_option('time_format'),
            strtotime( $submission->submission_date )
        );
        $display_code = isset($data['auth_code']) ? $data['auth_code'] : '';

        // Format auth code (XXXX-XXXX-XXXX)
        if ( strlen( $display_code ) === 12 ) {
            $display_code = substr( $display_code, 0, 4 ) . '-' . substr( $display_code, 4, 4 ) . '-' . substr( $display_code, 8, 4 );
        }

        // ✅ v2.9.7: Show MORE fields with better formatting
        // Only skip internal/technical fields
        $skip_fields = array(
            'auth_code',        // Already shown above
            'ticket',           // Internal field
            'fill_date',        // Redundant with submission_date
            'fill_time',        // Redundant
            'is_edited',        // Internal
            'edited_at',        // Internal
            'submission_id',    // Internal
            'magic_token'       // Internal/security
        );

        // ✅ Priority fields to show first (in order)
        $priority_fields = array('name', 'cpf_rf', 'email', 'program', 'date');

        // Callbacks for template
        $get_field_label_callback = array( $this, 'get_field_label' );
        $format_field_value_callback = array( $this, 'format_field_value' );

        // Render template
        ob_start();
        include FFC_PLUGIN_DIR . 'templates/certificate-preview.php';
        return ob_get_clean();
    }

    /**
     * Get human-readable field label
     *
     * @param string $field_key Field key
     * @return string Formatted label
     */
    private function get_field_label( string $field_key ): string {
    // Custom labels for known fields
    $labels = array(
        'cpf_rf'   => __( 'CPF/RF', 'ffcertificate' ),
        'cpf'      => __( 'CPF', 'ffcertificate' ),
        'rf'       => __( 'RF', 'ffcertificate' ),
        'name'     => __( 'Name', 'ffcertificate' ),
        'email'    => __( 'Email', 'ffcertificate' ),
        'program'  => __( 'Program', 'ffcertificate' ),
        'date'     => __( 'Date', 'ffcertificate' ),
        'rg'       => __( 'RG', 'ffcertificate' ),
        'phone'    => __( 'Phone', 'ffcertificate' ),
        'address'  => __( 'Address', 'ffcertificate' ),
        'city'     => __( 'City', 'ffcertificate' ),
        'state'    => __( 'State', 'ffcertificate' ),
        'zip'      => __( 'ZIP Code', 'ffcertificate' ),
        'course'   => __( 'Course', 'ffcertificate' ),
        'duration' => __( 'Duration', 'ffcertificate' ),
        'hours'    => __( 'Hours', 'ffcertificate' ),
        'grade'    => __( 'Grade', 'ffcertificate' ),
    );
    
    if ( isset( $labels[$field_key] ) ) {
        return $labels[$field_key];
    }
    
    // Auto-format unknown fields
    return ucwords( str_replace( array('_', '-'), ' ', $field_key ) );
}

    /**
     * Format field value for display
     *
     * @param string $field_key Field key
     * @param mixed $value Field value
     * @return string Formatted value
     */
    private function format_field_value( string $field_key, $value ): string {
    // Handle arrays
    if ( is_array( $value ) ) {
        return implode( ', ', $value );
    }
    
    // Format documents (CPF, RF, RG)
    if ( in_array( $field_key, array( 'cpf', 'cpf_rf', 'rg' ) ) && ! empty( $value ) ) {
        if ( class_exists( '\\FreeFormCertificate\\Core\\Utils' ) && method_exists( '\\FreeFormCertificate\\Core\\Utils', 'format_document' ) ) {
            return \FreeFormCertificate\Core\Utils::format_document( $value, 'auto' );
        }
    }

    return $value;
}

    /**
     * Verify certificate (used by shortcode fallback - non-AJAX)
     * Returns array with 'success' (bool), 'html' (string), 'message' (string)
     */
    public function verify_certificate( string $auth_code ): array {
        $result = $this->search_certificate( $auth_code );
        if ( $result['found'] && isset( $result['submission']->id ) ) {
            // Log access for LGPD compliance
            if ( class_exists( '\\FreeFormCertificate\\Core\\ActivityLog' ) ) {
                \FreeFormCertificate\Core\ActivityLog::log_data_accessed(
                    (int) $result['submission']->id,
                    array(
                        'method' => 'manual_verification',
                        'auth_code' => substr( $auth_code, 0, 4 ) . '...',
                        'ip' => \FreeFormCertificate\Core\Utils::get_user_ip()
                    )
                );
            }
        }

        if ( ! $result['found'] ) {
            return array(
                'success' => false,
                'html' => '',
                'message' => __( 'Document not found or invalid code.', 'ffcertificate' )
            );
        }

        if ( ! empty( $result['type'] ) && $result['type'] === 'appointment' ) {
            $html = $this->format_appointment_verification_response( $result );
        } else {
            $html = $this->format_verification_response( $result['submission'], $result['data'], true );
        }

        return array(
            'success' => true,
            'html' => $html,
            'message' => ''
        );
    }

    /**
     * Handle magic link verification via AJAX
     *
     * This endpoint bypasses security checks (captcha/honeypot) as the
     * magic token itself provides authentication.
     *
     * @since 2.8.0 Magic Links feature
     */
    public function handle_magic_verification_ajax(): void {
        // No nonce check - magic token is the authentication
        // No captcha - token proves legitimacy

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Magic token authentication; no nonce needed for this public endpoint.
        $token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
        $user_ip = \FreeFormCertificate\Core\Utils::get_user_ip();

        $rate_check = \FreeFormCertificate\Security\RateLimiter::check_verification( $user_ip );
        if ( ! $rate_check['allowed'] ) {
            wp_send_json_error( array(
                'message' => __( 'Too many verification attempts. Please try again later.', 'ffcertificate' )
            ) );
        }

        if ( empty( $token ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid token.', 'ffcertificate' ) ) );
        }

        // Verify by magic token
        $result = $this->verify_by_magic_token( $token );

        if ( isset( $result['error'] ) && $result['error'] === 'rate_limited' ) {
            wp_send_json_error( array(
                'message' => __( 'Too many attempts. Please try again in 1 minute.', 'ffcertificate' )
            ) );
        }

        if ( ! $result['found'] ) {
            wp_send_json_error( array(
                'message' => '❌ ' . __( 'Document not found or invalid link.', 'ffcertificate' )
            ) );
        }

        $pdf_generator = new \FreeFormCertificate\Generators\PdfGenerator();

        // Check if this is an appointment result
        if ( ! empty( $result['type'] ) && $result['type'] === 'appointment' && ! empty( $result['appointment'] ) ) {
            $pdf_data = $this->generate_appointment_verification_pdf( $result, $pdf_generator );
        } else {
            // Certificate: use standard PDF generator
            $pdf_data = $pdf_generator->generate_pdf_data(
                (int) $result['submission']->id,
                $this->submission_handler
            );
        }

        if ( is_wp_error( $pdf_data ) ) {
            wp_send_json_error( array( 'message' => $pdf_data->get_error_message() ) );
        }

        // Format response HTML with download button
        if ( ! empty( $result['type'] ) && $result['type'] === 'appointment' ) {
            $html = $this->format_appointment_verification_response( $result );
        } else {
            $html = $this->format_verification_response(
                $result['submission'],
                $result['data'],
                true
            );
        }

        wp_send_json_success( array(
            'html' => $html,
            'submission_id' => $result['submission']->id,
            'pdf_data' => $pdf_data
        ) );
    }

    /**
     * Handle certificate verification via AJAX (manual - with captcha)
     */
    public function handle_verification_ajax(): void {
        check_ajax_referer( 'ffc_frontend_nonce', 'nonce' );
        
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer.
        // Validate security
        $captcha_ans = isset($_POST['ffc_captcha_ans']) ? sanitize_text_field(wp_unslash($_POST['ffc_captcha_ans'])) : '';
        $captcha_hash = isset($_POST['ffc_captcha_hash']) ? sanitize_text_field(wp_unslash($_POST['ffc_captcha_hash'])) : '';
        $honeypot = isset($_POST['ffc_honeypot_trap']) ? sanitize_text_field(wp_unslash($_POST['ffc_honeypot_trap'])) : '';
        $user_ip = \FreeFormCertificate\Core\Utils::get_user_ip();

        if ( ! empty( $honeypot ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid submission.', 'ffcertificate' ) ) );
        }

        $rate_check = \FreeFormCertificate\Security\RateLimiter::check_verification( $user_ip );  
        if ( ! $rate_check['allowed'] ) {
            wp_send_json_error( array( 
                'message' => __( 'Too many verification attempts. Please try again later.', 'ffcertificate' ) 
            ) );
        }
        
        if ( ! \FreeFormCertificate\Core\Utils::verify_simple_captcha( $captcha_ans, $captcha_hash ) ) {
            $new_captcha = \FreeFormCertificate\Core\Utils::generate_simple_captcha();
            wp_send_json_error( array( 
                'message' => __( 'Incorrect answer to math question.', 'ffcertificate' ),
                'refresh_captcha' => true,
                'new_label' => $new_captcha['label'],
                'new_hash' => $new_captcha['hash']
            ) );
        }
        
        $auth_code = isset($_POST['ffc_auth_code']) ? sanitize_text_field(wp_unslash($_POST['ffc_auth_code'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        $result = $this->search_certificate( $auth_code );

        if ( ! $result['found'] ) {
            $new_captcha = \FreeFormCertificate\Core\Utils::generate_simple_captcha();
            wp_send_json_error( array(
                'message' => '❌ ' . __( 'Document not found or invalid code.', 'ffcertificate' ),
                'refresh_captcha' => true,
                'new_label' => $new_captcha['label'],
                'new_hash' => $new_captcha['hash']
            ) );
        }

        $pdf_generator = new \FreeFormCertificate\Generators\PdfGenerator();

        // Check if this is an appointment result
        if ( ! empty( $result['type'] ) && $result['type'] === 'appointment' && ! empty( $result['appointment'] ) ) {
            $pdf_data = $this->generate_appointment_verification_pdf( $result, $pdf_generator );
        } else {
            // Certificate: use standard PDF generator
            $pdf_data = $pdf_generator->generate_pdf_data(
                (int) $result['submission']->id,
                $this->submission_handler
            );
        }

        if ( is_wp_error( $pdf_data ) ) {
            wp_send_json_error( array( 'message' => $pdf_data->get_error_message() ) );
        }

        if ( ! empty( $result['type'] ) && $result['type'] === 'appointment' ) {
            $html = $this->format_appointment_verification_response( $result );
        } else {
            $html = $this->format_verification_response(
                $result['submission'],
                $result['data'],
                true
            );
        }

        wp_send_json_success( array(
            'html' => $html,
            'submission_id' => $result['submission']->id,
            'pdf_data' => $pdf_data
        ) );
    }

    /**
     * Generate appointment PDF data for verification context
     *
     * @since 4.2.0
     * @param array $result Search result array
     * @param \FreeFormCertificate\Generators\PdfGenerator $pdf_generator PDF generator instance
     * @return array PDF data array
     */
    private function generate_appointment_verification_pdf( array $result, \FreeFormCertificate\Generators\PdfGenerator $pdf_generator ): array {
        $appointment = $result['appointment'];
        $calendar = array( 'title' => $result['data']['calendar_title'] ?? __( 'N/A', 'ffcertificate' ) );

        // Get full calendar data if available
        if ( ! empty( $appointment['calendar_id'] ) ) {
            $calendar_repo = new \FreeFormCertificate\Repositories\CalendarRepository();
            $full_calendar = $calendar_repo->findById( (int) $appointment['calendar_id'] );
            if ( $full_calendar ) {
                $calendar = $full_calendar;
            }
        }

        return $pdf_generator->generate_appointment_pdf_data( $appointment, $calendar );
    }

    /**
     * Format appointment verification response HTML
     *
     * Displays appointment details in the same verification page layout
     *
     * @since 4.2.0
     * @param array $result Appointment search result
     * @return string HTML output
     */
    private function format_appointment_verification_response( array $result ): string {
        $data = $result['data'];
        $appointment = $result['appointment'];

        $date_format = get_option( 'date_format' );
        $time_format = get_option( 'time_format' );

        // Format date
        $formatted_date = __( 'N/A', 'ffcertificate' );
        if ( ! empty( $appointment['appointment_date'] ) ) {
            $ts = strtotime( $appointment['appointment_date'] );
            if ( $ts !== false ) {
                $formatted_date = date_i18n( $date_format, $ts );
            }
        }

        // Format time
        $formatted_time = __( 'N/A', 'ffcertificate' );
        if ( ! empty( $appointment['start_time'] ) ) {
            $ts = strtotime( $appointment['start_time'] );
            if ( $ts !== false ) {
                $formatted_time = date_i18n( $time_format, $ts );
            }
            if ( ! empty( $appointment['end_time'] ) ) {
                $ts2 = strtotime( $appointment['end_time'] );
                if ( $ts2 !== false ) {
                    $formatted_time .= ' - ' . date_i18n( $time_format, $ts2 );
                }
            }
        }

        // Format created_at
        $formatted_created = __( 'N/A', 'ffcertificate' );
        if ( ! empty( $appointment['created_at'] ) ) {
            $ts = strtotime( $appointment['created_at'] );
            if ( $ts !== false ) {
                $formatted_created = date_i18n( $date_format . ' ' . $time_format, $ts );
            }
        }

        // Status labels
        $status_labels = array(
            'pending'   => __( 'Pending Approval', 'ffcertificate' ),
            'confirmed' => __( 'Confirmed', 'ffcertificate' ),
            'cancelled' => __( 'Cancelled', 'ffcertificate' ),
            'completed' => __( 'Completed', 'ffcertificate' ),
            'no_show'   => __( 'No Show', 'ffcertificate' ),
        );
        $status = $appointment['status'] ?? 'pending';
        $status_label = $status_labels[ $status ] ?? $status;

        // Format validation code
        $display_code = '';
        if ( ! empty( $appointment['validation_code'] ) ) {
            $display_code = \FreeFormCertificate\Core\Utils::format_auth_code( $appointment['validation_code'] );
        }

        // Format CPF/RF
        $cpf_rf_display = '';
        if ( ! empty( $data['cpf_rf'] ) ) {
            $cpf_rf_display = \FreeFormCertificate\Core\Utils::format_document( $data['cpf_rf'] );
        }

        // Build HTML - uses same structure as certificate-preview.php for visual consistency
        $html = '<div class="ffc-certificate-preview ffc-appointment-verification">';

        // Header with gradient badge
        $html .= '<div class="ffc-preview-header">';
        $html .= '<span class="ffc-status-badge success">✅ ' . esc_html__( 'Appointment Receipt Valid', 'ffcertificate' ) . '</span>';
        $html .= '<br><span class="ffc-appointment-status ffc-status-' . esc_attr( $status ) . '">' . esc_html( $status_label ) . '</span>';
        $html .= '</div>';

        // Body with detail rows
        $html .= '<div class="ffc-preview-body">';
        $html .= '<h3>' . esc_html__( 'Appointment Details', 'ffcertificate' ) . '</h3>';

        // Validation code
        if ( ! empty( $display_code ) ) {
            $html .= '<div class="ffc-detail-row">';
            $html .= '<span class="label">' . esc_html__( 'Validation Code:', 'ffcertificate' ) . '</span>';
            $html .= '<span class="value code">' . esc_html( $display_code ) . '</span>';
            $html .= '</div>';
        }

        // Event
        if ( ! empty( $data['calendar_title'] ) ) {
            $html .= '<div class="ffc-detail-row">';
            $html .= '<span class="label">' . esc_html__( 'Event:', 'ffcertificate' ) . '</span>';
            $html .= '<span class="value">' . esc_html( $data['calendar_title'] ) . '</span>';
            $html .= '</div>';
        }

        // Date
        $html .= '<div class="ffc-detail-row">';
        $html .= '<span class="label">' . esc_html__( 'Date:', 'ffcertificate' ) . '</span>';
        $html .= '<span class="value">' . esc_html( $formatted_date ) . '</span>';
        $html .= '</div>';

        // Time
        $html .= '<div class="ffc-detail-row">';
        $html .= '<span class="label">' . esc_html__( 'Time:', 'ffcertificate' ) . '</span>';
        $html .= '<span class="value">' . esc_html( $formatted_time ) . '</span>';
        $html .= '</div>';

        $html .= '<hr>';
        $html .= '<h4>' . esc_html__( 'Participant Data:', 'ffcertificate' ) . '</h4>';

        // Name
        if ( ! empty( $data['name'] ) ) {
            $html .= '<div class="ffc-detail-row">';
            $html .= '<span class="label">' . esc_html__( 'Name:', 'ffcertificate' ) . '</span>';
            $html .= '<span class="value">' . esc_html( $data['name'] ) . '</span>';
            $html .= '</div>';
        }

        // CPF/RF
        if ( ! empty( $cpf_rf_display ) ) {
            $html .= '<div class="ffc-detail-row">';
            $html .= '<span class="label">' . esc_html__( 'CPF/RF:', 'ffcertificate' ) . '</span>';
            $html .= '<span class="value">' . esc_html( $cpf_rf_display ) . '</span>';
            $html .= '</div>';
        }

        // Booked on
        $html .= '<div class="ffc-detail-row">';
        $html .= '<span class="label">' . esc_html__( 'Booked on:', 'ffcertificate' ) . '</span>';
        $html .= '<span class="value">' . esc_html( $formatted_created ) . '</span>';
        $html .= '</div>';

        $html .= '</div>'; // .ffc-preview-body

        // Download button (same structure as certificate)
        $html .= '<div class="ffc-preview-actions">';
        $html .= '<button class="ffc-download-btn ffc-download-pdf-btn">⬇️ ' . esc_html__( 'Download Receipt (PDF)', 'ffcertificate' ) . '</button>';
        $html .= '</div>';

        $html .= '</div>'; // .ffc-certificate-preview

        return $html;
    }
}
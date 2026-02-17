<?php
declare(strict_types=1);

/**
 * FormProcessor
 * Handles form submission processing, validation, and restriction checks.
 *
 * v2.9.2: Unified PDF generation with FFC_PDF_Generator
 * v2.9.11: Using FFC_Utils for validation and sanitization
 * v2.9.13: Optimized detect_reprint() to use cpf_rf column with fallback
 * v2.10.0: LGPD - Validates consent checkbox (mandatory)
 * v3.3.0: Added strict types and type hints
 * v3.2.0: Migrated to namespace (Phase 2)
 * v4.12.17: Extracted AccessRestrictionChecker and ReprintDetector for SRP compliance.
 */

namespace FreeFormCertificate\Frontend;

use FreeFormCertificate\Submissions\SubmissionHandler;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


class FormProcessor {

    private $submission_handler;
    private $email_handler;

    /**
     * Constructor
     */
    public function __construct( SubmissionHandler $submission_handler, $email_handler ) {
        $this->submission_handler = $submission_handler;
        $this->email_handler = $email_handler;

        // AJAX hooks registered in Frontend::register_hooks() to avoid duplicate registration.
    }

    /**
     * Handle form submission via AJAX
     */
    public function handle_submission_ajax(): void {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ffc_frontend_nonce')) {
            wp_send_json_error(['message' => __('Security check failed. Please refresh the page.', 'ffcertificate')]);
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above via wp_verify_nonce.

        // ===== DEBUG CAPTCHA =====
        \FreeFormCertificate\Core\Debug::log_form('===== CAPTCHA DEBUG =====');
        \FreeFormCertificate\Core\Debug::log_form('Answer received', isset($_POST['ffc_captcha_ans']) ? sanitize_text_field(wp_unslash($_POST['ffc_captcha_ans'])) : 'NOT SET');
        \FreeFormCertificate\Core\Debug::log_form('Hash received', isset($_POST['ffc_captcha_hash']) ? sanitize_text_field(wp_unslash($_POST['ffc_captcha_hash'])) : 'NOT SET');

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- isset() check only; values sanitized inside block.
        if (isset($_POST['ffc_captcha_ans']) && isset($_POST['ffc_captcha_hash'])) {
            $test_answer = trim(sanitize_text_field(wp_unslash($_POST['ffc_captcha_ans'])));
            $received_hash = sanitize_text_field(wp_unslash($_POST['ffc_captcha_hash']));
            $generated_hash = wp_hash($test_answer . 'ffc_math_salt');

            \FreeFormCertificate\Core\Debug::log_form('Trimmed answer', $test_answer);
            \FreeFormCertificate\Core\Debug::log_form('Generated hash from answer', $generated_hash);
            \FreeFormCertificate\Core\Debug::log_form('Hashes match', $generated_hash === $received_hash ? 'YES' : 'NO');

            // Test with different variations
            \FreeFormCertificate\Core\Debug::log_form('Test with (int)', wp_hash((int)$test_answer . 'ffc_math_salt'));
            \FreeFormCertificate\Core\Debug::log_form('Test with (string)', wp_hash((string)$test_answer . 'ffc_math_salt'));
        }
        \FreeFormCertificate\Core\Debug::log_form('===== END CAPTCHA DEBUG =====');
        // ===== END DEBUG =====
        
        // Validate security fields using FFC_Utils
        $security_check = \FreeFormCertificate\Core\Utils::validate_security_fields($_POST);
        if ( $security_check !== true ) {
            // Generate new captcha for retry
            $new_captcha = \FreeFormCertificate\Core\Utils::generate_simple_captcha();
            wp_send_json_error( array(
                'message' => $security_check,
                'refresh_captcha' => true,
                'new_label' => $new_captcha['label'],
                'new_hash' => $new_captcha['hash'],
            ) );
        }

        $form_id = isset( $_POST['form_id'] ) ? absint( wp_unslash( $_POST['form_id'] ) ) : 0;
        if ( ! $form_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid Form ID.', 'ffcertificate' ) ) );
        }

        $form_config = get_post_meta( $form_id, '_ffc_form_config', true );
        if ( ! is_array( $form_config ) ) $form_config = array();
        
        $fields_config = get_post_meta( $form_id, '_ffc_form_fields', true );
        if ( ! $fields_config ) {
            wp_send_json_error( array( 'message' => __( 'Form configuration not found.', 'ffcertificate' ) ) );
        }

        // Process and sanitize form fields using FFC_Utils
        $submission_data = array();
        $user_email = '';

        // Name fields that should be normalized (capitalized with lowercase connectives)
        $name_fields = array( 'nome_completo', 'nome', 'name', 'full_name', 'ffc_nome', 'participante' );

        foreach ( $fields_config as $field ) {
            // Skip display-only field types (no user input)
            if ( isset( $field['type'] ) && in_array( $field['type'], array( 'info', 'embed' ), true ) ) {
                continue;
            }

            $name = $field['name'];
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- isset() check only; value unslashed and sanitized below.
            if ( isset( $_POST[ $name ] ) ) {
                $value = \FreeFormCertificate\Core\Utils::recursive_sanitize( wp_unslash( $_POST[ $name ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via recursive_sanitize().

                // Normalize name fields (proper capitalization with lowercase connectives)
                if ( in_array( $name, $name_fields, true ) && is_string( $value ) && ! empty( $value ) ) {
                    $value = \FreeFormCertificate\Core\Utils::normalize_brazilian_name( $value );
                }

                // Special validation for CPF/RF
                if ( $name === 'cpf_rf' ) {
                    $value = preg_replace( '/\D/', '', $value );
                    
                    // Validate length
                    if ( strlen($value) !== 7 && strlen($value) !== 11 ) {
                        wp_send_json_error( array( 'message' => __( 'CPF/RF must be exactly 7 or 11 digits.', 'ffcertificate' ) ) );
                    }
                    
                    // Validate CPF (11 digits) using official algorithm
                    if ( strlen($value) === 11 ) {
                        if ( ! \FreeFormCertificate\Core\Utils::validate_cpf( $value ) ) {
                            wp_send_json_error( array( 'message' => __( 'Invalid CPF. Please check the number and try again.', 'ffcertificate' ) ) );
                        }
                    }
                    
                    // Validate RF (7 digits) - must be numeric
                    if ( strlen($value) === 7 ) {
                        if ( ! \FreeFormCertificate\Core\Utils::validate_rf( $value ) ) {
                            wp_send_json_error( array( 'message' => __( 'Invalid RF. Must contain only numbers.', 'ffcertificate' ) ) );
                        }
                    }
                }
                
                $submission_data[ $name ] = $value;

                if ( isset($field['type']) && $field['type'] === 'email' ) {
                    // Normalize email to lowercase for consistent storage and lookups
                    $user_email = strtolower( sanitize_email( $value ) );
                }
            }
        }

        if ( empty( $user_email ) ) {
            wp_send_json_error( array( 'message' => __( 'Email address is required.', 'ffcertificate' ) ) );
        }
        // Validate LGPD consent (mandatory)
        if ( empty( $_POST['ffc_lgpd_consent'] ) || sanitize_text_field( wp_unslash( $_POST['ffc_lgpd_consent'] ) ) !== '1' ) {
            wp_send_json_error( array( 
                'message' => __( 'You must agree to the Privacy Policy to continue.', 'ffcertificate' ) 
            ) );
        }
        
        // Add consent to submission data
        $submission_data['ffc_lgpd_consent'] = '1';

        // Capture restriction fields (password/ticket) from POST
        $val_password = isset($_POST['ffc_password']) ? trim(sanitize_text_field(wp_unslash($_POST['ffc_password']))) : '';
        $val_ticket = isset($_POST['ffc_ticket']) ? strtoupper(trim(sanitize_text_field(wp_unslash($_POST['ffc_ticket'])))) : '';
        
        $val_cpf = isset($submission_data['cpf_rf']) ? trim($submission_data['cpf_rf']) : '';

        // Rate Limit Check
        if (class_exists('\FreeFormCertificate\Security\RateLimiter')) {
            $ip = \FreeFormCertificate\Core\Utils::get_user_ip();
            $email = $user_email;
            $cpf = $val_cpf;
            
            $rate_check = \FreeFormCertificate\Security\RateLimiter::check_all($ip, $email, $cpf, $form_id);
            
            if (!$rate_check['allowed']) {
                wp_send_json_error(array(
                    'message' => $rate_check['message'] ?? 'Rate limit exceeded.',
                    'rate_limit' => true,
                    'wait_seconds' => $rate_check['wait_seconds'] ?? 0
                ));
            }
            
            // Record attempt
            \FreeFormCertificate\Security\RateLimiter::record_attempt('ip', $ip, $form_id);
            if ($email) \FreeFormCertificate\Security\RateLimiter::record_attempt('email', $email, $form_id);
            if ($cpf) \FreeFormCertificate\Security\RateLimiter::record_attempt('cpf', preg_replace('/[^0-9]/', '', $cpf), $form_id);
        }

        // Geofence validation (date/time + geolocation)
        if (class_exists('\FreeFormCertificate\Security\Geofence')) {
            // Get form geofence config to check if IP validation is enabled
            $geofence_config = \FreeFormCertificate\Security\Geofence::get_form_config($form_id);
            $should_validate_ip = false;

            // Backend validation logic:
            // - Always validate datetime (server-side is authoritative)
            // - Only validate IP geolocation if explicitly enabled
            // - GPS validation happens on frontend (browser geolocation API)
            //   Note: GPS-only mode relies on frontend validation; backend cannot verify GPS
            if ($geofence_config && !empty($geofence_config['geo_enabled']) && !empty($geofence_config['geo_ip_enabled'])) {
                $should_validate_ip = true;
            }

            $geofence_check = \FreeFormCertificate\Security\Geofence::can_access_form($form_id, array(
                'check_datetime' => true,        // Always validate date/time server-side
                'check_geo' => $should_validate_ip, // Only validate IP if explicitly enabled
            ));

            if (!$geofence_check['allowed']) {
                wp_send_json_error(array(
                    'message' => $geofence_check['message'],
                    'geofence_blocked' => true,
                    'reason' => $geofence_check['reason']
                ));
            }
        }

        // Check restrictions (whitelist/denylist/tickets) — delegated to AccessRestrictionChecker
        $restriction_result = AccessRestrictionChecker::check( $form_config, $val_cpf, $val_ticket, $form_id );
        
        if ( ! $restriction_result['allowed'] ) {
            wp_send_json_error( array( 'message' => $restriction_result['message'] ) );
        }

        // === Quiz Mode Processing (v4.9.0) ===
        $is_quiz = ! empty( $form_config['quiz_enabled'] ) && $form_config['quiz_enabled'] === '1';
        $form_post = get_post( $form_id );
        $is_reprint = false;

        if ( $is_quiz ) {
            // Calculate quiz score
            $quiz_score = $this->calculate_quiz_score( $fields_config, $submission_data );
            $passing_score = absint( $form_config['quiz_passing_score'] ?? 70 );
            $max_attempts  = absint( $form_config['quiz_max_attempts'] ?? 0 );
            $passed = $quiz_score['percent'] >= $passing_score;

            // Store quiz data in submission
            $submission_data['_quiz_score']     = $quiz_score['score'];
            $submission_data['_quiz_max_score'] = $quiz_score['max_score'];
            $submission_data['_quiz_percent']   = $quiz_score['percent'];
            $submission_data['_quiz_passed']    = $passed ? '1' : '0';

            // Find existing quiz submission for this CPF + form
            $existing = $this->find_quiz_submission( $form_id, $val_cpf );

            // If already passed (status=publish), treat as reprint
            if ( $existing && $existing->status === 'publish' ) {
                $submission_id = (int) $existing->id;
                $real_submission_date = $existing->submission_date;
                $is_reprint = true;
            } else {
                // Count attempts
                $prev_attempt = 0;
                if ( $existing ) {
                    $prev_data = json_decode( $existing->data ?? '{}', true );
                    if ( ! is_array( $prev_data ) ) $prev_data = array();
                    $prev_attempt = absint( $prev_data['_quiz_attempt'] ?? 0 );
                }
                $attempt_number = $prev_attempt + 1;
                $submission_data['_quiz_attempt'] = $attempt_number;

                // Check attempt limit
                if ( $max_attempts > 0 && $attempt_number > $max_attempts ) {
                    wp_send_json_error( array(
                        'message' => __( 'Maximum quiz attempts reached for this CPF/RF.', 'ffcertificate' ),
                        'quiz'    => array( 'attempts_exhausted' => true ),
                    ) );
                }

                // Determine status
                if ( $passed ) {
                    $quiz_status = 'publish';
                } elseif ( $max_attempts > 0 && $attempt_number >= $max_attempts ) {
                    $quiz_status = 'quiz_failed';
                } else {
                    $quiz_status = 'quiz_in_progress';
                }

                if ( $existing ) {
                    // UPDATE existing submission
                    $submission_id = (int) $existing->id;
                    $repo = $this->submission_handler->get_repository();

                    // Build updated data JSON
                    $mandatory_keys = array( 'email', 'cpf_rf', 'auth_code', 'ffc_lgpd_consent' );
                    $extra_data = array_diff_key( $submission_data, array_flip( $mandatory_keys ) );
                    $data_json = wp_json_encode( $extra_data ) ?: '{}';

                    $update_fields = array( 'status' => $quiz_status, 'submission_date' => current_time( 'mysql' ) );
                    if ( class_exists( '\FreeFormCertificate\Core\Encryption' ) && \FreeFormCertificate\Core\Encryption::is_configured() ) {
                        $update_fields['data_encrypted'] = \FreeFormCertificate\Core\Encryption::encrypt( $data_json );
                    } else {
                        $update_fields['data'] = $data_json;
                    }

                    $repo->update( $submission_id, $update_fields );
                    $real_submission_date = current_time( 'mysql' );
                } else {
                    // INSERT new submission via existing handler
                    $submission_id = $this->submission_handler->process_submission(
                        $form_id, $form_post->post_title, $submission_data, $user_email, $fields_config, $form_config
                    );

                    if ( is_wp_error( $submission_id ) ) {
                        wp_send_json_error( array(
                            'code'    => $submission_id->get_error_code(),
                            'message' => $submission_id->get_error_message(),
                        ) );
                    }

                    // Update status if not publish
                    if ( $quiz_status !== 'publish' ) {
                        $this->submission_handler->get_repository()->updateStatus( $submission_id, $quiz_status );
                    }

                    $real_submission_date = current_time( 'mysql' );
                }

                // If not passed, return quiz feedback (no certificate)
                if ( ! $passed ) {
                    $remaining = ( $max_attempts > 0 ) ? max( 0, $max_attempts - $attempt_number ) : -1;
                    $show_score = ( $form_config['quiz_show_score'] ?? '1' ) === '1';

                    if ( $quiz_status === 'quiz_failed' ) {
                        $msg = $show_score
                            /* translators: %d: quiz score percentage */
                            ? sprintf( __( 'Quiz failed. Score: %d%%. Maximum attempts reached.', 'ffcertificate' ), $quiz_score['percent'] )
                            : __( 'Quiz failed. Maximum attempts reached.', 'ffcertificate' );
                    } else {
                        $msg = $show_score
                            /* translators: 1: score percentage, 2: remaining attempts */
                            ? sprintf( __( 'Score: %1$d%%. You can try again (%2$d attempts remaining).', 'ffcertificate' ), $quiz_score['percent'], $remaining )
                            /* translators: %d: number of remaining quiz attempts */
                            : sprintf( __( 'Not passed. You can try again (%d attempts remaining).', 'ffcertificate' ), $remaining );

                        if ( $remaining === -1 ) {
                            $msg = $show_score
                                /* translators: %d: quiz score percentage */
                                ? sprintf( __( 'Score: %d%%. You can try again.', 'ffcertificate' ), $quiz_score['percent'] )
                                : __( 'Not passed. You can try again.', 'ffcertificate' );
                        }
                    }

                    wp_send_json_error( array(
                        'message' => $msg,
                        'quiz'    => array(
                            'passed'    => false,
                            'score'     => $show_score ? $quiz_score['score'] : null,
                            'max_score' => $show_score ? $quiz_score['max_score'] : null,
                            'percent'   => $show_score ? $quiz_score['percent'] : null,
                            'attempt'   => $attempt_number,
                            'remaining' => $remaining,
                            'status'    => $quiz_status,
                        ),
                    ) );
                }
            }
        } else {
            // === Normal (non-quiz) flow ===

            // Detect reprint — delegated to ReprintDetector
            $reprint_result = ReprintDetector::detect( $form_id, $val_cpf, $val_ticket );
            $is_reprint = $reprint_result['is_reprint'];

            if ( $is_reprint ) {
                // Reprint - use existing submission ID (convert to int from wpdb string)
                $submission_id = (int) $reprint_result['id'];
                $real_submission_date = $reprint_result['date'];
            } else {
                // New submission - save to database
                $submission_id = $this->submission_handler->process_submission(
                    $form_id,
                    $form_post->post_title,
                    $submission_data,
                    $user_email,
                    $fields_config,
                    $form_config
                );

                if ( is_wp_error( $submission_id ) ) {
                    wp_send_json_error( array(
                        'code'    => $submission_id->get_error_code(),
                        'message' => $submission_id->get_error_message(),
                    ) );
                }

                // Get the submission date from the newly created submission
                $real_submission_date = current_time( 'mysql' );

                // Remove used ticket if applicable — delegated to AccessRestrictionChecker
                if ( $restriction_result['is_ticket'] && ! empty( $val_ticket ) ) {
                    AccessRestrictionChecker::consume_ticket( $form_id, $val_ticket );
                }
            }
        }

        // Generate PDF data
        $pdf_generator = new \FreeFormCertificate\Generators\PdfGenerator( $this->submission_handler );
        $pdf_data = $pdf_generator->generate_pdf_data(
            $submission_id,
            $this->submission_handler
        );

        if ( is_wp_error( $pdf_data ) ) {
            wp_send_json_error( array(
                'code'    => $pdf_data->get_error_code(),
                'message' => $pdf_data->get_error_message(),
            ) );
        }

        // Success message with HTML response (v2.9.7+)
        $custom_message = isset( $form_config['success_message'] ) ? trim( $form_config['success_message'] ) : '';
        $msg = $is_reprint
            ? __( 'Certificate previously issued (Reprint).', 'ffcertificate' )
            : ( ! empty( $custom_message ) ? $custom_message : __( 'Success!', 'ffcertificate' ) );

        // Quiz passed message
        if ( $is_quiz && ! $is_reprint ) {
            $show_score = ( $form_config['quiz_show_score'] ?? '1' ) === '1';
            $msg = $show_score
                /* translators: %d: quiz score percentage */
                ? sprintf( __( 'Congratulations! Score: %d%%. Certificate generated.', 'ffcertificate' ), $quiz_score['percent'] ?? 0 )
                : __( 'Congratulations! Quiz passed. Certificate generated.', 'ffcertificate' );
        }

        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $response = array(
            'message'  => $msg,
            'pdf_data' => $pdf_data,
            'html'     => \FreeFormCertificate\Core\Utils::generate_success_html(
                $submission_data,
                $form_id,
                $real_submission_date,
                $msg
            ),
        );

        // Add quiz data to success response
        if ( $is_quiz && isset( $quiz_score ) ) {
            $show_score = ( $form_config['quiz_show_score'] ?? '1' ) === '1';
            $response['quiz'] = array(
                'passed'    => true,
                'score'     => $show_score ? $quiz_score['score'] : null,
                'max_score' => $show_score ? $quiz_score['max_score'] : null,
                'percent'   => $show_score ? $quiz_score['percent'] : null,
            );
        }

        wp_send_json_success( $response );
    }

    /**
     * Calculate quiz score based on field points
     *
     * @param array $fields_config Form fields configuration
     * @param array $submission_data User's submitted data
     * @return array{score: int, max_score: int, percent: int}
     */
    private function calculate_quiz_score( array $fields_config, array $submission_data ): array {
        $score = 0;
        $max_score = 0;

        foreach ( $fields_config as $field ) {
            $type = $field['type'] ?? '';
            $points_str = $field['points'] ?? '';

            // Only radio/select fields with points participate in scoring
            if ( empty( $points_str ) || ! in_array( $type, array( 'radio', 'select' ), true ) ) {
                continue;
            }

            $options = array_map( 'trim', explode( ',', $field['options'] ?? '' ) );
            $points  = array_map( 'intval', array_map( 'trim', explode( ',', $points_str ) ) );

            // Max score: highest point value for this field
            $field_max = ! empty( $points ) ? max( $points ) : 0;
            $max_score += $field_max;

            // User's answer
            $name = $field['name'] ?? '';
            $user_value = isset( $submission_data[ $name ] ) ? trim( (string) $submission_data[ $name ] ) : '';

            // Find matching option index and get its points
            foreach ( $options as $i => $opt ) {
                if ( trim( $opt ) === $user_value && isset( $points[ $i ] ) ) {
                    $score += $points[ $i ];
                    break;
                }
            }
        }

        $percent = $max_score > 0 ? (int) round( ( $score / $max_score ) * 100 ) : 0;

        return array(
            'score'     => $score,
            'max_score' => $max_score,
            'percent'   => $percent,
        );
    }

    /**
     * Find existing quiz submission for a CPF + form combination
     *
     * Returns the most recent submission (any quiz status: in_progress, failed, or publish).
     *
     * @param int    $form_id Form ID
     * @param string $cpf     CPF/RF value
     * @return object|null
     */
    private function find_quiz_submission( int $form_id, string $cpf ): ?object {
        if ( empty( $cpf ) ) {
            return null;
        }

        global $wpdb;
        $table = \FreeFormCertificate\Core\Utils::get_submissions_table();
        $clean_cpf = preg_replace( '/[^0-9]/', '', $cpf );

        if ( class_exists( '\FreeFormCertificate\Core\Encryption' ) && \FreeFormCertificate\Core\Encryption::is_configured() ) {
            $cpf_hash = \FreeFormCertificate\Core\Encryption::hash( $clean_cpf );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            return $wpdb->get_row( $wpdb->prepare(
                'SELECT * FROM %i WHERE form_id = %d AND cpf_rf_hash = %s ORDER BY id DESC LIMIT 1',
                $table,
                $form_id,
                $cpf_hash
            ) );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM %i WHERE form_id = %d AND cpf_rf = %s ORDER BY id DESC LIMIT 1',
            $table,
            $form_id,
            $clean_cpf
        ) );
    }
}
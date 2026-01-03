<?php
/**
 * FFC_Shortcodes
 * Handles shortcode rendering for forms and verification pages.
 * 
 * v2.8.0: Added magic link detection and certificate preview
 * v2.9.0: Added hash-based token support (#token=)
 * v2.9.2: OPTIMIZED to use FFC_Utils functions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Shortcodes {
    
    private $form_processor;
    private $verification_handler;
    private $submission_handler;

    /**
     * Constructor
     * 
     * @param FFC_Form_Processor $form_processor
     * @param FFC_Verification_Handler $verification_handler
     * @param FFC_Submission_Handler $submission_handler Added in v2.8.0
     */
    public function __construct( $form_processor, $verification_handler, $submission_handler = null ) {
        $this->form_processor = $form_processor;
        $this->verification_handler = $verification_handler;
        $this->submission_handler = $submission_handler;
    }

    /**
     * Generate new captcha data (math question + hash)
     */
    public function get_new_captcha_data() {
        $n1 = rand( 1, 9 );
        $n2 = rand( 1, 9 );
        return array(
            'label' => sprintf( esc_html__( 'Security: How much is %d + %d?', 'ffc' ), $n1, $n2 ) . ' <span class="required">*</span>',
            'hash'  => wp_hash( ($n1 + $n2) . 'ffc_math_salt' )
        );
    }

    /**
     * Generate HTML for security fields (honeypot + captcha)
     */
    public function generate_security_fields() {
        $captcha = $this->get_new_captcha_data();
        ob_start();
        ?>
        <div class="ffc-security-container">
            <div class="ffc-honeypot-field">
                <label><?php esc_html_e('Do not fill this field if you are human:', 'ffc'); ?></label>
                <input type="text" name="ffc_honeypot_trap" value="" tabindex="-1" autocomplete="off">
            </div>

            <div class="ffc-captcha-row">
                <label for="ffc_captcha_ans">
                    <?php echo $captcha['label']; ?>
                </label>
                <input type="number" name="ffc_captcha_ans" id="ffc_captcha_ans" class="ffc-input" required>
                <input type="hidden" name="ffc_captcha_hash" value="<?php echo esc_attr( $captcha['hash'] ); ?>">
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render certificate preview for magic link access
     * 
     * @since 2.8.0 Magic Links feature
     * @param string $token Magic token
     * @return string HTML output
     */
    private function render_magic_link_preview( $token ) {
        // ✅ OPTIMIZED v2.9.2: Log magic link access
        FFC_Utils::debug_log( 'Magic link shortcode rendered', array(
            'token' => substr( $token, 0, 8 ) . '...',
            'ip' => FFC_Utils::get_user_ip()
        ) );
        
        ob_start();
        ?>
        <div class="ffc-verification-container ffc-magic-link-container" data-token="<?php echo esc_attr( $token ); ?>">
            <div class="ffc-verify-loading">
                <div class="ffc-spinner"></div>
                <p><?php esc_html_e( 'Verifying certificate...', 'ffc' ); ?></p>
            </div>
            <div class="ffc-verify-result"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Shortcode: [ffc_verification]
     * Displays certificate verification form (or magic link preview)
     * 
     * v2.8.0: Detects ?token= parameter and renders preview instead of form
     * v2.9.0: Also supports #token= (hash format) via JavaScript detection
     */
    public function render_verification_page( $atts ) {
        // Check for magic token in URL query string (?token=)
        $magic_token = isset( $_GET['token'] ) ? sanitize_text_field( $_GET['token'] ) : '';
        
        if ( ! empty( $magic_token ) ) {
            // Magic link access via query string - render preview container
            return $this->render_magic_link_preview( $magic_token );
        }

        // ✅ OPTIMIZED v2.9.2: Log verification page render
        FFC_Utils::debug_log( 'Verification shortcode rendered', array(
            'ip' => FFC_Utils::get_user_ip(),
            'has_token' => ! empty( $magic_token )
        ) );

        // Render container that supports both manual verification AND hash detection
        // JavaScript will check for #token= in URL and trigger auto-verification
        ob_start();
        ?>
        <div class="ffc-verification-container ffc-verification-auto-check">
            <!-- Loading (hidden initially, shown by JS if hash token found) -->
            <div class="ffc-verify-loading" style="display:none;">
                <div class="ffc-spinner"></div>
                <p><?php esc_html_e( 'Verifying certificate...', 'ffc' ); ?></p>
            </div>
            
            <!-- Manual verification form -->
            <div class="ffc-verification-manual">
                <div class="ffc-verification-header">
                    <h2><?php esc_html_e( 'Verify Certificate', 'ffc' ); ?></h2>
                    <p><?php esc_html_e( 'Enter the authentication code to verify the certificate authenticity.', 'ffc' ); ?></p>
                </div>
                
                <form method="POST" class="ffc-verification-form">
                    <div class="ffc-verify-input-group">
                        <input 
                            type="text" 
                            name="ffc_auth_code" 
                            class="ffc-input ffc-verify-input" 
                            placeholder="<?php esc_attr_e( 'XXXX-XXXX-XXXX', 'ffc' ); ?>" 
                            required 
                            maxlength="14"
                            pattern="[A-Za-z0-9\-]+"
                        >
                        <button type="submit" class="ffc-submit-btn"><?php esc_html_e( 'Verify', 'ffc' ); ?></button>
                    </div>
                    <div class="ffc-no-js-security"><?php echo $this->generate_security_fields(); ?></div>
                </form>
            </div>
            
            <!-- Verification result -->
            <div class="ffc-verify-result"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Shortcode: [ffc_form id="123"]
     * Displays certificate issuance form
     */
    public function render_form( $atts ) {
        $atts = shortcode_atts( array( 'id' => 0 ), $atts, 'ffc_form' );
        $form_id = absint( $atts['id'] );
        
        if ( ! $form_id || get_post_type( $form_id ) !== 'ffc_form' ) {
            // ✅ OPTIMIZED v2.9.2: Log invalid form access
            FFC_Utils::debug_log( 'Invalid form shortcode', array(
                'form_id' => $form_id,
                'ip' => FFC_Utils::get_user_ip()
            ) );
            return '<p>' . esc_html__( 'Form not found.', 'ffc' ) . '</p>';
        }

        $form_post  = get_post( $form_id );
        $form_title = $form_post ? $form_post->post_title : '';
        $fields = get_post_meta( $form_id, '_ffc_form_fields', true );
        
        if ( empty( $fields ) ) {
            // ✅ OPTIMIZED v2.9.2: Log form with no fields
            FFC_Utils::debug_log( 'Form has no fields', array(
                'form_id' => $form_id
            ) );
            return '<p>' . esc_html__( 'Form has no fields.', 'ffc' ) . '</p>';
        }

        // ✅ OPTIMIZED v2.9.2: Log form render
        FFC_Utils::debug_log( 'Form shortcode rendered', array(
            'form_id' => $form_id,
            'form_title' => FFC_Utils::truncate( $form_title, 50 ),
            'fields_count' => count( $fields ),
            'ip' => FFC_Utils::get_user_ip()
        ) );

        ob_start();
        ?>
        <div class="ffc-form-wrapper" id="ffc-form-<?php echo esc_attr( $form_id ); ?>">
            <h2 class="ffc-form-title"><?php echo esc_html( $form_title ); ?></h2>
            <form class="ffc-submission-form" id="ffc-form-element-<?php echo esc_attr( $form_id ); ?>">
                <input type="hidden" name="form_id" value="<?php echo esc_attr( $form_id ); ?>">
                
                <?php foreach ( $fields as $field ) : 
                    echo $this->render_field( $field );
                endforeach; ?>

                <?php echo $this->generate_security_fields(); ?>

                <button type="submit" class="ffc-submit-btn"><?php esc_html_e( 'Submit', 'ffc' ); ?></button>
                <div class="ffc-message"></div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render individual form field
     */
    private function render_field( $field ) {
        $type = isset($field['type']) ? $field['type'] : 'text';
        $name = isset($field['name']) ? $field['name'] : '';
        $label = isset($field['label']) ? $field['label'] : '';
        $default = isset($field['default_value']) ? $field['default_value'] : '';
        $is_req = ! empty( $field['required'] );
        $required_attr = $is_req ? 'required' : '';
        $options = ! empty( $field['options'] ) ? explode( ',', $field['options'] ) : array();

        if ( empty( $name ) ) return '';
        
        // Special treatment for CPF/RF
        if ( $name === 'cpf_rf' ) $type = 'tel'; 

        // Render hidden field outside the visual structure
        if ( $type === 'hidden' ) {
            return '<input type="hidden" name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '" value="' . esc_attr( $default ) . '">';
        }

        ob_start();
        ?>
        <div class="ffc-form-field">
            <label for="<?php echo esc_attr( $name ); ?>">
                <?php echo esc_html( $label ); ?> 
                <?php if ( $is_req ) echo '<span class="required">*</span>'; ?>
            </label>
            
            <?php if ( $type === 'textarea' ) : ?>
                <textarea class="ffc-input" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>" <?php echo $required_attr; ?>><?php echo esc_textarea($default); ?></textarea>

            <?php elseif ( $type === 'select' ) : ?>
                <select class="ffc-input" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>" <?php echo $required_attr; ?>>
                    <option value=""><?php esc_html_e( 'Select...', 'ffc' ); ?></option>
                    <?php foreach ( $options as $opt ) : $opt_val = trim($opt); ?>
                        <option value="<?php echo esc_attr( $opt_val ); ?>" <?php selected($default, $opt_val); ?>><?php echo esc_html( $opt_val ); ?></option>
                    <?php endforeach; ?>
                </select>

            <?php elseif ( $type === 'radio' ) : ?>
                <div class="ffc-radio-group">
                    <?php foreach ( $options as $opt ) : $opt_val = trim( $opt ); ?>
                        <label><input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $opt_val ); ?>" <?php echo $required_attr; ?> <?php checked($default, $opt_val); ?>> <?php echo esc_html( $opt_val ); ?></label>
                    <?php endforeach; ?>
                </div>

            <?php else : ?>
                <input class="ffc-input" type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $default ); ?>" <?php echo $required_attr; ?>>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
<?php
declare(strict_types=1);

/**
 * Shortcodes
 * Handles shortcode rendering for forms and verification pages.
 *
 * v2.8.0: Added magic link detection and certificate preview
 * v2.9.0: Added hash-based token support (#token=)
 * v2.9.2: OPTIMIZED to use FFC_Utils functions
 * v3.3.0: Added strict types and type hints
 * v3.2.0: Migrated to namespace (Phase 2)
 */

namespace FreeFormCertificate\Frontend;

use FreeFormCertificate\Submissions\SubmissionHandler;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Shortcodes {

    private $form_processor;
    private $verification_handler;
    private $submission_handler;

    /**
     * Constructor
     *
     * @param FormProcessor $form_processor
     * @param VerificationHandler $verification_handler
     * @param SubmissionHandler|null $submission_handler Added in v2.8.0
     */
    public function __construct( FormProcessor $form_processor, VerificationHandler $verification_handler, ?SubmissionHandler $submission_handler = null ) {
        $this->form_processor = $form_processor;
        $this->verification_handler = $verification_handler;
        $this->submission_handler = $submission_handler;
    }

    /**
     * Generate new captcha data (math question + hash)
     */
    public function get_new_captcha_data(): array {
        $n1 = wp_rand( 1, 9 );
        $n2 = wp_rand( 1, 9 );
        return array(
            /* translators: 1: first number, 2: second number */
            'label' => sprintf( esc_html__( 'Security: How much is %1$d + %2$d?', 'ffcertificate' ), $n1, $n2 ) . ' <span class="required">*</span>',
            'hash'  => wp_hash( ($n1 + $n2) . 'ffc_math_salt' )
        );
    }

    /**
     * Generate HTML for security fields (honeypot + captcha)
     */
    public function generate_security_fields(): string {
        $captcha = $this->get_new_captcha_data();
        ob_start();
        ?>
        <div class="ffc-security-container">
            <div class="ffc-honeypot-field">
                <label><?php esc_html_e('Do not fill this field if you are human:', 'ffcertificate'); ?></label>
                <input type="text" name="ffc_honeypot_trap" value="" tabindex="-1" autocomplete="off">
            </div>

            <div class="ffc-captcha-row">
                <label for="ffc_captcha_ans">
                    <?php echo wp_kses_post( $captcha['label'] ); ?>
                </label>
                <input type="number" name="ffc_captcha_ans" id="ffc_captcha_ans" class="ffc-input" required aria-required="true">
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
    private function render_magic_link_preview( string $token ): string {
        \FreeFormCertificate\Core\Utils::debug_log( 'Magic link shortcode rendered', array(
            'token' => substr( $token, 0, 8 ) . '...',
            'ip' => \FreeFormCertificate\Core\Utils::get_user_ip()
        ) );
        
        ob_start();
        ?>
        <div class="ffc-verification-container ffc-magic-link-container" data-token="<?php echo esc_attr( $token ); ?>">
            <div class="ffc-verify-loading" role="status" aria-live="polite">
                <div class="ffc-spinner" aria-hidden="true"></div>
                <p><?php esc_html_e( 'Verifying certificate...', 'ffcertificate' ); ?></p>
            </div>
            <div class="ffc-verify-result" role="region" aria-live="polite"></div>
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
    public function render_verification_page( array $atts ): string {
        // Check for magic token in URL query string (?token=)
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token is a display/routing parameter for verification page.
        $magic_token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
        
        if ( ! empty( $magic_token ) ) {
            // Magic link access via query string - render preview container
            return $this->render_magic_link_preview( $magic_token );
        }

        \FreeFormCertificate\Core\Utils::debug_log( 'Verification shortcode rendered', array(
            'ip' => \FreeFormCertificate\Core\Utils::get_user_ip(),
            'has_token' => ! empty( $magic_token )
        ) );

        // Render verification page using template
        $security_fields = $this->generate_security_fields();

        ob_start();
        include FFC_PLUGIN_DIR . 'templates/verification-page.php';
        return ob_get_clean();
    }

    /**
     * Shortcode: [ffc_form id="123"]
     * Displays certificate issuance form
     */
    public function render_form( array $atts ): string {
        $atts = shortcode_atts( array( 'id' => 0 ), $atts, 'ffc_form' );
        $form_id = absint( $atts['id'] );
        
        if ( ! $form_id || get_post_type( $form_id ) !== 'ffc_form' ) {
            \FreeFormCertificate\Core\Utils::debug_log( 'Invalid form shortcode', array(
                'form_id' => $form_id,
                'ip' => \FreeFormCertificate\Core\Utils::get_user_ip()
            ) );
            return '<p>' . esc_html__( 'Form not found.', 'ffcertificate' ) . '</p>';
        }

        $form_post  = get_post( $form_id );
        $form_title = $form_post ? $form_post->post_title : '';
        $fields = get_post_meta( $form_id, '_ffc_form_fields', true );
        
        if ( empty( $fields ) ) {
            \FreeFormCertificate\Core\Utils::debug_log( 'Form has no fields', array(
                'form_id' => $form_id
            ) );
            return '<p>' . esc_html__( 'Form has no fields.', 'ffcertificate' ) . '</p>';
        }

        \FreeFormCertificate\Core\Utils::debug_log( 'Form shortcode rendered', array(
            'form_id' => $form_id,
            'form_title' => \FreeFormCertificate\Core\Utils::truncate( $form_title, 50 ),
            'fields_count' => count( $fields ),
            'ip' => \FreeFormCertificate\Core\Utils::get_user_ip()
        ) );

        $geofence_config = get_post_meta( $form_id, '_ffc_geofence_config', true );
        $has_geofence = false;
        if ( is_array( $geofence_config ) ) {
            $has_datetime = ! empty( $geofence_config['datetime_enabled'] ) && $geofence_config['datetime_enabled'] == '1';
            $has_geo = ! empty( $geofence_config['geo_enabled'] ) && $geofence_config['geo_enabled'] == '1';
            $has_geofence = $has_datetime || $has_geo;
        }
        $wrapper_class = $has_geofence ? 'ffc-form-wrapper ffc-has-geofence' : 'ffc-form-wrapper';

        ob_start();
        ?>
        <div class="<?php echo esc_attr( $wrapper_class ); ?>" id="ffc-form-<?php echo esc_attr( $form_id ); ?>">
            <h2 class="ffc-form-title"><?php echo esc_html( $form_title ); ?></h2>
            <form class="ffc-submission-form" id="ffc-form-element-<?php echo esc_attr( $form_id ); ?>" autocomplete="off">
                <input type="hidden" name="form_id" value="<?php echo esc_attr( $form_id ); ?>">
                
                <?php foreach ( $fields as $field ) :
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_field() escapes all output internally
                    echo $this->render_field( $field );
                endforeach; ?>

                <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generate_security_fields() escapes all output internally
                echo $this->generate_security_fields();
                ?>

                <?php
                $form_config = get_post_meta($form_id, '_ffc_form_config', true);
                $restrictions = isset($form_config['restrictions']) ? $form_config['restrictions'] : array();

                // Password field (if active)
                if (!empty($restrictions['password']) && $restrictions['password'] == '1') {
                    ?>
                    <div class="ffc-form-field ffc-restriction-field">
                        <label for="ffc_password">
                            <?php esc_html_e('Password', 'ffcertificate'); ?> <span class="required">*</span>
                        </label>
                        <input type="password"
                               class="ffc-input"
                               name="ffc_password"
                               id="ffc_password"
                               required
                               aria-required="true"
                               autocomplete="off"
                               maxlength="20"
                               placeholder="<?php esc_attr_e('Enter password', 'ffcertificate'); ?>">
                    </div>
                    <?php
                }

                // Ticket field (if active)
                if (!empty($restrictions['ticket']) && $restrictions['ticket'] == '1') {
                    ?>
                    <div class="ffc-form-field ffc-restriction-field">
                        <label for="ffc_ticket">
                            <?php esc_html_e('Ticket Code', 'ffcertificate'); ?> <span class="required">*</span>
                        </label>
                        <input type="text"
                               class="ffc-input ffc-ticket-input ffc-uppercase"
                               name="ffc_ticket"
                               id="ffc_ticket"
                               required
                               aria-required="true"
                               placeholder="ABCD-1234"
                               autocomplete="off"
                               maxlength="9">
                        <p class="description"><?php esc_html_e('Enter your unique ticket code', 'ffcertificate'); ?></p>
                    </div>
                    <?php
                }
                ?>

                <div class="ffc-lgpd-consent">
                    <label class="ffc-consent-label">
                        <input type="checkbox"
                               name="ffc_lgpd_consent"
                               id="ffc_lgpd_consent"
                               required
                               aria-required="true"
                               value="1">
                        
                        <span class="ffc-consent-text">
                            <?php 
                            echo wp_kses_post( sprintf(
                                /* translators: %s: Privacy Policy page link */
                                esc_html__( 'I have read and agree to the %s and authorize the storage of my personal data for certificate issuance.', 'ffcertificate' ),
                                '<a href="' . esc_url( get_privacy_policy_url() ) . '" target="_blank">' . esc_html__( 'Privacy Policy', 'ffcertificate' ) . '</a>'
                            ) ); 
                            ?>
                            <span class="required">*</span>
                        </span>
                    </label>
                    
                    <p class="ffc-consent-description">
                        <?php esc_html_e( 'Your data will be stored securely and encrypted. You can request deletion at any time.', 'ffcertificate' ); ?>
                    </p>
                </div>

                <button type="submit" class="ffc-submit-btn"><?php esc_html_e( 'Submit', 'ffcertificate' ); ?></button>
                <div class="ffc-message" role="alert" aria-live="assertive"></div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render individual form field
     */
    private function render_field( array $field ): string {
        $type = isset($field['type']) ? $field['type'] : 'text';
        $name = isset($field['name']) ? $field['name'] : '';
        $label = isset($field['label']) ? $field['label'] : '';
        $default = isset($field['default_value']) ? $field['default_value'] : '';
        $is_req = ! empty( $field['required'] );
        $required_attr = $is_req ? 'required aria-required="true"' : '';
        $options = ! empty( $field['options'] ) ? explode( ',', $field['options'] ) : array();

        // Info block: display-only, no input
        if ( $type === 'info' ) {
            $content = isset( $field['content'] ) ? $field['content'] : '';
            if ( empty( $content ) && empty( $label ) ) return '';
            ob_start();
            ?>
            <div class="ffc-form-info-block">
                <?php if ( ! empty( $label ) ) : ?>
                    <h4 class="ffc-info-title"><?php echo esc_html( $label ); ?></h4>
                <?php endif; ?>
                <?php if ( ! empty( $content ) ) : ?>
                    <div class="ffc-info-content"><?php echo wp_kses_post( $content ); ?></div>
                <?php endif; ?>
            </div>
            <?php
            return ob_get_clean();
        }

        // Embed block: display media via oembed or img tag
        if ( $type === 'embed' ) {
            $embed_url = isset( $field['embed_url'] ) ? $field['embed_url'] : '';
            if ( empty( $embed_url ) ) return '';
            ob_start();
            ?>
            <div class="ffc-form-embed-block">
                <?php if ( ! empty( $label ) ) : ?>
                    <p class="ffc-embed-caption"><?php echo esc_html( $label ); ?></p>
                <?php endif; ?>
                <div class="ffc-embed-media">
                    <?php
                    // Check if URL is a direct image
                    if ( preg_match( '/\.(jpe?g|png|gif|webp|svg)(\?.*)?$/i', $embed_url ) ) {
                        echo '<img src="' . esc_url( $embed_url ) . '" alt="' . esc_attr( $label ) . '" class="ffc-embed-image">';
                    } else {
                        // Try oembed (YouTube, Vimeo, audio, etc.)
                        $embed_html = wp_oembed_get( $embed_url, array( 'width' => 600 ) );
                        if ( $embed_html ) {
                            echo $embed_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_oembed_get returns sanitized HTML from trusted providers.
                        } else {
                            // Fallback: show as a link
                            echo '<a href="' . esc_url( $embed_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $embed_url ) . '</a>';
                        }
                    }
                    ?>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }

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
                <textarea class="ffc-input ffc-textarea" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>" <?php echo $required_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static string: 'required aria-required="true"' ?> rows="1" style="height:auto!important;min-height:45px!important;max-height:300px!important;overflow-y:auto!important;resize:vertical!important"><?php echo esc_textarea($default); ?></textarea>

            <?php elseif ( $type === 'select' ) : ?>
                <select class="ffc-input" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>" <?php echo $required_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static string ?>>
                    <option value=""><?php esc_html_e( 'Select...', 'ffcertificate' ); ?></option>
                    <?php foreach ( $options as $opt ) : $opt_val = trim($opt); ?>
                        <option value="<?php echo esc_attr( $opt_val ); ?>" <?php selected($default, $opt_val); ?>><?php echo esc_html( $opt_val ); ?></option>
                    <?php endforeach; ?>
                </select>

            <?php elseif ( $type === 'radio' ) : ?>
                <div class="ffc-radio-group" role="group" aria-label="<?php echo esc_attr( $label ); ?>">
                    <?php foreach ( $options as $opt ) : $opt_val = trim( $opt ); ?>
                        <label><input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $opt_val ); ?>" <?php echo $required_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static string ?> <?php checked($default, $opt_val); ?>> <?php echo esc_html( $opt_val ); ?></label>
                    <?php endforeach; ?>
                </div>

            <?php else : ?>
                <input class="ffc-input" type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $default ); ?>" <?php echo $required_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static string ?>>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
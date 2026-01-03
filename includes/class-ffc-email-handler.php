<?php
/**
 * FFC_Email_Handler
 * Handles email configuration, sending, and certificate HTML generation.
 * 
 * v2.8.0: Added magic link support in emails
 * v2.9.0: Added QR Code placeholder support with hash-based URLs
 * v2.9.11: Using FFC_Utils for document formatting
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Email_Handler {
    
    public function __construct() {
        add_action( 'ffc_process_submission_hook', array( $this, 'async_process_submission' ), 10, 8 );
        add_action( 'phpmailer_init', array( $this, 'configure_custom_smtp' ) );
    }

    public function configure_custom_smtp( $phpmailer ) {
        $settings = get_option( 'ffc_settings', array() );
        
        if ( isset($settings['smtp_mode']) && $settings['smtp_mode'] === 'custom' ) {
            $phpmailer->isSMTP();
            $phpmailer->Host       = isset($settings['smtp_host']) ? $settings['smtp_host'] : '';
            $phpmailer->SMTPAuth   = true;
            $phpmailer->Port       = isset($settings['smtp_port']) ? (int) $settings['smtp_port'] : 587;
            $phpmailer->Username   = isset($settings['smtp_user']) ? $settings['smtp_user'] : '';
            $phpmailer->Password   = isset($settings['smtp_pass']) ? $settings['smtp_pass'] : '';
            $phpmailer->SMTPSecure = isset($settings['smtp_secure']) ? $settings['smtp_secure'] : 'tls';
            
            if ( ! empty( $settings['smtp_from_email'] ) ) {
                $phpmailer->From     = $settings['smtp_from_email'];
                $phpmailer->FromName = isset($settings['smtp_from_name']) ? $settings['smtp_from_name'] : get_bloginfo( 'name' );
            }
        }
    }

    public function async_process_submission( $submission_id, $form_id, $form_title, $submission_data, $user_email, $fields_config, $form_config, $magic_token = '' ) {
        if ( ! isset( $submission_data['email'] ) ) {
            $submission_data['email'] = $user_email;
        }
        
        // ⭐ IMPORTANTE: Adicionar submission_id para cache de QR Code
        if ( ! isset( $submission_data['submission_id'] ) ) {
            $submission_data['submission_id'] = $submission_id;
        }
        
        // ⭐ IMPORTANTE: Adicionar magic_token para QR Code
        if ( ! isset( $submission_data['magic_token'] ) && ! empty( $magic_token ) ) {
            $submission_data['magic_token'] = $magic_token;
        }
        
        $pdf_content = $this->generate_pdf_html( $submission_data, $form_title, $form_config );
        
        if ( isset( $form_config['send_user_email'] ) && $form_config['send_user_email'] == 1 ) {
            $this->send_user_email( $user_email, $form_title, $pdf_content, $form_config, $submission_data, $magic_token );
        }

        $this->send_admin_notification( $form_title, $submission_data, $form_config );
    }

    /**
     * Generate PDF HTML from template
     * 
     * Supported placeholders:
     * - {{name}}, {{email}}, {{auth_code}}, etc.
     * - {{qr_code}} - QR Code with default settings
     * - {{qr_code:size=150}} - Custom size
     * - {{qr_code:size=200:margin=0}} - Custom size and margin
     * - {{qr_code:error=H}} - Custom error correction
     * 
     * @param array $submission_data Submission data
     * @param string $form_title Form title
     * @param array $form_config Form configuration
     * @return string Processed HTML
     */
    public function generate_pdf_html( $submission_data, $form_title, $form_config ) {
        $layout = isset( $form_config['pdf_layout'] ) ? $form_config['pdf_layout'] : '';
        
        if ( empty( $layout ) ) {
            $layout = '<div style="text-align:center; padding: 50px;">
                        <h1>' . esc_html( $form_title ) . '</h1>
                        <p>' . esc_html__( 'We certify that the holder of the data below has completed the event.', 'ffc' ) . '</p>
                        <h2>{{name}}</h2>
                        <p>' . esc_html__( 'Authenticity:', 'ffc' ) . ' {{auth_code}}</p>
                      </div>';
        }

        // Replace standard placeholders
        // Note: {{validation_url}} is processed later with full parameter support
        $layout = str_replace( '{{submission_date}}', date_i18n( get_option( 'date_format' ), current_time( 'timestamp' ) ), $layout );
        $layout = str_replace( '{{form_title}}', $form_title, $layout );

        if ( !isset($submission_data['email']) && isset($submission_data['user_email']) ) {
             $submission_data['email'] = $submission_data['user_email'];
        }

        // Replace field placeholders
        foreach ( $submission_data as $key => $value ) {
            if ( is_array( $value ) ) {
                $value = implode( ', ', $value );
            }

            if ( in_array( $key, array( 'cpf', 'cpf_rf', 'rg' ) ) ) {
                $value = FFC_Utils::format_document( $value );
            }
            if ( $key === 'auth_code' ) {
                $value = FFC_Utils::format_auth_code( $value );
            }
            
            $safe_value = wp_kses( $value, FFC_Utils::get_allowed_html_tags() );
            $layout = str_replace( '{{' . $key . '}}', $safe_value, $layout );
        }

        // Fix relative URLs
        $site_url = untrailingslashit( get_home_url() );
        $layout = preg_replace('/(src|href|background)=["\']\/([^"\']+)["\']/i', '$1="' . $site_url . '/$2"', $layout);

        // ⭐ Process QR Code placeholders (v2.9.0)
        if ( strpos( $layout, '{{qr_code' ) !== false ) {
            $layout = $this->process_qr_code_placeholders( $layout, $submission_data );
        }

        // ⭐ Process Validation URL placeholders (v2.9.3)
        if ( strpos( $layout, '{{validation_url' ) !== false ) {
            $layout = $this->process_validation_url_placeholders( $layout, $submission_data );
        }

        return $layout;
    }

    /**
     * Process QR Code placeholders in template
     * 
     * Replaces {{qr_code}} and variants with actual QR Code images
     * 
     * @since 2.9.0
     * @param string $layout Template HTML
     * @param array $submission_data Submission data
     * @return string Processed HTML
     */
    private function process_qr_code_placeholders( $layout, $submission_data ) {
        // Initialize QR Code generator
        if ( ! class_exists( 'FFC_QRCode_Generator' ) ) {
            require_once FFC_PLUGIN_DIR . 'includes/class-ffc-qrcode-generator.php';
        }
        
        $qr_generator = new FFC_QRCode_Generator();
        
        // Determine target URL (magic link or verification page)
        $target_url = $this->get_qr_code_target_url( $submission_data );
        
        // Get submission ID if available
        $submission_id = isset( $submission_data['submission_id'] ) ? absint( $submission_data['submission_id'] ) : 0;
        
        // Replace all QR Code placeholders
        $layout = preg_replace_callback(
            '/\{\{qr_code(?::([^}]+))?\}\}/',
            function( $matches ) use ( $qr_generator, $target_url, $submission_id ) {
                $placeholder = $matches[0];
                return $qr_generator->parse_and_generate( $placeholder, $target_url, $submission_id );
            },
            $layout
        );
        
        return $layout;
    }

    /**
     * Get target URL for QR Code
     * 
     * Priority order:
     * 1. Magic link with hash format (if token exists)
     * 2. Verification page without parameters (fallback)
     * 
     * Format: /valid#token=xxx (hash prevents WordPress redirects)
     * 
     * @since 2.9.0
     * @param array $submission_data
     * @return string URL
     */
    private function get_qr_code_target_url( $submission_data ) {
        // Priority 1: Magic link (if exists)
        if ( isset( $submission_data['magic_token'] ) && ! empty( $submission_data['magic_token'] ) ) {
            $base_url = untrailingslashit( site_url( 'valid' ) );
            return $base_url . '#token=' . $submission_data['magic_token'];
        }
        
        // Priority 2: Verification page without parameters (fallback)
        return untrailingslashit( site_url( 'valid' ) );
    }

    private function send_user_email( $to, $form_title, $html_content, $form_config, $submission_data, $magic_token = '' ) {
        $subject = ! empty( $form_config['email_subject'] ) 
            ? $form_config['email_subject'] 
            : sprintf( __( 'Your Certificate: %s', 'ffc' ), $form_title );
        
        $magic_link_url = '';
        if ( ! empty( $magic_token ) ) {
            $base_url = untrailingslashit( site_url( 'valid' ) );
            $magic_link_url = $base_url . '#token=' . $magic_token;
        }

        $auth_code = isset( $submission_data['auth_code'] ) ? $submission_data['auth_code'] : '';
        if ( strlen( $auth_code ) === 12 ) {
            $auth_code = substr( $auth_code, 0, 4 ) . '-' . substr( $auth_code, 4, 4 ) . '-' . substr( $auth_code, 8, 4 );
        }

        $body_text = isset( $form_config['email_body'] ) ? wpautop( $form_config['email_body'] ) : '';
        
        $body  = '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Oxygen, Ubuntu, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; background: #f9f9f9; padding: 20px;">';
        
        $body .= '<div style="background: white; border-radius: 8px; padding: 30px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
        $body .= '<h2 style="margin: 0 0 20px 0; color: #0073aa; font-size: 24px;">' . esc_html__( 'Your Certificate has been Issued!', 'ffc' ) . '</h2>';
        $body .= $body_text;
        
        if ( ! empty( $auth_code ) ) {
            $body .= '<div style="background: #f5f5f5; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: center;">';
            $body .= '<p style="margin: 0 0 10px 0; font-size: 14px; color: #666;">' . esc_html__( 'Authentication Code:', 'ffc' ) . '</p>';
            $body .= '<p style="font-size: 24px; font-weight: bold; margin: 0; font-family: monospace; color: #0073aa; letter-spacing: 2px;">' . esc_html( $auth_code ) . '</p>';
            $body .= '</div>';
        }

        if ( ! empty( $magic_link_url ) ) {
            $body .= '<div style="text-align: center; margin: 30px 0;">';
            $body .= '<a href="' . esc_url( $magic_link_url ) . '" style="display: inline-block; background: #0073aa; color: white; padding: 15px 40px; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 16px; box-shadow: 0 2px 4px rgba(0,115,170,0.3);">';
            $body .= '🔗 ' . esc_html__( 'Access Certificate Online', 'ffc' );
            $body .= '</a>';
            $body .= '<p style="margin: 15px 0 0 0; font-size: 12px; color: #666;">' . esc_html__( 'Click the button above to view and download your certificate', 'ffc' ) . '</p>';
            $body .= '</div>';
        }

        $body .= '</div>';
        
        $body .= '<div style="background: white; border-radius: 8px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
        $body .= '<h3 style="margin: 0 0 15px 0; color: #333; font-size: 18px;">' . esc_html__( 'Certificate Preview:', 'ffc' ) . '</h3>';
        $body .= '<div style="border: 1px solid #eee; border-radius: 8px; overflow: hidden;">';
        $body .= $html_content;
        $body .= '</div></div>';
        
        $body .= '<div style="background: white; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
        $body .= '<p style="margin: 0; font-size: 12px; color: #999; text-align: center;">';
        $body .= esc_html__( 'You can also verify this certificate manually at', 'ffc' ) . ' ';
        $body .= '<a href="' . esc_url( untrailingslashit( site_url( 'valid' ) ) ) . '" style="color: #0073aa;">' . esc_url( untrailingslashit( site_url( 'valid' ) ) ) . '</a>';
        $body .= '</p></div>';
        
        $body .= '</div>';
        
        wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
    }

    private function send_admin_notification( $form_title, $data, $form_config ) {
        $admins = isset( $form_config['email_admin'] ) 
            ? array_filter(array_map('trim', explode( ',', $form_config['email_admin'] ))) 
            : array( get_option( 'admin_email' ) );
        
        $subject = sprintf( __( 'New Issuance: %s', 'ffc' ), $form_title );
        $body    = '<div style="font-family: sans-serif; max-width: 600px; margin: 0 auto;">';
        $body   .= '<h3 style="color: #0073aa;">' . __( 'Submission Details:', 'ffc' ) . '</h3>';
        $body   .= '<table border="1" cellpadding="10" style="border-collapse:collapse; width:100%; font-family: sans-serif; border: 1px solid #ddd;">';
        
        foreach ( $data as $k => $v ) {
            $display_v = is_array($v) ? implode(', ', $v) : $v;

            if ( in_array( $k, array( 'cpf', 'cpf_rf', 'rg' ) ) ) { 
                $display_v = FFC_Utils::format_document( $display_v ); 
            }
            if ( $k === 'auth_code' ) { 
                $display_v = FFC_Utils::format_auth_code( $display_v ); 
            }

            $label = ucwords( str_replace('_', ' ', $k) );
            $body .= '<tr>';
            $body .= '<td style="background:#f9f9f9; width:30%; font-weight: bold; border: 1px solid #ddd;">' . esc_html( $label ) . '</td>';
            $body .= '<td style="border: 1px solid #ddd;">' . wp_kses( $display_v, FFC_Utils::get_allowed_html_tags() ) . '</td>';
            $body .= '</tr>';
        }
        $body .= '</table></div>';

        foreach ( $admins as $email ) {
            if ( is_email( $email ) ) {
                wp_mail( $email, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
            }
        }
    }

    /**
     * Process Validation URL placeholders in template
     * 
     * Supports multiple formats:
     * - {{validation_url}} → Default: link to magic, text shows /valid
     * - {{validation_url link:m>v}} → Link to magic, text /valid
     * - {{validation_url link:v>v}} → Link to /valid, text /valid
     * - {{validation_url link:m>m}} → Link to magic, text magic
     * - {{validation_url link:v>m}} → Link to /valid, text magic
     * - {{validation_url link:m>"Custom Text"}} → Link to magic, custom text
     * - {{validation_url link:m>v target:_blank}} → With target
     * - {{validation_url link:m>v color:blue}} → With color
     * 
     * @since 2.9.3
     * @param string $layout Template HTML
     * @param array $submission_data Submission data
     * @return string Processed HTML
     */
    private function process_validation_url_placeholders( $layout, $submission_data ) {
        // Get base URLs
        $valid_url = untrailingslashit( site_url( 'valid' ) );
        
        // Get magic link URL (with fallback to /valid)
        $magic_token = isset( $submission_data['magic_token'] ) ? $submission_data['magic_token'] : '';
        $magic_url = $valid_url; // Fallback
        
        if ( ! empty( $magic_token ) ) {
            $magic_url = $valid_url . '/#token=' . $magic_token;
        }
        
        // Find all {{validation_url ...}} placeholders
        preg_match_all( '/{{validation_url(?:\s+([^}]+))?}}/', $layout, $matches, PREG_SET_ORDER );
        
        foreach ( $matches as $match ) {
            $full_placeholder = $match[0];
            $params_string = isset( $match[1] ) ? trim( $match[1] ) : '';
            
            // Parse parameters
            $params = $this->parse_validation_url_params( $params_string );
            
            // Determine href URL
            $href = ( $params['to'] === 'm' ) ? $magic_url : $valid_url;
            
            // Determine text
            $text = '';
            if ( is_string( $params['text'] ) && ! in_array( $params['text'], array( 'm', 'v' ) ) ) {
                // Custom text literal
                $text = $params['text'];
            } elseif ( $params['text'] === 'm' ) {
                $text = $magic_url;
            } else {
                // Default to /valid
                $text = $valid_url;
            }
            
            // Build <a> tag
            $link = '<a href="' . esc_url( $href ) . '" class="ffc-validation-link"';
            
            // Add target if specified
            if ( ! empty( $params['target'] ) ) {
                $link .= ' target="' . esc_attr( $params['target'] ) . '"';
            }
            
            // Add color style if specified
            if ( ! empty( $params['color'] ) ) {
                $link .= ' style="color: ' . esc_attr( $params['color'] ) . ';"';
            }
            
            $link .= '>' . esc_html( $text ) . '</a>';
            
            // Replace placeholder with generated link
            $layout = str_replace( $full_placeholder, $link, $layout );
        }
        
        return $layout;
    }
    
    /**
     * Parse validation URL parameters
     * 
     * @since 2.9.3
     * @param string $params_string Parameter string (e.g., "link:m>v target:_blank color:blue")
     * @return array Parsed parameters
     */
    private function parse_validation_url_params( $params_string ) {
        $defaults = array(
            'to' => 'm',      // Default destination: magic link
            'text' => 'v',    // Default text: /valid URL
            'target' => '',
            'color' => ''
        );
        
        // Empty params = default (link:m>v)
        if ( empty( $params_string ) ) {
            return $defaults;
        }
        
        $params = $defaults;
        
        // Split by spaces to get individual parameters
        $parts = preg_split( '/\s+/', $params_string );
        
        foreach ( $parts as $part ) {
            // Parse link:X>Y or link:X>"Custom Text"
            if ( preg_match( '/^link:(.+)$/', $part, $link_match ) ) {
                $link_value = $link_match[1];
                
                // Check for custom text: m>"Text" or v>"Text"
                if ( preg_match( '/^([mv])>"([^"]+)"$/', $link_value, $custom_match ) ) {
                    $params['to'] = $custom_match[1];
                    $params['text'] = $custom_match[2]; // Literal text
                }
                // Check for standard format: m>v, v>m, m>m, v>v
                elseif ( preg_match( '/^([mv])>([mv])$/', $link_value, $standard_match ) ) {
                    $params['to'] = $standard_match[1];
                    $params['text'] = $standard_match[2];
                }
            }
            // Parse target:_blank
            elseif ( preg_match( '/^target:(.+)$/', $part, $target_match ) ) {
                $params['target'] = $target_match[1];
            }
            // Parse color:blue or color:#2271b1
            elseif ( preg_match( '/^color:(.+)$/', $part, $color_match ) ) {
                $params['color'] = $color_match[1];
            }
        }
        
        return $params;
    }
}
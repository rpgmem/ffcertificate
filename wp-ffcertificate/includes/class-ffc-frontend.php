<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Frontend {
    
    private $submission_handler;

    public function __construct( FFC_Submission_Handler $handler ) {
        $this->submission_handler = $handler;
        
        add_action( 'wp_enqueue_scripts', array( $this, 'frontend_assets' ) );
        
        // Shortcodes
        add_shortcode( 'ffc_form', array( $this, 'shortcode_form' ) );
        add_shortcode( 'ffc_verification', array( $this, 'shortcode_verification_page' ) );
        
        // AJAX Handles - Submissão
        add_action( 'wp_ajax_ffc_submit_form', array( $this, 'handle_submission_ajax' ) );
        add_action( 'wp_ajax_nopriv_ffc_submit_form', array( $this, 'handle_submission_ajax' ) );

        // AJAX Handles - Verificação (Isso já estava aqui, mas faltava a função)
        add_action( 'wp_ajax_ffc_verify_certificate', array( $this, 'handle_verification_ajax' ) );
        add_action( 'wp_ajax_nopriv_ffc_verify_certificate', array( $this, 'handle_verification_ajax' ) );
    }

    public function frontend_assets() {
        global $post;
        
        if ( ! is_a( $post, 'WP_Post' ) ) {
            return;
        }

        if ( has_shortcode( $post->post_content, 'ffc_form' ) || has_shortcode( $post->post_content, 'ffc_verification' ) ) {
            
            wp_enqueue_style( 'ffc-frontend-css', FFC_PLUGIN_URL . 'assets/css/frontend.css', array(), '1.0.8' );
            
            wp_enqueue_script( 'html2canvas', FFC_PLUGIN_URL . 'assets/js/html2canvas.min.js', array(), '1.4.1', true );
            wp_enqueue_script( 'jspdf', FFC_PLUGIN_URL . 'assets/js/jspdf.umd.min.js', array(), '2.5.1', true );
            
            // Versão atualizada para garantir cache limpo
            wp_enqueue_script( 
                'ffc-frontend-js', 
                FFC_PLUGIN_URL . 'assets/js/frontend.js', 
                array( 'jquery', 'html2canvas', 'jspdf' ), 
                '2.2.0', 
                true 
            );

            wp_localize_script( 'ffc-frontend-js', 'ffc_ajax', array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'ffc_frontend_nonce' )
            ) );
        }
    }

    // =========================================================================
    // 0. FUNÇÕES AUXILIARES DE SEGURANÇA (CAPTCHA & HONEYPOT)
    // =========================================================================

    private function generate_security_fields() {
        $n1 = rand( 1, 9 );
        $n2 = rand( 1, 9 );
        $answer = $n1 + $n2;
        $answer_hash = wp_hash( $answer . 'ffc_math_salt' );

        ob_start();
        ?>
        <div class="ffc-security-container" style="margin: 15px 0; padding: 15px; background: #f9f9f9; border: 1px solid #eee; border-radius: 5px;">
            <div style="position: absolute; left: -9999px; display: none;">
                <label>Não preencha este campo se for humano:</label>
                <input type="text" name="ffc_honeypot_trap" value="" tabindex="-1" autocomplete="off">
            </div>

            <div class="ffc-captcha-row">
                <label for="ffc_captcha_ans" style="font-weight:bold; display:block; margin-bottom:5px; color:#555;">
                    <?php printf( __( 'Segurança: Quanto é %d + %d?', 'ffc' ), $n1, $n2 ); ?> <span class="required" style="color:red">*</span>
                </label>
                <input type="number" name="ffc_captcha_ans" id="ffc_captcha_ans" required style="width: 80px; padding: 8px; border:1px solid #ccc; border-radius:4px;">
                <input type="hidden" name="ffc_captcha_hash" value="<?php echo esc_attr( $answer_hash ); ?>">
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function validate_security_fields( $data ) {
        if ( ! empty( $data['ffc_honeypot_trap'] ) ) {
            return __( 'Erro de segurança: Requisição bloqueada.', 'ffc' );
        }

        if ( ! isset( $data['ffc_captcha_ans'] ) || ! isset( $data['ffc_captcha_hash'] ) ) {
            return __( 'Erro: Por favor, responda a pergunta de segurança.', 'ffc' );
        }

        $user_ans = trim( $data['ffc_captcha_ans'] );
        $hash_sent = $data['ffc_captcha_hash'];
        
        $check_hash = wp_hash( $user_ans . 'ffc_math_salt' );

        if ( $check_hash !== $hash_sent ) {
            return __( 'Erro: A resposta da conta matemática está incorreta.', 'ffc' );
        }

        return true; 
    }

    // =========================================================================
    // 1. PÁGINA DE VERIFICAÇÃO / AUTENTICIDADE (SHORTCODE)
    // =========================================================================
    public function shortcode_verification_page( $atts ) {
        ob_start();
        
        // Mantém suporte a POST direto (fallback se JS falhar)
        $input_raw = isset( $_POST['ffc_auth_code'] ) ? sanitize_text_field( $_POST['ffc_auth_code'] ) : '';
        $result_html = '';
        $error_msg = '';

        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ffc_auth_code']) ) {
            
            $security_check = $this->validate_security_fields( $_POST );
            
            if ( $security_check !== true ) {
                $error_msg = $security_check;
            } elseif ( empty( $input_raw ) ) {
                $error_msg = __( 'Por favor, digite o código.', 'ffc' );
            } else {
                global $wpdb;
                $table_name = $wpdb->prefix . 'ffc_submissions';
                
                // Limpeza rigorosa para o POST PHP também
                $clean_code = preg_replace( '/[^A-Z0-9]/', '', strtoupper( $input_raw ) );
                
                // Busca no JSON pela chave "auth_code"
                $like_query = '%' . $wpdb->esc_like( '"auth_code":"' . $clean_code . '"' ) . '%';
                
                $submission = $wpdb->get_row( $wpdb->prepare( 
                    "SELECT * FROM {$table_name} WHERE data LIKE %s LIMIT 1", 
                    $like_query 
                ) );

                if ( $submission ) {
                    $data = json_decode( $submission->data, true );
                    if ( is_null( $data ) ) {
                        $data = json_decode( stripslashes( $submission->data ), true );
                    }

                    $form = get_post( $submission->form_id );
                    $form_title = $form ? $form->post_title : 'N/A';
                    
                    $date_format_str = get_option('date_format') . ' ' . get_option('time_format');
                    $date_generated = date_i18n( $date_format_str, strtotime( $submission->submission_date ) );

                    $display_code = isset($data['auth_code']) ? $data['auth_code'] : $clean_code;

                    $result_html .= '<div class="ffc-verify-success" style="border:1px solid #28a745; background:#d4edda; color:#155724; padding:20px; border-radius:5px; margin-top:20px;">';
                    $result_html .= '<h3>✅ ' . __( 'Certificado Autêntico', 'ffc' ) . '</h3>';
                    $result_html .= '<p><strong>' . __( 'Código:', 'ffc' ) . '</strong> ' . esc_html( $display_code ) . '</p>';
                    $result_html .= '<p><strong>' . __( 'Evento:', 'ffc' ) . '</strong> ' . esc_html( $form_title ) . '</p>';
                    $result_html .= '<p><strong>' . __( 'Emitido em:', 'ffc' ) . '</strong> ' . esc_html( $date_generated ) . '</p>';
                    
                    $result_html .= '<hr style="border-color:#c3e6cb;">';
                    $result_html .= '<h4>' . __( 'Dados do Participante:', 'ffc' ) . '</h4>';
                    $result_html .= '<ul style="list-style:none; padding:0;">';
                    
                    if ( is_array( $data ) ) {
                        foreach ( $data as $key => $value ) {
                            if ( $key === 'auth_code' || $key === 'cpf_rf' || $key === 'ticket' || $key === 'fill_date' || $key === 'date' || $key === 'submission_date' ) continue;
                            $label = ucfirst( str_replace( '_', ' ', $key ) );
                            $result_html .= '<li><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $value ) . '</li>';
                        }
                    }
                    
                    $result_html .= '</ul>';
                    $result_html .= '</div>';
                } else {
                    $error_msg = __( 'Certificado não encontrado ou código inválido.', 'ffc' );
                }
            }

            if ( ! empty( $error_msg ) ) {
                $result_html .= '<div class="ffc-verify-error" style="border:1px solid #dc3545; background:#f8d7da; color:#721c24; padding:20px; border-radius:5px; margin-top:20px;">';
                $result_html .= '❌ ' . esc_html( $error_msg );
                $result_html .= '</div>';
            }
        }
        ?>

        <div class="ffc-verification-container">
            <form method="POST" class="ffc-verification-form" style="max-width:600px; margin:0 auto;">
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <input type="text" name="auth_code_check" value="<?php echo esc_attr( $input_raw ); ?>" placeholder="<?php _e( 'Digite o código (ex: A1B2-C3D4-E5F6)', 'ffc' ); ?>" required style="flex:1; padding:10px; text-transform:uppercase;" maxlength="20">
                    <button type="submit" class="button" style="padding:10px 20px; background:#0073aa; color:#fff; border:none; cursor:pointer; border-radius:4px;">
                        <?php _e( 'Verificar', 'ffc' ); ?>
                    </button>
                </div>
                
                <div class="ffc-no-js-security">
                    <?php echo $this->generate_security_fields(); ?>
                </div>
            </form>
            
            <div class="ffc-verify-result">
                <?php echo $result_html; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // =========================================================================
    // 2. FORMULÁRIO DE EMISSÃO (SHORTCODE)
    // =========================================================================
    public function shortcode_form( $atts ) {
        $atts = shortcode_atts( array( 'id' => 0 ), $atts, 'ffc_form' );
        $form_id = absint( $atts['id'] );
        
        if ( ! $form_id || get_post_type( $form_id ) !== 'ffc_form' ) {
            return '<p>' . esc_html__( 'Form not found.', 'ffc' ) . '</p>';
        }

        $form_post  = get_post( $form_id );
        $form_title = $form_post ? $form_post->post_title : '';

        $fields = get_post_meta( $form_id, '_ffc_form_fields', true );
        if ( empty( $fields ) ) {
            return '<p>' . esc_html__( 'Form has no fields.', 'ffc' ) . '</p>';
        }

        ob_start();
        ?>
        <div class="ffc-form-wrapper" id="ffc-form-<?php echo esc_attr( $form_id ); ?>">
            
            <h2 class="ffc-form-title" style="text-align:center; margin-bottom:20px; color:#333;">
                <?php echo esc_html( $form_title ); ?>
            </h2>

            <form class="ffc-submission-form" id="ffc-form-element-<?php echo esc_attr( $form_id ); ?>">
                <input type="hidden" name="form_id" value="<?php echo esc_attr( $form_id ); ?>">
                
                <?php foreach ( $fields as $field ) : 
                    $type     = isset($field['type']) ? $field['type'] : 'text';
                    $name     = isset($field['name']) ? $field['name'] : '';
                    $label    = isset($field['label']) ? $field['label'] : '';
                    $is_req   = ! empty( $field['required'] );
                    $required_attr = $is_req ? 'required' : '';
                    $options  = ! empty( $field['options'] ) ? explode( ',', $field['options'] ) : array();
                    
                    if ( empty( $name ) ) continue;

                    if ( $name === 'cpf_rf' ) {
                        $type = 'tel'; 
                    }
                    ?>
                    <div class="ffc-form-field">
                        <label for="<?php echo esc_attr( $name ); ?>">
                            <?php echo esc_html( $label ); ?> 
                            <?php if ( $is_req ) echo '<span class="required">*</span>'; ?>
                        </label>

                        <?php if ( $type === 'textarea' ) : ?>
                            <textarea name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>" <?php echo $required_attr; ?>></textarea>
                        
                        <?php elseif ( $type === 'select' ) : ?>
                            <select name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>" <?php echo $required_attr; ?>>
                                <option value=""><?php esc_html_e( 'Select...', 'ffc' ); ?></option>
                                <?php foreach ( $options as $opt ) : ?>
                                    <option value="<?php echo esc_attr( trim( $opt ) ); ?>"><?php echo esc_html( trim( $opt ) ); ?></option>
                                <?php endforeach; ?>
                            </select>

                        <?php elseif ( $type === 'radio' ) : ?>
                            <div class="ffc-radio-group">
                                <?php foreach ( $options as $opt ) : $opt_val = trim( $opt ); ?>
                                    <label>
                                        <input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $opt_val ); ?>" <?php echo $required_attr; ?>>
                                        <?php echo esc_html( $opt_val ); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        
                        <?php elseif ( $type === 'number' ) : ?>
                            <input type="number" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>" <?php echo $required_attr; ?> step="any">

                        <?php else : ?>
                            <input type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>" <?php echo $required_attr; ?>>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                
                <?php echo $this->generate_security_fields(); ?>

                <button type="submit" class="ffc-submit-btn" style="margin-top:10px;"><?php esc_html_e( 'Submit', 'ffc' ); ?></button>
                <div class="ffc-message"></div>
            </form>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var cpfInputs = document.querySelectorAll('input[name="cpf_rf"]');
            
            cpfInputs.forEach(function(input) {
                input.setAttribute('maxlength', '14'); 

                input.addEventListener('input', function(e) {
                    var v = e.target.value.replace(/\D/g, ''); 
                    
                    if (v.length > 11) {
                        v = v.slice(0, 11);
                    }

                    if (v.length <= 7) {
                        v = v.replace(/^(\d{3})(\d)/, '$1.$2');
                        v = v.replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2-$3');
                    } else {
                        v = v.replace(/^(\d{3})(\d)/, '$1.$2');
                        v = v.replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3');
                        v = v.replace(/^(\d{3})\.(\d{3})\.(\d{3})(\d)/, '$1.$2.$3-$4');
                    }

                    e.target.value = v;
                });
            });

            var forms = document.querySelectorAll('.ffc-submission-form');
            forms.forEach(function(form) {
                form.addEventListener('submit', function(e) {
                    var cpfField = form.querySelector('input[name="cpf_rf"]');
                    if (cpfField) {
                        var rawValue = cpfField.value.replace(/\D/g, '');
                        if (rawValue.length !== 7 && rawValue.length !== 11) {
                            e.preventDefault();
                            e.stopImmediatePropagation();
                            alert('O campo de identificação deve conter exatamente 7 ou 11 dígitos.');
                            cpfField.focus();
                            return false;
                        }
                    }
                });
            });
        });
        </script>

        <?php
        return ob_get_clean();
    }

    // =========================================================================
    // 3. PROCESSAMENTO AJAX (SUBMISSÃO DO FORMULÁRIO)
    // =========================================================================
    public function handle_submission_ajax() {
        check_ajax_referer( 'ffc_frontend_nonce', 'nonce' );
        
        $security_check = $this->validate_security_fields( $_POST );
        if ( $security_check !== true ) {
            wp_send_json_error( array( 'message' => $security_check ) );
        }

        $form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
        
        if ( ! $form_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid Form ID.', 'ffc' ) ) );
        }

        $form_config = get_post_meta( $form_id, '_ffc_form_config', true );
        if ( ! is_array( $form_config ) ) $form_config = array();

        $fields_config = get_post_meta( $form_id, '_ffc_form_fields', true );
        if ( ! $fields_config ) {
            wp_send_json_error( array( 'message' => __( 'Form configuration not found.', 'ffc' ) ) );
        }

        $submission_data = array();
        $user_email      = '';

        foreach ( $fields_config as $field ) {
            $name = $field['name'];
            if ( isset( $_POST[ $name ] ) ) {
                $value = sanitize_text_field( $_POST[ $name ] );
                
                if ( $name === 'cpf_rf' ) {
                    $value = preg_replace( '/\D/', '', $value );
                }

                if ( $field['type'] === 'number' && ! empty( $value ) ) {
                    if ( ! is_numeric( $value ) ) {
                        wp_send_json_error( array( 
                            'message' => sprintf( __( 'Erro: O campo "%s" deve conter apenas números.', 'ffc' ), $field['label'] ) 
                        ) );
                    }
                }

                $submission_data[ $name ] = $value;

                if ( $field['type'] === 'email' ) {
                    $user_email = sanitize_email( $value );
                }
            }
        }

        if ( empty( $user_email ) ) {
            wp_send_json_error( array( 'message' => __( 'Email address is required.', 'ffc' ) ) );
        }

        // --- VERIFICAÇÃO DE RESTRIÇÃO ---
        $val_cpf    = isset($submission_data['cpf_rf']) ? trim($submission_data['cpf_rf']) : '';
        $val_ticket = isset($submission_data['ticket']) ? trim($submission_data['ticket']) : '';
        
        $restriction_enabled = isset( $form_config['enable_restriction'] ) && $form_config['enable_restriction'] == 1;
        $is_ticket_usage     = false;

        // Denylist
        if ( ! empty( $val_cpf ) || ! empty( $val_ticket ) ) {
            $denied_raw = isset( $form_config['denied_users_list'] ) ? $form_config['denied_users_list'] : '';
            $denied_list = array_filter( array_map( 'trim', explode( "\n", $denied_raw ) ) );
            
            if ( (!empty($val_cpf) && in_array( $val_cpf, $denied_list )) || (!empty($val_ticket) && in_array( $val_ticket, $denied_list )) ) {
                wp_send_json_error( array( 'message' => __( 'Atenção: A emissão de certificado está bloqueada para este identificador (Denylist).', 'ffc' ) ) );
            }
        }

        if ( $restriction_enabled ) {
            $is_authorized = false;

            // A. Ticket
            if ( ! empty( $val_ticket ) ) {
                $generated_raw = isset( $form_config['generated_codes_list'] ) ? $form_config['generated_codes_list'] : '';
                $generated_list = array_filter( array_map( 'trim', explode( "\n", $generated_raw ) ) );
                
                if ( in_array( $val_ticket, $generated_list ) ) {
                    $is_authorized   = true;
                    $is_ticket_usage = true; 
                } else {
                    wp_send_json_error( array( 'message' => __( 'Ticket inválido ou já utilizado.', 'ffc' ) ) );
                }
            } 
            // B. CPF
            elseif ( ! empty( $val_cpf ) ) {
                $allowed_raw = isset( $form_config['allowed_users_list'] ) ? $form_config['allowed_users_list'] : '';
                $allowed_list = array_filter( array_map( 'trim', explode( "\n", $allowed_raw ) ) );
                
                if ( in_array( $val_cpf, $allowed_list ) ) {
                    $is_authorized = true;
                }
            } else {
                wp_send_json_error( array( 'message' => __( 'Erro: É necessário preencher um campo de validação (Ticket ou CPF).', 'ffc' ) ) );
            }

            // C. Senha Única
            $global_pass = isset( $form_config['validation_code'] ) ? trim( $form_config['validation_code'] ) : '';
            if ( ! empty( $global_pass ) ) {
                if ( (!empty($val_cpf) && $val_cpf === $global_pass) || (!empty($val_ticket) && $val_ticket === $global_pass) ) {
                    $is_authorized = true;
                    $is_ticket_usage = false; 
                }
            }

            if ( ! $is_authorized ) {
                wp_send_json_error( array( 'message' => __( 'Acesso Negado: Seus dados ou ticket não foram encontrados na lista de autorização.', 'ffc' ) ) );
            }
        }

        // --- 2ª VIA E DUPLICIDADE ---
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_submissions';
        $form_post  = get_post( $form_id );
        $result     = 0;
        $is_reprint = false;
        
        $real_submission_date = current_time( 'mysql' ); 
        
        $existing_submission = null;
        
        if ( ! empty( $val_ticket ) ) {
            $like_query = '%' . $wpdb->esc_like( '"ticket":"' . $val_ticket . '"' ) . '%';
            $existing_submission = $wpdb->get_row( $wpdb->prepare( 
                "SELECT * FROM {$table_name} WHERE form_id = %d AND data LIKE %s ORDER BY id DESC LIMIT 1",
                $form_id, $like_query
            ) );
        } elseif ( ! empty( $val_cpf ) ) {
            $like_query = '%' . $wpdb->esc_like( '"cpf_rf":"' . $val_cpf . '"' ) . '%';
            $existing_submission = $wpdb->get_row( $wpdb->prepare( 
                "SELECT * FROM {$table_name} WHERE form_id = %d AND data LIKE %s ORDER BY id DESC LIMIT 1",
                $form_id, $like_query
            ) );
        }

        if ( $existing_submission ) {
            $decoded_data = json_decode( $existing_submission->data, true );
            if( !is_array($decoded_data) ) {
                $decoded_data = json_decode( stripslashes( $existing_submission->data ), true );
            }
            $submission_data = $decoded_data;
            $result          = $existing_submission->id;
            $user_email      = $existing_submission->email;
            $is_reprint      = true;
            $real_submission_date = $existing_submission->submission_date;
        }

        if ( ! $is_reprint ) {
            // Processa via Handler (referência atualiza auth_code)
            $result = $this->submission_handler->process_submission( 
                $form_id, 
                $form_post->post_title, 
                $submission_data, 
                $user_email, 
                $fields_config, 
                $form_config 
            );

            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }
            
            // Queima Ticket
            if ( $is_ticket_usage && ! empty( $val_ticket ) ) {
                $current_config = get_post_meta( $form_id, '_ffc_form_config', true );
                $current_raw_codes = isset( $current_config['generated_codes_list'] ) ? $current_config['generated_codes_list'] : '';
                $current_list = array_filter( array_map( 'trim', explode( "\n", $current_raw_codes ) ) );
                
                $updated_list = array_diff( $current_list, array( $val_ticket ) );
                
                $current_config['generated_codes_list'] = implode( "\n", $updated_list );
                update_post_meta( $form_id, '_ffc_form_config', $current_config );
                $form_config['generated_codes_list'] = $current_config['generated_codes_list']; 
            }

            // Recarrega os dados salvos para garantir que temos o auth_code gerado pelo handler
            $saved_record = $wpdb->get_row( $wpdb->prepare( "SELECT data FROM {$table_name} WHERE id = %d", $result ) );
            if ( $saved_record ) {
                $decoded_new = json_decode( $saved_record->data, true );
                if ( !is_array( $decoded_new ) ) {
                    $decoded_new = json_decode( stripslashes( $saved_record->data ), true );
                }
                $submission_data = $decoded_new;
            }
        }

        $date_format_str = get_option('date_format') . ' ' . get_option('time_format');
        $formatted_date  = date_i18n( $date_format_str, strtotime( $real_submission_date ) );
        
        $submission_data['fill_date'] = $formatted_date;
        $submission_data['date']      = $formatted_date;

        $pdf_html = $this->submission_handler->generate_pdf_html( $submission_data, $form_post->post_title, $form_config );

        $custom_message = isset( $form_config['success_message'] ) ? trim( $form_config['success_message'] ) : '';
        $email_enabled  = isset( $form_config['send_user_email'] ) && $form_config['send_user_email'] == 1;

        if ( $is_reprint ) {
            $msg = __( 'Certificado já emitido anteriormente (2ª via).', 'ffc' );
        } elseif ( ! empty( $custom_message ) ) {
            $msg = $custom_message;
        } else {
            if ( $email_enabled ) {
                $msg = __( 'Sucesso! O certificado foi enviado para seu e-mail e o download começará em breve.', 'ffc' );
            } else {
                $msg = __( 'Sucesso! Seu certificado está sendo gerado e baixado automaticamente.', 'ffc' );
            }
        }

        wp_send_json_success( array( 
            'message'  => $msg,
            'pdf_data' => array(
                'template'      => $pdf_html,
                'form_title'    => $form_post->post_title,
                'submission_id' => $result,
                'submission'    => $submission_data
            )
        ) );
    }

    // =========================================================================
    // 4. AJAX VERIFICAÇÃO DE CERTIFICADO (ESTA É A FUNÇÃO QUE FALTAVA)
    // =========================================================================
    public function handle_verification_ajax() {
        check_ajax_referer( 'ffc_frontend_nonce', 'nonce' );

        $raw_code = isset( $_POST['auth_code'] ) ? sanitize_text_field( $_POST['auth_code'] ) : '';
        
        // Limpa o código recebido (Remove traços, pontos, espaços e deixa maiúsculo)
        // Isso garante que 'ABC-123' vire 'ABC123' para corresponder ao banco
        $clean_code = strtoupper( preg_replace( '/[^a-zA-Z0-9]/', '', $raw_code ) );

        if ( empty( $clean_code ) ) {
            wp_send_json_error( array( 'message' => __( 'Por favor, digite o código.', 'ffc' ) ) );
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_submissions';

        // Busca no banco onde a coluna DATA (JSON) contém o auth_code
        // Exemplo: "auth_code":"A1B2C3D4"
        $like_query = '%' . $wpdb->esc_like( '"auth_code":"' . $clean_code . '"' ) . '%';
        
        $result = $wpdb->get_row( $wpdb->prepare( 
            "SELECT * FROM {$table_name} WHERE data LIKE %s LIMIT 1", 
            $like_query 
        ) );

        if ( $result ) {
            $data = json_decode( $result->data, true );
            if ( !is_array($data) ) {
                $data = json_decode( stripslashes( $result->data ), true );
            }

            $form_title = get_the_title( $result->form_id );
            
            // Tenta encontrar o nome do aluno em chaves comuns
            $student_name = 'N/A';
            if ( isset($data['name']) ) $student_name = $data['name'];
            elseif ( isset($data['nome']) ) $student_name = $data['nome'];
            elseif ( isset($data['aluno']) ) $student_name = $data['aluno'];
            
            // Monta HTML de sucesso para exibir no frontend
            $response_html = '<div class="ffc-valid-cert" style="border:1px solid green; padding:15px; background:#f0fff0; border-radius:5px; margin-top:15px;">';
            $response_html .= '<h4 style="color:green; margin:0 0 10px 0;">✅ ' . __( 'Certificado Autêntico', 'ffc' ) . '</h4>';
            $response_html .= '<p style="margin:5px 0;"><strong>' . __( 'Aluno:', 'ffc' ) . '</strong> ' . esc_html( $student_name ) . '</p>';
            $response_html .= '<p style="margin:5px 0;"><strong>' . __( 'Curso/Evento:', 'ffc' ) . '</strong> ' . esc_html( $form_title ) . '</p>';
            $response_html .= '<p style="margin:5px 0;"><strong>' . __( 'Data de Emissão:', 'ffc' ) . '</strong> ' . date_i18n( get_option('date_format'), strtotime($result->submission_date) ) . '</p>';
            $response_html .= '</div>';

            wp_send_json_success( array( 'html' => $response_html ) );
        } else {
            wp_send_json_error( array( 'message' => '❌ ' . __( 'Certificado não encontrado. Verifique o código digitado.', 'ffc' ) ) );
        }
    }
}
<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Responsável pela segurança: Captcha, Honeypot e Sanitização de Dados.
 */
class FFC_Security {

    /**
     * Gera o HTML dos campos de segurança (Honeypot + Math Captcha).
     * Usado dentro dos formulários.
     */
    public function generate_security_fields() {
        // 1. Honeypot (Campo escondido que robôs preenchem)
        // O style inline garante que usuários reais não vejam
        $html = '<div style="display:none !important; position:absolute; left:-9999px;">
            <label>Leave this field empty</label>
            <input type="text" name="ffc_honeypot_trap" value="" tabindex="-1" autocomplete="off">
        </div>';

        // 2. Math Captcha simples
        $n1 = rand( 1, 9 );
        $n2 = rand( 1, 9 );
        $ans = $n1 + $n2;
        // Hash simples para validação no server-side (sem sessão PHP para compatibilidade com Cache)
        $hash = wp_hash( 'ffc_captcha_' . $ans );

        $html .= '<div class="ffc-form-field ffc-captcha-group">';
        $html .= '<label for="ffc_captcha_ans">' . sprintf( esc_html__( 'Security Check: %d + %d = ?', 'ffc' ), $n1, $n2 ) . ' <span class="required">*</span></label>';
        $html .= '<input type="number" name="ffc_captcha_ans" id="ffc_captcha_ans" class="ffc-input" required>';
        $html .= '<input type="hidden" name="ffc_captcha_hash" value="' . esc_attr( $hash ) . '">';
        $html .= '</div>';

        return $html;
    }

    /**
     * Valida os campos de segurança enviados via POST.
     * * @return true|WP_Error Retorna true se passou, ou WP_Error com a mensagem.
     */
    public function validate_security_fields( $params ) {
        // 1. Verifica Honeypot
        if ( ! empty( $params['ffc_honeypot_trap'] ) ) {
            return new WP_Error( 'spam_detected', __( 'Spam detected (Honeypot).', 'ffc' ) );
        }

        // 2. Verifica Captcha
        $ans  = isset( $params['ffc_captcha_ans'] ) ? intval( $params['ffc_captcha_ans'] ) : 0;
        $hash = isset( $params['ffc_captcha_hash'] ) ? sanitize_text_field( $params['ffc_captcha_hash'] ) : '';

        $expected_hash = wp_hash( 'ffc_captcha_' . $ans );

        if ( ! hash_equals( $expected_hash, $hash ) ) {
            return new WP_Error( 'captcha_failed', __( 'Incorrect security answer. Please try again.', 'ffc' ) );
        }

        return true;
    }

    /**
     * Gera novos dados de Captcha (usado pelo AJAX quando o usuário erra e precisa tentar de novo).
     */
    public function get_new_captcha_data() {
        $n1 = rand( 1, 9 );
        $n2 = rand( 1, 9 );
        $ans = $n1 + $n2;
        $hash = wp_hash( 'ffc_captcha_' . $ans );

        return array(
            'label' => sprintf( __( 'Security Check: %d + %d = ?', 'ffc' ), $n1, $n2 ),
            'hash'  => $hash
        );
    }

    /**
     * Sanitiza arrays recursivamente (útil para $_POST complexo).
     */
    public function sanitize_recursive( $data ) {
        if ( is_array( $data ) ) {
            $new_data = array();
            foreach ( $data as $key => $value ) {
                // Sanitiza a chave para garantir segurança no array final
                $sanitized_key = sanitize_key( $key ); 
                
                // Aplica a recursão
                $new_data[$sanitized_key] = $this->sanitize_recursive( $value );
            }
            return $new_data;
        } else {
            // Se não for um array, sanitiza como campo de texto simples
            return sanitize_text_field( $data );
        }
    }
}
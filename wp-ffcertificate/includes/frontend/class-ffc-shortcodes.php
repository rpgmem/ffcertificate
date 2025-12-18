<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * FFC_Shortcode
 * Responsável por renderizar o formulário de submissão ([ffc_form]) 
 * e o formulário de verificação ([ffc_verify]) no frontend.
 */
class FFC_Shortcode {

    public function __construct() {
        // A classe Shortcode foca APENAS na renderização.
        add_shortcode( 'ffc_form', array( $this, 'render_form' ) );
        add_shortcode( 'ffc_verify', array( $this, 'render_verification' ) );

        // Nota de Arquitetura: Os handlers AJAX (handle_submission e verify)
        // devem ser registrados em 'class-ffc-frontend-ajax.php' para manter o SRP.
    }

    /**
     * Renderiza o formulário de submissão com base no ID do formulário.
     * Enfileira os campos de entrada, segurança e botões de ação.
     */
    public function render_form( $atts ) {
        // Define os atributos padrão, garantindo que 'id' seja um inteiro não negativo
        $atts = shortcode_atts( array( 'id' => 0 ), $atts, 'ffc_form' );
        $form_id = absint( $atts['id'] );
        
        if ( $form_id === 0 ) {
            return '<p class="ffc-error">' . esc_html__( 'Error: Form ID is missing in the shortcode.', 'ffc' ) . '</p>';
        }

        // 1. Busca os campos do formulário salvos no Custom Post Type
        $fields = get_post_meta( $form_id, '_ffc_form_fields', true );
        if ( ! is_array( $fields ) || empty( $fields ) ) {
            return '<p class="ffc-error">' . esc_html__( 'Error: Form fields not configured for this ID.', 'ffc' ) . '</p>';
        }

        // 2. Busca o template HTML do certificado (opcional, usado pelo JS para gerar o PDF)
        $config = get_post_meta( $form_id, '_ffc_form_config', true );
        $html_layout = isset( $config['pdf_layout'] ) ? $config['pdf_layout'] : ''; // Usar 'pdf_layout'

        // --- Output Buffering para Capturar HTML ---
        ob_start();
        ?>
        <div class="ffc-form-wrapper" data-form-id="<?php echo esc_attr( $form_id ); ?>">
            <form class="ffc-submission-form" id="ffc-form-<?php echo esc_attr( $form_id ); ?>" method="post">

                <?php foreach ( $fields as $field ) : 
                    // Garante que os campos essenciais estejam definidos e sanitizados
                    $type      = isset( $field['type'] ) ? $field['type'] : 'text';
                    // sanitize_key é adequado para nomes de campos que serão chaves no banco
                    $name      = isset( $field['name'] ) ? sanitize_key( $field['name'] ) : ''; 
                    $label     = isset( $field['label'] ) ? esc_html( $field['label'] ) : '';
                    $required  = isset( $field['required'] ) ? absint( $field['required'] ) : 0;
                    // Explode as opções em array para select/radio/checkbox
                    $options   = isset( $field['options'] ) ? explode( "\n", $field['options'] ) : array();

                    if ( empty( $name ) ) continue; // Pula se não houver nome
                ?>
                <div class="ffc-form-field ffc-type-<?php echo esc_attr( $type ); ?>">
                    <label for="ffc_<?php echo esc_attr( $name ); ?>">
                        <?php echo $label; ?>
                        <?php if ( $required ) : ?><span class="required">*</span><?php endif; ?>
                    </label>

                    <?php if ( $type === 'textarea' ) : ?>
                        <textarea id="ffc_<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" <?php echo $required ? 'required' : ''; ?>></textarea>
                    
                    <?php elseif ( $type === 'select' && ! empty( $options ) ) : ?>
                        <select id="ffc_<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" <?php echo $required ? 'required' : ''; ?>>
                            <option value=""><?php esc_html_e( '- Select -', 'ffc' ); ?></option>
                            <?php foreach ( $options as $option ) : 
                                $option = trim( $option );
                                if ( empty( $option ) ) continue;
                                // Valor e Rótulo são o mesmo para simplificar
                                ?>
                                <option value="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $option ); ?></option>
                            <?php endforeach; ?>
                        </select>

                    <?php elseif ( $type === 'radio' && ! empty( $options ) ) : 
                        $option_index = 0;
                        foreach ( $options as $option ) : 
                            $option = trim( $option );
                            if ( empty( $option ) ) continue;
                            $option_index++;
                            $id = 'ffc_' . esc_attr( $name ) . '_' . $option_index;
                            ?>
                            <div class="ffc-radio-option">
                                <input type="radio" id="<?php echo $id; ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $option ); ?>" <?php echo $required ? 'required' : ''; ?>>
                                <label for="<?php echo $id; ?>"><?php echo esc_html( $option ); ?></label>
                            </div>
                        <?php endforeach; ?>

                    <?php elseif ( $type === 'checkbox' && ! empty( $options ) ) : 
                        // O nome deve ser um array para receber múltiplos valores
                        $array_name = esc_attr( $name ) . '[]'; 
                        $option_index = 0;
                        foreach ( $options as $option ) : 
                            $option = trim( $option );
                            if ( empty( $option ) ) continue;
                            $option_index++;
                            $id = 'ffc_' . esc_attr( $name ) . '_' . $option_index;
                            ?>
                            <div class="ffc-checkbox-option">
                                <input type="checkbox" id="<?php echo $id; ?>" name="<?php echo $array_name; ?>" value="<?php echo esc_attr( $option ); ?>">
                                <label for="<?php echo $id; ?>"><?php echo esc_html( $option ); ?></label>
                            </div>
                        <?php endforeach; ?>
                        
                    <?php else : // Padrão: text, email, number, date, etc. ?>
                        <input type="<?php echo esc_attr( $type ); ?>" id="ffc_<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" <?php echo $required ? 'required' : ''; ?>>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

                <?php 
                // Adiciona campos de segurança (Honeypot + Captcha)
                // A classe FFC_Security deve ser carregada pelo FFC_Frontend
                if ( class_exists( 'FFC_Security' ) ) {
                    $security = new FFC_Security();
                    echo $security->generate_security_fields(); // O método retorna HTML sanitizado
                }
                ?>

                <?php wp_nonce_field( 'ffc_frontend_ajax_nonce', 'ffc_nonce' ); ?>
                <input type="hidden" name="action" value="ffc_handle_submission">
                <input type="hidden" name="form_id" value="<?php echo esc_attr( $form_id ); ?>">

                <div class="ffc-form-status" style="margin-top: 15px;"></div>
                <button type="submit" class="button button-primary ffc-submit-btn"><?php esc_html_e( 'Get Certificate', 'ffc' ); ?></button>
            </form>

            <div class="ffc-result-container" style="display: none; margin-top: 20px;">
                </div>
            
            <?php // O template oculto é usado pelo frontend.js (html2canvas) para renderizar o PDF ?>
            <div id="ffc-pdf-template-hidden" style="display: none; visibility: hidden;">
                <?php echo wp_kses_post( $html_layout ); ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Renderiza o formulário de verificação de autenticidade ([ffc_verify]).
     */
    public function render_verification( $atts ) {
        // Note: O shortcode [ffc_verify] não requer um ID.
        ob_start();
        ?>
        <div class="ffc-verify-wrapper">
            <h2><?php esc_html_e( 'Verify Certificate Authenticity', 'ffc' ); ?></h2>
            <form class="ffc-verification-form" method="post">
                <div class="ffc-form-field">
                    <label for="ffc_verification_code"><?php esc_html_e( 'Authentication Code', 'ffc' ); ?></label>
                    <input type="text" id="ffc_verification_code" name="verification_code" required placeholder="AAAA-BBBB-CCCC">
                </div>

                <?php wp_nonce_field( 'ffc_frontend_ajax_nonce', 'ffc_nonce' ); ?>
                <input type="hidden" name="action" value="ffc_verify_certificate">

                <div class="ffc-form-status" style="margin-top: 15px;"></div>
                <button type="submit" class="button button-secondary ffc-verify-btn"><?php esc_html_e( 'Verify Code', 'ffc' ); ?></button>
            </form>
            
            <div class="ffc-verify-result-container" style="margin-top: 20px;">
                </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Responsável por processar o HTML do certificado.
 * Substitui variáveis (placeholders) pelos dados reais.
 */
class FFC_Template_Engine {

    /**
     * Gera o HTML final substituindo as tags.
     * * @param string $template O HTML cru com {{tags}}.
     * @param array  $data     Os dados da submissão.
     * @return string O HTML processado.
     */
    public function render( $template, $data ) {
        if ( empty( $template ) ) {
            return '';
        }

        // 1. Formata Código de Autenticidade (AAAA-BBBB-CCCC)
        if ( isset( $data['auth_code'] ) ) {
            $clean_code = preg_replace( '/[^A-Z0-9]/i', '', $data['auth_code'] );
            
            // Adiciona hífens a cada 4 caracteres para facilitar leitura
            $formatted_code = strtoupper( implode( '-', str_split( $clean_code, 4 ) ) );
            
            $template = str_ireplace( '{{auth_code}}', $formatted_code, $template );
            
            // Lógica do QR Code
            $template = $this->inject_qrcode( $template, $clean_code );
        }

        // 2. Substituição Genérica de Campos
        foreach ( $data as $key => $value ) {
            // Pula arrays (ex: ffc_data aninhado)
            if ( is_array( $value ) ) continue;

            // Formatação inteligente de datas (Y-m-d -> d/m/Y)
            // Apenas se o valor parecer uma data ISO e não for um número solto
            if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', $value ) ) {
                $timestamp = strtotime( $value );
                if ( $timestamp ) {
                    $value = date_i18n( 'd/m/Y', $timestamp );
                }
            }

            // Sanitização para evitar quebra de HTML no PDF
            // Nota: Se você permitir HTML nos campos, remova o esc_html
            $safe_value = $value;

            $template = str_ireplace( '{{' . $key . '}}', $safe_value, $template );
        }

        // 3. Limpeza: Remove tags que não foram substituídas (ex: {{campo_vazio}})
        // Isso evita que "{{sobrenome}}" apareça impresso se o usuário não preencheu.
        $template = preg_replace( '/\{\{[a-zA-Z0-9_]+\}\}/', '', $template );

        return $template;
    }

    /**
     * Gera e injeta o HTML do QR Code.
     */
    private function inject_qrcode( $template, $code ) {
        // Link para a home com parâmetro de verificação
        // Idealmente, isso deve apontar para a página onde está o shortcode [ffc_verification]
        $verify_url = home_url( '/?ffc_verify=' . $code );
        
        // API QR Code (QuickChart/GoQR/QRServer são boas opções públicas)
        $qr_src = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&margin=0&data=' . urlencode( $verify_url );
        
        $qr_img = '<img src="' . esc_url( $qr_src ) . '" class="ffc-qrcode" alt="QR Code verification" style="width:100px; height:auto; display:inline-block;">';
        
        return str_ireplace( '{{qrcode}}', $qr_img, $template );
    }
}
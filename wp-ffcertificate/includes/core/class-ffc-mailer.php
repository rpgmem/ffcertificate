<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Responsável pela configuração de envio de e-mails (SMTP Customizado).
 */
class FFC_Mailer {

    public function __construct() {
        // Gancho para injetar configurações no PHPMailer antes do envio
        add_action( 'phpmailer_init', array( $this, 'configure_custom_smtp' ) );
    }

    /**
     * Configura o PHPMailer com as opções definidas no plugin.
     */
    public function configure_custom_smtp( $phpmailer ) {
        $settings = get_option( 'ffc_settings', array() );
        
        // Verifica se o modo 'custom' está ativo
        if ( isset( $settings['smtp_mode'] ) && $settings['smtp_mode'] === 'custom' ) {
            $phpmailer->isSMTP();
            $phpmailer->Host       = isset( $settings['smtp_host'] ) ? $settings['smtp_host'] : '';
            $phpmailer->SMTPAuth   = true;
            $phpmailer->Port       = isset( $settings['smtp_port'] ) ? (int) $settings['smtp_port'] : 587;
            $phpmailer->Username   = isset( $settings['smtp_user'] ) ? $settings['smtp_user'] : '';
            $phpmailer->Password   = isset( $settings['smtp_pass'] ) ? $settings['smtp_pass'] : '';
            
            // Criptografia (TLS/SSL)
            $secure = isset( $settings['smtp_secure'] ) ? $settings['smtp_secure'] : 'tls';
            $phpmailer->SMTPSecure = ( $secure === 'none' ) ? '' : $secure;

            // Define o Remetente (From)
            if ( ! empty( $settings['smtp_from_email'] ) ) {
                $phpmailer->From = $settings['smtp_from_email'];
            }
            if ( ! empty( $settings['smtp_from_name'] ) ) {
                $phpmailer->FromName = $settings['smtp_from_name'];
            }
        }
    }

    /**
     * Método utilitário para enviar notificações simples (Wrapper).
     */
    public static function send_notification( $to, $subject, $message ) {
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        return wp_mail( $to, $subject, $message, $headers );
    }
}
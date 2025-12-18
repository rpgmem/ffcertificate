<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Responsável por carregar CSS e JS no Frontend (Site público).
 */
class FFC_Frontend_Assets {

    public function __construct() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
    }

    /**
     * Registra e enfileira os estilos e scripts.
     */
    public function enqueue_scripts() {
        
        // =========================================================
        // 1. CSS DO FORMULÁRIO
        // =========================================================
        wp_enqueue_style( 
            'ffc-frontend-css', 
            FFC_PLUGIN_URL . 'assets/css/frontend.css', 
            array(), 
            '1.0.0' 
        );

        // =========================================================
        // 2. JS - BIBLIOTECAS DE PDF
        // =========================================================
        // Nota: Carregamos as mesmas versões do Admin para consistência
        wp_enqueue_script( 'jspdf', 'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js', array(), '2.5.1', true );
        wp_enqueue_script( 'html2canvas', 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js', array(), '1.4.1', true );

        // =========================================================
        // 3. JS - FRONTEND PRINCIPAL
        // =========================================================
        wp_enqueue_script( 
            'ffc-frontend-js', 
            FFC_PLUGIN_URL . 'assets/js/frontend.js', 
            array( 'jquery', 'jspdf', 'html2canvas' ), // Dependências
            '1.0.0', 
            true // No footer
        );

        // =========================================================
        // 4. LOCALIZAÇÃO (Variáveis PHP -> JS)
        // =========================================================
        // Passamos dados essenciais para o frontend.js
        wp_localize_script( 'ffc-frontend-js', 'ffc_ajax', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            
            // Nonce específico para o frontend (diferente do admin)
            'nonce'    => wp_create_nonce( 'ffc_frontend_ajax_nonce' ),
            
            'strings'  => array(
                'processing'            => __( 'Processing...', 'ffc' ),
                'generatingCertificate' => __( 'Generating PDF certificate, please wait...', 'ffc' ),
                'connectionError'       => __( 'Connection error. Please try again.', 'ffc' ),
                'verifying'             => __( 'Verifying...', 'ffc' ),
                'verify'                => __( 'Verify Authenticity', 'ffc' ),
                'pdfLibrariesFailed'    => __( 'Error: PDF libraries failed to load.', 'ffc' ),
            )
        ));
    }
}
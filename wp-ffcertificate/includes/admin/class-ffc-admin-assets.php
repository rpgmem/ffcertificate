<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Responsável por carregar CSS e JS no painel administrativo.
 */
class FFC_Admin_Assets {

    /**
     * Registra e enfileira os estilos e scripts.
     */
    public function enqueue_styles_and_scripts( $hook ) {
        global $post_type;

        // Verifica se estamos na tela de edição do post type 'ffc_form' 
        // ou na página de configurações do plugin (ffc-settings)
        $is_ffc_screen = ( 'ffc_form' === $post_type ) || ( strpos( $hook, 'ffc-settings' ) !== false );

        if ( ! $is_ffc_screen ) {
            return;
        }
            
        // =========================================================
        // 1. CARREGAR A BIBLIOTECA DE MÍDIA (Essencial para Upload)
        // =========================================================
        if ( ! did_action( 'wp_enqueue_media' ) ) {
            wp_enqueue_media();
        }

        // =========================================================
        // 2. CSS
        // =========================================================
        wp_enqueue_style( 'wp-color-picker' );
        
        wp_enqueue_style( 
            'ffc-admin-css', 
            FFC_PLUGIN_URL . 'assets/css/admin.css', 
            array(), 
            '2.5.0' 
        );

        // =========================================================
        // 3. JS - BIBLIOTECAS DE PDF (Necessário para Preview no Admin)
        // =========================================================
        // Carregamos via CDN ou localmente se você tiver os arquivos
        wp_enqueue_script( 'jspdf', 'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js', array(), '2.5.1', true );
        wp_enqueue_script( 'html2canvas', 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js', array(), '1.4.1', true );
        
        // Script compartilhado que contém a função window.generateCertificate
        wp_enqueue_script( 
            'ffc-pdf-generator', 
            FFC_PLUGIN_URL . 'assets/js/ffc-pdf-generator.js', 
            array( 'jspdf', 'html2canvas', 'jquery' ), 
            '1.0', 
            true 
        );

        // =========================================================
        // 4. JS - ADMIN PRINCIPAL
        // =========================================================
        wp_enqueue_script( 
            'ffc-admin-js', 
            FFC_PLUGIN_URL . 'assets/js/admin.js', 
            // Adicionamos 'ffc-pdf-generator' como dependência para garantir a ordem de carregamento
            array( 'jquery', 'jquery-ui-sortable', 'wp-color-picker', 'ffc-pdf-generator' ), 
            '2.5.0', 
            true 
        );

        // =========================================================
        // 5. LOCALIZAÇÃO (Variáveis PHP -> JS)
        // =========================================================
        wp_localize_script( 'ffc-admin-js', 'ffc_admin_ajax', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            
            // O nome do nonce deve bater com o check_ajax_referer no PHP
            'nonce'    => wp_create_nonce( 'ffc_admin_ajax_nonce' ),
            
            'strings'  => array(
                // Strings básicas
                'fileImported'          => __( 'HTML Layout imported successfully!', 'ffc' ),
                'errorReadingFile'      => __( 'Error reading file.', 'ffc' ),
                'selectTemplate'        => __( 'Please select a template.', 'ffc' ),
                'confirmReplaceContent' => __( 'This will overwrite your current layout. Continue?', 'ffc' ),
                'loading'               => __( 'Loading...', 'ffc' ),
                'loadTemplate'          => __( 'Load Template', 'ffc' ),
                'templateLoaded'        => __( 'Template loaded successfully!', 'ffc' ),
                'error'                 => __( 'Error: ', 'ffc' ),
                
                // Strings para Imagem de Fundo (Media Uploader)
                'selectBackgroundImage' => __( 'Select Background Image', 'ffc' ),
                'useImage'              => __( 'Use this Image', 'ffc' ),
                
                // Strings para Geração de Tickets
                'generating'            => __( 'Generating codes...', 'ffc' ),
                'codesGenerated'        => __( 'codes generated successfully!', 'ffc' ),
                'errorGeneratingCodes'  => __( 'Error generating codes.', 'ffc' ),
                
                // Strings para Form Builder
                'confirmDeleteField'    => __( 'Are you sure you want to remove this field?', 'ffc' ),
                
                // Strings de Erro Genérico
                'errorJsVarsNotLoaded'  => __( 'Critical Error: JS variables not loaded.', 'ffc' ),
                'connectionError'       => __( 'Connection error. Please try again.', 'ffc' ),
                'unknown'               => __( 'Unknown error', 'ffc' ),
                'errorPdfLibraryNotLoaded' => __( 'Error: PDF Library not loaded.', 'ffc' ),
                'errorFetchingData'     => __( 'Error fetching data: ', 'ffc' ),
            )
        ));
    }
}
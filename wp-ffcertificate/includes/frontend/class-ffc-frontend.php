<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Classe principal do Frontend.
 * Carrega dependências, registra scripts e define os shortcodes.
 */
class FFC_Frontend {

    /**
     * @var FFC_Submission_Handler
     */
    private $submission_handler;

    /**
     * Instâncias das classes auxiliares.
     */
    private $shortcodes;
    private $process;

    /**
     * Construtor.
     */
    public function __construct( $submission_handler ) {
        $this->submission_handler = $submission_handler;

        $this->load_dependencies();
        $this->instantiate_classes();
        $this->define_hooks();
    }

    /**
     * Carrega os arquivos das subclasses da pasta includes/frontend/.
     */
    private function load_dependencies() {
        require_once FFC_PLUGIN_DIR . 'includes/frontend/class-ffc-security.php';
        require_once FFC_PLUGIN_DIR . 'includes/frontend/class-ffc-shortcodes.php';
        require_once FFC_PLUGIN_DIR . 'includes/frontend/class-ffc-process.php';
    }

    /**
     * Instancia as classes.
     */
    private function instantiate_classes() {
        // Renderizador de HTML
        $this->shortcodes = new FFC_Shortcodes();

        // Processador de Lógica (AJAX/DB)
        // Nota: O construtor do FFC_Process já registra os hooks de AJAX automaticamente
        $this->process = new FFC_Process( $this->submission_handler );
    }

    /**
     * Define os ganchos (Shortcodes e Assets).
     */
    private function define_hooks() {
        // Scripts e Estilos
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles_and_scripts' ) );

        // Shortcodes (Apontam para a classe FFC_Shortcodes)
        add_shortcode( 'ffc_form', array( $this->shortcodes, 'render_form' ) );
        add_shortcode( 'ffc_verification', array( $this->shortcodes, 'render_verification' ) );
    }

    /**
     * Carrega CSS e JS apenas se o shortcode estiver presente na página.
     */
    public function enqueue_styles_and_scripts() {
        global $post;

        // Verifica se estamos num post/página válido
        if ( ! is_a( $post, 'WP_Post' ) ) {
            return;
        }

        // Verifica se existe algum shortcode do plugin no conteúdo
        // Isso evita carregar arquivos pesados (JS PDF) em páginas que não precisam
        if ( ! has_shortcode( $post->post_content, 'ffc_form' ) && ! has_shortcode( $post->post_content, 'ffc_verification' ) ) {
            return;
        }

        // 1. CSS
        wp_enqueue_style( 
            'ffc-frontend-css', 
            FFC_PLUGIN_URL . 'assets/css/frontend.css', 
            array(), 
            '2.5.0' 
        );

        // 2. JS (Bibliotecas de PDF)
        // Só carrega se tiver o formulário de geração (ffc_form)
        if ( has_shortcode( $post->post_content, 'ffc_form' ) ) {
            // html2canvas
            wp_enqueue_script( 
                'html2canvas', 
                'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js', 
                array(), 
                '1.4.1', 
                true 
            );

            // jsPDF
            wp_enqueue_script( 
                'jspdf', 
                'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js', 
                array(), 
                '2.5.1', 
                true 
            );
        }

        // 3. JS Principal do Plugin
        wp_enqueue_script( 
            'ffc-frontend-js', 
            FFC_PLUGIN_URL . 'assets/js/frontend.js', 
            array( 'jquery' ), 
            '2.5.0', 
            true 
        );

        // 4. Localização (Variáveis para o JS)
        wp_localize_script( 'ffc-frontend-js', 'ffc_ajax', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'ffc_frontend_nonce' ),
            'strings'  => array(
                'pdfLibrariesFailed'    => __( 'PDF libraries failed to load.', 'ffc' ),
                'generatingCertificate' => __( 'Generating Certificate... Please wait.', 'ffc' ),
                'connectionError'       => __( 'Connection error. Try again.', 'ffc' ),
                'processing'            => __( 'Processing...', 'ffc' ),
                'enterCode'             => __( 'Please enter the code.', 'ffc' ),
                'verifying'             => __( 'Verifying...', 'ffc' ),
                'verify'                => __( 'Verify', 'ffc' ),
                'idMustHaveDigits'      => __( 'The ID must have 7 or 11 digits.', 'ffc' )
            )
        ));
    }
}
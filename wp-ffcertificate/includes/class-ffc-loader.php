<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Free_Form_Certificate_Loader
 * * O "Maestro" do plugin. Carrega dependências, instancia as classes principais
 * e define os ganchos (hooks) de execução (Cron, i18n, etc).
 */
class Free_Form_Certificate_Loader {

    protected $repository;
    protected $mailer;
    protected $cpt;
    protected $admin;
    protected $frontend;
    protected $admin_ajax;
    protected $frontend_ajax;

    public function __construct() {
        $this->load_dependencies();
        $this->instantiate_classes();
        $this->define_scheduled_events();
        
        // Carrega tradução
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
    }

    /**
     * Inicia a execução do plugin.
     * Como as classes instanciadas no construtor já registram seus hooks,
     * este método pode permanecer vazio ou ser usado para lógica tardia.
     */
    public function run() {
        // Hooks já foram disparados na instanciação.
    }

    private function load_dependencies() {
        // 1. DATA LAYER (Essencial)
        require_once FFC_PLUGIN_DIR . 'includes/data/class-ffc-repository.php';

        // 2. CORE & UTILIDADES
        // Verifica se arquivos existem para evitar Fatal Error durante desenvolvimento
        if ( file_exists( FFC_PLUGIN_DIR . 'includes/class-ffc-activator.php' ) ) require_once FFC_PLUGIN_DIR . 'includes/class-ffc-activator.php';
        if ( file_exists( FFC_PLUGIN_DIR . 'includes/class-ffc-deactivator.php' ) ) require_once FFC_PLUGIN_DIR . 'includes/class-ffc-deactivator.php';
        if ( file_exists( FFC_PLUGIN_DIR . 'includes/class-ffc-security.php' ) ) require_once FFC_PLUGIN_DIR . 'includes/class-ffc-security.php';
        
        // Mailer e Template
        if ( file_exists( FFC_PLUGIN_DIR . 'includes/core/class-ffc-mailer.php' ) ) {
            require_once FFC_PLUGIN_DIR . 'includes/core/class-ffc-mailer.php';
        }
        if ( file_exists( FFC_PLUGIN_DIR . 'includes/core/class-ffc-template-engine.php' ) ) {
            require_once FFC_PLUGIN_DIR . 'includes/core/class-ffc-template-engine.php';
        }

        // 3. POST TYPE (CPT)
        require_once FFC_PLUGIN_DIR . 'includes/class-ffc-cpt.php';

        // 4. ADMIN
        // Carrega apenas o controlador principal. Ele carregará UI, Assets, Settings, Export.
        require_once FFC_PLUGIN_DIR . 'includes/admin/class-ffc-admin.php';
        
        // Admin Ajax (Se separado)
        if ( file_exists( FFC_PLUGIN_DIR . 'includes/admin/class-ffc-admin-ajax.php' ) ) {
            require_once FFC_PLUGIN_DIR . 'includes/admin/class-ffc-admin-ajax.php';
        } elseif ( file_exists( FFC_PLUGIN_DIR . 'includes/class-ffc-ajax.php' ) ) {
            // Suporte legado
            require_once FFC_PLUGIN_DIR . 'includes/class-ffc-ajax.php';
        }

        // 5. FRONTEND
        if ( file_exists( FFC_PLUGIN_DIR . 'includes/frontend/class-ffc-frontend.php' ) ) {
            require_once FFC_PLUGIN_DIR . 'includes/frontend/class-ffc-frontend.php';
        }
        
        // Shortcodes e Assets Frontend
        // (Idealmente o FFC_Frontend carregaria estes, mas mantemos aqui se sua estrutura for desacoplada)
        if ( file_exists( FFC_PLUGIN_DIR . 'includes/frontend/class-ffc-shortcode.php' ) ) {
            require_once FFC_PLUGIN_DIR . 'includes/frontend/class-ffc-shortcode.php';
        }
        if ( file_exists( FFC_PLUGIN_DIR . 'includes/frontend/class-ffc-frontend-assets.php' ) ) {
            require_once FFC_PLUGIN_DIR . 'includes/frontend/class-ffc-frontend-assets.php';
        }
        
        // Frontend Ajax
        if ( file_exists( FFC_PLUGIN_DIR . 'includes/frontend/class-ffc-frontend-ajax.php' ) ) {
            require_once FFC_PLUGIN_DIR . 'includes/frontend/class-ffc-frontend-ajax.php';
        }
    }

    private function instantiate_classes() {
        // 1. Repositório de Dados
        $this->repository = class_exists( 'FFC_Repository' ) ? new FFC_Repository() : null;

        // 2. Serviços Core
        $this->mailer = class_exists( 'FFC_Mailer' ) ? new FFC_Mailer() : null;
        
        // 3. Custom Post Types
        $this->cpt = new FFC_CPT(); // Registra CPT e Duplicação

        // 4. Admin Area
        // Passamos o repositório para o Admin salvar dados
        $this->admin = new FFC_Admin( $this->repository );
        
        // Admin AJAX
        if ( class_exists( 'FFC_Ajax' ) ) {
            $this->admin_ajax = new FFC_Ajax(); 
        } elseif ( class_exists( 'FFC_Admin_Ajax' ) ) {
            $this->admin_ajax = new FFC_Admin_Ajax();
        }

        // 5. Frontend Area
        // Instancia controlador frontend
        if ( class_exists( 'FFC_Frontend' ) ) {
            $this->frontend = new FFC_Frontend( $this->repository );
        }

        // Assets Frontend (CSS/JS do site)
        if ( class_exists( 'FFC_Frontend_Assets' ) ) {
            new FFC_Frontend_Assets();
        }

        // Shortcodes [ffc_form]
        if ( class_exists( 'FFC_Shortcode' ) ) {
            new FFC_Shortcode();
        }
        
        // Frontend Ajax
        if ( class_exists( 'FFC_Frontend_Ajax' ) ) {
            $this->frontend_ajax = new FFC_Frontend_Ajax();
        }

        if ( class_exists( 'FFC_PDF_Generator' ) ) {
        // Note that the class is no longer static
        new FFC_PDF_Generator( $this->repository ); 
        }
        
        if ( class_exists( 'FFC_Export' ) ) {
        $this->export = new FFC_Export( $this->repository );
        $this->export->init();
        }
    }

    private function define_scheduled_events() {
        // Hook para limpeza diária de dados antigos (GDPR/Limpeza)
        add_action( 'ffc_daily_cleanup_hook', array( $this, 'execute_daily_cleanup' ) );
    }

    public function execute_daily_cleanup() {
        if ( ! $this->repository ) return;

        // Busca configurações
        $settings = get_option( 'ffc_settings', array() );
        $days = isset( $settings['cleanup_days'] ) ? intval( $settings['cleanup_days'] ) : 30;
        
        // Se configurado para 0 ou menos, não apaga nada
        if ( $days > 0 ) {
            $this->repository->cleanup_old_submissions( $days );
        }
    }

    public function load_textdomain() {
        load_plugin_textdomain(
            'ffc',
            false,
            dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/'
        );
    }
}
?>
<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * FFC_Admin
 * A classe principal do painel administrativo.
 * Responsável por orquestrar Assets, UI, Menus e instanciar os controladores.
 */
class FFC_Admin {

    /**
     * @var FFC_Submission_Repository
     */
    private $repository;

    /**
     * @var FFC_Submission_Controller
     */
    private $submission_controller;

    private $assets;
    private $ui;
    private $export;
    private $settings_page;

    /**
     * @param FFC_Submission_Repository $repository
     */
    public function __construct( $repository ) {
        $this->repository = $repository;

        $this->load_dependencies();
        $this->instantiate_classes();
        $this->define_hooks();
    }

    private function load_dependencies() {
        // Carrega as classes que compõem o admin
        require_once FFC_PLUGIN_DIR . 'includes/admin/class-ffc-admin-assets.php';
        require_once FFC_PLUGIN_DIR . 'includes/admin/class-ffc-admin-ui.php';
        require_once FFC_PLUGIN_DIR . 'includes/admin/class-ffc-export.php';
        
        // Carrega List Table e Controller
        require_once FFC_PLUGIN_DIR . 'includes/class-ffc-submissions-list-table.php';
        require_once FFC_PLUGIN_DIR . 'includes/class-ffc-submission-controller.php';

        // Carrega Settings
        if ( file_exists( FFC_PLUGIN_DIR . 'includes/admin/class-ffc-settings.php' ) ) {
            require_once FFC_PLUGIN_DIR . 'includes/admin/class-ffc-settings.php';
        }
    }

    private function instantiate_classes() {
        $this->assets = new FFC_Admin_Assets();
        $this->ui     = new FFC_Admin_UI();
        $this->export = new FFC_Export();
        
        // Passamos o handler para as settings
        $this->settings_page = class_exists( 'FFC_Settings' ) ? new FFC_Settings( $this->repository ) : null;

        // INICIALIZA O CONTROLLER DE SUBMISSÕES
        // Ele vai ouvir os POSTs de salvar/deletar/restaurar
        $this->submission_controller = new FFC_Submission_Controller( $this->repository );
    }

    private function define_hooks() {
        // 1. Assets (CSS/JS)
        add_action( 'admin_enqueue_scripts', array( $this->assets, 'enqueue_styles_and_scripts' ) );

        // 2. Exportação (CSV/Excel)
        $this->export->init();

        // 3. Menus e Meta Boxes
        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
        add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ), 30 );

        // 4. Salvamento do FORMULÁRIO (Estrutura e Config, não submissões)
        add_action( 'save_post', array( $this, 'save_form_meta' ) );

        // 5. Registra os hooks do Controller de Submissões
        $this->submission_controller->register_hooks();
    }

    public function register_admin_menu() {
        // 1. Submenu "Submissions" (Listagem Geral)
        add_submenu_page(
            'edit.php?post_type=ffc_form',      // Pai
            __( 'All Submissions', 'ffc' ),     // Título Página
            __( 'Submissions', 'ffc' ),         // Título Menu
            'edit_posts',                       // Cap
            'ffc-submissions',                  // Slug
            array( $this, 'render_submissions_page' ) // Callback
        );

        // 2. Submenu "Settings"
        if ( $this->settings_page ) {
            add_submenu_page(
                'edit.php?post_type=ffc_form',
                __( 'Plugin Settings', 'ffc' ),
                __( 'Settings / SMTP', 'ffc' ),
                'manage_options',
                'ffc-settings',
                array( $this->settings_page, 'render_settings_page' )
            );
        }
    }

    /**
     * RENDERIZA A PÁGINA DE LISTAGEM DE SUBMISSÕES
     * Aqui decidimos se mostramos a Tabela ou o Formulário de Edição
     */
    public function render_submissions_page() {
        // Verifica se é uma ação de edição (UI)
        if ( isset( $_GET['ffc_action'] ) && $_GET['ffc_action'] === 'edit_submission' ) {
            $submission_id = isset($_GET['submission_id']) ? absint($_GET['submission_id']) : 0;
            $this->render_edit_submission_view( $submission_id );
            return;
        }

        // Caso contrário, renderiza a Tabela Padrão (WP_List_Table)
        $list_table = new FFC_Submission_List( $this->repository );
        $list_table->prepare_items();

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php _e( 'Form Submissions', 'ffc' ); ?></h1>
            <hr class="wp-header-end">

            <form id="ffc-submissions-filter" method="get">
                <input type="hidden" name="post_type" value="ffc_form" />
                <input type="hidden" name="page" value="ffc-submissions" />
                
                <?php $list_table->views(); // Abas: All | Trash ?>
                <?php $list_table->search_box( __( 'Search', 'ffc' ), 'submission' ); ?>
                <?php $list_table->display(); // A Tabela em si ?>
            </form>
        </div>
        <?php
    }

    /**
     * Renderiza o formulário de edição de uma submissão específica.
     * (Poderia estar na classe UI, mas mantive aqui para simplificar o fluxo da página)
     */
    private function render_edit_submission_view( $id ) {
        $item = $this->repository->get_by_id( $id );

        if ( ! $item ) {
            echo '<div class="wrap"><div class="notice notice-error"><p>' . __( 'Submission not found.', 'ffc' ) . '</p></div></div>';
            return;
        }

        $data = json_decode( $item['data'], true );
        if ( ! is_array( $data ) ) $data = json_decode( stripslashes( $item['data'] ), true );
        
        // URL para voltar
        $back_url = admin_url( 'edit.php?post_type=ffc_form&page=ffc-submissions' );
        ?>
        <div class="wrap">
            <h1><?php _e( 'Edit Submission', 'ffc' ); ?> #<?php echo esc_html($id); ?></h1>
            
            <form method="post" action="">
                <input type="hidden" name="ffc_action" value="update_submission">
                <input type="hidden" name="submission_id" value="<?php echo esc_attr($id); ?>">
                <?php wp_nonce_field( 'ffc_save_submission_action', 'ffc_save_submission_nonce' ); ?>
                
                <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">
                    <table class="form-table">
                        <tr>
                            <th><?php _e('Date', 'ffc'); ?></th>
                            <td><?php echo esc_html($item['submission_date']); ?></td>
                        </tr>
                        <tr>
                            <th><?php _e('User Email', 'ffc'); ?></th>
                            <td><input type="email" name="ffc_data[email]" value="<?php echo esc_attr($item['email']); ?>" class="regular-text"></td>
                        </tr>
                        <?php if(is_array($data)): foreach ( $data as $key => $value ) : 
                             if($key === 'email') continue; // Já exibido acima
                        ?>
                            <tr>
                                <th><label><?php echo esc_html( ucfirst( str_replace('_', ' ', $key) ) ); ?></label></th>
                                <td>
                                    <?php if ( is_array( $value ) ) : ?>
                                        <input type="text" class="regular-text" name="ffc_data[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( implode(', ', $value) ); ?>">
                                        <p class="description"><?php _e('Comma separated values', 'ffc'); ?></p>
                                    <?php else : ?>
                                        <input type="text" class="regular-text" name="ffc_data[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>">
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </table>
                    
                    <p class="submit">
                        <?php submit_button( __( 'Update Submission', 'ffc' ), 'primary', 'submit', false ); ?>
                        <a href="<?php echo esc_url($back_url); ?>" class="button button-secondary"><?php _e('Cancel', 'ffc'); ?></a>
                    </p>
                </div>
            </form>
        </div>
        <?php
    }

    public function register_meta_boxes() {
        // Metabox: Construtor de Campos
        add_meta_box( 
            'ffc_form_builder', 
            __( 'Form Builder', 'ffc' ), 
            array( $this->ui, 'render_fields_metabox' ), 
            'ffc_form', 
            'normal', 
            'high' 
        );

        // Metabox: Configurações e Layout PDF
        add_meta_box( 
            'ffc_form_config', 
            __( 'Configuration & Certificate Layout', 'ffc' ), 
            array( $this->ui, 'render_config_metabox' ), 
            'ffc_form', 
            'normal', 
            'high' 
        );

        // Metabox: Resultados (Submissões) na tela de edição do FORMULÁRIO
        // Mantém a visualização rápida dentro do form builder
        add_meta_box( 
            'ffc_form_results', 
            __( 'Latest Submissions', 'ffc' ), 
            function( $post ) {
                $this->ui->render_results_metabox( $post, $this->repository );
            }, 
            'ffc_form', 
            'normal', 
            'low' 
        );

        // Metabox: Shortcodes
        add_meta_box( 
            'ffc_shortcodes', 
            __( 'Shortcodes', 'ffc' ), 
            array( $this->ui, 'render_shortcodes_metabox' ), 
            'ffc_form', 
            'side', 
            'default' 
        );
    }

    /**
     * SALVAMENTO DO POST DO FORMULÁRIO (Configuração do CPT)
     * Mantém-se inalterado pois lida com metadados do Post Type, não com as submissões dos usuários.
     */
    public function save_form_meta( $post_id ) {
        // Verificação de Nonce
        if ( ! isset( $_POST['ffc_form_meta_box_nonce'] ) || ! wp_verify_nonce( $_POST['ffc_form_meta_box_nonce'], 'ffc_form_meta_box' ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( 'ffc_form' !== get_post_type( $post_id ) ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        // 1. Salvar Campos do Formulário
        if ( isset( $_POST['ffc_fields'] ) && is_array( $_POST['ffc_fields'] ) ) {
            $fields = array();
            foreach ( $_POST['ffc_fields'] as $key => $field ) {
                if ( isset( $field['name'] ) && $field['name'] === 'TEMPLATE_INDEX' ) continue;
                if ( empty( $field['name'] ) && empty( $field['label'] ) ) continue;

                $clean_field = array(
                    'label'    => sanitize_text_field( isset( $field['label'] ) ? $field['label'] : '' ),
                    'name'     => sanitize_key( isset( $field['name'] ) ? $field['name'] : '' ),
                    'type'     => sanitize_text_field( isset( $field['type'] ) ? $field['type'] : 'text' ),
                    'options'  => sanitize_textarea_field( isset( $field['options'] ) ? $field['options'] : '' ),
                    'required' => isset( $field['required'] ) ? 1 : 0
                );

                if ( ! empty( $clean_field['name'] ) ) {
                    $fields[] = $clean_field;
                }
            }
            $fields = array_values( $fields );
            update_post_meta( $post_id, '_ffc_form_fields', $fields );
        } else {
            update_post_meta( $post_id, '_ffc_form_fields', array() );
        }

        // 2. Salvar Configurações Gerais
        if ( isset( $_POST['ffc_config'] ) && is_array( $_POST['ffc_config'] ) ) {
            $raw = $_POST['ffc_config'];
            $clean_config = array();

            // Textos
            $clean_config['success_message']  = sanitize_textarea_field( isset( $raw['success_message'] ) ? $raw['success_message'] : '' );
            $clean_config['background_image'] = esc_url_raw( isset( $raw['background_image'] ) ? $raw['background_image'] : '' );
            
            // Email
            $clean_config['email_admin']      = sanitize_text_field( isset( $raw['email_admin'] ) ? $raw['email_admin'] : '' );
            $clean_config['email_subject']    = sanitize_text_field( isset( $raw['email_subject'] ) ? $raw['email_subject'] : '' );
            $clean_config['email_body']       = wp_kses_post( isset( $raw['email_body'] ) ? $raw['email_body'] : '' );

            // Segurança
            $clean_config['validation_code']  = sanitize_text_field( isset( $raw['validation_code'] ) ? $raw['validation_code'] : '' );
            
            // Flags
            $clean_config['send_user_email']    = isset( $raw['send_user_email'] ) ? 1 : 0;
            $clean_config['enable_restriction'] = isset( $raw['enable_restriction'] ) ? 1 : 0;

            // Listas
            $clean_config['allowed_users_list']   = sanitize_textarea_field( isset( $raw['allowed_users_list'] ) ? $raw['allowed_users_list'] : '' );
            $clean_config['generated_codes_list'] = sanitize_textarea_field( isset( $raw['generated_codes_list'] ) ? $raw['generated_codes_list'] : '' );
            $clean_config['denied_users_list']    = sanitize_textarea_field( isset( $raw['denied_users_list'] ) ? $raw['denied_users_list'] : '' );
            
            // Layout PDF
            if ( current_user_can( 'unfiltered_html' ) ) {
                $clean_config['pdf_layout'] = isset( $raw['pdf_layout'] ) ? $raw['pdf_layout'] : '';
            } else {
                $clean_config['pdf_layout'] = wp_kses_post( isset( $raw['pdf_layout'] ) ? $raw['pdf_layout'] : '' );
            }

            update_post_meta( $post_id, '_ffc_form_config', $clean_config );
        }
    }
}
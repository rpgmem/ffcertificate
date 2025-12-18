<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Admin {
    
    private $submission_handler;
    private $form_editor;
    private $settings_page;

    public function __construct( FFC_Submission_Handler $handler ) {
        $this->submission_handler = $handler;

        // Carrega e Inicializa as subclasses modularizadas
        require_once plugin_dir_path( __FILE__ ) . 'class-ffc-form-editor.php';
        require_once plugin_dir_path( __FILE__ ) . 'class-ffc-settings.php';

        $this->form_editor   = new FFC_Form_Editor();
        $this->settings_page = new FFC_Settings( $handler );

        // Hooks Gerais de Admin
        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
        
        // Exportação CSV
        add_action( 'admin_init', array( $this, 'handle_csv_export_on_admin_init' ) );
        
        // Edição de Submissão
        add_action( 'admin_init', array( $this, 'handle_submission_edit_save' ) );
        
        // AJAX da Listagem
        add_action( 'wp_ajax_ffc_admin_get_pdf_data', array( $this, 'ajax_admin_get_pdf_data' ) );
    }

    public function register_admin_menu() {
        add_submenu_page( 'edit.php?post_type=ffc_form', __( 'Submissions', 'ffc' ), __( 'Submissions', 'ffc' ), 'manage_options', 'ffc-submissions', array( $this, 'display_submissions_page' ) );
        add_submenu_page( 'edit.php?post_type=ffc_form', __( 'Settings', 'ffc' ), __( 'Settings', 'ffc' ), 'manage_options', 'ffc-settings', array( $this->settings_page, 'display_settings_page' ) );
    }

    public function admin_assets( $hook ) {
        global $post_type;
        if ( $post_type === 'ffc_form' || ( isset($_GET['page']) && strpos($_GET['page'], 'ffc-') !== false ) ) {
            wp_enqueue_style( 'ffc-admin-css', FFC_PLUGIN_URL . 'assets/css/admin.css', array(), '1.0.0' );
            
            wp_enqueue_script( 'html2canvas', FFC_PLUGIN_URL . 'assets/js/html2canvas.min.js', array(), '1.4.1', true );
            wp_enqueue_script( 'jspdf', FFC_PLUGIN_URL . 'assets/js/jspdf.umd.min.js', array(), '2.5.1', true );
            wp_enqueue_script( 'ffc-frontend-js', FFC_PLUGIN_URL . 'assets/js/frontend.js', array( 'jquery', 'html2canvas', 'jspdf' ), time(), true );
            wp_enqueue_script( 'ffc-admin-js', FFC_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery', 'jquery-ui-sortable', 'ffc-frontend-js' ), time(), true );
            
            wp_localize_script( 'ffc-admin-js', 'ffc_admin_ajax', array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'ffc_admin_pdf_nonce' )
            ) );
        }
    }

    // =========================================================================
    // LISTAGEM E EDIÇÃO DE SUBMISSÕES
    // =========================================================================
    
    public function display_submissions_page() {
        $action = isset( $_GET['action'] ) ? $_GET['action'] : 'list';
        switch ( $action ) { 
            case 'edit': 
                $this->render_edit_page(); 
                break; 
            default: 
                $this->render_list_page(); 
                break; 
        }
    }
    
    private function render_list_page() {
        require_once FFC_PLUGIN_DIR . 'includes/class-ffc-submissions-list-table.php';
        $table = new FFC_Submission_List( $this->submission_handler );
        
        if ( isset( $_GET['submission_id'] ) ) {
            $id = absint( $_GET['submission_id'] );
            if ( isset( $_GET['action'] ) ) {
                $act = $_GET['action'];
                if ( $act === 'trash' ) { check_admin_referer( 'ffc_trash_submission' ); $this->submission_handler->trash_submission( $id ); echo '<div class="updated notice is-dismissible"><p>Item movido para a lixeira.</p></div>'; }
                if ( $act === 'restore' ) { check_admin_referer( 'ffc_restore_submission' ); $this->submission_handler->restore_submission( $id ); echo '<div class="updated notice is-dismissible"><p>Item restaurado.</p></div>'; }
                if ( $act === 'delete' ) { check_admin_referer( 'ffc_delete_submission' ); $this->submission_handler->delete_submission( $id ); echo '<div class="updated notice is-dismissible"><p>Item excluído permanentemente.</p></div>'; }
            }
        }

        if ( isset( $_GET['submission'] ) && is_array( $_GET['submission'] ) ) {
            $ids = $_GET['submission']; 
            $act = isset($_GET['action']) && $_GET['action']!=-1 ? $_GET['action'] : (isset($_GET['action2']) ? $_GET['action2'] : '');
            if($act=='bulk_trash'){ foreach($ids as $i) $this->submission_handler->trash_submission(absint($i)); echo '<div class="updated notice is-dismissible"><p>Itens movidos para a lixeira.</p></div>'; }
            if($act=='bulk_restore'){ foreach($ids as $i) $this->submission_handler->restore_submission(absint($i)); echo '<div class="updated notice is-dismissible"><p>Itens restaurados.</p></div>'; }
            if($act=='bulk_delete'){ foreach($ids as $i) $this->submission_handler->delete_submission(absint($i)); echo '<div class="updated notice is-dismissible"><p>Itens excluídos.</p></div>'; }
        }
        
        $table->prepare_items();
        ?> 
        <div class="wrap">
            <h1>Submissions</h1>
            <div style="float:right;margin-top:10px;">
                <form method="POST">
                    <input type="hidden" name="ffc_action" value="export_csv_smart">
                    <?php if(isset($_GET['filter_form_id']) && $_GET['filter_form_id'] > 0): ?>
                        <input type="hidden" name="form_id" value="<?php echo intval($_GET['filter_form_id']); ?>">
                        <button type="submit" class="button button-primary">CSV Filtrado</button>
                    <?php else: ?>
                        <button type="submit" class="button">CSV Tudo</button>
                    <?php endif; ?>
                    <?php wp_nonce_field('ffc_export_csv_nonce','ffc_export_csv_action'); ?>
                </form>
            </div>
            <hr class="wp-header-end">
            <form method="GET">
                <input type="hidden" name="post_type" value="ffc_form">
                <input type="hidden" name="page" value="ffc-submissions">
                <?php 
                $table->views(); 
                $table->search_box('Buscar','s'); 
                $table->display(); 
                ?>
            </form>
        </div> 
        <?php
    }

    // --- CORREÇÃO 1: REMOVIDO cpf_rf DA PROTEÇÃO E ADICIONADO ALERTA VISUAL ---
    private function render_edit_page() {
        $submission_id = isset( $_GET['submission_id'] ) ? absint( $_GET['submission_id'] ) : 0;
        global $wpdb; 
        $table = $wpdb->prefix . 'ffc_submissions'; 
        $sub = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $submission_id ) );
        
        if ( ! $sub ) { echo '<div class="wrap"><p>Não encontrado.</p></div>'; return; }

        $data = json_decode( $sub->data, true ); 
        if ( ! is_array( $data ) ) $data = json_decode( stripslashes($sub->data), true );
        
        $fields = get_post_meta( $sub->form_id, '_ffc_form_fields', true );
        
        // --- APENAS TICKET E DADOS TÉCNICOS SÃO PROTEGIDOS AGORA ---
        // CPF_RF foi removido desta lista para permitir edição
        $protected_fields = array( 'auth_code', 'fill_date', 'ticket' );

        ?> 
        <div class="wrap">
            <h1>Editar Submissão #<?php echo $sub->id; ?></h1>
            
            <?php 
            // ALERTA VISUAL: Se foi editado, mostra aviso
            if ( isset( $data['is_edited'] ) && $data['is_edited'] == true ) {
                $edit_date = isset($data['edited_at']) ? date_i18n( get_option('date_format') . ' ' . get_option('time_format'), strtotime($data['edited_at']) ) : 'Data desconhecida';
                echo '<div class="notice notice-warning inline" style="margin: 10px 0 20px 0; border-left-color: #f0ad4e;"><p><strong>⚠️ Atenção:</strong> Este registro foi editado manualmente por um administrador em <u>' . esc_html($edit_date) . '</u>.</p></div>';
            }
            ?>

            <form method="POST">
                <?php wp_nonce_field( 'ffc_edit_submission_nonce', 'ffc_edit_submission_action' ); ?>
                <input type="hidden" name="submission_id" value="<?php echo $sub->id; ?>">
                
                <table class="form-table">
                    <tr><th>Email</th><td><input type="email" name="user_email" value="<?php echo esc_attr($sub->email); ?>" class="regular-text"></td></tr>
                    <?php if(is_array($data)): foreach($data as $k => $v): 
                        // Pula campos de controle interno na exibição se desejar, ou exibe
                        if ($k === 'is_edited' || $k === 'edited_at') continue;

                        $lbl = $k; 
                        if(is_array($fields)) { foreach($fields as $f) { if(isset($f['name']) && $f['name'] === $k) $lbl = $f['label']; } }
                        
                        // Verifica se o campo atual está na lista de protegidos
                        $is_protected = in_array( $k, $protected_fields );
                        $ro = $is_protected ? 'readonly style="background:#f0f0f1; color:#666; cursor:not-allowed;"' : ''; 
                        $desc = $is_protected ? '<p class="description" style="font-size:11px;">Este campo é gerado automaticamente (Ticket/Código) e não pode ser editado.</p>' : '';
                        ?>
                        <tr>
                            <th><?php echo esc_html($lbl); ?></th>
                            <td>
                                <input type="text" name="data[<?php echo $k; ?>]" value="<?php echo esc_attr($v); ?>" class="regular-text" <?php echo $ro; ?>>
                                <?php echo $desc; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </table>
                <p class="submit">
                    <button type="submit" name="ffc_save_edit" class="button button-primary">Salvar Alterações</button> 
                    <a href="<?php echo admin_url('edit.php?post_type=ffc_form&page=ffc-submissions'); ?>" class="button">Cancelar</a>
                </p>
            </form>
        </div> 
        <?php
    }

    // --- CORREÇÃO 2: PERMITIR SALVAR CPF_RF E MARCAR COMO EDITADO ---
    public function handle_submission_edit_save() {
        if ( isset( $_POST['ffc_save_edit'] ) && check_admin_referer( 'ffc_edit_submission_nonce', 'ffc_edit_submission_action' ) ) {
            global $wpdb; 
            $table = $wpdb->prefix . 'ffc_submissions'; 
            $id = absint( $_POST['submission_id'] ); 
            $email = sanitize_email( $_POST['user_email'] ); 
            $raw = isset($_POST['data']) ? $_POST['data'] : array();
            
            // Dados antigos para restaurar os campos protegidos
            $old = $wpdb->get_row($wpdb->prepare("SELECT data FROM $table WHERE id=%d", $id)); 
            $old_data = json_decode($old->data, true); 
            if(!is_array($old_data)) $old_data = json_decode(stripslashes($old->data), true);
            
            // Lista de campos protegidos (Sem CPF/RF)
            $protected_fields = array( 'auth_code', 'fill_date', 'ticket' );

            $clean = array(); 
            foreach($raw as $k => $v) { 
                // Se for protegido e existir valor antigo, mantém o antigo
                if( in_array($k, $protected_fields) && isset($old_data[$k]) ) { 
                    $clean[$k] = $old_data[$k]; 
                } else { 
                    $clean[sanitize_key($k)] = sanitize_text_field($v); 
                }
            }

            // --- ADICIONA FLAGS DE EDIÇÃO ---
            $clean['is_edited'] = true;
            $clean['edited_at'] = current_time('mysql');
            
            $wpdb->update($table, array('email' => $email, 'data' => wp_json_encode($clean)), array('id' => $id));
            wp_redirect(admin_url('edit.php?post_type=ffc_form&page=ffc-submissions&msg=updated')); 
            exit;
        }
    }

    public function handle_csv_export_on_admin_init() {
        global $wpdb;
        if ( isset( $_POST['ffc_action'] ) && $_POST['ffc_action'] === 'export_csv_smart' ) {
            check_admin_referer( 'ffc_export_csv_nonce', 'ffc_export_csv_action' );
            $form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
            $table = $wpdb->prefix . 'ffc_submissions';
            
            if ( $form_id > 0 ) {
                $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE form_id = %d ORDER BY submission_date DESC", $form_id ), ARRAY_A );
                $filename = 'submissions-form-' . $form_id . '.csv';
            } else {
                $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY submission_date DESC", ARRAY_A );
                $filename = 'all-submissions.csv';
            }
            if(empty($rows)) wp_die('No Data');
            header('Content-Type: text/csv'); header("Content-Disposition: attachment; filename=$filename");
            $out = fopen('php://output', 'w'); fputcsv($out, array('ID', 'Date', 'Email', 'Status', 'Data (JSON)'));
            foreach($rows as $r) { 
                $d = json_decode($r['data'], true); if(!is_array($d)) $d = json_decode(stripslashes($r['data']), true);
                fputcsv($out, array($r['id'], $r['submission_date'], $r['email'], isset($r['status']) ? $r['status'] : 'publish', json_encode($d))); 
            } fclose($out); exit;
        }
    }

    public function ajax_admin_get_pdf_data() {
        check_ajax_referer( 'ffc_admin_pdf_nonce', 'nonce' );
        $id = isset($_POST['submission_id']) ? absint($_POST['submission_id']) : 0;
        if ( ! $id ) wp_send_json_error( 'ID inválido' );
        global $wpdb; $table = $wpdb->prefix . 'ffc_submissions';
        $sub = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
        if ( ! $sub ) wp_send_json_error( 'Registro não encontrado' );
        
        $data = json_decode( $sub->data, true ); if ( ! is_array( $data ) ) $data = json_decode( stripslashes( $sub->data ), true );
        $fmt = get_option('date_format') . ' ' . get_option('time_format');
        $dval = isset($data['fill_date']) ? $data['fill_date'] : date_i18n($fmt, strtotime($sub->submission_date));
        $data['fill_date'] = $dval; $data['date'] = $dval; $data['submission_date'] = $dval;
        
        $config = get_post_meta( $sub->form_id, '_ffc_form_config', true );
        $title  = get_the_title( $sub->form_id );
        wp_send_json_success(array('template' => isset($config['pdf_layout']) ? $config['pdf_layout'] : '','form_title' => $title,'submission_id' => $sub->id,'submission' => $data));
    }
}
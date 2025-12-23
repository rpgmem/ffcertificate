<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Classe responsável pela administração do plugin
 */
class FFC_Admin {
    
    private $submission_handler;
    private $form_editor;
    private $settings_page;

    public function __construct( FFC_Submission_Handler $handler ) {
        $this->submission_handler = $handler;

        require_once plugin_dir_path( __FILE__ ) . 'class-ffc-form-editor.php';
        require_once plugin_dir_path( __FILE__ ) . 'class-ffc-settings.php';

        $this->form_editor   = new FFC_Form_Editor();
        $this->settings_page = new FFC_Settings( $handler );

        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
        
        add_action( 'admin_init', array( $this, 'handle_submission_actions' ) );
        add_action( 'admin_init', array( $this, 'handle_csv_export_on_admin_init' ) );
        add_action( 'admin_init', array( $this, 'handle_submission_edit_save' ) );
        
        add_action( 'wp_ajax_ffc_admin_get_pdf_data', array( $this, 'ajax_admin_get_pdf_data' ) );
    }

    public function register_admin_menu() {
        add_submenu_page( 
            'edit.php?post_type=ffc_form', 
            __( 'Submissions', 'ffc' ), 
            __( 'Submissions', 'ffc' ), 
            'manage_options', 
            'ffc-submissions', 
            array( $this, 'display_submissions_page' ) 
        );
        add_submenu_page( 
            'edit.php?post_type=ffc_form', 
            __( 'Settings', 'ffc' ), 
            __( 'Settings', 'ffc' ), 
            'manage_options', 
            'ffc-settings', 
            array( $this->settings_page, 'display_settings_page' ) 
        );
    }

    public function admin_assets( $hook ) {
        global $post_type;
        
        // Carrega assets apenas na edição do formulário ou nas páginas do plugin
        $is_ffc_page = ( isset($_GET['page']) && strpos($_GET['page'], 'ffc-') !== false );
        
        if ( $post_type === 'ffc_form' || $is_ffc_page ) {
            wp_enqueue_media(); // Garante que a biblioteca de mídia do WP funcione
            
            wp_enqueue_style( 'ffc-pdf-core', FFC_PLUGIN_URL . 'assets/css/ffc-pdf-core.css', array(), time() );
            wp_enqueue_style( 'ffc-admin-css', FFC_PLUGIN_URL . 'assets/css/admin.css', array('ffc-pdf-core'), time() );
            
            wp_enqueue_script( 'html2canvas', FFC_PLUGIN_URL . 'assets/js/html2canvas.min.js', array(), '1.4.1', true );
            wp_enqueue_script( 'jspdf', FFC_PLUGIN_URL . 'assets/js/jspdf.umd.min.js', array(), '2.5.1', true );
            
            wp_enqueue_script( 'ffc-frontend-js', FFC_PLUGIN_URL . 'assets/js/frontend.js', array( 'jquery', 'html2canvas', 'jspdf' ), time(), true );
            
            // Adicionado explicitamente jquery-ui-sortable como dependência
            wp_enqueue_script( 'ffc-admin-js', FFC_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery', 'jquery-ui-sortable', 'ffc-frontend-js' ), time(), true );
            
            wp_localize_script( 'ffc-admin-js', 'ffc_admin_ajax', array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'ffc_admin_pdf_nonce' ),
                'strings'  => array(
                    'generating'            => __( 'Generating...', 'ffc' ),
                    'error'                 => __( 'Error: ', 'ffc' ),
                    'selectTemplate'        => __( 'Please select a template.', 'ffc' ),
                    'confirmReplaceContent' => __( 'Are you sure? This will replace the current layout.', 'ffc' ),
                    'templateLoaded'        => __( 'Template loaded successfully!', 'ffc' ),
                    'loadTemplate'          => __( 'Load Template', 'ffc' ),
                    'codesGenerated'        => __( 'codes generated.', 'ffc' ),
                    'errorGeneratingCodes'  => __( 'Error generating codes.', 'ffc' ),
                    'confirmDeleteField'    => __( 'Are you sure you want to remove this field?', 'ffc' ),
                    'selectBackgroundImage' => __( 'Select Background Image', 'ffc' ),
                    'useImage'              => __( 'Use Image', 'ffc' ),
                    'fileImported'          => __( 'File imported successfully!', 'ffc' ),
                    'connectionError'       => __( 'Connection error.', 'ffc' ),
                )
            ) );
        }
    }

    // ... Restante do código (handle_submission_actions, render_list_page, etc) permanecem iguais ...
    // Note: Mantive o restante do seu código original abaixo desta linha para brevidade, 
    // pois a lógica de submissão não afeta a visibilidade dos campos no editor.

    public function handle_submission_actions() {
        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'ffc-submissions' ) return;
        if ( isset( $_GET['submission_id'] ) && isset( $_GET['action'] ) ) {
            $id     = absint( $_GET['submission_id'] );
            $action = sanitize_key( $_GET['action'] );
            $nonce  = isset($_GET['_wpnonce']) ? $_GET['_wpnonce'] : '';
            $manipulation_actions = array( 'trash', 'restore', 'delete' );
            if ( in_array( $action, $manipulation_actions ) ) {
                if ( wp_verify_nonce( $nonce, 'ffc_action_' . $id ) ) {
                    if ( $action === 'trash' ) $this->submission_handler->trash_submission( $id );
                    if ( $action === 'restore' ) $this->submission_handler->restore_submission( $id );
                    if ( $action === 'delete' ) $this->submission_handler->delete_submission( $id );
                    $this->redirect_with_msg( $action );
                }
            }
        }
        if ( isset($_GET['action']) && isset($_GET['submission']) && is_array($_GET['submission']) ) {
            $bulk_action = $_GET['action'];
            if ( $bulk_action === '-1' && isset($_GET['action2']) ) $bulk_action = $_GET['action2'];
            $allowed_bulk = array( 'bulk_trash', 'bulk_restore', 'bulk_delete' );
            if ( in_array( $bulk_action, $allowed_bulk ) ) {
                check_admin_referer('bulk-submissions'); 
                $ids = array_map('absint', $_GET['submission']);
                foreach ( $ids as $id ) {
                    if ( $bulk_action === 'bulk_trash' ) $this->submission_handler->trash_submission( $id );
                    if ( $bulk_action === 'bulk_restore' ) $this->submission_handler->restore_submission( $id );
                    if ( $bulk_action === 'bulk_delete' ) $this->submission_handler->delete_submission( $id );
                }
                $this->redirect_with_msg('bulk_done');
            }
        }
    }

    public function display_submissions_page() {
        $action = isset( $_GET['action'] ) ? $_GET['action'] : 'list';
        if ( $action === 'edit' ) {
            $this->render_edit_page();
        } else {
            $this->render_list_page();
        }
    }
    
    private function render_list_page() {
        require_once FFC_PLUGIN_DIR . 'includes/class-ffc-submissions-list-table.php';
        $table = new FFC_Submission_List( $this->submission_handler );
        $this->display_admin_notices();
        $table->prepare_items();
        ?> 
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php _e( 'Submissions', 'ffc' ); ?></h1>
            <div class="ffc-admin-top-actions">
                <form method="POST">
                    <input type="hidden" name="ffc_action" value="export_csv_smart">
                    <?php if(isset($_GET['filter_form_id']) && $_GET['filter_form_id'] > 0): ?>
                        <input type="hidden" name="form_id" value="<?php echo intval($_GET['filter_form_id']); ?>">
                        <button type="submit" class="button button-primary"><?php _e( 'Filtered CSV', 'ffc' ); ?></button>
                    <?php else: ?>
                        <button type="submit" class="button"><?php _e( 'All CSV', 'ffc' ); ?></button>
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
                $table->search_box( __( 'Search', 'ffc' ), 's' ); 
                ?>
                <div class="ffc-table-responsive">
                    <?php $table->display(); ?>
                </div>
            </form>
        </div> 
        <?php
    }

    private function redirect_with_msg($msg) {
        $url = remove_query_arg(array('action', 'action2', 'submission_id', 'submission', '_wpnonce'), $_SERVER['REQUEST_URI']);
        wp_redirect( add_query_arg('msg', $msg, $url) );
        exit;
    }

    private function display_admin_notices() {
        if (!isset($_GET['msg'])) return;
        $msg = $_GET['msg'];
        $text = '';
        $type = 'updated';
        switch ($msg) {
            case 'trash':   $text = __('Item moved to trash.', 'ffc'); break;
            case 'restore': $text = __('Item restored.', 'ffc'); break;
            case 'delete':  $text = __('Item permanently deleted.', 'ffc'); break;
            case 'bulk_done': $text = __('Bulk action completed.', 'ffc'); break;
            case 'updated': $text = __('Submission updated successfully.', 'ffc'); break;
        }
        if ($text) {
            echo "<div class='$type notice is-dismissible'><p>$text</p></div>";
        }
    }
    
    private function render_edit_page() {
        $submission_id = isset( $_GET['submission_id'] ) ? absint( $_GET['submission_id'] ) : 0;
        $sub = $this->submission_handler->get_submission( $submission_id );
        if ( ! $sub ) { 
            echo '<div class="wrap"><p>' . __( 'Submission not found.', 'ffc' ) . '</p></div>'; 
            return; 
        }
        $sub_array = (array) $sub;
        $data = json_decode( $sub_array['data'], true ) ?: array(); 
        $fields = get_post_meta( $sub_array['form_id'], '_ffc_form_fields', true );
        $protected_fields = array( 'auth_code', 'fill_date', 'ticket' );
        ?> 
        <div class="wrap">
            <h1><?php printf( __( 'Edit Submission #%s', 'ffc' ), $sub_array['id'] ); ?></h1>
            <?php if ( isset( $data['is_edited'] ) && $data['is_edited'] == true ): ?>
                <div class="ffc-admin-notice ffc-admin-notice-warning">
                    <p><strong>⚠️ <?php _e( 'Warning:', 'ffc' ); ?></strong> <?php _e( 'This record was manually edited on', 'ffc' ); ?> <u><?php echo esc_html($data['edited_at']); ?></u>.</p>
                </div>
            <?php endif; ?>
            <form method="POST" class="ffc-edit-submission-form">
                <?php wp_nonce_field( 'ffc_edit_submission_nonce', 'ffc_edit_submission_action' ); ?>
                <input type="hidden" name="submission_id" value="<?php echo $sub_array['id']; ?>">
                <table class="form-table">
                    <tr>
                        <th><label for="user_email"><?php _e( 'Email', 'ffc' ); ?></label></th>
                        <td><input type="email" name="user_email" id="user_email" value="<?php echo esc_attr($sub_array['email']); ?>" class="regular-text"></td>
                    </tr>
                    <?php if(is_array($data)): foreach($data as $k => $v): 
                        if ($k === 'is_edited' || $k === 'edited_at') continue;
                        $lbl = $k; 
                        if(is_array($fields)) { 
                            foreach($fields as $f) { 
                                if(isset($f['name']) && $f['name'] === $k) $lbl = $f['label']; 
                            } 
                        }
                        $is_protected = in_array( $k, $protected_fields );
                        $field_class = $is_protected ? 'regular-text ffc-input-readonly' : 'regular-text';
                        $readonly_attr = $is_protected ? 'readonly' : ''; 
                        ?>
                        <tr>
                            <th><?php echo esc_html($lbl); ?></th>
                            <td>
                                <?php $display_value = is_array($v) ? implode(', ', $v) : $v; ?>
                                <input type="text" name="data[<?php echo $k; ?>]" value="<?php echo esc_attr($display_value); ?>" class="<?php echo $field_class; ?>" <?php echo $readonly_attr; ?>>
                                <?php if($is_protected): ?>
                                    <p class="description"><?php _e('Protected internal field.', 'ffc'); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </table>
                <p class="submit">
                    <button type="submit" name="ffc_save_edit" class="button button-primary"><?php _e( 'Save Changes', 'ffc' ); ?></button> 
                    <a href="<?php echo admin_url('edit.php?post_type=ffc_form&page=ffc-submissions'); ?>" class="button"><?php _e( 'Cancel', 'ffc' ); ?></a>
                </p>
            </form>
        </div> 
        <?php
    }

    public function handle_submission_edit_save() {
        if ( isset( $_POST['ffc_save_edit'] ) && check_admin_referer( 'ffc_edit_submission_nonce', 'ffc_edit_submission_action' ) ) {
            $id        = absint( $_POST['submission_id'] ); 
            $new_email = sanitize_email( $_POST['user_email'] ); 
            $raw_data  = isset($_POST['data']) ? $_POST['data'] : array();
            $clean_data = array(); 
            foreach($raw_data as $k => $v) { 
                $clean_data[sanitize_key($k)] = wp_kses($v, FFC_Utils::get_allowed_html_tags()); 
            }
            $clean_data['is_edited'] = true;
            $clean_data['edited_at'] = current_time('mysql');
            $this->submission_handler->update_submission($id, $new_email, $clean_data);
            wp_redirect(admin_url('edit.php?post_type=ffc_form&page=ffc-submissions&msg=updated')); 
            exit;
        }
    }

    public function handle_csv_export_on_admin_init() {
        if ( isset( $_POST['ffc_action'] ) && $_POST['ffc_action'] === 'export_csv_smart' ) {
            check_admin_referer( 'ffc_export_csv_nonce', 'ffc_export_csv_action' );
            $this->submission_handler->export_csv();
        }
    }

    public function ajax_admin_get_pdf_data() {
        check_ajax_referer( 'ffc_admin_pdf_nonce', 'nonce' );
        $id = isset($_POST['submission_id']) ? absint($_POST['submission_id']) : 0;
        $sub = $this->submission_handler->get_submission($id);
        if ( ! $sub ) { wp_send_json_error(); }
        $sub_array = (array) $sub;
        $data = json_decode( $sub_array['data'], true ); 
        if ( ! is_array( $data ) ) { $data = json_decode( stripslashes( $sub_array['data'] ), true ); }
        $data['email'] = $sub_array['email'];
        $form_id = $sub_array['form_id'];
        $form_title = get_the_title($form_id);
        $config = get_post_meta( $form_id, '_ffc_form_config', true );
        $bg_image_url = get_post_meta( $form_id, '_ffc_form_bg', true );
        $processed_html = $this->submission_handler->generate_pdf_html( $data, $form_title, $config );
        wp_send_json_success(array(
            'template'   => $processed_html,
            'submission' => $data,
            'bg_image'   => $bg_image_url,
            'form_title' => $form_title
        ));
    }
}
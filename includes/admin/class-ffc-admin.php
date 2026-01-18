<?php
/**
 * FFC_Admin
 * v2.10.0: ENCRYPTION - Shows LGPD consent status, data auto-decrypted by Submission Handler
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Admin {
    
    private $submission_handler;
    private $csv_exporter;
    private $email_handler;
    private $form_editor;
    private $settings_page;
    private $migration_manager;  // ✅ v2.9.13: Migration Manager

    public function __construct( $handler, $exporter, $email_handler = null ) {
        $this->submission_handler = $handler;
        $this->csv_exporter = $exporter;
        $this->email_handler = $email_handler;

        require_once plugin_dir_path( __FILE__ ) . 'class-ffc-form-editor.php';
        require_once plugin_dir_path( __FILE__ ) . 'class-ffc-settings.php';

        $this->form_editor   = new FFC_Form_Editor();
        $this->settings_page = new FFC_Settings( $handler );

        // ✅ v2.9.13: Initialize Migration Manager
        if ( ! class_exists( 'FFC_Migration_Manager' ) ) {
            require_once FFC_PLUGIN_DIR . 'includes/migrations/class-ffc-migration-manager.php';
        }
        $this->migration_manager = new FFC_Migration_Manager();

        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
        
        // ✅ v2.9.3: Configure TinyMCE to protect placeholders
        // Priority 999 to run AFTER other plugins
        add_filter( 'tiny_mce_before_init', array( $this, 'configure_tinymce_placeholders' ), 999 );
        
        add_action( 'admin_init', array( $this, 'handle_submission_actions' ) );
        add_action( 'admin_init', array( $this, 'handle_csv_export_request' ) );
        add_action( 'admin_init', array( $this, 'handle_submission_edit_save' ) );
        add_action( 'admin_init', array( $this, 'handle_migration_action' ) );  // ✅ v2.9.13: Unified handler
        
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
    }

    public function admin_assets( $hook ) {
        global $post_type;
        
        $is_ffc_page = ( isset($_GET['page']) && strpos($_GET['page'], 'ffc-') !== false );
        
        if ( $post_type === 'ffc_form' || $is_ffc_page ) {
            wp_enqueue_media();
            
            // CSS - Centralized with proper dependency chain
            // 1. Base styles (PDF core)
            wp_enqueue_style( 'ffc-pdf-core', FFC_PLUGIN_URL . 'assets/css/ffc-pdf-core.css', array(), FFC_VERSION);
            
            // 2. Admin general styles (depends on pdf-core)
            wp_enqueue_style( 'ffc-admin-css', FFC_PLUGIN_URL . 'assets/css/ffc-admin.css', array('ffc-pdf-core'), FFC_VERSION );
            
            // 3. Submissions page styles (depends on ffc-admin.css)
            wp_enqueue_style( 'ffc-admin-submissions-css', FFC_PLUGIN_URL . 'assets/css/ffc-admin-submissions.css', array('ffc-admin-css'), FFC_VERSION );
            
            // 4. Settings tabs styles (ONLY on settings page)
            if (isset($_GET['page']) && $_GET['page'] === 'ffc-settings') {
                wp_enqueue_style(
                    'ffc-admin-settings',
                    FFC_PLUGIN_URL . 'assets/css/ffc-admin-settings.css',
                    array('ffc-admin-css'),
                    FFC_VERSION
                );
            }
    
            wp_enqueue_script( 'ffc-admin-js', FFC_PLUGIN_URL . 'assets/js/ffc-admin.js', array( ), FFC_VERSION, true );
            
            // Localizar para AMBOS os scripts
            $localize_data = array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'ffc_admin_pdf_nonce' ),
                'strings'  => array(
                    'generating'              => __( 'Generating...', 'ffc' ),
                    'error'                   => __( 'Error: ', 'ffc' ),
                    'connectionError'         => __( 'Connection error.', 'ffc' ),
                    'fileImported'            => __( 'File imported successfully!', 'ffc' ),
                    'errorReadingFile'        => __( 'Error reading file.', 'ffc' ),
                    'selectTemplate'          => __( 'Please select a template.', 'ffc' ),
                    'confirmReplaceContent'   => __( 'This will replace current content. Continue?', 'ffc' ),
                    'loading'                 => __( 'Loading...', 'ffc' ),
                    'templateLoaded'          => __( 'Template loaded successfully!', 'ffc' ),
                    'selectBackgroundImage'   => __( 'Select Background Image', 'ffc' ),
                    'useImage'                => __( 'Use this image', 'ffc' ),
                    'codesGenerated'          => __( 'codes generated', 'ffc' ),
                    'errorGeneratingCodes'    => __( 'Error generating codes.', 'ffc' ),
                    'confirmDeleteField'      => __( 'Remove this field?', 'ffc' ),
                    'pdfLibrariesFailed'      => __( 'Error: PDF libraries failed to load.', 'ffc' ),
                    'pdfGenerationError'      => __( 'Error generating PDF. Please try again.', 'ffc' ),
                    'pleaseWait'              => __( 'Please wait, this may take a few seconds...', 'ffc' ),
                    'downloadAgain'           => __( 'Download Again', 'ffc' ),
                    'verifying'               => __( 'Verifying...', 'ffc' ),
                    'processing'              => __( 'Processing...', 'ffc' ),
                )
            );
            
            wp_localize_script( 'ffc-admin-js', 'ffc_ajax', $localize_data );
        }
    }

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
        require_once FFC_PLUGIN_DIR . 'includes/admin/class-ffc-submissions-list-table.php';
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
            case 'trash':     
                $text = __('Item moved to trash.', 'ffc'); 
                break;
            case 'restore':   
                $text = __('Item restored.', 'ffc'); 
                break;
            case 'delete':    
                $text = __('Item permanently deleted.', 'ffc'); 
                break;
            case 'bulk_done': 
                $text = __('Bulk action completed.', 'ffc'); 
                break;
            case 'updated':   
                $text = __('Submission updated successfully.', 'ffc'); 
                break;
            case 'migration_success':
                $migrated = isset($_GET['migrated']) ? intval($_GET['migrated']) : 0;
                $migration_name = isset($_GET['migration_name']) ? urldecode($_GET['migration_name']) : __('Migration', 'ffc');
                $text = sprintf(__('%s: %d records migrated successfully.', 'ffc'), $migration_name, $migrated);
                break;
            case 'migration_error':
                $error_msg = isset($_GET['error_msg']) ? urldecode($_GET['error_msg']) : __('Unknown error', 'ffc');
                $text = __('Migration Error: ', 'ffc') . $error_msg;
                $type = 'error';
                break;
        }

        if ($text) {
            echo "<div class='$type notice is-dismissible'><p>" . esc_html($text) . "</p></div>";
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
    
    // ✅ Campos protegidos (read-only dentro do JSON)
    $protected_json_fields = array( 'auth_code', 'fill_date', 'ticket' );
    
    // ✅ Campos editáveis nas colunas do banco
    $editable_columns = array( 'email' ); // Apenas email é editável
    
    // ✅ Campos read-only nas colunas do banco
    $readonly_columns = array( 'cpf_rf', 'auth_code', 'user_ip', 'magic_token', 'submission_date', 'consent_date', 'status' );
    
    $magic_token = isset( $sub_array['magic_token'] ) ? $sub_array['magic_token'] : '';
    $magic_link_url = FFC_Magic_Link_Helper::generate_magic_link( $magic_token );

    $formatted_date = isset( $sub_array['submission_date'] ) ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $sub_array['submission_date'] ) ) : __( 'Unknown', 'ffc' );
    
    // ✅ v3.0.2: Verificar se foi editado (COLUNAS do banco, não JSON)
    $was_edited = !empty( $sub_array['edited_at'] );
    $edited_at = $was_edited ? $sub_array['edited_at'] : '';
    $edited_by_id = !empty( $sub_array['edited_by'] ) ? $sub_array['edited_by'] : 0;
    $edited_by_name = '';
    
    if ( $edited_by_id ) {
        $user = get_userdata( $edited_by_id );
        $edited_by_name = $user ? $user->display_name : 'ID: ' . $edited_by_id;
    }

    ?> 
    <div class="wrap">
        <h1><?php printf( __( 'Edit Submission #%s', 'ffc' ), $sub_array['id'] ); ?></h1>
        
        <?php 
        // ✅ v3.0.2: Indicador visual usando COLUNAS do banco
        if ( $was_edited ): 
        ?>
            <div class="notice notice-warning ffc-edited-notice">
                <p>
                    <strong><?php _e( '⚠️ Warning:', 'ffc' ); ?></strong> 
                    <?php 
                    printf( 
                        __( 'This record was manually edited on <strong>%s</strong>', 'ffc' ), 
                        date_i18n( get_option('date_format') . ' ' . get_option('time_format'), strtotime($edited_at) )
                    ); 
                    ?>
                    <?php if ( $edited_by_name ): ?>
                        <?php printf( __( ' by <strong>%s</strong>', 'ffc' ), esc_html($edited_by_name) ); ?>
                    <?php endif; ?>
                    .
                </p>
            </div>
        <?php endif; ?>

        <form method="POST" class="ffc-edit-submission-form">
            <?php wp_nonce_field( 'ffc_edit_submission_nonce', 'ffc_edit_submission_action' ); ?>
            <input type="hidden" name="submission_id" value="<?php echo $sub_array['id']; ?>">
            
            <table class="form-table">
                <!-- SEÇÃO: INFORMAÇÕES DO SISTEMA -->
                <tr>
                    <td colspan="2" style="padding: 0;">
                        <h2 style="margin: 20px 0 10px 0; padding: 10px; background: #f0f0f1; border-left: 4px solid #2271b1;">
                            <?php _e( 'System Information', 'ffc' ); ?>
                        </h2>
                    </td>
                </tr>
                
                <tr>
                    <th><label><?php _e( 'Submission ID', 'ffc' ); ?></label></th>
                    <td>
                        <input type="text" value="<?php echo esc_attr( $sub_array['id'] ); ?>" class="regular-text ffc-input-readonly" readonly>
                        <p class="description"><?php _e( 'Unique submission identifier.', 'ffc' ); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th><label><?php _e( 'Submission Date', 'ffc' ); ?></label></th>
                    <td>
                        <input type="text" value="<?php echo esc_attr( $formatted_date ); ?>" class="regular-text ffc-input-readonly" readonly>
                        <p class="description"><?php _e( 'Original submission timestamp (read-only).', 'ffc' ); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th><label><?php _e( 'Status', 'ffc' ); ?></label></th>
                    <td>
                        <input type="text" value="<?php echo esc_attr( $sub_array['status'] ); ?>" class="regular-text ffc-input-readonly" readonly>
                        <p class="description"><?php _e( 'Submission status (publish, trash, etc).', 'ffc' ); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th><label><?php _e( 'Magic Link Token', 'ffc' ); ?></label></th>
                    <td>
                        <?php if ( ! empty( $magic_token ) ): ?>
                            <input type="text" value="<?php echo esc_attr( $magic_token ); ?>" class="regular-text ffc-input-readonly" readonly>
                            <p class="description">
                                <?php _e( 'Unique token for certificate access (read-only).', 'ffc' ); ?>
                                <?php echo FFC_Magic_Link_Helper::get_magic_link_html( $magic_token ); ?>
                            </p>
                        <?php else: ?>
                            <p class="description"><?php _e( 'Submission created before magic links', 'ffc' ); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                
                <?php if ( !empty( $sub_array['user_ip'] ) ): ?>
                <tr>
                    <th><label><?php _e( 'User IP', 'ffc' ); ?></label></th>
                    <td>
                        <input type="text" value="<?php echo esc_attr( $sub_array['user_ip'] ); ?>" class="regular-text ffc-input-readonly" readonly>
                        <?php if ( ! empty( $sub_array['user_ip_encrypted'] ) ): ?>
                            <p class="description">🔒 <?php _e( 'This IP is encrypted in the database.', 'ffc' ); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>

                <!-- SEÇÃO: QR CODE USAGE -->
                <tr>
                    <td colspan="2" style="padding: 0;">
                        <div style="margin: 20px 0; padding: 15px; background: #f0f6fc; border-left: 4px solid #0073aa; border-radius: 4px;">
                            <h3 style="margin: 0 0 10px 0;">
                                📱 <?php _e( 'QR Code Placeholder Usage', 'ffc' ); ?>
                            </h3>
                            <p style="margin: 0 0 10px 0;">
                                <?php _e( 'You can add dynamic QR Codes to your certificate template using these placeholders:', 'ffc' ); ?>
                            </p>
                            <table style="width: 100%; font-family: monospace; font-size: 12px; border-collapse: collapse;">
                                <tr>
                                    <td style="padding: 5px; background: #fff; border: 1px solid #ddd;">
                                        <code>{{qr_code}}</code>
                                    </td>
                                    <td style="padding: 5px;">
                                        <?php _e( 'Default QR Code (200x200px)', 'ffc' ); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 5px; background: #fff; border: 1px solid #ddd;">
                                        <code>{{qr_code:size=150}}</code>
                                    </td>
                                    <td style="padding: 5px;">
                                        <?php _e( 'Custom size (150x150px)', 'ffc' ); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 5px; background: #fff; border: 1px solid #ddd;">
                                        <code>{{qr_code:size=250:margin=0}}</code>
                                    </td>
                                    <td style="padding: 5px;">
                                        <?php _e( 'Custom size without margin', 'ffc' ); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 5px; background: #fff; border: 1px solid #ddd;">
                                        <code>{{qr_code:error=H}}</code>
                                    </td>
                                    <td style="padding: 5px;">
                                        <?php _e( 'High error correction (30%)', 'ffc' ); ?>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin: 10px 0 0 0; font-size: 12px; color: #666;">
                                💡 <?php _e( 'QR Codes automatically link to this certificate verification page. Configure defaults in Settings → QR Code.', 'ffc' ); ?>
                            </p>
                        </div>
                    </td>
                </tr>

                <!-- ✅ v2.10.0: SEÇÃO LGPD CONSENT STATUS -->
                <?php 
                $consent_given = isset( $sub_array['consent_given'] ) ? (int) $sub_array['consent_given'] : 0;
                $consent_date = isset( $sub_array['consent_date'] ) ? $sub_array['consent_date'] : '';
                $consent_ip = isset( $sub_array['consent_ip'] ) ? $sub_array['consent_ip'] : '';
                ?>
                <tr>
                    <td colspan="2" style="padding: 0;">
                        <div style="margin: 20px 0; padding: 15px; background: <?php echo $consent_given ? '#e7f5e7' : '#fff8e1'; ?>; border-left: 4px solid <?php echo $consent_given ? '#46a049' : '#ffa500'; ?>; border-radius: 4px;">
                            <h3 style="margin: 0 0 10px 0;">
                                <?php echo $consent_given ? '✅' : '⚠️'; ?> 
                                <?php _e( 'LGPD Consent Status', 'ffc' ); ?>
                            </h3>
                            
                            <?php if ( $consent_given ): ?>
                                <p style="margin: 0; color: #2d5c2e;">
                                    <strong><?php _e( 'Consent given:', 'ffc' ); ?></strong> 
                                    <?php _e( 'User explicitly agreed to data storage and privacy policy.', 'ffc' ); ?>
                                </p>
                                <?php if ( $consent_date ): ?>
                                    <p style="margin: 5px 0 0 0; font-size: 12px; color: #666;">
                                        📅 <?php printf( __( 'Date: %s', 'ffc' ), esc_html( $consent_date ) ); ?>
                                    </p>
                                <?php endif; ?>
                                
                                <?php if ( $consent_ip ): ?>
                                    <p style="margin: 5px 0 0 0; font-size: 12px; color: #666;">
                                        🌐 <?php printf( __( 'IP: %s', 'ffc' ), esc_html( $consent_ip ) ); ?>
                                    </p>
                                <?php endif; ?>
                                
                                <p style="margin: 10px 0 0 0; padding: 10px; background: rgba(255,255,255,0.7); border-radius: 3px; font-size: 12px;">
                                    🔒 <?php _e( 'Sensitive data (email, CPF/RF, IP) is encrypted in the database.', 'ffc' ); ?>
                                </p>
                            <?php else: ?>
                                <p style="margin: 0; color: #7a5c00;">
                                    <strong><?php _e( 'No consent recorded:', 'ffc' ); ?></strong> 
                                    <?php _e( 'This submission was created before LGPD consent feature (v2.10.0).', 'ffc' ); ?>
                                </p>
                                <p style="margin: 10px 0 0 0; font-size: 12px; color: #666;">
                                    ℹ️ <?php _e( 'Older submissions do not have explicit consent flag but may have been collected under privacy policy.', 'ffc' ); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                
                <!-- SEÇÃO: DADOS DO PARTICIPANTE -->
                <tr>
                    <td colspan="2" style="padding: 0;">
                        <h2 style="margin: 20px 0 10px 0; padding: 10px; background: #f0f0f1; border-left: 4px solid #0073aa;">
                            <?php _e( 'Participant Data', 'ffc' ); ?>
                        </h2>
                    </td>
                </tr>
                
                <!-- ✅ EMAIL (editável) -->
                <tr>
                    <th><label for="user_email"><?php _e( 'Email', 'ffc' ); ?> *</label></th>
                    <td>
                        <input type="email" name="user_email" id="user_email" value="<?php echo esc_attr($sub_array['email']); ?>" class="regular-text" required>
                        <?php if ( ! empty( $sub_array['email_encrypted'] ) ): ?>
                            <p class="description">🔒 <?php _e( 'This email is encrypted in the database.', 'ffc' ); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                
                <!-- ✅ CPF/RF (read-only se existir) -->
                <?php if ( !empty( $sub_array['cpf_rf'] ) ): ?>
                <tr>
                    <th><label><?php _e( 'CPF/RF', 'ffc' ); ?></label></th>
                    <td>
                        <input type="text" value="<?php echo esc_attr( $sub_array['cpf_rf'] ); ?>" class="regular-text ffc-input-readonly" readonly>
                        <?php if ( ! empty( $sub_array['cpf_rf_encrypted'] ) ): ?>
                            <p class="description">🔒 <?php _e( 'This CPF/RF is encrypted in the database.', 'ffc' ); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
                
                <!-- ✅ AUTH CODE (read-only se existir) -->
                <?php if ( !empty( $sub_array['auth_code'] ) ): ?>
                <tr>
                    <th><label><?php _e( 'Auth Code', 'ffc' ); ?></label></th>
                    <td>
                        <input type="text" value="<?php echo esc_attr( $sub_array['auth_code'] ); ?>" class="regular-text ffc-input-readonly" readonly>
                        <p class="description"><?php _e( 'Protected authentication code.', 'ffc' ); ?></p>
                    </td>
                </tr>
                <?php endif; ?>
                
                <!-- ✅ CAMPOS DINÂMICOS DO JSON -->
                <?php if(is_array($data)): foreach($data as $k => $v): 
                    // Pular campos de tracking antigos (agora estão em colunas)
                    if ($k === 'is_edited' || $k === 'edited_at') continue;
                    
                    $lbl = $k; 
                    if(is_array($fields)) { 
                        foreach($fields as $f) { 
                            if(isset($f['name']) && $f['name'] === $k) $lbl = $f['label']; 
                        } 
                    }
                    
                    $is_protected = in_array( $k, $protected_json_fields );
                    $field_class = $is_protected ? 'regular-text ffc-input-readonly' : 'regular-text';
                    $readonly_attr = $is_protected ? 'readonly' : ''; 
                    ?>
                    <tr>
                        <th><?php echo esc_html($lbl); ?></th>
                        <td>
                            <?php $display_value = is_array($v) ? implode(', ', $v) : $v; ?>
                            <input type="text" name="data[<?php echo esc_attr($k); ?>]" value="<?php echo esc_attr($display_value); ?>" class="<?php echo esc_attr($field_class); ?>" <?php echo $readonly_attr; ?>>
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
    
    <script>
    jQuery(document).ready(function($) {
        $('.ffc-copy-magic-link').on('click', function(e) {
            e.preventDefault();
            var url = $(this).data('url');
            var $btn = $(this);
            
            var $temp = $('<input>');
            $('body').append($temp);
            $temp.val(url).select();
            document.execCommand('copy');
            $temp.remove();
            
            var originalText = $btn.text();
            $btn.text('✅ <?php _e( 'Copied!', 'ffc' ); ?>').prop('disabled', true);
            
            setTimeout(function() {
                $btn.text(originalText).prop('disabled', false);
            }, 2000);
        });
    });
    </script>
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
            
            
            $this->submission_handler->update_submission($id, $new_email, $clean_data);
            
            wp_redirect(admin_url('edit.php?post_type=ffc_form&page=ffc-submissions&msg=updated')); 
            exit;
        }
    }

    public function handle_csv_export_request() {
        if ( isset( $_POST['ffc_action'] ) && $_POST['ffc_action'] === 'export_csv_smart' ) {
            $this->csv_exporter->handle_export_request();
        }
    }

    /**
     * Get PDF data for admin download
     * ✅ v2.9.15: Uses centralized PDF Generator (same as frontend)
     */
    public function ajax_admin_get_pdf_data() {
        try {
            error_log('[FFC Admin] PDF data request started');
            
            check_ajax_referer( 'ffc_admin_pdf_nonce', 'nonce' );
            
            $submission_id = isset($_POST['submission_id']) ? absint($_POST['submission_id']) : 0;
            error_log('[FFC Admin] Submission ID: ' . $submission_id);
            
            if ( ! $submission_id ) {
                error_log('[FFC Admin] Invalid submission ID');
                wp_send_json_error( array( 'message' => __( 'Invalid submission ID.', 'ffc' ) ) );
            }
            
            // Load required classes
            error_log('[FFC Admin] Loading PDF Generator class');
            if ( ! class_exists( 'FFC_PDF_Generator' ) ) {
                $pdf_gen_path = FFC_PLUGIN_DIR . 'includes/generators/class-ffc-pdf-generator.php';
                if ( ! file_exists( $pdf_gen_path ) ) {
                    error_log('[FFC Admin] ERROR: PDF Generator file not found at: ' . $pdf_gen_path);
                    wp_send_json_error( array( 'message' => 'PDF Generator class file not found' ) );
                }
                require_once $pdf_gen_path;
            }

            if ( ! class_exists( 'FFC_Submission_Handler' ) ) {
                require_once FFC_PLUGIN_DIR . 'includes/submissions/class-ffc-submission-handler.php';
            }

            if ( ! class_exists( 'FFC_Email_Handler' ) ) {
                require_once FFC_PLUGIN_DIR . 'includes/integrations/class-ffc-email-handler.php';
            }
            
            error_log('[FFC Admin] Classes loaded successfully');
            
            // Get handlers (use existing or create new)
            $submission_handler = $this->submission_handler ? $this->submission_handler : new FFC_Submission_Handler();
            $email_handler = $this->email_handler ? $this->email_handler : new FFC_Email_Handler();
            
            error_log('[FFC Admin] Handlers created');
            
            // ✅ Use centralized PDF Generator
            $pdf_generator = new FFC_PDF_Generator( $submission_handler, $email_handler );
            error_log('[FFC Admin] PDF Generator instantiated');
            
            $pdf_data = $pdf_generator->generate_pdf_data( $submission_id );
            error_log('[FFC Admin] PDF data generated, type: ' . gettype($pdf_data));
            
            if ( is_wp_error( $pdf_data ) ) {
                error_log('[FFC Admin] WP Error: ' . $pdf_data->get_error_message());
                wp_send_json_error( array( 
                    'message' => $pdf_data->get_error_message() 
                ) );
            }
            
            if ( ! is_array( $pdf_data ) ) {
                error_log('[FFC Admin] ERROR: PDF data is not array: ' . print_r($pdf_data, true));
                wp_send_json_error( array( 'message' => 'Invalid PDF data format' ) );
            }
            
            error_log('[FFC Admin] PDF data keys: ' . implode(', ', array_keys($pdf_data)));
            error_log('[FFC Admin] Filename: ' . (isset($pdf_data['filename']) ? $pdf_data['filename'] : 'NOT SET'));
            
            // ✅ Returns complete PDF data including filename with auth_code
            error_log('[FFC Admin] Sending success response');
            wp_send_json_success( $pdf_data );
            
        } catch ( Exception $e ) {
            error_log('[FFC Admin] EXCEPTION: ' . $e->getMessage());
            error_log('[FFC Admin] Stack trace: ' . $e->getTraceAsString());
            wp_send_json_error( array( 
                'message' => 'Server error: ' . $e->getMessage() 
            ) );
        } catch ( Error $e ) {
            error_log('[FFC Admin] FATAL ERROR: ' . $e->getMessage());
            error_log('[FFC Admin] Stack trace: ' . $e->getTraceAsString());
            wp_send_json_error( array( 
                'message' => 'Fatal error: ' . $e->getMessage() 
            ) );
        }
    }

    /**
     * Handle migration action (unified handler for all migrations)
     * 
     * @since 2.9.13
     */
    public function handle_migration_action() {
        if ( ! isset( $_GET['ffc_migration'] ) ) {
            return;
        }
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Insufficient permissions', 'ffc' ) );
        }
        
        $migration_key = sanitize_key( $_GET['ffc_migration'] );
        
        // Verify nonce
        check_admin_referer( 'ffc_migration_' . $migration_key );
        
        // Get migration info
        $migration = $this->migration_manager->get_migration( $migration_key );
        if ( ! $migration ) {
            wp_die( __( 'Invalid migration key', 'ffc' ) );
        }
        
        // Run migration
        $result = $this->migration_manager->run_migration( $migration_key );
        
        if ( is_wp_error( $result ) ) {
            $redirect_url = add_query_arg(
                array(
                    'post_type' => 'ffc_form',
                    'page' => 'ffc-submissions',
                    'msg' => 'migration_error',
                    'error_msg' => urlencode( $result->get_error_message() )
                ),
                admin_url( 'edit.php' )
            );
        } else {
            $migrated = isset( $result['migrated'] ) ? $result['migrated'] : 0;
            
            $redirect_url = add_query_arg(
                array(
                    'post_type' => 'ffc_form',
                    'page' => 'ffc-submissions',
                    'msg' => 'migration_success',
                    'migration_name' => urlencode( $migration['name'] ),
                    'migrated' => $migrated
                ),
                admin_url( 'edit.php' )
            );
        }
        
        wp_redirect( $redirect_url );
        exit;
    }

    /**
     * Configure TinyMCE to protect placeholders from being processed
     * 
     * This prevents TinyMCE from escaping characters inside placeholders.
     * For example: {{validation_url link:m>v}} stays as is, 
     * instead of being converted to {{validation_url link:m&gt;v}}
     * 
     * @since 2.9.3
     * @param array $init TinyMCE initialization settings
     * @return array Modified settings
     */
    public function configure_tinymce_placeholders( $init ) {
        // ⭐ DEBUG: Uncomment to verify this is being called
        // error_log('FFC: TinyMCE filter called!');
        // error_log('FFC: Init keys: ' . implode(', ', array_keys($init)));
        
        // ✅ STRATEGY 1: noneditable_regexp
        // Protect all content between {{ and }}
        // TinyMCE will NOT process the content inside
        $init['noneditable_regexp'] = '/{{[^}]+}}/g';
        
        // ✅ STRATEGY 2: noneditable_class
        // Mark placeholders with a class that TinyMCE won't edit
        $init['noneditable_class'] = 'ffc-placeholder';
        
        // ✅ STRATEGY 3: entity_encoding
        // Try to prevent entity encoding
        $init['entity_encoding'] = 'raw';
        
        // ✅ STRATEGY 4: valid_elements
        // Ensure our placeholders are considered valid
        if ( ! isset( $init['extended_valid_elements'] ) ) {
            $init['extended_valid_elements'] = '';
        }
        
        // ✅ STRATEGY 5: protect patterns
        // Additional protection for specific patterns
        if ( ! isset( $init['protect'] ) ) {
            $init['protect'] = array();
        }
        if ( is_array( $init['protect'] ) ) {
            $init['protect'][] = '/{{[^}]+}}/g';
        }
        
        // ✅ Visual styling (optional)
        // Uncomment to add custom CSS for placeholder highlighting
        // if ( ! isset( $init['content_css'] ) ) {
        //     $init['content_css'] = '';
        // } else {
        //     $init['content_css'] .= ',';
        // }
        // $init['content_css'] .= plugins_url( 'assets/css/ffc-editor-placeholders.css', FFC_PLUGIN_FILE );
        
        // ⭐ DEBUG: Uncomment to see final config
        // error_log('FFC: noneditable_regexp = ' . $init['noneditable_regexp']);
        // error_log('FFC: entity_encoding = ' . $init['entity_encoding']);
        
        return $init;
    }
}
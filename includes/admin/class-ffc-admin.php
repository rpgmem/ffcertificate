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
    private $assets_manager;     // ✅ v3.1.1: Assets Manager
    private $edit_page;          // ✅ v3.1.1: Submission Edit Page

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

        // ✅ v3.1.1: Initialize Assets Manager (extracted from FFC_Admin)
        require_once plugin_dir_path( __FILE__ ) . 'class-ffc-admin-assets-manager.php';
        $this->assets_manager = new FFC_Admin_Assets_Manager();
        $this->assets_manager->register();

        // ✅ v3.1.1: Initialize Submission Edit Page (extracted from FFC_Admin)
        require_once plugin_dir_path( __FILE__ ) . 'class-ffc-admin-submission-edit-page.php';
        $this->edit_page = new FFC_Admin_Submission_Edit_Page( $handler );

        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );

        // ✅ v2.9.3: Configure TinyMCE to protect placeholders
        // Priority 999 to run AFTER other plugins
        add_filter( 'tiny_mce_before_init', array( $this, 'configure_tinymce_placeholders' ), 999 );

        add_action( 'admin_init', array( $this, 'handle_submission_actions' ) );
        add_action( 'admin_init', array( $this, 'handle_csv_export_request' ) );
        add_action( 'admin_init', array( $this, 'handle_submission_edit_save' ) );
        add_action( 'admin_init', array( $this, 'handle_migration_action' ) );  // ✅ v2.9.13: Unified handler
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

    /**
     * Legacy admin assets method
     *
     * @deprecated 3.1.1 Asset management now handled by FFC_Admin_Assets_Manager
     * @param string $hook Hook suffix
     */
    public function admin_assets( $hook ) {
        // ✅ v3.1.1: This method is now managed by FFC_Admin_Assets_Manager
        // The Assets Manager is registered in the constructor and handles all asset loading.
        // This method is kept for backward compatibility in case it's called directly,
        // but the actual functionality has been extracted to improve code organization.
        //
        // See: class-ffc-admin-assets-manager.php
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
        // ✅ v3.1.1: Extracted to FFC_Admin_Submission_Edit_Page
        $submission_id = isset( $_GET['submission_id'] ) ? absint( $_GET['submission_id'] ) : 0;
        $this->edit_page->render( $submission_id );
    }

    public function handle_submission_edit_save() {
        // ✅ v3.1.1: Extracted to FFC_Admin_Submission_Edit_Page
        $this->edit_page->handle_save();
    }
    public function handle_csv_export_request() {
        if ( isset( $_POST['ffc_action'] ) && $_POST['ffc_action'] === 'export_csv_smart' ) {
            $this->csv_exporter->handle_export_request();
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

<?php
/**
 * FFC_Settings
 * 
 * Manages plugin settings with modular tab system
 * 
 * @package FFC
 * @since 1.0.0
 * @version 2.10.0 - Added Rate Limit tab
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Settings {
    
    private $submission_handler;
    private $tabs = array();
    
    public function __construct( FFC_Submission_Handler $handler ) {
        $this->submission_handler = $handler;
        
        // Load tabs
        $this->load_tabs();
        
        // Hooks
        add_action( 'admin_menu', array( $this, 'add_settings_page' ), 20 );
        add_action( 'admin_init', array( $this, 'handle_settings_submission' ) );
        add_action( 'admin_init', array( $this, 'handle_clear_qr_cache' ) );
        add_action( 'admin_init', array( $this, 'handle_migration_execution' ) );
        add_action( 'wp_ajax_ffc_preview_date_format', array( $this, 'ajax_preview_date_format' ) );
        add_action( 'admin_init', array( $this, 'handle_cache_actions'));
    }
    
    /**
     * Load all tab classes
     */
    private function load_tabs() {
        // Require abstract base class
        require_once FFC_PLUGIN_DIR . 'includes/settings/abstract-ffc-settings-tab.php';
        
        // Tab files to load
        $tab_files = array(
            'documentation' => 'class-ffc-tab-documentation.php',
            'general'       => 'class-ffc-tab-general.php',
            'smtp'          => 'class-ffc-tab-smtp.php',
            'qrcode'        => 'class-ffc-tab-qrcode.php',
            'rate_limit'    => 'class-ffc-tab-rate-limit.php', 
            'migrations'    => 'class-ffc-tab-migrations.php'
        );
        
        // Load each tab
        foreach ( $tab_files as $tab_id => $filename ) {
            $filepath = FFC_PLUGIN_DIR . 'includes/settings/' . $filename;
            
            if ( file_exists( $filepath ) ) {
                require_once $filepath;
                
                // Instantiate tab class
                $class_name = 'FFC_Tab_' . ucfirst( str_replace( '-', '_', $tab_id ) );
                
                if ( $tab_id === 'qrcode' ) {
                    $class_name = 'FFC_Tab_QRCode';
                } elseif ( $tab_id === 'smtp' ) {
                    $class_name = 'FFC_Tab_SMTP';
                } elseif ( $tab_id === 'rate_limit' ) {
                    $class_name = 'FFC_Tab_Rate_Limit';
                }
                
                if ( class_exists( $class_name ) ) {
                    $this->tabs[ $tab_id ] = new $class_name();
                }
            }
        }
        
        // Sort tabs by order
        uasort( $this->tabs, function( $a, $b ) {
            return $a->get_order() - $b->get_order();
        });
        
        // Allow plugins to add custom tabs
        $this->tabs = apply_filters( 'ffc_settings_tabs', $this->tabs );
    }
    
    public function add_settings_page() {
        add_submenu_page(
            'edit.php?post_type=ffc_form',
            __( 'Settings', 'ffc' ),
            __( 'Settings', 'ffc' ),
            'manage_options',
            'ffc-settings',
            array( $this, 'display_settings_page' )
        );
    }
    
    /**
     * Get default settings
     */
    public function get_default_settings() { 
        return array(
            'cleanup_days'           => 365,
            'smtp_mode'              => 'wp',
            'smtp_host'              => '',
            'smtp_port'              => 587,
            'smtp_user'              => '',
            'smtp_pass'              => '',
            'smtp_secure'            => 'tls',
            'smtp_from_email'        => '',
            'smtp_from_name'         => '',
            'qr_cache_enabled'       => 0,
            'qr_default_size'        => 200,
            'qr_default_margin'      => 2,
            'qr_default_error_level' => 'M',
            'date_format'            => 'F j, Y',
            'date_format_custom'     => '',
            'cache_enabled'          => 1,      // Default: ON
            'cache_expiration'       => 3600,   // 1 hour
            'cache_auto_warm'        => 0,      // Default: OFF
        );
    }
    
    /**
     * Get option value
     */
    public function get_option( $key ) { 
        $settings = get_option( 'ffc_settings', array() );
        $defaults = $this->get_default_settings();
        
        if ( isset( $settings[ $key ] ) ) {
            return $settings[ $key ];
        }
        
        if ( isset( $defaults[ $key ] ) ) {
            return $defaults[ $key ];
        }
        
        return '';
    }
    
    /**
     * Handle settings form submission
     */
    public function handle_settings_submission() {
        // Handle General/SMTP/QR Settings
        if ( isset( $_POST['ffc_settings_nonce'] ) && wp_verify_nonce( $_POST['ffc_settings_nonce'], 'ffc_settings_action' ) ) {
            $current = get_option( 'ffc_settings', array() );
            $new     = isset( $_POST['ffc_settings'] ) ? $_POST['ffc_settings'] : array();
            
            $clean = $current;
            
            // General Tab Fields
            if ( isset( $new['cleanup_days'] ) ) {
                $clean['cleanup_days'] = absint( $new['cleanup_days'] );
            }
            
            // SMTP Tab Fields
            if ( isset( $new['smtp_mode'] ) ) {
                $clean['smtp_mode'] = sanitize_key( $new['smtp_mode'] );
            }
            if ( isset( $new['smtp_host'] ) ) {
                $clean['smtp_host'] = sanitize_text_field( $new['smtp_host'] );
            }
            if ( isset( $new['smtp_port'] ) ) {
                $clean['smtp_port'] = absint( $new['smtp_port'] );
            }
            if ( isset( $new['smtp_user'] ) ) {
                $clean['smtp_user'] = sanitize_text_field( $new['smtp_user'] );
            }
            if ( isset( $new['smtp_pass'] ) ) {
                $clean['smtp_pass'] = sanitize_text_field( $new['smtp_pass'] );
            }
            if ( isset( $new['smtp_secure'] ) ) {
                $clean['smtp_secure'] = sanitize_key( $new['smtp_secure'] );
            }
            if ( isset( $new['smtp_from_email'] ) ) {
                $clean['smtp_from_email'] = sanitize_email( $new['smtp_from_email'] );
            }
            if ( isset( $new['smtp_from_name'] ) ) {
                $clean['smtp_from_name'] = sanitize_text_field( $new['smtp_from_name'] );
            }
            
            // QR Code Tab Fields
            if ( isset( $_POST['_ffc_tab'] ) && $_POST['_ffc_tab'] === 'qr_code' ) {
                $clean['qr_cache_enabled'] = isset( $new['qr_cache_enabled'] ) ? 1 : 0;
            }
            
            if ( isset( $new['qr_default_size'] ) ) {
                $clean['qr_default_size'] = absint( $new['qr_default_size'] );
            }
            if ( isset( $new['qr_default_margin'] ) ) {
                $clean['qr_default_margin'] = absint( $new['qr_default_margin'] );
            }
            if ( isset( $new['qr_default_error_level'] ) ) {
                $clean['qr_default_error_level'] = sanitize_text_field( $new['qr_default_error_level'] );
            }
            // Date Format Settings (v2.10.0)
            if ( isset( $new['date_format'] ) ) {
                $clean['date_format'] = sanitize_text_field( $new['date_format'] );
            }
            if ( isset( $new['date_format_custom'] ) ) {
                $clean['date_format_custom'] = sanitize_text_field( $new['date_format_custom'] );
            }
            
            update_option( 'ffc_settings', $clean );
            add_settings_error( 'ffc_settings', 'ffc_settings_updated', __( 'Settings saved.', 'ffc' ), 'updated' );
        }
        
        // Handle Global Data Deletion (Danger Zone)
        if ( isset( $_POST['ffc_delete_all_data'] ) && check_admin_referer( 'ffc_delete_all_data', 'ffc_critical_nonce' ) ) {
            $target = isset($_POST['delete_target']) ? $_POST['delete_target'] : 'all';
            $reset_counter = isset($_POST['reset_counter']) && $_POST['reset_counter'] == '1';
            
            $result = $this->submission_handler->delete_all_submissions( 
                $target === 'all' ? null : absint($target),
                $reset_counter
            );
            
            if ($result !== false) {
                $message = $reset_counter 
                    ? __('Data deleted and counter reset successfully.', 'ffc')
                    : __('Data deleted successfully.', 'ffc');
                add_settings_error('ffc_settings', 'ffc_data_deleted', $message, 'updated');
            } else {
                add_settings_error('ffc_settings', 'ffc_data_delete_failed', __('Failed to delete data.', 'ffc'), 'error');
            }
        }

    }
    
    /**
     * Handle QR Code cache clearing
     */
    public function handle_clear_qr_cache() {
        if ( ! isset( $_GET['ffc_clear_qr_cache'] ) || ! isset( $_GET['_wpnonce'] ) ) {
            return;
        }
        
        if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'ffc_clear_qr_cache' ) ) {
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_submissions';
        
        $cleared = $wpdb->query( "UPDATE {$table_name} SET qr_code_cache = NULL WHERE qr_code_cache IS NOT NULL" );
        
        wp_safe_redirect( add_query_arg( array(
            'post_type' => 'ffc_form',
            'page' => 'ffc-settings',
            'tab' => 'qr_code',
            'msg' => 'qr_cache_cleared',
            'cleared' => $cleared
        ), admin_url( 'edit.php' ) ) );
        exit;
    }
    
    /**
     * Display settings page with modular tabs
     */
    public function display_settings_page() {
        // Handle messages
        if ( isset( $_GET['msg'] ) ) {
            $msg = $_GET['msg'];
            
            if ( $msg === 'qr_cache_cleared' ) {
                $cleared = isset( $_GET['cleared'] ) ? intval( $_GET['cleared'] ) : 0;
                echo '<div class="notice notice-success is-dismissible">';
                echo '<p>' . sprintf( __( '%d QR Code(s) cleared from cache successfully.', 'ffc' ), $cleared ) . '</p>';
                echo '</div>';
            }
        }
        
        // Get active tab (default to first tab)
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '';
        
        // If no tab specified, use first tab
        if ( empty( $active_tab ) && ! empty( $this->tabs ) ) {
            reset( $this->tabs );
            $first_tab = current( $this->tabs );
            $active_tab = $first_tab->get_id();
        }

        if (isset($_GET['msg'])) {
            $msg = $_GET['msg'];
            
            if ($msg === 'cache_warmed') {
                $count = isset($_GET['count']) ? intval($_GET['count']) : 0;
                echo '<div class="notice notice-success is-dismissible">';
                echo '<p>✅ Cache aquecido! ' . $count . ' formulário(s) pré-carregado(s).</p>';
                echo '</div>';
            }
            
            if ($msg === 'cache_cleared') {
                echo '<div class="notice notice-success is-dismissible">';
                echo '<p>✅ Cache limpo com sucesso!</p>';
                echo '</div>';
            }
        }
        
        ?>
        <div class="wrap ffc-settings-wrap">
            <h1><?php esc_html_e( 'Certificate Settings', 'ffc' ); ?></h1>
            <?php settings_errors( 'ffc_settings' ); ?>
            
            <?php
            // Display migration messages
            if ( isset( $_GET['migration_success'] ) ) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( urldecode( $_GET['migration_success'] ) ) . '</p></div>';
            }
            if ( isset( $_GET['migration_error'] ) ) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( urldecode( $_GET['migration_error'] ) ) . '</p></div>';
            }
            ?>
            
            <h2 class="nav-tab-wrapper">
                <?php foreach ( $this->tabs as $tab_id => $tab_obj ) : ?>
                    <a href="?post_type=ffc_form&page=ffc-settings&tab=<?php echo esc_attr( $tab_id ); ?>" 
                       class="nav-tab <?php echo $active_tab === $tab_id ? 'nav-tab-active' : ''; ?>">
                        <?php echo $tab_obj->get_icon(); ?> 
                        <?php echo esc_html( $tab_obj->get_title() ); ?>
                    </a>
                <?php endforeach; ?>
            </h2>
            
            <div class="ffc-tab-content">
                <?php
                if ( isset( $this->tabs[ $active_tab ] ) ) {
                    $this->tabs[ $active_tab ]->render();
                } else {
                    // Fallback: render first tab
                    if ( ! empty( $this->tabs ) ) {
                        reset( $this->tabs );
                        $first_tab = current( $this->tabs );
                        $first_tab->render();
                    }
                }
                ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Handle migration execution from settings page
     */
    public function handle_migration_execution() {
        if ( ! isset( $_GET['ffc_run_migration'] ) ) {
            return;
        }
        
        $migration_key = sanitize_key( $_GET['ffc_run_migration'] );
        
        // Verify nonce
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'ffc_migration_' . $migration_key ) ) {
            wp_die( __( 'Security check failed.', 'ffc' ) );
        }
        
        // Load Migration Manager
        require_once FFC_PLUGIN_DIR . 'includes/class-ffc-migration-manager.php';
        $migration_manager = new FFC_Migration_Manager();
        
        // Run migration
        $result = $migration_manager->run_migration( $migration_key );
        
        // Prepare redirect URL
        $redirect_url = add_query_arg(
            array(
                'post_type' => 'ffc_form',
                'page' => 'ffc-settings',
                'tab' => 'migrations'
            ),
            admin_url( 'edit.php' )
        );
        
        // Add result message
        if ( is_wp_error( $result ) ) {
            $redirect_url = add_query_arg( 'migration_error', urlencode( $result->get_error_message() ), $redirect_url );
        } else {
            $message = sprintf(
                __( 'Migration executed: %d records processed.', 'ffc' ),
                isset( $result['processed'] ) ? $result['processed'] : 0
            );
            $redirect_url = add_query_arg( 'migration_success', urlencode( $message ), $redirect_url );
        }
        
        wp_redirect( $redirect_url );
        exit;
    }

    /**
     * AJAX handler for date format preview
     * 
     * @since 2.10.0
     */
    public function ajax_preview_date_format() {
        check_ajax_referer( 'ffc_preview_date', 'nonce' );
        
        $format = isset( $_POST['format'] ) ? sanitize_text_field( $_POST['format'] ) : 'F j, Y';
        $custom_format = isset( $_POST['custom_format'] ) ? sanitize_text_field( $_POST['custom_format'] ) : '';
        
        // Sample date for preview
        $sample_date = '2026-01-04 15:30:45';
        
        // Use custom format if selected
        if ( $format === 'custom' && ! empty( $custom_format ) ) {
            $format = $custom_format;
        }
        
        try {
            $formatted = date_i18n( $format, strtotime( $sample_date ) );
            wp_send_json_success( array( 'formatted' => $formatted ) );
        } catch ( Exception $e ) {
            wp_send_json_error( array( 'message' => __( 'Invalid date format', 'ffc' ) ) );
        }
    }

    public function handle_cache_actions() {
    // Warm Cache
    if (isset($_GET['action']) && $_GET['action'] === 'warm_cache') {
        check_admin_referer('ffc_warm_cache');
        
        if (!class_exists('FFC_Form_Cache')) {
            require_once FFC_PLUGIN_DIR . 'includes/class-ffc-form-cache.php';
        }
        
        $warmed = FFC_Form_Cache::warm_all_forms();
        
        wp_redirect(add_query_arg(array(
            'post_type' => 'ffc_form',
            'page' => 'ffc-settings',
            'tab' => 'general',
            'msg' => 'cache_warmed',
            'count' => $warmed
        ), admin_url('edit.php')));
        exit;
    }
    
    // Clear Cache
    if (isset($_GET['action']) && $_GET['action'] === 'clear_cache') {
        check_admin_referer('ffc_clear_cache');
        
        if (!class_exists('FFC_Form_Cache')) {
            require_once FFC_PLUGIN_DIR . 'includes/class-ffc-form-cache.php';
        }
        
        FFC_Form_Cache::clear_all_cache();
        
        wp_redirect(add_query_arg(array(
            'post_type' => 'ffc_form',
            'page' => 'ffc-settings',
            'tab' => 'general',
            'msg' => 'cache_cleared'
        ), admin_url('edit.php')));
        exit;
    }
}
}
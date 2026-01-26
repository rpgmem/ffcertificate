<?php
declare(strict_types=1);

/**
 * Settings
 *
 * Manages plugin settings with modular tab system
 * Acts as coordinator, delegating save operations to SettingsSaveHandler
 *
 * Responsibilities:
 * - Load and manage settings tabs
 * - Render settings page UI
 * - Delegate saving to Save Handler (v3.1.1)
 * - Handle cache actions and QR cache clearing
 * - Handle migration execution
 * - AJAX handlers
 *
 * @package FFC
 * @since 1.0.0
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Settings {

    private $submission_handler;
    private $tabs = array();
    private $save_handler;  // ✅ v3.1.1: Save Handler

    public function __construct( object $handler ) {
        $this->submission_handler = $handler;

        // ✅ Autoloader handles class loading
        $this->save_handler = new \FreeFormCertificate\Admin\SettingsSaveHandler( $handler );

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
     *
     * @since 4.0.0 Uses autoloader and namespaces (Hotfix 9)
     */
    private function load_tabs(): void {
        // Autoloader handles class loading - no require_once needed

        // Tab classes with proper namespaces
        $tab_classes = array(
            'documentation' => '\\FreeFormCertificate\\Settings\\Tabs\\TabDocumentation',
            'general'       => '\\FreeFormCertificate\\Settings\\Tabs\\TabGeneral',
            'smtp'          => '\\FreeFormCertificate\\Settings\\Tabs\\TabSMTP',
            'qrcode'        => '\\FreeFormCertificate\\Settings\\Tabs\\TabQRCode',
            'rate_limit'    => '\\FreeFormCertificate\\Settings\\Tabs\\TabRateLimit',
            'geolocation'   => '\\FreeFormCertificate\\Settings\\Tabs\\TabGeolocation',
            'user_access'   => '\\FreeFormCertificate\\Settings\\Tabs\\TabUserAccess',
            'migrations'    => '\\FreeFormCertificate\\Settings\\Tabs\\TabMigrations',
        );

        // Instantiate each tab
        foreach ( $tab_classes as $tab_id => $class_name ) {
            if ( class_exists( $class_name ) ) {
                $this->tabs[ $tab_id ] = new $class_name();
            }
        }

        // Sort tabs by order
        uasort( $this->tabs, function( $a, $b ) {
            return $a->get_order() - $b->get_order();
        });

        // Allow plugins to add custom tabs
        $this->tabs = apply_filters( 'ffc_settings_tabs', $this->tabs );
    }
    
    public function add_settings_page(): void {
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
    public function get_default_settings(): array { 
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
     * 
     * @param string $key Option key
     * @return mixed Option value (string|int|array|bool|'')
     */
    public function get_option( string $key ) { 
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
     *
     * @deprecated 3.1.1 Settings saving now handled by FFC_Settings_Save_Handler
     */
    public function handle_settings_submission(): void {
        // ✅ v3.1.1: All settings saving logic extracted to FFC_Settings_Save_Handler
        // This maintains backward compatibility while delegating to specialized handler
        $this->save_handler->handle_all_submissions();
    }
    
    /**
     * Handle QR Code cache clearing
     */
    public function handle_clear_qr_cache(): void {
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
    public function display_settings_page(): void {
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
                echo '<p>' . sprintf(
                    /* translators: %d: number of forms pre-loaded */
                    __( '✅ Cache warmed! %d form(s) pre-loaded.', 'ffc' ),
                    $count
                ) . '</p>';
                echo '</div>';
            }

            if ($msg === 'cache_cleared') {
                echo '<div class="notice notice-success is-dismissible">';
                echo '<p>' . __( '✅ Cache cleared successfully!', 'ffc' ) . '</p>';
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
    public function handle_migration_execution(): void {
        if ( ! isset( $_GET['ffc_run_migration'] ) ) {
            return;
        }
        
        $migration_key = sanitize_key( $_GET['ffc_run_migration'] );
        
        // Verify nonce
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'ffc_migration_' . $migration_key ) ) {
            wp_die( __( 'Security check failed.', 'ffc' ) );
        }

        // Autoloader handles class loading
        $migration_manager = new \FreeFormCertificate\Migrations\MigrationManager();
        
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
    public function ajax_preview_date_format(): void {
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

    public function handle_cache_actions(): void {
        // Warm Cache
        if (isset($_GET['action']) && $_GET['action'] === 'warm_cache') {
            check_admin_referer('ffc_warm_cache');

            // Autoloader handles class loading
            $warmed = \FreeFormCertificate\Submissions\FormCache::warm_all_forms();
            
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

            // Autoloader handles class loading
            \FreeFormCertificate\Submissions\FormCache::clear_all_cache();
            
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

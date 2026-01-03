<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles plugin settings, documentation tab, and data maintenance.
 * 
 * @since 2.9.0 Added QR Code settings
 */
class FFC_Settings {

    private $submission_handler;

    /**
     * Initialize the settings class.
     */
    public function __construct( FFC_Submission_Handler $handler ) {
        $this->submission_handler = $handler;
        add_action( 'admin_init', array( $this, 'handle_settings_submission' ) );
        add_action( 'admin_init', array( $this, 'handle_clear_qr_cache' ) );
        add_action( 'admin_init', array( $this, 'handle_migration_execution' ) );
    }

    /**
     * Define default plugin settings.
     */
    public function get_default_settings() { 
        return array( 
            'cleanup_days'           => 30, 
            'smtp_mode'              => 'wp', 
            'smtp_port'              => 587, 
            'smtp_secure'            => 'tls',
            'smtp_host'              => '',
            'smtp_user'              => '',
            'smtp_pass'              => '',
            'smtp_from_email'        => '',
            'smtp_from_name'         => '',
            'qr_cache_enabled'       => false,
            'qr_default_size'        => 200,
            'qr_default_margin'      => 2,
            'qr_default_error_level' => 'M'
        ); 
    }
    
    /**
     * Retrieve a specific option from the settings array.
     */
    public function get_option( $key ) { 
        $defaults = $this->get_default_settings();
        $saved_settings = get_option( 'ffc_settings', array() ); 
        
        if ( ! is_array( $saved_settings ) || empty( $saved_settings ) ) {
            return isset( $defaults[$key] ) ? $defaults[$key] : '';
        }

        return isset( $saved_settings[$key] ) ? $saved_settings[$key] : (isset( $defaults[$key] ) ? $defaults[$key] : '');
    }

    /**
     * Process settings form submissions and data deletion requests.
     * 
     * v2.9.3: Fixed to preserve settings from other tabs
     */
    public function handle_settings_submission() {
        // Handle General/SMTP/QR Settings
        if ( isset( $_POST['ffc_settings_nonce'] ) && wp_verify_nonce( $_POST['ffc_settings_nonce'], 'ffc_settings_action' ) ) {
            // ✅ v2.9.3: Get current settings to preserve
            $current = get_option( 'ffc_settings', array() );
            $new     = isset( $_POST['ffc_settings'] ) ? $_POST['ffc_settings'] : array();
            
            // ✅ v2.9.3: Only update fields that are present in the POST
            // This preserves settings from other tabs
            $clean = $current; // Start with existing settings
            
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
            // Checkbox: needs special handling (absent = false)
            if ( isset( $_POST['_ffc_tab'] ) && $_POST['_ffc_tab'] === 'qr_code' ) {
                // Only update checkbox if we're on QR tab
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
            
            update_option( 'ffc_settings', $clean );
            add_settings_error( 'ffc_settings', 'ffc_settings_updated', __( 'Settings saved.', 'ffc' ), 'updated' );
        }
        
        // Handle Global Data Deletion (Danger Zone)
        if ( isset( $_POST['ffc_delete_all_data'] ) && check_admin_referer( 'ffc_delete_all_data', 'ffc_critical_nonce' ) ) {
            $target = isset($_POST['delete_target']) ? $_POST['delete_target'] : 'all';
            
            $this->submission_handler->delete_all_submissions( $target === 'all' ? null : absint($target) );
            
            add_settings_error( 'ffc_settings', 'ffc_data_deleted', __( 'Data deleted successfully.', 'ffc' ), 'updated' );
        }
    }
    
    /**
     * Handle QR Code cache clearing
     * 
     * @since 2.9.0
     */
    public function handle_clear_qr_cache() {
        if ( ! isset( $_GET['ffc_clear_qr_cache'] ) ) {
            return;
        }
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Insufficient permissions', 'ffc' ) );
        }
        
        check_admin_referer( 'ffc_clear_qr_cache' );
        
        if ( ! class_exists( 'FFC_QRCode_Generator' ) ) {
            require_once FFC_PLUGIN_DIR . 'includes/class-ffc-qrcode-generator.php';
        }
        
        $qr_generator = new FFC_QRCode_Generator();
        $cleared = $qr_generator->clear_cache();
        
        $redirect_url = add_query_arg(
            array(
                'post_type' => 'ffc_form',
                'page' => 'ffc-settings',
                'tab' => 'qr_code',
                'msg' => 'qr_cache_cleared',
                'cleared' => $cleared
            ),
            admin_url( 'edit.php' )
        );
        
        wp_redirect( $redirect_url );
        exit;
    }
    
    /**
     * Render the settings page with tabs.
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
        
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'help'; 
        $forms = get_posts( array('post_type'=>'ffc_form', 'posts_per_page'=>-1) );
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
                <a href="?post_type=ffc_form&page=ffc-settings&tab=help" class="nav-tab <?php echo $active_tab == 'help' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Documentation', 'ffc' ); ?></a>
                <a href="?post_type=ffc_form&page=ffc-settings&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'General', 'ffc' ); ?></a>
                <a href="?post_type=ffc_form&page=ffc-settings&tab=smtp" class="nav-tab <?php echo $active_tab == 'smtp' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'SMTP', 'ffc' ); ?></a>
                <a href="?post_type=ffc_form&page=ffc-settings&tab=qr_code" class="nav-tab <?php echo $active_tab == 'qr_code' ? 'nav-tab-active' : ''; ?>">📱 <?php esc_html_e( 'QR Code', 'ffc' ); ?></a>
                <a href="?post_type=ffc_form&page=ffc-settings&tab=migrations" class="nav-tab <?php echo $active_tab == 'migrations' ? 'nav-tab-active' : ''; ?>">⚙️ <?php esc_html_e( 'Data Migrations', 'ffc' ); ?></a>
            </h2>
            
            <div class="ffc-tab-content">
                <?php if ( $active_tab == 'help' ) : ?>
                    <?php $this->render_help_tab(); ?>
                    
                <?php elseif ( $active_tab == 'general' ) : ?>
                    <?php $this->render_general_tab( $forms ); ?>
                    
                <?php elseif ( $active_tab == 'smtp' ) : ?>
                    <?php $this->render_smtp_tab(); ?>
                    
                <?php elseif ( $active_tab == 'qr_code' ) : ?>
                    <?php $this->render_qr_code_tab(); ?>
                
                <?php elseif ( $active_tab == 'migrations' ) : ?>
                    <?php $this->render_migrations_tab(); ?>
                    
                    
                <?php endif; ?>
            </div>
        </div>
        <?php
    }


    /**
     * Render the Help/Documentation tab.
     * 
     * v2.9.16: Extracted to template file for better organization
     */
    private function render_help_tab() {
        include FFC_PLUGIN_DIR . 'includes/settings-tabs/tab-documentation.php';
    }
    /**
     * Render the General settings tab.
     * 
     * v2.9.3: Added hidden field to identify tab
     */
    private function render_general_tab( $forms ) {
        ?>
        <form method="post">
            <?php wp_nonce_field( 'ffc_settings_action', 'ffc_settings_nonce' ); ?>
            <!-- ✅ v2.9.3: Identify which tab is being saved -->
            <input type="hidden" name="_ffc_tab" value="general">
            
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Auto-delete (days)', 'ffc' ); ?></th>
                    <td>
                        <input type="number" name="ffc_settings[cleanup_days]" value="<?php echo esc_attr( $this->get_option( 'cleanup_days' ) ); ?>">
                        <p class="description"><?php esc_html_e( 'Files removed after X days. Set to 0 to disable.', 'ffc' ); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        
        <div class="ffc-danger-zone">
            <h2><?php esc_html_e( 'Danger Zone', 'ffc' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Warning: These actions cannot be undone.', 'ffc' ); ?></p>
            <form method="post" id="ffc-danger-zone-form">
                <?php wp_nonce_field( 'ffc_delete_all_data', 'ffc_critical_nonce' ); ?>
                <input type="hidden" name="ffc_delete_all_data" value="1">
                <div class="ffc-admin-flex-row">
                    <select name="delete_target" id="ffc_delete_target" class="ffc-danger-select">
                        <option value="all"><?php esc_html_e( 'Delete All Submissions', 'ffc' ); ?></option>
                        <?php foreach ( $forms as $f ) : ?>
                            <option value="<?php echo esc_attr( $f->ID ); ?>"><?php echo esc_html( $f->post_title ); ?></option>
                        <?php endforeach; ?>
                    </select> 
                    <button type="submit" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Are you sure? This action cannot be undone.', 'ffc' ) ); ?>');">
                        <?php esc_html_e( 'Clear Data', 'ffc' ); ?>
                    </button>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Render the SMTP settings tab.
     * 
     * v2.9.3: Added hidden field to identify tab
     */
    private function render_smtp_tab() {
        ?>
        <form method="post">
            <?php wp_nonce_field( 'ffc_settings_action', 'ffc_settings_nonce' ); ?>
            <!-- ✅ v2.9.3: Identify which tab is being saved -->
            <input type="hidden" name="_ffc_tab" value="smtp">
            
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Mode', 'ffc' ); ?></th>
                    <td>
                        <label><input type="radio" name="ffc_settings[smtp_mode]" value="wp" <?php checked( 'wp', $this->get_option( 'smtp_mode' ) ); ?>> <?php esc_html_e( 'WP Default (PHPMail)', 'ffc' ); ?></label><br>
                        <label><input type="radio" name="ffc_settings[smtp_mode]" value="custom" <?php checked( 'custom', $this->get_option( 'smtp_mode' ) ); ?>> <?php esc_html_e( 'Custom SMTP', 'ffc' ); ?></label>
                    </td>
                </tr>
                <tbody id="smtp-options" class="<?php echo ( $this->get_option( 'smtp_mode' ) === 'custom' ) ? '' : 'ffc-hidden'; ?>">
                    <tr>
                        <th><?php esc_html_e( 'Host', 'ffc' ); ?></th>
                        <td><input type="text" name="ffc_settings[smtp_host]" value="<?php echo esc_attr( $this->get_option( 'smtp_host' ) ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Port', 'ffc' ); ?></th>
                        <td><input type="number" name="ffc_settings[smtp_port]" value="<?php echo esc_attr( $this->get_option( 'smtp_port' ) ); ?>" class="small-text"></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'User', 'ffc' ); ?></th>
                        <td><input type="text" name="ffc_settings[smtp_user]" value="<?php echo esc_attr( $this->get_option( 'smtp_user' ) ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Password', 'ffc' ); ?></th>
                        <td><input type="password" name="ffc_settings[smtp_pass]" value="<?php echo esc_attr( $this->get_option( 'smtp_pass' ) ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Encryption', 'ffc' ); ?></th>
                        <td>
                            <select name="ffc_settings[smtp_secure]">
                                <option value="tls" <?php selected( 'tls', $this->get_option( 'smtp_secure' ) ); ?>>TLS</option>
                                <option value="ssl" <?php selected( 'ssl', $this->get_option( 'smtp_secure' ) ); ?>>SSL</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'From Email', 'ffc' ); ?></th>
                        <td><input type="email" name="ffc_settings[smtp_from_email]" value="<?php echo esc_attr( $this->get_option( 'smtp_from_email' ) ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'From Name', 'ffc' ); ?></th>
                        <td><input type="text" name="ffc_settings[smtp_from_name]" value="<?php echo esc_attr( $this->get_option( 'smtp_from_name' ) ); ?>" class="regular-text"></td>
                    </tr>
                </tbody>
            </table>
            <?php submit_button(); ?>
        </form>
        
        <script>
        jQuery(document).ready(function($) {
            $('input[name="ffc_settings[smtp_mode]"]').on('change', function() {
                if ($(this).val() === 'custom') {
                    $('#smtp-options').removeClass('ffc-hidden');
                } else {
                    $('#smtp-options').addClass('ffc-hidden');
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Render the QR Code settings tab
     * 
     * @since 2.9.0
     * v2.9.3: Added hidden field to identify tab
     */
    private function render_qr_code_tab() {
        ?>
        <form method="post">
            <?php wp_nonce_field( 'ffc_settings_action', 'ffc_settings_nonce' ); ?>
            <!-- ✅ v2.9.3: Identify which tab is being saved -->
            <input type="hidden" name="_ffc_tab" value="qr_code">
            
            <h2>📱 <?php esc_html_e( 'QR Code Generation Settings', 'ffc' ); ?></h2>
            
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Enable QR Code Cache', 'ffc' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[qr_cache_enabled]" value="1" <?php checked( 1, $this->get_option( 'qr_cache_enabled' ) ); ?>>
                            <?php esc_html_e( 'Store generated QR Codes in database', 'ffc' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'Improves performance by caching QR Codes. Increases database size (~4KB per submission).', 'ffc' ); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th><?php esc_html_e( 'Default QR Code Size', 'ffc' ); ?></th>
                    <td>
                        <input type="number" name="ffc_settings[qr_default_size]" value="<?php echo esc_attr( $this->get_option( 'qr_default_size' ) ); ?>" min="100" max="500" step="10" class="small-text"> px
                        <p class="description">
                            <?php esc_html_e( 'Default size when {{qr_code}} placeholder is used without size parameter. Range: 100-500px.', 'ffc' ); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th><?php esc_html_e( 'Default QR Code Margin', 'ffc' ); ?></th>
                    <td>
                        <input type="number" name="ffc_settings[qr_default_margin]" value="<?php echo esc_attr( $this->get_option( 'qr_default_margin' ) ); ?>" min="0" max="10" step="1" class="small-text">
                        <p class="description">
                            <?php esc_html_e( 'White space around QR Code in modules. 0 = no margin, higher values = more white space.', 'ffc' ); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th><?php esc_html_e( 'Default Error Correction Level', 'ffc' ); ?></th>
                    <td>
                        <select name="ffc_settings[qr_default_error_level]">
                            <option value="L" <?php selected( 'L', $this->get_option( 'qr_default_error_level' ) ); ?>><?php esc_html_e( 'L - Low (7% correction)', 'ffc' ); ?></option>
                            <option value="M" <?php selected( 'M', $this->get_option( 'qr_default_error_level' ) ); ?>><?php esc_html_e( 'M - Medium (15% correction) - Recommended', 'ffc' ); ?></option>
                            <option value="Q" <?php selected( 'Q', $this->get_option( 'qr_default_error_level' ) ); ?>><?php esc_html_e( 'Q - Quartile (25% correction)', 'ffc' ); ?></option>
                            <option value="H" <?php selected( 'H', $this->get_option( 'qr_default_error_level' ) ); ?>><?php esc_html_e( 'H - High (30% correction)', 'ffc' ); ?></option>
                        </select>
                        <p class="description">
                            <?php esc_html_e( 'Higher levels allow more damage to QR Code but create denser patterns.', 'ffc' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
            
            <?php submit_button(); ?>
        </form>
        
        <hr>
        
        <h3><?php esc_html_e( 'Cache Statistics', 'ffc' ); ?></h3>
        <?php $this->render_qr_cache_stats(); ?>
        
        <hr>
        
        <h3><?php esc_html_e( 'Maintenance', 'ffc' ); ?></h3>
        <?php $this->render_qr_clear_cache_button(); ?>
        
        <?php
    }


    /**
     * Render the Data Migrations tab.
     * 
     * v2.9.16: New tab for database migrations management
     */
    private function render_migrations_tab() {
        include FFC_PLUGIN_DIR . 'includes/settings-tabs/tab-migrations.php';
    }
    /**
     * Render QR Code cache statistics
     * 
     * @since 2.9.0
     */
    public function render_qr_cache_stats() {
        if ( ! class_exists( 'FFC_QRCode_Generator' ) ) {
            require_once FFC_PLUGIN_DIR . 'includes/class-ffc-qrcode-generator.php';
        }
        
        $qr_generator = new FFC_QRCode_Generator();
        $stats = $qr_generator->get_cache_stats();
        
        ?>
        <div class="ffc-qr-stats" style="background: #f0f0f1; padding: 15px; border-radius: 4px; border-left: 4px solid #2271b1; max-width: 600px;">
            <table style="width: 100%;">
                <tr>
                    <td style="padding: 8px 0; width: 50%;"><strong><?php _e( 'Cache Status:', 'ffc' ); ?></strong></td>
                    <td style="padding: 8px 0;">
                        <?php if ( $stats['enabled'] ): ?>
                            <span style="color: #00a32a; font-weight: 600;">✓ <?php _e( 'Enabled', 'ffc' ); ?></span>
                        <?php else: ?>
                            <span style="color: #d63638; font-weight: 600;">✗ <?php _e( 'Disabled', 'ffc' ); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr style="background: rgba(255,255,255,0.5);">
                <td style="padding: 8px 0;"><strong><?php _e( 'Total Submissions:', 'ffc' ); ?></strong></td>
                <td style="padding: 8px 0;"><?php echo number_format_i18n( $stats['total_submissions'] ); ?></td>
                </tr>
                <tr>
                <td style="padding: 8px 0;"><strong><?php _e( 'Cached QR Codes:', 'ffc' ); ?></strong></td>
                <td style="padding: 8px 0;"><?php echo number_format_i18n( $stats['cached_qr_codes'] ); ?></td>
                </tr>
                <tr style="background: rgba(255,255,255,0.5);">
                <td style="padding: 8px 0;"><strong><?php _e( 'Estimated Cache Size:', 'ffc' ); ?></strong></td>
                <td style="padding: 8px 0;"><?php echo $stats['cache_size_mb']; ?> MB</td>
                </tr>
                </table>
        </div>
                <p class="description" style="margin-top: 10px;">
                <?php _e( 'Cache stores generated QR Codes to improve performance. Enable "QR Code Cache" above to start caching.', 'ffc' ); ?>
                </p>
        <?php
    }
    
    /**
     * Render clear cache button
     * 
     * @since 2.9.0
     */
    public function render_qr_clear_cache_button() {
    $clear_url = wp_nonce_url(
        add_query_arg( array(
            'post_type' => 'ffc_form',
            'page' => 'ffc-settings',
            'tab' => 'qr_code',
            'ffc_clear_qr_cache' => '1'
        ), admin_url( 'edit.php' ) ),
        'ffc_clear_qr_cache'
    );
    
    ?>
    <a href="<?php echo esc_url( $clear_url ); ?>" 
       class="button button-secondary" 
       onclick="return confirm('<?php echo esc_js( __( 'Clear all cached QR Codes? They will be regenerated on next use.', 'ffc' ) ); ?>')">
        🗑️ <?php _e( 'Clear All QR Code Cache', 'ffc' ); ?>
    </a>
    <p class="description" style="margin-top: 10px;">
        <?php _e( 'Use this if QR Codes are outdated or to free database space. QR Codes will be regenerated automatically when needed.', 'ffc' ); ?>
    </p>
    <?php
    }

    /**
     * Handle migration execution from settings page
     * 
     * v2.9.16: Execute migrations when requested
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
}
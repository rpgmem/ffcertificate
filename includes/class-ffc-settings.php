<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles plugin settings, documentation tab, and data maintenance.
 */
class FFC_Settings {

    private $submission_handler;

    /**
     * Initialize the settings class.
     */
    public function __construct( FFC_Submission_Handler $handler ) {
        $this->submission_handler = $handler;
        add_action( 'admin_init', array( $this, 'handle_settings_submission' ) );
    }

    /**
     * Define default plugin settings.
     */
    public function get_default_settings() { 
        return array( 
            'cleanup_days'    => 30, 
            'smtp_mode'       => 'wp', 
            'smtp_port'       => 587, 
            'smtp_secure'     => 'tls',
            'smtp_host'       => '',
            'smtp_user'       => '',
            'smtp_pass'       => '',
            'smtp_from_email' => '',
            'smtp_from_name'  => ''
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
     */
    public function handle_settings_submission() {
        // Handle General/SMTP Settings
        if ( isset( $_POST['ffc_settings_nonce'] ) && wp_verify_nonce( $_POST['ffc_settings_nonce'], 'ffc_settings_action' ) ) {
            $current = get_option( 'ffc_settings', array() );
            $new     = isset( $_POST['ffc_settings'] ) ? $_POST['ffc_settings'] : array();
            
            $clean = array_merge( $current, array( 
                'cleanup_days'    => absint( $new['cleanup_days'] ),
                'smtp_mode'       => sanitize_key( $new['smtp_mode'] ),
                'smtp_host'       => sanitize_text_field( $new['smtp_host'] ),
                'smtp_port'       => absint( $new['smtp_port'] ),
                'smtp_user'       => sanitize_text_field( $new['smtp_user'] ),
                'smtp_pass'       => sanitize_text_field( $new['smtp_pass'] ),
                'smtp_secure'     => sanitize_key( $new['smtp_secure'] ),
                'smtp_from_email' => sanitize_email( $new['smtp_from_email'] ),
                'smtp_from_name'  => sanitize_text_field( $new['smtp_from_name'] ),
            ) );
            
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
     * Render the settings page with tabs.
     */
    public function display_settings_page() {
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'help'; 
        $forms = get_posts( array('post_type'=>'ffc_form', 'posts_per_page'=>-1) );
        ?>
        <div class="wrap ffc-settings-wrap">
            <h1><?php esc_html_e( 'Certificate Settings', 'ffc' ); ?></h1>
            <?php settings_errors( 'ffc_settings' ); ?>
            
            <h2 class="nav-tab-wrapper">
                <a href="?post_type=ffc_form&page=ffc-settings&tab=help" class="nav-tab <?php echo $active_tab == 'help' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Documentation', 'ffc' ); ?></a>
                <a href="?post_type=ffc_form&page=ffc-settings&tab=general" class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'General', 'ffc' ); ?></a>
                <a href="?post_type=ffc_form&page=ffc-settings&tab=smtp" class="nav-tab <?php echo $active_tab == 'smtp' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'SMTP', 'ffc' ); ?></a>
            </h2>
            
            <div class="ffc-tab-content">
                <?php if ( $active_tab == 'help' ) : ?>
                    <div class="card ffc-settings-card">
                        <h3><?php esc_html_e( 'How to use this plugin', 'ffc' ); ?></h3>
                        <p><?php esc_html_e( 'This plugin allows you to create certificate issuance forms, generate PDFs automatically, and verify authenticity.', 'ffc' ); ?></p>
                    
                        <hr>

                        <h4><?php esc_html_e( '1. Shortcodes', 'ffc' ); ?></h4>
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th style="width: 200px;"><?php esc_html_e( 'Shortcode', 'ffc' ); ?></th>
                                    <th><?php esc_html_e( 'Description', 'ffc' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><code>[ffc_form id="123"]</code></td>
                                    <td><?php esc_html_e( 'Displays the issuance form. Replace "123" with the specific Form ID found in the "All Forms" list.', 'ffc' ); ?></td>
                                </tr>
                                <tr>
                                    <td><code>[ffc_verification]</code></td>
                                    <td><?php esc_html_e( 'Displays the public Authenticity Verification page where users can validate a certificate code.', 'ffc' ); ?></td>
                                </tr>
                            </tbody>
                        </table>

                        <br>
                        <hr>

                        <h4><?php esc_html_e( '2. PDF Template Variables', 'ffc' ); ?></h4>
                        <p><?php esc_html_e( 'When creating your form layout (HTML) in the editor, use these variables. They will be replaced by user data:', 'ffc' ); ?></p>
                        <table class="widefat striped">
                            <thead>
                                <tr>
                                    <th style="width: 200px;"><?php esc_html_e( 'Variable', 'ffc' ); ?></th>
                                    <th><?php esc_html_e( 'Description', 'ffc' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><code>{{name}}</code> <?php esc_html_e( 'or', 'ffc' ); ?> <code>{{nome}}</code></td>
                                    <td><?php esc_html_e( 'The full name of the participant.', 'ffc' ); ?></td>
                                </tr>
                                <tr>
                                    <td><code>{{cpf_rf}}</code></td>
                                    <td><?php esc_html_e( 'The Identification ID (CPF/RF) entered by the user.', 'ffc' ); ?></td>
                                </tr>
                                <tr>
                                    <td><code>{{email}}</code></td>
                                    <td><?php esc_html_e( 'The user email address.', 'ffc' ); ?></td>
                                </tr>
                                <tr>
                                    <td><code>{{auth_code}}</code></td>
                                    <td><?php esc_html_e( 'The unique authentication code (e.g., A1B2-C3D4-E5F6). Required for validation.', 'ffc' ); ?></td>
                                </tr>
                                <tr>
                                    <td><code>{{form_title}}</code></td>
                                    <td><?php esc_html_e( 'The title of this form/event.', 'ffc' ); ?></td>
                                </tr>
                                <tr>
                                    <td><code>{{current_date}}</code></td>
                                    <td><?php esc_html_e( 'The date the certificate was issued (DD/MM/YYYY).', 'ffc' ); ?></td>
                                </tr>
                                <tr>
                                    <td><code>{{custom_field_name}}</code></td>
                                    <td><?php esc_html_e( 'Any other custom field you added to the form (use the field name).', 'ffc' ); ?></td>
                                </tr>
                            </tbody>
                        </table>

                        <br>
                        <hr>

                        <h4><?php esc_html_e( '3. Security Features', 'ffc' ); ?></h4>
                        <ul>
                            <li><strong><?php esc_html_e( 'Allowlist:', 'ffc' ); ?></strong> <?php esc_html_e( 'Restrict issuance to a specific list of IDs/CPFs.', 'ffc' ); ?></li>
                            <li><strong><?php esc_html_e( 'Ticket Mode:', 'ffc' ); ?></strong> <?php esc_html_e( 'Require a unique ticket code to issue the certificate. Tickets are invalidated after use.', 'ffc' ); ?></li>
                            <li><strong><?php esc_html_e( 'Denylist:', 'ffc' ); ?></strong> <?php esc_html_e( 'Block specific IDs or Tickets from generating certificates.', 'ffc' ); ?></li>
                            <li><strong><?php esc_html_e( 'Math Captcha:', 'ffc' ); ?></strong> <?php esc_html_e( 'Built-in protection against bots on all forms.', 'ffc' ); ?></li>
                        </ul>
                    </div>
                    
                <?php elseif ( $active_tab == 'general' ) : ?>
                    <form method="post">
                        <?php wp_nonce_field( 'ffc_settings_action', 'ffc_settings_nonce' ); ?>
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
                        <form method="post" id="ffc-danger-zone-form">
                            <?php wp_nonce_field( 'ffc_delete_all_data', 'ffc_critical_nonce' ); ?>
                            <input type="hidden" name="ffc_delete_all_data" value="1">
                            <div class="ffc-admin-flex-row">
                                <select name="delete_target" id="ffc_delete_target">
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
                    
                <?php elseif ( $active_tab == 'smtp' ) : ?>
                    <form method="post">
                        <?php wp_nonce_field( 'ffc_settings_action', 'ffc_settings_nonce' ); ?>
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
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
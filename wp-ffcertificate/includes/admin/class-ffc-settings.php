<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Gerencia a página de configurações globais do plugin (SMTP e Limpeza).
 */
class FFC_Settings {

    public function __construct() {
        // Registra o gancho de salvamento aqui para garantir que rode no admin_init
        add_action( 'admin_init', array( $this, 'handle_settings_submission' ) );
    }

    /**
     * Retorna os valores padrão.
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
     * Recupera uma opção específica.
     */
    public function get_option( $key ) { 
        $defaults = $this->get_default_settings();
        $s = get_option( 'ffc_settings', $defaults ); 
        
        return isset( $s[$key] ) ? $s[$key] : ( isset( $defaults[$key] ) ? $defaults[$key] : '' ); 
    }

    /**
     * Renderiza o HTML da página de configurações.
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Free Form Certificate - Settings', 'ffc' ); ?></h1>
            
            <?php if ( isset( $_GET['ffc_msg'] ) && $_GET['ffc_msg'] == 'saved' ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved successfully.', 'ffc' ); ?></p></div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field( 'ffc_save_settings_nonce', 'ffc_settings_nonce' ); ?>
                
                <div class="card" style="max-width: 800px; margin-top: 20px;">
                    <h2><?php esc_html_e( 'Data Cleanup', 'ffc' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Automatically delete old submissions to save database space.', 'ffc' ); ?></p>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Keep data for (days)', 'ffc' ); ?></th>
                            <td>
                                <input type="number" name="ffc_settings[cleanup_days]" value="<?php echo esc_attr( $this->get_option( 'cleanup_days' ) ); ?>" class="small-text">
                                <p class="description"><?php esc_html_e( 'Set to 0 to disable auto-cleanup.', 'ffc' ); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="card" style="max-width: 800px; margin-top: 20px;">
                    <h2><?php esc_html_e( 'Email Settings (SMTP)', 'ffc' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Configure how emails are sent. Use "Custom SMTP" to avoid spam folders.', 'ffc' ); ?></p>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Sending Mode', 'ffc' ); ?></th>
                            <td>
                                <select name="ffc_settings[smtp_mode]" id="ffc_smtp_mode">
                                    <option value="wp" <?php selected( 'wp', $this->get_option( 'smtp_mode' ) ); ?>><?php esc_html_e( 'Default (WordPress/PHP Mail)', 'ffc' ); ?></option>
                                    <option value="custom" <?php selected( 'custom', $this->get_option( 'smtp_mode' ) ); ?>><?php esc_html_e( 'Custom SMTP', 'ffc' ); ?></option>
                                </select>
                            </td>
                        </tr>
                    </table>

                    <div id="smtp-options" style="<?php echo ( $this->get_option( 'smtp_mode' ) === 'custom' ) ? '' : 'display:none;'; ?>">
                        <table class="form-table">
                            <tbody>
                                <tr>
                                    <th><?php esc_html_e( 'SMTP Host', 'ffc' ); ?></th>
                                    <td><input type="text" name="ffc_settings[smtp_host]" value="<?php echo esc_attr( $this->get_option( 'smtp_host' ) ); ?>" class="regular-text" placeholder="smtp.gmail.com"></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'SMTP Port', 'ffc' ); ?></th>
                                    <td><input type="number" name="ffc_settings[smtp_port]" value="<?php echo esc_attr( $this->get_option( 'smtp_port' ) ); ?>" class="small-text" placeholder="587"></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Encryption', 'ffc' ); ?></th>
                                    <td>
                                        <select name="ffc_settings[smtp_secure]">
                                            <option value="tls" <?php selected( 'tls', $this->get_option( 'smtp_secure' ) ); ?>>TLS</option>
                                            <option value="ssl" <?php selected( 'ssl', $this->get_option( 'smtp_secure' ) ); ?>>SSL</option>
                                            <option value="none" <?php selected( 'none', $this->get_option( 'smtp_secure' ) ); ?>>None</option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Username', 'ffc' ); ?></th>
                                    <td><input type="text" name="ffc_settings[smtp_user]" value="<?php echo esc_attr( $this->get_option( 'smtp_user' ) ); ?>" class="regular-text"></td>
                                </tr>
                                <tr>
                                    <th><?php esc_html_e( 'Password', 'ffc' ); ?></th>
                                    <td><input type="password" name="ffc_settings[smtp_pass]" value="<?php echo esc_attr( $this->get_option( 'smtp_pass' ) ); ?>" class="regular-text"></td>
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
                    </div>
                </div>

                <?php submit_button( __( 'Save Settings', 'ffc' ) ); ?>
                
                <script>
                    jQuery(function($){ 
                        $('#ffc_smtp_mode').change(function(){ 
                            if($(this).val()==='custom') $('#smtp-options').slideDown(); 
                            else $('#smtp-options').slideUp(); 
                        }); 
                    });
                </script>
            </form>
        </div>
        <?php
    }

    /**
     * Processa o salvamento das configurações.
     */
    public function handle_settings_submission() {
        if ( ! isset( $_POST['ffc_settings_nonce'] ) ) return;
        
        if ( ! wp_verify_nonce( $_POST['ffc_settings_nonce'], 'ffc_save_settings_nonce' ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) return;

        if ( isset( $_POST['ffc_settings'] ) && is_array( $_POST['ffc_settings'] ) ) {
            $input = $_POST['ffc_settings'];
            $new_settings = array(
                'cleanup_days'    => absint( $input['cleanup_days'] ),
                'smtp_mode'       => sanitize_text_field( $input['smtp_mode'] ),
                'smtp_host'       => sanitize_text_field( $input['smtp_host'] ),
                'smtp_port'       => absint( $input['smtp_port'] ),
                'smtp_secure'     => sanitize_text_field( $input['smtp_secure'] ),
                'smtp_user'       => sanitize_text_field( $input['smtp_user'] ),
                'smtp_pass'       => sanitize_text_field( $input['smtp_pass'] ), // Senhas geralmente não devem ser higienizadas excessivamente, mas no WP simple text ok
                'smtp_from_email' => sanitize_email( $input['smtp_from_email'] ),
                'smtp_from_name'  => sanitize_text_field( $input['smtp_from_name'] ),
            );

            update_option( 'ffc_settings', $new_settings );
            
            // Redireciona para evitar reenvio de form
            wp_redirect( add_query_arg( 'ffc_msg', 'saved', $_SERVER['REQUEST_URI'] ) );
            exit;
        }
    }
}
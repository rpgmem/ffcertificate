<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Settings {

    private $submission_handler;

    public function __construct( FFC_Submission_Handler $handler ) {
        $this->submission_handler = $handler;
        
        // Hooks para salvar configurações são chamados aqui
        // Como esta classe é instanciada no admin_init pelo FFC_Admin, o hook já está ativo
        add_action( 'admin_init', array( $this, 'handle_settings_submission' ) );
    }

    public function get_default_settings() { 
        return array( 
            'cleanup_days' => 30, 
            'smtp_mode'    => 'wp', 
            'smtp_port'    => 587, 
            'smtp_secure'  => 'tls' 
        ); 
    }
    
    public function get_option( $key ) { 
        $s = get_option('ffc_settings', $this->get_default_settings()); 
        return isset($s[$key]) ? $s[$key] : (isset($this->get_default_settings()[$key]) ? $this->get_default_settings()[$key] : ''); 
    }

    public function handle_settings_submission() {
        // 1. Salvar Configurações Gerais e SMTP
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
        
        // 2. Executar Ações da Danger Zone (Deletar Dados)
        if ( isset( $_POST['ffc_delete_all_data'] ) && check_admin_referer( 'ffc_delete_all_data', 'ffc_critical_nonce' ) ) {
            $target = isset($_POST['delete_target']) ? $_POST['delete_target'] : 'all';
            
            if($target === 'all') { 
                $this->submission_handler->delete_all_submissions(); 
            } else { 
                $this->submission_handler->delete_all_submissions(absint($target)); 
            }
            
            add_settings_error( 'ffc_settings', 'ffc_data_deleted', __( 'Data deleted.', 'ffc' ), 'updated' );
        }
    }
    
    public function display_settings_page() {
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'help'; 
        $forms = get_posts( array('post_type'=>'ffc_form', 'posts_per_page'=>-1) );
        ?>
        <div class="wrap">
            <h1>Settings</h1>
            <?php settings_errors( 'ffc_settings' ); ?>
            
            <h2 class="nav-tab-wrapper">
                <a href="?post_type=ffc_form&page=ffc-settings&tab=help" class="nav-tab <?php echo $active_tab=='help'?'nav-tab-active':''; ?>">Help</a>
                <a href="?post_type=ffc_form&page=ffc-settings&tab=general" class="nav-tab <?php echo $active_tab=='general'?'nav-tab-active':''; ?>">General</a>
                <a href="?post_type=ffc_form&page=ffc-settings&tab=smtp" class="nav-tab <?php echo $active_tab=='smtp'?'nav-tab-active':''; ?>">SMTP</a>
            </h2>
            
            <?php if($active_tab=='help'): ?>
                <div class="card" style="margin-top:20px;padding:15px;">
                    <h3>Shortcodes</h3>
                    <p><code>[ffc_form id="ID"]</code> - Exibe o formulário de inscrição para um certificado específico.</p>
                    <p><code>[ffc_verification]</code> - Exibe o campo de verificação de autenticidade (página pública).</p>
                </div>
                
            <?php elseif($active_tab=='general'): ?>
                <form method="post">
                    <?php wp_nonce_field('ffc_settings_action','ffc_settings_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th>Auto-delete (days)</th>
                            <td>
                                <input type="number" name="ffc_settings[cleanup_days]" value="<?php echo esc_attr($this->get_option('cleanup_days')); ?>">
                                <p class="description">Arquivos temporários serão removidos após este período.</p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(); ?>
                </form>
                
                <hr>
                
                <h2>Danger Zone</h2>
                <div style="border:1px solid #d63638; padding:15px; background:#fff;">
                    <form method="post" onsubmit="return confirm('Tem certeza? Isso apagará TODOS os envios selecionados. Esta ação é irreversível.');">
                        <?php wp_nonce_field('ffc_delete_all_data','ffc_critical_nonce'); ?>
                        <input type="hidden" name="ffc_delete_all_data" value="1">
                        <p>
                            <label><strong>Excluir Dados de Submissões:</strong></label><br>
                            <select name="delete_target" style="margin-top:5px;">
                                <option value="all">Delete All Submissions (Global)</option>
                                <?php foreach($forms as $f): ?>
                                    <option value="<?php echo $f->ID; ?>">Form: <?php echo esc_html($f->post_title); ?></option>
                                <?php endforeach; ?>
                            </select> 
                            <button class="button button-link-delete" style="margin-left:10px;">Execute Deletion</button>
                        </p>
                    </form>
                </div>
                
            <?php elseif($active_tab=='smtp'): ?>
                <form method="post">
                    <?php wp_nonce_field('ffc_settings_action','ffc_settings_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th>Mode</th>
                            <td>
                                <label><input type="radio" name="ffc_settings[smtp_mode]" value="wp" <?php checked('wp',$this->get_option('smtp_mode')); ?>> WP Default (PHP Mail)</label><br>
                                <label><input type="radio" name="ffc_settings[smtp_mode]" value="custom" <?php checked('custom',$this->get_option('smtp_mode')); ?>> Custom SMTP</label>
                            </td>
                        </tr>
                        <tbody id="smtp-options" style="<?php echo ($this->get_option('smtp_mode')==='custom')?'':'display:none;'; ?>">
                            <tr><th>Host</th><td><input type="text" name="ffc_settings[smtp_host]" value="<?php echo esc_attr($this->get_option('smtp_host')); ?>" class="regular-text"></td></tr>
                            <tr><th>Port</th><td><input type="number" name="ffc_settings[smtp_port]" value="<?php echo esc_attr($this->get_option('smtp_port')); ?>" class="small-text"></td></tr>
                            <tr><th>User</th><td><input type="text" name="ffc_settings[smtp_user]" value="<?php echo esc_attr($this->get_option('smtp_user')); ?>" class="regular-text"></td></tr>
                            <tr><th>Pass</th><td><input type="password" name="ffc_settings[smtp_pass]" value="<?php echo esc_attr($this->get_option('smtp_pass')); ?>" class="regular-text"></td></tr>
                            <tr><th>Encryption</th><td><select name="ffc_settings[smtp_secure]"><option value="tls" <?php selected('tls',$this->get_option('smtp_secure')); ?>>TLS</option><option value="ssl" <?php selected('ssl',$this->get_option('smtp_secure')); ?>>SSL</option></select></td></tr>
                            <tr><th>From Email</th><td><input type="email" name="ffc_settings[smtp_from_email]" value="<?php echo esc_attr($this->get_option('smtp_from_email')); ?>" class="regular-text"></td></tr>
                            <tr><th>From Name</th><td><input type="text" name="ffc_settings[smtp_from_name]" value="<?php echo esc_attr($this->get_option('smtp_from_name')); ?>" class="regular-text"></td></tr>
                        </tbody>
                    </table>
                    <?php submit_button(); ?>
                    <script>
                        jQuery(function($){ 
                            $('input[name="ffc_settings[smtp_mode]"]').change(function(){ 
                                if($(this).val()==='custom') $('#smtp-options').show(); 
                                else $('#smtp-options').hide(); 
                            }); 
                        });
                    </script>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }
}
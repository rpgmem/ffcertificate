<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Form_Editor {

    public function __construct() {
        // Inicializa Metaboxes (Prioridade 20 para limpar duplicatas)
        add_action( 'add_meta_boxes', array( $this, 'add_custom_metaboxes' ), 20 );
        
        // Salvamento
        add_action( 'save_post', array( $this, 'save_form_data' ) );
        
        // Notificações de erro de validação
        add_action( 'admin_notices', array( $this, 'display_save_errors' ) );

        // AJAX Específico do Editor (Gerador de Códigos)
        add_action( 'wp_ajax_ffc_generate_codes', array( $this, 'ajax_generate_random_codes' ) );
    }

    // =========================================================================
    // 1. REGISTRO DOS METABOXES
    // =========================================================================
    public function add_custom_metaboxes() {
        // Limpeza preventiva
        remove_meta_box( 'ffc_form_builder', 'ffc_form', 'normal' );
        remove_meta_box( 'ffc_form_config', 'ffc_form', 'normal' );
        remove_meta_box( 'ffc_builder_box', 'ffc_form', 'normal' ); 

        // 1. Layout
        add_meta_box( 'ffc_box_layout', '1. Layout do Certificado', array( $this, 'render_box_layout' ), 'ffc_form', 'normal', 'high' );

        // 2. Form Builder
        add_meta_box( 'ffc_box_builder', '2. Form Builder (Campos)', array( $this, 'render_box_builder' ), 'ffc_form', 'normal', 'high' );

        // 3. Restrição e Segurança
        add_meta_box( 'ffc_box_restriction', '3. Restrição e Segurança', array( $this, 'render_box_restriction' ), 'ffc_form', 'normal', 'high' );

        // 4. E-mail
        add_meta_box( 'ffc_box_email', '4. Configuração de E-mail', array( $this, 'render_box_email' ), 'ffc_form', 'normal', 'high' );
    }

    // =========================================================================
    // 2. RENDERIZAÇÃO (HTML DOS BLOCOS)
    // =========================================================================

    // --- BOX 1: LAYOUT ---
    public function render_box_layout( $post ) {
        $config = get_post_meta( $post->ID, '_ffc_form_config', true );
        $layout = isset( $config['pdf_layout'] ) ? $config['pdf_layout'] : '';
        $templates = glob( FFC_PLUGIN_DIR . 'html/*.html' );
        
        wp_nonce_field( 'ffc_save_form_data', 'ffc_form_nonce' );
        ?>
        <table class="form-table">
            <tr>
                <th><label><?php _e( 'Importar Template', 'ffc' ); ?></label></th>
                <td>
                    <div style="display:flex; gap:10px;">
                        <select id="ffc_template_select" style="max-width:200px;">
                            <option value=""><?php _e( 'Selecione...', 'ffc' ); ?></option>
                            <?php if($templates): foreach($templates as $tpl): $filename = basename($tpl); ?>
                                <option value="<?php echo esc_attr($filename); ?>"><?php echo esc_html($filename); ?></option>
                            <?php endforeach; endif; ?>
                        </select>
                        <button type="button" id="ffc_load_template_btn" class="button"><?php _e( 'Carregar HTML', 'ffc' ); ?></button>
                    </div>
                </td>
            </tr>
            <tr>
                <td colspan="2">
                    <label><strong><?php _e( 'Editor HTML do Certificado', 'ffc' ); ?></strong></label><br>
                    <textarea name="ffc_config[pdf_layout]" id="ffc_pdf_layout" style="width:100%; height:400px; font-family:monospace; background:#2c3338; color:#fff; padding:10px; margin-top:5px;"><?php echo esc_textarea( $layout ); ?></textarea>
                    <p class="description">Tags Obrigatórias: <code>{{auth_code}}</code>, <code>{{name}}</code>, <code>{{cpf_rf}}</code>.</p>
                </td>
            </tr>
        </table>
        <?php
    }

    // --- BOX 2: BUILDER ---
    public function render_box_builder( $post ) {
        $fields = get_post_meta( $post->ID, '_ffc_form_fields', true );

        if ( empty( $fields ) && $post->post_status === 'auto-draft' ) {
            $fields = array(
                array( 'type' => 'text', 'label' => 'Nome Completo', 'name' => 'name', 'required' => '1' ),
                array( 'type' => 'email', 'label' => 'E-mail', 'name' => 'email', 'required' => '1' ),
                array( 'type' => 'text', 'label' => 'CPF / RF', 'name' => 'cpf_rf', 'required' => '1' )
            );
        }
        ?>
        <div id="ffc-fields-container">
            <?php if ( ! empty( $fields ) ) : foreach ( $fields as $index => $field ) : ?>
                <?php $this->render_field_row( $index, $field ); ?>
            <?php endforeach; endif; ?>
        </div>
        
        <div style="margin-top:15px; border-top:1px solid #eee; padding-top:10px;">
            <button type="button" class="button button-primary ffc-add-field"><?php _e( '+ Adicionar Campo', 'ffc' ); ?></button>
        </div>

        <div class="ffc-field-row ffc-field-template" style="display:none;">
            <?php $this->render_field_row( 'TEMPLATE', array() ); ?>
        </div>
        <?php
    }

    // --- BOX 3: RESTRIÇÃO (SEPARADA) ---
    public function render_box_restriction( $post ) {
        $config = get_post_meta( $post->ID, '_ffc_form_config', true );
        
        $enable    = isset($config['enable_restriction']) ? $config['enable_restriction'] : '0';
        $allow     = isset($config['allowed_users_list']) ? $config['allowed_users_list'] : '';
        $deny      = isset($config['denied_users_list']) ? $config['denied_users_list'] : ''; 
        $vcode     = isset($config['validation_code']) ? $config['validation_code'] : ''; 
        $gen_codes = isset($config['generated_codes_list']) ? $config['generated_codes_list'] : ''; 
        ?>
        <p class="description">Configure quem pode emitir certificados. Você pode usar CPFs (Allowlist) ou gerar Códigos de Acesso (Tickets).</p>
        <table class="form-table">
            
            <tr>
                <th><label>Senha Única (Opcional)</label></th>
                <td>
                    <input type="text" name="ffc_config[validation_code]" value="<?php echo esc_attr($vcode); ?>" class="regular-text">
                    <p class="description">Se definido, todos os usuários deverão digitar esta mesma senha para liberar o formulário.</p>
                </td>
            </tr>

            <tr>
                <th><label>Modo de Restrição</label></th>
                <td>
                    <select name="ffc_config[enable_restriction]">
                        <option value="0" <?php selected($enable, '0'); ?>>Desativado (Livre para todos)</option>
                        <option value="1" <?php selected($enable, '1'); ?>>Ativado (Requer CPF na Allowlist OU Ticket Válido)</option>
                    </select>
                </td>
            </tr>

            <tr>
                <th><label>Allowlist (CPFs / IDs)</label></th>
                <td>
                    <textarea name="ffc_config[allowed_users_list]" placeholder="12345678900&#10;98765432100" style="width:100%; height:120px; font-family:monospace;"><?php echo esc_textarea($allow); ?></textarea>
                    <p class="description">Lista de documentos (CPFs/RGs). Requer campo com variável <code>cpf_rf</code>.</p>
                </td>
            </tr>

            <tr>
                <th><label>Denylist (Bloqueados)</label></th>
                <td>
                    <textarea name="ffc_config[denied_users_list]" placeholder="CPF ou Código por linha" style="width:100%; height:80px;"><?php echo esc_textarea($deny); ?></textarea>
                    <p class="description">Usuários nesta lista serão bloqueados mesmo que o modo de restrição esteja desativado.</p>
                </td>
            </tr>
            
            <tr style="background:#f0f6fc; border-top:1px solid #ddd;">
                <th><label style="color:#2271b1; font-weight:bold;">Gerador de Tickets (Ingressos)</label></th>
                <td>
                    <div style="display:flex; align-items:center; gap:10px; margin-bottom: 5px;">
                        <input type="number" id="ffc_qty_codes" value="10" min="1" max="500" style="width:70px;">
                        <button type="button" class="button button-secondary" id="ffc_btn_generate_codes">Gerar Tickets</button>
                        <span id="ffc_gen_status" style="font-style:italic; color:#666;"></span>
                    </div>
                    
                    <textarea name="ffc_config[generated_codes_list]" id="ffc_generated_list" placeholder="Os códigos gerados aparecerão aqui..." style="width:100%; height:120px; font-family:monospace; background:#fff;"><?php echo esc_textarea($gen_codes); ?></textarea>
                    
                    <p class="description">
                        <strong>Como usar:</strong> Crie um campo no Form Builder com a variável (Name) <code>ticket</code>.<br>
                        O usuário deverá digitar um destes códigos para emitir. Após o uso, o código é removido da lista (queimado).
                    </p>
                </td>
            </tr>

        </table>
        
        <script>
        jQuery(document).ready(function($){
            $('#ffc_btn_generate_codes').click(function(){
                var qty = $('#ffc_qty_codes').val();
                var btn = $(this);
                var status = $('#ffc_gen_status');
                
                if(qty < 1) return;
                
                btn.prop('disabled', true);
                status.text('Gerando...');
                
                $.post(ffc_admin_ajax.ajax_url, {
                    action: 'ffc_generate_codes',
                    nonce: ffc_admin_ajax.nonce,
                    qty: qty
                }, function(res) {
                    btn.prop('disabled', false);
                    if(res.success) {
                        var current = $('#ffc_generated_list').val();
                        var sep = current.length > 0 && !current.endsWith('\n') ? '\n' : '';
                        $('#ffc_generated_list').val( current + sep + res.data.codes );
                        status.text(qty + ' códigos gerados! Não esqueça de salvar.');
                    } else {
                        status.text('Erro ao gerar.');
                    }
                });
            });
        });
        </script>
        <?php
    }

    // --- BOX 4: EMAIL ---
    public function render_box_email( $post ) {
        $config = get_post_meta( $post->ID, '_ffc_form_config', true );
        $send_email = isset($config['send_user_email']) ? $config['send_user_email'] : '0';
        $subject    = isset($config['email_subject']) ? $config['email_subject'] : 'Seu Certificado';
        $body       = isset($config['email_body']) ? $config['email_body'] : "Olá,\n\nSegue em anexo o seu certificado.\n\nAtenciosamente.";
        ?>
        <table class="form-table">
            <tr>
                <th><label>Enviar E-mail?</label></th>
                <td>
                    <select name="ffc_config[send_user_email]">
                        <option value="0" <?php selected($send_email, '0'); ?>>Não (Apenas download)</option>
                        <option value="1" <?php selected($send_email, '1'); ?>>Sim (Enviar com anexo PDF)</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label>Assunto</label></th>
                <td>
                    <input type="text" name="ffc_config[email_subject]" value="<?php echo esc_attr($subject); ?>" class="regular-text" style="width:100%;">
                </td>
            </tr>
            <tr>
                <th><label>Corpo do E-mail</label></th>
                <td>
                    <textarea name="ffc_config[email_body]" style="width:100%; height:120px;"><?php echo esc_textarea($body); ?></textarea>
                </td>
            </tr>
        </table>
        <?php
    }

    // --- AUXILIAR: LINHA DO BUILDER ---
    private function render_field_row( $index, $field ) {
        $type = isset( $field['type'] ) ? $field['type'] : 'text';
        $label = isset( $field['label'] ) ? $field['label'] : '';
        $name = isset( $field['name'] ) ? $field['name'] : '';
        $req = isset( $field['required'] ) ? $field['required'] : '';
        $opts = isset( $field['options'] ) ? $field['options'] : '';
        ?>
        <div class="ffc-field-row" style="background:#fff; border:1px solid #ccd0d4; padding:15px; margin-bottom:10px; border-left: 4px solid #2271b1; cursor:move;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                <span class="ffc-sort-handle" style="cursor:move; color:#999; font-size:18px;">☰ <span style="font-size:12px; font-weight:bold; color:#333;">Campo</span></span>
                <button type="button" class="button button-link-delete ffc-remove-field" style="color:#b32d2e;">Excluir</button>
            </div>
            
            <div style="display:grid; grid-template-columns: 1fr 1fr 1fr auto; gap:10px; align-items:end;">
                <label>
                    <span style="display:block; font-size:11px; color:#666;">Rótulo (Label)</span>
                    <input type="text" name="ffc_fields[<?php echo $index; ?>][label]" value="<?php echo esc_attr( $label ); ?>" style="width:100%;">
                </label>
                
                <label>
                    <span style="display:block; font-size:11px; color:#666;">Variável (Name)</span>
                    <input type="text" name="ffc_fields[<?php echo $index; ?>][name]" value="<?php echo esc_attr( $name ); ?>" style="width:100%;">
                </label>

                <label>
                    <span style="display:block; font-size:11px; color:#666;">Tipo</span>
                    <select name="ffc_fields[<?php echo $index; ?>][type]" class="ffc-field-type-select" style="width:100%;">
                        <option value="text" <?php selected($type, 'text'); ?>>Texto</option>
                        <option value="email" <?php selected($type, 'email'); ?>>E-mail</option>
                        <option value="number" <?php selected($type, 'number'); ?>>Número</option>
                        <option value="select" <?php selected($type, 'select'); ?>>Select (Lista)</option>
                        <option value="radio" <?php selected($type, 'radio'); ?>>Radio</option>
                    </select>
                </label>
                
                <label style="padding-bottom:5px;">
                    <input type="checkbox" name="ffc_fields[<?php echo $index; ?>][required]" value="1" <?php checked($req, '1'); ?>> Obrigatório
                </label>
            </div>

            <div class="ffc-options-field" style="margin-top:10px; background:#f0f0f1; padding:10px; display:<?php echo ($type=='select'||$type=='radio')?'block':'none'; ?>;">
                <label>Opções (separadas por vírgula): <input type="text" name="ffc_fields[<?php echo $index; ?>][options]" value="<?php echo esc_attr( $opts ); ?>" style="width:100%;"></label>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // 3. AJAX (GERADOR DE CÓDIGOS)
    // =========================================================================
    public function ajax_generate_random_codes() {
        check_ajax_referer( 'ffc_admin_pdf_nonce', 'nonce' );
        
        $qty = isset($_POST['qty']) ? absint($_POST['qty']) : 10;
        if($qty <= 0) $qty = 1;
        if($qty > 500) $qty = 500;

        $codes = array();
        for($i = 0; $i < $qty; $i++) {
            $rnd = strtoupper(bin2hex(random_bytes(4))); 
            $formatted = substr($rnd, 0, 4) . '-' . substr($rnd, 4, 4);
            $codes[] = $formatted;
        }
        
        wp_send_json_success( array( 'codes' => implode("\n", $codes) ) );
    }

    // =========================================================================
    // 4. SALVAMENTO COM VALIDAÇÃO
    // =========================================================================
    public function save_form_data( $post_id ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;
        if ( ! isset( $_POST['ffc_form_nonce'] ) || ! wp_verify_nonce( $_POST['ffc_form_nonce'], 'ffc_save_form_data' ) ) return;

        // 1. Salva Campos do Builder
        if ( isset( $_POST['ffc_fields'] ) && is_array( $_POST['ffc_fields'] ) ) {
            $raw_fields = $_POST['ffc_fields'];
            $clean_fields = array();

            foreach ( $raw_fields as $index => $field ) {
                if ( $index === 'TEMPLATE' ) continue;
                if ( empty( trim( $field['label'] ) ) && empty( trim( $field['name'] ) ) ) continue;

                $clean_fields[] = array(
                    'label'    => sanitize_text_field( $field['label'] ),
                    'name'     => sanitize_key( $field['name'] ),
                    'type'     => sanitize_key( $field['type'] ),
                    'required' => isset( $field['required'] ) ? '1' : '',
                    'options'  => sanitize_text_field( isset( $field['options'] ) ? $field['options'] : '' ),
                );
            }
            update_post_meta( $post_id, '_ffc_form_fields', $clean_fields );
        }

        // 2. Salva TODAS as Configurações
        if ( isset( $_POST['ffc_config'] ) ) {
            $config = $_POST['ffc_config'];
            $html_layout = isset($config['pdf_layout']) ? $config['pdf_layout'] : '';

            // Sanitização
            $config['email_body'] = isset($config['email_body']) ? sanitize_textarea_field( $config['email_body'] ) : '';
            $config['allowed_users_list'] = isset($config['allowed_users_list']) ? sanitize_textarea_field( $config['allowed_users_list'] ) : '';
            $config['denied_users_list'] = isset($config['denied_users_list']) ? sanitize_textarea_field( $config['denied_users_list'] ) : '';
            $config['validation_code'] = isset($config['validation_code']) ? sanitize_text_field( $config['validation_code'] ) : '';
            $config['generated_codes_list'] = isset($config['generated_codes_list']) ? sanitize_textarea_field( $config['generated_codes_list'] ) : '';

            // Validação de Tags (Layout)
            $missing_tags = array();
            if ( strpos( $html_layout, '{{auth_code}}' ) === false ) $missing_tags[] = '{{auth_code}}';
            if ( strpos( $html_layout, '{{name}}' ) === false ) $missing_tags[] = '{{name}}';
            if ( strpos( $html_layout, '{{cpf_rf}}' ) === false ) $missing_tags[] = '{{cpf_rf}}';

            if ( ! empty( $missing_tags ) ) {
                $old_config = get_post_meta( $post_id, '_ffc_form_config', true );
                $config['pdf_layout'] = ( is_array( $old_config ) && isset( $old_config['pdf_layout'] ) ) ? $old_config['pdf_layout'] : '';
                set_transient( 'ffc_save_error_' . get_current_user_id(), $missing_tags, 45 );
            }

            // Merge para segurança
            $current_config = get_post_meta( $post_id, '_ffc_form_config', true );
            if(!is_array($current_config)) $current_config = array();
            $final_config = array_merge($current_config, $config);

            update_post_meta( $post_id, '_ffc_form_config', $final_config );
        }
    }

    public function display_save_errors() {
        $error_tags = get_transient( 'ffc_save_error_' . get_current_user_id() );
        if ( $error_tags ) {
            delete_transient( 'ffc_save_error_' . get_current_user_id() );
            ?>
            <div class="notice notice-error is-dismissible">
                <p>
                    <strong><?php _e( 'Atenção! O layout do Certificado NÃO foi atualizado.', 'ffc' ); ?></strong><br>
                    <?php _e( 'Tags obrigatórias ausentes:', 'ffc' ); ?> <code><?php echo implode( ', ', $error_tags ); ?></code>.
                </p>
            </div>
            <?php
        }
    }
}
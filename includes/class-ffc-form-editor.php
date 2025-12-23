<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Form_Editor {

    public function __construct() {
        add_action( 'add_meta_boxes', array( $this, 'add_custom_metaboxes' ), 20 );
        add_action( 'save_post', array( $this, 'save_form_data' ) );
        add_action( 'admin_notices', array( $this, 'display_save_errors' ) );

        add_action( 'wp_ajax_ffc_generate_codes', array( $this, 'ajax_generate_random_codes' ) );
        add_action( 'wp_ajax_ffc_load_template', array( $this, 'ajax_load_template' ) );
    }

    public function add_custom_metaboxes() {
        // Limpa metaboxes antigos para evitar duplicidade
        remove_meta_box( 'ffc_form_builder', 'ffc_form', 'normal' );
        remove_meta_box( 'ffc_form_config', 'ffc_form', 'normal' );
        remove_meta_box( 'ffc_builder_box', 'ffc_form', 'normal' ); 

        add_meta_box( 'ffc_box_layout', __( '1. Certificate Layout', 'ffc' ), array( $this, 'render_box_layout' ), 'ffc_form', 'normal', 'high' );
        add_meta_box( 'ffc_box_builder', __( '2. Form Builder (Fields)', 'ffc' ), array( $this, 'render_box_builder' ), 'ffc_form', 'normal', 'high' );
        add_meta_box( 'ffc_box_restriction', __( '3. Restriction & Security', 'ffc' ), array( $this, 'render_box_restriction' ), 'ffc_form', 'normal', 'high' );
        add_meta_box( 'ffc_box_email', __( '4. Email Configuration', 'ffc' ), array( $this, 'render_box_email' ), 'ffc_form', 'normal', 'high' );
    }

    public function render_box_layout( $post ) {
        $config = get_post_meta( $post->ID, '_ffc_form_config', true );
        $layout = isset( $config['pdf_layout'] ) ? $config['pdf_layout'] : '';
        $bg_image = isset( $config['bg_image'] ) ? $config['bg_image'] : '';
        
        $templates_dir = FFC_PLUGIN_DIR . 'html/';
        $templates = glob( $templates_dir . '*.html' );
        
        wp_nonce_field( 'ffc_save_form_data', 'ffc_form_nonce' );
        ?>
        <table class="form-table">
            <tr>
                <th><label><?php esc_html_e( 'Actions', 'ffc' ); ?></label></th>
                <td>
                    <div class="ffc-admin-flex-row ffc-flex-wrap">
                        <div class="ffc-action-group">
                            <input type="file" id="ffc_import_html_file" accept=".html,.txt" class="ffc-hidden">
                            <button type="button" class="button" id="ffc_btn_import_html">
                                <span class="dashicons dashicons-upload"></span> 
                                <?php esc_html_e( 'Import HTML', 'ffc' ); ?>
                            </button>
                            <button type="button" class="button" id="ffc_btn_media_lib">
                                <span class="dashicons dashicons-format-image"></span> 
                                <?php esc_html_e( 'Background', 'ffc' ); ?>
                            </button>
                        </div>

                        <?php if($templates): ?>
                        <div class="ffc-template-loader">
                            <select id="ffc_template_select">
                                <option value=""><?php esc_html_e( 'Select Server Template...', 'ffc' ); ?></option>
                                <?php foreach($templates as $tpl): $filename = basename($tpl); ?>
                                    <option value="<?php echo esc_attr($filename); ?>"><?php echo esc_html($filename); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" id="ffc_load_template_btn" class="button"><?php esc_html_e( 'Load', 'ffc' ); ?></button>
                        </div>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <tr>
                <td colspan="2">
                    <label class="ffc-block-label"><strong><?php esc_html_e( 'Certificate HTML Editor', 'ffc' ); ?></strong></label>
                    <textarea name="ffc_config[pdf_layout]" id="ffc_pdf_layout" class="ffc-w100" rows="12"><?php echo esc_textarea( $layout ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'Mandatory Tags:', 'ffc' ); ?> <code>{{auth_code}}</code>, <code>{{name}}</code>, <code>{{cpf_rf}}</code>.</p>
                    
                    <div class="ffc-input-group" style="margin-top: 15px;">
                        <label class="ffc-block-label"><strong><?php esc_html_e( 'Background Image URL:', 'ffc' ); ?></strong></label>
                        <input type="text" name="ffc_config[bg_image]" id="ffc_bg_image_input" value="<?php echo esc_attr( $bg_image ); ?>" class="ffc-w100">
                    </div>
                </td>
            </tr>
        </table>
        <?php
    }

    public function render_box_builder( $post ) {
        $fields = get_post_meta( $post->ID, '_ffc_form_fields', true );

        if ( empty( $fields ) && $post->post_status === 'auto-draft' ) {
            $fields = array(
                array( 'type' => 'text', 'label' => 'Full Name', 'name' => 'name', 'required' => '1', 'options' => '' ),
                array( 'type' => 'email', 'label' => 'Email', 'name' => 'email', 'required' => '1', 'options' => '' ),
                array( 'type' => 'text', 'label' => 'CPF / ID', 'name' => 'cpf_rf', 'required' => '1', 'options' => '' )
            );
        }
        ?>
        <div id="ffc-fields-container">
            <?php 
            if ( ! empty( $fields ) && is_array($fields) ) {
                foreach ( $fields as $index => $field ) {
                    $this->render_field_row( $index, $field );
                }
            } 
            ?>
        </div>
        
        <div class="ffc-builder-actions" style="margin-top: 20px; padding: 10px; border-top: 1px solid #eee;">
            <button type="button" class="button button-primary ffc-add-field">
                <span class="dashicons dashicons-plus-alt" style="margin-top: 4px;"></span> 
                <?php esc_html_e( 'Add New Field', 'ffc' ); ?>
            </button>
        </div>

        <div class="ffc-field-template ffc-hidden" style="display: none;">
            <?php $this->render_field_row( 'TEMPLATE', array() ); ?>
        </div>
        <?php
    }

    public function render_box_restriction( $post ) {
        $config = get_post_meta( $post->ID, '_ffc_form_config', true );
        
        $enable     = isset($config['enable_restriction']) ? $config['enable_restriction'] : '0';
        $allow      = isset($config['allowed_users_list']) ? $config['allowed_users_list'] : '';
        $deny       = isset($config['denied_users_list']) ? $config['denied_users_list'] : ''; 
        $vcode      = isset($config['validation_code']) ? $config['validation_code'] : ''; 
        $gen_codes  = isset($config['generated_codes_list']) ? $config['generated_codes_list'] : ''; 
        ?>
        <table class="form-table">
            <tr>
                <th><label><?php esc_html_e( 'Single Password (Optional)', 'ffc' ); ?></label></th>
                <td><input type="text" name="ffc_config[validation_code]" value="<?php echo esc_attr($vcode); ?>" class="regular-text" placeholder="Ex: EVENTO2024"></td>
            </tr>
            <tr>
                <th><label><?php esc_html_e( 'Restriction Mode', 'ffc' ); ?></label></th>
                <td>
                    <select name="ffc_config[enable_restriction]" class="ffc-select-full">
                        <option value="0" <?php selected($enable, '0'); ?>><?php esc_html_e( 'Disabled (Open)', 'ffc' ); ?></option>
                        <option value="1" <?php selected($enable, '1'); ?>><?php esc_html_e( 'Enabled (Whitelist/Ticket)', 'ffc' ); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label><?php esc_html_e( 'Allowlist (CPFs / IDs)', 'ffc' ); ?></label></th>
                <td><textarea name="ffc_config[allowed_users_list]" class="ffc-textarea-mono ffc-h120 ffc-w100" placeholder="Um por linha..."><?php echo esc_textarea($allow); ?></textarea></td>
            </tr>
            <tr>
                <th><label><?php esc_html_e( 'Denylist (Blocked)', 'ffc' ); ?></label></th>
                <td><textarea name="ffc_config[denied_users_list]" class="ffc-textarea-mono ffc-h80 ffc-w100" placeholder="Usuários banidos..."><?php echo esc_textarea($deny); ?></textarea></td>
            </tr>
            <tr class="ffc-highlight-row">
                <th><label class="ffc-label-accent"><?php esc_html_e( 'Ticket Generator', 'ffc' ); ?></label></th>
                <td>
                    <div class="ffc-admin-flex-row ffc-mb5">
                        <input type="number" id="ffc_qty_codes" value="10" min="1" max="500" class="ffc-input-small">
                        <button type="button" class="button button-secondary" id="ffc_btn_generate_codes"><?php esc_html_e( 'Generate Tickets', 'ffc' ); ?></button>
                    </div>
                    <textarea name="ffc_config[generated_codes_list]" id="ffc_generated_list" class="ffc-textarea-mono ffc-h120 ffc-w100"><?php echo esc_textarea($gen_codes); ?></textarea>
                </td>
            </tr>
        </table>
        <?php
    }

    public function render_box_email( $post ) {
        $config = get_post_meta( $post->ID, '_ffc_form_config', true );
        $send_email = isset($config['send_user_email']) ? $config['send_user_email'] : '0';
        $subject    = isset($config['email_subject']) ? $config['email_subject'] : 'Your Certificate';
        $body       = isset($config['email_body']) ? $config['email_body'] : '';
        ?>
        <table class="form-table">
            <tr>
                <th><label><?php esc_html_e( 'Send Email?', 'ffc' ); ?></label></th>
                <td>
                    <select name="ffc_config[send_user_email]" class="ffc-select-full">
                        <option value="0" <?php selected($send_email, '0'); ?>><?php esc_html_e( 'No', 'ffc' ); ?></option>
                        <option value="1" <?php selected($send_email, '1'); ?>><?php esc_html_e( 'Yes', 'ffc' ); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label><?php esc_html_e( 'Subject', 'ffc' ); ?></label></th>
                <td><input type="text" name="ffc_config[email_subject]" value="<?php echo esc_attr($subject); ?>" class="ffc-w100"></td>
            </tr>
            <tr>
                <th><label><?php esc_html_e( 'Email Body (HTML)', 'ffc' ); ?></label></th>
                <td><textarea name="ffc_config[email_body]" class="ffc-h120 ffc-w100"><?php echo esc_textarea($body); ?></textarea></td>
            </tr>
        </table>
        <?php
    }

    private function render_field_row( $index, $field ) {
        $type  = isset( $field['type'] ) ? $field['type'] : 'text';
        $label = isset( $field['label'] ) ? $field['label'] : '';
        $name  = isset( $field['name'] ) ? $field['name'] : '';
        $req   = isset( $field['required'] ) ? $field['required'] : '';
        $opts  = isset( $field['options'] ) ? $field['options'] : '';
        
        // Determina se o campo de opções deve começar visível
        $options_visible_class = ( $type === 'select' || $type === 'radio' ) ? '' : 'ffc-hidden';
        ?>
        <div class="ffc-field-row" data-index="<?php echo $index; ?>">
            <div class="ffc-field-row-header">
                <span class="ffc-sort-handle">
                    <span class="dashicons dashicons-menu"></span>
                    <span class="ffc-field-title"><strong><?php esc_html_e( 'Field', 'ffc' ); ?></strong></span>
                </span>
                <button type="button" class="button button-link-delete ffc-remove-field"><?php esc_html_e( 'Remove', 'ffc' ); ?></button>
            </div>
            
            <div class="ffc-field-row-grid">
                <div class="ffc-grid-item">
                    <label><?php esc_html_e('Label', 'ffc'); ?></label>
                    <input type="text" name="ffc_fields[<?php echo $index; ?>][label]" value="<?php echo esc_attr( $label ); ?>" class="ffc-w100">
                </div>
                <div class="ffc-grid-item">
                    <label><?php esc_html_e('Variable Name', 'ffc'); ?></label>
                    <input type="text" name="ffc_fields[<?php echo $index; ?>][name]" value="<?php echo esc_attr( $name ); ?>" placeholder="ex: curso_nome" class="ffc-w100">
                </div>
                <div class="ffc-grid-item">
                    <label><?php esc_html_e('Type', 'ffc'); ?></label>
                    <select name="ffc_fields[<?php echo $index; ?>][type]" class="ffc-field-type-selector ffc-w100">
                        <option value="text" <?php selected($type, 'text'); ?>><?php esc_html_e('Text', 'ffc'); ?></option>
                        <option value="email" <?php selected($type, 'email'); ?>><?php esc_html_e('Email', 'ffc'); ?></option>
                        <option value="number" <?php selected($type, 'number'); ?>><?php esc_html_e('Number', 'ffc'); ?></option>
                        <option value="date" <?php selected($type, 'date'); ?>><?php esc_html_e('Date', 'ffc'); ?></option>
                        <option value="select" <?php selected($type, 'select'); ?>><?php esc_html_e('Select (Combobox)', 'ffc'); ?></option>
                        <option value="radio" <?php selected($type, 'radio'); ?>><?php esc_html_e('Radio Box', 'ffc'); ?></option>
                        <option value="hidden" <?php selected($type, 'hidden'); ?>><?php esc_html_e('Hidden Field', 'ffc'); ?></option>
                    </select>
                </div>
                <div class="ffc-grid-item ffc-flex-center">
                    <label class="ffc-req-label">
                        <input type="checkbox" name="ffc_fields[<?php echo $index; ?>][required]" value="1" <?php checked($req, '1'); ?>> 
                        <?php esc_html_e('Required?', 'ffc'); ?>
                    </label>
                </div>
            </div>
            
            <div class="ffc-options-field <?php echo $options_visible_class; ?>" style="<?php echo ($options_visible_class === 'ffc-hidden') ? 'display:none;' : ''; ?>">
                <p class="description" style="margin: 10px 0 5px; font-weight: bold; color: #2271b1;">
                    <?php esc_html_e('Options (separate with commas):', 'ffc'); ?>
                </p>
                <input type="text" name="ffc_fields[<?php echo $index; ?>][options]" value="<?php echo esc_attr( $opts ); ?>" placeholder="<?php esc_attr_e('Ex: Option 1, Option 2, Option 3', 'ffc'); ?>" class="ffc-w100">
            </div>
        </div>
        <?php
    }

    public function ajax_generate_random_codes() {
        check_ajax_referer( 'ffc_admin_pdf_nonce', 'nonce' );
        $qty = isset($_POST['qty']) ? absint($_POST['qty']) : 10;
        $codes = array();
        for($i = 0; $i < $qty; $i++) {
            $rnd = strtoupper(bin2hex(random_bytes(4))); 
            $codes[] = substr($rnd, 0, 4) . '-' . substr($rnd, 4, 4);
        }
        wp_send_json_success( array( 'codes' => implode("\n", $codes) ) );
    }

    public function ajax_load_template() {
        check_ajax_referer( 'ffc_admin_pdf_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error();

        $filename = isset($_POST['filename']) ? sanitize_file_name($_POST['filename']) : '';
        if ( empty($filename) ) wp_send_json_error();

        $filepath = FFC_PLUGIN_DIR . 'html/' . $filename;
        if ( ! file_exists( $filepath ) ) wp_send_json_error();

        $content = file_get_contents( $filepath );
        wp_send_json_success( $content );
    }

    public function save_form_data( $post_id ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;
        if ( ! isset( $_POST['ffc_form_nonce'] ) || ! wp_verify_nonce( $_POST['ffc_form_nonce'], 'ffc_save_form_data' ) ) return;

        // 1. Salva os Campos do Formulário
        if ( isset( $_POST['ffc_fields'] ) && is_array( $_POST['ffc_fields'] ) ) {
            $clean_fields = array();
            foreach ( $_POST['ffc_fields'] as $index => $field ) {
                if ( $index === 'TEMPLATE' || (empty($field['label']) && empty($field['name'])) ) continue;
                
                $clean_fields[] = array(
                    'label'    => sanitize_text_field( $field['label'] ),
                    'name'     => sanitize_key( $field['name'] ),
                    'type'     => sanitize_key( $field['type'] ),
                    'required' => isset( $field['required'] ) ? '1' : '',
                    'options'  => sanitize_text_field( isset( $field['options'] ) ? $field['options'] : '' ),
                );
            }
            update_post_meta( $post_id, '_ffc_form_fields', $clean_fields );
        } else {
            update_post_meta( $post_id, '_ffc_form_fields', array() );
        }

        // 2. Salva as Configurações
        if ( isset( $_POST['ffc_config'] ) ) {
            $config = $_POST['ffc_config'];
            $allowed_html = FFC_Utils::get_allowed_html_tags();

            $clean_config = array();
            $clean_config['pdf_layout'] = wp_kses( $config['pdf_layout'], $allowed_html );
            $clean_config['email_body'] = wp_kses( $config['email_body'], $allowed_html );
            $clean_config['bg_image']   = esc_url_raw( $config['bg_image'] );
            
            $clean_config['enable_restriction'] = sanitize_key( $config['enable_restriction'] );
            $clean_config['send_user_email']    = sanitize_key( $config['send_user_email'] );
            $clean_config['email_subject']      = sanitize_text_field( $config['email_subject'] );
            
            $clean_config['allowed_users_list']   = sanitize_textarea_field( $config['allowed_users_list'] );
            $clean_config['denied_users_list']    = sanitize_textarea_field( $config['denied_users_list'] );
            $clean_config['validation_code']      = sanitize_text_field( $config['validation_code'] );
            $clean_config['generated_codes_list'] = sanitize_textarea_field( $config['generated_codes_list'] );

            // Validação de Tags Obrigatórias
            $missing_tags = array();
            if ( strpos( $clean_config['pdf_layout'], '{{auth_code}}' ) === false ) $missing_tags[] = '{{auth_code}}';
            if ( strpos( $clean_config['pdf_layout'], '{{name}}' ) === false && strpos( $clean_config['pdf_layout'], '{{nome}}' ) === false ) $missing_tags[] = '{{name}}';
            if ( strpos( $clean_config['pdf_layout'], '{{cpf_rf}}' ) === false ) $missing_tags[] = '{{cpf_rf}}';

            if ( ! empty( $missing_tags ) ) {
                set_transient( 'ffc_save_error_' . get_current_user_id(), $missing_tags, 45 );
            }

            $current_config = get_post_meta( $post_id, '_ffc_form_config', true );
            if(!is_array($current_config)) $current_config = array();
            
            update_post_meta( $post_id, '_ffc_form_config', array_merge($current_config, $clean_config) );
        }
    }

    public function display_save_errors() {
        $error_tags = get_transient( 'ffc_save_error_' . get_current_user_id() );
        if ( $error_tags ) {
            delete_transient( 'ffc_save_error_' . get_current_user_id() );
            ?>
            <div class="notice notice-error is-dismissible">
                <p><strong><?php esc_html_e( 'Warning! Missing required tags in PDF Layout:', 'ffc' ); ?></strong> <code><?php echo implode( ', ', $error_tags ); ?></code>.</p>
            </div>
            <?php
        }
    }
}
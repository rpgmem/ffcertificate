<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Gerencia a interface de edição do Post Type (Metaboxes).
 * Responsável pelo "Form Builder" e configurações do PDF.
 */
class FFC_Form_Editor {

    public function __construct() {
        // Registra as Metaboxes
        add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
        
        // Salva os dados
        add_action( 'save_post', array( $this, 'save_form_data' ) );
        
    }

    /**
     * Adiciona as caixas ao editor.
     */
    public function add_meta_boxes() {
        // 1. Construtor do Formulário
        add_meta_box(
            'ffc_form_builder',
            __( 'Form Builder', 'ffc' ),
            array( $this, 'render_builder_metabox' ),
            'ffc_form',
            'normal',
            'high'
        );

        // 2. Configurações e PDF
        add_meta_box(
            'ffc_form_config',
            __( 'Configuration & Certificate Layout', 'ffc' ),
            array( $this, 'render_config_metabox' ),
            'ffc_form',
            'normal',
            'high'
        );
    }

    /**
     * Renderiza o Construtor (Drag & Drop simples).
     */
    public function render_builder_metabox( $post ) {
        // Recupera campos salvos
        $fields = get_post_meta( $post->ID, '_ffc_form_fields', true );
        if ( ! is_array( $fields ) ) {
            $fields = array();
        }
        
        wp_nonce_field( 'ffc_save_form_nonce', 'ffc_form_nonce' );
        ?>
        <div id="ffc-form-builder-wrapper">
            <p class="description">
                <?php _e( 'Add fields to your form via the button below. Drag to reorder.', 'ffc' ); ?>
            </p>

            <div id="ffc-fields-container">
                <?php 
                if ( ! empty( $fields ) ) {
                    foreach ( $fields as $index => $field ) {
                        $this->render_field_row( $index, $field );
                    }
                }
                ?>
            </div>

            <div class="ffc-builder-actions">
                <button type="button" class="button ffc-add-field">
                    <span class="dashicons dashicons-plus"></span> <?php _e( 'Add Field', 'ffc' ); ?>
                </button>
            </div>

            <div class="ffc-field-row ffc-field-template" style="display:none;">
                <div class="ffc-field-header">
                    <span class="dashicons dashicons-menu ffc-sort-handle"></span>
                    <span class="ffc-field-label-preview"><?php _e( 'New Field', 'ffc' ); ?></span>
                    <button type="button" class="button-link ffc-remove-field" style="float:right; color:#b32d2e;">
                        <span class="dashicons dashicons-trash"></span>
                    </button>
                </div>
                <div class="ffc-field-body">
                    <div class="ffc-row">
                        <div class="ffc-col">
                            <label><?php _e( 'Label', 'ffc' ); ?></label>
                            <input type="text" name="ffc_fields[INDEX][label]" class="widefat ffc-field-label-input">
                        </div>
                        <div class="ffc-col">
                            <label><?php _e( 'Type', 'ffc' ); ?></label>
                            <select name="ffc_fields[INDEX][type]" class="widefat ffc-field-type-select">
                                <option value="text"><?php _e( 'Text', 'ffc' ); ?></option>
                                <option value="email"><?php _e( 'Email', 'ffc' ); ?></option>
                                <option value="date"><?php _e( 'Date', 'ffc' ); ?></option>
                                <option value="select"><?php _e( 'Dropdown', 'ffc' ); ?></option>
                                <option value="textarea"><?php _e( 'Text Area', 'ffc' ); ?></option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="ffc-options-field" style="display:none; margin-top:10px;">
                        <label><?php _e( 'Options (comma separated)', 'ffc' ); ?></label>
                        <input type="text" name="ffc_fields[INDEX][options]" class="widefat" placeholder="Option 1, Option 2, Option 3">
                    </div>

                    <div class="ffc-row" style="margin-top:10px;">
                        <label>
                            <input type="checkbox" name="ffc_fields[INDEX][required]" value="1"> 
                            <?php _e( 'Required?', 'ffc' ); ?>
                        </label>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Renderiza uma linha de campo existente.
     */
    private function render_field_row( $index, $field ) {
        $label    = isset( $field['label'] ) ? esc_attr( $field['label'] ) : '';
        $type     = isset( $field['type'] ) ? esc_attr( $field['type'] ) : 'text';
        $options  = isset( $field['options'] ) ? esc_attr( $field['options'] ) : '';
        $required = isset( $field['required'] ) && $field['required'] ? 'checked' : '';
        
        // Estilo condicional para options
        $style_opts = ( $type === 'select' ) ? '' : 'display:none;';
        ?>
        <div class="ffc-field-row">
            <div class="ffc-field-header">
                <span class="dashicons dashicons-menu ffc-sort-handle"></span>
                <span class="ffc-field-label-preview"><?php echo $label ? $label : __( 'Field', 'ffc' ); ?></span>
                <button type="button" class="button-link ffc-remove-field" style="float:right; color:#b32d2e;">
                    <span class="dashicons dashicons-trash"></span>
                </button>
            </div>
            <div class="ffc-field-body">
                <div class="ffc-row">
                    <div class="ffc-col">
                        <label><?php _e( 'Label', 'ffc' ); ?></label>
                        <input type="text" name="ffc_fields[<?php echo $index; ?>][label]" value="<?php echo $label; ?>" class="widefat ffc-field-label-input">
                    </div>
                    <div class="ffc-col">
                        <label><?php _e( 'Type', 'ffc' ); ?></label>
                        <select name="ffc_fields[<?php echo $index; ?>][type]" class="widefat ffc-field-type-select">
                            <option value="text" <?php selected( $type, 'text' ); ?>><?php _e( 'Text', 'ffc' ); ?></option>
                            <option value="email" <?php selected( $type, 'email' ); ?>><?php _e( 'Email', 'ffc' ); ?></option>
                            <option value="date" <?php selected( $type, 'date' ); ?>><?php _e( 'Date', 'ffc' ); ?></option>
                            <option value="select" <?php selected( $type, 'select' ); ?>><?php _e( 'Dropdown', 'ffc' ); ?></option>
                            <option value="textarea" <?php selected( $type, 'textarea' ); ?>><?php _e( 'Text Area', 'ffc' ); ?></option>
                        </select>
                    </div>
                </div>
                
                <div class="ffc-options-field" style="<?php echo $style_opts; ?> margin-top:10px;">
                    <label><?php _e( 'Options (comma separated)', 'ffc' ); ?></label>
                    <input type="text" name="ffc_fields[<?php echo $index; ?>][options]" value="<?php echo $options; ?>" class="widefat">
                </div>

                <div class="ffc-row" style="margin-top:10px;">
                    <label>
                        <input type="checkbox" name="ffc_fields[<?php echo $index; ?>][required]" value="1" <?php echo $required; ?>> 
                        <?php _e( 'Required?', 'ffc' ); ?>
                    </label>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Renderiza a Metabox de Configurações e HTML do PDF.
     */
    public function render_config_metabox( $post ) {
        $config = get_post_meta( $post->ID, '_ffc_form_config', true );
        if ( ! is_array( $config ) ) $config = array();

        // Defaults
        $success_msg = isset($config['success_msg']) ? $config['success_msg'] : __( 'Certificate generated successfully!', 'ffc' );
        $email_subj  = isset($config['email_subject']) ? $config['email_subject'] : __( 'Your Certificate', 'ffc' );
        $html_layout = isset($config['html_layout']) ? $config['html_layout'] : '';
        ?>
        <table class="form-table">
            <tr>
                <th><label><?php _e( 'Success Message', 'ffc' ); ?></label></th>
                <td>
                    <input type="text" name="ffc_config[success_msg]" value="<?php echo esc_attr( $success_msg ); ?>" class="widefat">
                </td>
            </tr>

            <tr>
                <th><label><?php _e( 'Email Subject', 'ffc' ); ?></label></th>
                <td>
                    <input type="text" name="ffc_config[email_subject]" value="<?php echo esc_attr( $email_subj ); ?>" class="widefat">
                    <p class="description"><?php _e( 'Sent when the user receives the certificate via email.', 'ffc' ); ?></p>
                </td>
            </tr>

            <tr>
                <th>
                    <label><?php _e( 'Certificate HTML Layout', 'ffc' ); ?></label>
                    <div style="margin-top:10px;">
                        <button type="button" class="button" id="ffc_btn_import_html"><?php _e( 'Import HTML File', 'ffc' ); ?></button>
                        <input type="file" id="ffc_import_html_file" style="display:none;" accept=".html,.txt">
                    </div>
                </th>
                <td>
                    <textarea name="ffc_config[html_layout]" id="ffc_pdf_layout" rows="15" class="widefat code"><?php echo esc_textarea( $html_layout ); ?></textarea>
                    <p class="description">
                        <?php _e( 'Use {{field_name}} (lowercase) to insert form data. Mandatory tags: {{auth_code}}, {{qrcode}}.', 'ffc' ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Salva os metadados do formulário.
     */
    public function save_form_data( $post_id ) {
        // Verifica Nonce e Permissões
        if ( ! isset( $_POST['ffc_form_nonce'] ) || ! wp_verify_nonce( $_POST['ffc_form_nonce'], 'ffc_save_form_nonce' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        // 1. Salvar Campos (Builder)
        if ( isset( $_POST['ffc_fields'] ) && is_array( $_POST['ffc_fields'] ) ) {
            $fields = array();
            foreach ( $_POST['ffc_fields'] as $raw_field ) {
                // Remove o template 'INDEX' se vier por engano
                if ( isset( $raw_field['label'] ) && $raw_field['label'] !== '' ) {
                    $fields[] = array(
                        'label'    => sanitize_text_field( $raw_field['label'] ),
                        'type'     => sanitize_text_field( $raw_field['type'] ),
                        'options'  => sanitize_text_field( $raw_field['options'] ),
                        'required' => isset( $raw_field['required'] ) ? 1 : 0
                    );
                }
            }
            // Reindexa array
            $fields = array_values( $fields );
            update_post_meta( $post_id, '_ffc_form_fields', $fields );
        }

        // 2. Salvar Configurações
        if ( isset( $_POST['ffc_config'] ) && is_array( $_POST['ffc_config'] ) ) {
            $clean_config = array(
                'success_msg'   => sanitize_text_field( $_POST['ffc_config']['success_msg'] ),
                'email_subject' => sanitize_text_field( $_POST['ffc_config']['email_subject'] ),
                'html_layout'   => wp_kses_post( $_POST['ffc_config']['html_layout'] ) // Permite HTML
            );
            update_post_meta( $post_id, '_ffc_form_config', $clean_config );
        }
    }

    /**
     * AJAX: Carregar Template (Opcional, se tiver templates no servidor)
     */
    public function ajax_load_template() {
        if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error();
        
        // Simples exemplo, retornando um HTML básico
        $html = '<div style="text-align:center; border:5px solid #333; padding:20px;">
    <h1>Certificate of Completion</h1>
    <p>This certifies that <strong>{{name}}</strong></p>
    <p>Has completed the course.</p>
    <p>Code: {{auth_code}}</p>
</div>';
        
        wp_send_json_success( array( 'html' => $html ) );
    }

    /**
     * AJAX: Gerar Tickets (Ainda necessário?)
     * Mantido para compatibilidade se o usuário usar o recurso de sorteio.
     */
    public function ajax_generate_codes() {
        // Implementação simplificada ou removida se não usar Tickets
        wp_send_json_success( array( 'message' => 'Not implemented in this version.' ) );
    }
}
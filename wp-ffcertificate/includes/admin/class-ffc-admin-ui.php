<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * FFC_Admin_UI
 * Responsável apenas pela renderização HTML das metaboxes no Admin.
 */
class FFC_Admin_UI {

    /**
     * Renderiza a metabox de construção do formulário (Campos).
     */
    public function render_fields_metabox( $post ) {
        // SEGURANÇA: Nonce principal
        wp_nonce_field( 'ffc_save_form_data', 'ffc_form_nonce' );

        $fields = get_post_meta( $post->ID, '_ffc_form_fields', true );
        if ( ! is_array( $fields ) ) {
            $fields = array();
        }
        ?>
        <div id="ffc-form-builder">
            <p class="description"><?php esc_html_e( 'Drag and drop to reorder fields.', 'ffc' ); ?></p>
            
            <ul id="ffc-fields-container">
                <?php
                if ( ! empty( $fields ) ) {
                    foreach ( $fields as $index => $field ) {
                        $this->render_single_field_row( $index, $field );
                    }
                }
                ?>
            </ul>

            <div style="margin-top: 20px; border-top: 1px solid #ddd; padding-top: 10px;">
                <button type="button" class="button button-primary ffc-add-field"><?php esc_html_e( '+ Add Field', 'ffc' ); ?></button>
            </div>

            <li class="ffc-field-row ffc-field-template" style="display:none;">
                <div class="ffc-field-header">
                    <span class="dashicons dashicons-move ffc-sort-handle"></span>
                    <span class="ffc-field-label-preview"><?php esc_html_e( 'New Field', 'ffc' ); ?></span>
                    <div class="ffc-field-actions">
                        <button type="button" class="button-link ffc-toggle-field"><span class="dashicons dashicons-arrow-down-alt2"></span></button>
                        <span class="dashicons dashicons-trash ffc-remove-field" title="<?php esc_attr_e( 'Remove', 'ffc' ); ?>"></span>
                    </div>
                </div>
                <div class="ffc-field-body">
                    <?php $this->render_single_field_row( 'TEMPLATE_INDEX', array(), true ); ?>
                </div>
            </li>
        </div>
        <?php
    }

    /**
     * Helper para renderizar uma única linha de campo.
     */
    private function render_single_field_row( $index, $field_data, $is_template = false ) {
        $type  = isset( $field_data['type'] ) ? $field_data['type'] : 'text';
        $label = isset( $field_data['label'] ) ? $field_data['label'] : '';
        $name  = isset( $field_data['name'] ) ? $field_data['name'] : '';
        $req   = ! empty( $field_data['required'] );
        $opts  = isset( $field_data['options'] ) ? $field_data['options'] : '';
        
        $prefix = $is_template ? 'ffc_fields[TEMPLATE_INDEX]' : "ffc_fields[{$index}]";
        
        if ( ! $is_template ) : ?>
            <li class="ffc-field-row">
                <div class="ffc-field-header">
                    <span class="dashicons dashicons-move ffc-sort-handle"></span>
                    <strong><span class="ffc-field-label-preview"><?php echo esc_html( $label ? $label : __( '(No Label)', 'ffc' ) ); ?></span></strong>
                    <span style="color:#888; margin-left:10px;">(<?php echo esc_html( $name ); ?>)</span>
                    <div class="ffc-field-actions">
                        <button type="button" class="button-link ffc-toggle-field"><span class="dashicons dashicons-arrow-down-alt2"></span></button>
                        <span class="dashicons dashicons-trash ffc-remove-field" style="color: #d63638; cursor: pointer;"></span>
                    </div>
                </div>
                <div class="ffc-field-body" style="display: none;"> 
        <?php endif; ?>

            <table class="form-table" style="margin:0;">
                <tr>
                    <th><label><?php esc_html_e( 'Label', 'ffc' ); ?></label></th>
                    <td><input type="text" name="<?php echo $prefix; ?>[label]" value="<?php echo esc_attr( $label ); ?>" class="widefat ffc-field-label-input"></td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e( 'Field Name (Key)', 'ffc' ); ?></label></th>
                    <td>
                        <input type="text" name="<?php echo $prefix; ?>[name]" value="<?php echo esc_attr( $name ); ?>" class="widefat">
                        <p class="description"><?php esc_html_e( 'Unique identifier (e.g., student_name).', 'ffc' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e( 'Type', 'ffc' ); ?></label></th>
                    <td>
                        <select name="<?php echo $prefix; ?>[type]" class="ffc-field-type-select">
                            <option value="text" <?php selected( $type, 'text' ); ?>>Text</option>
                            <option value="email" <?php selected( $type, 'email' ); ?>>Email</option>
                            <option value="number" <?php selected( $type, 'number' ); ?>>Number</option>
                            <option value="date" <?php selected( $type, 'date' ); ?>>Date</option>
                            <option value="textarea" <?php selected( $type, 'textarea' ); ?>>Textarea</option>
                            <option value="select" <?php selected( $type, 'select' ); ?>>Select (Dropdown)</option>
                            <option value="radio" <?php selected( $type, 'radio' ); ?>>Radio Buttons</option>
                        </select>
                    </td>
                </tr>
                <tr class="ffc-options-field" style="<?php echo ( $type === 'select' || $type === 'radio' ) ? '' : 'display:none;'; ?>">
                    <th><label><?php esc_html_e( 'Options', 'ffc' ); ?></label></th>
                    <td>
                        <textarea name="<?php echo $prefix; ?>[options]" rows="3" class="widefat" placeholder="Option 1, Option 2"><?php echo esc_textarea( $opts ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'Comma separated values.', 'ffc' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th></th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo $prefix; ?>[required]" value="1" <?php checked( $req ); ?>>
                            <?php esc_html_e( 'Required Field', 'ffc' ); ?>
                        </label>
                    </td>
                </tr>
            </table>

        <?php if ( ! $is_template ) : ?>
                </div>
            </li>
        <?php endif; ?>
        <?php
    }

    /**
     * Renderiza a metabox de Configurações Gerais e Layout do PDF.
     */
    public function render_config_metabox( $post ) {
        $config = get_post_meta( $post->ID, '_ffc_form_config', true );
        if ( ! is_array( $config ) ) $config = array();

        // Extração de variáveis com defaults para evitar notices
        $success_msg = isset( $config['success_message'] ) ? $config['success_message'] : '';
        $send_email  = isset( $config['send_user_email'] ) ? $config['send_user_email'] : 0;
        $bg_image    = isset( $config['background_image'] ) ? $config['background_image'] : '';
        $pdf_layout  = isset( $config['pdf_layout'] ) ? $config['pdf_layout'] : '';
        
        $restriction_enabled = isset( $config['enable_restriction'] ) ? $config['enable_restriction'] : 0;
        $allowed_users       = isset( $config['allowed_users_list'] ) ? $config['allowed_users_list'] : '';
        $generated_codes     = isset( $config['generated_codes_list'] ) ? $config['generated_codes_list'] : '';
        $denied_users        = isset( $config['denied_users_list'] ) ? $config['denied_users_list'] : '';
        $validation_code     = isset( $config['validation_code'] ) ? $config['validation_code'] : '';
        
        // Dados de email
        $email_admin   = isset( $config['email_admin'] ) ? $config['email_admin'] : get_option('admin_email');
        $email_subject = isset( $config['email_subject'] ) ? $config['email_subject'] : 'Seu Certificado';
        $email_body    = isset( $config['email_body'] ) ? $config['email_body'] : 'Olá, segue em anexo seu certificado.';

        ?>
        <div class="ffc-metabox-tabs">
            <ul class="ffc-tabs-nav">
                <li data-tab="ffc-tab-general" class="active"><?php esc_html_e( 'General & Email', 'ffc' ); ?></li>
                <li data-tab="ffc-tab-restrictions"><?php esc_html_e( 'Restrictions', 'ffc' ); ?></li>
                <li data-tab="ffc-tab-pdf"><?php esc_html_e( 'PDF Layout', 'ffc' ); ?></li>
            </ul>

            <div id="ffc-tab-general" class="ffc-tab-content active">
                <p>
                    <label><strong><?php esc_html_e( 'Success Message:', 'ffc' ); ?></strong></label><br>
                    <textarea name="ffc_config[success_message]" rows="3" class="widefat"><?php echo esc_textarea( $success_msg ); ?></textarea>
                </p>
                <hr>
                <p><strong><?php esc_html_e( 'Email Settings', 'ffc' ); ?></strong></p>
                <p>
                    <label>
                        <input type="checkbox" name="ffc_config[send_user_email]" value="1" <?php checked( $send_email, 1 ); ?>>
                        <?php esc_html_e( 'Send PDF via Email?', 'ffc' ); ?>
                    </label>
                </p>
                <p>
                    <label><?php esc_html_e( 'Sender Email (From):', 'ffc' ); ?></label>
                    <input type="email" name="ffc_config[email_admin]" value="<?php echo esc_attr( $email_admin ); ?>" class="widefat">
                </p>
                <p>
                    <label><?php esc_html_e( 'Email Subject:', 'ffc' ); ?></label>
                    <input type="text" name="ffc_config[email_subject]" value="<?php echo esc_attr( $email_subject ); ?>" class="widefat">
                </p>
                <p>
                    <label><?php esc_html_e( 'Email Body:', 'ffc' ); ?></label>
                    <textarea name="ffc_config[email_body]" rows="3" class="widefat"><?php echo wp_kses_post( $email_body ); ?></textarea>
                </p>
            </div>

            <div id="ffc-tab-restrictions" class="ffc-tab-content">
                <p>
                    <label>
                        <input type="checkbox" name="ffc_config[enable_restriction]" value="1" <?php checked( $restriction_enabled, 1 ); ?>>
                        <strong><?php esc_html_e( 'Enable Access Restriction?', 'ffc' ); ?></strong>
                    </label>
                </p>
                
                <div class="ffc-restriction-options">
                    <hr>
                    <p><strong><?php esc_html_e( 'Method A: Allowed List', 'ffc' ); ?></strong></p>
                    <textarea name="ffc_config[allowed_users_list]" rows="4" class="widefat" placeholder="CPF/ID..."><?php echo esc_textarea( $allowed_users ); ?></textarea>
                    
                    <hr>
                    <p><strong><?php esc_html_e( 'Method B: Tickets', 'ffc' ); ?></strong></p>
                    <button type="button" class="button" id="ffc_generate_tickets"><?php esc_html_e( 'Generate 50 Tickets', 'ffc' ); ?></button>
                    <span class="spinner" id="ffc_ticket_spinner"></span>
                    <br><br>
                    <textarea name="ffc_config[generated_codes_list]" id="ffc_generated_codes" rows="4" class="widefat"><?php echo esc_textarea( $generated_codes ); ?></textarea>
                    
                    <hr>
                    <p><strong><?php esc_html_e( 'Method C: Global Password', 'ffc' ); ?></strong></p>
                    <input type="text" name="ffc_config[validation_code]" value="<?php echo esc_attr( $validation_code ); ?>" class="widefat">
                    
                    <hr>
                    <p><strong><?php esc_html_e( 'Denylist', 'ffc' ); ?></strong></p>
                    <textarea name="ffc_config[denied_users_list]" rows="3" class="widefat"><?php echo esc_textarea( $denied_users ); ?></textarea>
                </div>
            </div>

            <div id="ffc-tab-pdf" class="ffc-tab-content">
                <p>
                    <label><strong><?php esc_html_e( 'Background Image URL:', 'ffc' ); ?></strong></label><br>
                    <input type="text" name="ffc_config[background_image]" id="ffc_bg_image" value="<?php echo esc_url( $bg_image ); ?>" class="widefat">
                    <button type="button" class="button ffc-upload-img"><?php esc_html_e( 'Select Image', 'ffc' ); ?></button>
                </p>

                <hr>
                <div class="ffc-template-actions" style="margin-bottom: 10px;">
                    <button type="button" class="button" id="ffc_btn_import_html"><span class="dashicons dashicons-upload"></span> <?php esc_html_e( 'Import HTML', 'ffc' ); ?></button>
                    <input type="file" id="ffc_import_html_file" accept=".html,.txt" style="display: none;">
                    
                    <select id="ffc_template_select">
                        <option value=""><?php esc_html_e( '-- Select Template --', 'ffc' ); ?></option>
                        <?php 
                        $templates_dir = FFC_PLUGIN_DIR . 'templates/layouts/';
                        if ( is_dir( $templates_dir ) ) {
                            foreach ( glob( $templates_dir . '*.html' ) as $file ) {
                                $name = basename( $file, '.html' );
                                echo '<option value="' . esc_attr( $name ) . '">' . esc_html( ucfirst($name) ) . '</option>';
                            }
                        }
                        ?>
                    </select>
                    <button type="button" class="button" id="ffc_load_template_btn"><?php esc_html_e( 'Load', 'ffc' ); ?></button>
                    <span class="spinner" id="ffc_template_spinner"></span>
                </div>

                <textarea name="ffc_config[pdf_layout]" id="ffc_pdf_layout" rows="15" class="widefat code"><?php echo esc_textarea( $pdf_layout ); ?></textarea>
                <p class="description"><?php esc_html_e( 'HTML/CSS. Tags: {{name}}, {{date}}, {{auth_code}}.', 'ffc' ); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Renderiza a metabox de Submissões (Tabela).
     * [ATUALIZADO]: Agora aceita o Repository e controla a tela de Edição.
     */
    public function render_results_metabox( $post, $repository = null ) {
        
        // Verifica se estamos em modo de EDIÇÃO (query string action=edit_submission)
        // Isso permite alternar entre a Tabela e o Formulário de Edição
        $action        = isset( $_GET['ffc_action'] ) ? sanitize_key( $_GET['ffc_action'] ) : '';
        $submission_id = isset( $_GET['submission_id'] ) ? absint( $_GET['submission_id'] ) : 0;

        // --- MODO EDIÇÃO ---
        if ( 'edit_submission' === $action && $submission_id && $repository ) {
            $submission = $repository->get_by_id( $submission_id );
            
            if ( ! $submission ) {
                echo '<p>' . __( 'Submission not found.', 'ffc' ) . '</p>';
                echo '<a href="' . admin_url( 'post.php?post=' . $post->ID . '&action=edit' ) . '" class="button">' . __( 'Back', 'ffc' ) . '</a>';
                return;
            }

            // Decodifica JSON
            $data = json_decode( $submission['data'], true );
            ?>
            <div class="ffc-edit-submission-wrap">
                <h3><?php esc_html_e( 'Edit Submission', 'ffc' ); ?> #<?php echo $submission_id; ?></h3>
                
                <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
                    <input type="hidden" name="action" value="ffc_handle_submission_edit"> 
                    <input type="hidden" name="ffc_action" value="edit_submission">
                    <input type="hidden" name="form_id" value="<?php echo $post->ID; ?>">
                    <input type="hidden" name="submission_id" value="<?php echo $submission_id; ?>">
                    <?php wp_nonce_field( 'ffc_edit_submission_nonce' ); ?>

                    <table class="form-table">
                        <tr>
                            <th>ID</th>
                            <td><input type="text" disabled value="<?php echo $submission_id; ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th>Email (Sistema)</th>
                            <td><input type="text" disabled value="<?php echo esc_attr( $submission['email'] ); ?>" class="regular-text"></td>
                        </tr>
                        <?php 
                        if ( is_array( $data ) ) {
                            foreach ( $data as $key => $value ) {
                                ?>
                                <tr>
                                    <th><?php echo esc_html( ucfirst( $key ) ); ?></th>
                                    <td>
                                        <input type="text" name="submission_data[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" class="widefat">
                                    </td>
                                </tr>
                                <?php
                            }
                        } else {
                            echo '<tr><td colspan="2">Invalid JSON Data</td></tr>';
                        }
                        ?>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php esc_html_e( 'Save Changes', 'ffc' ); ?></button>
                        <a href="<?php echo admin_url( 'post.php?post=' . $post->ID . '&action=edit' ); ?>" class="button button-secondary"><?php esc_html_e( 'Cancel', 'ffc' ); ?></a>
                    </p>
                </form>
            </div>
            <?php
            return; // Encerra aqui para não mostrar a tabela
        }

        // --- MODO TABELA (Padrão) ---
        if ( ! class_exists( 'FFC_Submission_List' ) ) {
            // Ajuste o caminho conforme sua estrutura real. Assumindo que está em includes/admin
            // Se estiver em includes/data ou includes, ajuste aqui.
            require_once FFC_PLUGIN_DIR . 'includes/class-ffc-submissions-list-table.php';
        }

        // Passamos o repositório para a Tabela
        $list_table = new FFC_Submission_List( $repository );
        $list_table->prepare_items();
        
        echo '<div class="wrap">';
        echo '<form method="get">';
        echo '<input type="hidden" name="post" value="' . esc_attr( $post->ID ) . '">';
        echo '<input type="hidden" name="action" value="edit">';
        
        $list_table->search_box( __( 'Search', 'ffc' ), 'search_id' );
        $list_table->display();
        
        echo '</form>';
        echo '</div>';
        
        // Botão Exportar
        echo '<div style="margin-top: 20px; border-top: 1px solid #ddd; padding-top: 10px;">';
        echo '<form method="post" action="' . admin_url( 'admin-post.php' ) . '">';
        echo '<input type="hidden" name="action" value="ffc_export_csv">';
        echo '<input type="hidden" name="form_id" value="' . esc_attr( $post->ID ) . '">';
        wp_nonce_field( 'ffc_export_csv_nonce' );
        submit_button( __( 'Export CSV', 'ffc' ), 'secondary', 'ffc_export_csv', false );
        echo '</form>';
        echo '</div>';
    }
    
    /**
     * Renderiza a metabox com os Shortcodes.
     */
    public function render_shortcodes_metabox( $post ) {
        ?>
        <p><?php esc_html_e( 'Copy and paste:', 'ffc' ); ?></p>
        <p>
            <strong><?php esc_html_e( 'Form:', 'ffc' ); ?></strong><br>
            <code>[ffc_form id="<?php echo $post->ID; ?>"]</code>
        </p>
        <p>
            <strong><?php esc_html_e( 'Verification:', 'ffc' ); ?></strong><br>
            <code>[ffc_verification]</code>
        </p>
        <?php
    }
}
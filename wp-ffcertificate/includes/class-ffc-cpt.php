<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_CPT {

    public function __construct() {
        add_action( 'init', array( $this, 'register_form_cpt' ) );
        add_action( 'add_meta_boxes', array( $this, 'add_form_tabs_metabox' ) );
        add_action( 'save_post', array( $this, 'save_form_meta_boxes' ), 10, 2 );
        add_filter( 'post_row_actions', array( $this, 'add_duplicate_link' ), 10, 2 );
        add_action( 'admin_action_ffc_duplicate_form', array( $this, 'handle_form_duplication' ) );
    }
    
    public function register_form_cpt() {
        $labels = array(
            'name'          => _x( 'Forms', 'Post Type General Name', 'ffc' ),
            'singular_name' => _x( 'Form', 'Post Type Singular Name', 'ffc' ),
            'menu_name'     => __( 'Free Form Certificate', 'ffc' ),
            'add_new'       => __( 'Add New Form', 'ffc' ),
            'all_items'     => __( 'All Forms', 'ffc' ),
        );

        $args = array(
            'labels'       => $labels,
            'public'       => false, 
            'show_ui'      => true,
            'show_in_menu' => true,
            'menu_icon'    => 'dashicons-feedback',
            'supports'     => array( 'title' ),
            'rewrite'      => array( 'slug' => 'ffc-form' ),
        );

        register_post_type( 'ffc_form', $args );
    }

    public function add_form_tabs_metabox() {
        // Main Metabox (Builder)
        add_meta_box(
            'ffc_form_builder',
            __( 'Form Builder and Configuration', 'ffc' ),
            array( $this, 'display_metabox_tabs_content' ),
            'ffc_form',
            'normal',
            'high'
        );

        // Side Metabox for Shortcode and Instructions
        add_meta_box(
            'ffc_form_shortcode',
            __( 'How to Use / Shortcode', 'ffc' ),
            array( $this, 'display_shortcode_metabox' ),
            'ffc_form',
            'side',
            'high'
        );
    }

    // --- CONTENT OF THE SHORTCODE METABOX ---
    public function display_shortcode_metabox( $post ) {
        ?>
        <div style="background: #f0f0f1; padding: 10px; border-radius: 4px; border: 1px solid #c3c4c7;">
            <p><strong><?php _e( 'Copy this Shortcode:', 'ffc' ); ?></strong></p>
            <code style="display:block; padding: 8px; background: #fff; border: 1px solid #ddd; margin-bottom: 10px; user-select: all;">
                [ffc_form id="<?php echo $post->ID; ?>"]
            </code>
            <p class="description">
                <?php _e( 'Paste this code into any Page or Post to display the form.', 'ffc' ); ?>
            </p>
        </div>
        <hr>
        <p><strong><?php _e( 'Tips:', 'ffc' ); ?></strong></p>
        <ul style="list-style: disc; padding-left: 20px; font-size: 12px; color: #666;">
            <li><?php _e( 'Use <b>{{field_name}}</b> in the PDF Layout to insert user data.', 'ffc' ); ?></li>
            <li><?php _e( 'For dates, the variable is usually {{date}}.', 'ffc' ); ?></li>
        </ul>
        <?php
    }

    // --- CONTENT OF THE MAIN METABOX ---
    public function display_metabox_tabs_content( $post ) {
        wp_nonce_field( 'ffc_form_meta_box', 'ffc_form_meta_box_nonce' );

        echo '<div class="ffc-metabox-tabs">';
        echo '<ul class="ffc-tabs-nav">';
        echo '<li data-tab="ffc-fields-tab" class="nav-tab-active">' . esc_html__( 'Form Fields Builder', 'ffc' ) . '</li>';
        echo '<li data-tab="ffc-config-tab">' . esc_html__( 'Form Configuration', 'ffc' ) . '</li>';
        echo '</ul>';

        echo '<div id="ffc-fields-tab" class="ffc-metabox-tab-content">';
        $this->display_fields_builder_content( $post );
        echo '</div>';

        echo '<div id="ffc-config-tab" class="ffc-metabox-tab-content" style="display:none;">';
        $this->display_config_content( $post );
        echo '</div>';
        
        echo '</div>';
    }

    public function display_fields_builder_content( $post ) {
        $fields = get_post_meta( $post->ID, '_ffc_form_fields', true );
        
        if ( ! is_array( $fields ) ) {
            $fields = array();
        }

        echo '<div id="ffc-fields-container">';
        
        $max_index = 0;
        if ( ! empty( $fields ) ) {
            foreach ( $fields as $i => $field ) {
                $max_index = max( $max_index, $i );
                $this->render_field_row( $i, $field );
            }
        }
        
        echo '</div>';
        echo '<div class="ffc-add-field-actions">';
        echo '<button type="button" class="button button-secondary ffc-add-field" data-max-index="' . esc_attr( $max_index ) . '">' . esc_html__( 'Add New Field', 'ffc' ) . '</button>';
        echo '</div>';

        $this->render_field_row( '{{index}}', array(), true );
    }

    private function render_field_row( $index, $field_data, $is_template = false ) {
        $name     = isset( $field_data['name'] ) ? $field_data['name'] : '';
        $label    = isset( $field_data['label'] ) ? $field_data['label'] : '';
        $type     = isset( $field_data['type'] ) ? $field_data['type'] : 'text';
        $options  = isset( $field_data['options'] ) ? $field_data['options'] : '';
        $required = isset( $field_data['required'] ) ? (bool) $field_data['required'] : false;

        $input_name_prefix = "ffc_fields[$index]";
        $row_class         = 'ffc-field-row';
        $style             = '';

        if ( $is_template ) {
            $row_class .= ' ffc-field-template';
            $style      = 'display:none;';
        }

        ?>
        <div class="<?php echo esc_attr( $row_class ); ?>" style="<?php echo esc_attr( $style ); ?>">
            <div class="ffc-field-group ffc-sort-handle" style="cursor: move;">
                <span class="dashicons dashicons-menu"></span>
            </div>
            <div class="ffc-field-group">
                <input type="text" name="<?php echo esc_attr( $input_name_prefix . '[label]' ); ?>" value="<?php echo esc_attr( $label ); ?>" placeholder="<?php esc_attr_e( 'Label', 'ffc' ); ?>">
            </div>
            <div class="ffc-field-group">
                <input type="text" name="<?php echo esc_attr( $input_name_prefix . '[name]' ); ?>" value="<?php echo esc_attr( $name ); ?>" placeholder="<?php esc_attr_e( 'Name (key)', 'ffc' ); ?>">
            </div>
            <div class="ffc-field-group">
                <select name="<?php echo esc_attr( $input_name_prefix . '[type]' ); ?>" class="ffc-field-type-select">
                    <option value="text" <?php selected( $type, 'text' ); ?>><?php esc_html_e( 'Text', 'ffc' ); ?></option>
                    <option value="number" <?php selected( $type, 'number' ); ?>><?php esc_html_e( 'Number', 'ffc' ); ?></option>
                    <option value="email" <?php selected( $type, 'email' ); ?>><?php esc_html_e( 'Email', 'ffc' ); ?></option>
                    <option value="textarea" <?php selected( $type, 'textarea' ); ?>><?php esc_html_e( 'Textarea', 'ffc' ); ?></option>
                    <option value="select" <?php selected( $type, 'select' ); ?>><?php esc_html_e( 'Select', 'ffc' ); ?></option>
                    <option value="radio" <?php selected( $type, 'radio' ); ?>><?php esc_html_e( 'Radio', 'ffc' ); ?></option>
                    <option value="date" <?php selected( $type, 'date' ); ?>><?php esc_html_e( 'Date', 'ffc' ); ?></option>
                </select>
            </div>
            <div class="ffc-field-group ffc-options-field" style="<?php echo ( in_array( $type, array( 'select', 'radio' ) ) ) ? '' : 'display:none;'; ?>">
                <input type="text" name="<?php echo esc_attr( $input_name_prefix . '[options]' ); ?>" value="<?php echo esc_attr( $options ); ?>" placeholder="<?php esc_attr_e( 'Option1,Option2,...', 'ffc' ); ?>">
            </div>
            <div class="ffc-field-group" style="text-align: center;">
                <label><input type="checkbox" name="<?php echo esc_attr( $input_name_prefix . '[required]' ); ?>" value="1" <?php checked( $required, true ); ?>> <?php esc_html_e( 'Req?', 'ffc' ); ?></label>
            </div>
            <div class="ffc-field-group" style="text-align: right;">
                <button type="button" class="button button-small ffc-remove-field" title="<?php esc_attr_e( 'Remove', 'ffc' ); ?>"><span class="dashicons dashicons-trash"></span></button>
            </div>
        </div>
        <?php
    }

    public function display_config_content( $post ) {
        $config = get_post_meta( $post->ID, '_ffc_form_config', true );
        $config = wp_parse_args( $config, array(
            'success_message'    => '',
            'email_subject'      => '',
            'email_body'         => '',
            'email_admin'        => '',
            'pdf_layout'         => '',
            'send_user_email'    => 0,
            'enable_restriction' => 0,
            'allowed_users_list' => ''
        ) );

        ?>
        <p>
            <label for="ffc_success_message"><strong><?php esc_html_e( 'Success Message', 'ffc' ); ?></strong></label><br>
            <input type="text" id="ffc_success_message" name="ffc_config[success_message]" value="<?php echo esc_attr( $config['success_message'] ); ?>" class="widefat">
        </p>
        <p>
            <label for="ffc_email_admin"><strong><?php esc_html_e( 'Admin Notification Emails (comma separated)', 'ffc' ); ?></strong></label><br>
            <input type="text" id="ffc_email_admin" name="ffc_config[email_admin]" value="<?php echo esc_attr( $config['email_admin'] ); ?>" class="widefat">
        </p>

        <hr>
        <h3><?php esc_html_e( 'Access Control (Pre-Approval List)', 'ffc' ); ?></h3>
        <p>
            <label>
                <input type="checkbox" name="ffc_config[enable_restriction]" value="1" <?php checked( $config['enable_restriction'], 1 ); ?>>
                <strong><?php esc_html_e( 'Restrict submission by Pre-Approval List?', 'ffc' ); ?></strong>
            </label>
        </p>
        <p class="description">
            <?php esc_html_e( 'If checked, only users listed below can generate the certificate. The form MUST have a field with the "Name Attribute" set to "cpf_rf".', 'ffc' ); ?>
        </p>

        <p>
            <label for="ffc_allowed_users_list"><strong><?php esc_html_e( 'List of Allowed Users (CPF/RF)', 'ffc' ); ?>:</strong></label><br>
            <span class="description"><?php esc_html_e( 'Enter one number per line.', 'ffc' ); ?></span>
            <textarea name="ffc_config[allowed_users_list]" id="ffc_allowed_users_list" rows="10" class="widefat code"><?php echo esc_textarea( $config['allowed_users_list'] ); ?></textarea>
        </p>

        <hr>
        <h3><?php esc_html_e( 'Certificate Configuration', 'ffc' ); ?></h3>
        
        <p>
            <label for="ffc_send_user_email">
                <input type="checkbox" id="ffc_send_user_email" name="ffc_config[send_user_email]" value="1" <?php checked( $config['send_user_email'], 1 ); ?>>
                <strong><?php esc_html_e( 'Send Certificate via Email', 'ffc' ); ?></strong>
            </label>
            <br>
            <span class="description" style="color: #d63638;">
                <?php esc_html_e( 'Warning: Enabling this may slow down the submission process significantly depending on your server speed. Not recommended if you expect high traffic. The user can download the certificate immediately on the screen.', 'ffc' ); ?>
            </span>
        </p>

        <p>
            <label for="ffc_email_subject"><strong><?php esc_html_e( 'Email Subject', 'ffc' ); ?></strong></label><br>
            <input type="text" id="ffc_email_subject" name="ffc_config[email_subject]" value="<?php echo esc_attr( $config['email_subject'] ); ?>" class="widefat">
        </p>
        <p>
            <label for="ffc_email_body"><strong><?php esc_html_e( 'Email Body', 'ffc' ); ?></strong></label><br>
            <?php wp_editor( $config['email_body'], 'ffc_email_body_editor', array( 'textarea_name' => 'ffc_config[email_body]', 'textarea_rows' => 5, 'media_buttons' => false ) ); ?>
        </p>
        <p>
            <label for="ffc_pdf_layout"><strong><?php esc_html_e( 'PDF Layout HTML', 'ffc' ); ?></strong></label><br>
            <span class="description"><?php esc_html_e( 'Use placeholders like {{field_name}}.', 'ffc' ); ?></span>
            <textarea id="ffc_pdf_layout" name="ffc_config[pdf_layout]" rows="10" class="widefat code"><?php echo esc_textarea( $config['pdf_layout'] ); ?></textarea>
        </p>
        <?php
    }

    public function save_form_meta_boxes( $post_id ) {
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Save Fields
        if ( isset( $_POST['ffc_fields'] ) && is_array( $_POST['ffc_fields'] ) ) {
            $clean_fields = array();
            foreach ( $_POST['ffc_fields'] as $field ) {
                if ( empty( $field['label'] ) && empty( $field['name'] ) ) continue;
                
                $clean_fields[] = array(
                    'label'    => sanitize_text_field( $field['label'] ),
                    'name'     => sanitize_key( $field['name'] ),
                    'type'     => sanitize_text_field( $field['type'] ),
                    'options'  => sanitize_text_field( $field['options'] ),
                    'required' => isset( $field['required'] ) ? 1 : 0,
                );
            }
            update_post_meta( $post_id, '_ffc_form_fields', $clean_fields );
        } else {
             update_post_meta( $post_id, '_ffc_form_fields', array() );
        }

        // Save Configurations
        if ( isset( $_POST['ffc_config'] ) && is_array( $_POST['ffc_config'] ) ) {
            $config = array(
                'success_message' => sanitize_text_field( $_POST['ffc_config']['success_message'] ),
                'email_admin'     => sanitize_text_field( $_POST['ffc_config']['email_admin'] ),
                'email_subject'   => sanitize_text_field( $_POST['ffc_config']['email_subject'] ),
                'email_body'      => wp_kses_post( $_POST['ffc_config']['email_body'] ),
                // Preserves HTML if you are an admin
                'pdf_layout'      => current_user_can( 'unfiltered_html' ) ? $_POST['ffc_config']['pdf_layout'] : wp_kses_post( $_POST['ffc_config']['pdf_layout'] ),
                'send_user_email' => isset( $_POST['ffc_config']['send_user_email'] ) ? 1 : 0,
                
                // RESTRICTION
                'enable_restriction' => isset( $_POST['ffc_config']['enable_restriction'] ) ? 1 : 0,
                'allowed_users_list' => sanitize_textarea_field( $_POST['ffc_config']['allowed_users_list'] ),
            );
            update_post_meta( $post_id, '_ffc_form_config', $config );
        }
    }

    public function add_duplicate_link( $actions, $post ) {
        if ( $post->post_type !== 'ffc_form' ) {
            return $actions;
        }
        
        $url = wp_nonce_url(
            admin_url( 'admin.php?action=ffc_duplicate_form&post=' . $post->ID ),
            'ffc_duplicate_form_nonce'
        );
        
        $actions['duplicate'] = '<a href="' . esc_url( $url ) . '" title="' . esc_attr__( 'Duplicate this form', 'ffc' ) . '">' . __( 'Duplicate', 'ffc' ) . '</a>';
        
        return $actions;
    }

    public function handle_form_duplication() {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( esc_html__( 'You do not have permission to duplicate this post.', 'ffc' ) );
        }

        $post_id = ( isset( $_GET['post'] ) ? absint( $_GET['post'] ) : absint( $_POST['post'] ) );
        
        check_admin_referer( 'ffc_duplicate_form_nonce' );

        $post = get_post( $post_id );
        
        if ( ! $post || $post->post_type !== 'ffc_form' ) {
            wp_die( esc_html__( 'Invalid post.', 'ffc' ) );
        }

        $new_post_args = array(
            'post_title'  => sprintf( __( '%s (Copy)', 'ffc' ), $post->post_title ),
            'post_status' => 'draft',
            'post_type'   => $post->post_type,
            'post_author' => get_current_user_id(),
        );

        $new_post_id = wp_insert_post( $new_post_args );

        if ( is_wp_error( $new_post_id ) ) {
            wp_die( $new_post_id->get_error_message() );
        }

        $fields = get_post_meta( $post_id, '_ffc_form_fields', true );
        $config = get_post_meta( $post_id, '_ffc_form_config', true );

        update_post_meta( $new_post_id, '_ffc_form_fields', $fields );
        update_post_meta( $new_post_id, '_ffc_form_config', $config );

        wp_redirect( admin_url( 'edit.php?post_type=ffc_form' ) );
        exit;
    }
}
<?php
/**
 * FFC_Form_Editor
 * Handles the advanced UI for the Form Builder, including AJAX and layout management.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Form_Editor {

    private $metabox_renderer; // ✅ v3.1.1: Metabox Renderer

    public function __construct() {
        // ✅ v3.1.1: Initialize Metabox Renderer (extracted from FFC_Form_Editor)
        require_once plugin_dir_path( __FILE__ ) . 'class-ffc-form-editor-metabox-renderer.php';
        $this->metabox_renderer = new FFC_Form_Editor_Metabox_Renderer();

        add_action( 'add_meta_boxes', array( $this, 'add_custom_metaboxes' ), 20 );
        add_action( 'save_post', array( $this, 'save_form_data' ) );
        add_action( 'admin_notices', array( $this, 'display_save_errors' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

        // AJAX handlers for the editor
        add_action( 'wp_ajax_ffc_generate_codes', array( $this, 'ajax_generate_random_codes' ) );
        add_action( 'wp_ajax_ffc_load_template', array( $this, 'ajax_load_template' ) );
    }

    /**
     * Enqueue scripts and styles for form editor
     */
    public function enqueue_scripts($hook) {
        // Only load on form edit page
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ) ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== 'ffc_form' ) {
            return;
        }

        wp_enqueue_script(
            'ffc-geofence-admin',
            FFC_PLUGIN_URL . 'assets/js/ffc-geofence-admin.js',
            array( 'jquery' ),
            FFC_VERSION,
            true
        );

        wp_localize_script(
            'ffc-geofence-admin',
            'ffc_geofence_admin',
            array(
                'alert_message' => __( 'At least one geolocation method (GPS or IP) must be enabled when geolocation is active.', 'ffc' )
            )
        );
    }

    /**
     * Registers all metaboxes for the Form CPT
     *
     * ✅ v3.1.1: Delegates rendering to FFC_Form_Editor_Metabox_Renderer
     */
    public function add_custom_metaboxes() {
        // Remove any potential duplicates
        remove_meta_box( 'ffc_form_builder', 'ffc_form', 'normal' );
        remove_meta_box( 'ffc_form_config', 'ffc_form', 'normal' );
        remove_meta_box( 'ffc_builder_box', 'ffc_form', 'normal' );

        // Main metaboxes (content area) - Delegated to Metabox Renderer
        add_meta_box(
            'ffc_box_layout',
            __( '1. Certificate Layout', 'ffc' ),
            array( $this->metabox_renderer, 'render_box_layout' ),
            'ffc_form',
            'normal',
            'high'
        );

        add_meta_box(
            'ffc_box_builder',
            __( '2. Form Builder (Fields)', 'ffc' ),
            array( $this->metabox_renderer, 'render_box_builder' ),
            'ffc_form',
            'normal',
            'high'
        );

        add_meta_box(
            'ffc_box_restriction',
            __( '3. Restriction & Security', 'ffc' ),
            array( $this->metabox_renderer, 'render_box_restriction' ),
            'ffc_form',
            'normal',
            'high'
        );

        add_meta_box(
            'ffc_box_email',
            __( '4. Email Configuration', 'ffc' ),
            array( $this->metabox_renderer, 'render_box_email' ),
            'ffc_form',
            'normal',
            'high'
        );

        add_meta_box(
            'ffc_box_geofence',
            __( '5. Geolocation & Date/Time Restrictions', 'ffc' ),
            array( $this->metabox_renderer, 'render_box_geofence' ),
            'ffc_form',
            'normal',
            'high'
        );

        // Sidebar metabox (shortcode + instructions) - Delegated to Metabox Renderer
        add_meta_box(
            'ffc_form_shortcode',
            __( 'How to Use / Shortcode', 'ffc' ),
            array( $this->metabox_renderer, 'render_shortcode_metabox' ),
            'ffc_form',
            'side',
            'high'
        );
    }

    /**
     * AJAX: Generates a list of unique ticket codes
     */
    public function ajax_generate_random_codes() {
        check_ajax_referer( 'ffc_admin_pdf_nonce', 'nonce' );
        
        if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error();

        $qty = isset($_POST['qty']) ? absint($_POST['qty']) : 10;
        $codes = array();
        for($i = 0; $i < $qty; $i++) {
            $rnd = strtoupper(bin2hex(random_bytes(4))); 
            $codes[] = substr($rnd, 0, 4) . '-' . substr($rnd, 4, 4);
        }
        wp_send_json_success( array( 'codes' => implode("\n", $codes) ) );
    }

    /**
     * AJAX: Loads a local HTML template from the plugin directory
     */
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

    /**
     * Saves all form data and configurations
     */
    public function save_form_data( $post_id ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! isset( $_POST['ffc_form_nonce'] ) || ! wp_verify_nonce( $_POST['ffc_form_nonce'], 'ffc_save_form_data' ) ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        // 1. Save Form Fields
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

        // 2. Save Configurations
        if ( isset( $_POST['ffc_config'] ) ) {
            $config = $_POST['ffc_config'];
            $allowed_html = method_exists('FFC_Utils', 'get_allowed_html_tags') ? FFC_Utils::get_allowed_html_tags() : wp_kses_allowed_html('post');

            $clean_config = array();
            $clean_config['pdf_layout'] = wp_kses( $config['pdf_layout'], $allowed_html );
            $clean_config['email_body'] = wp_kses( $config['email_body'], $allowed_html );
            $clean_config['bg_image']   = esc_url_raw( $config['bg_image'] );
            
            $clean_config['enable_restriction'] = sanitize_key( $config['enable_restriction'] );
            $clean_config['send_user_email']    = sanitize_key( $config['send_user_email'] );
            $clean_config['email_subject']      = sanitize_text_field( $config['email_subject'] );
            
            // ✅ v2.10.0: Restrictions (checkboxes)
            $clean_config['restrictions'] = array(
                'password'  => isset($config['restrictions']['password']) ? '1' : '0',
                'allowlist' => isset($config['restrictions']['allowlist']) ? '1' : '0',
                'denylist'  => isset($config['restrictions']['denylist']) ? '1' : '0',
                'ticket'    => isset($config['restrictions']['ticket']) ? '1' : '0'
            );
            
            $clean_config['allowed_users_list']   = sanitize_textarea_field( $config['allowed_users_list'] );
            $clean_config['denied_users_list']    = sanitize_textarea_field( $config['denied_users_list'] );
            $clean_config['validation_code']      = sanitize_text_field( $config['validation_code'] );
            $clean_config['generated_codes_list'] = sanitize_textarea_field( $config['generated_codes_list'] );

            // Tag Validation: Ensure the user didn't remove critical tags
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

        // 3. Save Geofence Configuration
        if ( isset( $_POST['ffc_geofence'] ) ) {
            $geofence = $_POST['ffc_geofence'];

            $clean_geofence = array(
                // DateTime settings
                'datetime_enabled' => isset($geofence['datetime_enabled']) ? '1' : '0',
                'date_start' => !empty($geofence['date_start']) ? sanitize_text_field($geofence['date_start']) : '',
                'date_end' => !empty($geofence['date_end']) ? sanitize_text_field($geofence['date_end']) : '',
                'time_start' => !empty($geofence['time_start']) ? sanitize_text_field($geofence['time_start']) : '',
                'time_end' => !empty($geofence['time_end']) ? sanitize_text_field($geofence['time_end']) : '',
                'time_mode' => sanitize_key($geofence['time_mode'] ?? 'daily'),
                'datetime_hide_mode' => sanitize_key($geofence['datetime_hide_mode'] ?? 'message'),
                'msg_datetime' => sanitize_textarea_field($geofence['msg_datetime'] ?? ''),

                // Geolocation settings
                'geo_enabled' => isset($geofence['geo_enabled']) ? '1' : '0',
                'geo_gps_enabled' => isset($geofence['geo_gps_enabled']) ? '1' : '0',
                'geo_ip_enabled' => isset($geofence['geo_ip_enabled']) ? '1' : '0',
                'geo_areas' => sanitize_textarea_field($geofence['geo_areas'] ?? ''),
                'geo_ip_areas_permissive' => isset($geofence['geo_ip_areas_permissive']) ? '1' : '0',
                'geo_ip_areas' => sanitize_textarea_field($geofence['geo_ip_areas'] ?? ''),
                'geo_gps_ip_logic' => sanitize_key($geofence['geo_gps_ip_logic'] ?? 'or'),
                'geo_hide_mode' => sanitize_key($geofence['geo_hide_mode'] ?? 'message'),
                'msg_geo_blocked' => sanitize_textarea_field($geofence['msg_geo_blocked'] ?? ''),
                'msg_geo_error' => sanitize_textarea_field($geofence['msg_geo_error'] ?? ''),
            );

            update_post_meta( $post_id, '_ffc_geofence_config', $clean_geofence );
        }
    }

    /**
     * Displays validation warnings after saving
     */
    public function display_save_errors() {
        $error_tags = get_transient( 'ffc_save_error_' . get_current_user_id() );
        if ( $error_tags ) {
            delete_transient( 'ffc_save_error_' . get_current_user_id() );
            ?>
            <div class="notice notice-error is-dismissible">
                <p><strong><?php esc_html_e( 'Warning! Missing required tags in PDF Layout:', 'ffc' ); ?></strong> <code><?php echo esc_html(implode( ', ', $error_tags )); ?></code>.</p>
            </div>
            <?php
        }
    }
}
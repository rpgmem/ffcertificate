<?php
/**
 * FFC_Form_Editor
 * Handles the advanced UI for the Form Builder, including AJAX and layout management.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Form_Editor {

    public function __construct() {
        add_action( 'add_meta_boxes', array( $this, 'add_custom_metaboxes' ), 20 );
        add_action( 'save_post', array( $this, 'save_form_data' ) );
        add_action( 'admin_notices', array( $this, 'display_save_errors' ) );

        // AJAX handlers for the editor
        add_action( 'wp_ajax_ffc_generate_codes', array( $this, 'ajax_generate_random_codes' ) );
        add_action( 'wp_ajax_ffc_load_template', array( $this, 'ajax_load_template' ) );
    }

    /**
     * Registers all metaboxes for the Form CPT
     */
    public function add_custom_metaboxes() {
        // Remove any potential duplicates
        remove_meta_box( 'ffc_form_builder', 'ffc_form', 'normal' );
        remove_meta_box( 'ffc_form_config', 'ffc_form', 'normal' );
        remove_meta_box( 'ffc_builder_box', 'ffc_form', 'normal' ); 

        // Main metaboxes (content area)
        add_meta_box( 
            'ffc_box_layout', 
            __( '1. Certificate Layout', 'ffc' ), 
            array( $this, 'render_box_layout' ), 
            'ffc_form', 
            'normal', 
            'high' 
        );
        
        add_meta_box( 
            'ffc_box_builder', 
            __( '2. Form Builder (Fields)', 'ffc' ), 
            array( $this, 'render_box_builder' ), 
            'ffc_form', 
            'normal', 
            'high' 
        );
        
        add_meta_box( 
            'ffc_box_restriction', 
            __( '3. Restriction & Security', 'ffc' ), 
            array( $this, 'render_box_restriction' ), 
            'ffc_form', 
            'normal', 
            'high' 
        );
        
        add_meta_box(
            'ffc_box_email',
            __( '4. Email Configuration', 'ffc' ),
            array( $this, 'render_box_email' ),
            'ffc_form',
            'normal',
            'high'
        );

        add_meta_box(
            'ffc_box_geofence',
            __( '5. Geolocation & Date/Time Restrictions', 'ffc' ),
            array( $this, 'render_box_geofence' ),
            'ffc_form',
            'normal',
            'high'
        );

        // Sidebar metabox (shortcode + instructions)
        add_meta_box(
            'ffc_form_shortcode',
            __( 'How to Use / Shortcode', 'ffc' ),
            array( $this, 'render_shortcode_metabox' ),
            'ffc_form',
            'side',
            'high'
        );
    }

    /**
     * Render the shortcode sidebar metabox
     */
    public function render_shortcode_metabox( $post ) {
        ?>
        <div class="ffc-shortcode-box">
            <p><strong><?php _e( 'Copy this Shortcode:', 'ffc' ); ?></strong></p>
            <code class="ffc-shortcode-display">
                [ffc_form id="<?php echo esc_attr( $post->ID ); ?>"]
            </code>
            <p class="description">
                <?php _e( 'Paste this code into any Page or Post to display the form.', 'ffc' ); ?>
            </p>
        </div>
        <hr>
        <p><strong><?php _e( 'Tips:', 'ffc' ); ?></strong></p>
        <ul class="ffc-tips-list">
            <li><?php _e( 'Use <b>{{field_name}}</b> in the PDF Layout to insert user data.', 'ffc' ); ?></li>
            <li><?php _e( 'Common variables include {{auth_code}}, {{submission_date}}, and {{ticket}}.', 'ffc' ); ?></li>
        </ul>
        <?php
    }

    /**
     * Section 1: Certificate Layout Editor
     */
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
                                <?php esc_html_e( 'Background Image', 'ffc' ); ?>
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
                    <p class="description">
                        <?php esc_html_e( 'Mandatory Tags:', 'ffc' ); ?> <code>{{auth_code}}</code>, <code>{{name}}</code>, <code>{{cpf_rf}}</code>.
                    </p>
                    
                    <div class="ffc-input-group ffc-mt15">
                        <label class="ffc-block-label"><strong><?php esc_html_e( 'Background Image URL:', 'ffc' ); ?></strong></label>
                        <input type="text" name="ffc_config[bg_image]" id="ffc_bg_image_input" value="<?php echo esc_url( $bg_image ); ?>" class="ffc-w100">
                    </div>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Section 2: Form Builder (Fields)
     */
    public function render_box_builder( $post ) {
        $fields = get_post_meta( $post->ID, '_ffc_form_fields', true );

        // Default fields for brand new forms
        if ( empty( $fields ) && $post->post_status === 'auto-draft' ) {
            $fields = array(
                array( 'type' => 'text', 'label' => __( 'Full Name', 'ffc' ), 'name' => 'name', 'required' => '1', 'options' => '' ),
                array( 'type' => 'email', 'label' => __( 'Email', 'ffc' ), 'name' => 'email', 'required' => '1', 'options' => '' ),
                array( 'type' => 'text', 'label' => __( 'CPF / ID', 'ffc' ), 'name' => 'cpf_rf', 'required' => '1', 'options' => '' )
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
        <div>
        <p class="description">
        <?php esc_html_e( 'Minimal fields (Tag):', 'ffc' ); ?> <code>name</code>, <code>email</code>, <code>cpf_rf</code>.
        </p>
        </div>
        <div class="ffc-builder-actions ffc-mt20">
            <button type="button" class="button button-primary ffc-add-field">
                <span class="dashicons dashicons-plus-alt"></span> 
                <?php esc_html_e( 'Add New Field', 'ffc' ); ?>
            </button>
        </div>

        <div class="ffc-field-template ffc-hidden">
            <?php $this->render_field_row( 'TEMPLATE', array() ); ?>
        </div>
        <?php
    }

    /**
     * Section 3: Restrictions and Tickets
     */
    public function render_box_restriction( $post ) {
        $config = get_post_meta( $post->ID, '_ffc_form_config', true );
        
        // Get restrictions (new structure)
        $restrictions = isset($config['restrictions']) ? $config['restrictions'] : array();
        $password_active  = !empty($restrictions['password']) && $restrictions['password'] == '1';
        $allowlist_active = !empty($restrictions['allowlist']) && $restrictions['allowlist'] == '1';
        $denylist_active  = !empty($restrictions['denylist']) && $restrictions['denylist'] == '1';
        $ticket_active    = !empty($restrictions['ticket']) && $restrictions['ticket'] == '1';
        
        // Legacy fields
        $vcode      = isset($config['validation_code']) ? $config['validation_code'] : ''; 
        $allow      = isset($config['allowed_users_list']) ? $config['allowed_users_list'] : '';
        $deny       = isset($config['denied_users_list']) ? $config['denied_users_list'] : ''; 
        $gen_codes  = isset($config['generated_codes_list']) ? $config['generated_codes_list'] : ''; 
        ?>
        <table class="form-table">
            <tr>
                <th><label><?php esc_html_e( 'Form Restrictions', 'ffc' ); ?></label></th>
                <td>
                    <p class="description" style="margin-bottom: 15px;">
                        <?php esc_html_e( 'Select which restrictions to apply (can combine multiple):', 'ffc' ); ?>
                    </p>
                    
                    <label style="display: block; margin: 10px 0;">
                        <input type="checkbox" 
                               name="ffc_config[restrictions][password]" 
                               value="1" 
                               id="ffc_restriction_password"
                               <?php checked($password_active, true); ?>>
                        <strong><?php esc_html_e('Single Password', 'ffc'); ?></strong>
                        <span class="description"> — <?php esc_html_e('Shared password for all users', 'ffc'); ?></span>
                    </label>
                    
                    <label style="display: block; margin: 10px 0;">
                        <input type="checkbox" 
                               name="ffc_config[restrictions][allowlist]" 
                               value="1" 
                               id="ffc_restriction_allowlist"
                               <?php checked($allowlist_active, true); ?>>
                        <strong><?php esc_html_e('Allowlist (CPF/RF)', 'ffc'); ?></strong>
                        <span class="description"> — <?php esc_html_e('Only approved CPF/RF can submit', 'ffc'); ?></span>
                    </label>
                    
                    <label style="display: block; margin: 10px 0;">
                        <input type="checkbox" 
                               name="ffc_config[restrictions][denylist]" 
                               value="1" 
                               id="ffc_restriction_denylist"
                               <?php checked($denylist_active, true); ?>>
                        <strong><?php esc_html_e('Denylist (CPF/RF)', 'ffc'); ?></strong>
                        <span class="description"> — <?php esc_html_e('Blocked CPF/RF cannot submit', 'ffc'); ?></span>
                    </label>
                    
                    <label style="display: block; margin: 10px 0;">
                        <input type="checkbox" 
                               name="ffc_config[restrictions][ticket]" 
                               value="1" 
                               id="ffc_restriction_ticket"
                               <?php checked($ticket_active, true); ?>>
                        <strong><?php esc_html_e('Ticket (Unique Codes)', 'ffc'); ?></strong>
                        <span class="description"> — <?php esc_html_e('Requires valid ticket (consumed after use)', 'ffc'); ?></span>
                    </label>
                    
                    <p class="description" style="margin-top: 15px;">
                        <em><?php esc_html_e('Note: If no restriction is selected, form is Open (no restrictions).', 'ffc'); ?></em>
                    </p>
                </td>
            </tr>
            
            <tr id="ffc_password_field" style="<?php echo $password_active ? '' : 'display:none;'; ?>">
                <th><label><?php esc_html_e( 'Password Value', 'ffc' ); ?></label></th>
                <td>
                    <input type="text" 
                           name="ffc_config[validation_code]" 
                           value="<?php echo esc_attr($vcode); ?>" 
                           class="regular-text" 
                           placeholder="<?php esc_attr_e('Ex: PASS2025', 'ffc'); ?>">
                    <p class="description"><?php esc_html_e('This password will be required from all users.', 'ffc'); ?></p>
                </td>
            </tr>
            
            <tr id="ffc_allowlist_field" style="<?php echo $allowlist_active ? '' : 'display:none;'; ?>">
                <th><label><?php esc_html_e( 'Allowlist (CPFs / IDs)', 'ffc' ); ?></label></th>
                <td>
                    <textarea name="ffc_config[allowed_users_list]" 
                              class="ffc-textarea-mono ffc-h120 ffc-w100" 
                              placeholder="<?php esc_attr_e('One per line...', 'ffc'); ?>"><?php echo esc_textarea($allow); ?></textarea>
                    <p class="description"><?php esc_html_e('Accepts formats: 12345678900 or 123.456.789-00', 'ffc'); ?></p>
                </td>
            </tr>
            
            <tr id="ffc_denylist_field" style="<?php echo $denylist_active ? '' : 'display:none;'; ?>">
                <th><label><?php esc_html_e( 'Denylist (Blocked)', 'ffc' ); ?></label></th>
                <td>
                    <textarea name="ffc_config[denied_users_list]" 
                              class="ffc-textarea-mono ffc-h80 ffc-w100" 
                              placeholder="<?php esc_attr_e('Banned users...', 'ffc'); ?>"><?php echo esc_textarea($deny); ?></textarea>
                    <p class="description"><?php esc_html_e('Has priority over Allowlist. Accepts same formats.', 'ffc'); ?></p>
                </td>
            </tr>
            
            <tr id="ffc_ticket_field" class="ffc-highlight-row" style="<?php echo $ticket_active ? '' : 'display:none;'; ?>">
                <th><label class="ffc-label-accent"><?php esc_html_e( 'Ticket Generator', 'ffc' ); ?></label></th>
                <td>
                    <div class="ffc-admin-flex-row ffc-mb5">
                        <input type="number" id="ffc_qty_codes" value="10" min="1" max="500" class="ffc-input-small">
                        <button type="button" class="button button-secondary" id="ffc_btn_generate_codes"><?php esc_html_e( 'Generate Tickets', 'ffc' ); ?></button>
                        <span id="ffc_gen_status" class="ffc-gen-status"></span>
                    </div>
                    <textarea name="ffc_config[generated_codes_list]" 
                              id="ffc_generated_list" 
                              class="ffc-textarea-mono ffc-h120 ffc-w100"><?php echo esc_textarea($gen_codes); ?></textarea>
                    <p class="description"><?php esc_html_e('Tickets are consumed (removed) after successful use.', 'ffc'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Section 4: Email Settings
     */
    public function render_box_email( $post ) {
        $config = get_post_meta( $post->ID, '_ffc_form_config', true );
        $send_email = isset($config['send_user_email']) ? $config['send_user_email'] : '0';
        $subject    = isset($config['email_subject']) ? $config['email_subject'] : __( 'Your Certificate', 'ffc' );
        $body       = isset($config['email_body']) ? $config['email_body'] : '';
        ?>
        <table class="form-table">
            <tr>
                <th><label><?php esc_html_e( 'Send Email to User?', 'ffc' ); ?></label></th>
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
            <tr>
                <th></th>
                <td>
                <p class="description" style="margin-top: 15px;">
                <em><?php esc_html_e('Note: When this option is enabled, the email will only be sent when the user submits the form. This will add them to a waiting list and emails will be sent progressively.', 'ffc'); ?></em>
                </p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render Geofence & DateTime Restrictions Meta Box
     * @since 3.0.0
     */
    public function render_box_geofence( $post ) {
        $config = get_post_meta( $post->ID, '_ffc_geofence_config', true );
        if ( !is_array($config) ) $config = array();

        // Defaults
        $datetime_enabled = isset($config['datetime_enabled']) ? '1' : '0';
        $date_start = $config['date_start'] ?? '';
        $date_end = $config['date_end'] ?? '';
        $time_start = $config['time_start'] ?? '';
        $time_end = $config['time_end'] ?? '';
        $datetime_hide_mode = $config['datetime_hide_mode'] ?? 'message';
        $msg_datetime = $config['msg_datetime'] ?? __('This form is not available at this time.', 'ffc');

        $geo_enabled = isset($config['geo_enabled']) ? '1' : '0';
        $geo_gps_enabled = isset($config['geo_gps_enabled']) ? '1' : '0';
        $geo_ip_enabled = isset($config['geo_ip_enabled']) ? '1' : '0';
        $geo_areas = $config['geo_areas'] ?? '';
        $geo_ip_areas_permissive = isset($config['geo_ip_areas_permissive']) ? '1' : '0';
        $geo_ip_areas = $config['geo_ip_areas'] ?? '';
        $geo_gps_ip_logic = $config['geo_gps_ip_logic'] ?? 'or';
        $geo_hide_mode = $config['geo_hide_mode'] ?? 'message';
        $msg_geo_blocked = $config['msg_geo_blocked'] ?? __('This form is not available in your location.', 'ffc');
        $msg_geo_error = $config['msg_geo_error'] ?? __('Unable to determine your location. Please enable location services.', 'ffc');
        ?>

        <div class="ffc-geofence-container">
            <!-- Tab Navigation -->
            <div class="ffc-geofence-tabs">
                <button type="button" class="ffc-geo-tab-btn active" data-tab="datetime">
                    📅 <?php esc_html_e('Date & Time', 'ffc'); ?>
                </button>
                <button type="button" class="ffc-geo-tab-btn" data-tab="geolocation">
                    🌍 <?php esc_html_e('Geolocation', 'ffc'); ?>
                </button>
            </div>

            <!-- Tab: Date & Time -->
            <div class="ffc-geo-tab-content active" id="ffc-tab-datetime">
                <table class="form-table">
                    <tr>
                        <th><label><?php esc_html_e('Enable Date/Time Restrictions', 'ffc'); ?></label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="ffc_geofence[datetime_enabled]" value="1" <?php checked($datetime_enabled, '1'); ?>>
                                <?php esc_html_e('Restrict form access by date and time', 'ffc'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Control when users can access this form based on date range and daily hours.', 'ffc'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e('Date Range', 'ffc'); ?></label></th>
                        <td>
                            <label><?php esc_html_e('Start:', 'ffc'); ?> <input type="date" name="ffc_geofence[date_start]" value="<?php echo esc_attr($date_start); ?>"></label>
                            &nbsp;&nbsp;
                            <label><?php esc_html_e('End:', 'ffc'); ?> <input type="date" name="ffc_geofence[date_end]" value="<?php echo esc_attr($date_end); ?>"></label>
                            <p class="description"><?php esc_html_e('Leave empty for no date restriction. Format: YYYY-MM-DD', 'ffc'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e('Daily Time Range', 'ffc'); ?></label></th>
                        <td>
                            <label><?php esc_html_e('From:', 'ffc'); ?> <input type="time" name="ffc_geofence[time_start]" value="<?php echo esc_attr($time_start); ?>"></label>
                            &nbsp;&nbsp;
                            <label><?php esc_html_e('To:', 'ffc'); ?> <input type="time" name="ffc_geofence[time_end]" value="<?php echo esc_attr($time_end); ?>"></label>
                            <p class="description"><?php esc_html_e('Restrict access to specific hours each day. Leave empty for 24/7 access.', 'ffc'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e('Display Mode', 'ffc'); ?></label></th>
                        <td>
                            <select name="ffc_geofence[datetime_hide_mode]">
                                <option value="hide" <?php selected($datetime_hide_mode, 'hide'); ?>><?php esc_html_e('Hide form completely', 'ffc'); ?></option>
                                <option value="message" <?php selected($datetime_hide_mode, 'message'); ?>><?php esc_html_e('Show blocked message', 'ffc'); ?></option>
                                <option value="title_message" <?php selected($datetime_hide_mode, 'title_message'); ?>><?php esc_html_e('Show title + description + message', 'ffc'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('How to display the form when date/time is invalid.', 'ffc'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e('Blocked Message', 'ffc'); ?></label></th>
                        <td>
                            <textarea name="ffc_geofence[msg_datetime]" rows="3" class="ffc-w100"><?php echo esc_textarea($msg_datetime); ?></textarea>
                            <p class="description"><?php esc_html_e('Message shown when form is accessed outside allowed date/time.', 'ffc'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Tab: Geolocation -->
            <div class="ffc-geo-tab-content" id="ffc-tab-geolocation">
                <table class="form-table">
                    <tr>
                        <th><label><?php esc_html_e('Enable Geolocation', 'ffc'); ?></label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="ffc_geofence[geo_enabled]" value="1" <?php checked($geo_enabled, '1'); ?>>
                                <?php esc_html_e('Restrict form access by geographic location', 'ffc'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Limit form access to users within specific geographic areas.', 'ffc'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e('Validation Methods', 'ffc'); ?></label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="ffc_geofence[geo_gps_enabled]" value="1" <?php checked($geo_gps_enabled, '1'); ?>>
                                <?php esc_html_e('GPS (Browser geolocation)', 'ffc'); ?>
                            </label><br>
                            <label>
                                <input type="checkbox" name="ffc_geofence[geo_ip_enabled]" value="1" <?php checked($geo_ip_enabled, '1'); ?>>
                                <?php esc_html_e('IP Address (backend validation)', 'ffc'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Choose one or both methods. GPS is more accurate but requires user permission.', 'ffc'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e('Allowed Areas (GPS)', 'ffc'); ?></label></th>
                        <td>
                            <textarea name="ffc_geofence[geo_areas]" rows="5" class="ffc-w100" placeholder="-23.5505, -46.6333, 5&#10;-22.9068, -43.1729, 10"><?php echo esc_textarea($geo_areas); ?></textarea>
                            <p class="description"><?php esc_html_e('Format: latitude, longitude, radius(km) - One per line. Example: -23.5505, -46.6333, 5', 'ffc'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e('IP Geolocation Areas', 'ffc'); ?></label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="ffc_geofence[geo_ip_areas_permissive]" value="1" <?php checked($geo_ip_areas_permissive, '1'); ?>>
                                <?php esc_html_e('Use different (more permissive) areas for IP validation', 'ffc'); ?>
                            </label><br><br>
                            <textarea name="ffc_geofence[geo_ip_areas]" rows="5" class="ffc-w100" placeholder="-23.5505, -46.6333, 50&#10;-22.9068, -43.1729, 100"><?php echo esc_textarea($geo_ip_areas); ?></textarea>
                            <p class="description"><?php esc_html_e('IP geolocation is less precise (1-50km). Use larger radius. Leave empty to use same areas as GPS.', 'ffc'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e('GPS + IP Logic', 'ffc'); ?></label></th>
                        <td>
                            <select name="ffc_geofence[geo_gps_ip_logic]">
                                <option value="or" <?php selected($geo_gps_ip_logic, 'or'); ?>><?php esc_html_e('OR - Allow if GPS OR IP is valid (recommended)', 'ffc'); ?></option>
                                <option value="and" <?php selected($geo_gps_ip_logic, 'and'); ?>><?php esc_html_e('AND - Require both GPS AND IP to be valid (stricter)', 'ffc'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('When both GPS and IP are enabled, how to combine the results.', 'ffc'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e('Display Mode', 'ffc'); ?></label></th>
                        <td>
                            <select name="ffc_geofence[geo_hide_mode]">
                                <option value="hide" <?php selected($geo_hide_mode, 'hide'); ?>><?php esc_html_e('Hide form completely', 'ffc'); ?></option>
                                <option value="message" <?php selected($geo_hide_mode, 'message'); ?>><?php esc_html_e('Show blocked message', 'ffc'); ?></option>
                                <option value="title_message" <?php selected($geo_hide_mode, 'title_message'); ?>><?php esc_html_e('Show title + description + message', 'ffc'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('How to display the form when user is outside allowed areas.', 'ffc'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e('Blocked Message', 'ffc'); ?></label></th>
                        <td>
                            <textarea name="ffc_geofence[msg_geo_blocked]" rows="2" class="ffc-w100"><?php echo esc_textarea($msg_geo_blocked); ?></textarea>
                            <p class="description"><?php esc_html_e('Message shown when user is outside allowed geographic areas.', 'ffc'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e('Error Message', 'ffc'); ?></label></th>
                        <td>
                            <textarea name="ffc_geofence[msg_geo_error]" rows="2" class="ffc-w100"><?php echo esc_textarea($msg_geo_error); ?></textarea>
                            <p class="description"><?php esc_html_e('Message shown when location detection fails (GPS denied, etc).', 'ffc'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <style>
                .ffc-geofence-tabs {
                    margin-bottom: 20px;
                    border-bottom: 1px solid #ccc;
                }
                .ffc-geo-tab-btn {
                    background: #f1f1f1;
                    border: 1px solid #ccc;
                    border-bottom: none;
                    padding: 10px 20px;
                    cursor: pointer;
                    margin-right: 5px;
                    font-size: 14px;
                }
                .ffc-geo-tab-btn.active {
                    background: #fff;
                    border-bottom: 1px solid #fff;
                    margin-bottom: -1px;
                    font-weight: bold;
                }
                .ffc-geo-tab-content {
                    display: none;
                }
                .ffc-geo-tab-content.active {
                    display: block;
                }
            </style>

            <script>
            jQuery(document).ready(function($) {
                // Tab switching
                $('.ffc-geo-tab-btn').on('click', function() {
                    var tab = $(this).data('tab');
                    $('.ffc-geo-tab-btn').removeClass('active');
                    $(this).addClass('active');
                    $('.ffc-geo-tab-content').removeClass('active');
                    $('#ffc-tab-' + tab).addClass('active');
                });
            });
            </script>
        </div>
        <?php
    }

    /**
     * Helper: Renders a field row in the builder
     */
    private function render_field_row( $index, $field ) {
        $type  = isset( $field['type'] ) ? $field['type'] : 'text';
        $label = isset( $field['label'] ) ? $field['label'] : '';
        $name  = isset( $field['name'] ) ? $field['name'] : '';
        $req   = isset( $field['required'] ) ? $field['required'] : '';
        $opts  = isset( $field['options'] ) ? $field['options'] : '';
        
        $options_visible_class = ( $type === 'select' || $type === 'radio' ) ? '' : 'ffc-hidden';
        ?>
        <div class="ffc-field-row" data-index="<?php echo esc_attr($index); ?>">
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
                    <label><?php esc_html_e('Variable Name (Tag)', 'ffc'); ?></label>
                    <input type="text" name="ffc_fields[<?php echo $index; ?>][name]" value="<?php echo esc_attr( $name ); ?>" placeholder="<?php esc_attr_e('ex: course_name', 'ffc'); ?>" class="ffc-w100">
                </div>
                <div class="ffc-grid-item">
                    <label><?php esc_html_e('Type', 'ffc'); ?></label>
                    <select name="ffc_fields[<?php echo $index; ?>][type]" class="ffc-field-type-selector ffc-w100">
                        <option value="text" <?php selected($type, 'text'); ?>><?php esc_html_e('Text', 'ffc'); ?></option>
                        <option value="email" <?php selected($type, 'email'); ?>><?php esc_html_e('Email', 'ffc'); ?></option>
                        <option value="number" <?php selected($type, 'number'); ?>><?php esc_html_e('Number', 'ffc'); ?></option>
                        <option value="date" <?php selected($type, 'date'); ?>><?php esc_html_e('Date', 'ffc'); ?></option>
                        <option value="textarea" <?php selected($type, 'textarea'); ?>><?php esc_html_e('Textarea', 'ffc'); ?></option>
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
            
            <div class="ffc-options-field <?php echo $options_visible_class; ?>">
                <p class="description ffc-options-desc">
                    <?php esc_html_e('Options (separate with commas):', 'ffc'); ?>
                </p>
                <input type="text" name="ffc_fields[<?php echo $index; ?>][options]" value="<?php echo esc_attr( $opts ); ?>" placeholder="<?php esc_attr_e('Ex: Option 1, Option 2, Option 3', 'ffc'); ?>" class="ffc-w100">
            </div>
        </div>
        <?php
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
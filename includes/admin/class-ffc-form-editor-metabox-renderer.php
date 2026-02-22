<?php
declare(strict_types=1);

/**
 * FormEditorMetaboxRenderer
 *
 * Handles rendering of all metaboxes for the Form Editor.
 * Extracted from FFC_Form_Editor class to follow Single Responsibility Principle.
 *
 * @since 3.1.1 (Extracted from FFC_Form_Editor)
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

namespace FreeFormCertificate\Admin;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FormEditorMetaboxRenderer {

    /**
     * Render the shortcode sidebar metabox
     *
     * @param WP_Post $post The post object
     */
    public function render_shortcode_metabox( object $post ): void {
        ?>
        <div class="ffc-shortcode-box">
            <p><strong><?php esc_html_e( 'Copy this Shortcode:', 'ffcertificate' ); ?></strong></p>
            <code class="ffc-shortcode-display">
                [ffc_form id="<?php echo esc_attr( (string) $post->ID ); ?>"]
            </code>
            <p class="description">
                <?php esc_html_e( 'Paste this code into any Page or Post to display the form.', 'ffcertificate' ); ?>
            </p>
        </div>
        <hr>
        <p><strong><?php esc_html_e( 'Tips:', 'ffcertificate' ); ?></strong></p>
        <ul class="ffc-tips-list">
            <li><?php echo wp_kses_post( __( 'Use <b>{{field_name}}</b> in the PDF Layout to insert user data.', 'ffcertificate' ) ); ?></li>
            <li><?php esc_html_e( 'Common variables include {{auth_code}}, {{submission_date}}, and {{ticket}}.', 'ffcertificate' ); ?></li>
        </ul>
        <?php
    }

    /**
     * Section 1: Certificate Layout Editor
     *
     * @param WP_Post $post The post object
     */
    public function render_box_layout( object $post ): void {
        $config = get_post_meta( $post->ID, '_ffc_form_config', true );
        $layout = isset( $config['pdf_layout'] ) ? $config['pdf_layout'] : '';
        $bg_image = isset( $config['bg_image'] ) ? $config['bg_image'] : '';

        $templates_dir = FFC_PLUGIN_DIR . 'html/';
        $templates = glob( $templates_dir . '*.html' );

        wp_nonce_field( 'ffc_save_form_data', 'ffc_form_nonce' );
        ?>
        <table class="form-table">
            <tr>
                <th><label><?php esc_html_e( 'Actions', 'ffcertificate' ); ?></label></th>
                <td>
                    <div class="ffc-admin-flex-row ffc-flex-wrap">
                        <div class="ffc-action-group">
                            <input type="file" id="ffc_import_html_file" accept=".html,.txt" class="ffc-hidden">
                            <button type="button" class="button" id="ffc_btn_import_html">
                                <?php esc_html_e( 'Import HTML', 'ffcertificate' ); ?>
                            </button>
                            <button type="button" class="button" id="ffc_btn_media_lib">
                                <?php esc_html_e( 'Background Image', 'ffcertificate' ); ?>
                            </button>
                            <button type="button" class="button button-primary" id="ffc_btn_preview">
                                <span class="dashicons dashicons-visibility" style="vertical-align:text-bottom;margin-right:2px;"></span>
                                <?php esc_html_e( 'Preview', 'ffcertificate' ); ?>
                            </button>
                        </div>

                        <?php if($templates): ?>
                        <div class="ffc-template-loader">
                            <select id="ffc_template_select">
                                <option value=""><?php esc_html_e( 'Select Server Template...', 'ffcertificate' ); ?></option>
                                <?php foreach($templates as $tpl): $filename = basename($tpl); ?>
                                    <option value="<?php echo esc_attr($filename); ?>"><?php echo esc_html($filename); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" id="ffc_load_template_btn" class="button"><?php esc_html_e( 'Load', 'ffcertificate' ); ?></button>
                        </div>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <tr>
                <td colspan="2">
                    <label class="ffc-block-label"><strong><?php esc_html_e( 'Certificate HTML Editor', 'ffcertificate' ); ?></strong></label>
                    <textarea name="ffc_config[pdf_layout]" id="ffc_pdf_layout" class="ffc-w100" rows="12"><?php echo esc_textarea( $layout ); ?></textarea>
                    <p class="description">
                        <?php esc_html_e( 'Mandatory Tags:', 'ffcertificate' ); ?> <code>{{auth_code}}</code>, <code>{{name}}</code>, <code>{{cpf_rf}}</code>.
                    </p>

                    <div class="ffc-input-group ffc-mt15">
                        <label class="ffc-block-label"><strong><?php esc_html_e( 'Background Image URL:', 'ffcertificate' ); ?></strong></label>
                        <input type="text" name="ffc_config[bg_image]" id="ffc_bg_image_input" value="<?php echo esc_url( $bg_image ); ?>" class="ffc-w100">
                    </div>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Section 2: Form Builder (Fields)
     *
     * @param WP_Post $post The post object
     */
    public function render_box_builder( object $post ): void {
        $fields = get_post_meta( $post->ID, '_ffc_form_fields', true );

        // Default fields for brand new forms
        if ( empty( $fields ) && $post->post_status === 'auto-draft' ) {
            $fields = array(
                array( 'type' => 'text', 'label' => __( 'Full Name', 'ffcertificate' ), 'name' => 'name', 'required' => '1', 'options' => '' ),
                array( 'type' => 'email', 'label' => __( 'Email', 'ffcertificate' ), 'name' => 'email', 'required' => '1', 'options' => '' ),
                array( 'type' => 'text', 'label' => __( 'CPF / ID', 'ffcertificate' ), 'name' => 'cpf_rf', 'required' => '1', 'options' => '' )
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
        <?php esc_html_e( 'Minimal fields (Tag):', 'ffcertificate' ); ?> <code>name</code>, <code>email</code>, <code>cpf_rf</code>.
        </p>
        </div>
        <div class="ffc-builder-actions ffc-mt20">
            <button type="button" class="button button-primary ffc-add-field">
                <span class="dashicons dashicons-plus-alt"></span>
                <?php esc_html_e( 'Add New Field', 'ffcertificate' ); ?>
            </button>
        </div>

        <div class="ffc-field-template ffc-hidden">
            <?php $this->render_field_row( 'TEMPLATE', array() ); ?>
        </div>
        <?php
    }

    /**
     * Section 3: Restrictions and Tickets
     *
     * @param WP_Post $post The post object
     */
    public function render_box_restriction( object $post ): void {
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
                <th><label><?php esc_html_e( 'Form Restrictions', 'ffcertificate' ); ?></label></th>
                <td>
                    <p class="description ffc-mb-15">
                        <?php esc_html_e( 'Select which restrictions to apply (can combine multiple):', 'ffcertificate' ); ?>
                    </p>

                    <label class="ffc-restriction-label">
                        <input type="checkbox"
                               name="ffc_config[restrictions][password]"
                               value="1"
                               id="ffc_restriction_password"
                               <?php checked($password_active, true); ?>>
                        <strong><?php esc_html_e('Single Password', 'ffcertificate'); ?></strong>
                        <span class="description"> — <?php esc_html_e('Shared password for all users', 'ffcertificate'); ?></span>
                    </label>

                    <label class="ffc-restriction-label">
                        <input type="checkbox"
                               name="ffc_config[restrictions][allowlist]"
                               value="1"
                               id="ffc_restriction_allowlist"
                               <?php checked($allowlist_active, true); ?>>
                        <strong><?php esc_html_e('Allowlist (CPF/RF)', 'ffcertificate'); ?></strong>
                        <span class="description"> — <?php esc_html_e('Only approved CPF/RF can submit', 'ffcertificate'); ?></span>
                    </label>

                    <label class="ffc-restriction-label">
                        <input type="checkbox"
                               name="ffc_config[restrictions][denylist]"
                               value="1"
                               id="ffc_restriction_denylist"
                               <?php checked($denylist_active, true); ?>>
                        <strong><?php esc_html_e('Denylist (CPF/RF)', 'ffcertificate'); ?></strong>
                        <span class="description"> — <?php esc_html_e('Blocked CPF/RF cannot submit', 'ffcertificate'); ?></span>
                    </label>

                    <label class="ffc-restriction-label">
                        <input type="checkbox"
                               name="ffc_config[restrictions][ticket]"
                               value="1"
                               id="ffc_restriction_ticket"
                               <?php checked($ticket_active, true); ?>>
                        <strong><?php esc_html_e('Ticket (Unique Codes)', 'ffcertificate'); ?></strong>
                        <span class="description"> — <?php esc_html_e('Requires valid ticket (consumed after use)', 'ffcertificate'); ?></span>
                    </label>

                    <p class="description ffc-mt-15">
                        <em><?php esc_html_e('Note: If no restriction is selected, form is Open (no restrictions).', 'ffcertificate'); ?></em>
                    </p>
                </td>
            </tr>

            <tr id="ffc_password_field" class="ffc-conditional-field<?php echo esc_attr( $password_active ? ' active' : '' ); ?>">
                <th><label><?php esc_html_e( 'Password Value', 'ffcertificate' ); ?></label></th>
                <td>
                    <input type="text"
                           name="ffc_config[validation_code]"
                           value="<?php echo esc_attr($vcode); ?>"
                           class="regular-text"
                           placeholder="<?php esc_attr_e('Ex: PASS2025', 'ffcertificate'); ?>">
                    <p class="description"><?php esc_html_e('This password will be required from all users.', 'ffcertificate'); ?></p>
                </td>
            </tr>

            <tr id="ffc_allowlist_field" class="ffc-conditional-field<?php echo esc_attr( $allowlist_active ? ' active' : '' ); ?>">
                <th><label><?php esc_html_e( 'Allowlist (CPFs / IDs)', 'ffcertificate' ); ?></label></th>
                <td>
                    <textarea name="ffc_config[allowed_users_list]"
                              class="ffc-textarea-mono ffc-h120 ffc-w100"
                              placeholder="<?php esc_attr_e('One per line...', 'ffcertificate'); ?>"><?php echo esc_textarea($allow); ?></textarea>
                    <p class="description"><?php esc_html_e('Accepts formats: 12345678900 or 123.456.789-00', 'ffcertificate'); ?></p>
                </td>
            </tr>

            <tr id="ffc_denylist_field" class="ffc-conditional-field<?php echo esc_attr( $denylist_active ? ' active' : '' ); ?>">
                <th><label><?php esc_html_e( 'Denylist (Blocked)', 'ffcertificate' ); ?></label></th>
                <td>
                    <textarea name="ffc_config[denied_users_list]"
                              class="ffc-textarea-mono ffc-h80 ffc-w100"
                              placeholder="<?php esc_attr_e('Banned users...', 'ffcertificate'); ?>"><?php echo esc_textarea($deny); ?></textarea>
                    <p class="description"><?php esc_html_e('Has priority over Allowlist. Accepts same formats.', 'ffcertificate'); ?></p>
                </td>
            </tr>

            <tr id="ffc_ticket_field" class="ffc-highlight-row ffc-conditional-field<?php echo esc_attr( $ticket_active ? ' active' : '' ); ?>">
                <th><label class="ffc-label-accent"><?php esc_html_e( 'Ticket Generator', 'ffcertificate' ); ?></label></th>
                <td>
                    <div class="ffc-admin-flex-row ffc-mb5">
                        <input type="number" id="ffc_qty_codes" value="10" min="1" max="500" class="ffc-input-small">
                        <button type="button" class="button button-secondary" id="ffc_btn_generate_codes"><?php esc_html_e( 'Generate Tickets', 'ffcertificate' ); ?></button>
                        <span id="ffc_gen_status" class="ffc-gen-status"></span>
                    </div>
                    <textarea name="ffc_config[generated_codes_list]"
                              id="ffc_generated_list"
                              class="ffc-textarea-mono ffc-h120 ffc-w100"><?php echo esc_textarea($gen_codes); ?></textarea>
                    <p class="description"><?php esc_html_e('Tickets are consumed (removed) after successful use.', 'ffcertificate'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Section 4: Email Settings
     *
     * @param WP_Post $post The post object
     */
    public function render_box_email( object $post ): void {
        $config = get_post_meta( $post->ID, '_ffc_form_config', true );
        $send_email = isset($config['send_user_email']) ? $config['send_user_email'] : '0';
        $subject    = isset($config['email_subject']) ? $config['email_subject'] : __( 'Your Certificate', 'ffcertificate' );
        $body       = isset($config['email_body']) ? $config['email_body'] : '';
        ?>
        <table class="form-table">
            <tr>
                <th><label><?php esc_html_e( 'Send Email to User?', 'ffcertificate' ); ?></label></th>
                <td>
                    <select name="ffc_config[send_user_email]" class="ffc-select-full">
                        <option value="0" <?php selected($send_email, '0'); ?>><?php esc_html_e( 'No', 'ffcertificate' ); ?></option>
                        <option value="1" <?php selected($send_email, '1'); ?>><?php esc_html_e( 'Yes', 'ffcertificate' ); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label><?php esc_html_e( 'Subject', 'ffcertificate' ); ?></label></th>
                <td><input type="text" name="ffc_config[email_subject]" value="<?php echo esc_attr($subject); ?>" class="ffc-w100"></td>
            </tr>
            <tr>
                <th><label><?php esc_html_e( 'Email Body (HTML)', 'ffcertificate' ); ?></label></th>
                <td><textarea name="ffc_config[email_body]" class="ffc-h120 ffc-w100"><?php echo esc_textarea($body); ?></textarea></td>
            </tr>
            <tr>
                <th></th>
                <td>
                <p class="description ffc-mt-15">
                <em><?php esc_html_e('Note: When this option is enabled, the email will only be sent when the user submits the form. This will add them to a waiting list and emails will be sent progressively.', 'ffcertificate'); ?></em>
                </p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render Geofence & DateTime Restrictions Meta Box
     *
     * @since 3.0.0
     * @param WP_Post $post The post object
     */
    public function render_box_geofence( object $post ): void {
        $config = get_post_meta( $post->ID, '_ffc_geofence_config', true );
        if ( !is_array($config) ) $config = array();

        // Defaults
        $datetime_enabled = ($config['datetime_enabled'] ?? '0') == '1' ? '1' : '0';
        $date_start = $config['date_start'] ?? '';
        $date_end = $config['date_end'] ?? '';
        $time_start = $config['time_start'] ?? '';
        $time_end = $config['time_end'] ?? '';
        $time_mode = $config['time_mode'] ?? 'daily'; // 'daily' or 'span'
        $datetime_hide_mode = $config['datetime_hide_mode'] ?? 'message';
        $msg_datetime = $config['msg_datetime'] ?? __('This form is not available at this time.', 'ffcertificate');

        $geo_enabled = ($config['geo_enabled'] ?? '0') == '1' ? '1' : '0';
        $geo_gps_enabled = ($config['geo_gps_enabled'] ?? '0') == '1' ? '1' : '0';
        $geo_ip_enabled = ($config['geo_ip_enabled'] ?? '0') == '1' ? '1' : '0';
        $geo_areas_default = '';
        if ( $post->post_status === 'auto-draft' ) {
            $ffc_global = get_option( 'ffc_settings', array() );
            $geo_areas_default = $ffc_global['main_geo_areas'] ?? '';
        }
        $geo_areas = $config['geo_areas'] ?? $geo_areas_default;
        $geo_ip_areas_permissive = ($config['geo_ip_areas_permissive'] ?? '0') == '1' ? '1' : '0';
        $geo_ip_areas = $config['geo_ip_areas'] ?? '';
        $geo_gps_ip_logic = $config['geo_gps_ip_logic'] ?? 'or';
        $geo_hide_mode = $config['geo_hide_mode'] ?? 'message';
        $msg_geo_blocked = $config['msg_geo_blocked'] ?? __('This form is not available in your location.', 'ffcertificate');
        $msg_geo_error = $config['msg_geo_error'] ?? __('Unable to determine your location. Please enable location services.', 'ffcertificate');
        ?>

        <div class="ffc-geofence-container">
            <!-- Sentinel: ensures ffc_geofence is always in POST even when all fields are disabled -->
            <input type="hidden" name="ffc_geofence[_save]" value="1">
            <!-- Tab Navigation -->
            <div class="ffc-geofence-tabs">
                <button type="button" class="ffc-geo-tab-btn active ffc-icon-calendar" data-tab="datetime">
                    <?php esc_html_e('Date & Time', 'ffcertificate'); ?>
                </button>
                <button type="button" class="ffc-geo-tab-btn ffc-icon-globe" data-tab="geolocation">
                    <?php esc_html_e('Geolocation', 'ffcertificate'); ?>
                </button>
            </div>

            <!-- Tab: Date & Time -->
            <div class="ffc-geo-tab-content active" id="ffc-tab-datetime">
                <table class="form-table">
                    <tr>
                        <th><label><?php esc_html_e('Enable Date/Time Restrictions', 'ffcertificate'); ?></label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="ffc_geofence[datetime_enabled]" value="1" <?php checked($datetime_enabled, '1'); ?>>
                                <?php esc_html_e('Restrict form access by date and time', 'ffcertificate'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Control when users can access this form based on date range and daily hours.', 'ffcertificate'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e('Date Range', 'ffcertificate'); ?></label></th>
                        <td>
                            <label><?php esc_html_e('Start:', 'ffcertificate'); ?> <input type="date" name="ffc_geofence[date_start]" value="<?php echo esc_attr($date_start); ?>"></label>
                            &nbsp;&nbsp;
                            <label><?php esc_html_e('End:', 'ffcertificate'); ?> <input type="date" name="ffc_geofence[date_end]" value="<?php echo esc_attr($date_end); ?>"></label>
                            <p class="description"><?php esc_html_e('Leave empty for no date restriction. Format: YYYY-MM-DD', 'ffcertificate'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e('Time Range', 'ffcertificate'); ?></label></th>
                        <td>
                            <label><?php esc_html_e('From:', 'ffcertificate'); ?> <input type="time" name="ffc_geofence[time_start]" value="<?php echo esc_attr($time_start); ?>"></label>
                            &nbsp;&nbsp;
                            <label><?php esc_html_e('To:', 'ffcertificate'); ?> <input type="time" name="ffc_geofence[time_end]" value="<?php echo esc_attr($time_end); ?>"></label>
                            <p class="description"><?php esc_html_e('Leave empty for 24/7 access. Default: 00:00 to 23:59', 'ffcertificate'); ?></p>
                        </td>
                    </tr>
                    <tr id="ffc-time-mode-row" class="ffc-conditional-field<?php echo esc_attr( (!empty($date_start) && !empty($date_end) && $date_start !== $date_end) ? ' active' : '' ); ?>">
                        <th><label><?php esc_html_e('Time Behavior', 'ffcertificate'); ?></label></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="radio" name="ffc_geofence[time_mode]" value="span" <?php checked($time_mode, 'span'); ?>>
                                    <strong><?php esc_html_e('Time spans across dates', 'ffcertificate'); ?></strong>
                                </label>
                                <p class="description ffc-time-description">
                                    <?php esc_html_e('Start time applies to start date, end time applies to end date. Form is open continuously between those timestamps.', 'ffcertificate'); ?><br>
                                    <?php esc_html_e('Example: Start 01/01 12:00 + End 10/01 23:00 = Open from 12:00 on Jan 1st until 23:00 on Jan 10th', 'ffcertificate'); ?>
                                </p>

                                <label>
                                    <input type="radio" name="ffc_geofence[time_mode]" value="daily" <?php checked($time_mode, 'daily'); ?>>
                                    <strong><?php esc_html_e('Time applies to each day individually', 'ffcertificate'); ?></strong>
                                </label>
                                <p class="description ffc-time-description">
                                    <?php esc_html_e('Time range applies to every day in the date range. Form respects daily hours.', 'ffcertificate'); ?><br>
                                    <?php esc_html_e('Example: Start 01/01 + End 10/01 + Time 12:00-23:00 = Open 12:00-23:00 every day from Jan 1-10', 'ffcertificate'); ?>
                                </p>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e('Display Mode', 'ffcertificate'); ?></label></th>
                        <td>
                            <select name="ffc_geofence[datetime_hide_mode]">
                                <option value="message" <?php selected($datetime_hide_mode, 'message'); ?>><?php esc_html_e('Show blocked message (Recommended)', 'ffcertificate'); ?></option>
                                <option value="title_message" <?php selected($datetime_hide_mode, 'title_message'); ?>><?php esc_html_e('Show title + description + message', 'ffcertificate'); ?></option>
                                <option value="hide" <?php selected($datetime_hide_mode, 'hide'); ?>><?php esc_html_e('Hide form completely', 'ffcertificate'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('How to display the form when date/time is invalid.', 'ffcertificate'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e('Blocked Message', 'ffcertificate'); ?></label></th>
                        <td>
                            <textarea name="ffc_geofence[msg_datetime]" rows="3" class="ffc-w100"><?php echo esc_textarea($msg_datetime); ?></textarea>
                            <p class="description"><?php esc_html_e('Message shown when form is accessed outside allowed date/time.', 'ffcertificate'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Tab: Geolocation -->
            <div class="ffc-geo-tab-content" id="ffc-tab-geolocation">
                <table class="form-table">
                    <tr>
                        <th><label><?php esc_html_e('Enable Geolocation', 'ffcertificate'); ?></label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="ffc_geofence[geo_enabled]" value="1" <?php checked($geo_enabled, '1'); ?>>
                                <?php esc_html_e('Restrict form access by geographic location', 'ffcertificate'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Limit form access to users within specific geographic areas.', 'ffcertificate'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e('Validation Methods', 'ffcertificate'); ?></label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="ffc_geofence[geo_gps_enabled]" value="1" <?php checked($geo_gps_enabled, '1'); ?>>
                                <?php esc_html_e('GPS (Browser geolocation)', 'ffcertificate'); ?>
                            </label><br>
                            <label>
                                <input type="checkbox" name="ffc_geofence[geo_ip_enabled]" value="1" <?php checked($geo_ip_enabled, '1'); ?>>
                                <?php esc_html_e('IP Address (backend validation)', 'ffcertificate'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Choose one or both methods. GPS is more accurate but requires user permission.', 'ffcertificate'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e('Allowed Areas (GPS)', 'ffcertificate'); ?></label></th>
                        <td>
                            <textarea name="ffc_geofence[geo_areas]" rows="5" class="ffc-w100" placeholder="-23.5505, -46.6333, 5000&#10;-22.9068, -43.1729, 10000"><?php echo esc_textarea($geo_areas); ?></textarea>
                            <p class="description"><?php esc_html_e('Format: latitude, longitude, radius(meters) - One per line. Example: -23.5505, -46.6333, 5000', 'ffcertificate'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e('IP Geolocation Areas', 'ffcertificate'); ?></label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="ffc_geofence[geo_ip_areas_permissive]" value="1" <?php checked($geo_ip_areas_permissive, '1'); ?>>
                                <?php esc_html_e('Use different (more permissive) areas for IP validation', 'ffcertificate'); ?>
                            </label><br><br>
                            <textarea name="ffc_geofence[geo_ip_areas]" rows="5" class="ffc-w100" placeholder="-23.5505, -46.6333, 50000&#10;-22.9068, -43.1729, 100000"><?php echo esc_textarea($geo_ip_areas); ?></textarea>
                            <p class="description"><?php esc_html_e('IP geolocation is less precise (1-50km). Use larger radius (in meters). Leave empty to use same areas as GPS.', 'ffcertificate'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e('GPS + IP Logic', 'ffcertificate'); ?></label></th>
                        <td>
                            <select name="ffc_geofence[geo_gps_ip_logic]">
                                <option value="or" <?php selected($geo_gps_ip_logic, 'or'); ?>><?php esc_html_e('OR - Allow if GPS OR IP is valid (recommended)', 'ffcertificate'); ?></option>
                                <option value="and" <?php selected($geo_gps_ip_logic, 'and'); ?>><?php esc_html_e('AND - Require both GPS AND IP to be valid (stricter)', 'ffcertificate'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('When both GPS and IP are enabled, how to combine the results.', 'ffcertificate'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e('Display Mode', 'ffcertificate'); ?></label></th>
                        <td>
                            <select name="ffc_geofence[geo_hide_mode]">
                                <option value="message" <?php selected($geo_hide_mode, 'message'); ?>><?php esc_html_e('Show blocked message (Recommended)', 'ffcertificate'); ?></option>
                                <option value="title_message" <?php selected($geo_hide_mode, 'title_message'); ?>><?php esc_html_e('Show title + description + message', 'ffcertificate'); ?></option>
                                <option value="hide" <?php selected($geo_hide_mode, 'hide'); ?>><?php esc_html_e('Hide form completely', 'ffcertificate'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('How to display the form when user is outside allowed areas.', 'ffcertificate'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e('Blocked Message', 'ffcertificate'); ?></label></th>
                        <td>
                            <textarea name="ffc_geofence[msg_geo_blocked]" rows="2" class="ffc-w100"><?php echo esc_textarea($msg_geo_blocked); ?></textarea>
                            <p class="description"><?php esc_html_e('Message shown when user is outside allowed geographic areas.', 'ffcertificate'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e('Error Message', 'ffcertificate'); ?></label></th>
                        <td>
                            <textarea name="ffc_geofence[msg_geo_error]" rows="2" class="ffc-w100"><?php echo esc_textarea($msg_geo_error); ?></textarea>
                            <p class="description"><?php esc_html_e('Message shown when location detection fails (GPS denied, etc).', 'ffcertificate'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * Section 6: Quiz / Evaluation Mode
     *
     * @param WP_Post $post The post object
     */
    public function render_box_quiz( object $post ): void {
        $config = get_post_meta( $post->ID, '_ffc_form_config', true );
        if ( ! is_array( $config ) ) $config = array();

        $quiz_enabled      = ( $config['quiz_enabled'] ?? '0' ) === '1';
        $quiz_passing_score = $config['quiz_passing_score'] ?? '70';
        $quiz_max_attempts  = $config['quiz_max_attempts'] ?? '0';
        $quiz_show_score    = ( $config['quiz_show_score'] ?? '1' ) === '1';
        $quiz_show_correct  = ( $config['quiz_show_correct'] ?? '0' ) === '1';
        ?>
        <table class="form-table">
            <tr>
                <th><label><?php esc_html_e( 'Enable Quiz Mode', 'ffcertificate' ); ?></label></th>
                <td>
                    <label>
                        <input type="checkbox" name="ffc_config[quiz_enabled]" value="1" id="ffc_quiz_enabled" <?php checked( $quiz_enabled ); ?>>
                        <?php esc_html_e( 'Turn this form into a quiz/evaluation', 'ffcertificate' ); ?>
                    </label>
                    <p class="description"><?php esc_html_e( 'When enabled, radio and select fields can have point values per option. The form is scored on submission.', 'ffcertificate' ); ?></p>
                </td>
            </tr>
            <tr class="ffc-quiz-setting<?php echo $quiz_enabled ? '' : ' ffc-hidden'; ?>">
                <th><label><?php esc_html_e( 'Passing Score (%)', 'ffcertificate' ); ?></label></th>
                <td>
                    <input type="number" name="ffc_config[quiz_passing_score]" value="<?php echo esc_attr( $quiz_passing_score ); ?>" min="0" max="100" step="1" class="small-text">
                    <p class="description"><?php esc_html_e( 'Minimum percentage to pass. Set 0 for no minimum.', 'ffcertificate' ); ?></p>
                </td>
            </tr>
            <tr class="ffc-quiz-setting<?php echo $quiz_enabled ? '' : ' ffc-hidden'; ?>">
                <th><label><?php esc_html_e( 'Max Attempts', 'ffcertificate' ); ?></label></th>
                <td>
                    <input type="number" name="ffc_config[quiz_max_attempts]" value="<?php echo esc_attr( $quiz_max_attempts ); ?>" min="0" step="1" class="small-text">
                    <p class="description"><?php esc_html_e( 'Max retries per CPF/RF. 0 = unlimited.', 'ffcertificate' ); ?></p>
                </td>
            </tr>
            <tr class="ffc-quiz-setting<?php echo $quiz_enabled ? '' : ' ffc-hidden'; ?>">
                <th><label><?php esc_html_e( 'Display Options', 'ffcertificate' ); ?></label></th>
                <td>
                    <label>
                        <input type="checkbox" name="ffc_config[quiz_show_score]" value="1" <?php checked( $quiz_show_score ); ?>>
                        <?php esc_html_e( 'Show score after submission', 'ffcertificate' ); ?>
                    </label><br>
                    <label>
                        <input type="checkbox" name="ffc_config[quiz_show_correct]" value="1" <?php checked( $quiz_show_correct ); ?>>
                        <?php esc_html_e( 'Show which answers were correct/incorrect', 'ffcertificate' ); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php
        // Quiz toggle logic in ffc-admin.js
    }

    /**
     * Helper: Renders a field row in the builder
     *
     * @param int|string $index Field index
     * @param array<string, mixed> $field Field data
     */
    public function render_field_row( $index, array $field ): void {
        $type    = isset( $field['type'] ) ? $field['type'] : 'text';
        $label   = isset( $field['label'] ) ? $field['label'] : '';
        $name    = isset( $field['name'] ) ? $field['name'] : '';
        $req     = isset( $field['required'] ) ? $field['required'] : '';
        $opts    = isset( $field['options'] ) ? $field['options'] : '';
        $content   = isset( $field['content'] ) ? $field['content'] : '';
        $embed_url = isset( $field['embed_url'] ) ? $field['embed_url'] : '';
        $points    = isset( $field['points'] ) ? $field['points'] : '';

        $is_info = $type === 'info';
        $is_embed = $type === 'embed';
        $is_display_only = $is_info || $is_embed;
        $options_visible_class = ( $type === 'select' || $type === 'radio' ) ? '' : 'ffc-hidden';
        ?>
        <div class="ffc-field-row" data-index="<?php echo esc_attr($index); ?>">
            <div class="ffc-field-row-header">
                <span class="ffc-sort-handle">
                    <span class="dashicons dashicons-menu"></span>
                    <span class="ffc-field-title"><strong><?php
                        if ( $is_info ) {
                            esc_html_e( 'Info Block', 'ffcertificate' );
                        } elseif ( $is_embed ) {
                            esc_html_e( 'Embed', 'ffcertificate' );
                        } else {
                            esc_html_e( 'Field', 'ffcertificate' );
                        }
                    ?></strong></span>
                </span>
                <button type="button" class="button button-link-delete ffc-remove-field"><?php esc_html_e( 'Remove', 'ffcertificate' ); ?></button>
            </div>

            <div class="ffc-field-row-grid">
                <div class="ffc-grid-item">
                    <label><?php
                        if ( $is_info ) {
                            esc_html_e( 'Title (optional)', 'ffcertificate' );
                        } elseif ( $is_embed ) {
                            esc_html_e( 'Caption (optional)', 'ffcertificate' );
                        } else {
                            esc_html_e( 'Label', 'ffcertificate' );
                        }
                    ?></label>
                    <input type="text" name="ffc_fields[<?php echo esc_attr( $index ); ?>][label]" value="<?php echo esc_attr( $label ); ?>" class="ffc-w100">
                </div>
                <div class="ffc-grid-item ffc-standard-row<?php echo $is_display_only ? ' ffc-hidden' : ''; ?>">
                    <label><?php esc_html_e('Variable Name (Tag)', 'ffcertificate'); ?></label>
                    <input type="text" name="ffc_fields[<?php echo esc_attr( $index ); ?>][name]" value="<?php echo esc_attr( $name ); ?>" placeholder="<?php esc_attr_e('ex: course_name', 'ffcertificate'); ?>" class="ffc-w100">
                </div>
                <div class="ffc-grid-item">
                    <label><?php esc_html_e('Type', 'ffcertificate'); ?></label>
                    <select name="ffc_fields[<?php echo esc_attr( $index ); ?>][type]" class="ffc-field-type-selector ffc-w100">
                        <option value="text" <?php selected($type, 'text'); ?>><?php esc_html_e('Text', 'ffcertificate'); ?></option>
                        <option value="email" <?php selected($type, 'email'); ?>><?php esc_html_e('Email', 'ffcertificate'); ?></option>
                        <option value="number" <?php selected($type, 'number'); ?>><?php esc_html_e('Number', 'ffcertificate'); ?></option>
                        <option value="date" <?php selected($type, 'date'); ?>><?php esc_html_e('Date', 'ffcertificate'); ?></option>
                        <option value="textarea" <?php selected($type, 'textarea'); ?>><?php esc_html_e('Textarea', 'ffcertificate'); ?></option>
                        <option value="select" <?php selected($type, 'select'); ?>><?php esc_html_e('Select (Combobox)', 'ffcertificate'); ?></option>
                        <option value="radio" <?php selected($type, 'radio'); ?>><?php esc_html_e('Radio Box', 'ffcertificate'); ?></option>
                        <option value="hidden" <?php selected($type, 'hidden'); ?>><?php esc_html_e('Hidden Field', 'ffcertificate'); ?></option>
                        <option value="info" <?php selected($type, 'info'); ?>><?php esc_html_e('Info Block', 'ffcertificate'); ?></option>
                        <option value="embed" <?php selected($type, 'embed'); ?>><?php esc_html_e('Embed (Media)', 'ffcertificate'); ?></option>
                    </select>
                </div>
                <div class="ffc-grid-item ffc-flex-center ffc-standard-row<?php echo $is_display_only ? ' ffc-hidden' : ''; ?>">
                    <label class="ffc-req-label">
                        <input type="checkbox" name="ffc_fields[<?php echo esc_attr( $index ); ?>][required]" value="1" <?php checked($req, '1'); ?>>
                        <?php esc_html_e('Required?', 'ffcertificate'); ?>
                    </label>
                </div>
            </div>

            <div class="ffc-content-field<?php echo $is_info ? '' : ' ffc-hidden'; ?>">
                <p class="description ffc-options-desc">
                    <?php esc_html_e('Content (supports <b>, <i>, <a>, <p>, <ul>, <ol>):', 'ffcertificate'); ?>
                </p>
                <textarea name="ffc_fields[<?php echo esc_attr( $index ); ?>][content]" rows="4" class="ffc-w100"><?php echo esc_textarea( $content ); ?></textarea>
            </div>

            <div class="ffc-embed-field<?php echo $is_embed ? '' : ' ffc-hidden'; ?>">
                <p class="description ffc-options-desc">
                    <?php esc_html_e('Media URL (YouTube, Vimeo, image, or audio):', 'ffcertificate'); ?>
                </p>
                <input type="url" name="ffc_fields[<?php echo esc_attr( $index ); ?>][embed_url]" value="<?php echo esc_url( $embed_url ); ?>" placeholder="https://www.youtube.com/watch?v=..." class="ffc-w100">
            </div>

            <div class="ffc-options-field <?php echo esc_attr( $options_visible_class ); ?>">
                <p class="description ffc-options-desc">
                    <?php esc_html_e('Options (separate with commas):', 'ffcertificate'); ?>
                </p>
                <input type="text" name="ffc_fields[<?php echo esc_attr( $index ); ?>][options]" value="<?php echo esc_attr( $opts ); ?>" placeholder="<?php esc_attr_e('Ex: Option 1, Option 2, Option 3', 'ffcertificate'); ?>" class="ffc-w100">
                <div class="ffc-quiz-points ffc-hidden">
                    <p class="description ffc-options-desc ffc-mt5">
                        <?php esc_html_e('Points per option (same order, separate with commas):', 'ffcertificate'); ?>
                    </p>
                    <input type="text" name="ffc_fields[<?php echo esc_attr( $index ); ?>][points]" value="<?php echo esc_attr( $points ); ?>" placeholder="<?php esc_attr_e('Ex: 0, 10, 0', 'ffcertificate'); ?>" class="ffc-w100">
                </div>
            </div>
        </div>
        <?php
    }
}

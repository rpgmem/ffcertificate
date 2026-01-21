<?php
/**
 * FFC_Form_Editor_Save_Handler
 *
 * Handles saving and validation of form data.
 * Extracted from FFC_Form_Editor class to follow Single Responsibility Principle.
 *
 * @since 3.1.1 (Extracted from FFC_Form_Editor)
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Form_Editor_Save_Handler {

    /**
     * Saves all form data and configurations
     *
     * @param int $post_id The post ID
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

            // Validate geolocation configuration
            $validation_errors = $this->validate_geofence_config( $clean_geofence );
            if ( ! empty( $validation_errors ) ) {
                set_transient( 'ffc_geofence_error_' . get_current_user_id(), $validation_errors, 45 );
                return;
            }

            update_post_meta( $post_id, '_ffc_geofence_config', $clean_geofence );
        }
    }

    /**
     * Validates geofence configuration
     *
     * @param array $config Geofence configuration
     * @return array Array of validation errors (empty if valid)
     */
    private function validate_geofence_config( $config ) {
        $errors = array();

        // Check if GPS is enabled but areas are empty
        if ( $config['geo_gps_enabled'] === '1' && trim( $config['geo_areas'] ) === '' ) {
            $errors[] = __( 'GPS Geolocation is enabled but no allowed areas are defined.', 'ffc' );
        }

        // Check if IP is enabled with independent areas but areas are empty
        if ( $config['geo_ip_enabled'] === '1' && $config['geo_ip_areas_permissive'] === '1' && trim( $config['geo_ip_areas'] ) === '' ) {
            $errors[] = __( 'IP Geolocation is enabled with independent areas but no IP areas are defined.', 'ffc' );
        }

        // Validate GPS areas format
        if ( $config['geo_gps_enabled'] === '1' && trim( $config['geo_areas'] ) !== '' ) {
            $gps_errors = $this->validate_areas_format( $config['geo_areas'], 'GPS' );
            $errors = array_merge( $errors, $gps_errors );
        }

        // Validate IP areas format (if using independent areas)
        if ( $config['geo_ip_enabled'] === '1' && $config['geo_ip_areas_permissive'] === '1' && trim( $config['geo_ip_areas'] ) !== '' ) {
            $ip_errors = $this->validate_areas_format( $config['geo_ip_areas'], 'IP' );
            $errors = array_merge( $errors, $ip_errors );
        }

        return $errors;
    }

    /**
     * Validates area format (latitude, longitude, radius)
     *
     * @param string $areas_text Areas text (one per line)
     * @param string $type Type of area (GPS or IP) for error messages
     * @return array Array of validation errors
     */
    private function validate_areas_format( $areas_text, $type ) {
        $errors = array();
        $lines = array_filter( array_map( 'trim', explode( "\n", $areas_text ) ) );
        $line_number = 0;

        foreach ( $lines as $line ) {
            $line_number++;

            // Skip empty lines
            if ( empty( $line ) ) {
                continue;
            }

            // Check format: lat,lng,radius
            if ( ! preg_match( '/^-?\d+(\.\d+)?\s*,\s*-?\d+(\.\d+)?\s*,\s*\d+(\.\d+)?$/', $line ) ) {
                $errors[] = sprintf(
                    /* translators: 1: Area type (GPS/IP), 2: Line number */
                    __( '%1$s Area line %2$d: Invalid format. Use: latitude, longitude, radius', 'ffc' ),
                    $type,
                    $line_number
                );
                continue;
            }

            // Parse values
            $parts = array_map( 'trim', explode( ',', $line ) );
            $lat = floatval( $parts[0] );
            $lng = floatval( $parts[1] );
            $radius = floatval( $parts[2] );

            // Validate latitude range
            if ( $lat < -90 || $lat > 90 ) {
                $errors[] = sprintf(
                    /* translators: 1: Area type (GPS/IP), 2: Line number, 3: Latitude value */
                    __( '%1$s Area line %2$d: Invalid latitude %3$s (must be between -90 and 90)', 'ffc' ),
                    $type,
                    $line_number,
                    $lat
                );
            }

            // Validate longitude range
            if ( $lng < -180 || $lng > 180 ) {
                $errors[] = sprintf(
                    /* translators: 1: Area type (GPS/IP), 2: Line number, 3: Longitude value */
                    __( '%1$s Area line %2$d: Invalid longitude %3$s (must be between -180 and 180)', 'ffc' ),
                    $type,
                    $line_number,
                    $lng
                );
            }

            // Validate radius
            if ( $radius <= 0 ) {
                $errors[] = sprintf(
                    /* translators: 1: Area type (GPS/IP), 2: Line number */
                    __( '%1$s Area line %2$d: Radius must be greater than 0', 'ffc' ),
                    $type,
                    $line_number
                );
            }
        }

        return $errors;
    }

    /**
     * Displays validation warnings after saving
     */
    public function display_save_errors() {
        // Display PDF layout errors
        $error_tags = get_transient( 'ffc_save_error_' . get_current_user_id() );
        if ( $error_tags ) {
            delete_transient( 'ffc_save_error_' . get_current_user_id() );
            ?>
            <div class="notice notice-error is-dismissible">
                <p><strong><?php esc_html_e( 'Warning! Missing required tags in PDF Layout:', 'ffc' ); ?></strong> <code><?php echo esc_html(implode( ', ', $error_tags )); ?></code>.</p>
            </div>
            <?php
        }

        // Display geofence validation errors
        $geofence_errors = get_transient( 'ffc_geofence_error_' . get_current_user_id() );
        if ( $geofence_errors ) {
            delete_transient( 'ffc_geofence_error_' . get_current_user_id() );
            ?>
            <div class="notice notice-error is-dismissible">
                <p><strong><?php esc_html_e( 'Geolocation Configuration Error:', 'ffc' ); ?></strong></p>
                <ul style="list-style: disc; margin-left: 20px;">
                    <?php foreach ( $geofence_errors as $error ) : ?>
                        <li><?php echo esc_html( $error ); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php
        }
    }
}

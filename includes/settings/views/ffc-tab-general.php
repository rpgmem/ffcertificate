<?php
/**
 * General Settings Tab
 * @version 3.0.0 - Clean, no inline styles
 */

if (!defined('ABSPATH')) exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables scoped to this file

$ffcertificate_get_option = function($key, $default = '') {
    $settings = get_option('ffc_settings', array());
    return isset($settings[$key]) ? $settings[$key] : $default;
};

$ffcertificate_date_formats = array(
    'Y-m-d H:i:s' => '2026-01-04 15:30:45 (YYYY-MM-DD HH:MM:SS)',
    'Y-m-d' => '2026-01-04 (YYYY-MM-DD)',
    'd/m/Y' => '04/01/2026 (DD/MM/YYYY)',
    'd/m/Y H:i' => '04/01/2026 15:30 (DD/MM/YYYY HH:MM)',
    'd/m/Y H:i:s' => '04/01/2026 15:30:45 (DD/MM/YYYY HH:MM:SS)',
    'm/d/Y' => '01/04/2026 (MM/DD/YYYY)',
    'F j, Y' => __('January 4, 2026 (Month Day, Year)', 'ffcertificate'),
    'j \d\e F \d\e Y' => __('4 of January, 2026', 'ffcertificate'),
    'd \d\e F \d\e Y' => __('04 of January, 2026', 'ffcertificate'),
    'l, j \d\e F \d\e Y' => __('Saturday, January 4, 2026', 'ffcertificate'),
    'custom' => __('Custom Format', 'ffcertificate')
);

$ffcertificate_current_format = $ffcertificate_get_option('date_format', 'F j, Y');
$ffcertificate_custom_format = $ffcertificate_get_option('date_format_custom', '');
$ffcertificate_main_address = $ffcertificate_get_option('main_address', '');
$ffcertificate_main_geo_areas = $ffcertificate_get_option('main_geo_areas', '');
?>

<div class="ffc-settings-wrap">

<!-- General Settings Card -->
<div class="card">
    <h2 class="ffc-icon-settings"><?php esc_html_e('General Settings', 'ffcertificate'); ?></h2>
    
    <form method="post">
        <?php wp_nonce_field('ffc_settings_action', 'ffc_settings_nonce'); ?>
        <input type="hidden" name="_ffc_tab" value="general">
        
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="cleanup_days"><?php esc_html_e('Auto-delete (days)', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="ffc_settings[cleanup_days]" id="cleanup_days" value="<?php echo esc_attr($ffcertificate_get_option('cleanup_days')); ?>" class="small-text" min="0">
                        <p class="description"><?php esc_html_e('Files removed after X days. Set to 0 to disable.', 'ffcertificate'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="ffc_date_format"><?php esc_html_e('Date Format', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <select name="ffc_settings[date_format]" id="ffc_date_format" class="regular-text">
                            <?php foreach ($ffcertificate_date_formats as $ffcertificate_format => $ffcertificate_label) : ?>
                                <option value="<?php echo esc_attr($ffcertificate_format); ?>" <?php selected($ffcertificate_current_format, $ffcertificate_format); ?>>
                                    <?php echo esc_html($ffcertificate_label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Format used for {{submission_date}} placeholder in PDFs and emails.', 'ffcertificate'); ?>
                            <br>
                            <strong><?php esc_html_e('Preview:', 'ffcertificate'); ?></strong>
                            <span class="ffc-text-info ffc-monospace">
                                <?php
                                $ffcertificate_preview_date = '2026-01-04 15:30:45';
                                echo esc_html( date_i18n(($ffcertificate_current_format === 'custom' && !empty($ffcertificate_custom_format)) ? $ffcertificate_custom_format : $ffcertificate_current_format, strtotime($ffcertificate_preview_date)) );
                                ?>
                            </span>
                        </p>

                        <div id="ffc_custom_format_container" class="ffc-collapsible-section <?php echo esc_attr( $ffcertificate_current_format !== 'custom' ? 'ffc-hidden' : '' ); ?>">
                            <div class="ffc-collapsible-content active">
                                <label>
                                    <strong><?php esc_html_e('Custom Format:', 'ffcertificate'); ?></strong><br>
                                    <input type="text" name="ffc_settings[date_format_custom]" id="ffc_date_format_custom" value="<?php echo esc_attr($ffcertificate_custom_format); ?>" placeholder="d/m/Y H:i" class="regular-text">
                                </label>
                                <p class="description">
                                    <?php esc_html_e('Use PHP date format characters.', 'ffcertificate'); ?>
                                    <a href="https://www.php.net/manual/en/datetime.format.php" target="_blank"><?php esc_html_e('See documentation', 'ffcertificate'); ?></a>
                                </p>
                            </div>
                        </div>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="main_address"><?php esc_html_e('Main Address', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <input type="text" name="ffc_settings[main_address]" id="main_address" value="<?php echo esc_attr($ffcertificate_main_address); ?>" class="large-text">
                        <p class="description">
                            <?php esc_html_e('Main institutional address. Available as {{main_address}} placeholder in certificate and appointment templates.', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="main_geo_areas"><?php esc_html_e('Address Georeferencing', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <textarea name="ffc_settings[main_geo_areas]" id="main_geo_areas" rows="4" class="large-text" placeholder="-23.5505, -46.6333, 5000"><?php echo esc_textarea($ffcertificate_main_geo_areas); ?></textarea>
                        <p class="description">
                            <?php esc_html_e('Format: latitude, longitude, radius (meters) — one per line.', 'ffcertificate'); ?><br>
                            <?php esc_html_e('Example: -23.5505, -46.6333, 5000', 'ffcertificate'); ?><br>
                            <span class="ffc-text-info ffc-icon-info"><?php esc_html_e('Used as default geofencing area when creating new forms.', 'ffcertificate'); ?></span>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>

        <h3 class="ffc-icon-clipboard"><?php esc_html_e('Activity Log Settings', 'ffcertificate'); ?></h3>
        <p class="description">
            <?php esc_html_e('Activity Log tracks important actions in your system for audit and compliance purposes (LGPD).', 'ffcertificate'); ?> <br>
            <?php esc_html_e('This option has a significant impact on website speed and stability, so use it wisely.', 'ffcertificate'); ?> <br>
            <span class="ffc-text-warning ffc-icon-warning"><?php esc_html_e('If this option is disabled, debug logging will also be disabled.', 'ffcertificate'); ?></span><br>
            <span class="ffc-text-info ffc-icon-info"><?php esc_html_e('When enabled, actions like submission creation, data access, and settings changes are logged.', 'ffcertificate'); ?></span>
        </p>

        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="enable_activity_log"><?php esc_html_e('Enable Activity Log', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[enable_activity_log]" id="enable_activity_log" value="1" <?php checked($ffcertificate_get_option('enable_activity_log'), 1); ?>>
                            <?php esc_html_e('Track activities for audit trail', 'ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logs submission creation, data access, settings changes, and security events.', 'ffcertificate'); ?><br>
                            <span class="ffc-text-success ffc-icon-success"><?php esc_html_e('Includes user ID, IP address, and timestamp for LGPD compliance.', 'ffcertificate'); ?></span>
                            <?php if ($ffcertificate_get_option('enable_activity_log') == 1) : ?>
                                <br>
                                <a href="<?php echo esc_url( admin_url('edit.php?post_type=ffc_form&page=ffc-activity-log') ); ?>" class="button button-secondary ffc-mt-10">
                                    <span class="ffc-icon-chart"></span><?php esc_html_e('View Activity Logs', 'ffcertificate'); ?>
                                </a>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="activity_log_retention_days"><?php esc_html_e('Log Retention (days)', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="ffc_settings[activity_log_retention_days]" id="activity_log_retention_days" value="<?php echo esc_attr($ffcertificate_get_option('activity_log_retention_days', 90)); ?>" min="0" max="365" class="small-text">
                        <p class="description">
                            <?php esc_html_e('Automatically delete activity logs older than this many days. Set to 0 to keep logs indefinitely.', 'ffcertificate'); ?><br>
                            <?php esc_html_e('Cleanup runs daily via scheduled cron task.', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>

        <h3 class="ffc-icon-debug"><?php esc_html_e('Debug Settings', 'ffcertificate'); ?></h3>
        <p class="description">
            <?php esc_html_e('Enable debug logging for specific areas. Debug logs are written to the PHP error log.', 'ffcertificate'); ?><br>
            <span class="ffc-text-warning ffc-icon-warning"><?php esc_html_e('Only enable in development or when troubleshooting issues.', 'ffcertificate'); ?></span>
        </p>

        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="debug_pdf_generator"><?php esc_html_e('PDF Generator', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[debug_pdf_generator]" id="debug_pdf_generator" value="1" <?php checked($ffcertificate_get_option('debug_pdf_generator'), 1); ?>>
                            <?php esc_html_e('Enable debug logging for PDF generation', 'ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logs JSON data parsing, placeholder replacements, and PDF data preparation.', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="debug_email_handler"><?php esc_html_e('Email Handler', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[debug_email_handler]" id="debug_email_handler" value="1" <?php checked($ffcertificate_get_option('debug_email_handler'), 1); ?>>
                            <?php esc_html_e('Enable debug logging for email sending', 'ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logs email preparation, SMTP connection, and sending status.', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="debug_form_processor"><?php esc_html_e('Form Processor', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[debug_form_processor]" id="debug_form_processor" value="1" <?php checked($ffcertificate_get_option('debug_form_processor'), 1); ?>>
                            <?php esc_html_e('Enable debug logging for form submission processing', 'ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logs form data validation, processing steps, and submission creation.', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="debug_encryption"><?php esc_html_e('Encryption', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[debug_encryption]" id="debug_encryption" value="1" <?php checked($ffcertificate_get_option('debug_encryption'), 1); ?>>
                            <?php esc_html_e('Enable debug logging for encryption operations', 'ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logs encryption/decryption operations and key management.', 'ffcertificate'); ?><br>
                            <span class="ffc-text-warning ffc-icon-warning"><?php esc_html_e('Never enables actual data logging, only operation status.', 'ffcertificate'); ?></span>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="debug_geofence"><?php esc_html_e('Geofence', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[debug_geofence]" id="debug_geofence" value="1" <?php checked($ffcertificate_get_option('debug_geofence'), 1); ?>>
                            <?php esc_html_e('Enable debug logging for geofence validation', 'ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logs date/time restrictions, GPS validation, IP geolocation, and access denied events.', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="debug_user_manager"><?php esc_html_e('User Manager', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[debug_user_manager]" id="debug_user_manager" value="1" <?php checked($ffcertificate_get_option('debug_user_manager'), 1); ?>>
                            <?php esc_html_e('Enable debug logging for user management', 'ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logs user creation failures, decryption errors, and critical user management operations.', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="debug_rest_api"><?php esc_html_e('REST API', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[debug_rest_api]" id="debug_rest_api" value="1" <?php checked($ffcertificate_get_option('debug_rest_api'), 1); ?>>
                            <?php esc_html_e('Enable debug logging for REST API operations', 'ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logs REST API requests, responses, and errors.', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="debug_migrations"><?php esc_html_e('Migrations', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[debug_migrations]" id="debug_migrations" value="1" <?php checked($ffcertificate_get_option('debug_migrations'), 1); ?>>
                            <?php esc_html_e('Enable debug logging for database migrations', 'ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logs migration execution, user linking, and data transformation operations.', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="debug_activity_log"><?php esc_html_e('Activity Log', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[debug_activity_log]" id="debug_activity_log" value="1" <?php checked($ffcertificate_get_option('debug_activity_log'), 1); ?>>
                            <?php esc_html_e('Enable debug logging for activity log system', 'ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logs activity log operations and database queries.', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>

        <?php submit_button(); ?>
    </form>
</div>

<!-- Danger Zone Card -->
<div class="card ffc-danger-zone">
    <h2 class="ffc-icon-warning"><?php esc_html_e('Danger Zone', 'ffcertificate'); ?></h2>
    <p class="description"><?php esc_html_e('Warning: These actions cannot be undone.', 'ffcertificate'); ?></p>
    
    <form method="post" id="ffc-danger-zone-form">
        <?php wp_nonce_field('ffc_delete_all_data', 'ffc_critical_nonce'); ?>
        <input type="hidden" name="ffc_delete_all_data" value="1">
        
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="ffc_delete_target"><?php esc_html_e('Delete Submissions', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <select name="delete_target" id="ffc_delete_target" class="regular-text">
                            <option value="all"><?php esc_html_e('Delete All Submissions (All Forms)', 'ffcertificate'); ?></option>
                            <?php 
                            $ffcertificate_forms = get_posts(['post_type' => 'ffc_form', 'posts_per_page' => -1, 'post_status' => 'publish']);
                            if (!empty($ffcertificate_forms)) :
                                foreach ($ffcertificate_forms as $ffcertificate_f) : ?>
                                    <option value="<?php echo esc_attr($ffcertificate_f->ID); ?>">
                                        <?php echo esc_html($ffcertificate_f->post_title); ?>
                                    </option>
                                <?php endforeach;
                            endif;
                            ?>
                        </select>
                        
                        <div class="ffc-checkbox-group">
                            <label>
                                <input type="checkbox" name="reset_counter" value="1" id="ffc_reset_counter">
                                <span>
                                    <span class="checkbox-label-text"><?php esc_html_e('Reset ID counter to 1', 'ffcertificate'); ?></span>
                                    <span class="checkbox-sublabel"><?php esc_html_e('(recommended)', 'ffcertificate'); ?></span>
                                </span>
                            </label>
                            <p class="description">
                                <?php esc_html_e('When checked, next submission will start from ID #1. Only works if table becomes empty.', 'ffcertificate'); ?>
                            </p>
                        </div>
                        
                        <div class="ffc-mt-20">
                            <button type="submit" class="button button-link-delete ffc-icon-delete" onclick="return confirm('<?php echo esc_js(__('Are you absolutely sure?\n\nThis action CANNOT be undone!\n\nAll selected data will be permanently deleted.', 'ffcertificate')); ?>');">
                                <?php esc_html_e('Delete Data Permanently', 'ffcertificate'); ?>
                            </button>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </form>
</div>

<!-- Form Cache Card -->
<div class="card">
    <h2 class="ffc-icon-package"><?php esc_html_e('Form Cache', 'ffcertificate'); ?></h2>
    <p class="description">
        <?php esc_html_e('The cache stores form settings to improve performance.', 'ffcertificate'); ?> 
        <?php if (wp_using_ext_object_cache()): ?>
            <span class="ffc-text-success ffc-icon-success"><?php esc_html_e('External cache active (Redis/Memcached)', 'ffcertificate'); ?></span>
        <?php else: ?>
            <span class="ffc-text-warning ffc-icon-warning"><?php esc_html_e('Using default WordPress cache (database)', 'ffcertificate'); ?></span>
        <?php endif; ?>
    </p>
    
    <form method="post">
        <?php wp_nonce_field('ffc_settings_action', 'ffc_settings_nonce'); ?>
        <input type="hidden" name="_ffc_tab" value="general">
        
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="cache_enabled"><?php esc_html_e('Enable Cache', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[cache_enabled]" id="cache_enabled" value="1" <?php checked($ffcertificate_get_option('cache_enabled'), 1); ?>>
                            <?php esc_html_e('Enable form caching', 'ffcertificate'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Recommended for sites with many forms or high traffic.', 'ffcertificate'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="cache_expiration"><?php esc_html_e('Expiration Time', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <select name="ffc_settings[cache_expiration]" id="cache_expiration" class="regular-text">
                            <option value="900" <?php selected($ffcertificate_get_option('cache_expiration'), 900); ?>><?php esc_html_e('15 minutes', 'ffcertificate'); ?></option>
                            <option value="1800" <?php selected($ffcertificate_get_option('cache_expiration'), 1800); ?>><?php esc_html_e('30 minutes', 'ffcertificate'); ?></option>
                            <option value="3600" <?php selected($ffcertificate_get_option('cache_expiration'), 3600); ?>><?php esc_html_e('1 hour (default)', 'ffcertificate'); ?></option>
                            <option value="86400" <?php selected($ffcertificate_get_option('cache_expiration'), 86400); ?>><?php esc_html_e('24 hours', 'ffcertificate'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('Time the data remains in cache before being updated.', 'ffcertificate'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="cache_auto_warm"><?php esc_html_e('Automatic Warming', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[cache_auto_warm]" id="cache_auto_warm" value="1" <?php checked($ffcertificate_get_option('cache_auto_warm'), 1); ?>>
                            <?php esc_html_e('Pre-load cache daily', 'ffcertificate'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Runs a daily cron job to keep the cache always updated.', 'ffcertificate'); ?></p>
                    </td>
                </tr>
                
                <?php if (class_exists('\FreeFormCertificate\Submissions\FormCache')): ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Statistics', 'ffcertificate'); ?></th>
                    <td>
                        <?php 
                        $ffcertificate_stats = \FreeFormCertificate\Submissions\FormCache::get_stats();
                        $ffcertificate_total_forms = wp_count_posts('ffc_form')->publish;
                        ?>
                        <div class="ffc-stats-box">
                            <table>
                                <tr class="alternate">
                                    <td><strong><?php esc_html_e('Backend:', 'ffcertificate'); ?></strong></td>
                                    <td class="stat-value"><?php echo esc_html($ffcertificate_stats['backend']); ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?php esc_html_e('Group:', 'ffcertificate'); ?></strong></td>
                                    <td class="stat-value"><?php echo esc_html($ffcertificate_stats['group']); ?></td>
                                </tr>
                                <tr class="alternate">
                                    <td><strong><?php esc_html_e('Expiration:', 'ffcertificate'); ?></strong></td>
                                    <td class="stat-value"><?php echo esc_html($ffcertificate_stats['expiration']); ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?php esc_html_e('Published Forms:', 'ffcertificate'); ?></strong></td>
                                    <td class="stat-value info"><?php echo esc_html($ffcertificate_total_forms); ?></td>
                                </tr>
                            </table>
                            <?php if (!wp_using_ext_object_cache()): ?>
                                <p class="ffc-text-warning ffc-mt-20">
                                    <span class="ffc-icon-bulb"></span><?php esc_html_e('Tip: Install Redis or Memcached for better performance.', 'ffcertificate'); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php esc_html_e('Actions', 'ffcertificate'); ?></th>
                    <td>
                        <a href="<?php echo esc_url( wp_nonce_url(admin_url('edit.php?post_type=ffc_form&page=ffc-settings&tab=general&action=warm_cache'), 'ffc_warm_cache') ); ?>" class="button">
                            <?php esc_html_e('Warm Cache Now', 'ffcertificate'); ?>
                        </a>
                        <a href="<?php echo esc_url( wp_nonce_url(admin_url('edit.php?post_type=ffc_form&page=ffc-settings&tab=general&action=clear_cache'), 'ffc_clear_cache') ); ?>" class="button" onclick="return confirm('<?php echo esc_js(__('Clear all cache?', 'ffcertificate')); ?>');">
                            <span class="ffc-icon-delete"></span><?php esc_html_e('Clear Cache', 'ffcertificate'); ?>
                        </a>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <?php submit_button(); ?>
    </form>
</div>

</div><!-- .ffc-settings-wrap -->
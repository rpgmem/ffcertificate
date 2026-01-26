<?php
/**
 * General Settings Tab
 * @version 3.0.0 - Clean, no inline styles
 */

if (!defined('ABSPATH')) exit;

$get_option = function($key, $default = '') {
    $settings = get_option('ffc_settings', array());
    return isset($settings[$key]) ? $settings[$key] : $default;
};

$date_formats = array(
    'Y-m-d H:i:s' => '2026-01-04 15:30:45 (YYYY-MM-DD HH:MM:SS)',
    'Y-m-d' => '2026-01-04 (YYYY-MM-DD)',
    'd/m/Y' => '04/01/2026 (DD/MM/YYYY)',
    'd/m/Y H:i' => '04/01/2026 15:30 (DD/MM/YYYY HH:MM)',
    'd/m/Y H:i:s' => '04/01/2026 15:30:45 (DD/MM/YYYY HH:MM:SS)',
    'm/d/Y' => '01/04/2026 (MM/DD/YYYY)',
    'F j, Y' => __('January 4, 2026 (Month Day, Year)', 'ffc'),
    'j \d\e F \d\e Y' => __('4 of January, 2026', 'ffc'),
    'd \d\e F \d\e Y' => __('04 of January, 2026', 'ffc'),
    'l, j \d\e F \d\e Y' => __('Saturday, January 4, 2026', 'ffc'),
    'custom' => __('Custom Format', 'ffc')
);

$current_format = $get_option('date_format', 'F j, Y');
$custom_format = $get_option('date_format_custom', '');
?>

<div class="ffc-settings-wrap">

<!-- General Settings Card -->
<div class="card">
    <h2>⚙️ <?php esc_html_e('General Settings'); ?></h2>
    
    <form method="post">
        <?php wp_nonce_field('ffc_settings_action', 'ffc_settings_nonce'); ?>
        <input type="hidden" name="_ffc_tab" value="general">
        
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="cleanup_days"><?php esc_html_e('Auto-delete (days)', 'ffc'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="ffc_settings[cleanup_days]" id="cleanup_days" value="<?php echo esc_attr($get_option('cleanup_days')); ?>" class="small-text" min="0">
                        <p class="description"><?php esc_html_e('Files removed after X days. Set to 0 to disable.', 'ffc'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="ffc_date_format"><?php esc_html_e('Date Format', 'ffc'); ?></label>
                    </th>
                    <td>
                        <select name="ffc_settings[date_format]" id="ffc_date_format" class="regular-text">
                            <?php foreach ($date_formats as $format => $label) : ?>
                                <option value="<?php echo esc_attr($format); ?>" <?php selected($current_format, $format); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Format used for {{submission_date}} placeholder in PDFs and emails.', 'ffc'); ?>
                            <br>
                            <strong><?php esc_html_e('Preview:', 'ffc'); ?></strong>
                            <span class="ffc-text-info ffc-monospace">
                                <?php
                                $preview_date = '2026-01-04 15:30:45';
                                echo date_i18n(($current_format === 'custom' && !empty($custom_format)) ? $custom_format : $current_format, strtotime($preview_date));
                                ?>
                            </span>
                        </p>

                        <div id="ffc_custom_format_container" class="ffc-collapsible-section <?php echo $current_format !== 'custom' ? 'ffc-hidden' : ''; ?>">
                            <div class="ffc-collapsible-content active">
                                <label>
                                    <strong><?php esc_html_e('Custom Format:', 'ffc'); ?></strong><br>
                                    <input type="text" name="ffc_settings[date_format_custom]" id="ffc_date_format_custom" value="<?php echo esc_attr($custom_format); ?>" placeholder="d/m/Y H:i" class="regular-text">
                                </label>
                                <p class="description">
                                    <?php esc_html_e('Use PHP date format characters.', 'ffc'); ?>
                                    <a href="https://www.php.net/manual/en/datetime.format.php" target="_blank"><?php esc_html_e('See documentation', 'ffc'); ?></a>
                                </p>
                            </div>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>

        <h3>📋 <?php esc_html_e('Activity Log Settings', 'ffc'); ?></h3>
        <p class="description">
            <?php esc_html_e('Activity Log tracks important actions in your system for audit and compliance purposes (LGPD).', 'ffc'); ?> <br>
            <span class="ffc-text-warning">⚠️ <?php esc_html_e('Only enable in development or when troubleshooting issues.', 'ffc'); ?></span><br>
            <span class="ffc-text-info">ℹ️ <?php esc_html_e('If this option is disabled, debug logging will also be disabled.', 'ffc'); ?></span>
        </p>

        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="enable_activity_log"><?php esc_html_e('Enable Activity Log', 'ffc'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[enable_activity_log]" id="enable_activity_log" value="1" <?php checked($get_option('enable_activity_log'), 1); ?>>
                            <?php esc_html_e('Track activities for audit trail', 'ffc'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logs submission creation, data access, settings changes, and security events.', 'ffc'); ?><br>
                            <span class="ffc-text-success">✅ <?php esc_html_e('Includes user ID, IP address, and timestamp for LGPD compliance.', 'ffc'); ?></span>
                            <?php if ($get_option('enable_activity_log') == 1) : ?>
                                <br>
                                <a href="<?php echo admin_url('edit.php?post_type=ffc_form&page=ffc-activity-log'); ?>" class="button button-secondary ffc-mt-10">
                                    📊 <?php esc_html_e('View Activity Logs', 'ffc'); ?>
                                </a>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>

        <h3>🐛 <?php esc_html_e('Debug Settings', 'ffc'); ?></h3>
        <p class="description">
            <?php esc_html_e('Enable debug logging for specific areas. Debug logs are written to the PHP error log.', 'ffc'); ?><br>
            <span class="ffc-text-warning">⚠️ <?php esc_html_e('Only enable in development or when troubleshooting issues.', 'ffc'); ?></span>
        </p>

        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="debug_pdf_generator"><?php esc_html_e('PDF Generator', 'ffc'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[debug_pdf_generator]" id="debug_pdf_generator" value="1" <?php checked($get_option('debug_pdf_generator'), 1); ?>>
                            <?php esc_html_e('Enable debug logging for PDF generation', 'ffc'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logs JSON data parsing, placeholder replacements, and PDF data preparation.', 'ffc'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="debug_email_handler"><?php esc_html_e('Email Handler', 'ffc'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[debug_email_handler]" id="debug_email_handler" value="1" <?php checked($get_option('debug_email_handler'), 1); ?>>
                            <?php esc_html_e('Enable debug logging for email sending', 'ffc'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logs email preparation, SMTP connection, and sending status.', 'ffc'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="debug_form_processor"><?php esc_html_e('Form Processor', 'ffc'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[debug_form_processor]" id="debug_form_processor" value="1" <?php checked($get_option('debug_form_processor'), 1); ?>>
                            <?php esc_html_e('Enable debug logging for form submission processing', 'ffc'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logs form data validation, processing steps, and submission creation.', 'ffc'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="debug_encryption"><?php esc_html_e('Encryption', 'ffc'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[debug_encryption]" id="debug_encryption" value="1" <?php checked($get_option('debug_encryption'), 1); ?>>
                            <?php esc_html_e('Enable debug logging for encryption operations', 'ffc'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logs encryption/decryption operations and key management.', 'ffc'); ?><br>
                            <span class="ffc-text-warning">⚠️ <?php esc_html_e('Never enables actual data logging, only operation status.', 'ffc'); ?></span>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="debug_geofence"><?php esc_html_e('Geofence', 'ffc'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[debug_geofence]" id="debug_geofence" value="1" <?php checked($get_option('debug_geofence'), 1); ?>>
                            <?php esc_html_e('Enable debug logging for geofence validation', 'ffc'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logs date/time restrictions, GPS validation, IP geolocation, and access denied events.', 'ffc'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="debug_user_manager"><?php esc_html_e('User Manager', 'ffc'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[debug_user_manager]" id="debug_user_manager" value="1" <?php checked($get_option('debug_user_manager'), 1); ?>>
                            <?php esc_html_e('Enable debug logging for user management', 'ffc'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logs user creation failures, decryption errors, and critical user management operations.', 'ffc'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="debug_rest_api"><?php esc_html_e('REST API', 'ffc'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[debug_rest_api]" id="debug_rest_api" value="1" <?php checked($get_option('debug_rest_api'), 1); ?>>
                            <?php esc_html_e('Enable debug logging for REST API operations', 'ffc'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logs REST API requests, responses, and errors.', 'ffc'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="debug_migrations"><?php esc_html_e('Migrations', 'ffc'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[debug_migrations]" id="debug_migrations" value="1" <?php checked($get_option('debug_migrations'), 1); ?>>
                            <?php esc_html_e('Enable debug logging for database migrations', 'ffc'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logs migration execution, user linking, and data transformation operations.', 'ffc'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="debug_activity_log"><?php esc_html_e('Activity Log', 'ffc'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[debug_activity_log]" id="debug_activity_log" value="1" <?php checked($get_option('debug_activity_log'), 1); ?>>
                            <?php esc_html_e('Enable debug logging for activity log system', 'ffc'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logs activity log operations and database queries.', 'ffc'); ?>
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
    <h2>⚠️ <?php esc_html_e('Danger Zone', 'ffc'); ?></h2>
    <p class="description"><?php esc_html_e('Warning: These actions cannot be undone.', 'ffc'); ?></p>
    
    <form method="post" id="ffc-danger-zone-form">
        <?php wp_nonce_field('ffc_delete_all_data', 'ffc_critical_nonce'); ?>
        <input type="hidden" name="ffc_delete_all_data" value="1">
        
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="ffc_delete_target"><?php esc_html_e('Delete Submissions', 'ffc'); ?></label>
                    </th>
                    <td>
                        <select name="delete_target" id="ffc_delete_target" class="regular-text">
                            <option value="all"><?php esc_html_e('🗑️ Delete All Submissions (All Forms)', 'ffc'); ?></option>
                            <?php 
                            $forms = get_posts(['post_type' => 'ffc_form', 'posts_per_page' => -1, 'post_status' => 'publish']);
                            if (!empty($forms)) :
                                foreach ($forms as $f) : ?>
                                    <option value="<?php echo esc_attr($f->ID); ?>">
                                        <?php echo esc_html($f->post_title); ?>
                                    </option>
                                <?php endforeach;
                            endif;
                            ?>
                        </select>
                        
                        <div class="ffc-checkbox-group">
                            <label>
                                <input type="checkbox" name="reset_counter" value="1" id="ffc_reset_counter">
                                <span>
                                    <span class="checkbox-label-text"><?php esc_html_e('Reset ID counter to 1', 'ffc'); ?></span>
                                    <span class="checkbox-sublabel"><?php esc_html_e('(recommended)', 'ffc'); ?></span>
                                </span>
                            </label>
                            <p class="description">
                                <?php esc_html_e('When checked, next submission will start from ID #1. Only works if table becomes empty.', 'ffc'); ?>
                            </p>
                        </div>
                        
                        <div class="ffc-mt-20">
                            <button type="submit" class="button button-link-delete" onclick="return confirm('<?php echo esc_js(__('⚠️ Are you absolutely sure?\n\nThis action CANNOT be undone!\n\nAll selected data will be permanently deleted.', 'ffc')); ?>');">
                                <?php esc_html_e('🗑️ Delete Data Permanently', 'ffc'); ?>
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
    <h2>📦 <?php esc_html_e('Form Cache', 'ffc'); ?></h2>
    <p class="description">
        <?php esc_html_e('The cache stores form settings to improve performance.', 'ffc'); ?> 
        <?php if (wp_using_ext_object_cache()): ?>
            <span class="ffc-text-success">✅ <?php esc_html_e('External cache active (Redis/Memcached)', 'ffc'); ?></span>
        <?php else: ?>
            <span class="ffc-text-warning">⚠️ <?php esc_html_e('Using default WordPress cache (database)', 'ffc'); ?></span>
        <?php endif; ?>
    </p>
    
    <form method="post">
        <?php wp_nonce_field('ffc_settings_action', 'ffc_settings_nonce'); ?>
        <input type="hidden" name="_ffc_tab" value="general">
        
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="cache_enabled"><?php esc_html_e('Enable Cache', 'ffc'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[cache_enabled]" id="cache_enabled" value="1" <?php checked($get_option('cache_enabled'), 1); ?>>
                            <?php esc_html_e('Enable form caching', 'ffc'); ?>
                        </label>
                        <p class="description"><?php _e('Recommended for sites with many forms or high traffic.', 'ffc'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="cache_expiration"><?php esc_html_e('Expiration Time', 'ffc'); ?></label>
                    </th>
                    <td>
                        <select name="ffc_settings[cache_expiration]" id="cache_expiration" class="regular-text">
                            <option value="900" <?php selected($get_option('cache_expiration'), 900); ?>><?php esc_html_e('15 minutes', 'ffc'); ?></option>
                            <option value="1800" <?php selected($get_option('cache_expiration'), 1800); ?>><?php esc_html_e('30 minutes', 'ffc'); ?></option>
                            <option value="3600" <?php selected($get_option('cache_expiration'), 3600); ?>><?php esc_html_e('1 hour (default)', 'ffc'); ?></option>
                            <option value="86400" <?php selected($get_option('cache_expiration'), 86400); ?>><?php esc_html_e('24 hours', 'ffc'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('Time the data remains in cache before being updated.', 'ffc'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="cache_auto_warm"><?php esc_html_e('Automatic Warming', 'ffc'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[cache_auto_warm]" id="cache_auto_warm" value="1" <?php checked($get_option('cache_auto_warm'), 1); ?>>
                            <?php esc_html_e('Pre-load cache daily', 'ffc'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Runs a daily cron job to keep the cache always updated.', 'ffc'); ?></p>
                    </td>
                </tr>
                
                <?php if (class_exists('\FFC_Form_Cache')): ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Statistics', 'ffc'); ?></th>
                    <td>
                        <?php 
                        $stats = \FFC_Form_Cache::get_stats();
                        $total_forms = wp_count_posts('ffc_form')->publish;
                        ?>
                        <div class="ffc-stats-box">
                            <table>
                                <tr class="alternate">
                                    <td><strong><?php esc_html_e('Backend:', 'ffc'); ?></strong></td>
                                    <td class="stat-value"><?php echo esc_html($stats['backend']); ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?php esc_html_e('Group:', 'ffc'); ?></strong></td>
                                    <td class="stat-value"><?php echo esc_html($stats['group']); ?></td>
                                </tr>
                                <tr class="alternate">
                                    <td><strong><?php esc_html_e('Expiration:', 'ffc'); ?></strong></td>
                                    <td class="stat-value"><?php echo esc_html($stats['expiration']); ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?php esc_html_e('Published Forms:', 'ffc'); ?></strong></td>
                                    <td class="stat-value info"><?php echo esc_html($total_forms); ?></td>
                                </tr>
                            </table>
                            <?php if (!wp_using_ext_object_cache()): ?>
                                <p class="ffc-text-warning ffc-mt-20">
                                    💡 <?php esc_html_e('Tip: Install Redis or Memcached for better performance.', 'ffc'); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php esc_html_e('Actions', 'ffc'); ?></th>
                    <td>
                        <a href="<?php echo wp_nonce_url(admin_url('edit.php?post_type=ffc_form&page=ffc-settings&tab=general&action=warm_cache'), 'ffc_warm_cache'); ?>" class="button">
                            🔥 <?php esc_html_e('Warm Cache Now', 'ffc'); ?>
                        </a>
                        <a href="<?php echo wp_nonce_url(admin_url('edit.php?post_type=ffc_form&page=ffc-settings&tab=general&action=clear_cache'), 'ffc_clear_cache'); ?>" class="button" onclick="return confirm('<?php echo esc_js(__('Clear all cache?', 'ffc')); ?>');">
                            🗑️ <?php esc_html_e('Clear Cache', 'ffc'); ?>
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
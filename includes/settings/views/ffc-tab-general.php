<?php
/**
 * General Settings Tab
 * @version 3.0.0 - Clean, no inline styles
 */

if (!defined('ABSPATH')) exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables scoped to this file

$wp_ffcertificate_get_option = function($key, $default = '') {
    $settings = get_option('ffc_settings', array());
    return isset($settings[$key]) ? $settings[$key] : $default;
};

$wp_ffcertificate_date_formats = array(
    'Y-m-d H:i:s' => '2026-01-04 15:30:45 (YYYY-MM-DD HH:MM:SS)',
    'Y-m-d' => '2026-01-04 (YYYY-MM-DD)',
    'd/m/Y' => '04/01/2026 (DD/MM/YYYY)',
    'd/m/Y H:i' => '04/01/2026 15:30 (DD/MM/YYYY HH:MM)',
    'd/m/Y H:i:s' => '04/01/2026 15:30:45 (DD/MM/YYYY HH:MM:SS)',
    'm/d/Y' => '01/04/2026 (MM/DD/YYYY)',
    'F j, Y' => __('January 4, 2026 (Month Day, Year)', 'wp-ffcertificate'),
    'j \d\e F \d\e Y' => __('4 of January, 2026', 'wp-ffcertificate'),
    'd \d\e F \d\e Y' => __('04 of January, 2026', 'wp-ffcertificate'),
    'l, j \d\e F \d\e Y' => __('Saturday, January 4, 2026', 'wp-ffcertificate'),
    'custom' => __('Custom Format', 'wp-ffcertificate')
);

$wp_ffcertificate_current_format = $wp_ffcertificate_get_option('date_format', 'F j, Y');
$wp_ffcertificate_custom_format = $wp_ffcertificate_get_option('date_format_custom', '');
?>

<div class="ffc-settings-wrap">

<!-- General Settings Card -->
<div class="card">
    <h2>⚙️ <?php esc_html_e('General Settings', 'wp-ffcertificate'); ?></h2>
    
    <form method="post">
        <?php wp_nonce_field('ffc_settings_action', 'ffc_settings_nonce'); ?>
        <input type="hidden" name="_ffc_tab" value="general">
        
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="cleanup_days"><?php esc_html_e('Auto-delete (days)', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="ffc_settings[cleanup_days]" id="cleanup_days" value="<?php echo esc_attr($wp_ffcertificate_get_option('cleanup_days')); ?>" class="small-text" min="0">
                        <p class="description"><?php esc_html_e('Files removed after X days. Set to 0 to disable.', 'wp-ffcertificate'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="ffc_date_format"><?php esc_html_e('Date Format', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <select name="ffc_settings[date_format]" id="ffc_date_format" class="regular-text">
                            <?php foreach ($wp_ffcertificate_date_formats as $wp_ffcertificate_format => $wp_ffcertificate_label) : ?>
                                <option value="<?php echo esc_attr($wp_ffcertificate_format); ?>" <?php selected($wp_ffcertificate_current_format, $wp_ffcertificate_format); ?>>
                                    <?php echo esc_html($wp_ffcertificate_label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Format used for {{submission_date}} placeholder in PDFs and emails.', 'wp-ffcertificate'); ?>
                            <br>
                            <strong><?php esc_html_e('Preview:', 'wp-ffcertificate'); ?></strong>
                            <span class="ffc-text-info ffc-monospace">
                                <?php
                                $wp_ffcertificate_preview_date = '2026-01-04 15:30:45';
                                echo esc_html( date_i18n(($wp_ffcertificate_current_format === 'custom' && !empty($wp_ffcertificate_custom_format)) ? $wp_ffcertificate_custom_format : $wp_ffcertificate_current_format, strtotime($wp_ffcertificate_preview_date)) );
                                ?>
                            </span>
                        </p>

                        <div id="ffc_custom_format_container" class="ffc-collapsible-section <?php echo esc_attr( $wp_ffcertificate_current_format !== 'custom' ? 'ffc-hidden' : '' ); ?>">
                            <div class="ffc-collapsible-content active">
                                <label>
                                    <strong><?php esc_html_e('Custom Format:', 'wp-ffcertificate'); ?></strong><br>
                                    <input type="text" name="ffc_settings[date_format_custom]" id="ffc_date_format_custom" value="<?php echo esc_attr($wp_ffcertificate_custom_format); ?>" placeholder="d/m/Y H:i" class="regular-text">
                                </label>
                                <p class="description">
                                    <?php esc_html_e('Use PHP date format characters.', 'wp-ffcertificate'); ?>
                                    <a href="https://www.php.net/manual/en/datetime.format.php" target="_blank"><?php esc_html_e('See documentation', 'wp-ffcertificate'); ?></a>
                                </p>
                            </div>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>

        <h3>📋 <?php esc_html_e('Activity Log Settings', 'wp-ffcertificate'); ?></h3>
        <p class="description">
            <?php esc_html_e('Activity Log tracks important actions in your system for audit and compliance purposes (LGPD).', 'wp-ffcertificate'); ?> <br>
            <?php esc_html_e('This option has a significant impact on website speed and stability, so use it wisely.', 'wp-ffcertificate'); ?> <br>
            <span class="ffc-text-warning">⚠️ <?php esc_html_e('If this option is disabled, debug logging will also be disabled.', 'wp-ffcertificate'); ?></span><br>
            <span class="ffc-text-info">ℹ️ <?php esc_html_e('When enabled, actions like submission creation, data access, and settings changes are logged.', 'wp-ffcertificate'); ?></span>
        </p>

        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="enable_activity_log"><?php esc_html_e('Enable Activity Log', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[enable_activity_log]" id="enable_activity_log" value="1" <?php checked($wp_ffcertificate_get_option('enable_activity_log'), 1); ?>>
                            <?php esc_html_e('Track activities for audit trail', 'wp-ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logs submission creation, data access, settings changes, and security events.', 'wp-ffcertificate'); ?><br>
                            <span class="ffc-text-success">✅ <?php esc_html_e('Includes user ID, IP address, and timestamp for LGPD compliance.', 'wp-ffcertificate'); ?></span>
                            <?php if ($wp_ffcertificate_get_option('enable_activity_log') == 1) : ?>
                                <br>
                                <a href="<?php echo esc_url( admin_url('edit.php?post_type=ffc_form&page=ffc-activity-log') ); ?>" class="button button-secondary ffc-mt-10">
                                    📊 <?php esc_html_e('View Activity Logs', 'wp-ffcertificate'); ?>
                                </a>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>

        <h3>🐛 <?php esc_html_e('Debug Settings', 'wp-ffcertificate'); ?></h3>
        <p class="description">
            <?php esc_html_e('Enable debug logging for specific areas. Debug logs are written to the PHP error log.', 'wp-ffcertificate'); ?><br>
            <span class="ffc-text-warning">⚠️ <?php esc_html_e('Only enable in development or when troubleshooting issues.', 'wp-ffcertificate'); ?></span>
        </p>

        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="debug_pdf_generator"><?php esc_html_e('PDF Generator', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[debug_pdf_generator]" id="debug_pdf_generator" value="1" <?php checked($wp_ffcertificate_get_option('debug_pdf_generator'), 1); ?>>
                            <?php esc_html_e('Enable debug logging for PDF generation', 'wp-ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logs JSON data parsing, placeholder replacements, and PDF data preparation.', 'wp-ffcertificate'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="debug_email_handler"><?php esc_html_e('Email Handler', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[debug_email_handler]" id="debug_email_handler" value="1" <?php checked($wp_ffcertificate_get_option('debug_email_handler'), 1); ?>>
                            <?php esc_html_e('Enable debug logging for email sending', 'wp-ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logs email preparation, SMTP connection, and sending status.', 'wp-ffcertificate'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="debug_form_processor"><?php esc_html_e('Form Processor', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[debug_form_processor]" id="debug_form_processor" value="1" <?php checked($wp_ffcertificate_get_option('debug_form_processor'), 1); ?>>
                            <?php esc_html_e('Enable debug logging for form submission processing', 'wp-ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logs form data validation, processing steps, and submission creation.', 'wp-ffcertificate'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="debug_encryption"><?php esc_html_e('Encryption', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[debug_encryption]" id="debug_encryption" value="1" <?php checked($wp_ffcertificate_get_option('debug_encryption'), 1); ?>>
                            <?php esc_html_e('Enable debug logging for encryption operations', 'wp-ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logs encryption/decryption operations and key management.', 'wp-ffcertificate'); ?><br>
                            <span class="ffc-text-warning">⚠️ <?php esc_html_e('Never enables actual data logging, only operation status.', 'wp-ffcertificate'); ?></span>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="debug_geofence"><?php esc_html_e('Geofence', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[debug_geofence]" id="debug_geofence" value="1" <?php checked($wp_ffcertificate_get_option('debug_geofence'), 1); ?>>
                            <?php esc_html_e('Enable debug logging for geofence validation', 'wp-ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logs date/time restrictions, GPS validation, IP geolocation, and access denied events.', 'wp-ffcertificate'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="debug_user_manager"><?php esc_html_e('User Manager', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[debug_user_manager]" id="debug_user_manager" value="1" <?php checked($wp_ffcertificate_get_option('debug_user_manager'), 1); ?>>
                            <?php esc_html_e('Enable debug logging for user management', 'wp-ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logs user creation failures, decryption errors, and critical user management operations.', 'wp-ffcertificate'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="debug_rest_api"><?php esc_html_e('REST API', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[debug_rest_api]" id="debug_rest_api" value="1" <?php checked($wp_ffcertificate_get_option('debug_rest_api'), 1); ?>>
                            <?php esc_html_e('Enable debug logging for REST API operations', 'wp-ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logs REST API requests, responses, and errors.', 'wp-ffcertificate'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="debug_migrations"><?php esc_html_e('Migrations', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[debug_migrations]" id="debug_migrations" value="1" <?php checked($wp_ffcertificate_get_option('debug_migrations'), 1); ?>>
                            <?php esc_html_e('Enable debug logging for database migrations', 'wp-ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logs migration execution, user linking, and data transformation operations.', 'wp-ffcertificate'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="debug_activity_log"><?php esc_html_e('Activity Log', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[debug_activity_log]" id="debug_activity_log" value="1" <?php checked($wp_ffcertificate_get_option('debug_activity_log'), 1); ?>>
                            <?php esc_html_e('Enable debug logging for activity log system', 'wp-ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logs activity log operations and database queries.', 'wp-ffcertificate'); ?>
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
    <h2>⚠️ <?php esc_html_e('Danger Zone', 'wp-ffcertificate'); ?></h2>
    <p class="description"><?php esc_html_e('Warning: These actions cannot be undone.', 'wp-ffcertificate'); ?></p>
    
    <form method="post" id="ffc-danger-zone-form">
        <?php wp_nonce_field('ffc_delete_all_data', 'ffc_critical_nonce'); ?>
        <input type="hidden" name="ffc_delete_all_data" value="1">
        
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="ffc_delete_target"><?php esc_html_e('Delete Submissions', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <select name="delete_target" id="ffc_delete_target" class="regular-text">
                            <option value="all"><?php esc_html_e('🗑️ Delete All Submissions (All Forms)', 'wp-ffcertificate'); ?></option>
                            <?php 
                            $wp_ffcertificate_forms = get_posts(['post_type' => 'ffc_form', 'posts_per_page' => -1, 'post_status' => 'publish']);
                            if (!empty($wp_ffcertificate_forms)) :
                                foreach ($wp_ffcertificate_forms as $wp_ffcertificate_f) : ?>
                                    <option value="<?php echo esc_attr($wp_ffcertificate_f->ID); ?>">
                                        <?php echo esc_html($wp_ffcertificate_f->post_title); ?>
                                    </option>
                                <?php endforeach;
                            endif;
                            ?>
                        </select>
                        
                        <div class="ffc-checkbox-group">
                            <label>
                                <input type="checkbox" name="reset_counter" value="1" id="ffc_reset_counter">
                                <span>
                                    <span class="checkbox-label-text"><?php esc_html_e('Reset ID counter to 1', 'wp-ffcertificate'); ?></span>
                                    <span class="checkbox-sublabel"><?php esc_html_e('(recommended)', 'wp-ffcertificate'); ?></span>
                                </span>
                            </label>
                            <p class="description">
                                <?php esc_html_e('When checked, next submission will start from ID #1. Only works if table becomes empty.', 'wp-ffcertificate'); ?>
                            </p>
                        </div>
                        
                        <div class="ffc-mt-20">
                            <button type="submit" class="button button-link-delete" onclick="return confirm('<?php echo esc_js(__('⚠️ Are you absolutely sure?\n\nThis action CANNOT be undone!\n\nAll selected data will be permanently deleted.', 'wp-ffcertificate')); ?>');">
                                <?php esc_html_e('🗑️ Delete Data Permanently', 'wp-ffcertificate'); ?>
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
    <h2>📦 <?php esc_html_e('Form Cache', 'wp-ffcertificate'); ?></h2>
    <p class="description">
        <?php esc_html_e('The cache stores form settings to improve performance.', 'wp-ffcertificate'); ?> 
        <?php if (wp_using_ext_object_cache()): ?>
            <span class="ffc-text-success">✅ <?php esc_html_e('External cache active (Redis/Memcached)', 'wp-ffcertificate'); ?></span>
        <?php else: ?>
            <span class="ffc-text-warning">⚠️ <?php esc_html_e('Using default WordPress cache (database)', 'wp-ffcertificate'); ?></span>
        <?php endif; ?>
    </p>
    
    <form method="post">
        <?php wp_nonce_field('ffc_settings_action', 'ffc_settings_nonce'); ?>
        <input type="hidden" name="_ffc_tab" value="general">
        
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="cache_enabled"><?php esc_html_e('Enable Cache', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[cache_enabled]" id="cache_enabled" value="1" <?php checked($wp_ffcertificate_get_option('cache_enabled'), 1); ?>>
                            <?php esc_html_e('Enable form caching', 'wp-ffcertificate'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Recommended for sites with many forms or high traffic.', 'wp-ffcertificate'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="cache_expiration"><?php esc_html_e('Expiration Time', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <select name="ffc_settings[cache_expiration]" id="cache_expiration" class="regular-text">
                            <option value="900" <?php selected($wp_ffcertificate_get_option('cache_expiration'), 900); ?>><?php esc_html_e('15 minutes', 'wp-ffcertificate'); ?></option>
                            <option value="1800" <?php selected($wp_ffcertificate_get_option('cache_expiration'), 1800); ?>><?php esc_html_e('30 minutes', 'wp-ffcertificate'); ?></option>
                            <option value="3600" <?php selected($wp_ffcertificate_get_option('cache_expiration'), 3600); ?>><?php esc_html_e('1 hour (default)', 'wp-ffcertificate'); ?></option>
                            <option value="86400" <?php selected($wp_ffcertificate_get_option('cache_expiration'), 86400); ?>><?php esc_html_e('24 hours', 'wp-ffcertificate'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('Time the data remains in cache before being updated.', 'wp-ffcertificate'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="cache_auto_warm"><?php esc_html_e('Automatic Warming', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[cache_auto_warm]" id="cache_auto_warm" value="1" <?php checked($wp_ffcertificate_get_option('cache_auto_warm'), 1); ?>>
                            <?php esc_html_e('Pre-load cache daily', 'wp-ffcertificate'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Runs a daily cron job to keep the cache always updated.', 'wp-ffcertificate'); ?></p>
                    </td>
                </tr>
                
                <?php if (class_exists('\FreeFormCertificate\Submissions\FormCache')): ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Statistics', 'wp-ffcertificate'); ?></th>
                    <td>
                        <?php 
                        $wp_ffcertificate_stats = \FreeFormCertificate\Submissions\FormCache::get_stats();
                        $wp_ffcertificate_total_forms = wp_count_posts('ffc_form')->publish;
                        ?>
                        <div class="ffc-stats-box">
                            <table>
                                <tr class="alternate">
                                    <td><strong><?php esc_html_e('Backend:', 'wp-ffcertificate'); ?></strong></td>
                                    <td class="stat-value"><?php echo esc_html($wp_ffcertificate_stats['backend']); ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?php esc_html_e('Group:', 'wp-ffcertificate'); ?></strong></td>
                                    <td class="stat-value"><?php echo esc_html($wp_ffcertificate_stats['group']); ?></td>
                                </tr>
                                <tr class="alternate">
                                    <td><strong><?php esc_html_e('Expiration:', 'wp-ffcertificate'); ?></strong></td>
                                    <td class="stat-value"><?php echo esc_html($wp_ffcertificate_stats['expiration']); ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?php esc_html_e('Published Forms:', 'wp-ffcertificate'); ?></strong></td>
                                    <td class="stat-value info"><?php echo esc_html($wp_ffcertificate_total_forms); ?></td>
                                </tr>
                            </table>
                            <?php if (!wp_using_ext_object_cache()): ?>
                                <p class="ffc-text-warning ffc-mt-20">
                                    💡 <?php esc_html_e('Tip: Install Redis or Memcached for better performance.', 'wp-ffcertificate'); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php esc_html_e('Actions', 'wp-ffcertificate'); ?></th>
                    <td>
                        <a href="<?php echo esc_url( wp_nonce_url(admin_url('edit.php?post_type=ffc_form&page=ffc-settings&tab=general&action=warm_cache'), 'ffc_warm_cache') ); ?>" class="button">
                            🔥 <?php esc_html_e('Warm Cache Now', 'wp-ffcertificate'); ?>
                        </a>
                        <a href="<?php echo esc_url( wp_nonce_url(admin_url('edit.php?post_type=ffc_form&page=ffc-settings&tab=general&action=clear_cache'), 'ffc_clear_cache') ); ?>" class="button" onclick="return confirm('<?php echo esc_js(__('Clear all cache?', 'wp-ffcertificate')); ?>');">
                            🗑️ <?php esc_html_e('Clear Cache', 'wp-ffcertificate'); ?>
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
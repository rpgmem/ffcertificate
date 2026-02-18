<?php
/**
 * Advanced Settings Tab View
 *
 * Contains Activity Log, Debug Settings, and Danger Zone
 *
 * @package FFC
 * @since 4.6.16
 */

if (!defined('ABSPATH')) exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables scoped to this file

$ffcertificate_get_option = \Closure::fromCallable( [ $settings, 'get_option' ] );
?>

<div class="ffc-settings-wrap">

<!-- Activity Log Settings Card -->
<div class="card">
    <h2 class="ffc-icon-clipboard"><?php esc_html_e('Activity Log', 'ffcertificate'); ?></h2>
    <p class="description">
        <?php esc_html_e('Activity Log tracks important actions in your system for audit and compliance purposes (LGPD).', 'ffcertificate'); ?> <br>
        <?php esc_html_e('This option has a significant impact on website speed and stability, so use it wisely.', 'ffcertificate'); ?> <br>
        <span class="ffc-text-warning ffc-icon-warning"><?php esc_html_e('If this option is disabled, debug logging will also be disabled.', 'ffcertificate'); ?></span><br>
        <span class="ffc-text-info ffc-icon-info"><?php esc_html_e('When enabled, actions like submission creation, data access, and settings changes are logged.', 'ffcertificate'); ?></span>
    </p>

    <form method="post">
        <?php wp_nonce_field('ffc_settings_action', 'ffc_settings_nonce'); ?>
        <input type="hidden" name="_ffc_tab" value="advanced">

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
                                    <option value="<?php echo esc_attr((string) $ffcertificate_f->ID); ?>">
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

</div><!-- .ffc-settings-wrap -->

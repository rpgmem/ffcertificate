<?php
/**
 * SMTP Settings Tab
 * @version 3.1.0 - Added user creation email controls (submission & migration)
 */

if (!defined('ABSPATH')) exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables scoped to this file

$wp_ffcertificate_get_option = function($key, $default = '') {
    $settings = get_option('ffc_settings', array());
    return isset($settings[$key]) ? $settings[$key] : $default;
};
?>

<div class="ffc-settings-wrap">

<div class="card">
    <h2>📧 <?php esc_html_e('Email Configuration', 'wp-ffcertificate'); ?></h2>
    
    <form method="post">
        <?php wp_nonce_field('ffc_settings_action', 'ffc_settings_nonce'); ?>
        <input type="hidden" name="_ffc_tab" value="smtp">

        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="disable_all_emails"><?php esc_html_e('Email Status', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[disable_all_emails]" id="disable_all_emails" value="1" <?php checked('1', $wp_ffcertificate_get_option('disable_all_emails')); ?>>
                            <strong class="ffc-text-error"><?php esc_html_e('Disable ALL emails from this plugin globally', 'wp-ffcertificate'); ?></strong>
                        </label>
                        <p class="description">
                            <?php esc_html_e('When enabled, the plugin will NOT send any emails (certificates, notifications, password resets, etc.). Use this for testing or if you want to completely disable email functionality.', 'wp-ffcertificate'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="send_wp_user_email_submission"><?php esc_html_e('User Creation Emails (Submission)', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <fieldset>
                            <label>
                                <input type="radio" name="ffc_settings[send_wp_user_email_submission]" value="1" <?php checked('1', $wp_ffcertificate_get_option('send_wp_user_email_submission', '1')); ?>>
                                <strong><?php esc_html_e('Enabled', 'wp-ffcertificate'); ?></strong>
                            </label>
                            <br>
                            <label>
                                <input type="radio" name="ffc_settings[send_wp_user_email_submission]" value="0" <?php checked('0', $wp_ffcertificate_get_option('send_wp_user_email_submission', '1')); ?>>
                                <strong><?php esc_html_e('Disabled', 'wp-ffcertificate'); ?></strong>
                            </label>
                        </fieldset>
                        <p class="description">
                            <?php esc_html_e('Send welcome email when a new WordPress user is created via form submission. The email contains a password reset link.', 'wp-ffcertificate'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="send_wp_user_email_migration"><?php esc_html_e('User Creation Emails (Migration)', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <fieldset>
                            <label>
                                <input type="radio" name="ffc_settings[send_wp_user_email_migration]" value="1" <?php checked('1', $wp_ffcertificate_get_option('send_wp_user_email_migration', '0')); ?>>
                                <strong><?php esc_html_e('Enabled', 'wp-ffcertificate'); ?></strong>
                            </label>
                            <br>
                            <label>
                                <input type="radio" name="ffc_settings[send_wp_user_email_migration]" value="0" <?php checked('0', $wp_ffcertificate_get_option('send_wp_user_email_migration', '0')); ?>>
                                <strong><?php esc_html_e('Disabled (Recommended)', 'wp-ffcertificate'); ?></strong>
                            </label>
                        </fieldset>
                        <p class="description">
                            <?php esc_html_e('Send welcome email when a new WordPress user is created during migration. Recommended to keep disabled to avoid sending bulk emails.', 'wp-ffcertificate'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('Mode', 'wp-ffcertificate'); ?></label>
                    </th>
                    <td>
                        <fieldset id="smtp-mode-options">
                            <label>
                                <input type="radio" name="ffc_settings[smtp_mode]" value="wp" <?php checked('wp', $wp_ffcertificate_get_option('smtp_mode')); ?>>
                                <strong><?php esc_html_e('WP Default (PHPMail)', 'wp-ffcertificate'); ?></strong>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Uses WordPress default mail function. Simple but may have deliverability issues.', 'wp-ffcertificate'); ?>
                            </p>

                            <label>
                                <input type="radio" name="ffc_settings[smtp_mode]" value="custom" <?php checked('custom', $wp_ffcertificate_get_option('smtp_mode')); ?>>
                                <strong><?php esc_html_e('Custom SMTP', 'wp-ffcertificate'); ?></strong>
                            </label>
                            <p class="description">
                                <?php esc_html_e('Use an external SMTP server. Better deliverability and tracking.', 'wp-ffcertificate'); ?>
                            </p>
                        </fieldset>
                    </td>
                </tr>
            </tbody>
        </table>
        
        <div id="smtp-options" class="ffc-collapsible-section <?php echo esc_attr( ($wp_ffcertificate_get_option('smtp_mode') === 'custom') ? '' : 'ffc-hidden' ); ?>">
            <div class="ffc-collapsible-content active">
                <h3><?php esc_html_e('SMTP Server Configuration', 'wp-ffcertificate'); ?></h3>
                
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="smtp_host"><?php esc_html_e('SMTP Host', 'wp-ffcertificate'); ?></label></th>
                            <td>
                                <input type="text" name="ffc_settings[smtp_host]" id="smtp_host" value="<?php echo esc_attr($wp_ffcertificate_get_option('smtp_host')); ?>" class="regular-text" placeholder="smtp.gmail.com">
                                <p class="description"><?php esc_html_e('Your SMTP server address', 'wp-ffcertificate'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="smtp_port"><?php esc_html_e('SMTP Port', 'wp-ffcertificate'); ?></label></th>
                            <td>
                                <input type="number" name="ffc_settings[smtp_port]" id="smtp_port" value="<?php echo esc_attr($wp_ffcertificate_get_option('smtp_port')); ?>" class="small-text" placeholder="587">
                                <p class="description"><?php esc_html_e('Common ports: 587 (TLS), 465 (SSL), 25 (unencrypted)', 'wp-ffcertificate'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="smtp_user"><?php esc_html_e('Username', 'wp-ffcertificate'); ?></label></th>
                            <td>
                                <input type="text" name="ffc_settings[smtp_user]" id="smtp_user" value="<?php echo esc_attr($wp_ffcertificate_get_option('smtp_user')); ?>" class="regular-text" autocomplete="username">
                                <p class="description"><?php esc_html_e('Your SMTP username (usually your email)', 'wp-ffcertificate'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="smtp_pass"><?php esc_html_e('Password', 'wp-ffcertificate'); ?></label></th>
                            <td>
                                <input type="password" name="ffc_settings[smtp_pass]" id="smtp_pass" value="<?php echo esc_attr($wp_ffcertificate_get_option('smtp_pass')); ?>" class="regular-text" autocomplete="current-password">
                                <p class="description"><?php esc_html_e('Your SMTP password or app-specific password', 'wp-ffcertificate'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="smtp_secure"><?php esc_html_e('Encryption', 'wp-ffcertificate'); ?></label></th>
                            <td>
                                <select name="ffc_settings[smtp_secure]" id="smtp_secure">
                                    <option value="tls" <?php selected('tls', $wp_ffcertificate_get_option('smtp_secure')); ?>>TLS (recommended)</option>
                                    <option value="ssl" <?php selected('ssl', $wp_ffcertificate_get_option('smtp_secure')); ?>>SSL</option>
                                    <option value="none" <?php selected('none', $wp_ffcertificate_get_option('smtp_secure')); ?>>None (not recommended)</option>
                                </select>
                                <p class="description"><?php esc_html_e('TLS is recommended for port 587, SSL for port 465', 'wp-ffcertificate'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="smtp_from_email"><?php esc_html_e('From Email', 'wp-ffcertificate'); ?></label></th>
                            <td>
                                <input type="email" name="ffc_settings[smtp_from_email]" id="smtp_from_email" value="<?php echo esc_attr($wp_ffcertificate_get_option('smtp_from_email')); ?>" class="regular-text">
                                <p class="description"><?php esc_html_e('Email address to send from', 'wp-ffcertificate'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="smtp_from_name"><?php esc_html_e('From Name', 'wp-ffcertificate'); ?></label></th>
                            <td>
                                <input type="text" name="ffc_settings[smtp_from_name]" id="smtp_from_name" value="<?php echo esc_attr($wp_ffcertificate_get_option('smtp_from_name')); ?>" class="regular-text">
                                <p class="description"><?php esc_html_e('Name to display as sender', 'wp-ffcertificate'); ?></p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        
        <?php submit_button(); ?>
    </form>
</div>

<div class="card">
    <h2>💡 <?php esc_html_e('Popular SMTP Providers', 'wp-ffcertificate'); ?></h2>
    
    <div class="ffc-provider-grid">
        <div class="ffc-provider-card gmail">
            <h4>Gmail</h4>
            <p><strong>Host:</strong> smtp.gmail.com</p>
            <p><strong>Port:</strong> 587 (TLS)</p>
            <p><strong>Note:</strong> <?php esc_html_e('Use app-specific password', 'wp-ffcertificate'); ?></p>
        </div>
        
        <div class="ffc-provider-card outlook">
            <h4>Outlook/Office 365</h4>
            <p><strong>Host:</strong> smtp.office365.com</p>
            <p><strong>Port:</strong> 587 (TLS)</p>
            <p><strong>Note:</strong> <?php esc_html_e('Full email as username', 'wp-ffcertificate'); ?></p>
        </div>
        
        <div class="ffc-provider-card sendgrid">
            <h4>SendGrid</h4>
            <p><strong>Host:</strong> smtp.sendgrid.net</p>
            <p><strong>Port:</strong> 587 (TLS)</p>
            <p><strong>Note:</strong> <?php esc_html_e('Use API key as password', 'wp-ffcertificate'); ?></p>
        </div>

        <div class="ffc-provider-card">
            <h4>Hostinger</h4>
            <p><strong>Host:</strong> smtp.hostinger.com</p>
            <p><strong>Port:</strong> 465 (SSL) 587 (TLS/STARTTLS)</p>
            <p><strong>Note:</strong> <?php esc_html_e('Use API key as password, or find your settings in hPanel.', 'wp-ffcertificate'); ?></p>
            
        </div>
    </div>
</div>

</div><!-- .ffc-settings-wrap -->
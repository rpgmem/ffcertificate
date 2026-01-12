<?php
/**
 * SMTP Settings Tab
 * @version 3.0.0 - Clean, no inline styles
 */

if (!defined('ABSPATH')) exit;

$get_option = function($key, $default = '') {
    $settings = get_option('ffc_settings', array());
    return isset($settings[$key]) ? $settings[$key] : $default;
};
?>

<div class="ffc-settings-wrap">

<div class="card">
    <h2>📧 <?php esc_html_e('Email Configuration', 'ffc'); ?></h2>
    
    <form method="post">
        <?php wp_nonce_field('ffc_settings_action', 'ffc_settings_nonce'); ?>
        <input type="hidden" name="_ffc_tab" value="smtp">
        
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('Mode', 'ffc'); ?></label>
                    </th>
                    <td>
                        <fieldset>
                            <label>
                                <input type="radio" name="ffc_settings[smtp_mode]" value="wp" <?php checked('wp', $get_option('smtp_mode')); ?>>
                                <strong><?php esc_html_e('WP Default (PHPMail)', 'ffc'); ?></strong>
                            </label>
                            <p class="description">
                                <?php _e('Uses WordPress default mail function. Simple but may have deliverability issues.', 'ffc'); ?>
                            </p>
                            
                            <label>
                                <input type="radio" name="ffc_settings[smtp_mode]" value="custom" <?php checked('custom', $get_option('smtp_mode')); ?>>
                                <strong><?php esc_html_e('Custom SMTP', 'ffc'); ?></strong>
                            </label>
                            <p class="description">
                                <?php _e('Use an external SMTP server. Better deliverability and tracking.', 'ffc'); ?>
                            </p>
                        </fieldset>
                    </td>
                </tr>
            </tbody>
        </table>
        
        <div id="smtp-options" class="ffc-collapsible-section <?php echo ($get_option('smtp_mode') === 'custom') ? '' : 'ffc-hidden'; ?>">
            <div class="ffc-collapsible-content active">
                <h3><?php _e('SMTP Server Configuration', 'ffc'); ?></h3>
                
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="smtp_host"><?php esc_html_e('SMTP Host', 'ffc'); ?></label></th>
                            <td>
                                <input type="text" name="ffc_settings[smtp_host]" id="smtp_host" value="<?php echo esc_attr($get_option('smtp_host')); ?>" class="regular-text" placeholder="smtp.gmail.com">
                                <p class="description"><?php _e('Your SMTP server address', 'ffc'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="smtp_port"><?php esc_html_e('SMTP Port', 'ffc'); ?></label></th>
                            <td>
                                <input type="number" name="ffc_settings[smtp_port]" id="smtp_port" value="<?php echo esc_attr($get_option('smtp_port')); ?>" class="small-text" placeholder="587">
                                <p class="description"><?php _e('Common ports: 587 (TLS), 465 (SSL), 25 (unencrypted)', 'ffc'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="smtp_user"><?php esc_html_e('Username', 'ffc'); ?></label></th>
                            <td>
                                <input type="text" name="ffc_settings[smtp_user]" id="smtp_user" value="<?php echo esc_attr($get_option('smtp_user')); ?>" class="regular-text" autocomplete="username">
                                <p class="description"><?php _e('Your SMTP username (usually your email)', 'ffc'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="smtp_pass"><?php esc_html_e('Password', 'ffc'); ?></label></th>
                            <td>
                                <input type="password" name="ffc_settings[smtp_pass]" id="smtp_pass" value="<?php echo esc_attr($get_option('smtp_pass')); ?>" class="regular-text" autocomplete="current-password">
                                <p class="description"><?php _e('Your SMTP password or app-specific password', 'ffc'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="smtp_secure"><?php esc_html_e('Encryption', 'ffc'); ?></label></th>
                            <td>
                                <select name="ffc_settings[smtp_secure]" id="smtp_secure">
                                    <option value="tls" <?php selected('tls', $get_option('smtp_secure')); ?>>TLS (recommended)</option>
                                    <option value="ssl" <?php selected('ssl', $get_option('smtp_secure')); ?>>SSL</option>
                                    <option value="none" <?php selected('none', $get_option('smtp_secure')); ?>>None (not recommended)</option>
                                </select>
                                <p class="description"><?php _e('TLS is recommended for port 587, SSL for port 465', 'ffc'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="smtp_from_email"><?php esc_html_e('From Email', 'ffc'); ?></label></th>
                            <td>
                                <input type="email" name="ffc_settings[smtp_from_email]" id="smtp_from_email" value="<?php echo esc_attr($get_option('smtp_from_email')); ?>" class="regular-text">
                                <p class="description"><?php _e('Email address to send from', 'ffc'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="smtp_from_name"><?php esc_html_e('From Name', 'ffc'); ?></label></th>
                            <td>
                                <input type="text" name="ffc_settings[smtp_from_name]" id="smtp_from_name" value="<?php echo esc_attr($get_option('smtp_from_name')); ?>" class="regular-text">
                                <p class="description"><?php _e('Name to display as sender', 'ffc'); ?></p>
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
    <h2>💡 <?php _e('Popular SMTP Providers', 'ffc'); ?></h2>
    
    <div class="ffc-provider-grid">
        <div class="ffc-provider-card gmail">
            <h4>Gmail</h4>
            <p><strong>Host:</strong> smtp.gmail.com</p>
            <p><strong>Port:</strong> 587 (TLS)</p>
            <p><strong>Note:</strong> Use app-specific password</p>
        </div>
        
        <div class="ffc-provider-card outlook">
            <h4>Outlook/Office 365</h4>
            <p><strong>Host:</strong> smtp.office365.com</p>
            <p><strong>Port:</strong> 587 (TLS)</p>
            <p><strong>Note:</strong> Full email as username</p>
        </div>
        
        <div class="ffc-provider-card sendgrid">
            <h4>SendGrid</h4>
            <p><strong>Host:</strong> smtp.sendgrid.net</p>
            <p><strong>Port:</strong> 587 (TLS)</p>
            <p><strong>Note:</strong> Use API key as password</p>
        </div>
    </div>
</div>

</div><!-- .ffc-settings-wrap -->

<script>
jQuery(document).ready(function($) {
    $('input[name="ffc_settings[smtp_mode]"]').on('change', function() {
        if ($(this).val() === 'custom') {
            $('#smtp-options').removeClass('ffc-hidden').slideDown(200);
        } else {
            $('#smtp-options').slideUp(200, function() {
                $(this).addClass('ffc-hidden');
            });
        }
    });
});
</script>
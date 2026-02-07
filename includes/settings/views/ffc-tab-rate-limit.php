<?php
if (!defined('ABSPATH')) exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables scoped to this file
$wp_ffcertificate_s = $settings;
$wp_ffcertificate_stats = \FreeFormCertificate\Security\RateLimiter::get_stats();
?>
<div class="ffc-rate-limit-wrap">
<form method="post">
<?php wp_nonce_field('ffc_rate_limit_nonce'); ?>

<div class="card">
    <h2><?php esc_html_e('IP Rate Limit', 'wp-ffcertificate'); ?></h2>
    <p><label><input type="checkbox" name="ip_enabled" <?php checked($wp_ffcertificate_s['ip']['enabled']); ?>> <?php esc_html_e('Enable', 'wp-ffcertificate'); ?></label></p>
    <table class="form-table" role="presentation"><tbody>
        <tr><th><?php esc_html_e('Max per hour', 'wp-ffcertificate'); ?></th><td><input type="number" name="ip_max_per_hour" value="<?php echo esc_attr($wp_ffcertificate_s['ip']['max_per_hour']); ?>" min="1" max="1000"></td></tr>
        <tr><th><?php esc_html_e('Max per day', 'wp-ffcertificate'); ?></th><td><input type="number" name="ip_max_per_day" value="<?php echo esc_attr($wp_ffcertificate_s['ip']['max_per_day']); ?>" min="1" max="10000"></td></tr>
        <tr><th><?php esc_html_e('Cooldown (sec)', 'wp-ffcertificate'); ?></th><td><input type="number" name="ip_cooldown_seconds" value="<?php echo esc_attr($wp_ffcertificate_s['ip']['cooldown_seconds']); ?>" min="1" max="3600"></td></tr>
        <tr><th><?php esc_html_e('Apply to', 'wp-ffcertificate'); ?></th><td><select name="ip_apply_to"><option value="all"><?php esc_html_e('All forms', 'wp-ffcertificate'); ?></option></select></td></tr>
        <tr><th><?php esc_html_e('Message', 'wp-ffcertificate'); ?></th><td><textarea name="ip_message" rows="3" class="large-text"><?php echo esc_textarea($wp_ffcertificate_s['ip']['message']); ?></textarea></td></tr>
    </tbody></table>
</div>

<div class="card">
    <h2><?php esc_html_e('Email Rate Limit', 'wp-ffcertificate'); ?></h2>
    <p><label><input type="checkbox" name="email_enabled" <?php checked($wp_ffcertificate_s['email']['enabled']); ?>> <?php esc_html_e('Enable', 'wp-ffcertificate'); ?></label></p>
    <p><label><input type="checkbox" name="email_check_database" <?php checked($wp_ffcertificate_s['email']['check_database']); ?>> <?php esc_html_e('Check database', 'wp-ffcertificate'); ?></label></p>
    <table class="form-table" role="presentation"><tbody>
        <tr><th><?php esc_html_e('Max per day', 'wp-ffcertificate'); ?></th><td><input type="number" name="email_max_per_day" value="<?php echo esc_attr($wp_ffcertificate_s['email']['max_per_day']); ?>" min="1"></td></tr>
        <tr><th><?php esc_html_e('Max per week', 'wp-ffcertificate'); ?></th><td><input type="number" name="email_max_per_week" value="<?php echo esc_attr($wp_ffcertificate_s['email']['max_per_week']); ?>" min="1"></td></tr>
        <tr><th><?php esc_html_e('Max per month', 'wp-ffcertificate'); ?></th><td><input type="number" name="email_max_per_month" value="<?php echo esc_attr($wp_ffcertificate_s['email']['max_per_month']); ?>" min="1"></td></tr>
        <tr><th><?php esc_html_e('Message', 'wp-ffcertificate'); ?></th><td><textarea name="email_message" rows="3" class="large-text"><?php echo esc_textarea($wp_ffcertificate_s['email']['message']); ?></textarea></td></tr>
    </tbody></table>
</div>

<div class="card">
    <h2><?php esc_html_e('Tax ID (CPF) Rate Limit', 'wp-ffcertificate'); ?></h2>
    <p><label><input type="checkbox" name="cpf_enabled" <?php checked($wp_ffcertificate_s['cpf']['enabled']); ?>> <?php esc_html_e('Enable', 'wp-ffcertificate'); ?></label></p>
    <p><label><input type="checkbox" name="cpf_check_database" <?php checked($wp_ffcertificate_s['cpf']['check_database']); ?>> <?php esc_html_e('Check database', 'wp-ffcertificate'); ?></label></p>
    <table class="form-table" role="presentation"><tbody>
        <tr><th><?php esc_html_e('Max per month', 'wp-ffcertificate'); ?></th><td><input type="number" name="cpf_max_per_month" value="<?php echo esc_attr($wp_ffcertificate_s['cpf']['max_per_month']); ?>" min="1"></td></tr>
        <tr><th><?php esc_html_e('Max per year', 'wp-ffcertificate'); ?></th><td><input type="number" name="cpf_max_per_year" value="<?php echo esc_attr($wp_ffcertificate_s['cpf']['max_per_year']); ?>" min="1"></td></tr>
        <tr>
            <th><?php esc_html_e('Block after', 'wp-ffcertificate'); ?></th>
            <td>
                <?php
                echo wp_kses(
                    sprintf(
                        /* translators: %1$s: attempts input field, %2$s: hours input field */
                        __('%1$s attempts in %2$s hour(s)', 'wp-ffcertificate'),
                        '<input type="number" name="cpf_block_threshold" value="' . esc_attr($wp_ffcertificate_s['cpf']['block_threshold']) . '" min="1">',
                        '<input type="number" name="cpf_block_hours" value="' . esc_attr($wp_ffcertificate_s['cpf']['block_hours']) . '" min="1">'
                    ),
                    array( 'input' => array( 'type' => true, 'name' => true, 'value' => true, 'min' => true ) )
                ); ?>
            </td>
        </tr>
        <tr>
            <th><?php esc_html_e('Block duration', 'wp-ffcertificate'); ?></th>
            <td>
                <?php
                echo wp_kses(
                    sprintf(
                        /* translators: %1$s: duration input field */
                        __('%1$s hours', 'wp-ffcertificate'),
                        '<input type="number" name="cpf_block_duration" value="' . esc_attr($wp_ffcertificate_s['cpf']['block_duration']) . '" min="1">'
                    ),
                    array( 'input' => array( 'type' => true, 'name' => true, 'value' => true, 'min' => true ) )
                ); ?>
            </td>
        </tr>
        <tr><th><?php esc_html_e('Message', 'wp-ffcertificate'); ?></th><td><textarea name="cpf_message" rows="3" class="large-text"><?php echo esc_textarea($wp_ffcertificate_s['cpf']['message']); ?></textarea></td></tr>
    </tbody></table>
</div>

<div class="card">
    <h2><?php esc_html_e('Global Rate Limit', 'wp-ffcertificate'); ?></h2>
    <p><label><input type="checkbox" name="global_enabled" <?php checked($wp_ffcertificate_s['global']['enabled']); ?>> <?php esc_html_e('Enable', 'wp-ffcertificate'); ?></label></p>
    <table class="form-table" role="presentation"><tbody>
        <tr><th><?php esc_html_e('Max per minute', 'wp-ffcertificate'); ?></th><td><input type="number" name="global_max_per_minute" value="<?php echo esc_attr($wp_ffcertificate_s['global']['max_per_minute']); ?>" min="1"></td></tr>
        <tr><th><?php esc_html_e('Max per hour', 'wp-ffcertificate'); ?></th><td><input type="number" name="global_max_per_hour" value="<?php echo esc_attr($wp_ffcertificate_s['global']['max_per_hour']); ?>" min="1"></td></tr>
        <tr><th><?php esc_html_e('Message', 'wp-ffcertificate'); ?></th><td><textarea name="global_message" rows="3" class="large-text"><?php echo esc_textarea($wp_ffcertificate_s['global']['message']); ?></textarea></td></tr>
    </tbody></table>
</div>

<div class="card">
    <h2><?php esc_html_e('Whitelist', 'wp-ffcertificate'); ?></h2>
    <table class="form-table" role="presentation"><tbody>
        <tr><th><?php esc_html_e('IPs', 'wp-ffcertificate'); ?></th><td><textarea name="whitelist_ips" rows="5" class="large-text"><?php echo esc_textarea(implode("\n", $wp_ffcertificate_s['whitelist']['ips'])); ?></textarea><p class="description"><?php esc_html_e('One per line', 'wp-ffcertificate'); ?></p></td></tr>
        <tr><th><?php esc_html_e('Emails', 'wp-ffcertificate'); ?></th><td><textarea name="whitelist_emails" rows="5" class="large-text"><?php echo esc_textarea(implode("\n", $wp_ffcertificate_s['whitelist']['emails'])); ?></textarea></td></tr>
        <tr><th><?php esc_html_e('Domains', 'wp-ffcertificate'); ?></th><td><textarea name="whitelist_email_domains" rows="5" class="large-text"><?php echo esc_textarea(implode("\n", $wp_ffcertificate_s['whitelist']['email_domains'])); ?></textarea><p class="description"><?php esc_html_e('Format: *@domain.com', 'wp-ffcertificate'); ?></p></td></tr>
        <tr><th><?php esc_html_e('Tax IDs (CPFs)', 'wp-ffcertificate'); ?></th><td><textarea name="whitelist_cpfs" rows="5" class="large-text"><?php echo esc_textarea(implode("\n", $wp_ffcertificate_s['whitelist']['cpfs'])); ?></textarea></td></tr>
    </tbody></table>
</div>

<div class="card">
    <h2><?php esc_html_e('Blacklist', 'wp-ffcertificate'); ?></h2>
    <table class="form-table" role="presentation"><tbody>
        <tr><th><?php esc_html_e('IPs', 'wp-ffcertificate'); ?></th><td><textarea name="blacklist_ips" rows="5" class="large-text"><?php echo esc_textarea(implode("\n", $wp_ffcertificate_s['blacklist']['ips'])); ?></textarea></td></tr>
        <tr><th><?php esc_html_e('Emails', 'wp-ffcertificate'); ?></th><td><textarea name="blacklist_emails" rows="5" class="large-text"><?php echo esc_textarea(implode("\n", $wp_ffcertificate_s['blacklist']['emails'])); ?></textarea></td></tr>
        <tr><th><?php esc_html_e('Domains', 'wp-ffcertificate'); ?></th><td><textarea name="blacklist_email_domains" rows="5" class="large-text"><?php echo esc_textarea(implode("\n", $wp_ffcertificate_s['blacklist']['email_domains'])); ?></textarea><p class="description"><?php esc_html_e('Format: *@domain.com', 'wp-ffcertificate'); ?></p></td></tr>
        <tr><th><?php esc_html_e('Tax IDs (CPFs)', 'wp-ffcertificate'); ?></th><td><textarea name="blacklist_cpfs" rows="5" class="large-text"><?php echo esc_textarea(implode("\n", $wp_ffcertificate_s['blacklist']['cpfs'])); ?></textarea></td></tr>
    </tbody></table>
</div>

<div class="card">
    <h2><?php esc_html_e('Logs', 'wp-ffcertificate'); ?></h2>
    <p><label><input type="checkbox" name="logging_enabled" <?php checked($wp_ffcertificate_s['logging']['enabled']); ?>> <?php esc_html_e('Enable logs', 'wp-ffcertificate'); ?></label></p>
    <p><label><input type="checkbox" name="logging_log_allowed" <?php checked($wp_ffcertificate_s['logging']['log_allowed']); ?>> <?php esc_html_e('Log allowed requests', 'wp-ffcertificate'); ?></label></p>
    <p><label><input type="checkbox" name="logging_log_blocked" <?php checked($wp_ffcertificate_s['logging']['log_blocked']); ?>> <?php esc_html_e('Log blocked requests', 'wp-ffcertificate'); ?></label></p>
    <table class="form-table" role="presentation"><tbody>
        <tr><th><?php esc_html_e('Retention', 'wp-ffcertificate'); ?></th><td><input type="number" name="logging_retention_days" value="<?php echo esc_attr($wp_ffcertificate_s['logging']['retention_days']); ?>" min="1"> <?php esc_html_e('days', 'wp-ffcertificate'); ?></td></tr>
        <tr><th><?php esc_html_e('Max logs', 'wp-ffcertificate'); ?></th><td><input type="number" name="logging_max_logs" value="<?php echo esc_attr($wp_ffcertificate_s['logging']['max_logs']); ?>" min="100"></td></tr>
    </tbody></table>
</div>

<div class="card">
    <h2><?php esc_html_e('Interface', 'wp-ffcertificate'); ?></h2>
    <p><label><input type="checkbox" name="ui_show_remaining" <?php checked($wp_ffcertificate_s['ui']['show_remaining']); ?>> <?php esc_html_e('Show remaining attempts', 'wp-ffcertificate'); ?></label></p>
    <p><label><input type="checkbox" name="ui_show_wait_time" <?php checked($wp_ffcertificate_s['ui']['show_wait_time']); ?>> <?php esc_html_e('Show wait time', 'wp-ffcertificate'); ?></label></p>
    <p><label><input type="checkbox" name="ui_countdown_timer" <?php checked($wp_ffcertificate_s['ui']['countdown_timer']); ?>> <?php esc_html_e('Countdown timer', 'wp-ffcertificate'); ?></label></p>
</div>

<div class="card">
    <h2><?php esc_html_e('Statistics', 'wp-ffcertificate'); ?></h2>
    <p><strong><?php esc_html_e('Blocked today:', 'wp-ffcertificate'); ?></strong> <?php echo esc_html(number_format($wp_ffcertificate_stats['today'])); ?></p>
    <p><strong><?php esc_html_e('Blocked (30 days):', 'wp-ffcertificate'); ?></strong> <?php echo esc_html(number_format($wp_ffcertificate_stats['month'])); ?></p>
<?php if (!empty($wp_ffcertificate_stats['by_type'])): ?>
    <h3><?php esc_html_e('By type:', 'wp-ffcertificate'); ?></h3>
    <ul><?php foreach ($wp_ffcertificate_stats['by_type'] as $wp_ffcertificate_t): ?>
        <li><?php echo esc_html($wp_ffcertificate_t['type']); ?>: <?php echo esc_html(number_format($wp_ffcertificate_t['count'])); ?></li>
    <?php endforeach; ?></ul>
<?php endif; ?>
<?php if (!empty($wp_ffcertificate_stats['top_ips'])): ?>
    <h3><?php esc_html_e('Top blocked IPs:', 'wp-ffcertificate'); ?></h3>
    <ol><?php foreach ($wp_ffcertificate_stats['top_ips'] as $wp_ffcertificate_ip): ?>
        <li><?php echo esc_html($wp_ffcertificate_ip['identifier']); ?> (<?php echo esc_html(number_format($wp_ffcertificate_ip['count'])); ?>x)</li>
    <?php endforeach; ?></ol>
<?php endif; ?>
</div>

<p class="submit"><input type="submit" name="ffc_save_rate_limit" class="button button-primary" value="<?php esc_attr_e('Save Changes', 'wp-ffcertificate'); ?>"></p>
</form>
</div>

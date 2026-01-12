<?php 
if (!defined('ABSPATH')) exit; 
$s = $settings; 
$stats = FFC_Rate_Limiter::get_stats(); 
$domain = 'ffc-rate-limiter';
?>
<div class="ffc-rate-limit-wrap">
<form method="post">
<?php wp_nonce_field('ffc_rate_limit_nonce'); ?>

<div class="card">
    <h2>🛡️ <?php _e('IP Rate Limit', $domain); ?></h2>
    <p><label><input type="checkbox" name="ip_enabled" <?php checked($s['ip']['enabled']); ?>> <?php _e('Enable', $domain); ?></label></p>
    <table class="form-table">
        <tr><th><?php _e('Max per hour', $domain); ?></th><td><input type="number" name="ip_max_per_hour" value="<?php echo $s['ip']['max_per_hour']; ?>" min="1" max="1000"></td></tr>
        <tr><th><?php _e('Max per day', $domain); ?></th><td><input type="number" name="ip_max_per_day" value="<?php echo $s['ip']['max_per_day']; ?>" min="1" max="10000"></td></tr>
        <tr><th><?php _e('Cooldown (sec)', $domain); ?></th><td><input type="number" name="ip_cooldown_seconds" value="<?php echo $s['ip']['cooldown_seconds']; ?>" min="1" max="3600"></td></tr>
        <tr><th><?php _e('Apply to', $domain); ?></th><td><select name="ip_apply_to"><option value="all"><?php _e('All forms', $domain); ?></option></select></td></tr>
        <tr><th><?php _e('Message', $domain); ?></th><td><textarea name="ip_message" rows="3" class="large-text"><?php echo esc_textarea($s['ip']['message']); ?></textarea></td></tr>
    </table>
</div>

<div class="card">
    <h2>📧 <?php _e('Email Rate Limit', $domain); ?></h2>
    <p><label><input type="checkbox" name="email_enabled" <?php checked($s['email']['enabled']); ?>> <?php _e('Enable', $domain); ?></label></p>
    <p><label><input type="checkbox" name="email_check_database" <?php checked($s['email']['check_database']); ?>> <?php _e('Check database', $domain); ?></label></p>
    <table class="form-table">
        <tr><th><?php _e('Max per day', $domain); ?></th><td><input type="number" name="email_max_per_day" value="<?php echo $s['email']['max_per_day']; ?>" min="1"></td></tr>
        <tr><th><?php _e('Max per week', $domain); ?></th><td><input type="number" name="email_max_per_week" value="<?php echo $s['email']['max_per_week']; ?>" min="1"></td></tr>
        <tr><th><?php _e('Max per month', $domain); ?></th><td><input type="number" name="email_max_per_month" value="<?php echo $s['email']['max_per_month']; ?>" min="1"></td></tr>
        <tr><th><?php _e('Message', $domain); ?></th><td><textarea name="email_message" rows="3" class="large-text"><?php echo esc_textarea($s['email']['message']); ?></textarea></td></tr>
    </table>
</div>

<div class="card">
    <h2>🆔 <?php _e('Tax ID (CPF) Rate Limit', $domain); ?></h2>
    <p><label><input type="checkbox" name="cpf_enabled" <?php checked($s['cpf']['enabled']); ?>> <?php _e('Enable', $domain); ?></label></p>
    <p><label><input type="checkbox" name="cpf_check_database" <?php checked($s['cpf']['check_database']); ?>> <?php _e('Check database', $domain); ?></label></p>
    <table class="form-table">
        <tr><th><?php _e('Max per month', $domain); ?></th><td><input type="number" name="cpf_max_per_month" value="<?php echo $s['cpf']['max_per_month']; ?>" min="1"></td></tr>
        <tr><th><?php _e('Max per year', $domain); ?></th><td><input type="number" name="cpf_max_per_year" value="<?php echo $s['cpf']['max_per_year']; ?>" min="1"></td></tr>
        <tr>
            <th><?php _e('Block after', $domain); ?></th>
            <td>
                <?php printf(
                    __('%1$s attempts in %2$s hour(s)', $domain),
                    '<input type="number" name="cpf_block_threshold" value="'.$s['cpf']['block_threshold'].'" min="1">',
                    '<input type="number" name="cpf_block_hours" value="'.$s['cpf']['block_hours'].'" min="1">'
                ); ?>
            </td>
        </tr>
        <tr>
            <th><?php _e('Block duration', $domain); ?></th>
            <td>
                <?php printf(
                    __('%1$s hours', $domain),
                    '<input type="number" name="cpf_block_duration" value="'.$s['cpf']['block_duration'].'" min="1">'
                ); ?>
            </td>
        </tr>
        <tr><th><?php _e('Message', $domain); ?></th><td><textarea name="cpf_message" rows="3" class="large-text"><?php echo esc_textarea($s['cpf']['message']); ?></textarea></td></tr>
    </table>
</div>

<div class="card">
    <h2>⚡ <?php _e('Global Rate Limit', $domain); ?></h2>
    <p><label><input type="checkbox" name="global_enabled" <?php checked($s['global']['enabled']); ?>> <?php _e('Enable', $domain); ?></label></p>
    <table class="form-table">
        <tr><th><?php _e('Max per minute', $domain); ?></th><td><input type="number" name="global_max_per_minute" value="<?php echo $s['global']['max_per_minute']; ?>" min="1"></td></tr>
        <tr><th><?php _e('Max per hour', $domain); ?></th><td><input type="number" name="global_max_per_hour" value="<?php echo $s['global']['max_per_hour']; ?>" min="1"></td></tr>
        <tr><th><?php _e('Message', $domain); ?></th><td><textarea name="global_message" rows="3" class="large-text"><?php echo esc_textarea($s['global']['message']); ?></textarea></td></tr>
    </table>
</div>

<div class="card">
    <h2>✅ <?php _e('Whitelist', $domain); ?></h2>
    <table class="form-table">
        <tr><th><?php _e('IPs', $domain); ?></th><td><textarea name="whitelist_ips" rows="5" class="large-text"><?php echo esc_textarea(implode("\n", $s['whitelist']['ips'])); ?></textarea><p class="description"><?php _e('One per line', $domain); ?></p></td></tr>
        <tr><th><?php _e('Emails', $domain); ?></th><td><textarea name="whitelist_emails" rows="5" class="large-text"><?php echo esc_textarea(implode("\n", $s['whitelist']['emails'])); ?></textarea></td></tr>
        <tr><th><?php _e('Domains', $domain); ?></th><td><textarea name="whitelist_email_domains" rows="5" class="large-text"><?php echo esc_textarea(implode("\n", $s['whitelist']['email_domains'])); ?></textarea><p class="description"><?php _e('Format: *@domain.com', $domain); ?></p></td></tr>
        <tr><th><?php _e('Tax IDs (CPFs)', $domain); ?></th><td><textarea name="whitelist_cpfs" rows="5" class="large-text"><?php echo esc_textarea(implode("\n", $s['whitelist']['cpfs'])); ?></textarea></td></tr>
    </table>
</div>

<div class="card">
    <h2>🚫 <?php _e('Blacklist', $domain); ?></h2>
    <table class="form-table">
        <tr><th><?php _e('IPs', $domain); ?></th><td><textarea name="blacklist_ips" rows="5" class="large-text"><?php echo esc_textarea(implode("\n", $s['blacklist']['ips'])); ?></textarea></td></tr>
        <tr><th><?php _e('Emails', $domain); ?></th><td><textarea name="blacklist_emails" rows="5" class="large-text"><?php echo esc_textarea(implode("\n", $s['blacklist']['emails'])); ?></textarea></td></tr>
        <tr><th><?php _e('Domains', $domain); ?></th><td><textarea name="blacklist_email_domains" rows="5" class="large-text"><?php echo esc_textarea(implode("\n", $s['blacklist']['email_domains'])); ?></textarea><p class="description"><?php _e('Format: *@domain.com', $domain); ?></p></td></tr>
        <tr><th><?php _e('Tax IDs (CPFs)', $domain); ?></th><td><textarea name="blacklist_cpfs" rows="5" class="large-text"><?php echo esc_textarea(implode("\n", $s['blacklist']['cpfs'])); ?></textarea></td></tr>
    </table>
</div>

<div class="card">
    <h2>📊 <?php _e('Logs', $domain); ?></h2>
    <p><label><input type="checkbox" name="logging_enabled" <?php checked($s['logging']['enabled']); ?>> <?php _e('Enable logs', $domain); ?></label></p>
    <p><label><input type="checkbox" name="logging_log_allowed" <?php checked($s['logging']['log_allowed']); ?>> <?php _e('Log allowed requests', $domain); ?></label></p>
    <p><label><input type="checkbox" name="logging_log_blocked" <?php checked($s['logging']['log_blocked']); ?>> <?php _e('Log blocked requests', $domain); ?></label></p>
    <table class="form-table">
        <tr><th><?php _e('Retention', $domain); ?></th><td><input type="number" name="logging_retention_days" value="<?php echo $s['logging']['retention_days']; ?>" min="1"> <?php _e('days', $domain); ?></td></tr>
        <tr><th><?php _e('Max logs', $domain); ?></th><td><input type="number" name="logging_max_logs" value="<?php echo $s['logging']['max_logs']; ?>" min="100"></td></tr>
    </table>
</div>

<div class="card">
    <h2>🎨 <?php _e('Interface', $domain); ?></h2>
    <p><label><input type="checkbox" name="ui_show_remaining" <?php checked($s['ui']['show_remaining']); ?>> <?php _e('Show remaining attempts', $domain); ?></label></p>
    <p><label><input type="checkbox" name="ui_show_wait_time" <?php checked($s['ui']['show_wait_time']); ?>> <?php _e('Show wait time', $domain); ?></label></p>
    <p><label><input type="checkbox" name="ui_countdown_timer" <?php checked($s['ui']['countdown_timer']); ?>> <?php _e('Countdown timer', $domain); ?></label></p>
</div>

<div class="card">
    <h2>📊 <?php _e('Statistics', $domain); ?></h2>
    <p><strong><?php _e('Blocked today:', $domain); ?></strong> <?php echo number_format($stats['today']); ?></p>
    <p><strong><?php _e('Blocked (30 days):', $domain); ?></strong> <?php echo number_format($stats['month']); ?></p>
<?php if (!empty($stats['by_type'])): ?>
    <h3><?php _e('By type:', $domain); ?></h3>
    <ul><?php foreach ($stats['by_type'] as $t): ?>
        <li><?php echo esc_html($t['type']); ?>: <?php echo number_format($t['count']); ?></li>
    <?php endforeach; ?></ul>
<?php endif; ?>
<?php if (!empty($stats['top_ips'])): ?>
    <h3><?php _e('Top blocked IPs:', $domain); ?></h3>
    <ol><?php foreach ($stats['top_ips'] as $ip): ?>
        <li><?php echo esc_html($ip['identifier']); ?> (<?php echo number_format($ip['count']); ?>x)</li>
    <?php endforeach; ?></ol>
<?php endif; ?>
</div>

<p class="submit"><input type="submit" name="ffc_save_rate_limit" class="button button-primary" value="<?php esc_attr_e('Save Changes', $domain); ?>"></p>
</form>
</div>
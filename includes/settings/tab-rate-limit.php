<?php 
if (!defined('ABSPATH')) exit; 
$s = $settings; 
$stats = FFC_Rate_Limiter::get_stats(); 
?>
<div class="ffc-rate-limit-wrap">
<form method="post">
<?php wp_nonce_field('ffc_rate_limit_nonce'); ?>

<div class="card">
    <h2>🛡️ <?php esc_html_e('IP Rate Limit', 'ffc'); ?></h2>
    <p><label><input type="checkbox" name="ip_enabled" <?php checked($s['ip']['enabled']); ?>> <?php esc_html_e('Enable', 'ffc'); ?></label></p>
    <table class="form-table">
        <tr><th><?php esc_html_e('Max per hour', 'ffc'); ?></th><td><input type="number" name="ip_max_per_hour" value="<?php echo $s['ip']['max_per_hour']; ?>" min="1" max="1000"></td></tr>
        <tr><th><?php esc_html_e('Max per day', 'ffc'); ?></th><td><input type="number" name="ip_max_per_day" value="<?php echo $s['ip']['max_per_day']; ?>" min="1" max="10000"></td></tr>
        <tr><th><?php esc_html_e('Cooldown (sec)', 'ffc'); ?></th><td><input type="number" name="ip_cooldown_seconds" value="<?php echo $s['ip']['cooldown_seconds']; ?>" min="1" max="3600"></td></tr>
        <tr><th><?php esc_html_e('Apply to', 'ffc'); ?></th><td><select name="ip_apply_to"><option value="all"><?php esc_html_e('All forms', 'ffc'); ?></option></select></td></tr>
        <tr><th><?php esc_html_e('Message', 'ffc'); ?></th><td><textarea name="ip_message" rows="3" class="large-text"><?php echo esc_textarea($s['ip']['message']); ?></textarea></td></tr>
    </table>
</div>

<div class="card">
    <h2>📧 <?php esc_html_e('Email Rate Limit', 'ffc'); ?></h2>
    <p><label><input type="checkbox" name="email_enabled" <?php checked($s['email']['enabled']); ?>> <?php esc_html_e('Enable', 'ffc'); ?></label></p>
    <p><label><input type="checkbox" name="email_check_database" <?php checked($s['email']['check_database']); ?>> <?php esc_html_e('Check database', 'ffc'); ?></label></p>
    <table class="form-table">
        <tr><th><?php esc_html_e('Max per day', 'ffc'); ?></th><td><input type="number" name="email_max_per_day" value="<?php echo $s['email']['max_per_day']; ?>" min="1"></td></tr>
        <tr><th><?php esc_html_e('Max per week', 'ffc'); ?></th><td><input type="number" name="email_max_per_week" value="<?php echo $s['email']['max_per_week']; ?>" min="1"></td></tr>
        <tr><th><?php esc_html_e('Max per month', 'ffc'); ?></th><td><input type="number" name="email_max_per_month" value="<?php echo $s['email']['max_per_month']; ?>" min="1"></td></tr>
        <tr><th><?php esc_html_e('Message', 'ffc'); ?></th><td><textarea name="email_message" rows="3" class="large-text"><?php echo esc_textarea($s['email']['message']); ?></textarea></td></tr>
    </table>
</div>

<div class="card">
    <h2>🆔 <?php esc_html_e('Tax ID (CPF) Rate Limit', 'ffc'); ?></h2>
    <p><label><input type="checkbox" name="cpf_enabled" <?php checked($s['cpf']['enabled']); ?>> <?php esc_html_e('Enable', 'ffc'); ?></label></p>
    <p><label><input type="checkbox" name="cpf_check_database" <?php checked($s['cpf']['check_database']); ?>> <?php esc_html_e('Check database', 'ffc'); ?></label></p>
    <table class="form-table">
        <tr><th><?php esc_html_e('Max per month', 'ffc'); ?></th><td><input type="number" name="cpf_max_per_month" value="<?php echo $s['cpf']['max_per_month']; ?>" min="1"></td></tr>
        <tr><th><?php esc_html_e('Max per year', 'ffc'); ?></th><td><input type="number" name="cpf_max_per_year" value="<?php echo $s['cpf']['max_per_year']; ?>" min="1"></td></tr>
        <tr>
            <th><?php esc_html_e('Block after', 'ffc'); ?></th>
            <td>
                <?php printf(
                    __('%1$s attempts in %2$s hour(s)', 'ffc'),
                    '<input type="number" name="cpf_block_threshold" value="'.$s['cpf']['block_threshold'].'" min="1">',
                    '<input type="number" name="cpf_block_hours" value="'.$s['cpf']['block_hours'].'" min="1">'
                ); ?>
            </td>
        </tr>
        <tr>
            <th><?php esc_html_e('Block duration', 'ffc'); ?></th>
            <td>
                <?php printf(
                    __('%1$s hours', 'ffc'),
                    '<input type="number" name="cpf_block_duration" value="'.$s['cpf']['block_duration'].'" min="1">'
                ); ?>
            </td>
        </tr>
        <tr><th><?php esc_html_e('Message', 'ffc'); ?></th><td><textarea name="cpf_message" rows="3" class="large-text"><?php echo esc_textarea($s['cpf']['message']); ?></textarea></td></tr>
    </table>
</div>

<div class="card">
    <h2>⚡ <?php esc_html_e('Global Rate Limit', 'ffc'); ?></h2>
    <p><label><input type="checkbox" name="global_enabled" <?php checked($s['global']['enabled']); ?>> <?php esc_html_e('Enable', 'ffc'); ?></label></p>
    <table class="form-table">
        <tr><th><?php esc_html_e('Max per minute', 'ffc'); ?></th><td><input type="number" name="global_max_per_minute" value="<?php echo $s['global']['max_per_minute']; ?>" min="1"></td></tr>
        <tr><th><?php esc_html_e('Max per hour', 'ffc'); ?></th><td><input type="number" name="global_max_per_hour" value="<?php echo $s['global']['max_per_hour']; ?>" min="1"></td></tr>
        <tr><th><?php esc_html_e('Message', 'ffc'); ?></th><td><textarea name="global_message" rows="3" class="large-text"><?php echo esc_textarea($s['global']['message']); ?></textarea></td></tr>
    </table>
</div>

<div class="card">
    <h2>✅ <?php esc_html_e('Whitelist', 'ffc'); ?></h2>
    <table class="form-table">
        <tr><th><?php esc_html_e('IPs', 'ffc'); ?></th><td><textarea name="whitelist_ips" rows="5" class="large-text"><?php echo esc_textarea(implode("\n", $s['whitelist']['ips'])); ?></textarea><p class="description"><?php esc_html_e('One per line', 'ffc'); ?></p></td></tr>
        <tr><th><?php esc_html_e('Emails', 'ffc'); ?></th><td><textarea name="whitelist_emails" rows="5" class="large-text"><?php echo esc_textarea(implode("\n", $s['whitelist']['emails'])); ?></textarea></td></tr>
        <tr><th><?php esc_html_e('Domains', 'ffc'); ?></th><td><textarea name="whitelist_email_domains" rows="5" class="large-text"><?php echo esc_textarea(implode("\n", $s['whitelist']['email_domains'])); ?></textarea><p class="description"><?php esc_html_e('Format: *@domain.com', 'ffc'); ?></p></td></tr>
        <tr><th><?php esc_html_e('Tax IDs (CPFs)', 'ffc'); ?></th><td><textarea name="whitelist_cpfs" rows="5" class="large-text"><?php echo esc_textarea(implode("\n", $s['whitelist']['cpfs'])); ?></textarea></td></tr>
    </table>
</div>

<div class="card">
    <h2>🚫 <?php esc_html_e('Blacklist', 'ffc'); ?></h2>
    <table class="form-table">
        <tr><th><?php esc_html_e('IPs', 'ffc'); ?></th><td><textarea name="blacklist_ips" rows="5" class="large-text"><?php echo esc_textarea(implode("\n", $s['blacklist']['ips'])); ?></textarea></td></tr>
        <tr><th><?php esc_html_e('Emails', 'ffc'); ?></th><td><textarea name="blacklist_emails" rows="5" class="large-text"><?php echo esc_textarea(implode("\n", $s['blacklist']['emails'])); ?></textarea></td></tr>
        <tr><th><?php esc_html_e('Domains', 'ffc'); ?></th><td><textarea name="blacklist_email_domains" rows="5" class="large-text"><?php echo esc_textarea(implode("\n", $s['blacklist']['email_domains'])); ?></textarea><p class="description"><?php esc_html_e('Format: *@domain.com', 'ffc'); ?></p></td></tr>
        <tr><th><?php esc_html_e('Tax IDs (CPFs)', 'ffc'); ?></th><td><textarea name="blacklist_cpfs" rows="5" class="large-text"><?php echo esc_textarea(implode("\n", $s['blacklist']['cpfs'])); ?></textarea></td></tr>
    </table>
</div>

<div class="card">
    <h2>📊 <?php esc_html_e('Logs', 'ffc'); ?></h2>
    <p><label><input type="checkbox" name="logging_enabled" <?php checked($s['logging']['enabled']); ?>> <?php esc_html_e('Enable logs', 'ffc'); ?></label></p>
    <p><label><input type="checkbox" name="logging_log_allowed" <?php checked($s['logging']['log_allowed']); ?>> <?php esc_html_e('Log allowed requests', 'ffc'); ?></label></p>
    <p><label><input type="checkbox" name="logging_log_blocked" <?php checked($s['logging']['log_blocked']); ?>> <?php esc_html_e('Log blocked requests', 'ffc'); ?></label></p>
    <table class="form-table">
        <tr><th><?php esc_html_e('Retention', 'ffc'); ?></th><td><input type="number" name="logging_retention_days" value="<?php echo $s['logging']['retention_days']; ?>" min="1"> <?php esc_html_e('days', 'ffc'); ?></td></tr>
        <tr><th><?php esc_html_e('Max logs', 'ffc'); ?></th><td><input type="number" name="logging_max_logs" value="<?php echo $s['logging']['max_logs']; ?>" min="100"></td></tr>
    </table>
</div>

<div class="card">
    <h2>🎨 <?php esc_html_e('Interface', 'ffc'); ?></h2>
    <p><label><input type="checkbox" name="ui_show_remaining" <?php checked($s['ui']['show_remaining']); ?>> <?php esc_html_e('Show remaining attempts', 'ffc'); ?></label></p>
    <p><label><input type="checkbox" name="ui_show_wait_time" <?php checked($s['ui']['show_wait_time']); ?>> <?php esc_html_e('Show wait time', 'ffc'); ?></label></p>
    <p><label><input type="checkbox" name="ui_countdown_timer" <?php checked($s['ui']['countdown_timer']); ?>> <?php esc_html_e('Countdown timer', 'ffc'); ?></label></p>
</div>

<div class="card">
    <h2>📊 <?php esc_html_e('Statistics', 'ffc'); ?></h2>
    <p><strong><?php esc_html_e('Blocked today:', 'ffc'); ?></strong> <?php echo number_format($stats['today']); ?></p>
    <p><strong><?php esc_html_e('Blocked (30 days):', 'ffc'); ?></strong> <?php echo number_format($stats['month']); ?></p>
<?php if (!empty($stats['by_type'])): ?>
    <h3><?php esc_html_e('By type:', 'ffc'); ?></h3>
    <ul><?php foreach ($stats['by_type'] as $t): ?>
        <li><?php echo esc_html($t['type']); ?>: <?php echo number_format($t['count']); ?></li>
    <?php endforeach; ?></ul>
<?php endif; ?>
<?php if (!empty($stats['top_ips'])): ?>
    <h3><?php esc_html_e('Top blocked IPs:', 'ffc'); ?></h3>
    <ol><?php foreach ($stats['top_ips'] as $ip): ?>
        <li><?php echo esc_html($ip['identifier']); ?> (<?php echo number_format($ip['count']); ?>x)</li>
    <?php endforeach; ?></ol>
<?php endif; ?>
</div>

<p class="submit"><input type="submit" name="ffc_save_rate_limit" class="button button-primary" value="<?php esc_attr_e('Save Changes', 'ffc'); ?>"></p>
</form>
</div>
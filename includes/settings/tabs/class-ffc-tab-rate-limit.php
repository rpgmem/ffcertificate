<?php
/**
 * Rate Limit Settings Tab
 */

if (!defined('ABSPATH')) exit;

class FFC_Tab_Rate_Limit extends FFC_Settings_Tab {
    
    protected function init() {
        $this->tab_id = 'rate_limit';
        $this->tab_title = __('Rate Limit', 'ffc');
        $this->tab_icon = '🛡️';
        $this->tab_order = 60;
    }
    
    private function get_settings() {
        $defaults = array(
            'ip' => array('enabled' => true, 'max_per_hour' => 5, 'max_per_day' => 20, 'cooldown_seconds' => 60, 'apply_to' => 'all', 'message' => 'Limite atingido. Aguarde {time}.'),
            'email' => array('enabled' => true, 'max_per_day' => 3, 'max_per_week' => 10, 'max_per_month' => 30, 'wait_hours' => 24, 'apply_to' => 'all', 'message' => 'Você já possui {count} certificados.', 'check_database' => true),
            'cpf' => array('enabled' => false, 'max_per_month' => 5, 'max_per_year' => 50, 'block_threshold' => 3, 'block_hours' => 1, 'block_duration' => 24, 'apply_to' => 'all', 'message' => 'Limite de CPF/RF atingido.', 'check_database' => true),
            'global' => array('enabled' => false, 'max_per_minute' => 100, 'max_per_hour' => 1000, 'message' => 'Sistema indisponível.'),
            'whitelist' => array('ips' => array(), 'emails' => array(), 'email_domains' => array(), 'cpfs' => array()),
            'blacklist' => array('ips' => array(), 'emails' => array(), 'email_domains' => array(), 'cpfs' => array()),
            'logging' => array('enabled' => true, 'log_allowed' => false, 'log_blocked' => true, 'retention_days' => 30, 'max_logs' => 10000),
            'ui' => array('show_remaining' => true, 'show_wait_time' => true, 'countdown_timer' => true)
        );
        return wp_parse_args(get_option('ffc_rate_limit_settings', array()), $defaults);
    }
    
    public function render() {
        if ($_POST && isset($_POST['ffc_save_rate_limit'])) {
            check_admin_referer('ffc_rate_limit_nonce');
            $this->save_settings();
            echo '<div class="notice notice-success"><p>Configurações salvas!</p></div>';
        }
        
        $settings = $this->get_settings();
        include FFC_PLUGIN_DIR . 'includes/settings/ffc-tab-rate-limit.php';
    }
    
    private function save_settings() {
        $settings = array(
            'ip' => array(
                'enabled' => isset($_POST['ip_enabled']),
                'max_per_hour' => absint($_POST['ip_max_per_hour'] ?? 5),
                'max_per_day' => absint($_POST['ip_max_per_day'] ?? 20),
                'cooldown_seconds' => absint($_POST['ip_cooldown_seconds'] ?? 60),
                'apply_to' => $_POST['ip_apply_to'] ?? 'all',
                'message' => sanitize_textarea_field($_POST['ip_message'] ?? '')
            ),
            'email' => array(
                'enabled' => isset($_POST['email_enabled']),
                'max_per_day' => absint($_POST['email_max_per_day'] ?? 3),
                'max_per_week' => absint($_POST['email_max_per_week'] ?? 10),
                'max_per_month' => absint($_POST['email_max_per_month'] ?? 30),
                'wait_hours' => absint($_POST['email_wait_hours'] ?? 24),
                'apply_to' => $_POST['email_apply_to'] ?? 'all',
                'message' => sanitize_textarea_field($_POST['email_message'] ?? ''),
                'check_database' => isset($_POST['email_check_database'])
            ),
            'cpf' => array(
                'enabled' => isset($_POST['cpf_enabled']),
                'max_per_month' => absint($_POST['cpf_max_per_month'] ?? 5),
                'max_per_year' => absint($_POST['cpf_max_per_year'] ?? 50),
                'block_threshold' => absint($_POST['cpf_block_threshold'] ?? 3),
                'block_hours' => absint($_POST['cpf_block_hours'] ?? 1),
                'block_duration' => absint($_POST['cpf_block_duration'] ?? 24),
                'apply_to' => $_POST['cpf_apply_to'] ?? 'all',
                'message' => sanitize_textarea_field($_POST['cpf_message'] ?? ''),
                'check_database' => isset($_POST['cpf_check_database'])
            ),
            'global' => array(
                'enabled' => isset($_POST['global_enabled']),
                'max_per_minute' => absint($_POST['global_max_per_minute'] ?? 100),
                'max_per_hour' => absint($_POST['global_max_per_hour'] ?? 1000),
                'message' => sanitize_textarea_field($_POST['global_message'] ?? '')
            ),
            'whitelist' => array(
                'ips' => array_filter(array_map('trim', explode("\n", $_POST['whitelist_ips'] ?? ''))),
                'emails' => array_filter(array_map('trim', explode("\n", $_POST['whitelist_emails'] ?? ''))),
                'email_domains' => array_filter(array_map('trim', explode("\n", $_POST['whitelist_email_domains'] ?? ''))),
                'cpfs' => array_filter(array_map('trim', explode("\n", $_POST['whitelist_cpfs'] ?? '')))
            ),
            'blacklist' => array(
                'ips' => array_filter(array_map('trim', explode("\n", $_POST['blacklist_ips'] ?? ''))),
                'emails' => array_filter(array_map('trim', explode("\n", $_POST['blacklist_emails'] ?? ''))),
                'email_domains' => array_filter(array_map('trim', explode("\n", $_POST['blacklist_email_domains'] ?? ''))),
                'cpfs' => array_filter(array_map('trim', explode("\n", $_POST['blacklist_cpfs'] ?? '')))
            ),
            'logging' => array(
                'enabled' => isset($_POST['logging_enabled']),
                'log_allowed' => isset($_POST['logging_log_allowed']),
                'log_blocked' => isset($_POST['logging_log_blocked']),
                'retention_days' => absint($_POST['logging_retention_days'] ?? 30),
                'max_logs' => absint($_POST['logging_max_logs'] ?? 10000)
            ),
            'ui' => array(
                'show_remaining' => isset($_POST['ui_show_remaining']),
                'show_wait_time' => isset($_POST['ui_show_wait_time']),
                'countdown_timer' => isset($_POST['ui_countdown_timer'])
            )
        );
        
        update_option('ffc_rate_limit_settings', $settings);
    }
}
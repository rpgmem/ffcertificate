<?php
declare(strict_types=1);

/**
 * Rate Limit Settings Tab
 *
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

namespace FreeFormCertificate\Settings\Tabs;

use FreeFormCertificate\Settings\SettingsTab;

if (!defined('ABSPATH')) exit;

class TabRateLimit extends SettingsTab {

    protected function init(): void {
        $this->tab_id = 'rate_limit';
        $this->tab_title = __('Rate Limit', 'ffcertificate');
        $this->tab_icon = '🛡️';
        $this->tab_order = 60;
    }
    
    private function get_settings(): array {
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
    
    public function render(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified below via check_admin_referer.
        if ($_POST && isset($_POST['ffc_save_rate_limit'])) {
            check_admin_referer('ffc_rate_limit_nonce');
            $this->save_settings();
            echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved!', 'ffcertificate' ) . '</p></div>';
        }
        
        $settings = $this->get_settings();
        include FFC_PLUGIN_DIR . 'includes/settings/views/ffc-tab-rate-limit.php';
    }
    
    private function save_settings(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified in render() via check_admin_referer.
        $settings = array(
            'ip' => array(
                'enabled' => isset($_POST['ip_enabled']),
                'max_per_hour' => absint(wp_unslash($_POST['ip_max_per_hour'] ?? 5)),
                'max_per_day' => absint(wp_unslash($_POST['ip_max_per_day'] ?? 20)),
                'cooldown_seconds' => absint(wp_unslash($_POST['ip_cooldown_seconds'] ?? 60)),
                'apply_to' => sanitize_text_field(wp_unslash($_POST['ip_apply_to'] ?? 'all')),
                'message' => sanitize_textarea_field(wp_unslash($_POST['ip_message'] ?? ''))
            ),
            'email' => array(
                'enabled' => isset($_POST['email_enabled']),
                'max_per_day' => absint(wp_unslash($_POST['email_max_per_day'] ?? 3)),
                'max_per_week' => absint(wp_unslash($_POST['email_max_per_week'] ?? 10)),
                'max_per_month' => absint(wp_unslash($_POST['email_max_per_month'] ?? 30)),
                'wait_hours' => absint(wp_unslash($_POST['email_wait_hours'] ?? 24)),
                'apply_to' => sanitize_text_field(wp_unslash($_POST['email_apply_to'] ?? 'all')),
                'message' => sanitize_textarea_field(wp_unslash($_POST['email_message'] ?? '')),
                'check_database' => isset($_POST['email_check_database'])
            ),
            'cpf' => array(
                'enabled' => isset($_POST['cpf_enabled']),
                'max_per_month' => absint(wp_unslash($_POST['cpf_max_per_month'] ?? 5)),
                'max_per_year' => absint(wp_unslash($_POST['cpf_max_per_year'] ?? 50)),
                'block_threshold' => absint(wp_unslash($_POST['cpf_block_threshold'] ?? 3)),
                'block_hours' => absint(wp_unslash($_POST['cpf_block_hours'] ?? 1)),
                'block_duration' => absint(wp_unslash($_POST['cpf_block_duration'] ?? 24)),
                'apply_to' => sanitize_text_field(wp_unslash($_POST['cpf_apply_to'] ?? 'all')),
                'message' => sanitize_textarea_field(wp_unslash($_POST['cpf_message'] ?? '')),
                'check_database' => isset($_POST['cpf_check_database'])
            ),
            'global' => array(
                'enabled' => isset($_POST['global_enabled']),
                'max_per_minute' => absint(wp_unslash($_POST['global_max_per_minute'] ?? 100)),
                'max_per_hour' => absint(wp_unslash($_POST['global_max_per_hour'] ?? 1000)),
                'message' => sanitize_textarea_field(wp_unslash($_POST['global_message'] ?? ''))
            ),
            'whitelist' => array(
                'ips' => array_filter(array_map('trim', explode("\n", sanitize_textarea_field(wp_unslash($_POST['whitelist_ips'] ?? ''))))),
                'emails' => array_filter(array_map('trim', explode("\n", sanitize_textarea_field(wp_unslash($_POST['whitelist_emails'] ?? ''))))),
                'email_domains' => array_filter(array_map('trim', explode("\n", sanitize_textarea_field(wp_unslash($_POST['whitelist_email_domains'] ?? ''))))),
                'cpfs' => array_filter(array_map('trim', explode("\n", sanitize_textarea_field(wp_unslash($_POST['whitelist_cpfs'] ?? '')))))
            ),
            'blacklist' => array(
                'ips' => array_filter(array_map('trim', explode("\n", sanitize_textarea_field(wp_unslash($_POST['blacklist_ips'] ?? ''))))),
                'emails' => array_filter(array_map('trim', explode("\n", sanitize_textarea_field(wp_unslash($_POST['blacklist_emails'] ?? ''))))),
                'email_domains' => array_filter(array_map('trim', explode("\n", sanitize_textarea_field(wp_unslash($_POST['blacklist_email_domains'] ?? ''))))),
                'cpfs' => array_filter(array_map('trim', explode("\n", sanitize_textarea_field(wp_unslash($_POST['blacklist_cpfs'] ?? '')))))
            ),
            'logging' => array(
                'enabled' => isset($_POST['logging_enabled']),
                'log_allowed' => isset($_POST['logging_log_allowed']),
                'log_blocked' => isset($_POST['logging_log_blocked']),
                'retention_days' => absint(wp_unslash($_POST['logging_retention_days'] ?? 30)),
                'max_logs' => absint(wp_unslash($_POST['logging_max_logs'] ?? 10000))
            ),
            'ui' => array(
                'show_remaining' => isset($_POST['ui_show_remaining']),
                'show_wait_time' => isset($_POST['ui_show_wait_time']),
                'countdown_timer' => isset($_POST['ui_countdown_timer'])
            )
        );
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        update_option('ffc_rate_limit_settings', $settings);
    }
}
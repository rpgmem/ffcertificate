<?php
declare(strict_types=1);

/**
 * SMTP Settings Tab
 *
 * @package FFC
 * @since 2.10.0
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

namespace FreeFormCertificate\Settings\Tabs;

use FreeFormCertificate\Settings\SettingsTab;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TabSMTP extends SettingsTab {

    protected function init(): void {
        $this->tab_id = 'smtp';
        $this->tab_title = __( 'SMTP', 'ffcertificate' );
        $this->tab_icon = 'ffc-icon-email';
        $this->tab_order = 30;

        // Enqueue scripts for this tab
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    /**
     * Enqueue scripts for SMTP settings page
     */
    public function enqueue_scripts(string $hook): void {
        // Only load on settings page with this tab active
        if ($hook !== 'ffc_form_page_ffc-settings') {
            return;
        }

        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab parameter for conditional script loading.
        if ($active_tab === 'smtp') {
            $s = \FreeFormCertificate\Core\Utils::asset_suffix();
            wp_enqueue_script(
                'ffc-smtp-settings',
                FFC_PLUGIN_URL . "assets/js/ffc-smtp-settings{$s}.js",
                array('jquery'),
                FFC_VERSION,
                true
            );
        }
    }

    public function render(): void {
        // Include view file
        $view_file = FFC_PLUGIN_DIR . 'includes/settings/views/ffc-tab-smtp.php';
        
        if ( file_exists( $view_file ) ) {
            include $view_file;
        } else {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__( 'SMTP settings view file not found.', 'ffcertificate' );
            echo '</p></div>';
        }
    }
    
    /**
     * Get option value (for view compatibility)
     */
    public static function get_option( string $key, string $default = '' ): string {
        $settings = get_option( 'ffc_settings', array() );
        return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
    }
}

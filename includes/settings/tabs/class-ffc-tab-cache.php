<?php
declare(strict_types=1);

/**
 * Cache & Performance Settings Tab
 *
 * Contains Form Cache and QR Code Cache settings
 *
 * @package FFC
 * @since 4.6.16
 */

namespace FreeFormCertificate\Settings\Tabs;

use FreeFormCertificate\Settings\SettingsTab;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TabCache extends SettingsTab {

    protected function init(): void {
        $this->tab_id = 'cache';
        $this->tab_title = __( 'Cache', 'ffcertificate' );
        $this->tab_icon = 'ffc-icon-package';
        $this->tab_order = 30;
    }

    public function render(): void {
        $view_file = FFC_PLUGIN_DIR . 'includes/settings/views/ffc-tab-cache.php';

        if ( file_exists( $view_file ) ) {
            $settings = $this;
            include $view_file;
        } else {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__( 'Cache settings view file not found.', 'ffcertificate' );
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

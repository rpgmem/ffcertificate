<?php
declare(strict_types=1);

/**
 * QR Code Settings Tab
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

class TabQRCode extends SettingsTab {

    protected function init(): void {
        $this->tab_id = 'qr_code';
        $this->tab_title = __( 'QR Code', 'ffcertificate' );
        $this->tab_icon = '📱';
        $this->tab_order = 40;
    }
    
    public function render(): void {
        // Include view file
        $view_file = FFC_PLUGIN_DIR . 'includes/settings/views/ffc-tab-qrcode.php';
        
        if ( file_exists( $view_file ) ) {
            include $view_file;
        } else {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__( 'QR Code settings view file not found.', 'ffcertificate' );
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

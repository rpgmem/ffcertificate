<?php
declare(strict_types=1);

/**
 * URL Shortener Settings Tab
 *
 * @since 5.1.0
 * @package FreeFormCertificate\Settings\Tabs
 */

namespace FreeFormCertificate\Settings\Tabs;

use FreeFormCertificate\Settings\SettingsTab;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TabUrlShortener extends SettingsTab {

    protected function init(): void {
        $this->tab_id    = 'url_shortener';
        $this->tab_title = __( 'URL Shortener', 'ffcertificate' );
        $this->tab_icon  = 'ffc-icon-link';
        $this->tab_order = 35;
    }

    public function render(): void {
        $view_file = FFC_PLUGIN_DIR . 'includes/settings/views/ffc-tab-url-shortener.php';

        if ( file_exists( $view_file ) ) {
            $settings = $this;
            include $view_file;
        } else {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__( 'URL Shortener settings view file not found.', 'ffcertificate' );
            echo '</p></div>';
        }
    }
}

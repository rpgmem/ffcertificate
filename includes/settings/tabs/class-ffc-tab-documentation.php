<?php
declare(strict_types=1);

/**
 * Documentation Tab
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

class TabDocumentation extends SettingsTab {

    protected function init(): void {
        $this->tab_id = 'documentation';
        $this->tab_title = __( 'Documentation', 'ffcertificate' );
        $this->tab_icon = 'ffc-icon-doc';
        $this->tab_order = 90;
    }
    
    public function render(): void {
        // Include view file
        $view_file = FFC_PLUGIN_DIR . 'includes/settings/views/ffc-tab-documentation.php';
        
        if ( file_exists( $view_file ) ) {
            include $view_file;
        } else {
            echo '<div class="notice notice-error"><p>';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo esc_html__( 'Documentation view file not found.', 'ffcertificate' );
            echo '</p></div>';
        }
    }
}

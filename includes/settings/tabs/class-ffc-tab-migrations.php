<?php
declare(strict_types=1);

/**
 * Data Migrations Tab
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

class TabMigrations extends SettingsTab {

    protected function init(): void {
        $this->tab_id = 'migrations';
        $this->tab_title = __( 'Data Migrations', 'ffc' );
        $this->tab_icon = '🔄';
        $this->tab_order = 50;
    }
    
    public function render(): void {
        // Include view file
        $view_file = FFC_PLUGIN_DIR . 'includes/settings/views/ffc-tab-migrations.php';
        
        if ( file_exists( $view_file ) ) {
            include $view_file;
        } else {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__( 'Migrations view file not found.', 'ffc' );
            echo '</p></div>';
        }
    }
}

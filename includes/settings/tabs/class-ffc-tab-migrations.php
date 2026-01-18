<?php
/**
 * Data Migrations Tab
 * 
 * @package FFC
 * @since 2.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Tab_Migrations extends FFC_Settings_Tab {
    
    protected function init() {
        $this->tab_id = 'migrations';
        $this->tab_title = __( 'Data Migrations', 'ffc' );
        $this->tab_icon = '🔄';
        $this->tab_order = 50;
    }
    
    public function render() {
        // Include view file
        $view_file = FFC_PLUGIN_DIR . 'includes/settings/ffc-tab-migrations.php';
        
        if ( file_exists( $view_file ) ) {
            include $view_file;
        } else {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__( 'Migrations view file not found.', 'ffc' );
            echo '</p></div>';
        }
    }
}

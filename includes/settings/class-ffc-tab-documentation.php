<?php
/**
 * Documentation Tab
 * 
 * @package FFC
 * @since 2.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Tab_Documentation extends FFC_Settings_Tab {
    
    protected function init() {
        $this->tab_id = 'documentation';
        $this->tab_title = __( 'Documentation', 'ffc' );
        $this->tab_icon = '📚';
        $this->tab_order = 10;
    }
    
    public function render() {
        // Include view file
        $view_file = FFC_PLUGIN_DIR . 'includes/settings/tab-documentation.php';
        
        if ( file_exists( $view_file ) ) {
            include $view_file;
        } else {
            echo '<div class="notice notice-error"><p>';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo esc_html__( 'Documentation view file not found.', 'ffc' );
            echo '</p></div>';
        }
    }
}

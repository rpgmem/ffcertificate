<?php
/**
 * SMTP Settings Tab
 * 
 * @package FFC
 * @since 2.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Tab_SMTP extends FFC_Settings_Tab {
    
    protected function init() {
        $this->tab_id = 'smtp';
        $this->tab_title = __( 'SMTP', 'ffc' );
        $this->tab_icon = '📧';
        $this->tab_order = 30;
    }
    
    public function render() {
        // Include view file
        $view_file = FFC_PLUGIN_DIR . 'includes/settings/ffc-tab-smtp.php';
        
        if ( file_exists( $view_file ) ) {
            include $view_file;
        } else {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__( 'SMTP settings view file not found.', 'ffc' );
            echo '</p></div>';
        }
    }
    
    /**
     * Get option value (for view compatibility)
     */
    public static function get_option( $key, $default = '' ) {
        $settings = get_option( 'ffc_settings', array() );
        return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
    }
}

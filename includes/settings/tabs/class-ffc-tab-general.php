<?php
declare(strict_types=1);

/**
 * General Settings Tab
 *
 * @package FFC
 * @since 2.10.0
 * @version 3.3.0 - Added strict types and type hints
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Tab_General extends FFC_Settings_Tab {

    private ?array $forms = null;
    private ?object $settings = null;

    protected function init(): void {
        $this->tab_id = 'general';
        $this->tab_title = __( 'General', 'ffc' );
        $this->tab_icon = '⚙️';
        $this->tab_order = 20;
    }
    
    public function render(): void {
        // Load FFC_Form_Cache if not already loaded
        if ( ! class_exists( 'FFC_Form_Cache' ) ) {
            require_once FFC_PLUGIN_DIR . 'includes/submissions/class-ffc-form-cache.php';
        }
        
        // Get forms for danger zone
        $this->forms = get_posts( array(
            'post_type' => 'ffc_form',
            'posts_per_page' => -1
        ) );
        
        // ✅ Create settings object for view compatibility
        $this->settings = new stdClass();
        $this->settings->get_option = function( $key, $default = '' ) {
            return self::get_option( $key, $default );
        };
        
        // Include view file
        $view_file = FFC_PLUGIN_DIR . 'includes/settings/views/ffc-tab-general.php';
        
        if ( file_exists( $view_file ) ) {
            // ✅ Make variables available to view
            $forms = $this->forms;
            $settings = $this; // ✅ Pass $this as $settings for compatibility
            
            include $view_file;
        } else {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__( 'General settings view file not found.', 'ffc' );
            echo '</p></div>';
        }
    }
    
    /**
     * Get option value (for view compatibility)
     */
    public function get_option( string $key, string $default = '' ): string {
        $settings = get_option( 'ffc_settings', array() );
        return isset( $settings[ $key ] ) ? $settings[ $key ] : $default;
    }
}
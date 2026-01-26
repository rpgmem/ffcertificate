<?php
/**
 * Abstract Base Class for Settings Tabs
 *
 * Provides common functionality for all settings tabs
 *
 * @package FFC
 * @since 2.10.0
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

declare(strict_types=1);

namespace FreeFormCertificate\Settings;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Ensure WordPress is loaded
if ( ! function_exists( 'wp_kses_post' ) ) {
    require_once( ABSPATH . 'wp-includes/formatting.php' );
}

abstract class SettingsTab {
    
    /**
     * Tab unique identifier
     * @var string
     */
    protected $tab_id;
    
    /**
     * Tab display title
     * @var string
     */
    protected $tab_title;
    
    /**
     * Tab icon (emoji or HTML)
     * @var string
     */
    protected $tab_icon;
    
    /**
     * Tab order/priority
     * @var int
     */
    protected $tab_order = 10;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->init();
    }
    
    /**
     * Initialize tab properties
     * Override this in child classes
     */
    protected function init() {
        // Override in child class
    }
    
    /**
     * Render tab content
     * Must be implemented by child classes
     */
    abstract public function render();
    
    /**
     * Get tab ID
     * @return string
     */
    public function get_id() {
        return $this->tab_id;
    }
    
    /**
     * Get tab title
     * @return string
     */
    public function get_title() {
        return $this->tab_title;
    }
    
    /**
     * Get tab icon
     * @return string
     */
    public function get_icon() {
        return $this->tab_icon;
    }
    
    /**
     * Get tab order
     * @return int
     */
    public function get_order() {
        return $this->tab_order;
    }
    
    /**
     * Render admin notice
     * 
     * @param string $message Notice message
     * @param string $type Notice type (success, error, warning, info)
     */
    protected function render_notice( $message, $type = 'success' ) {
        ?>
        <div class="notice notice-<?php echo esc_attr($type); ?> is-dismissible">
            <p><?php echo wp_kses_post($message); ?></p>
        </div>
        <?php
    }
    
    /**
     * Render settings section header
     * 
     * @param string $title Section title
     * @param string $description Section description (optional)
     */
    protected function render_section_header( $title, $description = '' ) {
        ?>
        <div class="ffc-section-header">
            <h2><?php echo esc_html($title); ?></h2>
            <?php if ( ! empty($description) ) : ?>
                <p class="description"><?php echo wp_kses_post($description); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render settings table row
     * 
     * @param string $label Field label
     * @param string $content Field HTML content
     * @param string $description Field description (optional)
     */
    protected function render_field_row( $label, $content, $description = '' ) {
        ?>
        <tr>
            <th scope="row">
                <label><?php echo esc_html($label); ?></label>
            </th>
            <td>
                <?php echo $content; // Already escaped in calling code ?>
                <?php if ( ! empty($description) ) : ?>
                    <p class="description"><?php echo wp_kses_post($description); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }
    
    /**
     * Check if current tab is active
     * @return bool
     */
    protected function is_active() {
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '';
        return $active_tab === $this->tab_id;
    }
    
    /**
     * Get tab URL
     * @return string
     */
    protected function get_tab_url() {
        return admin_url( 'edit.php?post_type=ffc_form&page=ffc-settings&tab=' . $this->tab_id );
    }
}
<?php
/**
 * User Access Settings Tab
 *
 * @package FFC
 * @since 3.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class FFC_Tab_User_Access extends FFC_Settings_Tab {

    protected function init() {
        $this->tab_id = 'user_access';
        $this->tab_title = __('User Access', 'ffc');
        $this->tab_icon = '👥';
        $this->tab_order = 85;

        // Enqueue styles for this tab
        add_action('admin_enqueue_scripts', array($this, 'enqueue_styles'));
    }

    /**
     * Enqueue styles for User Access settings page
     */
    public function enqueue_styles($hook) {
        // Only load on settings page with this tab active
        if ($hook !== 'ffc_form_page_ffc-settings') {
            return;
        }

        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : '';
        if ($active_tab === 'user_access') {
            wp_enqueue_style(
                'ffc-admin-user-access',
                FFC_PLUGIN_URL . 'assets/css/admin-user-access.css',
                array(),
                FFC_VERSION
            );
        }
    }

    public function render() {
        // Include view file
        $view_file = FFC_PLUGIN_DIR . 'includes/settings/views/ffc-tab-user-access.php';

        if (file_exists($view_file)) {
            // Make variables available to view
            $settings = $this;

            include $view_file;
        } else {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('User Access settings view file not found.', 'ffc');
            echo '</p></div>';
        }
    }

    /**
     * Get option value
     */
    public function get_option($key, $default = '') {
        $settings = get_option('ffc_user_access_settings', array());
        return isset($settings[$key]) ? $settings[$key] : $default;
    }

    /**
     * Save settings (called by parent class)
     */
    public function save_settings() {
        if (!isset($_POST['ffc_user_access_nonce']) || !wp_verify_nonce($_POST['ffc_user_access_nonce'], 'ffc_user_access_settings')) {
            return;
        }

        $settings = array(
            'block_wp_admin' => isset($_POST['block_wp_admin']),
            'blocked_roles' => isset($_POST['blocked_roles']) && is_array($_POST['blocked_roles']) ? $_POST['blocked_roles'] : array('ffc_user'),
            'redirect_url' => isset($_POST['redirect_url']) ? esc_url_raw($_POST['redirect_url']) : home_url('/dashboard'),
            'redirect_message' => isset($_POST['redirect_message']) ? sanitize_textarea_field($_POST['redirect_message']) : '',
            'allow_admin_bar' => isset($_POST['allow_admin_bar']),
            'bypass_for_admins' => isset($_POST['bypass_for_admins']),
        );

        update_option('ffc_user_access_settings', $settings);

        add_settings_error(
            'ffc_user_access_settings',
            'ffc_user_access_updated',
            __('User Access settings saved successfully.', 'ffc'),
            'updated'
        );
    }
}

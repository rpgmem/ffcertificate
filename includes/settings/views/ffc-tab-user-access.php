<?php
/**
 * User Access Settings View
 *
 * @package FFC
 * @since 3.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get current settings
$current_settings = get_option('ffc_user_access_settings', array());

// Defaults
$defaults = array(
    'block_wp_admin' => false,
    'blocked_roles' => array('ffc_user'),
    'redirect_url' => home_url('/dashboard'),
    'redirect_message' => __('You were redirected from the admin panel. Use this dashboard to access your certificates.', 'ffc'),
    'allow_admin_bar' => false,
    'bypass_for_admins' => true,
);

$settings = wp_parse_args($current_settings, $defaults);

// Get all WordPress roles
$wp_roles = wp_roles();
$available_roles = $wp_roles->get_names();

// Get dashboard page URL
$dashboard_page_id = get_option('ffc_dashboard_page_id');
$dashboard_url = $dashboard_page_id ? get_permalink($dashboard_page_id) : home_url('/dashboard');
?>

<div class="wrap ffc-settings-page">
    <form method="post" action="">
        <?php wp_nonce_field('ffc_user_access_settings', 'ffc_user_access_nonce'); ?>

        <!-- wp-admin Blocking -->
        <div class="card">
            <h2><?php esc_html_e('WP-Admin Access Control', 'ffc'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="block_wp_admin">
                            <?php esc_html_e('Block WP-Admin Access', 'ffc'); ?>
                        </label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="block_wp_admin"
                                   id="block_wp_admin"
                                   value="1"
                                   <?php checked($settings['block_wp_admin'], true); ?>>
                            <?php esc_html_e('Prevent selected roles from accessing /wp-admin', 'ffc'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('When enabled, users with selected roles will be redirected when trying to access the WordPress admin panel.', 'ffc'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="blocked_roles">
                            <?php esc_html_e('Blocked Roles', 'ffc'); ?>
                        </label>
                    </th>
                    <td>
                        <fieldset>
                            <?php foreach ($available_roles as $role_slug => $role_name) : ?>
                                <label style="display: block; margin-bottom: 8px;">
                                    <input type="checkbox"
                                           name="blocked_roles[]"
                                           value="<?php echo esc_attr($role_slug); ?>"
                                           <?php checked(in_array($role_slug, $settings['blocked_roles'])); ?>>
                                    <?php echo esc_html($role_name); ?>
                                    <?php if ($role_slug === 'ffc_user') : ?>
                                        <em>(<?php esc_html_e('recommended', 'ffc'); ?>)</em>
                                    <?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                        <p class="description">
                            <?php esc_html_e('Select which roles should be blocked from accessing wp-admin.', 'ffc'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="bypass_for_admins">
                            <?php esc_html_e('Bypass for Administrators', 'ffc'); ?>
                        </label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="bypass_for_admins"
                                   id="bypass_for_admins"
                                   value="1"
                                   <?php checked($settings['bypass_for_admins'], true); ?>>
                            <?php esc_html_e('Allow administrators to bypass the block (recommended)', 'ffc'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Even if an admin has a blocked role, they can still access wp-admin.', 'ffc'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Redirect Settings -->
        <div class="card">
            <h2><?php esc_html_e('Redirect Settings', 'ffc'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="redirect_url">
                            <?php esc_html_e('Redirect URL', 'ffc'); ?>
                        </label>
                    </th>
                    <td>
                        <input type="url"
                               name="redirect_url"
                               id="redirect_url"
                               value="<?php echo esc_attr($settings['redirect_url']); ?>"
                               class="regular-text"
                               placeholder="<?php echo esc_attr($dashboard_url); ?>">
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: Dashboard page URL */
                                esc_html__('Where to redirect blocked users. Default: %s', 'ffc'),
                                '<code>' . esc_html($dashboard_url) . '</code>'
                            );
                            ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="redirect_message">
                            <?php esc_html_e('Redirect Message', 'ffc'); ?>
                        </label>
                    </th>
                    <td>
                        <textarea name="redirect_message"
                                  id="redirect_message"
                                  rows="3"
                                  class="large-text"><?php echo esc_textarea($settings['redirect_message']); ?></textarea>
                        <p class="description">
                            <?php esc_html_e('Message shown to users after being redirected (appears on the dashboard page).', 'ffc'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Admin Bar -->
        <div class="card">
            <h2><?php esc_html_e('Admin Bar', 'ffc'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="allow_admin_bar">
                            <?php esc_html_e('Show Admin Bar', 'ffc'); ?>
                        </label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="allow_admin_bar"
                                   id="allow_admin_bar"
                                   value="1"
                                   <?php checked($settings['allow_admin_bar'], true); ?>>
                            <?php esc_html_e('Show admin bar on frontend for blocked roles', 'ffc'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('If unchecked, the WordPress admin bar will be hidden for blocked roles.', 'ffc'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Info Box -->
        <div class="card" style="background: #f0f6fc; border-left: 4px solid #0073aa;">
            <h2>ℹ️ <?php esc_html_e('Information', 'ffc'); ?></h2>
            <p>
                <?php esc_html_e('The "FFC User" role is automatically assigned to users who submit forms with CPF/RF.', 'ffc'); ?>
            </p>
            <p>
                <?php
                printf(
                    /* translators: %s: Shortcode */
                    esc_html__('Users can access their certificates via the dashboard page using the %s shortcode.', 'ffc'),
                    '<code>[user_dashboard_personal]</code>'
                );
                ?>
            </p>
            <p>
                <?php
                if ($dashboard_page_id) {
                    printf(
                        /* translators: %s: Dashboard page URL */
                        esc_html__('Dashboard page: %s', 'ffc'),
                        '<a href="' . esc_url($dashboard_url) . '" target="_blank">' . esc_html($dashboard_url) . '</a>'
                    );
                } else {
                    printf(
                        /* translators: %s: Dashboard page slug */
                        esc_html__('Dashboard page will be created at: %s (activate the plugin to create it)', 'ffc'),
                        '<code>' . esc_html(home_url('/dashboard')) . '</code>'
                    );
                }
                ?>
            </p>
        </div>

        <p class="submit">
            <button type="submit" name="save_settings" class="button button-primary">
                <?php esc_html_e('Save Settings', 'ffc'); ?>
            </button>
        </p>
    </form>
</div>

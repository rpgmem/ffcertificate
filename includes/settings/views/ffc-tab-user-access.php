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

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables scoped to this file

// Get current settings
$wp_ffcertificate_current_settings = get_option('ffc_user_access_settings', array());

// Defaults
$wp_ffcertificate_defaults = array(
    'block_wp_admin' => false,
    'blocked_roles' => array('ffc_user'),
    'redirect_url' => home_url('/dashboard'),
    'redirect_message' => __('You were redirected from the admin panel. Use this dashboard to access your certificates.', 'wp-ffcertificate'),
    'allow_admin_bar' => false,
    'bypass_for_admins' => true,
);

$wp_ffcertificate_settings = wp_parse_args($wp_ffcertificate_current_settings, $wp_ffcertificate_defaults);

// Get all WordPress roles
$wp_ffcertificate_wp_roles = wp_roles();
$wp_ffcertificate_available_roles = $wp_ffcertificate_wp_roles->get_names();

// Get dashboard page URL
$wp_ffcertificate_dashboard_page_id = get_option('ffc_dashboard_page_id');
$wp_ffcertificate_dashboard_url = $wp_ffcertificate_dashboard_page_id ? get_permalink($wp_ffcertificate_dashboard_page_id) : home_url('/dashboard');
?>

<div class="wrap ffc-settings-page">
    <form method="post" action="">
        <?php wp_nonce_field('ffc_user_access_settings', 'ffc_user_access_nonce'); ?>

        <!-- wp-admin Blocking -->
        <div class="card">
            <h2><?php esc_html_e('WP-Admin Access Control', 'wp-ffcertificate'); ?></h2>
            <table class="form-table" role="presentation"><tbody>
                <tr>
                    <th scope="row">
                        <label for="block_wp_admin">
                            <?php esc_html_e('Block WP-Admin Access', 'wp-ffcertificate'); ?>
                        </label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="block_wp_admin"
                                   id="block_wp_admin"
                                   value="1"
                                   <?php checked($wp_ffcertificate_settings['block_wp_admin'], true); ?>>
                            <?php esc_html_e('Prevent selected roles from accessing /wp-admin', 'wp-ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('When enabled, users with selected roles will be redirected when trying to access the WordPress admin panel.', 'wp-ffcertificate'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="blocked_roles">
                            <?php esc_html_e('Blocked Roles', 'wp-ffcertificate'); ?>
                        </label>
                    </th>
                    <td>
                        <fieldset>
                            <?php foreach ($wp_ffcertificate_available_roles as $wp_ffcertificate_role_slug => $wp_ffcertificate_role_name) : ?>
                                <label class="ffc-checkbox-label">
                                    <input type="checkbox"
                                           name="blocked_roles[]"
                                           value="<?php echo esc_attr($wp_ffcertificate_role_slug); ?>"
                                           <?php checked(in_array($wp_ffcertificate_role_slug, $wp_ffcertificate_settings['blocked_roles'])); ?>>
                                    <?php echo esc_html($wp_ffcertificate_role_name); ?>
                                    <?php if ($wp_ffcertificate_role_slug === 'ffc_user') : ?>
                                        <em>(<?php esc_html_e('recommended', 'wp-ffcertificate'); ?>)</em>
                                    <?php endif; ?>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                        <p class="description">
                            <?php esc_html_e('Select which roles should be blocked from accessing wp-admin.', 'wp-ffcertificate'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="bypass_for_admins">
                            <?php esc_html_e('Bypass for Administrators', 'wp-ffcertificate'); ?>
                        </label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="bypass_for_admins"
                                   id="bypass_for_admins"
                                   value="1"
                                   <?php checked($wp_ffcertificate_settings['bypass_for_admins'], true); ?>>
                            <?php esc_html_e('Allow administrators to bypass the block (recommended)', 'wp-ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Even if an admin has a blocked role, they can still access wp-admin.', 'wp-ffcertificate'); ?>
                        </p>
                    </td>
                </tr>
            </tbody></table>
        </div>

        <!-- Redirect Settings -->
        <div class="card">
            <h2><?php esc_html_e('Redirect Settings', 'wp-ffcertificate'); ?></h2>
            <table class="form-table" role="presentation"><tbody>
                <tr>
                    <th scope="row">
                        <label for="redirect_url">
                            <?php esc_html_e('Redirect URL', 'wp-ffcertificate'); ?>
                        </label>
                    </th>
                    <td>
                        <input type="url"
                               name="redirect_url"
                               id="redirect_url"
                               value="<?php echo esc_attr($wp_ffcertificate_settings['redirect_url']); ?>"
                               class="regular-text"
                               placeholder="<?php echo esc_attr($wp_ffcertificate_dashboard_url); ?>">
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: Dashboard page URL */
                                esc_html__('Where to redirect blocked users. Default: %s', 'wp-ffcertificate'),
                                '<code>' . esc_html($wp_ffcertificate_dashboard_url) . '</code>'
                            );
                            ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="redirect_message">
                            <?php esc_html_e('Redirect Message', 'wp-ffcertificate'); ?>
                        </label>
                    </th>
                    <td>
                        <textarea name="redirect_message"
                                  id="redirect_message"
                                  rows="3"
                                  class="large-text"><?php echo esc_textarea($wp_ffcertificate_settings['redirect_message']); ?></textarea>
                        <p class="description">
                            <?php esc_html_e('Message shown to users after being redirected (appears on the dashboard page).', 'wp-ffcertificate'); ?>
                        </p>
                    </td>
                </tr>
            </tbody></table>
        </div>

        <!-- Admin Bar -->
        <div class="card">
            <h2><?php esc_html_e('Admin Bar', 'wp-ffcertificate'); ?></h2>
            <table class="form-table" role="presentation"><tbody>
                <tr>
                    <th scope="row">
                        <label for="allow_admin_bar">
                            <?php esc_html_e('Show Admin Bar', 'wp-ffcertificate'); ?>
                        </label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="allow_admin_bar"
                                   id="allow_admin_bar"
                                   value="1"
                                   <?php checked($wp_ffcertificate_settings['allow_admin_bar'], true); ?>>
                            <?php esc_html_e('Show admin bar on frontend for blocked roles', 'wp-ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('If unchecked, the WordPress admin bar will be hidden for blocked roles.', 'wp-ffcertificate'); ?>
                        </p>
                    </td>
                </tr>
            </tbody></table>
        </div>

        <!-- Info Box -->
        <div class="card ffc-info-card">
            <h2>ℹ️ <?php esc_html_e('Information', 'wp-ffcertificate'); ?></h2>
            <p>
                <?php esc_html_e('The "FFC User" role is automatically assigned to users who submit forms with CPF/RF.', 'wp-ffcertificate'); ?>
            </p>
            <p>
                <?php
                printf(
                    /* translators: %s: Shortcode */
                    esc_html__('Users can access their certificates via the dashboard page using the %s shortcode.', 'wp-ffcertificate'),
                    '<code>[user_dashboard_personal]</code>'
                );
                ?>
            </p>
            <p>
                <?php
                if ($wp_ffcertificate_dashboard_page_id) {
                    printf(
                        /* translators: %s: Dashboard page URL */
                        esc_html__('Dashboard page: %s', 'wp-ffcertificate'),
                        '<a href="' . esc_url($wp_ffcertificate_dashboard_url) . '" target="_blank">' . esc_html($wp_ffcertificate_dashboard_url) . '</a>'
                    );
                } else {
                    printf(
                        /* translators: %s: Dashboard page slug */
                        esc_html__('Dashboard page will be created at: %s (activate the plugin to create it)', 'wp-ffcertificate'),
                        '<code>' . esc_html(home_url('/dashboard')) . '</code>'
                    );
                }
                ?>
            </p>
        </div>

        <p class="submit">
            <button type="submit" name="save_settings" class="button button-primary">
                <?php esc_html_e('Save Settings', 'wp-ffcertificate'); ?>
            </button>
        </p>
    </form>
</div>

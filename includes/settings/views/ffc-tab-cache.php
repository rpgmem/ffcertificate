<?php
/**
 * Cache & Performance Settings Tab View
 *
 * Contains Form Cache and QR Code Cache settings
 *
 * @package FFC
 * @since 4.6.16
 */

if (!defined('ABSPATH')) exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables scoped to this file

$ffcertificate_get_option = \Closure::fromCallable( [ $settings, 'get_option' ] );
?>

<div class="ffc-settings-wrap">

<!-- Form Cache Card -->
<div class="card">
    <h2 class="ffc-icon-package"><?php esc_html_e('Form Cache', 'ffcertificate'); ?></h2>
    <p class="description">
        <?php esc_html_e('The cache stores form settings to improve performance.', 'ffcertificate'); ?>
        <?php if (wp_using_ext_object_cache()): ?>
            <span class="ffc-text-success ffc-icon-success"><?php esc_html_e('External cache active (Redis/Memcached)', 'ffcertificate'); ?></span>
        <?php else: ?>
            <span class="ffc-text-warning ffc-icon-warning"><?php esc_html_e('Using default WordPress cache (database)', 'ffcertificate'); ?></span>
        <?php endif; ?>
    </p>

    <form method="post">
        <?php wp_nonce_field('ffc_settings_action', 'ffc_settings_nonce'); ?>
        <input type="hidden" name="_ffc_tab" value="cache">

        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="cache_enabled"><?php esc_html_e('Enable Cache', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[cache_enabled]" id="cache_enabled" value="1" <?php checked($ffcertificate_get_option('cache_enabled'), 1); ?>>
                            <?php esc_html_e('Enable form caching', 'ffcertificate'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Recommended for sites with many forms or high traffic.', 'ffcertificate'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="cache_expiration"><?php esc_html_e('Expiration Time', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <select name="ffc_settings[cache_expiration]" id="cache_expiration" class="regular-text">
                            <option value="900" <?php selected($ffcertificate_get_option('cache_expiration'), 900); ?>><?php esc_html_e('15 minutes', 'ffcertificate'); ?></option>
                            <option value="1800" <?php selected($ffcertificate_get_option('cache_expiration'), 1800); ?>><?php esc_html_e('30 minutes', 'ffcertificate'); ?></option>
                            <option value="3600" <?php selected($ffcertificate_get_option('cache_expiration'), 3600); ?>><?php esc_html_e('1 hour (default)', 'ffcertificate'); ?></option>
                            <option value="86400" <?php selected($ffcertificate_get_option('cache_expiration'), 86400); ?>><?php esc_html_e('24 hours', 'ffcertificate'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('Time the data remains in cache before being updated.', 'ffcertificate'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="cache_auto_warm"><?php esc_html_e('Automatic Warming', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[cache_auto_warm]" id="cache_auto_warm" value="1" <?php checked($ffcertificate_get_option('cache_auto_warm'), 1); ?>>
                            <?php esc_html_e('Pre-load cache daily', 'ffcertificate'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Runs a daily cron job to keep the cache always updated.', 'ffcertificate'); ?></p>
                    </td>
                </tr>

                <?php if (class_exists('\FreeFormCertificate\Submissions\FormCache')): ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Statistics', 'ffcertificate'); ?></th>
                    <td>
                        <?php
                        $ffcertificate_stats = \FreeFormCertificate\Submissions\FormCache::get_stats();
                        $ffcertificate_total_forms = wp_count_posts('ffc_form')->publish;
                        ?>
                        <div class="ffc-stats-box">
                            <table>
                                <tr class="alternate">
                                    <td><strong><?php esc_html_e('Backend:', 'ffcertificate'); ?></strong></td>
                                    <td class="stat-value"><?php echo esc_html($ffcertificate_stats['backend']); ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?php esc_html_e('Group:', 'ffcertificate'); ?></strong></td>
                                    <td class="stat-value"><?php echo esc_html($ffcertificate_stats['group']); ?></td>
                                </tr>
                                <tr class="alternate">
                                    <td><strong><?php esc_html_e('Expiration:', 'ffcertificate'); ?></strong></td>
                                    <td class="stat-value"><?php echo esc_html($ffcertificate_stats['expiration']); ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?php esc_html_e('Published Forms:', 'ffcertificate'); ?></strong></td>
                                    <td class="stat-value info"><?php echo esc_html($ffcertificate_total_forms); ?></td>
                                </tr>
                            </table>
                            <?php if (!wp_using_ext_object_cache()): ?>
                                <p class="ffc-text-warning ffc-mt-20">
                                    <span class="ffc-icon-bulb"></span><?php esc_html_e('Tip: Install Redis or Memcached for better performance.', 'ffcertificate'); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e('Actions', 'ffcertificate'); ?></th>
                    <td>
                        <a href="<?php echo esc_url( wp_nonce_url(admin_url('edit.php?post_type=ffc_form&page=ffc-settings&tab=cache&action=warm_cache'), 'ffc_warm_cache') ); ?>" class="button">
                            <?php esc_html_e('Warm Cache Now', 'ffcertificate'); ?>
                        </a>
                        <a href="<?php echo esc_url( wp_nonce_url(admin_url('edit.php?post_type=ffc_form&page=ffc-settings&tab=cache&action=clear_cache'), 'ffc_clear_cache') ); ?>" class="button" onclick="return confirm('<?php echo esc_js(__('Clear all cache?', 'ffcertificate')); ?>');">
                            <span class="ffc-icon-delete"></span><?php esc_html_e('Clear Cache', 'ffcertificate'); ?>
                        </a>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- QR Code Cache Section -->
        <h3 class="ffc-icon-phone"><?php esc_html_e('QR Code Cache', 'ffcertificate'); ?></h3>
        <p class="description">
            <?php esc_html_e('Store generated QR Codes in database to avoid regenerating them on each request.', 'ffcertificate'); ?>
        </p>

        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="qr_cache_enabled"><?php esc_html_e('Enable QR Code Cache', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[qr_cache_enabled]" id="qr_cache_enabled" value="1" <?php checked(1, $ffcertificate_get_option('qr_cache_enabled')); ?>>
                            <?php esc_html_e('Store generated QR Codes in database', 'ffcertificate'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Improves performance by caching QR Codes. Increases database size (~4KB per submission).', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>

                <?php if (class_exists('\FreeFormCertificate\Generators\QRCodeGenerator')): ?>
                <tr>
                    <th scope="row"><?php esc_html_e('QR Statistics', 'ffcertificate'); ?></th>
                    <td>
                        <?php
                        $ffcertificate_qr_generator = new \FreeFormCertificate\Generators\QRCodeGenerator();
                        $ffcertificate_qr_stats = $ffcertificate_qr_generator->get_cache_stats();
                        ?>
                        <div class="ffc-stats-box">
                            <table>
                                <tr class="alternate">
                                    <td><strong><?php esc_html_e('Cache Status:', 'ffcertificate'); ?></strong></td>
                                    <td>
                                        <?php if ($ffcertificate_qr_stats['enabled']): ?>
                                            <span class="ffc-text-success ffc-icon-checkmark"><?php esc_html_e('Enabled', 'ffcertificate'); ?></span>
                                        <?php else: ?>
                                            <span class="ffc-text-error ffc-icon-cross"><?php esc_html_e('Disabled', 'ffcertificate'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong><?php esc_html_e('Total Submissions:', 'ffcertificate'); ?></strong></td>
                                    <td class="stat-value"><?php echo esc_html( number_format_i18n($ffcertificate_qr_stats['total_submissions']) ); ?></td>
                                </tr>
                                <tr class="alternate">
                                    <td><strong><?php esc_html_e('Cached QR Codes:', 'ffcertificate'); ?></strong></td>
                                    <td class="stat-value info"><?php echo esc_html( number_format_i18n($ffcertificate_qr_stats['cached_qr_codes']) ); ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?php esc_html_e('Estimated Cache Size:', 'ffcertificate'); ?></strong></td>
                                    <td class="stat-value"><?php echo esc_html($ffcertificate_qr_stats['cache_size']); ?></td>
                                </tr>
                            </table>
                        </div>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e('QR Actions', 'ffcertificate'); ?></th>
                    <td>
                        <?php
                        $ffcertificate_clear_qr_url = wp_nonce_url(
                            add_query_arg(array(
                                'post_type' => 'ffc_form',
                                'page' => 'ffc-settings',
                                'tab' => 'cache',
                                'ffc_clear_qr_cache' => '1'
                            ), admin_url('edit.php')),
                            'ffc_clear_qr_cache'
                        );
                        ?>
                        <a href="<?php echo esc_url($ffcertificate_clear_qr_url); ?>"
                           class="button button-secondary"
                           onclick="return confirm('<?php echo esc_js(__('Clear all cached QR Codes?\n\nThey will be regenerated automatically when needed.', 'ffcertificate')); ?>');">
                            <span class="ffc-icon-delete"></span><?php esc_html_e('Clear All QR Code Cache', 'ffcertificate'); ?>
                        </a>
                        <p class="description ffc-mt-10">
                            <?php esc_html_e('QR Codes will be regenerated automatically when needed. This action is safe and reversible.', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php submit_button(); ?>
    </form>
</div>

<!-- Page Cache Compatibility Card -->
<div class="card">
    <h2 class="ffc-icon-shield"><?php esc_html_e('Page Cache Compatibility', 'ffcertificate'); ?></h2>
    <p class="description">
        <?php esc_html_e('Status of cache-compatibility features that ensure forms work correctly with full-page caching (LiteSpeed, Varnish, etc.).', 'ffcertificate'); ?>
    </p>

    <table class="form-table" role="presentation">
        <tbody>
            <tr>
                <th scope="row"><?php esc_html_e('Dynamic Fragments', 'ffcertificate'); ?></th>
                <td>
                    <span class="ffc-text-success ffc-icon-checkmark"><?php esc_html_e('Active', 'ffcertificate'); ?></span>
                    <p class="description">
                        <?php esc_html_e('Captcha and nonces are automatically refreshed via AJAX on page load. Forms work correctly even when served from a page cache.', 'ffcertificate'); ?>
                    </p>
                </td>
            </tr>
            <tr class="alternate">
                <th scope="row"><?php esc_html_e('Dashboard Exclusion', 'ffcertificate'); ?></th>
                <td>
                    <span class="ffc-text-success ffc-icon-checkmark"><?php esc_html_e('Active', 'ffcertificate'); ?></span>
                    <p class="description">
                        <?php esc_html_e('Pages with [user_dashboard_personal] are automatically excluded from page cache (no-cache headers sent).', 'ffcertificate'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Object Cache (Redis)', 'ffcertificate'); ?></th>
                <td>
                    <?php if (wp_using_ext_object_cache()): ?>
                        <span class="ffc-text-success ffc-icon-checkmark"><?php esc_html_e('Active', 'ffcertificate'); ?></span>
                        <p class="description">
                            <?php esc_html_e('Rate limiter counters persist between requests. Form cache is shared across all processes.', 'ffcertificate'); ?>
                        </p>
                    <?php else: ?>
                        <span class="ffc-text-warning ffc-icon-warning"><?php esc_html_e('Not detected', 'ffcertificate'); ?></span>
                        <p class="description">
                            <?php esc_html_e('Without Redis/Memcached, rate limiter hourly counters reset on each request and form cache is not shared. Install an object cache plugin (e.g. LiteSpeed Cache with Redis) for full rate limiting functionality.', 'ffcertificate'); ?>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr class="alternate">
                <th scope="row"><?php esc_html_e('AJAX Endpoints', 'ffcertificate'); ?></th>
                <td>
                    <span class="ffc-text-success ffc-icon-checkmark"><?php esc_html_e('Compatible', 'ffcertificate'); ?></span>
                    <p class="description">
                        <?php esc_html_e('All AJAX endpoints use admin-ajax.php which is excluded from page cache by default.', 'ffcertificate'); ?>
                    </p>
                </td>
            </tr>
        </tbody>
    </table>
</div>

</div><!-- .ffc-settings-wrap -->

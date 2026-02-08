<?php
/**
 * QR Code Settings Tab
 * @version 3.0.0 - With cards and proper structure
 */

if (!defined('ABSPATH')) exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables scoped to this file

$ffcertificate_get_option = function($key, $default = '') {
    $settings = get_option('ffc_settings', array());
    return isset($settings[$key]) ? $settings[$key] : $default;
};
?>

<div class="ffc-settings-wrap">

<!-- QR Code Settings Card -->
<div class="card">
    <h2 class="ffc-icon-phone"><?php esc_html_e('QR Code Generation Settings', 'ffcertificate'); ?></h2>
    
    <form method="post">
        <?php wp_nonce_field('ffc_settings_action', 'ffc_settings_nonce'); ?>
        <input type="hidden" name="_ffc_tab" value="qr_code">
        
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
                
                <tr>
                    <th scope="row">
                        <label for="qr_default_size"><?php esc_html_e('Default QR Code Size', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="ffc_settings[qr_default_size]" id="qr_default_size" value="<?php echo esc_attr($ffcertificate_get_option('qr_default_size', 100)); ?>" min="100" max="500" step="10" class="small-text"> px
                        <p class="description">
                            <?php esc_html_e('Default size when {{qr_code}} placeholder is used without size parameter. Range: 100-500px.', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="qr_default_margin"><?php esc_html_e('Default QR Code Margin', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="ffc_settings[qr_default_margin]" id="qr_default_margin" value="<?php echo esc_attr($ffcertificate_get_option('qr_default_margin', 0)); ?>" min="0" max="10" step="1" class="small-text">
                        <p class="description">
                            <?php esc_html_e('White space around QR Code in modules. 0 = no margin, higher values = more white space.', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="qr_default_error_level"><?php esc_html_e('Default Error Correction Level', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <select name="ffc_settings[qr_default_error_level]" id="qr_default_error_level" class="regular-text">
                            <option value="L" <?php selected('L', $ffcertificate_get_option('qr_default_error_level', 'L')); ?>>
                                L - <?php esc_html_e('Low (7% correction)', 'ffcertificate'); ?>
                            </option>
                            <option value="M" <?php selected('M', $ffcertificate_get_option('qr_default_error_level', 'M')); ?>>
                                M - <?php esc_html_e('Medium (15% correction) - Recommended', 'ffcertificate'); ?>
                            </option>
                            <option value="Q" <?php selected('Q', $ffcertificate_get_option('qr_default_error_level', 'Q')); ?>>
                                Q - <?php esc_html_e('Quartile (25% correction)', 'ffcertificate'); ?>
                            </option>
                            <option value="H" <?php selected('H', $ffcertificate_get_option('qr_default_error_level', 'H')); ?>>
                                H - <?php esc_html_e('High (30% correction)', 'ffcertificate'); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Higher levels allow more damage to QR Code but create denser patterns.', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>
        
        <?php submit_button(); ?>
    </form>
</div>

<!-- Cache Statistics Card -->
<div class="card">
    <h2 class="ffc-icon-chart"><?php esc_html_e('Cache Statistics', 'ffcertificate'); ?></h2>
    
    <?php
    // Autoloader handles class loading
    $ffcertificate_qr_generator = new \FreeFormCertificate\Generators\QRCodeGenerator();
    $ffcertificate_stats = $ffcertificate_qr_generator->get_cache_stats();
    ?>
    
    <div class="ffc-stats-box">
        <table>
            <tr class="alternate">
                <td><strong><?php esc_html_e('Cache Status:', 'ffcertificate'); ?></strong></td>
                <td>
                    <?php if ($ffcertificate_stats['enabled']): ?>
                        <span class="ffc-text-success ffc-icon-checkmark"><?php esc_html_e('Enabled', 'ffcertificate'); ?></span>
                    <?php else: ?>
                        <span class="ffc-text-error ffc-icon-cross"><?php esc_html_e('Disabled', 'ffcertificate'); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td><strong><?php esc_html_e('Total Submissions:', 'ffcertificate'); ?></strong></td>
                <td class="stat-value"><?php echo esc_html( number_format_i18n($ffcertificate_stats['total_submissions']) ); ?></td>
            </tr>
            <tr class="alternate">
                <td><strong><?php esc_html_e('Cached QR Codes:', 'ffcertificate'); ?></strong></td>
                <td class="stat-value info"><?php echo esc_html( number_format_i18n($ffcertificate_stats['cached_qr_codes']) ); ?></td>
            </tr>
            <tr>
                <td><strong><?php esc_html_e('Estimated Cache Size:', 'ffcertificate'); ?></strong></td>
                <td class="stat-value"><?php echo esc_html($ffcertificate_stats['cache_size']); ?></td>
            </tr>
        </table>
    </div>
    
    <p class="description ffc-mt-15">
        <span class="ffc-icon-bulb"></span><?php esc_html_e('Cache stores generated QR Codes to improve performance. Enable "QR Code Cache" above to start caching.', 'ffcertificate'); ?>
    </p>
</div>

<!-- Maintenance Card -->
<div class="card">
    <h2 class="ffc-icon-delete"><?php esc_html_e('Maintenance', 'ffcertificate'); ?></h2>
    
    <?php
    $ffcertificate_clear_url = wp_nonce_url(
        add_query_arg(array(
            'post_type' => 'ffc_form',
            'page' => 'ffc-settings',
            'tab' => 'qr_code',
            'ffc_clear_qr_cache' => '1'
        ), admin_url('edit.php')),
        'ffc_clear_qr_cache'
    );
    ?>
    
    <p><?php esc_html_e('Use this to clear outdated QR Codes or free database space.', 'ffcertificate'); ?></p>
    
    <p>
        <a href="<?php echo esc_url($ffcertificate_clear_url); ?>" 
           class="button button-secondary" 
           onclick="return confirm('<?php echo esc_js(__('Clear all cached QR Codes?\n\nThey will be regenerated automatically when needed.', 'ffcertificate')); ?>');">
            <span class="ffc-icon-delete"></span><?php esc_html_e('Clear All QR Code Cache', 'ffcertificate'); ?>
        </a>
    </p>
    
    <p class="description">
        <?php esc_html_e('QR Codes will be regenerated automatically when needed. This action is safe and reversible.', 'ffcertificate'); ?>
    </p>
</div>

</div><!-- .ffc-settings-wrap -->
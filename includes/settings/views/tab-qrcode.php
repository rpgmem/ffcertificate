<?php
/**
 * QR Code Settings Tab
 * @version 3.0.0 - With cards and proper structure
 */

if (!defined('ABSPATH')) exit;

$get_option = function($key, $default = '') {
    $settings = get_option('ffc_settings', array());
    return isset($settings[$key]) ? $settings[$key] : $default;
};
?>

<div class="ffc-settings-wrap">

<!-- QR Code Settings Card -->
<div class="card">
    <h2>📱 <?php esc_html_e('QR Code Generation Settings', 'ffc'); ?></h2>
    
    <form method="post">
        <?php wp_nonce_field('ffc_settings_action', 'ffc_settings_nonce'); ?>
        <input type="hidden" name="_ffc_tab" value="qr_code">
        
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="qr_cache_enabled"><?php esc_html_e('Enable QR Code Cache', 'ffc'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="ffc_settings[qr_cache_enabled]" id="qr_cache_enabled" value="1" <?php checked(1, $get_option('qr_cache_enabled')); ?>>
                            <?php esc_html_e('Store generated QR Codes in database', 'ffc'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Improves performance by caching QR Codes. Increases database size (~4KB per submission).', 'ffc'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="qr_default_size"><?php esc_html_e('Default QR Code Size', 'ffc'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="ffc_settings[qr_default_size]" id="qr_default_size" value="<?php echo esc_attr($get_option('qr_default_size', 100)); ?>" min="100" max="500" step="10" class="small-text"> px
                        <p class="description">
                            <?php esc_html_e('Default size when {{qr_code}} placeholder is used without size parameter. Range: 100-500px.', 'ffc'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="qr_default_margin"><?php esc_html_e('Default QR Code Margin', 'ffc'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="ffc_settings[qr_default_margin]" id="qr_default_margin" value="<?php echo esc_attr($get_option('qr_default_margin', 0)); ?>" min="0" max="10" step="1" class="small-text">
                        <p class="description">
                            <?php esc_html_e('White space around QR Code in modules. 0 = no margin, higher values = more white space.', 'ffc'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="qr_default_error_level"><?php esc_html_e('Default Error Correction Level', 'ffc'); ?></label>
                    </th>
                    <td>
                        <select name="ffc_settings[qr_default_error_level]" id="qr_default_error_level" class="regular-text">
                            <option value="L" <?php selected('L', $get_option('qr_default_error_level', 'L')); ?>>
                                L - <?php esc_html_e('Low (7% correction)', 'ffc'); ?>
                            </option>
                            <option value="M" <?php selected('M', $get_option('qr_default_error_level', 'M')); ?>>
                                M - <?php esc_html_e('Medium (15% correction) - Recommended', 'ffc'); ?>
                            </option>
                            <option value="Q" <?php selected('Q', $get_option('qr_default_error_level', 'Q')); ?>>
                                Q - <?php esc_html_e('Quartile (25% correction)', 'ffc'); ?>
                            </option>
                            <option value="H" <?php selected('H', $get_option('qr_default_error_level', 'H')); ?>>
                                H - <?php esc_html_e('High (30% correction)', 'ffc'); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Higher levels allow more damage to QR Code but create denser patterns.', 'ffc'); ?>
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
    <h2>📊 <?php esc_html_e('Cache Statistics', 'ffc'); ?></h2>
    
    <?php
    if (!class_exists('FFC_QRCode_Generator')) {
        require_once FFC_PLUGIN_DIR . 'includes/generators/class-ffc-qrcode-generator.php';
    }
    
    $qr_generator = new FFC_QRCode_Generator();
    $stats = $qr_generator->get_cache_stats();
    ?>
    
    <div class="ffc-stats-box">
        <table>
            <tr class="alternate">
                <td><strong><?php _e('Cache Status:', 'ffc'); ?></strong></td>
                <td>
                    <?php if ($stats['enabled']): ?>
                        <span class="ffc-text-success">✓ <?php _e('Enabled', 'ffc'); ?></span>
                    <?php else: ?>
                        <span class="ffc-text-error">✗ <?php _e('Disabled', 'ffc'); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td><strong><?php _e('Total Submissions:', 'ffc'); ?></strong></td>
                <td class="stat-value"><?php echo number_format_i18n($stats['total_submissions']); ?></td>
            </tr>
            <tr class="alternate">
                <td><strong><?php _e('Cached QR Codes:', 'ffc'); ?></strong></td>
                <td class="stat-value info"><?php echo number_format_i18n($stats['cached_qr_codes']); ?></td>
            </tr>
            <tr>
                <td><strong><?php _e('Estimated Cache Size:', 'ffc'); ?></strong></td>
                <td class="stat-value"><?php echo esc_html($stats['cache_size']); ?></td>
            </tr>
        </table>
    </div>
    
    <p class="description ffc-mt-15">
        💡 <?php _e('Cache stores generated QR Codes to improve performance. Enable "QR Code Cache" above to start caching.', 'ffc'); ?>
    </p>
</div>

<!-- Maintenance Card -->
<div class="card">
    <h2>🗑️ <?php esc_html_e('Maintenance', 'ffc'); ?></h2>
    
    <?php
    $clear_url = wp_nonce_url(
        add_query_arg(array(
            'post_type' => 'ffc_form',
            'page' => 'ffc-settings',
            'tab' => 'qr_code',
            'ffc_clear_qr_cache' => '1'
        ), admin_url('edit.php')),
        'ffc_clear_qr_cache'
    );
    ?>
    
    <p><?php _e('Use this to clear outdated QR Codes or free database space.', 'ffc'); ?></p>
    
    <p>
        <a href="<?php echo esc_url($clear_url); ?>" 
           class="button button-secondary" 
           onclick="return confirm('<?php echo esc_js(__('Clear all cached QR Codes?\n\nThey will be regenerated automatically when needed.', 'ffc')); ?>');">
            🗑️ <?php _e('Clear All QR Code Cache', 'ffc'); ?>
        </a>
    </p>
    
    <p class="description">
        <?php _e('QR Codes will be regenerated automatically when needed. This action is safe and reversible.', 'ffc'); ?>
    </p>
</div>

</div><!-- .ffc-settings-wrap -->
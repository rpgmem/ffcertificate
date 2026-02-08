<?php
/**
 * General Settings Tab
 * @version 4.6.16 - Simplified: moved debug/activity log/danger zone to Advanced, cache to Cache tab
 */

if (!defined('ABSPATH')) exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables scoped to this file

$ffcertificate_get_option = \Closure::fromCallable( [ $settings, 'get_option' ] );

$ffcertificate_date_formats = array(
    'Y-m-d H:i:s' => '2026-01-04 15:30:45 (YYYY-MM-DD HH:MM:SS)',
    'Y-m-d' => '2026-01-04 (YYYY-MM-DD)',
    'd/m/Y' => '04/01/2026 (DD/MM/YYYY)',
    'd/m/Y H:i' => '04/01/2026 15:30 (DD/MM/YYYY HH:MM)',
    'd/m/Y H:i:s' => '04/01/2026 15:30:45 (DD/MM/YYYY HH:MM:SS)',
    'm/d/Y' => '01/04/2026 (MM/DD/YYYY)',
    'F j, Y' => __('January 4, 2026 (Month Day, Year)', 'ffcertificate'),
    'j \d\e F \d\e Y' => __('4 of January, 2026', 'ffcertificate'),
    'd \d\e F \d\e Y' => __('04 of January, 2026', 'ffcertificate'),
    'l, j \d\e F \d\e Y' => __('Saturday, January 4, 2026', 'ffcertificate'),
    'custom' => __('Custom Format', 'ffcertificate')
);

$ffcertificate_current_format = $ffcertificate_get_option('date_format', 'F j, Y');
$ffcertificate_custom_format = $ffcertificate_get_option('date_format_custom', '');
$ffcertificate_main_address = $ffcertificate_get_option('main_address', '');
?>

<div class="ffc-settings-wrap">

<!-- General Settings Card -->
<div class="card">
    <h2 class="ffc-icon-settings"><?php esc_html_e('General Settings', 'ffcertificate'); ?></h2>

    <form method="post">
        <?php wp_nonce_field('ffc_settings_action', 'ffc_settings_nonce'); ?>
        <input type="hidden" name="_ffc_tab" value="general">

        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="ffc_dark_mode"><?php esc_html_e('Dark Mode', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <select name="ffc_settings[dark_mode]" id="ffc_dark_mode" class="regular-text">
                            <option value="off" <?php selected($ffcertificate_get_option('dark_mode', 'off'), 'off'); ?>><?php esc_html_e('Off', 'ffcertificate'); ?></option>
                            <option value="on" <?php selected($ffcertificate_get_option('dark_mode', 'off'), 'on'); ?>><?php esc_html_e('On (always dark)', 'ffcertificate'); ?></option>
                            <option value="auto" <?php selected($ffcertificate_get_option('dark_mode', 'off'), 'auto'); ?>><?php esc_html_e('Auto (follow OS)', 'ffcertificate'); ?></option>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Controls the dark mode appearance for plugin admin pages.', 'ffcertificate'); ?><br>
                            <span class="ffc-text-info ffc-icon-info"><?php esc_html_e('"Auto" follows your operating system preference.', 'ffcertificate'); ?></span>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="cleanup_days"><?php esc_html_e('Auto-delete (days)', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <input type="number" name="ffc_settings[cleanup_days]" id="cleanup_days" value="<?php echo esc_attr($ffcertificate_get_option('cleanup_days')); ?>" class="small-text" min="0">
                        <p class="description"><?php esc_html_e('Files removed after X days. Set to 0 to disable.', 'ffcertificate'); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="ffc_date_format"><?php esc_html_e('Date Format', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <select name="ffc_settings[date_format]" id="ffc_date_format" class="regular-text">
                            <?php foreach ($ffcertificate_date_formats as $ffcertificate_format => $ffcertificate_label) : ?>
                                <option value="<?php echo esc_attr($ffcertificate_format); ?>" <?php selected($ffcertificate_current_format, $ffcertificate_format); ?>>
                                    <?php echo esc_html($ffcertificate_label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Format used for {{submission_date}} placeholder in PDFs and emails.', 'ffcertificate'); ?>
                            <br>
                            <strong><?php esc_html_e('Preview:', 'ffcertificate'); ?></strong>
                            <span class="ffc-text-info ffc-monospace">
                                <?php
                                $ffcertificate_preview_date = '2026-01-04 15:30:45';
                                echo esc_html( date_i18n(($ffcertificate_current_format === 'custom' && !empty($ffcertificate_custom_format)) ? $ffcertificate_custom_format : $ffcertificate_current_format, strtotime($ffcertificate_preview_date)) );
                                ?>
                            </span>
                        </p>

                        <div id="ffc_custom_format_container" class="ffc-collapsible-section <?php echo esc_attr( $ffcertificate_current_format !== 'custom' ? 'ffc-hidden' : '' ); ?>">
                            <div class="ffc-collapsible-content active">
                                <label>
                                    <strong><?php esc_html_e('Custom Format:', 'ffcertificate'); ?></strong><br>
                                    <input type="text" name="ffc_settings[date_format_custom]" id="ffc_date_format_custom" value="<?php echo esc_attr($ffcertificate_custom_format); ?>" placeholder="d/m/Y H:i" class="regular-text">
                                </label>
                                <p class="description">
                                    <?php esc_html_e('Use PHP date format characters.', 'ffcertificate'); ?>
                                    <a href="https://www.php.net/manual/en/datetime.format.php" target="_blank"><?php esc_html_e('See documentation', 'ffcertificate'); ?></a>
                                </p>
                            </div>
                        </div>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="main_address"><?php esc_html_e('Main Address', 'ffcertificate'); ?></label>
                    </th>
                    <td>
                        <input type="text" name="ffc_settings[main_address]" id="main_address" value="<?php echo esc_attr($ffcertificate_main_address); ?>" class="large-text">
                        <p class="description">
                            <?php esc_html_e('Main institutional address. Available as {{main_address}} placeholder in certificate and appointment templates.', 'ffcertificate'); ?>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- QR Code Defaults Section -->
        <h3 class="ffc-icon-phone"><?php esc_html_e('QR Code Defaults', 'ffcertificate'); ?></h3>
        <p class="description">
            <?php esc_html_e('Default settings for QR Code generation in certificates.', 'ffcertificate'); ?>
        </p>

        <table class="form-table" role="presentation">
            <tbody>
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

</div><!-- .ffc-settings-wrap -->

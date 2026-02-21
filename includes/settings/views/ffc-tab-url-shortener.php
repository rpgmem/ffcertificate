<?php
/**
 * URL Shortener Settings Tab View
 *
 * @since 5.1.0
 * @var \FreeFormCertificate\Settings\Tabs\TabUrlShortener $settings
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$ffc_settings = get_option( 'ffc_settings', [] );

$enabled       = isset( $ffc_settings['url_shortener_enabled'] ) ? (int) $ffc_settings['url_shortener_enabled'] : 1;
$prefix        = $ffc_settings['url_shortener_prefix'] ?? 'go';
$code_length   = (int) ( $ffc_settings['url_shortener_code_length'] ?? 6 );
$auto_create   = isset( $ffc_settings['url_shortener_auto_create'] ) ? (int) $ffc_settings['url_shortener_auto_create'] : 1;
$redirect_type = (int) ( $ffc_settings['url_shortener_redirect_type'] ?? 302 );
$post_types    = $ffc_settings['url_shortener_post_types'] ?? [ 'post', 'page' ];
if ( is_string( $post_types ) ) {
    $post_types = array_filter( array_map( 'trim', explode( ',', $post_types ) ) );
}

$all_post_types = get_post_types( [ 'public' => true ], 'objects' );
?>

<div class="ffc-section-header">
    <h2><?php esc_html_e( 'URL Shortener', 'ffcertificate' ); ?></h2>
    <p class="description">
        <?php esc_html_e( 'Configure the built-in URL shortener. Short URLs redirect visitors and generate QR Codes.', 'ffcertificate' ); ?>
    </p>
</div>

<form method="post">
    <?php wp_nonce_field( 'ffc_settings_action', 'ffc_settings_nonce' ); ?>
    <input type="hidden" name="_ffc_tab" value="url_shortener">

<table class="form-table">
    <tr>
        <th scope="row">
            <label for="url_shortener_enabled"><?php esc_html_e( 'Enable URL Shortener', 'ffcertificate' ); ?></label>
        </th>
        <td>
            <label>
                <input type="checkbox" name="ffc_settings[url_shortener_enabled]" id="url_shortener_enabled" value="1" <?php checked( $enabled, 1 ); ?> />
                <?php esc_html_e( 'Enable the URL Shortener module', 'ffcertificate' ); ?>
            </label>
        </td>
    </tr>

    <tr>
        <th scope="row">
            <label for="url_shortener_prefix"><?php esc_html_e( 'URL Prefix', 'ffcertificate' ); ?></label>
        </th>
        <td>
            <code><?php echo esc_html( home_url( '/' ) ); ?></code>
            <input type="text" name="ffc_settings[url_shortener_prefix]" id="url_shortener_prefix"
                   value="<?php echo esc_attr( $prefix ); ?>"
                   class="regular-text" style="width: 100px;" />
            <code>/abc123</code>
            <p class="description">
                <?php esc_html_e( 'The URL prefix for short links (e.g. "go", "r", "l"). Only letters, numbers, and hyphens.', 'ffcertificate' ); ?>
            </p>
        </td>
    </tr>

    <tr>
        <th scope="row">
            <label for="url_shortener_code_length"><?php esc_html_e( 'Code Length', 'ffcertificate' ); ?></label>
        </th>
        <td>
            <input type="number" name="ffc_settings[url_shortener_code_length]" id="url_shortener_code_length"
                   value="<?php echo esc_attr( (string) $code_length ); ?>"
                   min="4" max="10" step="1" class="small-text" />
            <p class="description">
                <?php esc_html_e( 'Length of the random code in short URLs (4-10 characters). Default: 6.', 'ffcertificate' ); ?>
            </p>
        </td>
    </tr>

    <tr>
        <th scope="row">
            <label for="url_shortener_redirect_type"><?php esc_html_e( 'Redirect Type', 'ffcertificate' ); ?></label>
        </th>
        <td>
            <select name="ffc_settings[url_shortener_redirect_type]" id="url_shortener_redirect_type">
                <option value="302" <?php selected( $redirect_type, 302 ); ?>>302 - <?php esc_html_e( 'Temporary (recommended)', 'ffcertificate' ); ?></option>
                <option value="301" <?php selected( $redirect_type, 301 ); ?>>301 - <?php esc_html_e( 'Permanent', 'ffcertificate' ); ?></option>
                <option value="307" <?php selected( $redirect_type, 307 ); ?>>307 - <?php esc_html_e( 'Temporary (strict)', 'ffcertificate' ); ?></option>
            </select>
            <p class="description">
                <?php esc_html_e( '302 is recommended. Use 301 only if short URLs will never change target.', 'ffcertificate' ); ?>
            </p>
        </td>
    </tr>

    <tr>
        <th scope="row">
            <label for="url_shortener_auto_create"><?php esc_html_e( 'Auto-create Short URLs', 'ffcertificate' ); ?></label>
        </th>
        <td>
            <label>
                <input type="checkbox" name="ffc_settings[url_shortener_auto_create]" id="url_shortener_auto_create" value="1" <?php checked( $auto_create, 1 ); ?> />
                <?php esc_html_e( 'Automatically generate a short URL when a post/page is published', 'ffcertificate' ); ?>
            </label>
        </td>
    </tr>

    <tr>
        <th scope="row"><?php esc_html_e( 'Post Types', 'ffcertificate' ); ?></th>
        <td>
            <?php foreach ( $all_post_types as $pt ) : ?>
                <?php if ( in_array( $pt->name, [ 'attachment', 'ffc_form', 'ffc_calendar' ], true ) ) continue; ?>
                <label style="display: block; margin-bottom: 4px;">
                    <input type="checkbox"
                           name="ffc_settings[url_shortener_post_types][]"
                           value="<?php echo esc_attr( $pt->name ); ?>"
                           <?php checked( in_array( $pt->name, $post_types, true ) ); ?> />
                    <?php echo esc_html( $pt->labels->singular_name ); ?> <code>(<?php echo esc_html( $pt->name ); ?>)</code>
                </label>
            <?php endforeach; ?>
            <p class="description">
                <?php esc_html_e( 'Select which post types will show the URL Shortener meta box.', 'ffcertificate' ); ?>
            </p>
        </td>
    </tr>
</table>

    <?php submit_button(); ?>
</form>

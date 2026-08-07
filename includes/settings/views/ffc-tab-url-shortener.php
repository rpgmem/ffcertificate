<?php
/**
 * URL Shortener Settings Tab View
 *
 * @package FreeFormCertificate\Settings\Views
 * @since 5.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ffc_settings = \FreeFormCertificate\Settings\SettingsReader::all();

$prefix        = $ffc_settings['url_shortener_prefix'] ?? 'go';
$code_length   = (int) ( $ffc_settings['url_shortener_code_length'] ?? 6 );
$auto_create   = isset( $ffc_settings['url_shortener_auto_create'] ) ? (int) $ffc_settings['url_shortener_auto_create'] : 1;
$redirect_type = (int) ( $ffc_settings['url_shortener_redirect_type'] ?? 302 );
$post_types    = $ffc_settings['url_shortener_post_types'] ?? array( 'post', 'page' );
if ( is_string( $post_types ) ) {
	$post_types = array_filter( array_map( 'trim', explode( ',', $post_types ) ) );
}

$expose_types = $ffc_settings['url_shortener_expose_post_types'] ?? array();
if ( is_string( $expose_types ) ) {
	$expose_types = array_filter( array_map( 'trim', explode( ',', $expose_types ) ) );
}

$all_post_types = get_post_types( array( 'public' => true ), 'objects' );
?>

<div class="ffc-settings-wrap">

<div class="card">
	<h2 class="ffc-icon-link"><?php esc_html_e( 'URL Shortener', 'ffcertificate' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Configure the built-in URL shortener. Short URLs redirect visitors and generate QR Codes.', 'ffcertificate' ); ?>
	</p>
	<p class="description">
		<?php
		printf(
			/* translators: %s: link to the Modules settings tab */
			esc_html__( 'Enable or disable this module in the %s tab.', 'ffcertificate' ),
			'<a href="' . esc_url( admin_url( 'admin.php?page=ffc-settings&tab=modulos' ) ) . '">' . esc_html__( 'Modules', 'ffcertificate' ) . '</a>'
		);
		?>
	</p>

	<form method="post">
		<?php wp_nonce_field( 'ffc_settings_action', 'ffc_settings_nonce' ); ?>
		<input type="hidden" name="_ffc_tab" value="url_shortener">

	<table class="form-table" role="presentation">
		<tbody>
		<tr>
		<th scope="row">
			<label for="url_shortener_prefix"><?php esc_html_e( 'URL Prefix', 'ffcertificate' ); ?></label>
		</th>
		<td>
			<code><?php echo esc_html( home_url( '/' ) ); ?></code>
			<input type="text" name="ffc_settings[url_shortener_prefix]" id="url_shortener_prefix"
					value="<?php echo esc_attr( $prefix ); ?>"
					class="small-text" />
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
			<?php
			\FreeFormCertificate\Admin\AdminUI::render_toggle(
				array(
					'name'    => 'ffc_settings[url_shortener_auto_create]',
					'id'      => 'url_shortener_auto_create',
					'checked' => 1 === (int) $auto_create,
					'label'   => __( 'Automatically generate a short URL when a post/page is published', 'ffcertificate' ),
					'data'    => array( 'ffc-autosave-key' => 'url_shortener_auto_create' ),
				)
			);
			?>
		</td>
	</tr>

	<tr>
		<th scope="row"><?php esc_html_e( 'Post Types', 'ffcertificate' ); ?></th>
		<td>
			<table class="widefat striped ffc-url-shortener-types">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Post type', 'ffcertificate' ); ?></th>
						<th scope="col" class="ffc-col-center"><?php esc_html_e( 'Shorten', 'ffcertificate' ); ?></th>
						<th scope="col" class="ffc-col-center"><?php esc_html_e( 'Expose', 'ffcertificate' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $all_post_types as $pt ) : ?>
					<?php
					if ( in_array( $pt->name, array( 'attachment', 'ffc_form', 'ffc_calendar' ), true ) ) {
						continue;
					}
					$ffc_is_shortened = in_array( $pt->name, $post_types, true );
					$ffc_is_exposed   = in_array( $pt->name, $expose_types, true );
					?>
					<tr>
						<td><?php echo esc_html( $pt->labels->singular_name ); ?> <code>(<?php echo esc_html( $pt->name ); ?>)</code></td>
						<td class="ffc-col-center">
							<input type="checkbox"
									class="ffc-shorten-type"
									name="ffc_settings[url_shortener_post_types][]"
									value="<?php echo esc_attr( $pt->name ); ?>"
									<?php checked( $ffc_is_shortened ); ?> />
						</td>
						<td class="ffc-col-center">
							<input type="checkbox"
									class="ffc-expose-type"
									name="ffc_settings[url_shortener_expose_post_types][]"
									value="<?php echo esc_attr( $pt->name ); ?>"
									<?php checked( $ffc_is_exposed ); ?>
									<?php disabled( ! $ffc_is_shortened ); ?> />
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">
				<?php esc_html_e( '"Shorten" shows the URL Shortener meta box on that post type. "Expose" publishes the page\'s short URL as the site\'s canonical shortlink — the rel="shortlink" tag in the page head and the HTTP Link header (also used by the editor\'s "Get Shortlink" button and REST). Expose depends on Shorten and only takes effect once a short URL exists for the page.', 'ffcertificate' ); ?>
			</p>
		</td>
	</tr>

	<tr>
		<th scope="row"><?php esc_html_e( 'Existing pages', 'ffcertificate' ); ?></th>
		<td>
			<button type="button" class="button" id="ffc-url-shortener-backfill">
				<?php esc_html_e( 'Generate missing short URLs', 'ffcertificate' ); ?>
			</button>
			<span id="ffc-url-shortener-backfill-status" class="ffc-inline-status" role="status" aria-live="polite"></span>
			<?php wp_nonce_field( \FreeFormCertificate\UrlShortener\UrlShortenerBackfillHandler::NONCE_ACTION, 'ffc_url_shortener_backfill_nonce' ); ?>
			<p class="description">
				<?php esc_html_e( 'Create short URLs for already-published posts of the shortened post types that do not have one yet. Runs in the background in batches; safe to leave running. This only creates the short URLs — tick "Expose" above to publish them as shortlinks.', 'ffcertificate' ); ?>
			</p>
		</td>
	</tr>
		</tbody>
	</table>

		<?php submit_button(); ?>
	</form>
</div>

</div><!-- .ffc-settings-wrap -->

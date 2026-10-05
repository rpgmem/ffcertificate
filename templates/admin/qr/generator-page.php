<?php
/**
 * Template: Short URLs → QR Code Generator (#1563).
 *
 * Included from {@see \FreeFormCertificate\UrlShortener\QrGeneratorPage::render_page()}.
 * Every content field carries `data-ffc-qr-field="<type>:<key>"`, which is
 * what `ffc-qr-generator.js` collects for the active type; the design rows
 * come from the shared partial.
 *
 * @var \FreeFormCertificate\Generators\QrDesign $ffc_qr_design    Starting design (the global one).
 * @var int                                      $ffc_qr_logo_id   Logo attachment id.
 * @var bool                                     $ffc_qr_gradient  Gradient on.
 * @var string                                   $ffc_qr_color_end Gradient end colour.
 * @var callable(string): string                 $ffc_qr_name      Field name for a design key.
 * @var int                                      $ffc_qr_margin    Starting quiet zone.
 * @var string                                   $ffc_qr_level     Starting error correction.
 *
 * @package FreeFormCertificate\UrlShortener
 * @since   6.34.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ffc_qr_types = array(
	'url'      => __( 'Website', 'ffcertificate' ),
	'text'     => __( 'Text', 'ffcertificate' ),
	'wifi'     => __( 'Wi-Fi', 'ffcertificate' ),
	'email'    => __( 'E-mail', 'ffcertificate' ),
	'phone'    => __( 'Phone call', 'ffcertificate' ),
	'sms'      => __( 'SMS', 'ffcertificate' ),
	'whatsapp' => __( 'WhatsApp', 'ffcertificate' ),
);
?>
<div class="wrap ffc-admin-page ffc-page-qr-generator">
	<h1><?php esc_html_e( 'QR Code Generator', 'ffcertificate' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Create a QR code for any content and download it. Nothing is stored: the design starts from Settings → QR Code and changes here apply to this code only. Only "Shorten" saves something — a short URL, listed with the others.', 'ffcertificate' ); ?>
	</p>

	<form id="ffc-qr-generator" class="ffc-qr-generator" autocomplete="off">
		<div class="ffc-qr-generator__main">
			<div class="card">
				<h2><?php esc_html_e( 'Content', 'ffcertificate' ); ?></h2>
				<fieldset class="ffc-qr-generator__types">
					<legend class="screen-reader-text"><?php esc_html_e( 'Type of content', 'ffcertificate' ); ?></legend>
					<?php foreach ( $ffc_qr_types as $ffc_type => $ffc_label ) : ?>
						<label class="ffc-qr-generator__type">
							<input type="radio" name="type" value="<?php echo esc_attr( $ffc_type ); ?>" <?php checked( 'url', $ffc_type ); ?>>
							<?php echo esc_html( $ffc_label ); ?>
						</label>
					<?php endforeach; ?>
				</fieldset>

				<table class="form-table" role="presentation" data-ffc-qr-type="url">
					<tbody>
						<tr>
							<th scope="row"><label for="ffc-qr-url"><?php esc_html_e( 'Address', 'ffcertificate' ); ?></label></th>
							<td>
								<input type="url" id="ffc-qr-url" class="large-text" data-ffc-qr-field="url:url" placeholder="https://">
								<p>
									<input type="text" id="ffc-qr-url-title" class="regular-text" placeholder="<?php esc_attr_e( 'Title for the short URL (optional)', 'ffcertificate' ); ?>">
									<button type="button" class="button" id="ffc-qr-shorten"><?php esc_html_e( 'Shorten', 'ffcertificate' ); ?></button>
								</p>
								<p class="description"><?php esc_html_e( 'Shortening creates a short URL that counts the scans, and puts it in the address field.', 'ffcertificate' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<table class="form-table" role="presentation" data-ffc-qr-type="text" hidden>
					<tbody>
						<tr>
							<th scope="row"><label for="ffc-qr-text"><?php esc_html_e( 'Text', 'ffcertificate' ); ?></label></th>
							<td><textarea id="ffc-qr-text" class="large-text" rows="4" data-ffc-qr-field="text:text"></textarea></td>
						</tr>
					</tbody>
				</table>

				<table class="form-table" role="presentation" data-ffc-qr-type="wifi" hidden>
					<tbody>
						<tr>
							<th scope="row"><label for="ffc-qr-ssid"><?php esc_html_e( 'Network name (SSID)', 'ffcertificate' ); ?></label></th>
							<td><input type="text" id="ffc-qr-ssid" class="regular-text" data-ffc-qr-field="wifi:ssid"></td>
						</tr>
						<tr>
							<th scope="row"><label for="ffc-qr-security"><?php esc_html_e( 'Security', 'ffcertificate' ); ?></label></th>
							<td>
								<select id="ffc-qr-security" data-ffc-qr-field="wifi:security">
									<option value="WPA"><?php esc_html_e( 'WPA / WPA2 / WPA3', 'ffcertificate' ); ?></option>
									<option value="WEP">WEP</option>
									<option value="nopass"><?php esc_html_e( 'Open network (no password)', 'ffcertificate' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="ffc-qr-wifi-password"><?php esc_html_e( 'Password', 'ffcertificate' ); ?></label></th>
							<td><input type="text" id="ffc-qr-wifi-password" class="regular-text" data-ffc-qr-field="wifi:password"></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Hidden network', 'ffcertificate' ); ?></th>
							<td><label><input type="checkbox" value="1" data-ffc-qr-field="wifi:hidden"> <?php esc_html_e( 'The network does not broadcast its name', 'ffcertificate' ); ?></label></td>
						</tr>
					</tbody>
				</table>

				<table class="form-table" role="presentation" data-ffc-qr-type="email" hidden>
					<tbody>
						<tr>
							<th scope="row"><label for="ffc-qr-email"><?php esc_html_e( 'To', 'ffcertificate' ); ?></label></th>
							<td><input type="email" id="ffc-qr-email" class="regular-text" data-ffc-qr-field="email:email"></td>
						</tr>
						<tr>
							<th scope="row"><label for="ffc-qr-subject"><?php esc_html_e( 'Subject', 'ffcertificate' ); ?></label></th>
							<td><input type="text" id="ffc-qr-subject" class="large-text" data-ffc-qr-field="email:subject"></td>
						</tr>
						<tr>
							<th scope="row"><label for="ffc-qr-body"><?php esc_html_e( 'Message', 'ffcertificate' ); ?></label></th>
							<td><textarea id="ffc-qr-body" class="large-text" rows="3" data-ffc-qr-field="email:body"></textarea></td>
						</tr>
					</tbody>
				</table>

				<?php foreach ( array( 'phone', 'sms', 'whatsapp' ) as $ffc_type ) : ?>
				<table class="form-table" role="presentation" data-ffc-qr-type="<?php echo esc_attr( $ffc_type ); ?>" hidden>
					<tbody>
						<tr>
							<th scope="row"><label for="ffc-qr-<?php echo esc_attr( $ffc_type ); ?>-phone"><?php esc_html_e( 'Phone number', 'ffcertificate' ); ?></label></th>
							<td>
								<input type="tel" id="ffc-qr-<?php echo esc_attr( $ffc_type ); ?>-phone" class="regular-text" placeholder="+55 11 98765-4321" data-ffc-qr-field="<?php echo esc_attr( $ffc_type ); ?>:phone">
								<p class="description"><?php esc_html_e( 'With the country code, so it works from anywhere.', 'ffcertificate' ); ?></p>
							</td>
						</tr>
						<?php if ( 'phone' !== $ffc_type ) : ?>
						<tr>
							<th scope="row"><label for="ffc-qr-<?php echo esc_attr( $ffc_type ); ?>-message"><?php esc_html_e( 'Message', 'ffcertificate' ); ?></label></th>
							<td><textarea id="ffc-qr-<?php echo esc_attr( $ffc_type ); ?>-message" class="large-text" rows="3" data-ffc-qr-field="<?php echo esc_attr( $ffc_type ); ?>:message"></textarea></td>
						</tr>
						<?php endif; ?>
					</tbody>
				</table>
				<?php endforeach; ?>
			</div>

			<div class="card">
				<h2><?php esc_html_e( 'Design', 'ffcertificate' ); ?></h2>
				<table class="form-table" role="presentation">
					<tbody>
						<?php require FFC_PLUGIN_DIR . 'templates/admin/qr/design-fields.php'; ?>
						<tr>
							<th scope="row"><label for="qr_default_margin"><?php esc_html_e( 'Margin (modules)', 'ffcertificate' ); ?></label></th>
							<td><input type="number" id="qr_default_margin" value="<?php echo esc_attr( (string) $ffc_qr_margin ); ?>" min="0" max="10" step="1" class="small-text" required></td>
						</tr>
						<tr>
							<th scope="row"><label for="qr_default_error_level"><?php esc_html_e( 'Error correction', 'ffcertificate' ); ?></label></th>
							<td>
								<select id="qr_default_error_level">
									<?php foreach ( array( 'L', 'M', 'Q', 'H' ) as $ffc_level ) : ?>
										<option value="<?php echo esc_attr( $ffc_level ); ?>" <?php selected( $ffc_level, $ffc_qr_level ); ?>><?php echo esc_html( $ffc_level ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>

		<div class="ffc-qr-generator__side">
			<div class="card">
				<div id="ffc-qr-generator-preview" class="ffc-qr-generator__preview" aria-hidden="true"></div>
				<p id="ffc-qr-generator-usage" class="ffc-qr-generator__usage"></p>
				<p id="ffc-qr-generator-status" class="ffc-qr-generator__status" role="status" aria-live="polite"></p>
				<p>
					<label for="ffc-qr-png-width"><?php esc_html_e( 'PNG width', 'ffcertificate' ); ?></label>
					<select id="ffc-qr-png-width">
						<option value="500">500 px</option>
						<option value="1000" selected>1000 px</option>
						<option value="2000">2000 px</option>
					</select>
				</p>
				<p class="ffc-qr-generator__downloads">
					<button type="button" class="button button-primary" id="ffc-qr-download-png" disabled><?php esc_html_e( 'Download PNG', 'ffcertificate' ); ?></button>
					<button type="button" class="button" id="ffc-qr-download-svg" disabled><?php esc_html_e( 'Download SVG', 'ffcertificate' ); ?></button>
				</p>
			</div>
		</div>
	</form>
</div>

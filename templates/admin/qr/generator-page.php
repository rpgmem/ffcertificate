<?php
/**
 * Template: Short URLs → QR Code Generator (#1563).
 *
 * Included from {@see \FreeFormCertificate\UrlShortener\QrGeneratorPage::render_page()}.
 * Every content field carries `data-ffc-qr-field="<type>:<key>"`, which is
 * what `ffc-qr-generator.js` collects for the active type; the design rows
 * come from the shared partial, in collapsible sections, and the preview
 * panel on the right stays in view while the form scrolls (#1570).
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

use FreeFormCertificate\Core\Icons;

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
	'vcard'    => __( 'Contact card (vCard)', 'ffcertificate' ),
	'social'   => __( 'Social profile', 'ffcertificate' ),
	'event'    => __( 'Event', 'ffcertificate' ),
);

$ffc_qr_vcard_fields = array(
	'first_name'   => array( __( 'First name', 'ffcertificate' ), 'text' ),
	'last_name'    => array( __( 'Last name', 'ffcertificate' ), 'text' ),
	'organization' => array( __( 'Organisation', 'ffcertificate' ), 'text' ),
	'job_title'    => array( __( 'Job title', 'ffcertificate' ), 'text' ),
	'phone'        => array( __( 'Work phone', 'ffcertificate' ), 'tel' ),
	'mobile'       => array( __( 'Mobile', 'ffcertificate' ), 'tel' ),
	'email'        => array( __( 'E-mail', 'ffcertificate' ), 'email' ),
	'website'      => array( __( 'Website', 'ffcertificate' ), 'url' ),
	'street'       => array( __( 'Street address', 'ffcertificate' ), 'text' ),
	'city'         => array( __( 'City', 'ffcertificate' ), 'text' ),
	'region'       => array( __( 'State', 'ffcertificate' ), 'text' ),
	'postcode'     => array( __( 'Postal code', 'ffcertificate' ), 'text' ),
	'country'      => array( __( 'Country', 'ffcertificate' ), 'text' ),
);
?>
<div class="wrap ffc-admin-page ffc-page-qr-generator">
	<h1><?php esc_html_e( 'QR Code Generator', 'ffcertificate' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Create a QR code for any content and download it. The design starts from Settings → QR Code and changes here apply to this code only. Nothing about the content is stored, except a short URL for a website or a social profile when "Create a short URL" is on — it is saved on download or print and listed with the others.', 'ffcertificate' ); ?>
	</p>

	<form id="ffc-qr-generator" class="ffc-qr-generator" autocomplete="off">
		<div class="ffc-qr-generator__main">
			<div class="card">
				<h2><?php esc_html_e( 'Content', 'ffcertificate' ); ?></h2>
				<fieldset class="ffc-qr-generator__types">
					<legend class="screen-reader-text"><?php esc_html_e( 'Type of content', 'ffcertificate' ); ?></legend>
					<?php foreach ( $ffc_qr_types as $ffc_type => $ffc_label ) : ?>
						<label class="ffc-qr-type">
							<input type="radio" class="ffc-qr-type__input" name="type" value="<?php echo esc_attr( $ffc_type ); ?>" <?php checked( 'url', $ffc_type ); ?>>
							<span class="ffc-qr-type__face">
								<?php echo Icons::svg( $ffc_type, 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- constant icon markup. ?>
								<span class="ffc-qr-type__label"><?php echo esc_html( $ffc_label ); ?></span>
							</span>
						</label>
					<?php endforeach; ?>
				</fieldset>

				<table class="form-table" role="presentation" data-ffc-qr-type="url">
					<tbody>
						<tr>
							<th scope="row"><label for="ffc-qr-url"><?php esc_html_e( 'Address', 'ffcertificate' ); ?></label></th>
							<td>
								<input type="text" inputmode="url" id="ffc-qr-url" class="large-text" data-ffc-qr-field="url:url" placeholder="https://" aria-describedby="ffc-qr-url-help">
								<p class="description" id="ffc-qr-url-help"><?php esc_html_e( 'Without a scheme, https:// is used (example.com becomes https://example.com). For http, ftp, ftps, sftp or ssh, type the full address.', 'ffcertificate' ); ?></p>
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
									<option value="WPA2-EAP"><?php esc_html_e( 'WPA2 / WPA3 Enterprise (user name and password)', 'ffcertificate' ); ?></option>
								</select>
							</td>
						</tr>
						<tr data-ffc-qr-wifi-enterprise hidden>
							<th scope="row"><label for="ffc-qr-wifi-identity"><?php esc_html_e( 'User name', 'ffcertificate' ); ?></label></th>
							<td>
								<input type="text" id="ffc-qr-wifi-identity" class="regular-text" autocomplete="off" data-ffc-qr-field="wifi:identity">
								<p class="description"><?php esc_html_e( 'Android saves the network from the code but leaves the CA certificate unset, so it will not connect yet: open the saved network, set CA certificate to "Trust on first use" (or "Do not validate"), then connect. The iPhone camera does not read Enterprise codes; iPhone users type the details in.', 'ffcertificate' ); ?></p>
							</td>
						</tr>
						<tr data-ffc-qr-wifi-enterprise hidden>
							<th scope="row"><label for="ffc-qr-wifi-eap"><?php esc_html_e( 'EAP method', 'ffcertificate' ); ?></label></th>
							<td>
								<select id="ffc-qr-wifi-eap" data-ffc-qr-field="wifi:eap">
									<option value="PEAP">PEAP</option>
									<option value="TTLS">TTLS</option>
								</select>
							</td>
						</tr>
						<tr data-ffc-qr-wifi-enterprise hidden>
							<th scope="row"><label for="ffc-qr-wifi-phase2"><?php esc_html_e( 'Phase 2 authentication', 'ffcertificate' ); ?></label></th>
							<td>
								<select id="ffc-qr-wifi-phase2" data-ffc-qr-field="wifi:phase2">
									<option value="MSCHAPV2">MSCHAPv2</option>
									<option value="GTC">GTC</option>
									<option value="PAP">PAP</option>
								</select>
							</td>
						</tr>
						<tr data-ffc-qr-wifi-enterprise hidden>
							<th scope="row"><label for="ffc-qr-wifi-anonymous"><?php esc_html_e( 'Anonymous identity', 'ffcertificate' ); ?></label></th>
							<td>
								<input type="text" id="ffc-qr-wifi-anonymous" class="regular-text" autocomplete="off" data-ffc-qr-field="wifi:anonymous">
								<p class="description"><?php esc_html_e( 'Optional. Leave blank unless the network administrator gave you one.', 'ffcertificate' ); ?></p>
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

				<table class="form-table" role="presentation" data-ffc-qr-type="vcard" hidden>
					<tbody>
						<?php foreach ( $ffc_qr_vcard_fields as $ffc_key => $ffc_field ) : ?>
						<tr>
							<th scope="row"><label for="ffc-qr-vcard-<?php echo esc_attr( $ffc_key ); ?>"><?php echo esc_html( $ffc_field[0] ); ?></label></th>
							<td><input type="<?php echo esc_attr( $ffc_field[1] ); ?>" id="ffc-qr-vcard-<?php echo esc_attr( $ffc_key ); ?>" class="regular-text" data-ffc-qr-field="vcard:<?php echo esc_attr( $ffc_key ); ?>"></td>
						</tr>
						<?php endforeach; ?>
						<tr>
							<th scope="row"><label for="ffc-qr-vcard-note"><?php esc_html_e( 'Note', 'ffcertificate' ); ?></label></th>
							<td>
								<textarea id="ffc-qr-vcard-note" class="large-text" rows="2" data-ffc-qr-field="vcard:note"></textarea>
								<p class="description"><?php esc_html_e( 'The whole card travels inside the code: it works offline and nothing about the person is stored. Every field you fill makes the code denser.', 'ffcertificate' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<table class="form-table" role="presentation" data-ffc-qr-type="social" hidden>
					<tbody>
						<tr>
							<th scope="row"><label for="ffc-qr-network"><?php esc_html_e( 'Network', 'ffcertificate' ); ?></label></th>
							<td>
								<select id="ffc-qr-network" data-ffc-qr-field="social:network">
									<?php foreach ( \FreeFormCertificate\Generators\QrPayload::NETWORKS as $ffc_key => $ffc_network ) : ?>
										<option value="<?php echo esc_attr( $ffc_key ); ?>" data-ffc-qr-prefix="<?php echo esc_attr( $ffc_network[1] ); ?>"><?php echo esc_html( $ffc_network[0] ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="ffc-qr-username"><?php esc_html_e( 'User name', 'ffcertificate' ); ?></label></th>
							<td>
								<code id="ffc-qr-social-prefix"><?php echo esc_html( (string) array_values( \FreeFormCertificate\Generators\QrPayload::NETWORKS )[0][1] ); ?></code>
								<input type="text" id="ffc-qr-username" class="regular-text" data-ffc-qr-field="social:username">
							</td>
						</tr>
					</tbody>
				</table>

				<div class="ffc-qr-short" data-ffc-qr-short-for="url social">
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><?php esc_html_e( 'Short URL', 'ffcertificate' ); ?></th>
								<td>
									<?php
									\FreeFormCertificate\Admin\AdminUI::render_toggle(
										array(
											'name'    => 'ffc_qr_short',
											'id'      => 'ffc-qr-short',
											'checked' => true,
											'label'   => __( 'Create a short URL', 'ffcertificate' ),
										)
									);
									?>
									<p class="description"><?php esc_html_e( 'On: the code carries a short URL that counts the scans and whose destination can be changed later, even after printing. It is saved on download or print. Off: the code carries the address itself, nothing is stored in the database, and it keeps working even if this site goes down.', 'ffcertificate' ); ?></p>
									<p class="ffc-qr-short__notice" id="ffc-qr-short-direct" hidden><?php esc_html_e( 'A short URL redirects web addresses only (http or https), so this address is used as is.', 'ffcertificate' ); ?></p>
									<p class="ffc-qr-short__notice" id="ffc-qr-short-circular" hidden><?php esc_html_e( 'This address is already a short URL of this site, so it is used as is: shortening it again would only chain two redirects.', 'ffcertificate' ); ?></p>
								</td>
							</tr>
							<tr data-ffc-qr-short-on>
								<th scope="row"><label for="ffc-qr-short-title"><?php esc_html_e( 'Title', 'ffcertificate' ); ?> <span class="required" aria-hidden="true">*</span></label></th>
								<td>
									<input type="text" id="ffc-qr-short-title" class="regular-text" maxlength="255" aria-required="true">
									<p class="description"><?php esc_html_e( 'Required: it names the short URL in the list.', 'ffcertificate' ); ?></p>
								</td>
							</tr>
							<tr data-ffc-qr-short-on id="ffc-qr-short-duplicates" hidden>
								<th scope="row"><?php esc_html_e( 'Already shortened', 'ffcertificate' ); ?></th>
								<td>
									<div class="ffc-qr-short__duplicates">
										<p><?php esc_html_e( 'A short URL already sends to this address. Use it, or confirm that you want another one.', 'ffcertificate' ); ?></p>
										<ul id="ffc-qr-short-duplicate-list"></ul>
										<label><input type="checkbox" id="ffc-qr-short-ack" value="1"> <?php esc_html_e( 'I know, and I want to create another short URL for this address.', 'ffcertificate' ); ?></label>
									</div>
								</td>
							</tr>
							<tr id="ffc-qr-short-result-row" hidden>
								<th scope="row"><?php esc_html_e( 'Short URL in the code', 'ffcertificate' ); ?></th>
								<td>
									<button type="button" class="button-link ffc-qr-short__result" id="ffc-qr-short-result" title="<?php esc_attr_e( 'Click to copy', 'ffcertificate' ); ?>"></button>
									<span class="ffc-qr-short__copied" id="ffc-qr-short-copied" role="status" aria-live="polite"></span>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<table class="form-table" role="presentation" data-ffc-qr-type="event" hidden>
					<tbody>
						<tr>
							<th scope="row"><label for="ffc-qr-event-title"><?php esc_html_e( 'Title', 'ffcertificate' ); ?></label></th>
							<td><input type="text" id="ffc-qr-event-title" class="large-text" data-ffc-qr-field="event:title"></td>
						</tr>
						<tr>
							<th scope="row"><label for="ffc-qr-event-date"><?php esc_html_e( 'Date', 'ffcertificate' ); ?></label></th>
							<td>
								<input type="date" id="ffc-qr-event-date" data-ffc-qr-field="event:date">
								<input type="time" id="ffc-qr-event-start" aria-label="<?php esc_attr_e( 'Start time', 'ffcertificate' ); ?>" data-ffc-qr-field="event:start">
								&ndash;
								<input type="time" id="ffc-qr-event-end" aria-label="<?php esc_attr_e( 'End time', 'ffcertificate' ); ?>" data-ffc-qr-field="event:end">
								<p class="description"><?php esc_html_e( 'In the site\'s time zone, on one day.', 'ffcertificate' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="ffc-qr-event-location"><?php esc_html_e( 'Location', 'ffcertificate' ); ?></label></th>
							<td><input type="text" id="ffc-qr-event-location" class="large-text" data-ffc-qr-field="event:location"></td>
						</tr>
						<tr>
							<th scope="row"><label for="ffc-qr-event-description"><?php esc_html_e( 'Description', 'ffcertificate' ); ?></label></th>
							<td><textarea id="ffc-qr-event-description" class="large-text" rows="3" data-ffc-qr-field="event:description"></textarea></td>
						</tr>
						<tr>
							<th scope="row"><label for="ffc-qr-event-mode"><?php esc_html_e( 'Scanning opens', 'ffcertificate' ); ?></label></th>
							<td>
								<select id="ffc-qr-event-mode" data-ffc-qr-field="event:mode">
									<option value="vevent"><?php esc_html_e( 'The event itself (the phone offers to add it to its calendar)', 'ffcertificate' ); ?></option>
									<option value="google"><?php esc_html_e( 'Google Calendar, already filled in', 'ffcertificate' ); ?></option>
									<option value="ics"><?php esc_html_e( 'A calendar file (.ics) for any calendar app', 'ffcertificate' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="ffc-qr-event-until"><?php esc_html_e( 'Link valid until', 'ffcertificate' ); ?></label></th>
							<td>
								<input type="date" id="ffc-qr-event-until" data-ffc-qr-field="event:until">
								<p class="description"><?php esc_html_e( 'Calendar file only. Leave empty for a link that never expires -- a printed code keeps working. After this date the link answers that it has expired.', 'ffcertificate' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="ffc-qr-generator__design">
				<div class="ffc-qr-generator__design-head">
					<h2><?php esc_html_e( 'Design', 'ffcertificate' ); ?></h2>
					<button type="button" class="button" id="ffc-qr-design-reset"><?php esc_html_e( 'Reset to default', 'ffcertificate' ); ?></button>
				</div>
				<p class="description"><?php esc_html_e( 'The generator remembers your design when you download a code; "Reset to default" goes back to the global design from Settings → QR Code.', 'ffcertificate' ); ?></p>
				<div class="ffc-qr-sections">
					<?php require FFC_PLUGIN_DIR . 'templates/admin/qr/design-fields.php'; ?>
					<?php $ffc_qr_section( 'advanced', __( 'Advanced', 'ffcertificate' ), __( 'Quiet zone and error correction.', 'ffcertificate' ) ); ?>
						<div class="ffc-qr-fields">
							<div class="ffc-qr-field">
								<label class="ffc-qr-field__label" for="qr_default_margin"><?php esc_html_e( 'Margin (modules)', 'ffcertificate' ); ?></label>
								<input type="number" id="qr_default_margin" value="<?php echo esc_attr( (string) $ffc_qr_margin ); ?>" min="0" max="10" step="1" class="small-text" required>
							</div>
							<div class="ffc-qr-field">
								<label class="ffc-qr-field__label" for="qr_default_error_level"><?php esc_html_e( 'Error correction', 'ffcertificate' ); ?></label>
								<select id="qr_default_error_level">
									<?php foreach ( array( 'L', 'M', 'Q', 'H' ) as $ffc_level ) : ?>
										<option value="<?php echo esc_attr( $ffc_level ); ?>" <?php selected( $ffc_level, $ffc_qr_level ); ?>><?php echo esc_html( $ffc_level ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
						</div>
						</div>
					</details>
				</div>
			</div>
		</div>

		<div class="ffc-qr-generator__side">
			<div class="ffc-qr-generator__panel">
				<h2 class="ffc-qr-generator__panel-title"><?php esc_html_e( 'Preview', 'ffcertificate' ); ?></h2>
				<div id="ffc-qr-generator-preview" class="ffc-qr-generator__preview" aria-hidden="true"></div>
				<p id="ffc-qr-generator-usage" class="ffc-qr-generator__usage"></p>
				<p id="ffc-qr-generator-status" class="ffc-qr-generator__status" role="status" aria-live="polite"></p>
				<div class="ffc-qr-generator__export">
					<div class="ffc-qr-field">
						<label class="ffc-qr-field__label" for="ffc-qr-format"><?php esc_html_e( 'Format', 'ffcertificate' ); ?></label>
						<select id="ffc-qr-format">
							<option value="png" selected>PNG</option>
							<option value="svg">SVG</option>
						</select>
					</div>
					<div class="ffc-qr-field">
						<label class="ffc-qr-field__label" for="ffc-qr-png-width"><?php esc_html_e( 'Size', 'ffcertificate' ); ?></label>
						<select id="ffc-qr-png-width">
							<option value="500">500 px</option>
							<option value="1000" selected>1000 px</option>
							<option value="2000">2000 px</option>
						</select>
					</div>
				</div>
				<div class="ffc-qr-generator__actions">
					<button type="button" class="button button-primary ffc-qr-generator__download" id="ffc-qr-download" disabled>
						<?php echo Icons::svg( 'download', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- constant icon markup. ?>
						<?php esc_html_e( 'Download', 'ffcertificate' ); ?>
					</button>
					<button type="button" class="button ffc-qr-generator__print" id="ffc-qr-print" disabled>
						<?php echo Icons::svg( 'print', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- constant icon markup. ?>
						<span class="screen-reader-text"><?php esc_html_e( 'Print', 'ffcertificate' ); ?></span>
					</button>
				</div>
			</div>
		</div>
	</form>

	<div id="ffc-qr-short-saved" class="ffc-qr-saved" hidden>
		<div class="ffc-qr-saved__backdrop" data-ffc-qr-saved-close></div>
		<div class="ffc-qr-saved__dialog" role="dialog" aria-modal="true" aria-labelledby="ffc-qr-short-saved-title" aria-describedby="ffc-qr-short-saved-text">
			<button type="button" class="ffc-qr-saved__close" data-ffc-qr-saved-close aria-label="<?php esc_attr_e( 'Close', 'ffcertificate' ); ?>">&times;</button>
			<span class="dashicons dashicons-yes-alt ffc-qr-saved__icon" aria-hidden="true"></span>
			<h2 class="ffc-qr-saved__title" id="ffc-qr-short-saved-title"><?php esc_html_e( 'Short URL saved', 'ffcertificate' ); ?></h2>
			<p class="ffc-qr-saved__text" id="ffc-qr-short-saved-text"></p>
			<div class="ffc-qr-saved__link">
				<label class="screen-reader-text" for="ffc-qr-short-saved-url"><?php esc_html_e( 'Short URL', 'ffcertificate' ); ?></label>
				<input type="text" id="ffc-qr-short-saved-url" class="ffc-qr-saved__url" readonly>
				<button type="button" class="button button-primary" id="ffc-qr-short-saved-copy"><?php esc_html_e( 'Copy', 'ffcertificate' ); ?></button>
			</div>
			<p class="ffc-qr-saved__copied" id="ffc-qr-short-saved-copied" role="status" aria-live="polite"></p>
			<p class="ffc-qr-saved__actions">
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=ffc-short-urls' ) ); ?>"><?php esc_html_e( 'Open the Short URLs list', 'ffcertificate' ); ?></a>
				<button type="button" class="button" data-ffc-qr-saved-close><?php esc_html_e( 'Close', 'ffcertificate' ); ?></button>
			</p>
		</div>
	</div>
</div>

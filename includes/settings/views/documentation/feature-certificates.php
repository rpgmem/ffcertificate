<?php
/**
 * Documentation partial — Feature: Certificates & Forms.
 *
 * Forms overview + geofence + complete template examples. Part of the
 * documentation reorganization (rpgmem/ffcertificate#674).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Certificates & Forms Section -->
<div class="card">
	<h3 id="feature-certificates" class="ffc-icon-award"><?php esc_html_e( 'Certificates & Forms', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'Create certificate-issuance forms, generate the certificate PDF automatically on submission, and let anyone verify authenticity by QR code or validation link.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Certificates Dashboard', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Certificates → Dashboard opens with a summary row (forms in the month shown, submissions today and over the last 7 days, GeoFence windows open now) above a calendar of forms by GeoFence start date, or by publication date when a form has none. Picking a day lists its forms beside the calendar. It needs ffc_view_certificates.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Issuing certificates in bulk', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'When the participants are already known — an attendance sheet, a course roster — open the form in the form editor and use its Bulk issue tab, the last one, which appears only to users who hold the Issue certificates in bulk capability. Download the CSV template for the form, upload your file (up to 2 MB) and map each column to a form field. Name, CPF/RF and e-mail are required, the fields marked * must be mapped, and a row that leaves one of them empty is skipped. The preview then says how many certificates would be issued and how many rows would be skipped, with the reason for each skipped row. Nothing is issued until you confirm, and the certificates are issued in batches with a progress indicator.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'On a quiz form the tab also asks for the score of each participant: take the points from a column of the file, which must not exceed the maximum and must reach the passing score, or issue every certificate with a zero score, in which case the passing score does not apply and the preview warns you first. The issue date is the day each certificate is issued, one date you choose for all of them, or a column of the file. Choose whether to send the magic-link e-mail as each certificate is issued or not at all; a link that was not sent can be sent later from the submissions list with Send link by e-mail.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'A row is skipped, and listed in the report, when its name, CPF/RF or e-mail is missing or invalid, when a CPF or RF fails its check digits, when the same identifier appears earlier in the file, or when the form already holds a certificate for that CPF or RF. The form\'s own restrictions — allowlist, tickets, geofence, rate limit, date window and captcha — do not apply to the import. Each job writes one Activity Log entry, and a job left unfinished for seven days is expired and its staged rows are removed.', 'ffcertificate' ); ?></p>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Building a form', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'Place a form on any page with the [ffc_form id="123"] shortcode, then configure its fields, certificate PDF template and email in the form editor. See the Reference section for the details:', 'ffcertificate' ); ?></p>
		<ul>
			<li><strong><?php esc_html_e( 'Shortcodes', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'the [ffc_form] tag and its attributes', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Template Variables / Tokens', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'the {{placeholders}} available in the certificate PDF', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'QR Code in the Certificate', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'the {{qr_code}} verification placeholder', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Validation URL', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'building the certificate verification link', 'ffcertificate' ); ?></li>
		</ul>
		<p><?php esc_html_e( 'In the form editor each tab opens into collapsible sections whose chip says On/Off, or the schedule, while closed, and each tab carries a dot that follows its switches: hollow when off, green when on, and amber while something switched on still lacks the field it needs (a password, a list, the tickets, a date or time, an allowed area for GPS) or, when Settings → Certificates requires {{schedule}}, while the event schedule is empty. A summary box in the sidebar shows what the saved form does, the shortcode has a Copy button, and the registered locations of a geofence are picked with checkboxes.', 'ffcertificate' ); ?></p>
		<p><?php esc_html_e( 'What a certificate requires is one table, Settings → Certificates → Required data. Each row has a name, a label, a type and two switches. Required in the form: a new form starts with the field, and the form editor locks it and refuses a form without it. Required in the certificate HTML: the form editor refuses to save a layout that lacks the {{tag}}. By default name and cpf_rf are required in both, email in the form and {{auth_code}} and {{schedule}} in the HTML. Email cannot leave the form and {{auth_code}} cannot leave the HTML. A tag the plugin fills in itself, such as {{schedule}}, has the HTML switch only, and {{schedule}} is asked only of a form with an Event Schedule on the Time tab. The table saves as you change it.', 'ffcertificate' ); ?></p>
		<p><?php esc_html_e( 'The same tab holds the quiz focus-loss grace: how long, in milliseconds, a quiz window set to Strict may lose the focus before it counts as leaving (default 1000, up to 10000; 0 counts every loss).', 'ffcertificate' ); ?></p>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Checks before the form', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'Before a visitor sees a certificate form, the browser runs a short queue of checks, in this order. The first one that fails stops the queue and shows one message; nothing after it runs until the visitor fixes the cause or goes on where that is allowed.', 'ffcertificate' ); ?></p>
		<ol>
			<li><?php esc_html_e( 'Date and time: on a form with a date window, outside it the form\'s closed-period message is shown.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Secure connection: a form that asks for the location, or any form while the captcha is ALTCHA only, needs a page the browser treats as secure (HTTPS, or localhost); on any other page the visitor is asked to contact the site administrator. The browser decides, so a development host such as a .local name or a LAN address over plain HTTP is stopped too.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Browser: an app\'s own browser (Instagram, WhatsApp, Facebook, TikTok, LinkedIn and others) is asked to open the page in the phone\'s browser, where the location and the download work; a "Continue anyway" link lets the visitor go on, because the detection can misfire. A browser missing what the form needs to issue the certificate is stopped with the list of browsers to use, and cannot go on.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Cookies: a browser that blocks cookies is warned, with how to allow them, and may try anyway.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Location: on a form with GPS validation, the location is asked for and checked (see Geolocation / Geofence).', 'ffcertificate' ); ?></li>
		</ol>
		<p><?php esc_html_e( 'Every form runs the browser and cookie checks, not only the ones with a restriction. The "already submitted" notice is drawn only after the queue passes. Settings → Certificates → Browser check turns the browser check off, should visitors be stopped by mistake. An administrator for whom the geolocation bypass is on skips the browser and cookie checks. Each stop is written to the Activity Log as a pre-flight block, naming the form, or the CSV download page the visitor was on, and the form editor counts its own form\'s.', 'ffcertificate' ); ?></p>
		<p><?php esc_html_e( 'The public CSV download page ([ffc_csv_download]) runs the browser check too, under the same setting: the file is built and saved by the browser, which an app\'s own browser often cannot do.', 'ffcertificate' ); ?></p>
		<p><?php esc_html_e( 'The verification page ([ffc_verification]) has a "Test my device" mode, linked from its footer and reached by adding ?ffc_device_check=1 to its address. It runs these checks on the visitor\'s own device and shows each result, grouped by subject: secure connection, connection, whether a request reaches the server (and how long it took), the browser by name and version, cookies, local storage, the captcha (what ALTCHA needs, and whether its widget loaded), the device ID the device limit reads (a short code, how many signals were read and whether they hold between two reads), the device clock against the server\'s, time zone and language, the device\'s location (the permission and, when the visitor asks, how precise it is), screen, the theme in use and, last, PDF download. A summary above the rows says whether the device is ready, ready with warnings, or may fail. A row that fails carries an information button with how to fix it, and a check still running counts down from 20 seconds. A content or script blocker is named where it stops the server request, the device ID or the captcha; when it stops the test\'s own scripts, the page says so after a few seconds without them. A page served from an old cache, whose scripts belong to an earlier release, is named too, and how often one address may run the test is set on Settings → Rate Limit (default 60 runs per 10 minutes). Nothing is sent or stored, and the position itself is never shown. A "Copy the results" button puts the results, the plugin\'s version, the captcha mode, whether the device limit is on, the browser\'s identification and the time in the clipboard, for a message to whoever supports the visitor.', 'ffcertificate' ); ?></p>
		<p><?php esc_html_e( 'The layout box of the form editor sets a background image and a background colour; the colour fills the page wherever the image does not cover it. Preview renders the unsaved layout on the server with sample data, through the same renderer as the PDF, so the background, the colour and the QR code are the ones the certificate prints. Save as model keeps the layout, the image and the colour, and loading the model brings all three back.', 'ffcertificate' ); ?></p>
		<p><?php esc_html_e( 'The {{qr_code}} image follows the design set in Settings → QR Code (module shape, colours, logo, frame) when that design is switched on for certificates; otherwise a plain black-and-white code is drawn.', 'ffcertificate' ); ?></p>
		<p><?php esc_html_e( 'The All Forms list has a Features column with three switches per form — CSV (the public operator download), Quiz and Device limit. Each saves as soon as it is flipped, writes the same setting as the form editor, and is read-only for someone who cannot edit that form.', 'ffcertificate' ); ?></p>
	</div>

	<?php ob_start(); ?>
		<p>
			<?php esc_html_e( 'Certificate PDF layouts are managed as reusable, duplicable templates in the Document Templates hub — not edited inline per form only. The form editor selects which pool template it issues; the same pool also backs the reregistration record and the appointment receipt. Build or duplicate a layout once and reuse it across forms.', 'ffcertificate' ); ?> <a href="#document-templates-hub"><?php esc_html_e( 'See the Document Templates hub.', 'ffcertificate' ); ?></a>
		</p>
	<?php
	\FreeFormCertificate\Core\AdminNotice::callout(
		'info',
		(string) ob_get_clean(),
		array( 'title' => __( 'Templates come from a shared pool.', 'ffcertificate' ) )
	);
	?>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Geofence: restrict where a form can be submitted', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'Define reusable named locations for geofencing restrictions. Locations are shared across all forms and can be assigned as defaults.', 'ffcertificate' ); ?></p>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Managing Locations:', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'Go to Settings > Geolocation tab to add, edit, or delete locations. Each location has:', 'ffcertificate' ); ?></p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Field', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Description', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><strong><?php esc_html_e( 'Name', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Descriptive name (e.g. "Main Office", "Campus North")', 'ffcertificate' ); ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Latitude / Longitude', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'GPS coordinates of the center point', 'ffcertificate' ); ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Radius', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Radius in meters around the center point', 'ffcertificate' ); ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Default GPS', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'When enabled, new forms auto-select this location for GPS validation', 'ffcertificate' ); ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Default IP', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'When enabled, new forms auto-select this location for IP validation', 'ffcertificate' ); ?></td></tr>
			</tbody>
		</table>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Using in Forms:', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'On the Geolocation tab of the form editor\'s "Certificate Form Configuration" box, choose the area source for GPS and IP validation:', 'ffcertificate' ); ?></p>
		<ul>
			<li><strong><?php esc_html_e( 'Registered Locations:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'Tick one or more named locations in the list. Coordinates are resolved at runtime from the registry.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Custom Coordinates:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'Enter coordinates manually in the textarea (lat,lng,radius format, one per line). This is the legacy behavior.', 'ffcertificate' ); ?></li>
		</ul>
		<p><?php esc_html_e( 'Existing forms that were created before this feature default to "Custom Coordinates" and continue to work without any changes.', 'ffcertificate' ); ?></p>
	</div>

	<details class="ffc-doc-example">
		<summary><strong><?php esc_html_e( 'Complete template examples', 'ffcertificate' ); ?></strong></summary>
	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Example 1: Simple Certificate', 'ffcertificate' ); ?></h4>
		<pre><code>&lt;div style="text-align: center; font-family: Arial; padding: 50px;"&gt;
	&lt;h1&gt;CERTIFICADO&lt;/h1&gt;
	
	&lt;p&gt;
		Certificamos que &lt;strong&gt;{{name}}&lt;/strong&gt;, 
		CPF &lt;strong&gt;{{cpf_rf}}&lt;/strong&gt;, 
		participou do evento &lt;strong&gt;{{form_title}}&lt;/strong&gt;.
	&lt;/p&gt;
	
	&lt;p&gt;Data: {{submission_date}}&lt;/p&gt;
	&lt;p&gt;Código: {{auth_code}}&lt;/p&gt;
	
	{{qr_code:size=150}}
&lt;/div&gt;</code></pre>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Example 2: Certificate with Header & Footer', 'ffcertificate' ); ?></h4>
		<pre><code>&lt;div style="font-family: Arial; padding: 30px;"&gt;
	&lt;!-- Header with logos --&gt;
	&lt;table width="100%"&gt;
		&lt;tr&gt;
			&lt;td width="25%"&gt;
				&lt;img src="https://example.com/logo-left.png" width="150"&gt;
			&lt;/td&gt;
			&lt;td width="50%" style="text-align: center;"&gt;
				&lt;div style="font-size: 10pt;"&gt;
					ORGANIZATION NAME&lt;br&gt;
					DEPARTMENT&lt;br&gt;
					DIVISION
				&lt;/div&gt;
			&lt;/td&gt;
			&lt;td width="25%" style="text-align: right;"&gt;
				&lt;img src="https://example.com/logo-right.png" width="150"&gt;
			&lt;/td&gt;
		&lt;/tr&gt;
	&lt;/table&gt;
	
	&lt;!-- Title --&gt;
	&lt;p style="text-align: center; margin-top: 40px;"&gt;
		&lt;strong style="font-size: 20pt;"&gt;CERTIFICATE OF ATTENDANCE&lt;/strong&gt;
	&lt;/p&gt;
	
	&lt;!-- Body --&gt;
	&lt;div style="text-align: center; margin: 40px 0; font-size: 12pt;"&gt;
		We certify that &lt;strong&gt;{{name}}&lt;/strong&gt;, 
		ID: &lt;strong&gt;{{cpf_rf}}&lt;/strong&gt;, 
		successfully attended the &lt;strong&gt;{{program}}&lt;/strong&gt; program 
		held on December 11, 2025.
	&lt;/div&gt;
	
	&lt;!-- Signature --&gt;
	&lt;table width="100%" style="margin-top: 60px;"&gt;
		&lt;tr&gt;
			&lt;td width="50%"&gt;&lt;/td&gt;
			&lt;td width="50%" style="text-align: center;"&gt;
				&lt;img src="https://example.com/signature.png" height="60"&gt;&lt;br&gt;
				&lt;div style="border-top: 1px solid #000; width: 200px; margin: 5px auto;"&gt;&lt;/div&gt;
				&lt;strong&gt;Director Name&lt;/strong&gt;&lt;br&gt;
				&lt;span style="font-size: 9pt;"&gt;Position Title&lt;/span&gt;
			&lt;/td&gt;
		&lt;/tr&gt;
	&lt;/table&gt;
	
	&lt;!-- Footer with QR Code --&gt;
	&lt;div style="margin-top: 60px;"&gt;
		&lt;table width="100%"&gt;
			&lt;tr&gt;
				&lt;td width="30%"&gt;
					{{qr_code:size=150:margin=0}}
				&lt;/td&gt;
				&lt;td width="70%" style="font-size: 9pt; vertical-align: middle;"&gt;
					Issued: {{submission_date}}&lt;br&gt;
					Verify at: https://example.com/verify/&lt;br&gt;
					Verification Code: &lt;strong&gt;{{auth_code}}&lt;/strong&gt;
				&lt;/td&gt;
			&lt;/tr&gt;
		&lt;/table&gt;
	&lt;/div&gt;
&lt;/div&gt;</code></pre>
	</div>
	</details>
</div>

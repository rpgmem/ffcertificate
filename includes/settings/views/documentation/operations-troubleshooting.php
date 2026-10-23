<?php
/**
 * Documentation partial — Section 18: Troubleshooting.
 *
 * Extracted from `ffc-tab-documentation.php` per S8 of the
 * god-object refactor (rpgmem/ffcertificate#141).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- 18. Troubleshooting Section -->
<div class="card">
	<h3 id="operations-troubleshooting" class="ffc-icon-help"><?php esc_html_e( 'Troubleshooting', 'ffcertificate' ); ?></h3>

	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Problem', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Solution', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><?php esc_html_e( 'Variable not replaced', 'ffcertificate' ); ?> <code>{{name}}</code></td>
				<td>
					• <?php esc_html_e( 'Check spelling matches exactly', 'ffcertificate' ); ?><br>
					• <?php esc_html_e( 'Ensure field exists in form', 'ffcertificate' ); ?><br>
					• <?php esc_html_e( 'Use lowercase for custom fields', 'ffcertificate' ); ?>
				</td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Image not showing in PDF', 'ffcertificate' ); ?></td>
				<td>
					• <?php esc_html_e( 'Use absolute URLs (https://...)', 'ffcertificate' ); ?><br>
					• <?php esc_html_e( 'Check image is publicly accessible', 'ffcertificate' ); ?><br>
					• <?php esc_html_e( 'Add width/height attributes', 'ffcertificate' ); ?>
				</td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'QR Code too large/small', 'ffcertificate' ); ?></td>
				<td>
					• <?php esc_html_e( 'Use:', 'ffcertificate' ); ?> <code>{{qr_code:size=150}}</code><br>
					• <?php esc_html_e( 'Recommended: 100-200px for certificates', 'ffcertificate' ); ?>
				</td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Formatting not showing (bold, italic)', 'ffcertificate' ); ?></td>
				<td>
					• <?php esc_html_e( 'Use HTML tags:', 'ffcertificate' ); ?> <code>&lt;strong&gt;</code> <code>&lt;em&gt;</code><br>
					• <?php esc_html_e( 'Or inline style:', 'ffcertificate' ); ?> <code>style="font-weight: bold;"</code>
				</td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Layout broken in PDF', 'ffcertificate' ); ?></td>
				<td>
					• <?php esc_html_e( 'Use tables for complex layouts', 'ffcertificate' ); ?><br>
					• <?php esc_html_e( 'Always use inline styles', 'ffcertificate' ); ?><br>
					• <?php esc_html_e( 'Test with simple content first', 'ffcertificate' ); ?>
				</td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Settings not saving between tabs', 'ffcertificate' ); ?></td>
				<td>
					• <?php esc_html_e( 'Update to latest version', 'ffcertificate' ); ?><br>
					• <?php esc_html_e( 'Clear WordPress cache', 'ffcertificate' ); ?><br>
					• <?php esc_html_e( 'Clear browser cache (Ctrl+F5)', 'ffcertificate' ); ?>
				</td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Emails not arriving', 'ffcertificate' ); ?></td>
				<td>
					• <?php esc_html_e( 'Confirm the global "Disable all emails" toggle is off (Settings → SMTP)', 'ffcertificate' ); ?><br>
					• <?php esc_html_e( 'Configure Custom SMTP with a real provider — the server default often lands in spam', 'ffcertificate' ); ?><br>
					• <?php esc_html_e( 'Check the recipient spam folder and sender reputation (SPF / DKIM)', 'ffcertificate' ); ?><br>
					• <?php esc_html_e( 'For bulk sends, install the total-mail-queue plugin for queueing + automatic retries', 'ffcertificate' ); ?><br>
					• <?php esc_html_e( 'See the "Emails &amp; Delivery" reference page for the full pipeline', 'ffcertificate' ); ?>
				</td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Form is hidden or "not available"', 'ffcertificate' ); ?></td>
				<td>
					• <?php esc_html_e( 'Check the form\'s Date & Time Restrictions (the "Time" tab of its restrictions) — the form may be outside its open/close window', 'ffcertificate' ); ?><br>
					• <?php esc_html_e( 'Check Geolocation (GPS/IP) — the visitor may be outside the allowed area', 'ffcertificate' ); ?><br>
					• <?php esc_html_e( 'Admins can enable "bypass" for date/time and geolocation to test (Settings → Geolocation)', 'ffcertificate' ); ?>
				</td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'A visitor sees "The form did not load completely" or "JavaScript is off"', 'ffcertificate' ); ?></td>
				<td>
					• <?php esc_html_e( 'The form is sent and the PDF is drawn by scripts in the browser, so it cannot work without them', 'ffcertificate' ); ?><br>
					• <?php esc_html_e( 'A content blocker or a dropped request kept a script, or the refresh of the captcha and security codes, from loading: ask the visitor to reload, then to allow the site in the blocker or use another browser', 'ffcertificate' ); ?><br>
					• <?php esc_html_e( 'If every visitor sees it, check that a cache or optimisation plugin is not combining or delaying the plugin\'s scripts, and that admin-ajax.php is reachable', 'ffcertificate' ); ?>
				</td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'A visitor sees "Open this page in your browser" or "This browser cannot run the form"', 'ffcertificate' ); ?></td>
				<td>
					• <?php esc_html_e( 'The page was opened inside an app (a link in Instagram or WhatsApp, for example): the visitor should open it in the phone\'s browser, or use "Continue anyway"', 'ffcertificate' ); ?><br>
					• <?php esc_html_e( 'A browser missing what the form needs cannot issue the certificate: the message lists the browsers to use instead', 'ffcertificate' ); ?><br>
					• <?php esc_html_e( 'If visitors in an ordinary browser are stopped, turn the check off under Settings → Certificates → Browser check and report the browser', 'ffcertificate' ); ?>
				</td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Short URL returns 404', 'ffcertificate' ); ?></td>
				<td>
					• <?php esc_html_e( 'Re-save Settings → Permalinks once to flush WordPress rewrite rules', 'ffcertificate' ); ?><br>
					• <?php esc_html_e( 'Confirm the link is Active (not disabled or trashed) in the Short URLs list', 'ffcertificate' ); ?>
				</td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Submission blocked ("limit reached")', 'ffcertificate' ); ?></td>
				<td>
					• <?php esc_html_e( 'The IP / email / CPF-RF rate limit was hit — review Settings → Rate Limit', 'ffcertificate' ); ?><br>
					• <?php esc_html_e( 'Add a trusted IP or identifier to the allowlist there if needed', 'ffcertificate' ); ?>
				</td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'An admin cannot see a plugin menu', 'ffcertificate' ); ?></td>
				<td>
					• <?php esc_html_e( 'Each area is gated by a capability — grant the matching ffc_view_* capability on the user profile', 'ffcertificate' ); ?><br>
					• <?php esc_html_e( 'A full administrator (manage_options) always sees every FFC menu', 'ffcertificate' ); ?>
				</td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Reminders, cleanups or date messages run late or not at all', 'ffcertificate' ); ?></td>
				<td>
					• <?php esc_html_e( 'Open Settings → Scheduled Tasks and look for tasks marked late', 'ffcertificate' ); ?><br>
					• <?php esc_html_e( 'WP-Cron only runs when the site gets visits — install the server cron line that tab generates', 'ffcertificate' ); ?>
				</td>
			</tr>
		</tbody>
	</table>

	<?php ob_start(); ?>
		<p>
			<?php esc_html_e( 'For additional support, check the plugin repository documentation or contact support.', 'ffcertificate' ); ?>
		</p>
	<?php
	\FreeFormCertificate\Core\AdminNotice::callout(
		'info',
		(string) ob_get_clean(),
		array(
			'title'   => __( 'Need More Help?', 'ffcertificate' ),
			'classes' => array( 'ffc-mt-2xl' ),
		)
	);
	?>
</div>

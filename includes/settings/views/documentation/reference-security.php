<?php
/**
 * Documentation partial — Section 14: Security Features.
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
<!-- 14. Security Features Section -->
<div class="card">
	<h3 id="reference-security"><span class="dashicons dashicons-lock" aria-hidden="true"></span> <?php esc_html_e( 'Security Features', 'ffcertificate' ); ?></h3>
	
	<ul class="ffc-doc-list">
		<li>
			<strong><?php esc_html_e( 'Single Password:', 'ffcertificate' ); ?></strong><br> 
			<?php esc_html_e( 'The form will have a global password for submission.', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Allowlist/Denylist:', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'Ensure that the listed IDs are allowed or blocked from retrieving certificates.', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Ticket (Unique Codes):', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'Require users to have a single-use ticket to generate the certificate (it is consumed after use).', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Rate Limiting:', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'Prevents abuse with configurable submission limits', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Data Encryption:', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'Encryption for sensitive data (LGPD compliant)', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Honeypot Fields:', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'Invisible spam protection', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Math CAPTCHA:', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'Basic humanity verification', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Geofencing (GPS + IP):', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'Restrict form access by GPS coordinates and/or IP geolocation, with configurable fallback behavior and admin bypass', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Sensitive Data Encryption:', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'CPF and RF fields are encrypted at rest using AES-256-CBC with encrypt-then-HMAC (LGPD compliant)', 'ffcertificate' ); ?>
		</li>
	</ul>
</div>

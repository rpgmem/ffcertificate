<?php
/**
 * Documentation partial — Section 13: Features.
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
<!-- 13. Features Section -->
<div class="card">
	<h3 id="features" class="ffc-icon-celebrate"><?php esc_html_e( '13. Features', 'ffcertificate' ); ?></h3>
	
	<ul class="ffc-doc-list">
		<li>
			<strong><?php esc_html_e( 'Unique Authentication Codes:', 'ffcertificate' ); ?></strong><br> 
			<?php esc_html_e( 'Every certificate gets a unique 12-character code (e.g., A1B2-C3D4-E5F6)', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'QR Code Validation:', 'ffcertificate' ); ?></strong><br> 
			<?php esc_html_e( 'Scan to instantly verify certificate authenticity', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Magic Links:', 'ffcertificate' ); ?></strong><br> 
			<?php esc_html_e( 'Links that don\'t pass validation on the website. Shared by email and quickly verifying the certificate\'s.', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Reprinting certificates:', 'ffcertificate' ); ?></strong><br> 
			<?php esc_html_e( 'Previously submitted identification information (CPF/RF) does not generate new certificates.', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'CSV Export:', 'ffcertificate' ); ?></strong><br> 
			<?php esc_html_e( 'Generate a CSV list with the submissions already sent.', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Email Notifications:', 'ffcertificate' ); ?></strong><br> 
			<?php esc_html_e( 'Automatic (or not) email sent with certificate PDF attached upon submission', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'PDF Customization:', 'ffcertificate' ); ?></strong><br> 
			<?php esc_html_e( 'Full HTML editor to design your own certificate layout', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Auto-delete:', 'ffcertificate' ); ?></strong><br> 
			<?php esc_html_e( 'Ensure submissions are deleted after "X" days.', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Date Format:', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'Format used for {{submission_date}} and {{print_date}} placeholders in PDFs and emails.', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Data Migrations:', 'ffcertificate' ); ?></strong><br> 
			<?php esc_html_e( 'Migration of all data from the plugin\'s old infrastructure.', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Form Cache:', 'ffcertificate' ); ?></strong><br> 
			<?php esc_html_e( 'The cache stores form settings to improve performance.', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Multi-language Support:', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'Supports Portuguese and English languages', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Audience Custom Fields:', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'Define custom data fields per audience group with validation (CPF, email, phone, regex)', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Reregistration Campaigns:', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'Collect updated information from audience members with configurable email notifications and approval workflow', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Ficha PDF:', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'Generate PDF records for reregistration submissions with custom template support', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Named Geofence Locations:', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'Define reusable named locations in Settings > Geolocation, then assign them to forms via dropdown instead of entering coordinates manually', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Public CSV Download:', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'Allow form organizers to download submission CSVs via a public page using a secure hash, gated by form expiration and per-form quota', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'CSV Download Page URL:', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'Configure the base URL in Settings > General so the form editor displays the full download link instead of just the query string', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Quiz / Evaluation Mode:', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'Score-based certificates with {{score}}, {{max_score}}, and {{score_percent}} template variables', 'ffcertificate' ); ?>
		</li>
	</ul>
</div>

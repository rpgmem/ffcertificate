<?php
/**
 * Documentation Tab
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="ffc-settings-wrap">

<!-- Main Documentation Card with intro -->
<div class="card">
	<h2 class="ffc-icon-doc"><?php esc_html_e( 'Complete Plugin Documentation', 'ffcertificate' ); ?></h2>
	<p><?php esc_html_e( 'This plugin allows you to create certificate issuance forms, generate PDFs automatically, and verify authenticity with QR codes.', 'ffcertificate' ); ?></p>
</div>

<!-- Sentinel: when this scrolls out of the viewport the TOC card below
	auto-collapses (handled by ffc-doc-toc.js + IntersectionObserver). -->
<div class="ffc-doc-toc-sentinel" aria-hidden="true"></div>

<!-- Table of Contents — sticky on scroll, collapses to a thin strip once
	the user has scrolled past its original position. -->
<div class="card ffc-doc-toc">
	<h3><?php esc_html_e( 'Quick Navigation', 'ffcertificate' ); ?></h3>
	<ul class="ffc-doc-toc-list">
			<li><a href="#shortcodes" class="ffc-icon-pin"><?php esc_html_e( '1. Shortcodes', 'ffcertificate' ); ?></a></li>
			<li><a href="#variables" class="ffc-icon-tag"><?php esc_html_e( '2. Template Variables', 'ffcertificate' ); ?></a></li>
			<li><a href="#quiz-variables" class="ffc-icon-tag"><?php esc_html_e( '3. Quiz / Evaluation Variables', 'ffcertificate' ); ?></a></li>
			<li><a href="#appointment-variables" class="ffc-icon-tag"><?php esc_html_e( '4. Appointment Receipt Variables', 'ffcertificate' ); ?></a></li>
			<li><a href="#qr-code" class="ffc-icon-phone"><?php esc_html_e( '5. QR Code Options', 'ffcertificate' ); ?></a></li>
			<li><a href="#validation-url" class="ffc-icon-link"><?php esc_html_e( '6. Validation URL', 'ffcertificate' ); ?></a></li>
			<li><a href="#html-styling" class="ffc-icon-palette"><?php esc_html_e( '7. HTML & Styling', 'ffcertificate' ); ?></a></li>
			<li><a href="#custom-fields" class="ffc-icon-edit"><?php esc_html_e( '8. Custom Fields', 'ffcertificate' ); ?></a></li>
			<li><a href="#audience-custom-fields" class="ffc-icon-user"><?php esc_html_e( '9. Audience Custom Fields', 'ffcertificate' ); ?></a></li>
			<li><a href="#reregistration" class="ffc-icon-note"><?php esc_html_e( '10. Reregistration', 'ffcertificate' ); ?></a></li>
			<li><a href="#ficha-pdf" class="ffc-icon-doc"><?php esc_html_e( '11. Ficha PDF', 'ffcertificate' ); ?></a></li>
			<li><a href="#geofence-locations" class="ffc-icon-globe"><?php esc_html_e( '12. Geofence Locations', 'ffcertificate' ); ?></a></li>
			<li><a href="#features" class="ffc-icon-celebrate"><?php esc_html_e( '13. Features', 'ffcertificate' ); ?></a></li>
			<li><a href="#security" class="ffc-icon-lock"><?php esc_html_e( '14. Security Features', 'ffcertificate' ); ?></a></li>
			<li><a href="#examples" class="ffc-icon-note"><?php esc_html_e( '15. Complete Examples', 'ffcertificate' ); ?></a></li>
			<li><a href="#url-shortener" class="ffc-icon-link"><?php esc_html_e( '16. URL Shortener & QR Codes', 'ffcertificate' ); ?></a></li>
			<li><a href="#hooks" class="ffc-icon-wrench"><?php esc_html_e( '17. Developer Hooks', 'ffcertificate' ); ?></a></li>
			<li><a href="#troubleshooting" class="ffc-icon-wrench"><?php esc_html_e( '18. Troubleshooting', 'ffcertificate' ); ?></a></li>
			<li><a href="#rest-api-auth" class="ffc-icon-lock"><?php esc_html_e( '19. REST API Authentication', 'ffcertificate' ); ?></a></li>
			<li><a href="#recruitment" class="ffc-icon-user"><?php esc_html_e( '20. Recruitment', 'ffcertificate' ); ?></a></li>
			<li><a href="#maintenance-tools" class="ffc-icon-wrench"><?php esc_html_e( '21. Maintenance Tools', 'ffcertificate' ); ?></a></li>
	</ul>
</div>

<?php require __DIR__ . '/documentation/01-shortcodes.php'; ?>

<?php require __DIR__ . '/documentation/02-variables.php'; ?>

<?php require __DIR__ . '/documentation/03-quiz-variables.php'; ?>

<?php require __DIR__ . '/documentation/04-appointment-variables.php'; ?>

<?php require __DIR__ . '/documentation/05-qr-code.php'; ?>

<?php require __DIR__ . '/documentation/06-validation-url.php'; ?>

<?php require __DIR__ . '/documentation/07-html-styling.php'; ?>

<?php require __DIR__ . '/documentation/08-custom-fields.php'; ?>

<?php require __DIR__ . '/documentation/09-audience-custom-fields.php'; ?>

<?php require __DIR__ . '/documentation/10-reregistration.php'; ?>

<?php require __DIR__ . '/documentation/11-ficha-pdf.php'; ?>

<?php require __DIR__ . '/documentation/12-geofence-locations.php'; ?>

<?php require __DIR__ . '/documentation/13-features.php'; ?>

<?php require __DIR__ . '/documentation/14-security.php'; ?>

<?php require __DIR__ . '/documentation/15-examples.php'; ?>

<?php require __DIR__ . '/documentation/16-url-shortener.php'; ?>

<?php require __DIR__ . '/documentation/17-hooks.php'; ?>

<?php require __DIR__ . '/documentation/18-troubleshooting.php'; ?>

<?php require __DIR__ . '/documentation/19-rest-api-auth.php'; ?>

<?php require __DIR__ . '/documentation/20-recruitment.php'; ?>

<?php require __DIR__ . '/documentation/21-maintenance-tools.php'; ?>

</div><!-- .ffc-settings-wrap -->
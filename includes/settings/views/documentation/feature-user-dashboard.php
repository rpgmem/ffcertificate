<?php
/**
 * Documentation partial — Feature: User Dashboard & Access.
 *
 * Documents the front-end personal dashboard ([user_dashboard_personal]) and
 * its access control — the reorganization from #674.
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- User Dashboard & Access Section -->
<div class="card">
	<h3 id="feature-user-dashboard" class="ffc-icon-user"><?php esc_html_e( 'User Dashboard & Access', 'ffcertificate' ); ?></h3>
	<p><?php esc_html_e( 'A front-end panel where each logged-in user sees their own data — issued certificates, appointments and profile — without any admin access.', 'ffcertificate' ); ?></p>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Adding the dashboard', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'Place the shortcode on any page (for example a "My Account" page):', 'ffcertificate' ); ?></p>
		<pre><code>[user_dashboard_personal]</code></pre>
		<p><?php esc_html_e( 'The page is excluded from full-page caching automatically, because it renders user-specific data that must never be served from a shared cache.', 'ffcertificate' ); ?></p>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'What the user sees', 'ffcertificate' ); ?></h4>
		<ul>
			<li><?php esc_html_e( 'Their own issued certificates, with download / verification links', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Their self-scheduling appointments (with receipt / cancel actions where allowed)', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'The reregistration banner and "Download Ficha" action when a reregistration applies to them', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'Their profile fields (identity, contact, address, employment) sourced from the WordPress user profile', 'ffcertificate' ); ?></li>
		</ul>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Access control', 'ffcertificate' ); ?></h4>
		<ul>
			<li><strong><?php esc_html_e( 'Must be logged in:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'the dashboard resolves data from the current user and shows nothing to anonymous visitors.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Own data only:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'records are matched to the current user (and their CPF/RF); a user never sees another user\'s data.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Roles & capabilities:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'admin surfaces are gated separately by FFC capabilities — see the Capabilities & Roles reference page.', 'ffcertificate' ); ?></li>
		</ul>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Profile custom fields', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'The identity/contact/address/employment fields shown on the dashboard live on the WordPress user profile and are mapped to the reregistration and audience data, so a user\'s details stay consistent across a certificate PDF, a ficha and their profile.', 'ffcertificate' ); ?></p>
	</div>
</div>

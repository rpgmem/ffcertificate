<?php
/**
 * Documentation partial — Overview.
 *
 * The landing page of the documentation tab: a short "what this plugin does"
 * introduction plus the headline capabilities, linking into the functional
 * tree. Rewritten for the functional reorganization (rpgmem/ffcertificate#697).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Overview Section -->
<div class="card">
	<h3 id="overview"><span class="dashicons dashicons-info" aria-hidden="true"></span> <?php esc_html_e( 'Overview', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'Free Form Certificate turns WordPress into a complete platform for issuing verifiable documents. Build a form, design the certificate once, and let people receive a signed PDF the moment they submit — validated by QR code or a public link, protected against fraud and duplicate issuance, and delivered by email automatically.', 'ffcertificate' ); ?></p>

	<p><?php esc_html_e( 'It is much more than certificates: the same foundation powers appointment scheduling, shared-space booking, reregistration campaigns, public-tender candidate queues and a click-tracking URL shortener — all under one roof, one design system and one email pipeline.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'What you can do', 'ffcertificate' ); ?></h4>
	<ul class="ffc-doc-list">
		<li>
			<strong><?php esc_html_e( 'Issue certificates from any form', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'Drag-build the fields, design the PDF in a full HTML editor with {{tokens}}, and issue automatically on submission — each with a unique authentication code.', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Prove authenticity instantly', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'Every certificate carries a QR code and a validation link; anyone can confirm it is genuine, and the holder gets a one-click "magic link" to re-download.', 'ffcertificate' ); ?></li>
		<li>
			<strong><?php esc_html_e( 'Stop fraud and duplicates', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'Single-use tickets, allow/deny lists, one-certificate-per-person reprints, per-device limits, rate limiting, and geofencing by GPS or IP.', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Schedule and book', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'Personal appointment calendars (fixed slots) and audience calendars for shared spaces (free-form bookings), each with confirmations, reminders and ICS invites.', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Run campaigns and tenders', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'Collect updated data with reregistration campaigns (and a Ficha PDF), or manage classified candidate queues with public rankings and call-ups.', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Protect personal data (LGPD)', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'CPF, RF and email are encrypted at rest and masked on screen unless a viewer holds a PII capability; a granular capability system gates every admin area.', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Send beautiful, reliable email', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'One configurable "Email Model" wraps every message, sent through a single SMTP-aware pipeline with a global kill-switch and multipart delivery.', 'ffcertificate' ); ?>
		</li>
		<li>
			<strong><?php esc_html_e( 'Extend it', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'A REST API, an authenticated Forms API and dozens of action/filter hooks let developers integrate and customize everything.', 'ffcertificate' ); ?>
		</li>
	</ul>

	<div class="ffc-doc-note">
		<p>
			<strong class="ffc-icon-info"><?php esc_html_e( 'Find your way around:', 'ffcertificate' ); ?></strong>
			<?php esc_html_e( 'Use the Quick Navigation on the left — it mirrors the plugin\'s own menus. Start with', 'ffcertificate' ); ?>
			<a href="#feature-certificates"><?php esc_html_e( 'Certificates & Forms', 'ffcertificate' ); ?></a>, <?php esc_html_e( 'or jump to any area (Scheduling, Reregistration, Recruitment, Short URLs, Developer).', 'ffcertificate' ); ?>
		</p>
	</div>
</div>

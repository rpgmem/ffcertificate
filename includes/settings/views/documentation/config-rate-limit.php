<?php
/**
 * Documentation partial — Configuration: Rate Limit.
 *
 * The Settings → Rate Limit tab: throttling dimensions, device fingerprint,
 * allow/blocklists, logging, interface and statistics. Part of the functional
 * reorganization (rpgmem/ffcertificate#697).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Configuration: Rate Limit Section -->
<div class="card">
	<h3 id="config-rate-limit"><span class="dashicons dashicons-shield-alt" aria-hidden="true"></span> <?php esc_html_e( 'Rate Limit', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'Settings → Rate Limit throttles abusive traffic across several independent dimensions. Every dimension has its own on/off switch, thresholds and block message. Requests are evaluated in this order: blocklist → allowlist → device → global → IP → email → CPF/RF.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Throttling dimensions', 'ffcertificate' ); ?></h4>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Dimension', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'What it limits', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Shipped defaults', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr><td><strong><?php esc_html_e( 'IP', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Submissions per client IP, plus a cooldown between attempts.', 'ffcertificate' ); ?></td><td><?php esc_html_e( '5/hour, 20/day, 60s cooldown', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Email', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Certificates per email address over time.', 'ffcertificate' ); ?></td><td><?php esc_html_e( '3/day, 10/week, 30/month', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'CPF/RF', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Certificates per identifier, plus an abuse block after too many attempts in an hour.', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'off; 5/month, 50/year when on', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Global', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'A site-wide ceiling regardless of who is submitting.', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'off; 100/minute, 1000/hour when on', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Read (GET) / calendars', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Public calendar API reads (slots, list, detail) — protects the booking endpoints from scraping. Logged-in users and allowlisted IPs bypass.', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'per-endpoint per-minute/hour', 'ffcertificate' ); ?></td></tr>
		</tbody>
	</table>
	<p class="description"><?php esc_html_e( 'A blocked submitter sees the dimension\'s message; a blocked API read gets HTTP 429 with Retry-After.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Device fingerprint', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'A privacy-respecting browser/device fingerprint caps submissions from the same device (off by default). It combines a cookie with a set of non-cookie signals (screen, timezone, canvas, WebGL, audio, fonts, …). A device matches when the cookie matches, or when enough signals match — the match threshold — with a minimum number of "strong" signals to corroborate.', 'ffcertificate' ); ?></p>
	<ul>
		<li><strong><?php esc_html_e( 'Max per form', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'submissions allowed from one device per form (default 1).', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Match threshold', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'how many signals must match (default 7).', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Strong-signal minimum', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'how many of those must be strong signals (default 2); 0 disables the strong tier.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Manager bypass', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'administrators / settings-managers are skipped.', 'ffcertificate' ); ?></li>
	</ul>
	<p class="description"><?php esc_html_e( 'Forms can override these values in the form editor — empty per-form values inherit these globals.', 'ffcertificate' ); ?> <a href="#reference-security"><?php esc_html_e( 'See Security & Restrictions.', 'ffcertificate' ); ?></a></p>

	<h4><?php esc_html_e( 'Allowlist & blocklist', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Static lists that bypass or hard-block requests, each matching by IP, email, email domain (format *@domain.com) or CPF/RF. The allowlist wins immediately; the blocklist is checked first and denies outright.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Log, interface & statistics', 'ffcertificate' ); ?></h4>
	<ul>
		<li><strong><?php esc_html_e( 'Log', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'blocked (and optionally allowed) events, with a retention window and a row cap. IPs are stored in the clear; every other identifier is hashed for privacy.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Interface', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'toggles for showing remaining attempts, the wait time, and a countdown timer to the visitor.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Statistics', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'blocked today, blocked in the last 30 days, a breakdown by type, and the top blocked IPs.', 'ffcertificate' ); ?></li>
	</ul>
</div>

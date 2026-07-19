<?php
/**
 * Documentation partial — Configuration: Geolocation.
 *
 * The Settings → Geolocation tab: the named-locations registry, the IP
 * geolocation API, caching, fallback behavior and admin bypass. Part of the
 * functional reorganization (rpgmem/ffcertificate#697).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Configuration: Geolocation Section -->
<div class="card">
	<h3 id="config-geolocation"><span class="dashicons dashicons-location-alt" aria-hidden="true"></span> <?php esc_html_e( 'Geolocation', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'Settings → Geolocation is where the geofencing building blocks are configured. Individual forms then pick from these.', 'ffcertificate' ); ?> <a href="#forms-geolocation"><?php esc_html_e( 'See Geolocation (Forms)', 'ffcertificate' ); ?></a> <?php esc_html_e( 'for per-form usage.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Named-location registry', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Create reusable locations shared by every form. Each has a name, latitude, longitude, radius (meters) and optional "default for GPS" / "default for IP" flags — a new form auto-selects the defaults. Editing a location updates every form that references it.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'IP geolocation API', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Backend IP validation needs an external lookup service. It is off by default; the per-form IP toggle stays disabled until you enable it here.', 'ffcertificate' ); ?></p>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Setting', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Notes', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr><td><strong><?php esc_html_e( 'Primary service', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'ip-api.com (free, ~45 requests/min, no key) or ipinfo.io (needs an API key).', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Service cascade', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'If the primary provider fails, try the other one.', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'IP cache + TTL', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Cache lookups (TTL 300–3600s) to save API calls.', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'GPS cache TTL', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'How long the browser remembers a GPS fix (60–3600s, default 600).', 'ffcertificate' ); ?></td></tr>
		</tbody>
	</table>

	<h4><?php esc_html_e( 'Fallback behavior', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Decide what happens when location cannot be determined:', 'ffcertificate' ); ?></p>
	<ul>
		<li><strong><?php esc_html_e( 'When GPS fails', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'a preset (tolerant / hybrid / strict) or a custom per-case allow/block matrix (permission denied, no API, position unavailable, timeout, safety timer).', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'When the IP API fails', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'allow, block, or fall back to GPS only.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'When both fail', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'allow or block (default block).', 'ffcertificate' ); ?></li>
	</ul>

	<h4><?php esc_html_e( 'Administrator bypass', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Optionally let administrators ignore the date/time restriction and/or the geolocation restriction, so they can test forms from anywhere at any time.', 'ffcertificate' ); ?></p>
</div>

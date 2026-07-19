<?php
/**
 * Documentation partial — Forms: Geolocation / Geofence.
 *
 * Restricting where a form may be submitted (GPS + IP), and the reusable
 * named-locations registry. Part of the functional reorganization
 * (rpgmem/ffcertificate#697).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Forms: Geolocation Section -->
<div class="card">
	<h3 id="forms-geolocation"><span class="dashicons dashicons-location" aria-hidden="true"></span> <?php esc_html_e( 'Geolocation / Geofence', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'Geofencing restricts where a form may be submitted. It is configured in the form editor\'s Geofence box, "Geolocation" tab, and can validate by GPS position, by IP geolocation, or both.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'GPS vs IP', 'ffcertificate' ); ?></h4>
	<ul>
		<li><strong><?php esc_html_e( 'GPS:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'precise; the browser asks the visitor to share their location.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'IP:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'coarse (roughly 1–50 km) — use a larger radius. IP validation requires the global geolocation API to be enabled (Settings → Geolocation); the per-form IP toggle stays disabled until then.', 'ffcertificate' ); ?></li>
	</ul>
	<p><?php esc_html_e( 'When both are enabled you choose whether the visitor must pass GPS AND IP, or either one (OR). Blocked visitors see a configurable message, the title + message, or the form is hidden.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Area source: registered locations vs custom coordinates', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'For each method you pick where the allowed area comes from:', 'ffcertificate' ); ?></p>
	<ul>
		<li><strong><?php esc_html_e( 'Registered Locations:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'choose one or more named locations from the shared registry; the coordinates are resolved at runtime.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Custom Coordinates:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'type areas by hand, one "latitude, longitude, radius(m)" per line.', 'ffcertificate' ); ?></li>
	</ul>

	<h4><?php esc_html_e( 'The named-locations registry', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Reusable locations are managed under Settings → Geolocation and shared by every form. Each has a name, latitude/longitude, radius (meters) and optional "default for GPS" / "default for IP" flags — a new form auto-selects the defaults. Editing a location updates every form that references it.', 'ffcertificate' ); ?></p>

	<div class="ffc-doc-note">
		<p>
			<strong class="ffc-icon-info"><?php esc_html_e( 'Admin bypass.', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'Geofencing can be set to ignore administrators so they can test a form from anywhere (Settings → Geofence).', 'ffcertificate' ); ?>
		</p>
	</div>
</div>

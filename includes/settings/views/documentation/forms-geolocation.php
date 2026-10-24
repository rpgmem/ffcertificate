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
	<h3 id="forms-geolocation" class="ffc-icon-map-pin"><?php esc_html_e( 'Geolocation / Geofence', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'Geofencing restricts where a form may be submitted. It is configured on the Geolocation tab of the form editor\'s "Certificate Form Configuration" box, and can validate by GPS position, by IP geolocation, or both.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'GPS vs IP', 'ffcertificate' ); ?></h4>
	<ul>
		<li><strong><?php esc_html_e( 'GPS:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'precise; the browser asks the visitor to share their location.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'IP:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'coarse (roughly 1–50 km) — use a larger radius. IP validation requires the global geolocation API to be enabled (Settings → Geolocation); the per-form IP toggle stays disabled until then.', 'ffcertificate' ); ?></li>
	</ul>
	<p><?php esc_html_e( 'When both are enabled you choose whether the visitor must pass GPS AND IP, or either one (OR, the default). Blocked visitors see a configurable message, the title + description + message, or the form is hidden. A separate Error Message is shown when the location cannot be determined (e.g. GPS permission denied).', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'The browser checks the location first, to say so before the visitor fills the form, and the server decides when the form is sent: the browser sends the GPS reading with the submission, and the server checks it against the areas and combines it with the IP the way the form asks. A reading whose margin of error is larger than the smallest area, or older than the GPS cache lifetime, cannot tell inside from outside and is treated as "Location unavailable" in the "When GPS fails" policy; a submission that arrives without a reading follows "Browser does not support geolocation". With "GPS or IP", a visitor whose GPS is inside passes even when their IP resolves elsewhere, which mobile networks often do.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'The location is the last of the checks a form runs before it is shown, after the date window, the secure connection, the browser and the cookies; see Certificates & Forms → Checks before the form. A page served over plain HTTP cannot ask for the location, and an app\'s own browser often cannot either, which is why those two are checked first.', 'ffcertificate' ); ?></p>

	<p><?php esc_html_e( 'The visitor\'s position is used to decide and then discarded: it is not stored with the submission and is not written to any log. With geolocation debugging on, the debug entries say which area matched, never where the visitor was. Entries written before 6.42.0 did record the position; the "Activity Log: Delete Logged Locations" migration removes them.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Area source: registered locations vs custom coordinates', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'You pick where the allowed GPS area comes from; IP validation reuses the GPS areas unless "Use different areas for IP validation" is checked, in which case IP gets its own source:', 'ffcertificate' ); ?></p>
	<ul>
		<li><strong><?php esc_html_e( 'Registered Locations:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'choose one or more named locations from the shared registry; the coordinates are resolved at runtime.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Custom Coordinates:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'type areas by hand, one "latitude, longitude, radius(m)" per line.', 'ffcertificate' ); ?></li>
	</ul>

	<h4><?php esc_html_e( 'The named-locations registry', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Reusable locations are managed under Settings → Geolocation and shared by every form. Each has a name, latitude/longitude, radius (meters) and optional "default for GPS" / "default for IP" flags — a new form auto-selects the defaults. Editing a location updates every form that references it.', 'ffcertificate' ); ?></p>

	<?php ob_start(); ?>
		<p>
			<?php esc_html_e( 'Geofencing can be set to ignore administrators so they can test a form from anywhere (Settings → Geolocation).', 'ffcertificate' ); ?>
		</p>
	<?php
	\FreeFormCertificate\Core\AdminNotice::callout(
		'info',
		(string) ob_get_clean(),
		array( 'title' => __( 'Admin bypass.', 'ffcertificate' ) )
	);
	?>
</div>

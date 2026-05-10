<?php
/**
 * Documentation partial — Section 19: REST API Authentication.
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
<!-- 19. REST API Authentication Section -->
<div class="card">
	<h3 id="rest-api-auth" class="ffc-icon-lock"><?php esc_html_e( '19. REST API Authentication', 'ffcertificate' ); ?></h3>

	<p>
		<?php esc_html_e( 'External integrations call the FFC REST API under the namespace', 'ffcertificate' ); ?>
		<code>/wp-json/ffc/v1/</code>.
		<?php esc_html_e( 'Endpoints fall into two categories:', 'ffcertificate' ); ?>
	</p>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Public-by-design endpoints', 'ffcertificate' ); ?></h4>
		<p>
			<?php esc_html_e( 'Anonymous-by-contract because they serve the plugin\'s public flows: the form-submission shortcode, the certificate-verification page, and the booking shortcode. They do not accept authentication and are protected by rate limiting, geofence rules, hash_equals comparisons on tokens, and (for submissions) email + CPF/RF format validation.', 'ffcertificate' ); ?>
		</p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Method', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Endpoint', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Purpose', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><code>GET</code></td>
					<td><code>/forms/{id}/schema</code></td>
					<td><?php esc_html_e( 'Lightweight form metadata for renderer integrations.', 'ffcertificate' ); ?></td>
				</tr>
				<tr>
					<td><code>POST</code></td>
					<td><code>/forms/{id}/submit</code></td>
					<td><?php esc_html_e( 'Anonymous certificate submission.', 'ffcertificate' ); ?></td>
				</tr>
				<tr>
					<td><code>POST</code></td>
					<td><code>/verify</code></td>
					<td><?php esc_html_e( 'Verify a certificate by auth code.', 'ffcertificate' ); ?></td>
				</tr>
				<tr>
					<td><code>GET</code></td>
					<td><code>/calendars</code>, <code>/calendars/{id}</code>, <code>/calendars/{id}/slots</code></td>
					<td><?php esc_html_e( 'Public booking calendars and available slots.', 'ffcertificate' ); ?></td>
				</tr>
				<tr>
					<td><code>POST</code></td>
					<td><code>/calendars/{id}/appointments</code></td>
					<td><?php esc_html_e( 'Anonymous booking creation.', 'ffcertificate' ); ?></td>
				</tr>
			</tbody>
		</table>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Authenticated endpoints', 'ffcertificate' ); ?></h4>
		<p>
			<?php esc_html_e( 'Require a logged-in WordPress user with a specific FFC capability. Locked down in v6.4.1 to plug a config-blob leak. Use one of:', 'ffcertificate' ); ?>
		</p>
		<ul>
			<li>
				<strong><?php esc_html_e( 'Application Passwords (recommended).', 'ffcertificate' ); ?></strong>
				<?php esc_html_e( 'Built into WordPress since 5.6. Edit the integrator\'s user profile, scroll to "Application Passwords", create one named e.g. "FFC API", and use the resulting token with HTTP Basic Auth (username + the generated password). The integrator\'s user must hold the capability listed below.', 'ffcertificate' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Logged-in cookie (same-origin).', 'ffcertificate' ); ?></strong>
				<?php esc_html_e( 'When called from the WordPress admin or front-end while logged in, the request is authenticated automatically. The user must hold the capability.', 'ffcertificate' ); ?>
			</li>
		</ul>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Method', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Endpoint', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Required capability', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Returns', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><code>GET</code></td>
					<td><code>/forms</code></td>
					<td><code>ffc_read_forms_api</code></td>
					<td><?php esc_html_e( 'List of published forms (id, title, status, dates, link). Capped at 100 per request.', 'ffcertificate' ); ?></td>
				</tr>
				<tr>
					<td><code>GET</code></td>
					<td><code>/forms/{id}</code></td>
					<td><code>ffc_read_forms_api</code></td>
					<td><?php esc_html_e( 'Single form metadata (id, title, status, dates, link). For form structure use /forms/{id}/schema.', 'ffcertificate' ); ?></td>
				</tr>
			</tbody>
		</table>
		<p>
			<strong><?php esc_html_e( 'Granting the capability:', 'ffcertificate' ); ?></strong>
			<?php esc_html_e( 'Granted automatically to the administrator role on every plugin upgrade. Delegate to other roles (or specific users) via your favourite capability-management plugin or the user-edit screen.', 'ffcertificate' ); ?>
		</p>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Example: list forms with curl', 'ffcertificate' ); ?></h4>
		<pre><code>curl -u USERNAME:APP_PASSWORD https://your-site.com/wp-json/ffc/v1/forms?limit=20</code></pre>
		<p>
			<?php esc_html_e( 'Replace USERNAME with the WordPress login of the integrator user, and APP_PASSWORD with the token created from the user\'s "Application Passwords" panel. The user must hold ffc_read_forms_api.', 'ffcertificate' ); ?>
		</p>
	</div>

	<div class="ffc-alert ffc-alert-info">
		<p>
			<strong class="ffc-icon-info"><?php esc_html_e( 'Why was the previous /forms unauthenticated?', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'It was — and the response embedded the full _ffc_form_config blob, which on a typical install contains allowed/denied user lists, validation codes, generated codes, geofence configuration, and email templates. v6.4.1 closes that hole; the trimmed payload is now safe by construction (id, title, status, dates, link only).', 'ffcertificate' ); ?>
		</p>
	</div>
</div>

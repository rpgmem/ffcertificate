<?php
/**
 * Documentation partial — Feature: Document Templates hub.
 *
 * The certificate template pool: one place to create, edit and duplicate the
 * PDF layouts shared by certificates, the reregistration ficha and the
 * appointment receipt, plus how each feature selects a pool template
 * (#865/#945/#951).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Document Templates hub Section -->
<div class="card">
	<h3 id="document-templates-hub"><span class="dashicons dashicons-media-document" aria-hidden="true"></span> <?php esc_html_e( 'Document Templates hub', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'Settings → Document Templates is the single library of PDF layouts. Every document the plugin renders — a certificate, a reregistration ficha, an appointment receipt — draws from this shared pool instead of a hardcoded or bundled file, so a layout is built or duplicated once and reused wherever it fits.', 'ffcertificate' ); ?></p>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'One pool, several document kinds', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'Each template carries a kind that marks what it is for. A Category column and filter in the hub let you find templates of one kind quickly:', 'ffcertificate' ); ?></p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Kind', 'ffcertificate' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Used by', 'ffcertificate' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><strong><?php esc_html_e( 'Certificate', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'The certificate PDF a form issues on submission (the default kind).', 'ffcertificate' ); ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Appointment receipt', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'The self-scheduling comprovante. Two defaults ship — Regular and Custom.', 'ffcertificate' ); ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Ficha', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'The reregistration ficha PDF.', 'ffcertificate' ); ?></td></tr>
			</tbody>
		</table>
		<p><?php esc_html_e( 'Templates are edited in a code editor (HTML + {{placeholders}}), can be duplicated as a starting point, and each kind ships a default that seeds automatically — so nothing needs configuring for the plugin to render documents out of the box.', 'ffcertificate' ); ?></p>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Where each feature selects its template', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'The hub holds the templates; each feature keeps its own selection (falling back to the shipped default when unset):', 'ffcertificate' ); ?></p>
		<ul>
			<li><strong><?php esc_html_e( 'Certificate:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'chosen per form in the form editor.', 'ffcertificate' ); ?> <a href="#feature-certificates"><?php esc_html_e( 'See Certificates & Forms.', 'ffcertificate' ); ?></a></li>
			<li><strong><?php esc_html_e( 'Appointment receipt:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'chosen globally per scheduling mode on Scheduling → Settings → Receipt.', 'ffcertificate' ); ?> <a href="#feature-self-scheduling"><?php esc_html_e( 'See Personal Calendars.', 'ffcertificate' ); ?></a></li>
			<li><strong><?php esc_html_e( 'Ficha:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'chosen globally on the Reregistration settings tab.', 'ffcertificate' ); ?> <a href="#feature-ficha"><?php esc_html_e( 'See Ficha PDF.', 'ffcertificate' ); ?></a></li>
		</ul>
		<p><?php esc_html_e( 'A read-only "Current assignments" overview at the top of the hub shows, at a glance, which pool template each feature is using right now, with a "Change →" link into that feature\'s own settings. The overview is display-only — the selection controls (and their capabilities) stay per-feature.', 'ffcertificate' ); ?></p>
	</div>

	<div class="ffc-doc-note">
		<p>
			<strong class="ffc-icon-info"><?php esc_html_e( 'No migration, fully backward compatible.', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'A template with no kind marker is treated as a certificate, so installs that predate the pool render exactly as before. Developer filters still let code override the resolved HTML for the ficha and receipt where a bundled layout was used previously.', 'ffcertificate' ); ?>
		</p>
	</div>
</div>

<?php
/**
 * Documentation partial — Feature: Document Templates hub.
 *
 * The certificate template pool: one place to create, edit and duplicate the
 * PDF layouts shared by certificates, the reregistration record and the
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
	<h3 id="document-templates-hub" class="ffc-icon-file"><?php esc_html_e( 'Document Templates hub', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'Settings → Document Templates is the single library of PDF layouts. Every document the plugin renders — a certificate, a reregistration record, an appointment receipt — draws from this shared pool instead of a hardcoded or bundled file, so a layout is built or duplicated once and reused wherever it fits.', 'ffcertificate' ); ?></p>

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
				<tr><td><strong><?php esc_html_e( 'Certificate', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'The certificate PDF a form issues on submission (the default kind). Six ready-made certificate models ship.', 'ffcertificate' ); ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Appointment receipt', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'The Personal Calendars appointment receipt. Two defaults ship — Regular and Custom.', 'ffcertificate' ); ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Record', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'The reregistration record PDF.', 'ffcertificate' ); ?></td></tr>
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
			<li><strong><?php esc_html_e( 'Record:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'chosen globally on the Reregistration settings tab.', 'ffcertificate' ); ?> <a href="#feature-record"><?php esc_html_e( 'See Record PDF.', 'ffcertificate' ); ?></a></li>
		</ul>
		<p><?php esc_html_e( 'Loading a certificate model copies its HTML into the form, so when a release improves a default, forms that copied an earlier version keep it: certificates are regenerated from the form\'s layout, and changing it silently would change certificates already issued. The form\'s layout box says so instead, when the layout is an unedited copy of an earlier version, with an Update layout button that loads the current version into the editor. Nothing changes until the form is saved; an edited copy is never offered one.', 'ffcertificate' ); ?></p>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Copy or reference a certificate template', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'The Layout tab of a form chooses its Layout source. Own layout is the copy described above. Template makes the form print a certificate template from the pool, with its background: editing that template changes every form that references it, and their certificates already issued, the next time they are generated or verified. The template\'s edit screen says how many forms and certificates that is before you save.', 'ffcertificate' ); ?></p>
		<p><?php esc_html_e( 'A template can declare fields that each referencing form fills — a place, the description of the activity, a date. Declare them in the template\'s Template fields box (key, label, short text, long text or date, required, default) and print them as {{model:key}}. On the form they appear under the chosen template; an empty field prints the default, and a required field left empty saves with a warning. Removing a field leaves the values forms filled stored and unused.', 'ffcertificate' ); ?></p>
		<p><?php esc_html_e( 'Where it is stored: the form keeps the template\'s id and the values it filled in its own post meta, and keeps its own copy untouched, so switching back to Own layout, or a template sent to the bin, prints that copy. Shipped default templates can be referenced too; they declare no fields.', 'ffcertificate' ); ?></p>
		<p><?php esc_html_e( 'Many forms carrying copies of one layout can be moved onto a shared template at once. Copy one form\'s layout into a new certificate template, put a {{model:key}} token where the forms differ (the place, the action) and the {{signer:…}} tokens where the signer is typed, declare the fields and add the signers under Certificates → Signers. The "Move Copied Layouts onto a Shared Template" card under Settings → Data Migrations then fills each form\'s fields with what it typed in place of each token and switches it, but only when its certificate prints exactly what it printed before; it previews each form first and lists the rest with the reason. Several families of copies take one template each.', 'ffcertificate' ); ?></p>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Visual certificate templates', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'A new certificate template chooses its editor: HTML, or Visual. A visual template is a list of elements placed on the page; the certificate HTML is generated from it on every save and goes through the same capture as any template, so forms reference it, preview it and print it the same way. Until the page editor arrives, the layout is edited as JSON in the template\'s Visual layout field, and a layout that does not check out is not saved and says why.', 'ffcertificate' ); ?></p>
		<p><?php esc_html_e( 'The format: {"version": 1, "page": {"orientation": "landscape"}, "elements": [ … ]}. Positions and sizes are page pixels — 1123 × 794 landscape, 794 × 1123 portrait — as x, y, w and h, with rotation in degrees and locked. The list order is the stacking order, the first element at the back; the background image and the page colour sit beneath every element.', 'ffcertificate' ); ?></p>
		<ul>
			<li><?php esc_html_e( 'text — literal text, tokens allowed, with font_family, font_size, font_weight, font_style, color, align, letter_spacing, line_height, text_transform and fit (none, line or box: the text shrinks to fit the width, or the box).', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'field — one token, as {{name}} or {{model:place}}, with the same text properties.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'image — attachment_id, a Media Library image, or token {{signer:signature}}; fit contain or cover, drawn as an explicit size because the capture ignores object-fit.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'qr — the validation QR code, sized to the shorter side of its box; margin.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'frame — border_width, border_style, border_color, radius and fill; the border is drawn inside the box.', 'ffcertificate' ); ?></li>
			<li><?php esc_html_e( 'html — a block of HTML, filtered by the same rules as an HTML template.', 'ffcertificate' ); ?></li>
		</ul>
		<p><?php esc_html_e( 'An out-of-range number is brought into range, an unknown value takes its default and an unknown key is dropped; text is printed as text. Duplicating a visual template keeps it visual. Only certificates can be visual; the record and the receipts stay HTML.', 'ffcertificate' ); ?></p>
	</div>

	<div class="ffc-doc-example">
		<p><?php esc_html_e( 'A read-only "Current assignments" overview on the Document Templates tab shows which pool template the appointment receipts (Regular and Custom) and the reregistration record are using right now, with a "Change" link into that feature\'s own settings. Each row appears only to users who can view that feature, and certificates have no row because each form chooses its own. The overview is display-only — the selection controls (and their capabilities) stay per-feature.', 'ffcertificate' ); ?></p>
	</div>

	<?php ob_start(); ?>
		<p>
			<?php esc_html_e( 'A template with no kind marker is treated as a certificate, so installs that predate the pool render exactly as before. Developer filters still let code override the resolved HTML for the record and receipt where a bundled layout was used previously.', 'ffcertificate' ); ?>
		</p>
	<?php
	\FreeFormCertificate\Core\AdminNotice::callout(
		'info',
		(string) ob_get_clean(),
		array( 'title' => __( 'No migration, fully backward compatible.', 'ffcertificate' ) )
	);
	?>
</div>

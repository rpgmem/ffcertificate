<?php
/**
 * Documentation partial — Feature: Email texts hub.
 *
 * The Settings → Email texts tab: the single place to edit every plugin email's
 * default subject + body, the Global/Custom override model, the shared standard
 * for subjects and bodies, and the "All plugin emails" directory (#964/#965/#976).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Email texts hub Section -->
<div class="card">
	<h3 id="email-texts-hub"><span class="dashicons dashicons-email" aria-hidden="true"></span> <?php esc_html_e( 'Email texts hub', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'Settings → Email texts is where you edit the wording of every plugin email in one place. It edits the message body and subject only — the shared header/footer chrome is the separate Email Model tab, and the transport is the SMTP tab.', 'ffcertificate' ); ?> <a href="#reference-emails"><?php esc_html_e( 'See Emails & Delivery for the whole pipeline.', 'ffcertificate' ); ?></a></p>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'One email at a time', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'A feature-grouped selector lists every editable email; pick one and only that email\'s editor opens (its rich editor is created on demand, so the page stays light even though many emails exist). Each editor has its own {{token}} help and a "Restore Default Text" button. One "Save email texts" button persists them all — you do not save per email.', 'ffcertificate' ); ?></p>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Global default vs. per-entity override', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'What you edit here is the GLOBAL default for each email. A subject + body left equal to the shipped default clears the stored override, so the email keeps tracking the built-in default and picks up future improvements automatically. Some emails can additionally be overridden closer to their source:', 'ffcertificate' ); ?></p>
		<ul>
			<li><strong><?php esc_html_e( 'Certificate email:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'each form can switch to Custom and carry its own text (Forms → Email); a form left on Global follows the hub.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Self-scheduling confirmation:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'a calendar can keep its own confirmation body; "Restore Default Text" there restores the effective global.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Audience booking / cancellation:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'a schedule\'s own custom body still overrides the global.', 'ffcertificate' ); ?></li>
		</ul>
		<div class="ffc-doc-note">
			<p>
				<strong class="ffc-icon-info"><?php esc_html_e( 'Heads up after an upgrade.', 'ffcertificate' ); ?></strong><br>
				<?php esc_html_e( 'When a shipped default text changes, a per-form / per-schedule / per-calendar body that still holds the OLD default is detected as Custom and keeps its old wording until you press "Restore Default Text"; blank (Global-tracking) entities render the new standard automatically.', 'ffcertificate' ); ?>
			</p>
		</div>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'The shared standard', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'All default emails follow one skeleton so the communication reads as one voice:', 'ffcertificate' ); ?></p>
		<ul>
			<li><strong><?php esc_html_e( 'Subject:', 'ffcertificate' ); ?></strong> <code><?php echo esc_html__( 'Event: {{reference}}', 'ffcertificate' ); ?></code> — <?php esc_html_e( 'a short event title, a colon, then the record it refers to (no bracketed site-name prefix, no trailing dashes).', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Body:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'an event-title heading in a semantic colour, a greeting, one context line, and a details box.', 'ffcertificate' ); ?></li>
		</ul>
		<p><?php esc_html_e( 'The semantic colours are:', 'ffcertificate' ); ?> <?php esc_html_e( 'green = confirmed / approved, red = cancelled, amber = reminder, purple = waitlist, blue = informational.', 'ffcertificate' ); ?></p>
	</div>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( '"All plugin emails" directory', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'A read-only index at the bottom of the tab lists every email the plugin can send, grouped by feature, each with a one-line purpose, a personalisation state (Editable text / On-off only / System default) and an "Open →" deep-link to where it is configured. It is discoverability only — it moves no controls.', 'ffcertificate' ); ?></p>
	</div>

	<div class="ffc-doc-note">
		<p>
			<strong class="ffc-icon-info"><?php esc_html_e( 'Who can edit here.', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'The Email texts hub is gated by the dedicated ffc_manage_email_templates capability, so editing email copy can be delegated to — or withheld from — a role independently of the blanket Settings capability. Without it the tab is hidden entirely.', 'ffcertificate' ); ?>
		</p>
	</div>
</div>

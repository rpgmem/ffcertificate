<?php
/**
 * Documentation partial — Forms: Email (per-form).
 *
 * The form's own Email tab (user + admin notifications) and how it plugs into
 * the shared email pipeline. Part of the functional reorganization
 * (rpgmem/ffcertificate#697).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Forms: Email Section -->
<div class="card">
	<h3 id="forms-email"><span class="dashicons dashicons-email-alt" aria-hidden="true"></span> <?php esc_html_e( 'Email (per-form)', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'Each form has its own Email box controlling the message sent to the participant on submission, plus an optional admin notification.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Participant email', 'ffcertificate' ); ?></h4>
	<ul>
		<li><strong><?php esc_html_e( 'Send email to user:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'toggle the participant email on/off.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Email content — Global / Custom:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'a switch that decides where this form\'s certificate email text comes from.', 'ffcertificate' ); ?>
			<ul>
				<li><strong><?php esc_html_e( 'Global (default):', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'the form follows the shared certificate text edited once in Settings → Email texts. The per-form subject/body fields stay hidden behind a short note linking there — change the wording in one place and every Global form updates.', 'ffcertificate' ); ?></li>
				<li><strong><?php esc_html_e( 'Custom:', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'reveals the per-form subject/body fields (pre-seeded with the current global text so you edit from real content) so this one form can differ. Switching back to Global clears the per-form copy — behind a confirmation — so the form tracks the global again.', 'ffcertificate' ); ?></li>
			</ul>
		</li>
		<li><strong><?php esc_html_e( 'Subject & body (Custom mode):', 'ffcertificate' ); ?></strong> <?php esc_html_e( 'edited in a visual editor. "Restore Default Text" restores the current global default.', 'ffcertificate' ); ?></li>
	</ul>
	<p><?php esc_html_e( 'Tokens available in the body:', 'ffcertificate' ); ?> <code>{{name}}</code>, <code>{{form_title}}</code>, <code>{{auth_code}}</code>, <code>{{date}}</code>, <?php esc_html_e( 'plus the validation-URL link DSL — e.g.', 'ffcertificate' ); ?> <code>{{validation_url link:m&gt;"Download (PDF)"}}</code> <?php esc_html_e( '(magic download link) and', 'ffcertificate' ); ?> <code>{{validation_url link:v&gt;v}}</code> <?php esc_html_e( '(public validation page). See', 'ffcertificate' ); ?> <a href="#reference-validation-url"><?php esc_html_e( 'Validation URL', 'ffcertificate' ); ?></a>.</p>

	<h4><?php esc_html_e( 'Admin notification', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Optionally notify one or more admin addresses on each submission (comma-separated; blank falls back to the site admin email).', 'ffcertificate' ); ?></p>

	<div class="ffc-doc-note">
		<p>
			<strong class="ffc-icon-info"><?php esc_html_e( 'You edit the body only.', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'At send time the message is wrapped in the shared, admin-configurable chrome (the "Email Model") and delivered through the one email pipeline — the same one every plugin email uses. Enabling the participant email adds it to a waiting list that is sent progressively, not instantly. See', 'ffcertificate' ); ?> <a href="#reference-emails"><?php esc_html_e( 'Emails & Delivery', 'ffcertificate' ); ?></a>.
		</p>
	</div>
</div>

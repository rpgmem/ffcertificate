<?php
/**
 * Documentation partial — Recruitment.
 *
 * Public-tender candidate queues: notices, adjutancies, reasons, candidates,
 * public shortcodes, settings and capabilities. Reviewed against the
 * recruitment module for the functional reorganization
 * (rpgmem/ffcertificate#697).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Recruitment Section -->
<div class="card">
	<h3 id="feature-recruitment" class="ffc-icon-users"><?php esc_html_e( 'Recruitment', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'The Recruitment module manages public-tender candidate queues: import classified candidates, publish the ranking, and record call-ups (convocations). It lives under the top-level "Recruitment" admin menu.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Admin tabs', 'ffcertificate' ); ?></h4>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Tab', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Purpose', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr><td><?php esc_html_e( 'Notices', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Create and edit notices (editais). Each notice has a unique code, a lifecycle status, and a configurable set of public columns.', 'ffcertificate' ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Adjutancies', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Manage adjutancies — the named units (with a color) attached to a notice to organize and filter its candidates.', 'ffcertificate' ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Candidates', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Look up candidates by CPF/RF, import them (CSV), and record call-ups and outcomes.', 'ffcertificate' ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Reasons', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Manage the reusable reason labels for the preliminary list (e.g. granted / denied / appeal), and which preliminary statuses each applies to.', 'ffcertificate' ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Settings', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Module-wide options: convocation email, public-queue tuning and status colors.', 'ffcertificate' ); ?></td></tr>
		</tbody>
	</table>

	<p class="description"><?php esc_html_e( 'On a record that already exists, a switch saves the moment you flip it, without the Save button. A new record keeps the Save button until it is created.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Notice lifecycle', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'A notice moves through these statuses; the public shortcode exposes the classification from preliminary onward:', 'ffcertificate' ); ?></p>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Status', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Public?', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Meaning', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr><td><code>draft</code></td><td><?php esc_html_e( 'No', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Being prepared; never shown publicly.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>preliminary</code></td><td><?php esc_html_e( 'Yes', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Provisional ranking published for review.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>definitive</code></td><td><?php esc_html_e( 'Yes', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Final ranking in force; candidates can be called.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>closed</code></td><td><?php esc_html_e( 'Yes', 'ffcertificate' ); ?></td><td><?php esc_html_e( 'Finished; still shown publicly with a "closed" banner. Reopening back to definitive requires a reason.', 'ffcertificate' ); ?></td></tr>
		</tbody>
	</table>

	<h4><?php esc_html_e( 'Public shortcodes', 'ffcertificate' ); ?></h4>
	<ul>
		<li><code>[ffc_recruitment_queue notice="EDITAL-01"]</code> — <?php esc_html_e( 'the public classification list for a notice. Shows only the columns the notice marks public. Accepts an optional adjutancy="slug" attribute, and honors the ?q (name search), ?adjutancy, ?subscription (pcd/geral) and ?page_top / ?page_bottom URL filters. The "Show on the site" card on the notice edit screen has it ready to copy.', 'ffcertificate' ); ?></li>
		<li><code>[ffc_recruitment_queue]</code> — <?php esc_html_e( 'without a notice, a selector of every notice in its preliminary or definitive list; drafts and closed notices are left out. The chosen notice\'s list appears below it. Add list="preliminary" or list="definitive" to offer only one kind.', 'ffcertificate' ); ?></li>
		<li><code>[ffc_recruitment_my_calls]</code> — <?php esc_html_e( 'shows the logged-in candidate their own call-ups (no attributes). Matched by the candidate record linked to their WordPress account.', 'ffcertificate' ); ?></li>
	</ul>

	<h4><?php esc_html_e( 'Import & call-ups', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Candidates are imported from CSV (headers: name, cpf, rf, email, adjutancy, rank, score, pcd, plus optional phone, time_points, hab_emebs) into either the preliminary or the definitive list. The definitive list is imported only while the notice is preliminary and has no calls, and the import promotes the notice in the same step, so a refused import changes nothing. Call-ups are recorded per candidate; calling out of rank order requires a reason. Outcomes move a candidate through called → accepted / not_shown / hired / withdrew.', 'ffcertificate' ); ?></p>

	<p><?php esc_html_e( 'Every line is checked before anything is imported, on the screen\'s import and through the API alike: identifiers, score, time points, rank and adjutancy. One invalid line refuses the whole file, and the report names each line, each problem and the value at fault (never a CPF or RF, which are personal data). The file must be UTF-8: Excel\'s plain "CSV" is Windows-1252, so choose "CSV UTF-8" when saving. Promoting the preliminary list keeps every column, time points and the EMEBS qualification included.', 'ffcertificate' ); ?></p>

	<p><?php esc_html_e( 'What a notice takes depends on its status. A draft takes adjutancy changes but no preliminary statuses, since nothing is published yet. A preliminary notice takes both. From definitive on, adjutancies are no longer attached or detached, no candidate moves to another adjutancy, and the preliminary list keeps the statuses and reasons it was published with. A closed notice changes no more: no call, outcome, cancel, reopen or undo. The screen shows each list read-only where its status allows no change, and the REST API refuses the same changes.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'Calls and outcomes are recorded only on the definitive list of a notice in definitive. A draft, preliminary or closed notice takes none, so nobody is called about a list that was not published or after the notice closed. Cancel call and Reopen end the call itself: its .ics stops downloading, it leaves "My calls", and the next call is the only active one. The candidate is e-mailed when a call is cancelled, under the same send mode as the call; reopening a not shown row sends nothing. A notice in preliminary cannot go back to draft.', 'ffcertificate' ); ?></p>

	<p><?php esc_html_e( 'The call e-mail gives the presentation date and time in the site\'s formats, attaches the call\'s .ics, links to "My calls" and asks the candidate to confirm by replying. The "Days to answer a call" option of the Recruitment settings adds a deadline, counted from the day of the call and never later than the presentation; 0 names none. The e-mail also carries a link to define a password, which is how a candidate whose account the import created signs in for the first time. A candidate with no e-mail on file is marked "No email — contact manually" on the definitive list, and calling one says so, since nobody was notified.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'Candidate accounts are linked or created once the notice reaches preliminary, never for a draft. A background task handles them in batches after the import: it links an existing WordPress account (matched by CPF, RF or e-mail) and creates one only when none exists, without the welcome e-mail. A candidate who already has an account keeps it, so a re-import moves nothing. The linked account is what lets the candidate follow their own call-ups.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'A candidate is shared by every notice and is recognised by CPF, then by RF. Before anything is written, each line is compared with the candidate it matches. A line whose CPF belongs to one candidate and whose RF belongs to another refuses the whole file. A line whose name, CPF, RF or e-mail differs from the stored candidate is listed, and the import waits for confirmation: confirming attaches the line to that candidate and keeps the stored data. An import never overwrites a stored name or identifier; it only fills in one the candidate lacks. To correct a candidate, edit it on its own screen.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'Only a draft notice can be deleted, and its imported lists and adjutancy links go with it. A published notice is closed instead, so its calls and its history stay.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'A candidate linked to an account reads their own data unmasked, so a link is a grant of access. Nobody can link a candidate to their own account. Without the PII capability, a candidate can only be linked to the account that carries its own CPF, RF or e-mail. Every link and unlink is recorded in the activity log and in the candidate\'s history.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'The REST API edits a candidate under the edit screen\'s rules: name, phone and notes for the manage capability, the e-mail only for whoever may reveal it, CPF and RF never, and every change logged. Every reveal of a CPF, RF or e-mail is logged; there is no setting to turn that off. The PCD filter of the public list exists only when the notice shows the PCD column, and the candidate\'s own API answer carries the score only when the notice shows it, never the staff fields of a call.', 'ffcertificate' ); ?></p>

	<p><?php esc_html_e( 'The notice screen opens to everyone who can view recruitment. A Viewer reads the lists. An Operator also calls candidates, records outcomes (accepted, not shown, hired, withdrew) and cancels or reopens the calls they made. The notice\'s name, public columns, status and adjutancies, the preliminary statuses, imports, deletion and undoing a decision stay with the Manager, who can also cancel any call.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Capabilities', 'ffcertificate' ); ?></h4>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Capability', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Grants', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr><td><code>ffc_view_recruitment</code></td><td><?php esc_html_e( 'read-only access to the admin pages.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>ffc_manage_recruitment</code></td><td><?php esc_html_e( 'create / edit notices, candidates and adjutancies.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>ffc_import_recruitment</code> / <code>ffc_call_recruitment</code></td><td><?php esc_html_e( 'import candidates / call candidates, record outcomes and cancel their own calls.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>ffc_delete_recruitment</code></td><td><?php esc_html_e( 'delete notices, candidates and adjutancies.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>ffc_view_recruitment_reasons</code> / <code>ffc_manage_recruitment_reasons</code></td><td><?php esc_html_e( 'view / edit the reason catalog.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>ffc_view_recruitment_settings</code> / <code>ffc_manage_recruitment_settings</code></td><td><?php esc_html_e( 'view / save the Settings tab.', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>ffc_view_recruitment_pii</code></td><td><?php esc_html_e( 'reveal unmasked CPF/RF/email — without it those columns stay masked.', 'ffcertificate' ); ?></td></tr>
		</tbody>
	</table>

	<?php ob_start(); ?>
		<p>
			<strong><?php esc_html_e( 'Privacy:', 'ffcertificate' ); ?></strong>
			<?php esc_html_e( 'CPF, RF and email are stored encrypted and shown masked by default in both the admin lists and the public queue; unmasking requires the ffc_view_recruitment_pii capability, and a candidate can always see their own data. Convocation emails always mask these fields.', 'ffcertificate' ); ?>
		</p>
	<?php
	\FreeFormCertificate\Core\AdminNotice::callout(
		'info',
		(string) ob_get_clean(),
		array( 'icon' => 'lock' )
	);
	?>
</div>

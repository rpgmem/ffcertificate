<?php
/**
 * Documentation partial — Feature: Date Messages.
 *
 * E-mails sent on a date in each person's profile (starting with birthdays):
 * rules, recipient selection, preview and test send, manual sends, history,
 * manager summary, upcoming dates, the daily schedule, opt-out, capabilities
 * and tokens (#1538).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Date Messages Section -->
<div class="card">
	<h3 id="feature-date-messages" class="ffc-icon-email"><?php esc_html_e( 'Date Messages', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'Date Messages sends an e-mail on a date stored in each person\'s profile — today, their birthday — or, as an announcement, once to an audience. It has its own top-level "Date Messages" menu with the Rules, Send now, History, Upcoming dates and Schedule tabs.', 'ffcertificate' ); ?></p>

	<?php ob_start(); ?>
		<p>
			<?php esc_html_e( 'The module is enabled by default (Settings → Modules), but no rule ships with the plugin, so nothing is sent until an administrator creates a rule and activates it.', 'ffcertificate' ); ?>
		</p>
	<?php
	\FreeFormCertificate\Core\AdminNotice::callout(
		'info',
		(string) ob_get_clean(),
		array( 'title' => __( 'On, but silent until you create a rule.', 'ffcertificate' ) )
	);
	?>

	<h4><?php esc_html_e( 'Rules', 'ffcertificate' ); ?></h4>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Setting', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Meaning', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr><td><strong><?php esc_html_e( 'Name', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Operator-facing label for the rule.', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Kind of message', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'A dated rule follows a date in each person\'s profile; an announcement has no date and is sent once to the members of its audiences, or to every account. The kind decides which fields and placeholders the editor offers.', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Date', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Dated rules only: which date in the profile the rule follows (the birth date).', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Days from the date', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Dated rules only: 0 sends on the date itself; a negative number sends that many days before it, a positive one after it, up to two months either way.', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Audiences', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Everyone, or the members of the audiences chosen in the two-column picker — the same one the reregistration campaign uses. Belonging to any one of them is enough, and members of their sub-audiences are included; choosing an audience brings its sub-audiences along. The number beside each audience is how many people it reaches, sub-audiences included, each person counted once.', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Subject and message', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'The e-mail body only; the header and footer come from the Email Model. A new dated rule starts from the "Birthday message" default in Settings → Email texts, a new announcement from the "Announcement message" default, and "Restore Default Text" brings back the subject and message of the kind chosen.', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Sending', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( '"Active" and "E-mail each person on their date". Active, a dated rule is sent by the daily run each day, and an announcement goes out once, at its send time or from Send now; inactive, neither is sent. Activating a rule takes the send capability. A rule can stay active with personal e-mails off: it then e-mails nobody and runs, from the daily run or Send now alike, only to send its managers the summary of whose date it is.', 'ffcertificate' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Manager summary', 'ffcertificate' ); ?></strong></td><td><?php esc_html_e( 'Optionally e-mails chosen managers a summary 24 hours after each run, with counts only or counts and the names of who received it. See below.', 'ffcertificate' ); ?></td></tr>
		</tbody>
	</table>
	<p><?php esc_html_e( 'Rules can be edited, duplicated and deleted from the Rules tab, where the Active toggle on each rule switches it on or off and saves at once. Deleting a rule keeps what it already sent in the history.', 'ffcertificate' ); ?></p>

	<p class="description"><?php esc_html_e( 'On a record that already exists, a switch saves the moment you flip it, without the Save button. A new record keeps the Save button until it is created.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Announcements', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'An announcement is a rule of the kind "Announcement". It reaches each person in its audiences once per rule: sending it again, on any day, reaches only whoever it missed. Its placeholders leave out {{date}}, {{age}} and {{days_until}}, which need a date.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( '"Send at" schedules it once, in the site timezone, and the history lists that run with the Announcement trigger. Left empty, the announcement is sent by hand from Send now, for today only.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'People who turned announcements off on their dashboard are skipped, unless "Send it also to people who turned announcements off" is on; the history then counts them as sent despite an opt-out.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'Activating, scheduling and sending an announcement, and sending it past opt-outs, take ffc_send_date_messages, as activating any rule does.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Who receives a message', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'For each person whose date falls on the day, the first matching decision wins: outside the audience, opted out, no valid e-mail, already sent, otherwise will receive. Each person gets a rule\'s message once per occurrence of their date, however often the run repeats. People born on 29 February are treated as born on 28 February in every year, leap years included: that is the day they are reached and the day {{age}} goes up. The year of birth is never shown; {{age}} is computed from it.', 'ffcertificate' ); ?> <?php esc_html_e( 'An announcement to audiences reads only their members, sub-audiences included, so its run and its preview never list anyone outside them; an announcement to everyone reads every account.', 'ffcertificate' ); ?> <?php esc_html_e( 'An announcement reaches each person once per rule: sending it again on another day reaches only whoever it missed.', 'ffcertificate' ); ?></p>
	<p class="description"><?php esc_html_e( 'A birth date given before the profile field existed reaches the profile through a migration card in Settings → Data Migrations; while it has accounts left, the Date Messages screen shows a warning, because those people are not yet in previews, upcoming dates or sends.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Body appearance', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'A rule can draw its message body over a background image from the Media Library, with a fallback colour, a text colour and the text on the left, on the right or across the whole width (taking 50, 60 or 70 per cent of it). Only the body changes: the header and footer stay the Email Model\'s, and the image fills the body edge to edge at the Email Model width, at least as tall as the image. On phones the text takes the full width, over the image.', 'ffcertificate' ); ?></p>
	<p class="description"><?php esc_html_e( 'The fallback colour is required with an image: Outlook on Windows and readers that block images show it instead. The editor shows the contrast of the text against it; over the image itself, check it in "Preview message".', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Preview recipients and test send', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( '"Preview recipients" lists, for a range of dates, everyone the rule would reach and the decision for each, using the very selection the send uses, so the preview cannot disagree with what is sent. Long lists show only the first rows, while the totals count everyone. "Preview message" shows the e-mail as it will be sent, header, body appearance and footer, at computer or phone width. "Send test to me" mails the message, filled with fictional values, to your own address. All three use the values on the form, saved or not, and none sends to anybody else. Only the test send is recorded: it shows in the history marked "Test", with who sent it, and it never counts as a delivery, so it cannot stop the real message from going out.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Send now', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Sends one active rule now, in batches handed to wp_mail(): a dated rule to everyone whose date falls in a chosen range of up to 31 days, an announcement for today only. Before it starts, the page confirms the rule, the dates and how many people it will reach. People already sent that rule for that date are skipped, so running the same range twice sends nothing new. With no mail-queue plugin active, a large range is sent as fast as PHP runs.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'Send now is also how a missed day of a dated rule is recovered: the daily run covers only the current day, so a day the scheduler did not run is not caught up the next day. Only an active rule can be sent; deactivating a rule stops a run still in progress at its next batch.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'A batch that is cut off part-way, for example by a slow mail server, is run again ten minutes later, up to three times; nobody it already reached is sent the message twice or counted twice. A message that cannot be built or sent counts as failed and the batch goes on. The daily run starts each rule as its own scheduled task, so one rule that fails does not stop the others.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'History', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Every run is listed with its rule, its trigger — Daily, Manual, or Announcement for a scheduled announcement — its dates and its counters: sent, sent despite an opt-out, opted out, no valid e-mail, outside the audience, failed, and listed for the summary only. "Sent" counts messages handed to wp_mail(); with a mail queue active, delivery happens afterwards from the queue. Test sends are listed marked "Test", and "Hide test sends" leaves them out. Above the list, the tab shows when the next daily run is due, with a link to Settings → Scheduled Tasks for those who can open it.', 'ffcertificate' ); ?></p>
	<p><?php esc_html_e( 'The history keeps the last 365 days: each daily run removes older runs together with their deliveries, even while e-mails are disabled. A message can never go out twice because of it, since a delivery is recorded per date including its year.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Manager summary', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'When enabled on a rule, the chosen managers receive a summary 24 hours after each run starts. Names go only to recipients allowed to see who receives date messages (ffc_view_date_messages_pii, or administrators), and travel masked, with a link to the run in History; everyone else gets the counts. A run that reached nobody sends no summary. A run still in progress after 24 hours is reported once it closes, checked every hour for up to 12 hours, and a summary every send refused is tried again on the same schedule. Only administrators and accounts holding a date-messages capability can be chosen, listed by name with the e-mail masked.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Upcoming dates', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Lists the birthdays in the next 7 or 30 days or in a chosen month, by day and month. It can be narrowed to one active rule, and then to one of that rule\'s audiences; with no rule chosen, the audience filter offers those of every active rule. An Audiences column names which of them each person belongs to. The tab is shown only to holders of ffc_view_date_messages_pii and administrators. Names are masked, here and in the recipient preview; Reveal shows one and "Reveal this page" all of them, each recorded in the activity log.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Daily schedule', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'The daily run sends every active rule once a day, for that day\'s dates only, at 08:00 in the site timezone by default. The Schedule tab shows the current time and the next run; the time itself is changed on Settings → Scheduled Tasks, which needs the settings-management capability (ffc_manage_settings) and also shows the server line that keeps scheduled tasks running on a quiet site.', 'ffcertificate' ); ?> <?php esc_html_e( 'Announcements are not part of it: each goes out at its own send time, or from Send now.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'Opting out', 'ffcertificate' ); ?></h4>
	<ul>
		<li><?php esc_html_e( 'On their dashboard, under Notification Preferences, a person can turn off "Date messages (such as birthday greetings)" and "Announcements from this site". The two are separate: turning one off does not stop the other.', 'ffcertificate' ); ?></li>
		<li><?php esc_html_e( 'The link in a dated message turns date messages off; the one in an announcement turns announcements off.', 'ffcertificate' ); ?></li>
		<li><?php esc_html_e( 'An announcement sent to everyone still reaches people who turned announcements off when its rule is set to send it anyway. The history counts those sends as forced, so the choice is never silent.', 'ffcertificate' ); ?></li>
		<li><?php esc_html_e( 'Every message carries an unsubscribe link; a body that does not place {{unsubscribe_url}} gets an unsubscribe line appended. The link is a signed token that stores nothing; opening it shows a confirmation page, and only confirming turns date messages off, so mail scanners that follow links change nothing. Each message also carries the List-Unsubscribe headers, so the Unsubscribe button a mail client shows beside the sender turns them off in one step, checked by the same token.', 'ffcertificate' ); ?></li>
	</ul>

	<h4><?php esc_html_e( 'E-mail tokens', 'ffcertificate' ); ?></h4>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Variable', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Description', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr><td><code>{{name}}</code>, <code>{{first_name}}</code>, <code>{{last_name}}</code>, <code>{{full_name}}</code></td><td><?php esc_html_e( 'The person\'s name as stored; the first and last name from the WordPress profile (when empty, the first word of the name and the rest); and the two joined', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>{{email}}</code></td><td><?php esc_html_e( 'The person\'s e-mail address', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>{{date}}</code>, <code>{{age}}</code></td><td><?php esc_html_e( 'The date of this occurrence and the age reached on it', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>{{days_until}}</code></td><td><?php esc_html_e( 'Days between the send and the date', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>{{site_name}}</code>, <code>{{dashboard_url}}</code></td><td><?php esc_html_e( 'Site name and the link to the user dashboard', 'ffcertificate' ); ?></td></tr>
			<tr><td><code>{{unsubscribe_url}}</code></td><td><?php esc_html_e( 'The unsubscribe link (opens a confirmation page)', 'ffcertificate' ); ?></td></tr>
		</tbody>
	</table>
	<p class="description"><?php esc_html_e( 'Messages go through the shared e-mail pipeline and Email Model chrome, and respect the global "Disable all emails" switch.', 'ffcertificate' ); ?> <a href="#reference-emails"><?php esc_html_e( 'See Emails & Delivery', 'ffcertificate' ); ?></a>.</p>

	<h4><?php esc_html_e( 'Capabilities', 'ffcertificate' ); ?></h4>
	<ul>
		<li><code>ffc_view_date_messages</code> — <?php esc_html_e( 'read-only access to the rules, their send history and recipient totals.', 'ffcertificate' ); ?></li>
		<li><code>ffc_manage_date_messages</code> — <?php esc_html_e( 'create, edit and duplicate rules. Activating a rule, sending an announcement past opt-outs, sending a rule and scheduling an announcement take ffc_send_date_messages, and deleting a rule takes ffc_delete_date_messages. The daily send time is set on Settings → Scheduled Tasks, under ffc_manage_settings.', 'ffcertificate' ); ?></li>
		<li><code>ffc_send_date_messages</code> — <?php esc_html_e( 'send a rule now, schedule an announcement, activate a rule or send an announcement past opt-outs, in the editor, on the list\'s toggle and on a copy alike. Managing rules does not include it; the current managers receive it when the plugin is updated.', 'ffcertificate' ); ?></li>
		<li><code>ffc_delete_date_messages</code> — <?php esc_html_e( 'delete a rule; its history stays. Managing rules does not include it; the current managers receive it when the plugin is updated.', 'ffcertificate' ); ?></li>
		<li><code>ffc_test_date_messages</code> — <?php esc_html_e( 'send a rule\'s test, with sample values, to your own address. Managing date messages allows it too. One test a minute per account; an account that may only test sends the rule as saved, not what is typed in the editor. Previewing the recipients and the message needs only the view capability.', 'ffcertificate' ); ?></li>
		<li><code>ffc_view_date_messages_pii</code> — <?php esc_html_e( 'see people by name with their birthday (day and month) in the recipient preview, the upcoming dates and the manager summary.', 'ffcertificate' ); ?></li>
	</ul>
</div>

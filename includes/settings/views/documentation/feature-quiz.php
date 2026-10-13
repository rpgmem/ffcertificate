<?php
/**
 * Documentation partial — Feature: Quiz / Evaluation.
 *
 * Documents the additional PDF template variables ({{score}}, {{max_score}},
 * {{score_percent}}, …) that become available when a form runs in quiz /
 * evaluation mode.
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- 3. Quiz / Evaluation Variables Section -->
<div class="card">
	<h3 id="feature-quiz" class="ffc-icon-chart"><?php esc_html_e( 'Quiz / Evaluation', 'ffcertificate' ); ?></h3>
	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Turning a form into a quiz', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'Enable it on the Quiz tab of the form editor\'s "Certificate Form Configuration" box. Each field on the Fields tab then shows "Score this question"; a question left off is out of the count and of the points the percentage is taken from.', 'ffcertificate' ); ?></p>
		<ul>
			<li><strong><?php esc_html_e( 'Radio, select and checkbox', 'ffcertificate' ); ?></strong> — <?php esc_html_e( '"Correct option(s)": tick the correct options beside them; radio and select score when a correct option is chosen, a checkbox only when every correct option is ticked and no other. "Points per option": give each option its points; a checkbox adds the boxes ticked, never below zero, so a wrong box can be worth negative points.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Text and textarea', 'ffcertificate' ); ?></strong> — <?php esc_html_e( '"No points", "Points when answered", which respects the minimum length the field sets, or "Manual grading", where a person gives the answer from 0 to its points.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Number and date', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'a correct range, bounds included; the same value in both bounds is one exact answer, and an empty bound leaves the range open.', 'ffcertificate' ); ?></li>
		</ul>
		<p><?php esc_html_e( 'A form saved before these options keeps scoring as it did: radio and select by their points per option. A question set to score that cannot be marked — no option marked correct, or no correct range — is reported when the form is saved and left out of the score. A checkbox whose maximum selections is below its number of correct options has the maximum raised to fit.', 'ffcertificate' ); ?></p>
		<ul>
			<li><strong><?php esc_html_e( 'Passing Score (%)', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'minimum percentage to pass (default 70; 0 means no minimum).', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Max Attempts', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'retries allowed per CPF/RF (0 = unlimited).', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Display Options', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'show the score after submission (on by default) and show which answers were correct or incorrect: the message says how many questions were answered correctly, and each scored question is marked Correct or Incorrect. The right answer itself is never shown.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Leaving the Window', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'off by default. When on, the form warns the participant before they start; once they have started filling in the form, switching to another tab or minimising the browser clears the scored answers (name, CPF/RF and the other fields stay) and a dialog explains why on return. Leaving does not count as an attempt; the number of times it happened is stored with the submission and shown on its edit screen as "Times the quiz window was left". It is a deterrent, not a lock: another device, or a browser without JavaScript, is not stopped, and the count is reported by the browser.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Manual grading', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'an attempt with an open answer graded by a person shows no result: it is stored as "Quiz: Awaiting Grading", the participant is told the result will be emailed, and no certificate exists yet. While it waits, the same CPF/RF cannot start another attempt. Grading it on the submission edit screen decides it and emails the participant: the document link on a pass; otherwise the result, with the score only when the form shows it, and how many attempts are left.', 'ffcertificate' ); ?></li>
		</ul>
		<p><?php esc_html_e( 'An attempt below the passing score is kept as a "Quiz: Retry" submission while attempts remain, and as "Quiz: Failed" once they run out; no certificate is issued for either.', 'ffcertificate' ); ?></p>
	</div>

	<p><?php esc_html_e( 'When a form uses quiz/evaluation mode, these additional variables are available in the PDF template:', 'ffcertificate' ); ?></p>

	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Variable', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Description', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Example Output', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><code>{{score}}</code></td>
				<td><?php esc_html_e( 'Points earned across the scored questions', 'ffcertificate' ); ?></td>
				<td><em>8</em></td>
			</tr>
			<tr>
				<td><code>{{max_score}}</code></td>
				<td><?php esc_html_e( 'Maximum points available across the scored questions', 'ffcertificate' ); ?></td>
				<td><em>10</em></td>
			</tr>
			<tr>
				<td><code>{{score_percent}}</code></td>
				<td><?php esc_html_e( 'Percentage score', 'ffcertificate' ); ?></td>
				<td><em>80</em></td>
			</tr>
			<tr>
				<td><code>{{correct_count}}</code></td>
				<td><?php esc_html_e( 'Questions answered correctly as a whole', 'ffcertificate' ); ?></td>
				<td><em>4</em></td>
			</tr>
			<tr>
				<td><code>{{question_count}}</code></td>
				<td><?php esc_html_e( 'Questions scored', 'ffcertificate' ); ?></td>
				<td><em>5</em></td>
			</tr>
		</tbody>
	</table>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Example Usage:', 'ffcertificate' ); ?></h4>
		<pre><code>&lt;p&gt;Score: &lt;strong&gt;{{score}}&lt;/strong&gt; / {{max_score}} ({{score_percent}}%)&lt;/p&gt;</code></pre>
	</div>
</div>

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
	<h3 id="feature-quiz"><span class="dashicons dashicons-chart-bar" aria-hidden="true"></span> <?php esc_html_e( 'Quiz / Evaluation', 'ffcertificate' ); ?></h3>
	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Turning a form into a quiz', 'ffcertificate' ); ?></h4>
		<p><?php esc_html_e( 'Enable it on the Quiz tab of the form editor\'s "Certificate Form Configuration" box, then give each scored field its points per option on the Fields tab. Only radio and select fields are scored.', 'ffcertificate' ); ?></p>
		<ul>
			<li><strong><?php esc_html_e( 'Passing Score (%)', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'minimum percentage to pass (default 70; 0 means no minimum).', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Max Attempts', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'retries allowed per CPF/RF (0 = unlimited).', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Display Options', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'show the score after submission (on by default) and show which answers were correct or incorrect.', 'ffcertificate' ); ?></li>
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
				<td><?php esc_html_e( 'Points earned — sum of the point values of the options the participant selected', 'ffcertificate' ); ?></td>
				<td><em>8</em></td>
			</tr>
			<tr>
				<td><code>{{max_score}}</code></td>
				<td><?php esc_html_e( 'Maximum points available — sum of each scored field\'s highest option value', 'ffcertificate' ); ?></td>
				<td><em>10</em></td>
			</tr>
			<tr>
				<td><code>{{score_percent}}</code></td>
				<td><?php esc_html_e( 'Percentage score', 'ffcertificate' ); ?></td>
				<td><em>80</em></td>
			</tr>
		</tbody>
	</table>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Example Usage:', 'ffcertificate' ); ?></h4>
		<pre><code>&lt;p&gt;Score: &lt;strong&gt;{{score}}&lt;/strong&gt; / {{max_score}} ({{score_percent}}%)&lt;/p&gt;</code></pre>
	</div>
</div>

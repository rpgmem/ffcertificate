<?php
/**
 * Documentation partial — Section 3: Quiz / Evaluation Variables.
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
<!-- 3. Quiz / Evaluation Variables Section -->
<div class="card">
	<h3 id="feature-quiz"><span class="dashicons dashicons-chart-bar" aria-hidden="true"></span> <?php esc_html_e( 'Quiz / Evaluation', 'ffcertificate' ); ?></h3>
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
				<td><?php esc_html_e( 'Number of correct answers', 'ffcertificate' ); ?></td>
				<td><em>8</em></td>
			</tr>
			<tr>
				<td><code>{{max_score}}</code></td>
				<td><?php esc_html_e( 'Total number of questions', 'ffcertificate' ); ?></td>
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

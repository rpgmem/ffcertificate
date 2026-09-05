<?php
/**
 * Documentation partial — Configuration: Captcha.
 *
 * The Settings → Captcha tab: the three modes and what each one costs, the
 * ALTCHA widget's own options, and what the proof of work does and does not
 * send (rpgmem/ffcertificate#1053).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Configuration: Captcha Section -->
<div class="card">
	<h3 id="config-captcha"><span class="dashicons dashicons-shield" aria-hidden="true"></span> <?php esc_html_e( 'Captcha', 'ffcertificate' ); ?></h3>

	<p><?php esc_html_e( 'Settings → Captcha chooses the challenge that guards every public form at once: certificate submissions, certificate verification, appointment booking and the public CSV download. The invisible honeypot field is always on and is not part of this choice.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'The three modes', 'ffcertificate' ); ?></h4>
	<table class="widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Mode', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'What the visitor does', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Requires', 'ffcertificate' ); ?></th>
				<th scope="col"><?php esc_html_e( 'What it costs', 'ffcertificate' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><strong><?php esc_html_e( 'Math challenge only', 'ffcertificate' ); ?></strong> <em>(<?php esc_html_e( 'default', 'ffcertificate' ); ?>)</em></td>
				<td><?php esc_html_e( 'Answers an arithmetic question.', 'ffcertificate' ); ?></td>
				<td><?php esc_html_e( 'Nothing', 'ffcertificate' ); ?></td>
				<td><?php esc_html_e( 'Weakest against automation — the answer space is small, so a script that does arithmetic gets through.', 'ffcertificate' ); ?></td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'ALTCHA, with the math challenge as fallback', 'ffcertificate' ); ?></strong></td>
				<td><?php esc_html_e( 'Solves a proof of work, or answers the arithmetic question when JavaScript is off.', 'ffcertificate' ); ?></td>
				<td><?php esc_html_e( 'Nothing', 'ffcertificate' ); ?></td>
				<td><?php esc_html_e( 'Reach, not strength: the server accepts either proof, so an attacker picks the cheaper one and the effective security equals the math challenge alone.', 'ffcertificate' ); ?></td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'ALTCHA only', 'ffcertificate' ); ?></strong></td>
				<td><?php esc_html_e( 'Solves a proof of work in the browser.', 'ffcertificate' ); ?></td>
				<td><?php esc_html_e( 'JavaScript and HTTPS', 'ffcertificate' ); ?></td>
				<td><?php esc_html_e( 'A visitor without JavaScript cannot submit the form at all. The widget also refuses to run outside a secure connection, so this mode cannot be saved on a site that is not served over HTTPS.', 'ffcertificate' ); ?></td>
			</tr>
		</tbody>
	</table>
	<p class="description"><?php esc_html_e( 'Whichever mode is in force, the token is signed with a key derived from this site, expires, and is accepted once — a captured answer cannot be replayed.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'ALTCHA widget options', 'ffcertificate' ); ?></h4>
	<ul>
		<li><strong><?php esc_html_e( 'Work factor', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'the size of the secret number the browser searches for, so it does about half that many hash operations. Bounded on both sides: below the floor the proof means nothing, above the ceiling a slow phone grinds until the widget gives up after 90 seconds.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Challenge lifetime', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'how long an issued challenge stays valid. Long enough to fill in the form without rushing, short enough that a solved challenge is not worth stockpiling.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Control', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'checkbox or switch.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'When to verify', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'on activation, on focus, on page load or on submit. Verifying on load spends the work before anyone decides to submit, and a challenge started too early can expire while the form is still being filled in.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Attribution', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'hide the logo and/or the "Protected by ALTCHA" footer.', 'ffcertificate' ); ?></li>
	</ul>
	<p class="description"><?php esc_html_e( 'These options only appear when a mode that shows the widget is selected. The widget follows the site\'s colours and its light/dark mode on its own.', 'ffcertificate' ); ?></p>

	<h4><?php esc_html_e( 'What it does, and does not, send', 'ffcertificate' ); ?></h4>
	<p><?php esc_html_e( 'Nothing leaves this server. The widget file is served from this site rather than a content delivery network, the challenge is issued by this WordPress installation, the computation happens in the visitor\'s browser, and the answer is checked here. Two privacy-relevant widget features are switched off and deliberately not offered as settings: the behavioural signature (pointer and keyboard timings) and the widget\'s own cookie. The proof of work already carries the anti-automation load, and turning either back on would change what the site has to disclose.', 'ffcertificate' ); ?></p>
	<p class="description"><?php esc_html_e( 'In the fallback mode, a submission that arrives without a proof of work is recorded in the Activity Log with a hashed, truncated IP — so you can tell whether anyone still needs the fallback before switching it off.', 'ffcertificate' ); ?> <a href="#reference-security"><?php esc_html_e( 'See Security & Restrictions.', 'ffcertificate' ); ?></a></p>
</div>

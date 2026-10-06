<?php
/**
 * Documentation partial — Identity Resolution.
 *
 * Certificate → Identities: the queue that works the identity audit's
 * findings (wrong CPF/RF, two accounts on one number, records with no login),
 * the actions it offers and the capabilities that gate them. Documented
 * against IdentityResolutionPage / identity-resolution-page.php and the
 * includes/maintenance/class-ffc-identity-*.php services.
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Identity Resolution Section -->
<div class="card">
	<h3 id="feature-identity-resolution"><span class="dashicons dashicons-groups" aria-hidden="true"></span> <?php esc_html_e( 'Identity Resolution', 'ffcertificate' ); ?></h3>
	<p><?php esc_html_e( 'Certificate → Identities lists accounts and stored CPF/RF numbers that do not agree with each other — the findings of the identity audit on the Data Migrations tab — sorted by how much of the answer is already known. Check digits can say a number is wrong, never what the right one is, so confirm corrections with HR. Identifiers are shown as a hash; a stored number is never displayed.', 'ffcertificate' ); ?></p>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'The panels', 'ffcertificate' ); ?></h4>
		<ul>
			<li><strong><?php esc_html_e( 'Numbers to correct', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'a stored CPF or RF that fails its own check digit. Work these first: a number that cannot be anyone\'s is not evidence that two accounts are one person, so a merge or move that depends on it is refused.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'One is mistyped', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'an account holding two values where the check digits show which one is wrong, so no value has to be asked for.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Two accounts, one number', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'one number stored against more than one account: either a duplicate login to merge, or one login carrying someone else\'s mistyped number.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'One address, several people', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'numbers that share one address without being variants of each other, such as a shared mailbox or an account submitting for other people.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Needs a decision', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'the check digits do not single one value out, so HR has to say which number is this person\'s.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'No account', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'records carrying an identifier that belongs to no account, usually recruitment candidacies never promoted or records whose account was deleted.', 'ffcertificate' ); ?></li>
		</ul>
	</div>

	<h4><?php esc_html_e( 'Actions', 'ffcertificate' ); ?></h4>
	<ul>
		<li><strong><?php esc_html_e( 'Correct', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'replace a wrong stored number with the right one.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Move or link', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'attach records to the account they belong to. The two sides must already agree on one identifier, and the screen says before you confirm whether the move would be accepted.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Split off', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'give records an account of their own, which creates a WordPress user.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Merge this pair', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'consolidate two logins that are one person. It cannot be undone: afterwards nothing can say which record came from which login.', 'ffcertificate' ); ?></li>
		<li><strong><?php esc_html_e( 'Accept as unresolvable', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'when the number was never supplied or HR cannot say whose it is, record that decision. The finding moves to "Accepted — no resolution possible", where it can be put back in the queue; nothing stored changes, and a merge or move blocked by that number stays blocked.', 'ffcertificate' ); ?></li>
	</ul>
	<p class="description"><?php esc_html_e( 'Every action runs as a single transaction, rolled back whole if any part refuses. The findings can be exported as CSV.', 'ffcertificate' ); ?></p>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'Capabilities', 'ffcertificate' ); ?></h4>
		<ul>
			<li><code>ffc_manage_identities</code> — <?php esc_html_e( 'open the screen, correct numbers and move records. It does not reveal a stored identifier.', 'ffcertificate' ); ?></li>
			<li><code>ffc_split_identities</code> — <?php esc_html_e( 'open a new account from the queue.', 'ffcertificate' ); ?></li>
			<li><code>ffc_merge_identities</code> — <?php esc_html_e( 'merge two accounts.', 'ffcertificate' ); ?></li>
		</ul>
	</div>

	<p class="description"><?php esc_html_e( 'New wrong RFs keep arriving while the RF check digit setting is off.', 'ffcertificate' ); ?> <a href="#config-general"><?php esc_html_e( 'See General.', 'ffcertificate' ); ?></a></p>
</div>

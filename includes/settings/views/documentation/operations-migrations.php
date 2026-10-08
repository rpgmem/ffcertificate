<?php
/**
 * Documentation partial — Operations: Data Migrations.
 *
 * Settings → Data Migrations: the batched data-migration cards registered in
 * MigrationRegistry, described by purpose rather than enumerated by count, plus
 * how a card runs. The maintenance tools on the same tab have their own page
 * (operations-maintenance.php).
 *
 * @package FreeFormCertificate\Settings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!-- Data Migrations Section -->
<div class="card">
	<h3 id="operations-migrations" class="ffc-icon-database"><?php esc_html_e( 'Data Migrations', 'ffcertificate' ); ?></h3>
	<p><?php esc_html_e( 'Settings → Data Migrations brings stored data in line with what newer versions of the plugin expect. Each migration is a card showing how many records are migrated and how many are still pending. Click "Run Migration" once: it keeps processing batches until the card reaches 100%, with a live progress bar. Running a migration needs the Danger Zone capability.', 'ffcertificate' ); ?></p>

	<div class="ffc-doc-example">
		<h4><?php esc_html_e( 'What the cards do', 'ffcertificate' ); ?></h4>
		<ul>
			<li><strong><?php esc_html_e( 'Identifiers and search hashes', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'split the old combined CPF/RF column into separate CPF and RF columns, rewrite stored CPF, RF and e-mail values into one canonical form, rebuild the e-mail lookup hashes, and copy the CPF/RF hashes already linked to a user into the identity index.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Encryption', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'the Encryption Key Rotation re-encrypts stored personal data under FFC_ENCRYPTION_KEY from wp-config.php, with a second card for the remaining areas (recruitment candidates and reregistration bodies); two more cards clear plaintext left on activity log rows that are already encrypted and encrypt the client IP addresses older activity log rows still hold in clear.', 'ffcertificate' ); ?> <a href="#config-advanced"><?php esc_html_e( 'See Advanced → Encryption Key Health.', 'ffcertificate' ); ?></a></li>
			<li><strong><?php esc_html_e( 'Accounts and profiles', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'grant the certificate capabilities to accounts that own a certificate but cannot open it, name accounts created without a name, split full names into WordPress\'s first and last name, and copy birth dates already given in reregistrations to the profile.', 'ffcertificate' ); ?></li>
			<li><strong><?php esc_html_e( 'Legacy certificate templates', 'ffcertificate' ); ?></strong> — <?php esc_html_e( 'import layouts left in the old html/ drop-folder into the template pool and move the images they reference into the Media Library. These cards appear only while that folder exists.', 'ffcertificate' ); ?></li>
		</ul>
	</div>

	<div class="ffc-doc-note">
		<p>
			<strong class="ffc-icon-info"><?php esc_html_e( 'Safe to repeat, not to undo.', 'ffcertificate' ); ?></strong><br>
			<?php esc_html_e( 'A migration can be run again safely, and a finished one simply has nothing left to do. It cannot be undone, so take a database backup first and run the encryption cards during low traffic.', 'ffcertificate' ); ?>
		</p>
	</div>
	<p class="description"><?php esc_html_e( 'The same tab hosts the maintenance tools and the identity audit.', 'ffcertificate' ); ?> <a href="#operations-maintenance"><?php esc_html_e( 'See Maintenance Tools.', 'ffcertificate' ); ?></a></p>
</div>

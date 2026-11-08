<?php
/**
 * MigrationRegistry
 *
 * Centralized registry for all available migrations.
 * Separates configuration from execution logic.
 *
 * @package FreeFormCertificate\Migrations
 * @since 3.1.0 (Extracted from FFC_Migration_Manager v3.1.0 refactor)
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace
 */

declare(strict_types=1);

namespace FreeFormCertificate\Migrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry of migration entries.
 */
class MigrationRegistry {

	/**
	 * Registry of all available migrations
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $migrations = array();

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->register_migrations();
	}

	/**
	 * Register all available migrations
	 *
	 * An entry carrying `removal` is deprecated: its card says which release
	 * deletes it, so an install still showing records pending runs it first
	 * (#2115). The `@removal` marker beside it is what makes that release fire.
	 *
	 * @return void
	 */
	private function register_migrations(): void {
		$this->migrations = array();

		// v6.19.0 (#857 S7b): Re-encrypt submissions + appointments PII under a
		// newly-defined FFC_ENCRYPTION_KEY and rebuild search hashes under
		// FFC_HASH_SALT. Only runnable once the key is decoupled in wp-config.php.
		$this->migrations['key_rotation'] = array(
			'name'        => __( 'Encryption Key Rotation', 'ffcertificate' ),
			'description' => __( 'Re-encrypt stored personal data (submissions and appointments) under a strong FFC_ENCRYPTION_KEY defined in wp-config.php, rebuilding CPF/RF/email search hashes. Define the key first (Settings → Advanced → Encryption Key Health); run during low traffic, as hash-based lookups may transiently miss un-migrated rows until it completes.', 'ffcertificate' ),
			'icon'        => 'ffc-icon-encryption',
			'batch_size'  => 100,
			'order'       => 4,
		);

		// v6.24.0 (#1236): finish the rotation over the areas the S7b strategy
		// never walked -- recruitment candidates and reregistration bodies. On an
		// install that decoupled AFTER its data existed, those rows are still
		// under the WordPress-derived key, and their search hashes still under
		// the old salt, which breaks every hash lookup made since.
		$this->migrations['key_rotation_remaining'] = array(
			'name'        => __( 'Encryption Key Rotation — Remaining Areas', 'ffcertificate' ),
			'description' => __( 'Finish the key rotation over the areas the first pass never covered: recruitment candidates and reregistration submission bodies. Re-encrypts them under FFC_ENCRYPTION_KEY and rebuilds the CPF/RF/email search hashes under FFC_HASH_SALT — without this, candidate lookups by CPF or RF silently find nothing, and the data stays tied to the WordPress salts. Define both constants first (Settings → Advanced → Encryption Key Health).', 'ffcertificate' ),
			'icon'        => 'ffc-icon-encryption',
			'batch_size'  => 50,
			'order'       => 5,
		);

		// v6.26.0 (#1313): bring the identifiers already stored into the canonical
		// form the registry now applies on every write. Pairs with the `email`
		// normalization rule that landed in the same release -- the rule alone
		// would make a lookup canonicalise while the rows did not.
		$this->migrations['identity_normalization'] = array(
			'name'        => __( 'Canonicalise Stored Identifiers', 'ffcertificate' ),
			'description' => __( 'Rewrite stored CPF, RF and e-mail values into the one canonical form every module now hashes — digits only for CPF/RF, lowercase for e-mail — and rebuild their search hashes. Without it, an appointment booked as Joao@Escola.gov.br stays invisible to every lookup made elsewhere, and a CPF stored with its punctuation never matches the same person\'s certificate. Idempotent: a row already canonical is read and left alone.', 'ffcertificate' ),
			'icon'        => 'ffc-icon-id',
			'batch_size'  => 25,
			'order'       => 6,
		);

		// v6.26.0 (#1313 PR 8): project the identifiers already linked to a user
		// in the module tables into the identity index. Ordered AFTER the
		// canonicalisation card because it refuses to run before that one is
		// done -- see the strategy's `can_run()` for why there is no second
		// chance to fix a hash copied too early.
		$this->migrations['identity_index_backfill'] = array(
			'name'        => __( 'Backfill the Identity Index', 'ffcertificate' ),
			'description' => __( 'Copy the CPF and RF hashes already linked to a user in submissions, appointments and recruitment candidacies into the indexed identity columns, so resolving a person is one indexed lookup instead of a scan of every module. Fills an empty column only: where one account carries two different identifiers the column is left empty, because that is a conflict to review rather than one to resolve by picking. Run "Canonicalise Stored Identifiers" first — this card refuses until it is complete.', 'ffcertificate' ),
			'icon'        => 'ffc-icon-hash',
			'batch_size'  => 50,
			'order'       => 7,
		);

		// v6.28.2 (#1345): repair accounts that own a certificate and hold no
		// capability to read it. Ordered AFTER the identity cards because it
		// is their consequence -- adoption is what linked these rows, and the
		// grant it should have carried is what this card supplies.
		$this->migrations['certificate_capability_backfill'] = array(
			'name'        => __( 'Restore Access to Owned Certificates', 'ffcertificate' ),
			'description' => __( 'Grant the certificate capabilities to every account that owns a certificate and cannot open it. The permission used to come from whichever path created the account, while ownership comes from the record itself — so a person whose old submissions were claimed after their account was created by a candidacy, a reregistration import or an appointment ended up holding certificates the dashboard refused to show them. Measures the remaining accounts on every read, so running it again once it reports zero does nothing.', 'ffcertificate' ),
			'icon'        => 'ffc-icon-user-check',
			'batch_size'  => 100,
			'order'       => 8,
		);

		// v6.30.0 (#1480): give a name to accounts promoted from a candidacy,
		// which were created with none. Ordered AFTER the capability card
		// because it is the same population seen from the other side -- that
		// one gave them access to their certificates, this one gives them their
		// own name on the screen that lists them.
		$this->migrations['display_name_backfill'] = array(
			'name'        => __( 'Name the Accounts Created Without One', 'ffcertificate' ),
			'description' => __( 'Write the display name, first name and last name of every account that has none of them, from the name its own candidacy or submission already carries. A promotion used to create the account without passing the person\'s name, so WordPress fell back to storing the login — which is what the user list, the dashboard greeting and every e-mail then showed them. Only an account whose display name is still its login and whose first name is empty is touched, because those two are written together and an account with one but not the other was changed by something else. Completion here means every such account was EXAMINED: one whose records name nobody is read, left alone, and counted as done.', 'ffcertificate' ),
			'icon'        => 'ffc-icon-user',
			'batch_size'  => 100,
			'order'       => 9,
		);

		// v6.33.0 (#1552): split the full name that account creation and the
		// display-name card stored whole in `first_name`. Ordered right after
		// that card, which now splits too.
		$this->migrations['name_parts_backfill'] = array(
			'name'        => __( 'Split Full Names into First and Last Name', 'ffcertificate' ),
			'description' => __( 'Fill WordPress\'s first and last name from the full name the plugin stores — first word, then the rest — on accounts where the whole name sits in the first name, or neither is filled. Account creation used to store the whole name as the first name. Parts somebody filled in by hand are not touched. Completion here means every such account was EXAMINED: a one-word name is read, left as it is, and counted as done.', 'ffcertificate' ),
			'icon'        => 'ffc-icon-split',
			'batch_size'  => 100,
			'order'       => 10,
		);

		// v6.33.0 (#1538): copy the birth date people already gave through a
		// reregistration onto the canonical profile field, which is the only
		// place a scheduled job can query.
		$this->migrations['birth_date_backfill'] = array(
			'name'        => __( 'Copy Stored Birth Dates to the Profile', 'ffcertificate' ),
			'description' => __( 'Fill the profile birth date of every account that answered a reregistration before the field existed, from the newest submission (or, failing that, the stored field values). The full date is stored encrypted, with its day and month beside it for scheduled messages. Completion here means every such account was EXAMINED: one whose stored answers hold no valid date is read, left alone, and counted as done.', 'ffcertificate' ),
			'icon'        => 'ffc-icon-calendar',
			'batch_size'  => 100,
			'order'       => 11,
		);

		// #2502: the per-audience copies of the reregistration fields folded
		// into the field every audience inherits; their answers follow.
		$this->migrations['custom_field_answers'] = array(
			'name'        => __( 'Reregistration: Move Answers to Inherited Fields', 'ffcertificate' ),
			'description' => __( 'Audiences used to keep their own copy of each standard reregistration field, each with its own answers. The copies were folded into the field every audience now inherits; this moves the answers stored under a copy onto that field. Until it runs, the answers are read through the fold, so none is out of sight. When a person answered differently under two copies, the one kept is the value of their latest reregistration, otherwise the copy of the deepest audience they belong to.', 'ffcertificate' ),
			'icon'        => 'ffc-icon-merge',
			'batch_size'  => 200,
			'order'       => 12,
		);

		// #2245: PCD moved from the candidate to each notice classification.
		$this->migrations['recruitment_pcd_per_notice'] = array(
			'name'        => __( 'Recruitment: PCD per Notice', 'ffcertificate' ),
			'description' => __( 'Copy the PCD flag each candidate carried onto every one of its notice classifications, where it is now recorded, so a candidate can be PCD in one notice and general in another. A flag that can no longer be read (the WordPress salts were rotated) is recorded as general, which is what the screens already showed, and counted for review by hand.', 'ffcertificate' ),
			'icon'        => 'ffc-icon-hash',
			'batch_size'  => 200,
			'order'       => 13,
		);

		// #2242: reregistration drafts stored their sensitive values in clear
		// until the draft save encrypted them like the final submit.
		$this->migrations['rereg_draft_encryption'] = array(
			'name'        => __( 'Reregistration: Encrypt Draft Answers', 'ffcertificate' ),
			'description' => __( 'Encrypt the answers to sensitive fields (such as CPF, RF and birth date) that reregistration drafts stored in clear before drafts were saved encrypted. This covers drafts the daily run later expired and submissions returned to draft and saved again. Completion here means every stored submission was EXAMINED: one with nothing to encrypt is read, left as it is, and counted as done.', 'ffcertificate' ),
			'icon'        => 'ffc-icon-encryption',
			'batch_size'  => 100,
			'order'       => 12,
		);

		// #1574: the activity log wrote the client IP in clear until this
		// release; encrypt what older rows hold and clear the plaintext.
		$this->migrations['activity_log_encrypt_ip'] = array(
			'name'        => __( 'Activity Log: Encrypt Client IPs', 'ffcertificate' ),
			'description' => __( 'Encrypt the client IP address of activity log rows written before addresses were stored encrypted, and clear the plaintext copy. An address that cannot be encrypted is cleared all the same.', 'ffcertificate' ),
			'icon'        => 'ffc-icon-encryption',
			'batch_size'  => 200,
			'order'       => 4,
		);

		// #2109: the rate-limit log wrote the request address in clear and
		// used it as the identifier of IP rows; encrypt it and re-key them.
		$this->migrations['rate_limit_logs_encrypt_ip'] = array(
			'name'        => __( 'Rate Limit Log: Encrypt Addresses', 'ffcertificate' ),
			'description' => __( 'Encrypt the request address of rate limit log rows written before addresses were stored encrypted, clear the plaintext copy, and identify blocked addresses by a keyed fingerprint instead of the address. An address that cannot be encrypted is cleared all the same. The log keeps 30 days by default, so the plaintext would also leave on its own.', 'ffcertificate' ),
			'icon'        => 'ffc-icon-encryption',
			'batch_size'  => 200,
			'order'       => 4,
		);

		// #2343: e-mail and CPF identifiers of the rate-limit tables were an
		// unkeyed sha256, which a list of every CPF reverses; delete them.
		$this->migrations['rate_limit_keyed_identifiers'] = array(
			'name'        => __( 'Rate Limit: Keyed Identifiers', 'ffcertificate' ),
			'description' => __( 'Delete the rate limit counters and log lines that identify an e-mail address or a CPF/RF by an unkeyed hash, which can be reversed by trying every CPF. New rows use a keyed hash. A hash cannot be re-keyed, so the old rows are deleted; they are short-lived anyway, and the counters they hold start again from zero once.', 'ffcertificate' ),
			'icon'        => 'ffc-icon-encryption',
			'batch_size'  => 500,
			'order'       => 4,
		);

		// #2040: area checks logged the visitor's coordinates as debug rows;
		// delete them now that the check logs its decision only.
		$this->migrations['activity_log_location_cleanup'] = array(
			'name'        => __( 'Activity Log: Delete Logged Locations', 'ffcertificate' ),
			'description' => __( 'Delete the "IP Geolocation Debug" activity log entries. Until this release every geofence area check wrote the visitor\'s position there: the exact GPS position of each audience check-in, and the position each IP address resolved to. The entries are debug output with no audit value. A check now logs its decision only, and only while geolocation debugging is on.', 'ffcertificate' ),
			'icon'        => 'ffc-icon-map-pin-x',
			'batch_size'  => 500,
			'order'       => 4,
		);

		// #2109: a geofence refusal copied the visitor's address in clear
		// into its context; the row's own column already holds it encrypted.
		$this->migrations['activity_log_access_denied_ip'] = array(
			'name'        => __( 'Activity Log: Clear Refused Addresses', 'ffcertificate' ),
			'description' => __( 'Remove the visitor\'s address from the details of "Access Denied" activity log entries. Until this release a geofence refusal copied it there in clear; the entry\'s own address column keeps it, encrypted.', 'ffcertificate' ),
			'icon'        => 'ffc-icon-log-clean',
			'batch_size'  => 500,
			'order'       => 4,
		);

		// Allow plugins to add custom migrations.
		$this->migrations = apply_filters( 'ffcertificate_migrations_registry', $this->migrations );
	}

	/**
	 * Get all registered migrations
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_all_migrations(): array {
		return $this->migrations;
	}

	/**
	 * Get a specific migration definition
	 *
	 * @param string $migration_key Migration identifier.
	 * @return array<string, mixed>|null Migration definition or null if not found
	 */
	public function get_migration( string $migration_key ) {
		return isset( $this->migrations[ $migration_key ] ) ? $this->migrations[ $migration_key ] : null;
	}

	/**
	 * Check if a migration exists
	 *
	 * @param string $migration_key Migration identifier.
	 * @return bool
	 */
	public function exists( string $migration_key ): bool {
		return isset( $this->migrations[ $migration_key ] );
	}

	/**
	 * Check if a migration is available to run
	 *
	 * The gate the Migrations tab consults before asking for a status, and the
	 * one {@see \FreeFormCertificate\Admin\MigrationActionsAjaxEndpoint}
	 * consults before running anything.
	 *
	 * @param string $migration_key Migration identifier.
	 * @return bool
	 */
	public function is_available( string $migration_key ): bool {
		return $this->exists( $migration_key );
	}
}

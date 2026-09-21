<?php
/**
 * MigrationRegistry
 *
 * Centralized registry for all available migrations.
 * Separates configuration from execution logic.
 *
 * @package FreeFormCertificate\Migrations
 * @since 3.1.0 (Extracted from FFC_Migration_Manager v3.1.0 refactor)
 * @version 5.0.0 - Retired 10 completed migrations, kept only split_cpf_rf
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
	 * V5.0.0: Retired 10 completed migrations. Only split_cpf_rf remains
	 * as it is still needed for legacy records with combined cpf_rf_hash.
	 *
	 * @return void
	 */
	private function register_migrations(): void {
		$this->migrations = array();

		// v5.0.0: CPF/RF split migration (only active migration).
		$this->migrations['split_cpf_rf'] = array(
			'name'        => __( 'Split CPF/RF', 'ffcertificate' ),
			'description' => __( 'Separate combined CPF/RF column into individual CPF and RF columns', 'ffcertificate' ),
			'icon'        => 'ffc-icon-id',
			'batch_size'  => 50,
			'order'       => 1,
		);

		// v5.3.1: Rehash legacy unsalted email_hash values in submissions and appointments.
		$this->migrations['email_hash_rehash'] = array(
			'name'        => __( 'Rehash Email Lookup Hashes', 'ffcertificate' ),
			'description' => __( 'Recompute email_hash with the salted Encryption::hash() so lookups match cross-table writes.', 'ffcertificate' ),
			'icon'        => 'ffc-icon-shield',
			'batch_size'  => 100,
			'order'       => 2,
		);

		// v6.19.0 (#857 S7b): Re-encrypt submissions + appointments PII under a
		// newly-defined FFC_ENCRYPTION_KEY and rebuild search hashes under
		// FFC_HASH_SALT. Only runnable once the key is decoupled in wp-config.php.
		$this->migrations['key_rotation'] = array(
			'name'        => __( 'Encryption Key Rotation', 'ffcertificate' ),
			'description' => __( 'Re-encrypt stored personal data (submissions and appointments) under a strong FFC_ENCRYPTION_KEY defined in wp-config.php, rebuilding CPF/RF/email search hashes. Define the key first (Settings → Advanced → Encryption Key Health); run during low traffic, as hash-based lookups may transiently miss un-migrated rows until it completes.', 'ffcertificate' ),
			'icon'        => 'ffc-icon-shield',
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
			'icon'        => 'ffc-icon-shield',
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
			'icon'        => 'ffc-icon-shield',
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
			'icon'        => 'ffc-icon-id',
			'batch_size'  => 50,
			'order'       => 7,
		);

		// v6.29.0 (#1345): repair accounts that own a certificate and hold no
		// capability to read it. Ordered AFTER the identity cards because it
		// is their consequence -- adoption is what linked these rows, and the
		// grant it should have carried is what this card supplies.
		$this->migrations['certificate_capability_backfill'] = array(
			'name'        => __( 'Restore Access to Owned Certificates', 'ffcertificate' ),
			'description' => __( 'Grant the certificate capabilities to every account that owns a certificate and cannot open it. The permission used to come from whichever path created the account, while ownership comes from the record itself — so a person whose old submissions were claimed after their account was created by a candidacy, a reregistration import or an appointment ended up holding certificates the dashboard refused to show them. Measures the remaining accounts on every read, so running it again once it reports zero does nothing.', 'ffcertificate' ),
			'icon'        => 'ffc-icon-shield',
			'batch_size'  => 100,
			'order'       => 8,
		);

		// v5.4.1: Clear plaintext context on activity log rows that already
		// hold a ciphertext, eliminating the dual-storage leak.
		$this->migrations['activity_log_clear_plaintext'] = array(
			'name'        => __( 'Activity Log: Clear Plaintext on Encrypted Rows', 'ffcertificate' ),
			'description' => __( 'NULL the plaintext context column on activity log rows that already store the JSON in context_encrypted.', 'ffcertificate' ),
			'icon'        => 'ffc-icon-shield',
			'batch_size'  => 200,
			'order'       => 3,
		);

		// v6.18.0 (#865): import certificate layouts left in the legacy `html/`
		// drop-folder into the database-backed template pool, then retire the glob.
		$this->migrations['import_legacy_templates'] = array(
			'name'        => __( 'Import Legacy Certificate Templates', 'ffcertificate' ),
			'description' => __( 'Import certificate layouts left in the plugin\'s html/ drop-folder into the reusable template pool (Certificate → Templates). Non-destructive and idempotent: shipped defaults are skipped and each file is imported once.', 'ffcertificate' ),
			'icon'        => 'ffc-icon-scroll',
			'batch_size'  => 20,
			'order'       => 5,
		);

		// v6.18.0 (#865): move images referenced from the legacy `html/` folder
		// into the Media Library and rewrite stored form layouts / backgrounds /
		// pool templates to point at the new attachments.
		$this->migrations['rewrite_html_image_refs'] = array(
			'name'        => __( 'Rewrite html/ Image References', 'ffcertificate' ),
			'description' => __( 'Move images referenced from the legacy html/ folder into the Media Library and update stored certificate layouts, backgrounds and template-pool bodies to point at the new attachments. Idempotent; missing files are reported as errors.', 'ffcertificate' ),
			'icon'        => 'ffc-icon-palette',
			'batch_size'  => 10,
			'order'       => 6,
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
	 * @param string $migration_key Migration identifier.
	 * @return bool
	 */
	public function is_available( string $migration_key ): bool {
		return $this->exists( $migration_key );
	}
}

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
			'name'            => __( 'Split CPF/RF', 'ffcertificate' ),
			'description'     => __( 'Separate combined CPF/RF column into individual CPF and RF columns', 'ffcertificate' ),
			'icon'            => 'ffc-icon-id',
			'batch_size'      => 50,
			'order'           => 1,
			'requires_column' => true,
		);

		// v5.3.1: Rehash legacy unsalted email_hash values in submissions and appointments.
		$this->migrations['email_hash_rehash'] = array(
			'name'            => __( 'Rehash Email Lookup Hashes', 'ffcertificate' ),
			'description'     => __( 'Recompute email_hash with the salted Encryption::hash() so lookups match cross-table writes.', 'ffcertificate' ),
			'icon'            => 'ffc-icon-shield',
			'batch_size'      => 100,
			'order'           => 2,
			'requires_column' => false,
		);

		// v6.19.0 (#857 S7b): Re-encrypt submissions + appointments PII under a
		// newly-defined FFC_ENCRYPTION_KEY and rebuild search hashes under
		// FFC_HASH_SALT. Only runnable once the key is decoupled in wp-config.php.
		$this->migrations['key_rotation'] = array(
			'name'            => __( 'Encryption Key Rotation', 'ffcertificate' ),
			'description'     => __( 'Re-encrypt stored personal data (submissions and appointments) under a strong FFC_ENCRYPTION_KEY defined in wp-config.php, rebuilding CPF/RF/email search hashes. Define the key first (Settings → Advanced → Encryption Key Health); run during low traffic, as hash-based lookups may transiently miss un-migrated rows until it completes.', 'ffcertificate' ),
			'icon'            => 'ffc-icon-shield',
			'batch_size'      => 100,
			'order'           => 4,
			'requires_column' => false,
		);

		// v5.4.1: Clear plaintext context on activity log rows that already
		// hold a ciphertext, eliminating the dual-storage leak.
		$this->migrations['activity_log_clear_plaintext'] = array(
			'name'            => __( 'Activity Log: Clear Plaintext on Encrypted Rows', 'ffcertificate' ),
			'description'     => __( 'NULL the plaintext context column on activity log rows that already store the JSON in context_encrypted.', 'ffcertificate' ),
			'icon'            => 'ffc-icon-shield',
			'batch_size'      => 200,
			'order'           => 3,
			'requires_column' => false,
		);

		// v6.18.0 (#865): import certificate layouts left in the legacy `html/`
		// drop-folder into the database-backed template pool, then retire the glob.
		$this->migrations['import_legacy_templates'] = array(
			'name'            => __( 'Import Legacy Certificate Templates', 'ffcertificate' ),
			'description'     => __( 'Import certificate layouts left in the plugin\'s html/ drop-folder into the reusable template pool (Certificate → Templates). Non-destructive and idempotent: shipped defaults are skipped and each file is imported once.', 'ffcertificate' ),
			'icon'            => 'ffc-icon-scroll',
			'batch_size'      => 20,
			'order'           => 5,
			'requires_column' => false,
		);

		// v6.18.0 (#865): move images referenced from the legacy `html/` folder
		// into the Media Library and rewrite stored form layouts / backgrounds /
		// pool templates to point at the new attachments.
		$this->migrations['rewrite_html_image_refs'] = array(
			'name'            => __( 'Rewrite html/ Image References', 'ffcertificate' ),
			'description'     => __( 'Move images referenced from the legacy html/ folder into the Media Library and update stored certificate layouts, backgrounds and template-pool bodies to point at the new attachments. Idempotent; missing files are reported as errors.', 'ffcertificate' ),
			'icon'            => 'ffc-icon-palette',
			'batch_size'      => 10,
			'order'           => 6,
			'requires_column' => false,
		);

		// Allow plugins to add custom migrations.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- ffcertificate is the plugin prefix
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

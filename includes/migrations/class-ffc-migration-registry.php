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
 * @version 3.2.0 - Migrated to namespace (Phase 2)
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

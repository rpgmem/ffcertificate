<?php
/**
 * ActivityLogEncryptIpMigrationStrategy
 *
 * The activity log wrote the client IP in clear to `user_ip` until #1574.
 * New rows store it only in `user_ip_encrypted`; this strategy brings the
 * older rows in line: it encrypts each plaintext address into
 * `user_ip_encrypted` and NULLs `user_ip`.
 *
 * Termination does not depend on a cursor (CLAUDE.md "Batched migrations"):
 * the batch selects rows whose `user_ip` is set and its write NULLs exactly
 * that column, so a processed row leaves the predicate, and the batch counts
 * the rows the UPDATE actually changed. An address that cannot be encrypted
 * is cleared all the same -- dropping it is the safe outcome, a plaintext IP
 * left behind is not.
 *
 * @package FreeFormCertificate\Migrations\Strategies
 * @since 6.34.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Migrations\Strategies;

use FreeFormCertificate\Core\ArrayValue;
use FreeFormCertificate\Core\Encryption;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Data statements against the plugin's own ffc_activity_log table during a migration. WordPress exposes no API for it, and there is nothing to cache on this path.
/**
 * Strategy that encrypts the client IP of older activity log rows.
 */
class ActivityLogEncryptIpMigrationStrategy implements MigrationStrategyInterface {

	use \FreeFormCertificate\Core\DatabaseHelperTrait;

	/**
	 * Activity log table.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'ffc_activity_log';
	}

	/**
	 * Whether the table carries both columns this strategy reads and writes.
	 *
	 * @return bool
	 */
	private function schema_ready(): bool {
		return self::table_exists( $this->table )
			&& self::column_exists( $this->table, 'user_ip' )
			&& self::column_exists( $this->table, 'user_ip_encrypted' );
	}

	/**
	 * Calculate migration status.
	 *
	 * @param string               $migration_key Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @return array<string, mixed>
	 */
	public function calculate_status( string $migration_key, array $migration_config ): array {
		if ( ! $this->schema_ready() ) {
			return array(
				'total'       => 0,
				'migrated'    => 0,
				'pending'     => 0,
				'percent'     => 100,
				'is_complete' => true,
			);
		}

		global $wpdb;

		$pending = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE user_ip IS NOT NULL AND user_ip <> ''",
				$this->table
			)
		);

		$migrated = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE user_ip_encrypted IS NOT NULL AND user_ip_encrypted <> ''",
				$this->table
			)
		);

		$total   = $pending + $migrated;
		$percent = $total > 0 ? ( $migrated / $total ) * 100 : 100;

		return array(
			'total'       => $total,
			'migrated'    => $migrated,
			'pending'     => $pending,
			'percent'     => round( $percent, 2 ),
			'is_complete' => 0 === $pending,
		);
	}

	/**
	 * Execute one batch.
	 *
	 * @param string               $migration_key Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @param int                  $batch_number Batch number (unused -- the predicate drives progress).
	 * @return array<string, mixed>
	 */
	public function execute( string $migration_key, array $migration_config, int $batch_number = 0 ): array {
		$batch_size = max( 1, ArrayValue::int( $migration_config, 'batch_size', 200 ) );

		if ( ! $this->schema_ready() ) {
			return array(
				'success'   => true,
				'processed' => 0,
				'has_more'  => false,
				'message'   => __( 'Activity log table is not available — nothing to migrate.', 'ffcertificate' ),
				'errors'    => array(),
			);
		}

		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_ip FROM %i WHERE user_ip IS NOT NULL AND user_ip <> '' ORDER BY id ASC LIMIT %d",
				$this->table,
				$batch_size
			),
			ARRAY_A
		);

		/**
		 * Rows as `$wpdb` hands them back, checked against the SELECT above.
		 *
		 * @var list<array{id: numeric-string, user_ip: string}>|null $rows
		 */
		$changed = 0;
		$errors  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$ip         = ArrayValue::string( $row, 'user_ip' );
			$ciphertext = Encryption::is_configured() ? Encryption::encrypt( $ip ) : null;

			$updated = $wpdb->update(
				$this->table,
				array(
					'user_ip_encrypted' => $ciphertext,
					'user_ip'           => null,
				),
				array( 'id' => ArrayValue::int( $row, 'id' ) ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			if ( false === $updated ) {
				$errors[] = $wpdb->last_error;
				continue;
			}
			$changed += (int) $updated;
		}

		$status = $this->calculate_status( $migration_key, $migration_config );

		return array(
			'success'   => empty( $errors ),
			'processed' => $changed,
			// Only a batch that changed something may ask for another: one
			// that changed nothing would select the same rows forever.
			'has_more'  => $changed > 0 && $status['pending'] > 0,
			/* translators: %d: number of activity log rows whose client IP was encrypted. */
			'message'   => sprintf( __( 'Encrypted the client IP of %d activity log rows.', 'ffcertificate' ), $changed ),
			'errors'    => $errors,
		);
	}

	/**
	 * Check whether the migration can run.
	 *
	 * @param string               $migration_key Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @return bool|WP_Error
	 */
	public function can_run( string $migration_key, array $migration_config ) {
		if ( ! Encryption::is_configured() ) {
			return new WP_Error(
				'encryption_not_configured',
				__( 'Encryption keys are not configured, so the addresses cannot be encrypted.', 'ffcertificate' )
			);
		}
		return true;
	}

	/**
	 * Strategy name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Activity Log: Encrypt Client IPs', 'ffcertificate' );
	}
}

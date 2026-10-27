<?php
/**
 * ActivityLogAccessDeniedIpMigrationStrategy
 *
 * Until #2109 a geofence refusal wrote an `access_denied` activity-log row
 * whose context carried the visitor's address in clear, under the key
 * `identifier`. The row's own address column holds the same address,
 * encrypted, so the copy in the context added nothing but a plaintext IP.
 * A refusal now logs its reason only; this strategy removes the key from
 * the rows written before.
 *
 * Termination does not depend on a cursor (CLAUDE.md "Batched migrations"):
 * a row is selected while its context carries the key, its write removes
 * it, and the batch counts the rows the UPDATE changed. A context that does
 * not decode is cleared to NULL rather than left behind, because the key
 * can only be removed from a document that can be read.
 *
 * @package FreeFormCertificate\Migrations\Strategies
 * @since 6.43.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Migrations\Strategies;

use FreeFormCertificate\Core\ArrayValue;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Data statements against the plugin's own ffc_activity_log table during a migration. WordPress exposes no API for it, and there is nothing to cache on this path.
/**
 * Strategy that removes the plaintext address from older refusal rows.
 */
class ActivityLogAccessDeniedIpMigrationStrategy implements MigrationStrategyInterface {

	use \FreeFormCertificate\Core\DatabaseHelperTrait;

	/**
	 * The action a refusal is logged under.
	 */
	public const ACTION = 'access_denied';

	/**
	 * The context key that carried the address.
	 */
	public const KEY = 'identifier';

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
	 * Whether the table carries the columns this strategy reads and writes.
	 *
	 * @return bool
	 */
	private function schema_ready(): bool {
		return self::table_exists( $this->table ) && self::column_exists( $this->table, 'context' );
	}

	/**
	 * Calculate migration status.
	 *
	 * @param string               $migration_key Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @return array<string, mixed>
	 */
	public function calculate_status( string $migration_key, array $migration_config ): array {
		$pending = $this->pending();

		return array(
			'total'       => $pending,
			'migrated'    => 0,
			'pending'     => $pending,
			'percent'     => 0 === $pending ? 100 : 0,
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
		$batch_size = max( 1, ArrayValue::int( $migration_config, 'batch_size', 500 ) );

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
				'SELECT id, context FROM %i WHERE action = %s AND context LIKE %s ORDER BY id ASC LIMIT %d',
				$this->table,
				self::ACTION,
				$this->key_pattern(),
				$batch_size
			),
			ARRAY_A
		);

		$changed = 0;
		$errors  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$updated = $wpdb->update(
				$this->table,
				array( 'context' => $this->without_key( ArrayValue::string( $row, 'context' ) ) ),
				array( 'id' => ArrayValue::int( $row, 'id' ) ),
				array( '%s' ),
				array( '%d' )
			);

			if ( false === $updated ) {
				$errors[] = $wpdb->last_error;
				continue;
			}
			$changed += (int) $updated;
		}

		return array(
			'success'   => empty( $errors ),
			'processed' => $changed,
			// Only a batch that changed something may ask for another: one
			// that changed nothing would select the same rows forever (#1378).
			'has_more'  => $changed > 0 && $this->pending() > 0,
			/* translators: %d: number of activity log entries whose plaintext address was removed. */
			'message'   => sprintf( __( 'Removed the plaintext address from %d access denied entries.', 'ffcertificate' ), $changed ),
			'errors'    => $errors,
		);
	}

	/**
	 * The context without the address key, or NULL when it does not decode.
	 *
	 * @param string $context Context JSON as stored.
	 * @return string|null
	 */
	private function without_key( string $context ): ?string {
		$decoded = json_decode( $context, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		unset( $decoded[ self::KEY ] );
		$encoded = wp_json_encode( $decoded );
		return false === $encoded ? null : $encoded;
	}

	/**
	 * LIKE pattern for a context that carries the key.
	 *
	 * @return string
	 */
	private function key_pattern(): string {
		global $wpdb;
		return '%' . $wpdb->esc_like( '"' . self::KEY . '"' ) . '%';
	}

	/**
	 * Rows still carrying the address.
	 *
	 * @return int
	 */
	private function pending(): int {
		if ( ! $this->schema_ready() ) {
			return 0;
		}

		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE action = %s AND context LIKE %s',
				$this->table,
				self::ACTION,
				$this->key_pattern()
			)
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
		if ( ! self::table_exists( $this->table ) ) {
			return new WP_Error(
				'activity_log_table_missing',
				__( 'Activity log table is missing — re-activate the plugin to create it.', 'ffcertificate' )
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
		return __( 'Activity Log: Clear Refused Addresses', 'ffcertificate' );
	}
}

<?php
/**
 * ActivityLogClearPlaintextMigrationStrategy
 *
 * After commit 886464c (item 4) and the dual-storage fix that follows,
 * sensitive activity log rows must hold the JSON in `context_encrypted`
 * with `context` NULL. Earlier rows wrote the encrypted column alongside
 * the plaintext one, defeating the encryption.
 *
 * This strategy walks `wp_ffc_activity_log`, finds rows where both the
 * encrypted and the plaintext columns are populated, and NULLs out the
 * plaintext column. The encrypted payload is left untouched and remains
 * the source of truth for reads via ActivityLogQuery::resolve_context().
 *
 * Idempotent: subsequent runs find zero such rows. Cursor-based progress
 * stored in a single option so the job can resume after interruption.
 *
 * @package FreeFormCertificate\Migrations\Strategies
 * @since 5.4.1
 */

declare(strict_types=1);

namespace FreeFormCertificate\Migrations\Strategies;

use Exception;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

/**
 * Strategy for clearing the plaintext context on encrypted activity log rows.
 */
class ActivityLogClearPlaintextMigrationStrategy implements MigrationStrategyInterface {

	use \FreeFormCertificate\Core\DatabaseHelperTrait;

	/**
	 * Option key for the cursor (last processed id).
	 */
	private const CURSOR_OPTION = 'ffc_activity_log_clear_plaintext_cursor';

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
	 * Calculate migration status.
	 *
	 * @param string               $migration_key Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @return array<string, mixed>
	 */
	public function calculate_status( string $migration_key, array $migration_config ): array {
		if ( ! self::table_exists( $this->table )
			|| ! self::column_exists( $this->table, 'context' )
			|| ! self::column_exists( $this->table, 'context_encrypted' )
		) {
			return array(
				'total'       => 0,
				'migrated'    => 0,
				'pending'     => 0,
				'percent'     => 100,
				'is_complete' => true,
			);
		}

		global $wpdb;

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE context_encrypted IS NOT NULL AND context_encrypted <> ''",
				$this->table
			)
		);

		$pending = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i
				 WHERE context_encrypted IS NOT NULL AND context_encrypted <> ''
				   AND context IS NOT NULL AND context <> ''",
				$this->table
			)
		);

		$migrated = max( 0, $total - $pending );
		$percent  = $total > 0 ? ( $migrated / $total ) * 100 : 100;

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
	 * @param int                  $batch_number Batch number (unused — cursor-based).
	 * @return array<string, mixed>
	 */
	public function execute( string $migration_key, array $migration_config, int $batch_number = 0 ): array {
		$batch_size = isset( $migration_config['batch_size'] ) ? (int) $migration_config['batch_size'] : 200;

		if ( ! self::table_exists( $this->table )
			|| ! self::column_exists( $this->table, 'context' )
			|| ! self::column_exists( $this->table, 'context_encrypted' )
		) {
			return array(
				'success'   => true,
				'processed' => 0,
				'has_more'  => false,
				'message'   => __( 'Activity log table is not available — nothing to migrate.', 'ffcertificate' ),
				'errors'    => array(),
			);
		}

		global $wpdb;
		$cursor = (int) get_option( self::CURSOR_OPTION, 0 );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM %i
				 WHERE id > %d
				   AND context_encrypted IS NOT NULL AND context_encrypted <> ''
				   AND context IS NOT NULL AND context <> ''
				 ORDER BY id ASC
				 LIMIT %d",
				$this->table,
				$cursor,
				$batch_size
			)
		);

		if ( empty( $ids ) ) {
			// Either we ran past the last matching row or there were never
			// any. Either way, advance the cursor to MAX(id) so future
			// status calls don't re-scan the whole table.
			$max_id = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', $this->table )
			);
			if ( $max_id > $cursor ) {
				update_option( self::CURSOR_OPTION, $max_id, false );
			}
			return array(
				'success'   => true,
				'processed' => 0,
				'has_more'  => false,
				'message'   => __( 'Activity log plaintext leak: nothing left to clear.', 'ffcertificate' ),
				'errors'    => array(),
			);
		}

		$ids          = array_map( 'intval', (array) $ids );
		$last_id      = (int) end( $ids );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a list of %d only.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET context = NULL WHERE id IN ({$placeholders})",
				array_merge( array( $this->table ), $ids )
			)
		);

		update_option( self::CURSOR_OPTION, $last_id, false );

		$status = $this->calculate_status( $migration_key, $migration_config );

		if ( class_exists( '\\FreeFormCertificate\\Core\\ActivityLog' ) ) {
			\FreeFormCertificate\Core\ActivityLog::log(
				'activity_log_clear_plaintext_batch',
				\FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
				array(
					'updated' => (int) $updated,
					'pending' => $status['pending'],
				)
			);
		}

		return array(
			'success'   => false !== $updated,
			'processed' => false !== $updated ? (int) $updated : 0,
			'has_more'  => $status['pending'] > 0,
			/* translators: %d: number of activity log rows whose plaintext context column was nulled. */
			'message'   => sprintf( __( 'Cleared plaintext context on %d activity log rows.', 'ffcertificate' ), false !== $updated ? (int) $updated : 0 ),
			'errors'    => false === $updated ? array( $wpdb->last_error ) : array(),
		);
	}

	/**
	 * Preflight.
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
		return __( 'Activity Log: Clear Plaintext Context on Encrypted Rows', 'ffcertificate' );
	}
}

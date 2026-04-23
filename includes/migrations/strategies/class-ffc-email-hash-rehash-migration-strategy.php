<?php
/**
 * EmailHashRehashMigrationStrategy
 *
 * Rehashes email_hash columns in wp_ffc_submissions and
 * wp_ffc_self_scheduling_appointments using the salted Encryption::hash().
 *
 * Background: two code paths historically wrote email_hash with raw
 * hash('sha256', ...) (no salt):
 *   - AppointmentRepository::createAppointment (all appointments).
 *   - UserCleanup::handle_email_change (submissions reindexed on email change).
 * Both have been corrected to use Encryption::hash(), but existing rows
 * still hold the unsalted value and therefore never match lookups that use
 * the salted hash.
 *
 * The strategy walks both tables by id, decrypts email_encrypted, recomputes
 * the salted hash and updates email_hash when it differs. It is idempotent:
 * rows already correct are skipped with zero writes.
 *
 * Progress is tracked via a per-table option acting as a cursor on the row
 * id. Migration completes when the cursor reaches MAX(id) in both tables.
 *
 * @package FreeFormCertificate\Migrations\Strategies
 * @since 5.3.1
 */

declare(strict_types=1);

namespace FreeFormCertificate\Migrations\Strategies;

use Exception;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Strategy implementation for rehashing legacy email_hash values.
 */
class EmailHashRehashMigrationStrategy implements MigrationStrategyInterface {

	use \FreeFormCertificate\Core\DatabaseHelperTrait;

	/**
	 * Option prefix for the per-table cursor.
	 */
	private const CURSOR_OPTION_PREFIX = 'ffc_email_hash_rehash_cursor_';

	/**
	 * Submissions table.
	 *
	 * @var string
	 */
	private string $submissions_table;

	/**
	 * Appointments table.
	 *
	 * @var string
	 */
	private string $appointments_table;

	/**
	 * Constructor
	 */
	public function __construct() {
		global $wpdb;
		$this->submissions_table  = $wpdb->prefix . 'ffc_submissions';
		$this->appointments_table = $wpdb->prefix . 'ffc_self_scheduling_appointments';
	}

	/**
	 * Calculate migration status across both tables
	 *
	 * @param string               $migration_key Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @return array<string, mixed>
	 */
	public function calculate_status( string $migration_key, array $migration_config ): array {
		$submissions  = $this->count_table_status( $this->submissions_table );
		$appointments = $this->count_table_status( $this->appointments_table );

		$total    = $submissions['total'] + $appointments['total'];
		$migrated = $submissions['migrated'] + $appointments['migrated'];
		$pending  = $submissions['pending'] + $appointments['pending'];
		$percent  = ( $total > 0 ) ? ( $migrated / $total ) * 100 : 100;

		return array(
			'total'       => $total,
			'migrated'    => $migrated,
			'pending'     => $pending,
			'percent'     => round( $percent, 2 ),
			'is_complete' => ( 0 === $pending ),
		);
	}

	/**
	 * Execute one batch across both tables
	 *
	 * @param string               $migration_key Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @param int                  $batch_number Batch number (unused — cursor-based).
	 * @return array<string, mixed>
	 */
	public function execute( string $migration_key, array $migration_config, int $batch_number = 0 ): array {
		$batch_size = isset( $migration_config['batch_size'] ) ? (int) $migration_config['batch_size'] : 100;

		$total_processed = 0;
		$all_errors      = array();

		foreach ( array( $this->submissions_table, $this->appointments_table ) as $table ) {
			$result           = $this->process_table( $table, $batch_size );
			$total_processed += $result['processed'];
			if ( ! empty( $result['errors'] ) ) {
				$all_errors = array_merge( $all_errors, $result['errors'] );
			}
		}

		$status = $this->calculate_status( $migration_key, $migration_config );

		if ( class_exists( '\\FreeFormCertificate\\Core\\ActivityLog' ) ) {
			\FreeFormCertificate\Core\ActivityLog::log(
				'email_hash_rehash_migration_batch',
				\FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
				array(
					'processed' => $total_processed,
					'errors'    => count( $all_errors ),
					'pending'   => $status['pending'],
				)
			);
		}

		return array(
			'success'   => 0 === count( $all_errors ),
			'processed' => $total_processed,
			'has_more'  => $status['pending'] > 0,
			/* translators: %d: number of records rehashed */
			'message'   => sprintf( __( 'Rehashed email_hash for %d records', 'ffcertificate' ), $total_processed ),
			'errors'    => $all_errors,
		);
	}

	/**
	 * Preflight check
	 *
	 * @param string               $migration_key Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @return bool|WP_Error
	 */
	public function can_run( string $migration_key, array $migration_config ) {
		if ( ! class_exists( '\\FreeFormCertificate\\Core\\Encryption' ) ) {
			return new WP_Error(
				'encryption_class_missing',
				__( 'Encryption class not found. Required for email hash rehash migration.', 'ffcertificate' )
			);
		}

		if ( ! \FreeFormCertificate\Core\Encryption::is_configured() ) {
			return new WP_Error(
				'encryption_not_configured',
				__( 'Encryption keys not configured. Required for email hash rehash migration.', 'ffcertificate' )
			);
		}

		return true;
	}

	/**
	 * Strategy name
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Email Hash Rehash', 'ffcertificate' );
	}

	/**
	 * Count status for a single table
	 *
	 * - total:    rows with encrypted email present.
	 * - migrated: rows with id <= cursor (already walked).
	 * - pending:  total - migrated.
	 *
	 * @param string $table Table name.
	 * @return array{total: int, migrated: int, pending: int}
	 */
	private function count_table_status( string $table ): array {
		global $wpdb;

		if ( ! self::table_exists( $table ) || ! self::column_exists( $table, 'email_hash' ) || ! self::column_exists( $table, 'email_encrypted' ) ) {
			return array(
				'total'    => 0,
				'migrated' => 0,
				'pending'  => 0,
			);
		}

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE email_encrypted IS NOT NULL AND email_encrypted <> ''",
				$table
			)
		);

		$cursor = $this->get_cursor( $table );

		$migrated = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE id <= %d AND email_encrypted IS NOT NULL AND email_encrypted <> ''",
				$table,
				$cursor
			)
		);

		$pending = max( 0, $total - $migrated );

		return array(
			'total'    => $total,
			'migrated' => $migrated,
			'pending'  => $pending,
		);
	}

	/**
	 * Process a batch from a single table
	 *
	 * Walks rows above the stored cursor, decrypts email, recomputes salted
	 * hash, updates only when it differs.
	 *
	 * @param string $table Table name.
	 * @param int    $batch_size Max rows in this batch.
	 * @return array{processed: int, errors: string[]}
	 */
	private function process_table( string $table, int $batch_size ): array {
		global $wpdb;

		$errors    = array();
		$processed = 0;

		if ( ! self::table_exists( $table ) || ! self::column_exists( $table, 'email_hash' ) || ! self::column_exists( $table, 'email_encrypted' ) ) {
			return array(
				'processed' => 0,
				'errors'    => array(),
			);
		}

		$cursor = $this->get_cursor( $table );

		$records = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, email_encrypted, email_hash FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d',
				$table,
				$cursor,
				$batch_size
			),
			ARRAY_A
		);

		if ( empty( $records ) ) {
			// Nothing above cursor — mark as complete by advancing to MAX(id).
			$max_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', $table ) );
			if ( $max_id > $cursor ) {
				$this->set_cursor( $table, $max_id );
			}
			return array(
				'processed' => 0,
				'errors'    => array(),
			);
		}

		$last_id = $cursor;

		foreach ( $records as $record ) {
			$last_id = (int) $record['id'];

			try {
				$encrypted = (string) ( $record['email_encrypted'] ?? '' );
				if ( '' === $encrypted ) {
					continue;
				}

				$plain = \FreeFormCertificate\Core\Encryption::decrypt( $encrypted );
				if ( null === $plain || '' === $plain ) {
					$errors[] = sprintf( 'Could not decrypt email for ID %d in %s', (int) $record['id'], $table );
					continue;
				}

				// Hash the same value that gets encrypted (no normalization), matching
				// SubmissionHandler and the post-fix AppointmentRepository convention.
				$correct_hash = \FreeFormCertificate\Core\Encryption::hash( $plain );
				if ( null === $correct_hash ) {
					continue;
				}

				$current_hash = (string) ( $record['email_hash'] ?? '' );
				if ( hash_equals( $correct_hash, $current_hash ) ) {
					// Already correct — idempotent no-op.
					continue;
				}

				$updated = $wpdb->update(
					$table,
					array( 'email_hash' => $correct_hash ),
					array( 'id' => (int) $record['id'] ),
					array( '%s' ),
					array( '%d' )
				);

				if ( false === $updated ) {
					$errors[] = sprintf(
						'Failed to update email_hash for ID %d in %s: %s',
						(int) $record['id'],
						$table,
						$wpdb->last_error
					);
					continue;
				}

				++$processed;
			} catch ( Exception $e ) {
				$errors[] = sprintf(
					'Error processing ID %d in %s: %s',
					(int) $record['id'],
					$table,
					$e->getMessage()
				);
			}
		}

		// Advance cursor to the last id seen in this batch, regardless of whether
		// the row needed a write. Rows with null encrypted email are also skipped.
		$this->set_cursor( $table, $last_id );

		return array(
			'processed' => $processed,
			'errors'    => $errors,
		);
	}

	/**
	 * Read the per-table cursor
	 *
	 * @param string $table Table name.
	 * @return int
	 */
	private function get_cursor( string $table ): int {
		return (int) get_option( self::CURSOR_OPTION_PREFIX . $table, 0 );
	}

	/**
	 * Store the per-table cursor
	 *
	 * @param string $table Table name.
	 * @param int    $id Cursor value.
	 * @return void
	 */
	private function set_cursor( string $table, int $id ): void {
		update_option( self::CURSOR_OPTION_PREFIX . $table, $id, false );
	}
}

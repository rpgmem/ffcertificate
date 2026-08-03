<?php
/**
 * KeyRotationMigrationStrategy
 *
 * S7b encryption-key rotation (Architecture A). Re-encrypts every PII column in
 * wp_ffc_submissions and wp_ffc_self_scheduling_appointments under the ACTIVE
 * encryption key (FFC_ENCRYPTION_KEY) and rebuilds the searchable cpf_hash /
 * rf_hash / email_hash under the active salt (FFC_HASH_SALT), reading legacy
 * ciphertexts through the WP-derived-key fallback baked into
 * {@see \FreeFormCertificate\Core\Encryption::decrypt()}.
 *
 * Flow (see #857 / #840 Part A):
 *   1. The operator defines strong FFC_ENCRYPTION_KEY (and optionally
 *      FFC_HASH_SALT) in wp-config.php — surfaced from the S7a health card.
 *   2. New writes immediately use the custom key; existing rows stay readable
 *      via the decrypt() fallback (zero downtime).
 *   3. This migration walks both tables once, decrypting each PII column and
 *      re-encrypting it under the active key, so the site can eventually stop
 *      depending on the WordPress salts for these two tables.
 *
 * Guard-rails:
 *   - Keyed on the active key's fingerprint ({@see Encryption::key_fingerprint()}).
 *     If the operator changes the key again, the stored fingerprint no longer
 *     matches and the migration re-arms (reports pending) instead of silently
 *     claiming "complete".
 *   - A row whose ciphertext cannot be decrypted even with the fallback (the
 *     forbidden "WP salts were also changed mid-rotation" case) is reported as
 *     an error and left UNTOUCHED — the migration never overwrites a column it
 *     could not read, so data is never orphaned in silence.
 *
 * Progress is cursor-based per table (same shape as EmailHashRehash): a row id
 * cursor advances through the batch; completion latches once both tables are
 * fully walked under the current fingerprint.
 *
 * NOTE: only submissions + appointments are rotated here — the plugin's core
 * certificate PII. Other encrypted stores (recruitment candidates, user
 * profiles, the reregistration `data` JSON, activity-log context) remain
 * readable via the permanent WP-derived fallback and are a tracked follow-up;
 * do not drop the WordPress salts until those are rotated too.
 *
 * @package FreeFormCertificate\Migrations\Strategies
 * @since 6.19.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Migrations\Strategies;

use Exception;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strategy implementation for the S7b encryption-key rotation.
 */
class KeyRotationMigrationStrategy implements MigrationStrategyInterface {

	use \FreeFormCertificate\Core\DatabaseHelperTrait;

	/**
	 * Option holding the rotation state:
	 * array{ fingerprint: string, cursor: array<string,int>, completed: bool }.
	 *
	 * Autoloaded=false — read only on the migrations admin surface / AJAX batch.
	 */
	private const STATE_OPTION = 'ffc_key_rotation_state';

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
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->submissions_table  = $wpdb->prefix . 'ffc_submissions';
		$this->appointments_table = $wpdb->prefix . 'ffc_self_scheduling_appointments';
	}

	/**
	 * Encrypted-column → searchable-hash-column map per table. A null hash means
	 * the column is encrypted but not independently searchable (no hash to
	 * rebuild). Columns absent from the live schema are filtered out at runtime.
	 *
	 * @param string $table Table name.
	 * @return array<string, string|null>
	 */
	private function field_map( string $table ): array {
		if ( $table === $this->submissions_table ) {
			return array(
				'email_encrypted'   => 'email_hash',
				'cpf_encrypted'     => 'cpf_hash',
				'rf_encrypted'      => 'rf_hash',
				'user_ip_encrypted' => null,
				'data_encrypted'    => null,
			);
		}

		if ( $table === $this->appointments_table ) {
			return array(
				'email_encrypted'       => 'email_hash',
				'cpf_encrypted'         => 'cpf_hash',
				'rf_encrypted'          => 'rf_hash',
				'phone_encrypted'       => null,
				'user_ip_encrypted'     => null,
				'custom_data_encrypted' => null,
			);
		}

		return array();
	}

	/**
	 * The encrypted columns that actually exist on $table.
	 *
	 * @param string $table Table name.
	 * @return array<int, string>
	 */
	private function existing_encrypted_columns( string $table ): array {
		$cols = array();
		foreach ( array_keys( $this->field_map( $table ) ) as $enc_col ) {
			if ( self::column_exists( $table, $enc_col ) ) {
				$cols[] = $enc_col;
			}
		}
		return $cols;
	}

	/**
	 * Calculate migration status across both tables.
	 *
	 * @param string               $migration_key    Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @return array<string, mixed>
	 */
	public function calculate_status( string $migration_key, array $migration_config ): array {
		$submissions  = $this->count_table_status( $this->submissions_table );
		$appointments = $this->count_table_status( $this->appointments_table );

		$total = $submissions['total'] + $appointments['total'];

		// If the active key changed since the last run (or never ran), the whole
		// dataset is pending under the current fingerprint.
		if ( ! $this->fingerprint_matches() ) {
			return array(
				'total'       => $total,
				'migrated'    => 0,
				'pending'     => $total,
				'percent'     => ( $total > 0 ) ? 0.0 : 100.0,
				'is_complete' => ( 0 === $total ),
			);
		}

		if ( $this->is_completed() ) {
			return array(
				'total'       => $total,
				'migrated'    => $total,
				'pending'     => 0,
				'percent'     => 100.0,
				'is_complete' => true,
			);
		}

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
	 * Execute one batch across both tables.
	 *
	 * @param string               $migration_key    Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @param int                  $batch_number     Batch number (unused — cursor-based).
	 * @return array<string, mixed>
	 */
	public function execute( string $migration_key, array $migration_config, int $batch_number = 0 ): array {
		$batch_size = isset( $migration_config['batch_size'] ) ? (int) $migration_config['batch_size'] : 100;

		// Re-arm the cursor + completion flag whenever the active key changed
		// since the last run: the dataset must be re-rotated onto the new key.
		$this->sync_fingerprint();

		if ( $this->is_completed() ) {
			return array(
				'success'   => true,
				'processed' => 0,
				'has_more'  => false,
				'message'   => __( 'Encryption key rotation already completed.', 'ffcertificate' ),
				'errors'    => array(),
			);
		}

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

		if ( 0 === $status['pending'] && empty( $all_errors ) ) {
			$this->mark_completed();
			$status['is_complete'] = true;
		}

		if ( class_exists( '\\FreeFormCertificate\\Core\\ActivityLog' ) ) {
			\FreeFormCertificate\Core\ActivityLog::log(
				'key_rotation_migration_batch',
				\FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
				array(
					'processed'   => $total_processed,
					'errors'      => count( $all_errors ),
					'pending'     => $status['pending'],
					'fingerprint' => $this->active_fingerprint(),
				)
			);
		}

		return array(
			'success'   => 0 === count( $all_errors ),
			'processed' => $total_processed,
			'has_more'  => $status['pending'] > 0,
			/* translators: %d: number of records re-encrypted */
			'message'   => sprintf( __( 'Re-encrypted %d records under the active key', 'ffcertificate' ), $total_processed ),
			'errors'    => $all_errors,
		);
	}

	/**
	 * Preflight check.
	 *
	 * @param string               $migration_key    Migration identifier.
	 * @param array<string, mixed> $migration_config Migration configuration.
	 * @return bool|WP_Error
	 */
	public function can_run( string $migration_key, array $migration_config ) {
		if ( ! class_exists( '\\FreeFormCertificate\\Core\\Encryption' ) ) {
			return new WP_Error(
				'encryption_class_missing',
				__( 'Encryption class not found. Required for key rotation.', 'ffcertificate' )
			);
		}

		if ( ! \FreeFormCertificate\Core\Encryption::is_configured() ) {
			return new WP_Error(
				'encryption_not_configured',
				__( 'Encryption keys not configured. Required for key rotation.', 'ffcertificate' )
			);
		}

		// Rotation only makes sense once a strong FFC_ENCRYPTION_KEY has been
		// defined in wp-config.php — otherwise there is no new key to rotate to.
		if ( ! \FreeFormCertificate\Core\Encryption::key_health_report()['encryption_decoupled'] ) {
			return new WP_Error(
				'encryption_not_decoupled',
				__( 'Define a strong FFC_ENCRYPTION_KEY (32+ chars) in wp-config.php first, then run this migration to re-encrypt existing data under it. See Settings → Advanced → Encryption Key Health.', 'ffcertificate' )
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
		return __( 'Encryption Key Rotation', 'ffcertificate' );
	}

	/**
	 * Count status for a single table (cursor-based).
	 *
	 * - total:    rows carrying at least one non-empty encrypted PII column.
	 * - migrated: those with id <= cursor (already walked).
	 * - pending:  total - migrated.
	 *
	 * @param string $table Table name.
	 * @return array{total: int, migrated: int, pending: int}
	 */
	private function count_table_status( string $table ): array {
		global $wpdb;

		$empty = array(
			'total'    => 0,
			'migrated' => 0,
			'pending'  => 0,
		);

		if ( ! self::table_exists( $table ) ) {
			return $empty;
		}

		$columns = $this->existing_encrypted_columns( $table );
		if ( empty( $columns ) ) {
			return $empty;
		}

		$predicate = $this->non_empty_predicate( $columns );

		// $predicate is built only from an internal allow-list of column names
		// (never request data) via non_empty_predicate(); table via %i.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE {$predicate}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $predicate is an allow-listed IS-NOT-NULL clause, no user input.
				$table
			)
		);

		$cursor = $this->get_cursor( $table );

		$migrated = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE id <= %d AND ( {$predicate} )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see above.
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
	 * Build an "at least one column is non-empty" SQL predicate from an
	 * allow-listed set of column names. Column identifiers are validated
	 * against the field map, so no request data ever reaches the string.
	 *
	 * @param array<int, string> $columns Encrypted column names.
	 * @return string
	 */
	private function non_empty_predicate( array $columns ): string {
		$parts = array();
		foreach ( $columns as $col ) {
			// Backtick-quote the identifier; $col is an internal constant name.
			$parts[] = '`' . str_replace( '`', '', $col ) . "` <> ''";
		}
		return '(' . implode( ' OR ', $parts ) . ')';
	}

	/**
	 * Process a batch from a single table: decrypt each PII column via the
	 * fallback-aware decrypt(), re-encrypt under the active key, rebuild the
	 * paired searchable hash, and update the row.
	 *
	 * @param string $table      Table name.
	 * @param int    $batch_size Max rows in this batch.
	 * @return array{processed: int, errors: string[]}
	 */
	private function process_table( string $table, int $batch_size ): array {
		global $wpdb;

		if ( ! self::table_exists( $table ) ) {
			return array(
				'processed' => 0,
				'errors'    => array(),
			);
		}

		$columns = $this->existing_encrypted_columns( $table );
		if ( empty( $columns ) ) {
			return array(
				'processed' => 0,
				'errors'    => array(),
			);
		}

		$cursor    = $this->get_cursor( $table );
		$predicate = $this->non_empty_predicate( $columns );

		// Select id + the existing encrypted columns above the cursor.
		$select_cols = implode(
			', ',
			array_map(
				static function ( $c ) {
					return '`' . str_replace( '`', '', $c ) . '`';
				},
				$columns
			)
		);

		$records = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, {$select_cols} FROM %i WHERE id > %d AND ( {$predicate} ) ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $select_cols/$predicate are allow-listed column identifiers, no user input.
				$table,
				$cursor,
				$batch_size
			),
			ARRAY_A
		);

		if ( empty( $records ) ) {
			// Nothing above cursor — advance to MAX(id) to latch completion.
			$max_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', $table ) );
			if ( $max_id > $cursor ) {
				$this->set_cursor( $table, $max_id );
			}
			return array(
				'processed' => 0,
				'errors'    => array(),
			);
		}

		$field_map = $this->field_map( $table );
		$errors    = array();
		$processed = 0;
		$last_id   = $cursor;

		foreach ( $records as $record ) {
			$last_id = (int) $record['id'];

			try {
				$update  = array();
				$formats = array();

				foreach ( $columns as $enc_col ) {
					$ciphertext = (string) ( $record[ $enc_col ] ?? '' );
					if ( '' === $ciphertext ) {
						continue;
					}

					$plain = \FreeFormCertificate\Core\Encryption::decrypt( $ciphertext );
					if ( null === $plain || '' === $plain ) {
						$errors[] = sprintf(
							/* translators: 1: column name, 2: record ID, 3: table name */
							__( 'Could not decrypt %1$s for ID %2$d in %3$s — left unchanged (key may be unrecoverable).', 'ffcertificate' ),
							$enc_col,
							(int) $record['id'],
							$table
						);
						continue;
					}

					$new_ciphertext = \FreeFormCertificate\Core\Encryption::encrypt( $plain );
					if ( null === $new_ciphertext ) {
						continue;
					}

					$update[ $enc_col ] = $new_ciphertext;
					$formats[]          = '%s';

					// Rebuild the paired searchable hash under the active salt.
					$hash_col = $field_map[ $enc_col ] ?? null;
					if ( null !== $hash_col && self::column_exists( $table, $hash_col ) ) {
						$new_hash = \FreeFormCertificate\Core\Encryption::hash( $plain );
						if ( null !== $new_hash ) {
							$current_hash = isset( $record[ $hash_col ] ) ? (string) $record[ $hash_col ] : '';
							if ( '' === $current_hash || ! hash_equals( $new_hash, $current_hash ) ) {
								$update[ $hash_col ] = $new_hash;
								$formats[]           = '%s';
							}
						}
					}
				}

				if ( empty( $update ) ) {
					continue;
				}

				$updated = $wpdb->update(
					$table,
					$update,
					array( 'id' => (int) $record['id'] ),
					$formats,
					array( '%d' )
				);

				if ( false === $updated ) {
					$errors[] = sprintf(
						'Failed to update ID %d in %s: %s',
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

		$this->set_cursor( $table, $last_id );

		return array(
			'processed' => $processed,
			'errors'    => $errors,
		);
	}

	// ------------------------------------------------------------------
	// Fingerprint + state helpers
	// ------------------------------------------------------------------

	/**
	 * The active encryption key's fingerprint.
	 *
	 * @return string
	 */
	private function active_fingerprint(): string {
		if ( ! class_exists( '\\FreeFormCertificate\\Core\\Encryption' ) ) {
			return '';
		}
		return \FreeFormCertificate\Core\Encryption::key_fingerprint();
	}

	/**
	 * Whether the stored rotation fingerprint matches the active key.
	 *
	 * @return bool
	 */
	private function fingerprint_matches(): bool {
		$state = $this->get_state();
		return isset( $state['fingerprint'] ) && hash_equals( (string) $state['fingerprint'], $this->active_fingerprint() );
	}

	/**
	 * Re-arm the state when the active key changed since the last run: reset the
	 * cursors and completion flag and stamp the new fingerprint. No-op when the
	 * fingerprint already matches.
	 *
	 * @return void
	 */
	private function sync_fingerprint(): void {
		if ( $this->fingerprint_matches() ) {
			return;
		}
		$this->save_state(
			array(
				'fingerprint' => $this->active_fingerprint(),
				'cursor'      => array(),
				'completed'   => false,
			)
		);
	}

	/**
	 * Whether the rotation has completed under the current fingerprint.
	 *
	 * @return bool
	 */
	private function is_completed(): bool {
		$state = $this->get_state();
		return ! empty( $state['completed'] );
	}

	/**
	 * Latch the rotation complete.
	 *
	 * @return void
	 */
	private function mark_completed(): void {
		$state              = $this->get_state();
		$state['completed'] = true;
		$this->save_state( $state );
	}

	/**
	 * Read the per-table cursor.
	 *
	 * @param string $table Table name.
	 * @return int
	 */
	private function get_cursor( string $table ): int {
		$state = $this->get_state();
		return isset( $state['cursor'][ $table ] ) ? (int) $state['cursor'][ $table ] : 0;
	}

	/**
	 * Store the per-table cursor.
	 *
	 * @param string $table Table name.
	 * @param int    $id    Cursor value.
	 * @return void
	 */
	private function set_cursor( string $table, int $id ): void {
		$state = $this->get_state();
		if ( ! isset( $state['cursor'] ) || ! is_array( $state['cursor'] ) ) {
			$state['cursor'] = array();
		}
		$state['cursor'][ $table ] = $id;
		$this->save_state( $state );
	}

	/**
	 * Read the rotation state option: keys fingerprint (string), cursor
	 * (array<string,int>), completed (bool).
	 *
	 * @return array<string, mixed>
	 */
	private function get_state(): array {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Persist the rotation state option.
	 *
	 * @param array<string, mixed> $state State.
	 * @return void
	 */
	private function save_state( array $state ): void {
		update_option( self::STATE_OPTION, $state, false );
	}
}

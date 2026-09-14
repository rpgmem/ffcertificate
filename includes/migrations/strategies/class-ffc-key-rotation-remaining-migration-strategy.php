<?php
/**
 * Re-encrypt the PII areas that `KeyRotationMigrationStrategy` never reached.
 *
 * WHY A SECOND STRATEGY AND NOT AN EXTENSION OF THE FIRST (#1236)
 *
 * `KeyRotationMigrationStrategy` walks exactly two tables -- `ffc_submissions`
 * and `ffc_self_scheduling_appointments` -- and latches a `completed` boolean
 * in its own state. Its `calculate_status()` short-circuits to `pending = 0`
 * BEFORE looking at any table, so on an install that already finished the
 * rotation, adding targets to it would keep reporting "complete" while never
 * touching them: a false green over exactly the data at risk. Re-arming that
 * flag instead would force a pointless re-walk of the two tables already done.
 *
 * A separate strategy gets its own cursor, its own completion flag and its own
 * card in Settings -> Migrations, which is the evidence shape this project
 * prefers: a counter an operator can read in production.
 *
 * WHAT IS AT STAKE, AND IT IS NOT PERFORMANCE
 *
 * A value still encrypted under the WordPress-derived key depends on
 * `SECURE_AUTH_KEY` / `LOGGED_IN_KEY` / `NONCE_KEY`. Rotating those -- routine
 * security hygiene, offered as a one-click action by several hosts -- makes
 * the data PERMANENTLY unreadable. Decoupling exists to sever that dependency;
 * until this migration runs it is severed for two areas only.
 *
 * SCOPE OF THIS FILE
 *
 * Reregistration submission bodies (the `data` JSON) only, so far. The other
 * two areas #1236 names join as additional targets in {@see self::targets()},
 * which is why the cursor is keyed per target from the start rather than being
 * a single scalar -- each is a different storage SHAPE, not merely another
 * table: `ffc_recruitment_candidate` is columns with paired search hashes to
 * rebuild, and the user profile is usermeta keyed by user rather than rows.
 *
 * The recruitment target is NOT hypothetical: a production install measured
 * 7.695 candidate rows, every one of them written inside a single hour on one
 * day -- a bulk import. That shape matters twice over. It means the whole set
 * shares one salt era, so a handful of rows answers for all of them; and it
 * explains why no duplicate candidate was ever observed there, since a second
 * import never ran to re-find those people. The absence of duplicates is
 * therefore evidence about the IMPORT HISTORY, never about the hashes.
 *
 * @package FreeFormCertificate
 * @since   6.24.0
 */

namespace FreeFormCertificate\Migrations\Strategies;

use FreeFormCertificate\Core\ArrayValue;
use FreeFormCertificate\Core\Encryption;
use WP_Error;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Data statements against the plugin's own ffc_* tables during a migration. WordPress exposes no API for them, and a cached read is exactly what a migration cursor must not take.

/**
 * Finishes the key rotation over the areas the original strategy never walked.
 */
class KeyRotationRemainingMigrationStrategy implements MigrationStrategyInterface {

	/**
	 * Option holding cursor + fingerprint + completion for this strategy.
	 */
	private const STATE_OPTION = 'ffc_key_rotation_remaining_state';

	/**
	 * Target key for the reregistration submission bodies.
	 */
	private const TARGET_REREGISTRATION = 'reregistration_data';

	/**
	 * Rows examined per batch.
	 *
	 * Smaller than the original strategy's 100 because each row here carries a
	 * whole JSON body with an unbounded number of encrypted values, where a row
	 * there carries a fixed handful of columns.
	 */
	private const BATCH_SIZE = 50;

	/**
	 * Targets this strategy walks, in order.
	 *
	 * Adding `ffc_recruitment_candidate` and the profile usermeta means adding
	 * entries here plus a `migrate_*` method; the cursor, the status maths and
	 * the completion latch already work per target.
	 *
	 * @return array<int, string>
	 */
	private function targets(): array {
		return array( self::TARGET_REREGISTRATION );
	}

	/**
	 * Full table name for the reregistration submissions.
	 *
	 * @return string
	 */
	private function reregistration_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ffc_reregistration_submissions';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $migration_key    Registry key.
	 * @param array<string, mixed> $migration_config Registry config.
	 * @return array<string, mixed>
	 */
	public function calculate_status( string $migration_key, array $migration_config ): array {
		unset( $migration_key, $migration_config );

		$total = $this->count_total();

		// A changed active key invalidates everything already walked: rows
		// re-encrypted under the previous key are legacy again.
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

		$migrated = $this->count_migrated();
		$pending  = max( 0, $total - $migrated );

		return array(
			'total'       => $total,
			'migrated'    => $migrated,
			'pending'     => $pending,
			'percent'     => ( $total > 0 ) ? round( ( $migrated / $total ) * 100, 2 ) : 100.0,
			'is_complete' => ( 0 === $pending ),
		);
	}

	/**
	 * Rows carrying at least one ciphertext, across every target.
	 *
	 * @return int
	 */
	private function count_total(): int {
		global $wpdb;
		$table = $this->reregistration_table();

		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE data LIKE %s', $table, '%' . $wpdb->esc_like( Encryption::V2_PREFIX ) . '%' )
		);
	}

	/**
	 * Rows already behind the cursor.
	 *
	 * @return int
	 */
	private function count_migrated(): int {
		global $wpdb;
		$table = $this->reregistration_table();

		if ( ! $this->table_exists( $table ) ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE data LIKE %s AND id <= %d',
				$table,
				'%' . $wpdb->esc_like( Encryption::V2_PREFIX ) . '%',
				$this->get_cursor( self::TARGET_REREGISTRATION )
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $migration_key    Registry key.
	 * @param array<string, mixed> $migration_config Registry config.
	 * @param int                  $batch_number     Batch counter.
	 * @return array<string, mixed>
	 */
	public function execute( string $migration_key, array $migration_config, int $batch_number = 0 ): array {
		unset( $migration_key, $migration_config, $batch_number );

		$can_run = $this->can_run( '', array() );
		if ( $can_run instanceof WP_Error ) {
			return array(
				'success' => false,
				'message' => $can_run->get_error_message(),
			);
		}

		// The fingerprint is stamped on the first batch of a run. A key changed
		// mid-migration re-arms from zero rather than leaving a half-rotated set
		// silently marked complete.
		$this->stamp_fingerprint();

		$result = $this->migrate_reregistration_batch();

		$status = $this->calculate_status( '', array() );
		if ( 0 === $status['pending'] && empty( $result['errors'] ) ) {
			$this->mark_completed();
		}

		return array(
			'success'   => true,
			'processed' => $result['processed'],
			'pending'   => $status['pending'],
			'has_more'  => $status['pending'] > 0,
			'errors'    => $result['errors'],
		);
	}

	/**
	 * Re-encrypt one batch of reregistration bodies.
	 *
	 * @return array{processed: int, errors: array<int, string>}
	 */
	private function migrate_reregistration_batch(): array {
		global $wpdb;

		$table  = $this->reregistration_table();
		$cursor = $this->get_cursor( self::TARGET_REREGISTRATION );
		$errors = array();

		if ( ! $this->table_exists( $table ) ) {
			return array(
				'processed' => 0,
				'errors'    => $errors,
			);
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, data FROM %i WHERE id > %d AND data LIKE %s ORDER BY id ASC LIMIT %d',
				$table,
				$cursor,
				'%' . $wpdb->esc_like( Encryption::V2_PREFIX ) . '%',
				self::BATCH_SIZE
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) || array() === $rows ) {
			// Nothing left ahead of the cursor: park it at the end so the status
			// maths reports complete instead of stalling one row short.
			$max_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', $table ) );
			if ( $max_id > $cursor ) {
				$this->set_cursor( self::TARGET_REREGISTRATION, $max_id );
			}

			return array(
				'processed' => 0,
				'errors'    => $errors,
			);
		}

		$processed = 0;
		$last_id   = $cursor;

		foreach ( $rows as $row ) {
			$last_id = (int) $row['id'];

			$rewritten = $this->rewrite_body( (string) ( $row['data'] ?? '' ), $last_id, $errors );
			if ( null === $rewritten ) {
				continue;
			}

			$updated = $wpdb->update(
				$table,
				array( 'data' => $rewritten ),
				array( 'id' => $last_id ),
				array( '%s' ),
				array( '%d' )
			);

			if ( false === $updated ) {
				$errors[] = sprintf(
					/* translators: %d: submission ID */
					__( 'Could not write the re-encrypted body for reregistration submission %d.', 'ffcertificate' ),
					$last_id
				);
				continue;
			}

			++$processed;
		}

		$this->set_cursor( self::TARGET_REREGISTRATION, $last_id );

		return array(
			'processed' => $processed,
			'errors'    => $errors,
		);
	}

	/**
	 * Re-encrypt every ciphertext inside one submission body.
	 *
	 * WHICH VALUES ARE CIPHERTEXT IS READ FROM THE VALUE, NOT FROM THE CONFIG.
	 *
	 * The writer encrypts a field only when its `is_sensitive` flag is on
	 * ({@see \FreeFormCertificate\Reregistration\ReregistrationDataProcessor}),
	 * and that flag is editable -- a field switched off after some submissions
	 * were stored leaves ciphertext behind that the config no longer claims.
	 * Dispatching on the stored value's own `v2:` prefix is therefore the only
	 * reading that cannot drift, and it also skips plaintext without needing to
	 * know which fields exist.
	 *
	 * @param string             $json    Raw `data` column.
	 * @param int                $row_id  Submission ID, for error messages.
	 * @param array<int, string> $errors  Collected errors, by reference.
	 * @return string|null Rewritten JSON, or null when there is nothing to write.
	 */
	private function rewrite_body( string $json, int $row_id, array &$errors ): ?string {
		if ( '' === $json ) {
			return null;
		}

		$body = json_decode( $json, true );
		if ( ! is_array( $body ) ) {
			$errors[] = sprintf(
				/* translators: %d: submission ID */
				__( 'The body of reregistration submission %d is not valid JSON — left unchanged.', 'ffcertificate' ),
				$row_id
			);
			return null;
		}

		$fields = ArrayValue::array( $body, 'fields' );
		if ( array() === $fields ) {
			return null;
		}

		$changed = false;

		foreach ( $fields as $key => $value ) {
			if ( ! is_string( $value ) || 0 !== strpos( $value, Encryption::V2_PREFIX ) ) {
				continue;
			}

			$plain = Encryption::decrypt( $value );
			if ( null === $plain || '' === $plain ) {
				$errors[] = sprintf(
					/* translators: 1: field key, 2: submission ID */
					__( 'Could not decrypt %1$s on reregistration submission %2$d — left unchanged (the key may be unrecoverable).', 'ffcertificate' ),
					(string) $key,
					$row_id
				);
				continue;
			}

			$fresh = Encryption::encrypt( $plain );
			if ( null === $fresh ) {
				continue;
			}

			$fields[ $key ] = $fresh;
			$changed        = true;
		}

		if ( ! $changed ) {
			return null;
		}

		$body['fields'] = $fields;
		$encoded        = wp_json_encode( $body );

		return is_string( $encoded ) ? $encoded : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Mirrors the original strategy's gate: BOTH constants must be defined
	 * before anything runs. Rotating with only the key set would rebuild search
	 * hashes under the still-shared WordPress salt, forcing a second rotation
	 * the moment the salt is decoupled later.
	 *
	 * @param string               $migration_key    Registry key.
	 * @param array<string, mixed> $migration_config Registry config.
	 * @return true|WP_Error
	 */
	public function can_run( string $migration_key, array $migration_config ) {
		unset( $migration_key, $migration_config );

		$health = Encryption::key_health_report();

		if ( empty( $health['encryption_decoupled'] ) || empty( $health['salt_decoupled'] ) ) {
			return new WP_Error(
				'encryption_not_decoupled',
				__( 'Define both FFC_ENCRYPTION_KEY and FFC_HASH_SALT (32+ chars each) in wp-config.php first. See Settings → Advanced → Encryption Key Health.', 'ffcertificate' )
			);
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Encryption Key Rotation — Remaining Areas', 'ffcertificate' );
	}

	/**
	 * Whether a table exists.
	 *
	 * Local rather than inherited from the database trait: this class is a
	 * migration strategy, not an activator, and it needs exactly this one
	 * helper.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Stored state.
	 *
	 * @return array<string, mixed>
	 */
	private function get_state(): array {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Persist state.
	 *
	 * @param array<string, mixed> $state State.
	 * @return void
	 */
	private function put_state( array $state ): void {
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Cursor for one target.
	 *
	 * @param string $target Target key.
	 * @return int
	 */
	private function get_cursor( string $target ): int {
		$state   = $this->get_state();
		$cursors = ArrayValue::array( $state, 'cursors' );

		return isset( $cursors[ $target ] ) && is_numeric( $cursors[ $target ] ) ? (int) $cursors[ $target ] : 0;
	}

	/**
	 * Advance the cursor for one target.
	 *
	 * @param string $target Target key.
	 * @param int    $value  New cursor.
	 * @return void
	 */
	private function set_cursor( string $target, int $value ): void {
		$state              = $this->get_state();
		$cursors            = ArrayValue::array( $state, 'cursors' );
		$cursors[ $target ] = $value;
		$state['cursors']   = $cursors;

		$this->put_state( $state );
	}

	/**
	 * Whether the stored fingerprint still matches the active key.
	 *
	 * @return bool
	 */
	private function fingerprint_matches(): bool {
		$state = $this->get_state();

		return array_key_exists( 'fingerprint', $state )
			&& hash_equals( ArrayValue::string( $state, 'fingerprint' ), Encryption::key_fingerprint() );
	}

	/**
	 * Stamp the active fingerprint, resetting progress when the key changed.
	 *
	 * @return void
	 */
	private function stamp_fingerprint(): void {
		if ( $this->fingerprint_matches() ) {
			return;
		}

		$this->put_state(
			array(
				'fingerprint' => Encryption::key_fingerprint(),
				'cursors'     => array(),
			)
		);
	}

	/**
	 * Whether the run is latched complete.
	 *
	 * @return bool
	 */
	private function is_completed(): bool {
		$state = $this->get_state();
		return ! empty( $state['completed'] );
	}

	/**
	 * Latch completion.
	 *
	 * @return void
	 */
	private function mark_completed(): void {
		$state              = $this->get_state();
		$state['completed'] = true;

		$this->put_state( $state );
	}
}

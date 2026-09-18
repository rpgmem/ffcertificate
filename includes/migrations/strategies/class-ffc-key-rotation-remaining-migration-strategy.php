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
 * @since   6.25.0
 */

namespace FreeFormCertificate\Migrations\Strategies;

use FreeFormCertificate\Core\ArrayValue;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Repositories\UserProfileRepository;
use WP_Error;

/*
 * NO file-level `phpcs:disable`, on purpose (#1236).
 *
 * #1035 collapsed per-line annotations into file-level disables where the
 * justification was a property of the CLASS: every table touched there is
 * `ffc_*`, for which WordPress exposes no API. That stopped holding here -- the
 * profile target reads `wp_usermeta`, a core table --, and
 * `PhpcsSuppressionTest::test_file_level_direct_query_disables_only_cover_plugin_tables()`
 * enforces exactly that honesty. Of the two ways out the guard itself names,
 * this is the second: drop the disable and annotate per line.
 */

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
	 * Target key for the recruitment candidate columns.
	 */
	private const TARGET_RECRUITMENT = 'recruitment_candidate';

	/**
	 * Target key for the sensitive user-profile usermeta.
	 */
	private const TARGET_USER_PROFILE = 'user_profile_meta';

	/**
	 * Encrypted meta key => the ffc_user_profiles COLUMN carrying its lookup
	 * hash (null when the field is not searchable by hash).
	 *
	 * THE TWO SIDES LIVE IN DIFFERENT STORES, AND THAT IS THE POINT (#1313)
	 *
	 * The ciphertext stays in wp_usermeta, which is indexed for "read this
	 * user's attribute". The hash moved to a column on ffc_user_profiles,
	 * which is indexed for "which user carries this identifier" -- the
	 * question wp_usermeta cannot answer without scanning, because it indexes
	 * `meta_key` and never `meta_value`. So this map now spans both, and a
	 * rotation that rebuilt only one of them would leave the index answering
	 * under a salt the ciphertext no longer uses: every lookup silently
	 * finding nobody, which is worse than the PII being unreadable because
	 * nothing reports it.
	 *
	 * THE NAMES ARE LITERALS HERE ON PURPOSE. The source of truth is
	 * `UserProfileFieldMap`, in the UserDashboard module -- and importing it
	 * would create the `Migrations > UserDashboard` edge, which does not exist
	 * in `ModuleBoundaryTest`'s baseline. The strategy already pins the column
	 * names of the other two targets for the same reason; what enforces the
	 * agreement is `KeyRotationUserProfileTargetTest`, which lives outside the
	 * module graph and fails when the map gains a sensitive field this list
	 * does not know about, or names a hash column this list spells differently.
	 *
	 * `ffc_user_profiles`'s other columns are NOT in: they are plain text
	 * (`sensitive => false` in the map), so there is nothing to re-encrypt
	 * there. #1236 conflated the two in a single line; measured, the ciphertext
	 * lives in the usermeta alone -- which is still true, and is why the
	 * columns above hold a hash rather than an envelope.
	 *
	 * @return array<string, string|null>
	 */
	private function profile_meta_map(): array {
		return array(
			'ffc_user_cpf' => 'cpf_hash',
			'ffc_user_rf'  => 'rf_hash',
			'ffc_user_rg'  => null,
		);
	}

	/**
	 * Targets this strategy walks, in order.
	 *
	 * Adding the profile usermeta means adding an entry here plus a `migrate_*`
	 * method; the cursor, the status maths and the completion latch already work
	 * per target.
	 *
	 * @return array<int, string>
	 */
	private function targets(): array {
		return array( self::TARGET_RECRUITMENT, self::TARGET_REREGISTRATION, self::TARGET_USER_PROFILE );
	}

	/**
	 * Encrypted column => paired searchable hash column, for the recruitment
	 * candidate table.
	 *
	 * Rebuilding these hashes is the half that fixes a LIVE defect rather than
	 * merely preventing a future one: `Encryption::hash()` reads
	 * `FFC_HASH_SALT` as soon as it is defined, so every hash written before
	 * the decoupling is unreachable by every lookup made after it --
	 * `RecruitmentCandidateReader::get_by_cpf_hash()` compares for equality.
	 * Four consumers break, and the fourth is not a search: the importer's
	 * dedup (`CandidatePersister`), which on the next import would create a
	 * second row for someone it cannot find.
	 *
	 * @return array<string, string>
	 */
	private function recruitment_columns(): array {
		return array(
			'cpf_encrypted'   => 'cpf_hash',
			'rf_encrypted'    => 'rf_hash',
			'email_encrypted' => 'email_hash',
		);
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
	 * Full table name for the recruitment candidates.
	 *
	 * @return string
	 */
	private function recruitment_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ffc_recruitment_candidate';
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
	 * Rows carrying at least one ciphertext, summed across every target.
	 *
	 * @return int
	 */
	private function count_total(): int {
		$total = 0;
		foreach ( $this->targets() as $target ) {
			$total += $this->count_target( $target, false );
		}

		return $total;
	}

	/**
	 * Rows already behind the cursor, summed across every target.
	 *
	 * @return int
	 */
	private function count_migrated(): int {
		$migrated = 0;
		foreach ( $this->targets() as $target ) {
			$migrated += $this->count_target( $target, true );
		}

		return $migrated;
	}

	/**
	 * Count rows of one target, optionally only those behind its cursor.
	 *
	 * @param string $target        Target key.
	 * @param bool   $behind_cursor Restrict to rows already walked.
	 * @return int
	 */
	private function count_target( string $target, bool $behind_cursor ): int {
		global $wpdb;

		if ( self::TARGET_USER_PROFILE === $target ) {
			return $this->count_user_profile_pending( $behind_cursor );
		}

		$table = $this->table_for( $target );
		if ( '' === $table || ! $this->table_exists( $table ) ) {
			return 0;
		}

		$where  = $this->pending_predicate( $target );
		$values = array( $table );

		if ( self::TARGET_REREGISTRATION === $target ) {
			$values[] = '%' . $wpdb->esc_like( Encryption::V2_PREFIX ) . '%';
		}

		if ( $behind_cursor ) {
			$where   .= ' AND id <= %d';
			$values[] = $this->get_cursor( $target );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $where comes only from pending_predicate(), which returns one of two hard-coded literals and never touches request data; every value, the table included, is bound through prepare().
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where}", $values ) );
	}

	/**
	 * The WHERE fragment that identifies rows this target still has to consider.
	 *
	 * Literal fragments only -- the bound values are appended by the caller.
	 *
	 * @param string $target Target key.
	 * @return string
	 */
	private function pending_predicate( string $target ): string {
		if ( self::TARGET_REREGISTRATION === $target ) {
			return 'data LIKE %s';
		}

		return '( cpf_encrypted IS NOT NULL OR rf_encrypted IS NOT NULL OR email_encrypted IS NOT NULL )';
	}

	/**
	 * Table backing one target.
	 *
	 * @param string $target Target key.
	 * @return string Empty when the target has no table of its own.
	 */
	private function table_for( string $target ): string {
		if ( self::TARGET_REREGISTRATION === $target ) {
			return $this->reregistration_table();
		}

		if ( self::TARGET_RECRUITMENT === $target ) {
			return $this->recruitment_table();
		}

		return '';
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

		// One target per batch: the first that still has rows ahead of its own
		// cursor. Mixing targets inside one batch would make the cursor
		// ambiguous and stop it resuming from where it left off.
		$result = array(
			'processed' => 0,
			'errors'    => array(),
		);

		foreach ( $this->targets() as $target ) {
			if ( $this->count_target( $target, false ) <= $this->count_target( $target, true ) ) {
				continue;
			}

			if ( self::TARGET_RECRUITMENT === $target ) {
				$result = $this->migrate_recruitment_batch();
			} elseif ( self::TARGET_REREGISTRATION === $target ) {
				$result = $this->migrate_reregistration_batch();
			} else {
				$result = $this->migrate_user_profile_batch();
			}
			break;
		}

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

		/**
		 * Explicit row typing, the idiom the other row-reading classes use (see
		 * `AbstractRepository`, `AppointmentReader`). Without it the return of
		 * `get_results()` is `mixed` and the "Row shapes (level 9)" gate fails
		 * every offset access and every cast -- zero tolerance, by a decision
		 * recorded in the workflow itself.
		 *
		 * `string|null` rather than a literal shape: MySQL returns every column
		 * as a string, and `id` arrives as a number in text.
		 *
		 * @var list<array<string, string|null>>|null $rows
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Data statement against the plugin's own `ffc_*` table inside a migration: WordPress exposes no API for it, and a cached read is exactly what a migration cursor must not take.
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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Data statement against the plugin's own `ffc_*` table inside a migration: WordPress exposes no API for it, and a cached read is exactly what a migration cursor must not take.
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

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Data statement against the plugin's own `ffc_*` table inside a migration: WordPress exposes no API for it, and a cached read is exactly what a migration cursor must not take.
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
	 * How many USERS still have sensitive meta to consider in this target.
	 *
	 * It counts distinct users, not meta rows, because that is how the batch
	 * pages -- a user with three metas is one unit of work, not three. Counting
	 * rows would make `execute()`'s `count(false) > count(true)` comparison
	 * disagree with what the batch actually consumes.
	 *
	 * @param bool $behind_cursor Restrict to what already fell behind the cursor.
	 * @return int
	 */
	private function count_user_profile_pending( bool $behind_cursor ): int {
		global $wpdb;

		$meta_keys    = array_keys( $this->profile_meta_map() );
		$placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );

		$values   = array( $wpdb->usermeta );
		$values   = array_merge( $values, $meta_keys );
		$values[] = '%' . $wpdb->esc_like( Encryption::V2_PREFIX ) . '%';

		$cursor_sql = '';
		if ( $behind_cursor ) {
			$cursor_sql = ' AND user_id <= %d';
			$values[]   = $this->get_cursor( self::TARGET_USER_PROFILE );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The interpolated fragments are placeholders generated here from the key count plus a fixed cursor literal; every value, the table included, goes through prepare(). A migration read: a cached answer is exactly what a cursor must not take.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM %i WHERE meta_key IN ({$placeholders}) AND meta_value LIKE %s{$cursor_sql}", $values ) );
	}

	/**
	 * Re-encrypt one batch of users' sensitive meta and rebuild the hashes.
	 *
	 * WHY THE PAGE IS OF USERS, NOT OF META ROWS
	 *
	 * A user carries up to three encrypted metas. Paging by meta row while
	 * advancing the cursor by `user_id` would lose the metas left behind for a
	 * user split across two batches -- the cursor would already have passed
	 * them. Paging by user makes the unit of work indivisible.
	 *
	 * WHY THE WRITE GOES THROUGH THE WordPress API
	 *
	 * The only direct query is the SELECT that resolves the page of `user_id`:
	 * a keyset (`user_id > %d`) that `WP_User_Query` cannot express, and
	 * swapping the keyset for an `offset` would risk skipping a user -- which
	 * here means PII unreadable forever after a salt rotation. The read and the
	 * write, though, go through `get_user_meta()` / `update_user_meta()`, which
	 * pass through the object cache and keep the write path identical to
	 * `UserProfileService`'s. The rebuilt hashes leave through
	 * {@see self::rebuild_identity_index()}, which takes that same service's
	 * write path for the index -- `UserProfileRepository` -- for the same
	 * reason.
	 *
	 * @return array{processed: int, errors: array<int, string>}
	 */
	private function migrate_user_profile_batch(): array {
		global $wpdb;

		$cursor = $this->get_cursor( self::TARGET_USER_PROFILE );
		$errors = array();
		$map    = $this->profile_meta_map();

		$meta_keys    = array_keys( $map );
		$placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );

		$values   = array( $wpdb->usermeta );
		$values   = array_merge( $values, $meta_keys );
		$values[] = '%' . $wpdb->esc_like( Encryption::V2_PREFIX ) . '%';
		$values[] = $cursor;
		$values[] = self::BATCH_SIZE;

		/**
		 * A column of ids, typed explicitly because `get_col()` returns `mixed`
		 * to the analyser.
		 *
		 * @var list<string>|null $ids
		 */
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As in count_user_profile_pending(): fragments generated here, every value through prepare(), and caching forbidden inside a migration cursor.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM %i WHERE meta_key IN ({$placeholders}) AND meta_value LIKE %s AND user_id > %d ORDER BY user_id ASC LIMIT %d", $values ) );

		if ( ! is_array( $ids ) || array() === $ids ) {
			return array(
				'processed' => 0,
				'errors'    => $errors,
			);
		}

		$processed = 0;
		$last_id   = $cursor;

		foreach ( $ids as $raw_id ) {
			$user_id = (int) $raw_id;
			$last_id = $user_id;

			/**
			 * Rebuilt hashes for this user, flushed in one upsert below.
			 *
			 * @var array<string, string> $index
			 */
			$index = array();

			foreach ( $map as $meta_key => $hash_column ) {
				$stored = get_user_meta( $user_id, $meta_key, true );

				// The prefix is a COST filter, not a correctness one:
				// `decrypt()` would already return null for what it cannot
				// decrypt, and the guard below would protect the value anyway
				// (measured by mutation). What it avoids is the CALL: opening
				// the envelope, deriving the HMAC comparison and calling
				// `openssl_decrypt` for every plaintext meta, inside a loop
				// that walks every user of the site.
				//
				// Until #1234 there was a second, larger reason: every failure
				// wrote a row into `ffc_activity_log`, with no ceiling. The
				// ceiling now exists (five per request), so what remains is the
				// cost of the decryption itself -- enough, but no longer the
				// dramatic argument this comment used to carry.
				if ( ! is_string( $stored ) || 0 !== strpos( $stored, Encryption::V2_PREFIX ) ) {
					continue;
				}

				$plain = Encryption::decrypt( $stored );
				if ( null === $plain || '' === $plain ) {
					$errors[] = sprintf(
						/* translators: 1: meta key, 2: user ID */
						__( 'Could not decrypt %1$s for user %2$d — left untouched (the key may be unrecoverable).', 'ffcertificate' ),
						$meta_key,
						$user_id
					);
					continue;
				}

				$reencrypted = Encryption::encrypt( $plain );
				if ( null === $reencrypted ) {
					continue;
				}
				update_user_meta( $user_id, $meta_key, $reencrypted );

				if ( null === $hash_column ) {
					continue;
				}

				$hash = Encryption::hash( $plain );
				if ( null === $hash ) {
					continue;
				}

				$index[ $hash_column ] = $hash;
			}

			$this->rebuild_identity_index( $user_id, $index );

			++$processed;
		}

		$this->set_cursor( self::TARGET_USER_PROFILE, $last_id );

		return array(
			'processed' => $processed,
			'errors'    => $errors,
		);
	}

	/**
	 * Write one user's rebuilt hashes to ffc_user_profiles.
	 *
	 * It goes through `UserProfileRepository` rather than a direct statement,
	 * which is the same write path `UserProfileService` takes -- so the
	 * rotation and the ordinary write cannot disagree about how a row is
	 * created, and the repository's cache is invalidated either way. The
	 * `Migrations > Repositories` edge already exists in the boundary
	 * baseline; `Migrations > UserDashboard` does not, which is why the column
	 * names above are literals.
	 *
	 * Written only when it changes, mirroring the other two targets: a row
	 * already under the current salt costs no write. That costs one SELECT per
	 * user -- `findByUserId()` reads the row directly and is NOT one of the
	 * repository's cached paths -- which is the same trade the meta-side guard
	 * made with `get_user_meta()`, against a write that would otherwise touch
	 * every row of the index on every rotation.
	 *
	 * @param int                   $user_id WordPress user ID.
	 * @param array<string, string> $index   Hash column => rebuilt hash.
	 * @return void
	 */
	private function rebuild_identity_index( int $user_id, array $index ): void {
		if ( empty( $index ) ) {
			return;
		}

		$repository = new UserProfileRepository();
		$row        = $repository->findByUserId( $user_id );
		$changed    = array();

		foreach ( $index as $column => $hash ) {
			$stored  = null !== $row ? ( $row[ $column ] ?? null ) : null;
			$current = is_string( $stored ) ? $stored : '';
			if ( ! hash_equals( $hash, $current ) ) {
				$changed[ $column ] = $hash;
			}
		}

		if ( empty( $changed ) ) {
			return;
		}

		$repository->upsertForUserId( $user_id, $changed );
	}

	/**
	 * Re-encrypt one batch of recruitment candidates and rebuild their hashes.
	 *
	 * REBUILDING THE HASH CAN COLLIDE -- AND THE CASE WHERE IT DOES IS EXACTLY
	 * THE ONE #1236 DESCRIBES.
	 *
	 * This block once claimed the opposite, and the claim was wrong. It said
	 * two rows for the same person are "what the constraints already forbid".
	 * They are not: `cpf_hash` and `rf_hash` are UNIQUE over the hash VALUE,
	 * not over the person. Under different salts the same person produces
	 * different values, and the pair passes the constraint without touching it.
	 *
	 * And that pair exists precisely because of the defect this migration
	 * fixes. Part 2 of #1236 raises the hypothesis: after the decoupling,
	 * `RecruitmentCandidateReader::get_by_cpf_hash()` stopped finding the old
	 * candidate, so the importer's dedup (`CandidatePersister`) created a NEW
	 * row instead of updating the existing one.
	 *
	 * Where that happened, rebuilding the old row's hash produces the value the
	 * new row already has, and the `UPDATE` hits the UNIQUE. The consequence is
	 * not loss: the error enters `$errors`, the loop carries on, and the row
	 * simply does not migrate -- but the card never reaches 0 pending until the
	 * duplicates are reconciled by hand. That is why the error message carries
	 * the database's `last_error`: it is what names the duplicated key and
	 * value.
	 *
	 * What remains true, and is worth saying so the two cases are not
	 * confused: a table that never received such a pair cannot collide here.
	 * Distinct inputs stay distinct under SHA-256, and the two salt eras
	 * produce values unrelated to each other, so a half-migrated table is safe
	 * too. The collision is a property of the DATA, not of the algorithm.
	 *
	 * The hash is only written when it actually differs, mirroring the original
	 * strategy -- a row already under the current salt costs no write.
	 *
	 * @return array{processed: int, errors: array<int, string>}
	 */
	private function migrate_recruitment_batch(): array {
		global $wpdb;

		$table  = $this->recruitment_table();
		$cursor = $this->get_cursor( self::TARGET_RECRUITMENT );
		$errors = array();

		if ( ! $this->table_exists( $table ) ) {
			return array(
				'processed' => 0,
				'errors'    => $errors,
			);
		}

		/**
		 * Explicit row typing for this target.
		 *
		 * @see self::migrate_reregistration_batch() for why the annotation is here.
		 *
		 * The map is generic on purpose -- `array<string, string|null>` rather
		 * than a literal shape -- because the columns are read by a VARIABLE key
		 * (`$row[ $enc_col ]`), and a literal shape refuses access by variable.
		 *
		 * @var list<array<string, string|null>>|null $rows
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Data statement against the plugin's own `ffc_*` table inside a migration: WordPress exposes no API for it, and a cached read is exactly what a migration cursor must not take.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, cpf_encrypted, cpf_hash, rf_encrypted, rf_hash, email_encrypted, email_hash
				 FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d',
				$table,
				$cursor,
				self::BATCH_SIZE
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) || array() === $rows ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Data statement against the plugin's own `ffc_*` table inside a migration: WordPress exposes no API for it, and a cached read is exactly what a migration cursor must not take.
			$max_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i', $table ) );
			if ( $max_id > $cursor ) {
				$this->set_cursor( self::TARGET_RECRUITMENT, $max_id );
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
			$update  = array();
			$formats = array();

			foreach ( $this->recruitment_columns() as $enc_col => $hash_col ) {
				$ciphertext = (string) ( $row[ $enc_col ] ?? '' );
				if ( '' === $ciphertext ) {
					continue;
				}

				$plain = Encryption::decrypt( $ciphertext );
				if ( null === $plain || '' === $plain ) {
					$errors[] = sprintf(
						/* translators: 1: column name, 2: candidate ID */
						__( 'Could not decrypt %1$s for recruitment candidate %2$d — left unchanged (the key may be unrecoverable).', 'ffcertificate' ),
						$enc_col,
						$last_id
					);
					continue;
				}

				$fresh = Encryption::encrypt( $plain );
				if ( null === $fresh ) {
					continue;
				}

				$update[ $enc_col ] = $fresh;
				$formats[]          = '%s';

				$new_hash = Encryption::hash( $plain );
				if ( null === $new_hash ) {
					continue;
				}

				$current_hash = isset( $row[ $hash_col ] ) ? (string) $row[ $hash_col ] : '';
				if ( '' === $current_hash || ! hash_equals( $new_hash, $current_hash ) ) {
					$update[ $hash_col ] = $new_hash;
					$formats[]           = '%s';
				}
			}

			if ( array() === $update ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Data statement against the plugin's own `ffc_*` table inside a migration: WordPress exposes no API for it, and a cached read is exactly what a migration cursor must not take.
			$written = $wpdb->update( $table, $update, array( 'id' => $last_id ), $formats, array( '%d' ) );

			if ( false === $written ) {
				// `last_error` names the key and the value when the reason is
				// the `cpf_hash`/`rf_hash` UNIQUE -- the case described in the
				// docblock, which needs manual reconciliation rather than a
				// retry. Without it the message cannot tell that apart from any
				// other write failure.
				$detail = isset( $wpdb->last_error ) && '' !== (string) $wpdb->last_error
					? (string) $wpdb->last_error
					: '';

				$errors[] = '' !== $detail
					? sprintf(
						/* translators: 1: candidate ID, 2: database error message */
						__( 'Could not write the re-encrypted values for recruitment candidate %1$d: %2$s', 'ffcertificate' ),
						$last_id,
						$detail
					)
					: sprintf(
						/* translators: %d: candidate ID */
						__( 'Could not write the re-encrypted values for recruitment candidate %d.', 'ffcertificate' ),
						$last_id
					);
				continue;
			}

			++$processed;
		}

		$this->set_cursor( self::TARGET_RECRUITMENT, $last_id );

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

		if ( ! $this->is_decoupled() ) {
			return new WP_Error(
				'encryption_not_decoupled',
				__( 'Define both FFC_ENCRYPTION_KEY and FFC_HASH_SALT (32+ chars each) in wp-config.php first. See Settings → Advanced → Encryption Key Health.', 'ffcertificate' )
			);
		}

		return true;
	}

	/**
	 * Whether BOTH decoupling constants are in place.
	 *
	 * A seam, and a deliberate one: `execute()` re-checks this even though
	 * `MigrationStatusCalculator::execute_migration()` already gates on
	 * `can_run()`. The redundancy is worth keeping because this path WRITES
	 * ciphertext -- a caller that skipped the gate would not merely fail, it
	 * would re-encrypt every row under the WordPress-derived key and persist
	 * it, making the situation worse than doing nothing. Reading the flag
	 * through an overridable method is what lets a test drive the guarded code
	 * without defining `FFC_ENCRYPTION_KEY`, which is process-wide and would
	 * change `Encryption` for every test that runs afterwards (the order
	 * dependence the CLAUDE.md records).
	 *
	 * @return bool
	 */
	protected function is_decoupled(): bool {
		$health = Encryption::key_health_report();

		return ! empty( $health['encryption_decoupled'] ) && ! empty( $health['salt_decoupled'] );
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema probe inside a migration: the answer must reflect the live schema, so caching would be precisely wrong, and WordPress exposes no API for it.
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

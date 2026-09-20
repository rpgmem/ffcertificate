<?php
/**
 * IdentityIndexBackfillMigrationStrategy
 *
 * Projects the identifiers already linked to a user in the module tables into
 * the identity index, `ffc_user_profiles` (#1313 PR 8).
 *
 * WHY ANYTHING IS LEFT TO BACKFILL
 *
 * The index is written forward: every resolution through
 * `UserCreator::get_or_create_user_dual()` feeds it, and since #1313 PR 7 that
 * is every module. Nothing walks BACKWARDS over what was linked before.
 * `UserDashboardActivator::backfill_identity_index()` copies the legacy hash
 * meta keys out of `wp_usermeta`, which only ever held what the user PROFILE
 * wrote -- a person whose sole record is a certificate or an appointment has
 * no such meta and stays invisible. The canonicalisation card (#1313 PR 3)
 * rewrites values in place and writes no index either.
 *
 * So without this the index converges only over people who transact again
 * after the release, which is a subset nobody can state, and the resolver's
 * fallback to `ffc_submissions` can never be removed.
 *
 * IT FILLS AN EMPTY COLUMN AND NEVER PICKS
 *
 * Where a user carries two DIFFERENT hashes for the same field across the
 * module tables, this is not noise to resolve -- it is two identities under
 * one account, which is the conflict #1313 wants COUNTED before anyone designs
 * a merge policy. The column is therefore left empty and the disagreement
 * survives in the source rows, where the auditor can find it. A `MAX()` under
 * a `GROUP BY` would make the SQL simpler, write a plausible-looking answer,
 * and destroy the only evidence that the question exists.
 *
 * The same rule in the other direction: a column that already holds a hash is
 * never overwritten, because the forward path
 * (`UserCreator::feed_identity_index()`) states exactly that and a backfill
 * that disagreed with it would make the index depend on which ran last.
 *
 * THE ORDER IS FORCED, AND `can_run()` IS WHERE THAT LIVES
 *
 * A hash copied here before the canonicalisation card has run is a hash of
 * whatever spelling arrived, and it lands in a profile row that may hold no
 * ciphertext for that field -- so the canonicalisation card, which repairs a
 * profile hash by decrypting the profile's OWN ciphertext, cannot fix it
 * afterwards. There is no second chance, so this card refuses to run while
 * that one reports pending rows.
 *
 * WHAT IT DOES NOT DO
 *
 * It keeps no count of the conflicts it declined to resolve. A counter here
 * would be a second place to read the same fact, and the `cpf_rf_encrypted`
 * precedent is explicit that a parallel counter beside an existing signal is
 * redundant indirection: the conflicting rows are in the tables, and reporting
 * them belongs to the auditor that already reports `shared_identities`.
 *
 * @package FreeFormCertificate\Migrations\Strategies
 * @since 6.26.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Migrations\Strategies;

use WP_Error;
use FreeFormCertificate\Repositories\UserProfileRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backfills `ffc_user_profiles` from the module tables.
 */
class IdentityIndexBackfillMigrationStrategy implements MigrationStrategyInterface {

	/**
	 * Where the cursor lives.
	 *
	 * The cursor and nothing else. This option also carried a `completed`
	 * boolean until the card was made to measure instead of latch; an install
	 * upgraded from before that still has the key and it is simply never read.
	 * It is not deleted, because a write to remove a value nobody consults
	 * buys nothing.
	 */
	private const STATE_OPTION = 'ffc_identity_index_backfill_state';

	/**
	 * Users per batch.
	 *
	 * The unit is a USER, not a row: a person with four hundred submissions is
	 * one decision, and batching by row would walk the same person hundreds of
	 * times to reach the same answer.
	 */
	private const BATCH_SIZE = 50;

	/**
	 * The index columns this card fills, and nothing else.
	 *
	 * `email_hash` is deliberately absent: the profile declares no such column,
	 * because `wp_users.user_email` is already uniquely indexed and a third
	 * copy would answer a question WordPress answers (#1313 PR 2).
	 *
	 * @var list<string>
	 */
	private const INDEX_COLUMNS = array( 'cpf_hash', 'rf_hash' );

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $migration_key    Registry key.
	 * @param array<string, mixed> $migration_config Registry config.
	 * @return array<string, mixed>
	 */
	public function calculate_status( string $migration_key, array $migration_config ): array {
		unset( $migration_key, $migration_config );

		// NOTHING IS LATCHED, AND THE MEASUREMENT IS CHEAPER THAN THE FLAG WAS
		//
		// This card used to short-circuit on a stored `completed` boolean and
		// answer 100% WITHOUT counting -- the only migration in the plugin
		// that could not tell on its own whether there was work to do. Five
		// siblings compute `pending` from the data; three latch but anchor on
		// the encryption-key fingerprint, so a key change re-arms them. This
		// one latched on nothing, so the walk finishing once made it complete
		// forever, and a user linked afterwards was invisible.
		//
		// Removing it costs nothing: the flagged path still ran one union for
		// the total, and the unflagged path ran two. One statement now answers
		// both counts, so the card is never more expensive than before and is
		// half the cost while the walk is in progress.
		//
		// PROGRESS IS STILL THE CURSOR. A count of users who still NEED a
		// column filled would mean joining the index against every source, and
		// it would answer a different question anyway -- see the note on
		// `execute()` about what "complete" means for THIS card.
		$counts   = $this->counts( $this->get_cursor() );
		$total    = $counts['total'];
		$pending  = $counts['beyond'];
		$migrated = max( 0, $total - $pending );

		return array(
			'total'       => $total,
			'migrated'    => $migrated,
			'pending'     => $pending,
			'percent'     => ( $total > 0 ) ? round( ( $migrated / $total ) * 100, 2 ) : 100.0,
			'is_complete' => ( 0 === $pending ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $migration_key    Registry key.
	 * @param array<string, mixed> $migration_config Registry config.
	 * @param int                  $batch_number     Ignored; the cursor is the position.
	 * @return array<string, mixed>
	 */
	public function execute( string $migration_key, array $migration_config, int $batch_number = 0 ): array {
		unset( $migration_key, $migration_config, $batch_number );

		// The gate is re-read here and not only by the calculator. This path
		// WRITES, and a caller that reached it another way would otherwise
		// copy pre-canonical hashes into rows nothing can repair.
		$can_run = $this->can_run( '', array() );
		if ( $can_run instanceof WP_Error ) {
			return array(
				'success'   => false,
				'processed' => 0,
				'message'   => $can_run->get_error_message(),
			);
		}

		$users = $this->next_users( $this->get_cursor(), self::BATCH_SIZE );

		if ( array() === $users ) {
			// Nothing is recorded. The cursor already sits past the last user
			// that carried a linked identifier, so `calculate_status()` reads
			// zero beyond it and says complete -- and it says so again, or
			// stops saying so, every time it is asked.
			//
			// WHAT "COMPLETE" MEANS HERE, PRECISELY
			//
			// That this card filled everything IT can fill. An account holding
			// two different hashes for one field is left empty on purpose (see
			// the class note), so a hundred per cent here never claims the
			// index is whole -- only that nothing is left that a backfill is
			// allowed to decide. Reporting those conflicts is the audit's job,
			// and folding them into this bar would hide a decision behind a
			// progress percentage.
			return array(
				'success'   => true,
				'processed' => 0,
				'message'   => __( 'Every linked identifier a backfill may resolve is in the index.', 'ffcertificate' ),
			);
		}

		$repository = new UserProfileRepository();
		$filled     = 0;
		$conflicts  = 0;

		foreach ( $users as $user_id ) {
			$outcome    = $this->backfill_user( $repository, $user_id );
			$filled    += $outcome['filled'];
			$conflicts += $outcome['conflicts'];
			$this->set_cursor( $user_id );
		}

		return array(
			'success'   => true,
			'processed' => count( $users ),
			'message'   => sprintf(
				/* translators: 1: number of users examined, 2: number of index columns filled, 3: number of columns left empty because the user carries two different identifiers */
				__( 'Examined %1$d users, filled %2$d index columns, left %3$d unresolved because one account carries two different identifiers.', 'ffcertificate' ),
				count( $users ),
				$filled,
				$conflicts
			),
		);
	}

	/**
	 * Fill one user's empty index columns.
	 *
	 * @param UserProfileRepository $repository Index repository.
	 * @param int                   $user_id    User.
	 * @return array{filled: int, conflicts: int}
	 */
	private function backfill_user( UserProfileRepository $repository, int $user_id ): array {
		$row   = $repository->findByUserId( $user_id );
		$index = array();

		$filled    = 0;
		$conflicts = 0;

		foreach ( self::INDEX_COLUMNS as $column ) {
			$stored = null !== $row ? ( $row[ $column ] ?? null ) : null;
			if ( is_string( $stored ) && '' !== $stored ) {
				// Already answered. Never overwritten -- see the class note.
				continue;
			}

			$candidates = $this->distinct_hashes( $user_id, $column );

			if ( 1 !== count( $candidates ) ) {
				// Zero: this user has no such identifier anywhere, which is
				// ordinary. Two or more: one account, two identities -- left
				// for the auditor rather than resolved by picking.
				if ( count( $candidates ) > 1 ) {
					++$conflicts;
				}
				continue;
			}

			$index[ $column ] = $candidates[0];
			++$filled;
		}

		if ( array() !== $index ) {
			$repository->upsertForUserId( $user_id, $index );
		}

		return array(
			'filled'    => $filled,
			'conflicts' => $conflicts,
		);
	}

	/**
	 * The distinct hashes one user carries for one column, capped at two.
	 *
	 * Two is all the caller can act on: exactly one is a fill, more than one is
	 * a conflict, and knowing whether it is three or thirty changes nothing
	 * here while costing a full scan of that user's rows.
	 *
	 * @param int    $user_id User.
	 * @param string $column  `cpf_hash` or `rf_hash`.
	 * @return list<string>
	 */
	private function distinct_hashes( int $user_id, string $column ): array {
		global $wpdb;

		$tables = $this->source_tables();
		if ( array() === $tables ) {
			return array();
		}

		$parts  = array();
		$values = array();

		foreach ( $tables as $table ) {
			$parts[]  = "SELECT %i AS h FROM %i WHERE user_id = %d AND %i IS NOT NULL AND %i <> ''";
			$values[] = $column;
			$values[] = $table;
			$values[] = $user_id;
			$values[] = $column;
			$values[] = $column;
		}

		$union = implode( ' UNION ', $parts );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $union is built from the hard-coded fragment above, once per resolved table, with every placeholder and its value appended in the same loop; the table names come from a fixed list this class resolves itself, never from a request. A migration read over the plugin's own tables must not be served from cache.
		$found = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT h FROM ({$union}) u LIMIT 2", ...$values ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$out = array();
		foreach ( (array) $found as $hash ) {
			if ( is_string( $hash ) && '' !== $hash ) {
				$out[] = $hash;
			}
		}

		return $out;
	}

	/**
	 * The next users to examine, in ascending id order.
	 *
	 * @param int $after Cursor.
	 * @param int $limit Batch size.
	 * @return list<int>
	 */
	private function next_users( int $after, int $limit ): array {
		global $wpdb;

		$union = $this->linked_users_union( $after );
		if ( null === $union ) {
			return array();
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As in `distinct_hashes()`: the union is assembled from a hard-coded fragment per resolved table, with its values collected in the same loop, and a migration read must never come from cache.
		$found = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT user_id FROM ({$union['sql']}) u ORDER BY user_id ASC LIMIT %d",
				...array_merge( $union['values'], array( $limit ) )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$out = array();
		foreach ( (array) $found as $user_id ) {
			$out[] = (int) $user_id;
		}

		return $out;
	}

	/**
	 * How many linked users there are, and how many sit beyond the cursor.
	 *
	 * ONE STATEMENT, BECAUSE THE UNION IS THE EXPENSIVE PART
	 *
	 * The two numbers used to be two calls, each building and scanning the
	 * same union over every source table. They differ only in a predicate on
	 * `user_id`, so a conditional `COUNT(DISTINCT …)` answers both from one
	 * scan -- which is what makes dropping the completion flag free: the card
	 * now issues ONE union where it previously issued two mid-walk and one
	 * when latched.
	 *
	 * `COUNT(DISTINCT CASE WHEN … END)` and not a `SUM`, because the same user
	 * appears once per source table that carries an identifier for them, and
	 * the question is how many USERS are left rather than how many rows.
	 *
	 * @param int $after Cursor.
	 * @return array{total: int, beyond: int}
	 */
	private function counts( int $after ): array {
		global $wpdb;

		$union = $this->linked_users_union( 0 );
		if ( null === $union ) {
			return array(
				'total'  => 0,
				'beyond' => 0,
			);
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As in `distinct_hashes()`: a union of hard-coded fragments over tables this class resolves itself. `$after` is bound first because its placeholder comes first in the statement. A migration count must reflect the live tables rather than a cache.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT user_id) AS total,
                        COUNT(DISTINCT CASE WHEN user_id > %d THEN user_id END) AS beyond
                   FROM ({$union['sql']}) u",
				$after,
				...$union['values']
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$row    = is_array( $row ) ? $row : array();
		$total  = $row['total'] ?? 0;
		$beyond = $row['beyond'] ?? 0;

		return array(
			'total'  => is_numeric( $total ) ? (int) $total : 0,
			'beyond' => is_numeric( $beyond ) ? (int) $beyond : 0,
		);
	}

	/**
	 * The `UNION` over every source table's linked, identifier-carrying rows.
	 *
	 * @param int $after Cursor.
	 * @return array{sql: string, values: list<mixed>}|null Null when no source table exists.
	 */
	private function linked_users_union( int $after ): ?array {
		$tables = $this->source_tables();
		if ( array() === $tables ) {
			return null;
		}

		$parts  = array();
		$values = array();

		foreach ( $tables as $table ) {
			$conditions = array();
			foreach ( self::INDEX_COLUMNS as $column ) {
				$conditions[] = "(%i IS NOT NULL AND %i <> '')";
			}
			$parts[] = 'SELECT user_id FROM %i WHERE user_id IS NOT NULL AND user_id > %d AND ( ' . implode( ' OR ', $conditions ) . ' )';

			$values[] = $table;
			$values[] = $after;
			foreach ( self::INDEX_COLUMNS as $column ) {
				$values[] = $column;
				$values[] = $column;
			}
		}

		return array(
			'sql'    => implode( ' UNION ', $parts ),
			'values' => $values,
		);
	}

	/**
	 * The module tables that exist on this install.
	 *
	 * Resolved rather than assumed: a site that never activated recruitment
	 * has no candidate table, and a `UNION` naming it fails the whole query.
	 *
	 * @return list<string>
	 */
	private function source_tables(): array {
		global $wpdb;

		$out = array();

		foreach ( array(
			'ffc_submissions',
			'ffc_self_scheduling_appointments',
			'ffc_recruitment_candidate',
		) as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			if ( $this->table_exists( $table ) ) {
				$out[] = $table;
			}
		}

		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $migration_key    Registry key.
	 * @param array<string, mixed> $migration_config Registry config.
	 * @return true|WP_Error
	 */
	public function can_run( string $migration_key, array $migration_config ) {
		unset( $migration_key, $migration_config );

		if ( ! $this->table_exists( $this->index_table() ) ) {
			return new WP_Error(
				'identity_index_missing',
				__( 'The identity index table does not exist yet. Reactivate the plugin so it is created, then run this again.', 'ffcertificate' )
			);
		}

		$pending = $this->canonicalisation_pending();

		if ( $pending > 0 ) {
			return new WP_Error(
				'canonicalisation_pending',
				sprintf(
					/* translators: %d: number of rows the canonicalisation migration has not yet processed */
					__( 'Run "Canonicalise Stored Identifiers" to completion first: %d rows are still pending. A hash copied into the index before then is a hash of whatever spelling arrived, and nothing can repair it afterwards.', 'ffcertificate' ),
					$pending
				)
			);
		}

		return true;
	}

	/**
	 * How many rows the canonicalisation card still has to walk.
	 *
	 * A seam so a test can drive both sides of the gate without standing up
	 * that card's own state, which is the same reason its sibling exposes
	 * `encryption_available()`.
	 *
	 * @return int
	 */
	protected function canonicalisation_pending(): int {
		$status  = ( new IdentityNormalizationMigrationStrategy() )->calculate_status( '', array() );
		$pending = $status['pending'] ?? null;

		// Checked rather than cast. The sibling's status array is
		// `array<string, mixed>`, so a blind `(int)` would turn anything at all
		// into a number -- and the number this reads decides whether a write
		// that cannot be undone is allowed. A shape that is not numeric means
		// the question was not answered, which is not the same as "zero
		// pending", so it fails closed on 1 rather than open on 0.
		if ( is_numeric( $pending ) ) {
			return (int) $pending;
		}

		return 1;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Backfill the Identity Index', 'ffcertificate' );
	}

	/**
	 * The index table.
	 *
	 * @return string
	 */
	private function index_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'ffc_user_profiles';
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema probe inside a migration: the answer must reflect the live schema, so caching would be precisely wrong.
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
	 * The highest user id already examined.
	 *
	 * @return int
	 */
	private function get_cursor(): int {
		$state  = $this->get_state();
		$cursor = $state['cursor'] ?? null;

		// `get_option()` returns whatever is stored, so the value is checked
		// before it is trusted -- the idiom `IdentityNormalizationMigrationStrategy`
		// uses for its own cursors. A non-numeric cursor restarts the walk,
		// which is safe here: every step is idempotent.
		return is_numeric( $cursor ) ? (int) $cursor : 0;
	}

	/**
	 * Advance the cursor.
	 *
	 * @param int $user_id Last user examined.
	 * @return void
	 */
	private function set_cursor( int $user_id ): void {
		$state           = $this->get_state();
		$state['cursor'] = $user_id;
		$this->put_state( $state );
	}
}

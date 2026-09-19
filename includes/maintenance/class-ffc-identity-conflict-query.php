<?php
/**
 * IdentityConflictQuery
 *
 * The questions about identity that belong to no single module (#1313 PR 10).
 *
 * WHY THIS IS NOT ON A READER
 *
 * `SubmissionReader` answers them for `ffc_submissions`, and that is right:
 * they are questions about its own rows. But "does one person hold two
 * accounts" is not a submissions question, an appointments question or a
 * recruitment question -- it is only visible ACROSS them, and putting it on any
 * one reader would make that reader know about the other two. So it lives with
 * the auditor that reports it.
 *
 * WHY THE AUDITOR IS WHERE THE NUMBER COMES FROM
 *
 * #1313's backfill fills an empty index column and deliberately never picks
 * between two different hashes, because that is a conflict to count before
 * anyone designs a merge policy -- and it keeps no counter of its own, since a
 * parallel counter beside an existing signal is the indirection the
 * `cpf_rf_encrypted` precedent rejects. This is that existing signal, widened.
 *
 * EVERY QUERY HERE IS READ-ONLY, AND THAT IS STRUCTURAL
 *
 * The tool that uses it declares `is_actionable() === false`. Re-linking
 * identity records automatically is the decision nobody has made yet; the
 * report exists so a person can make it.
 *
 * @package FreeFormCertificate\Maintenance
 * @since 6.26.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Maintenance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only cross-store identity questions.
 */
class IdentityConflictQuery {

	/**
	 * The identifier columns every store shares.
	 *
	 * `email_hash` is not among them on purpose: the index declares no such
	 * column, because `wp_users.user_email` is already uniquely indexed and a
	 * third copy would answer a question WordPress answers (#1313 PR 2).
	 *
	 * @var list<string>
	 */
	private const COLUMNS = array( 'cpf_hash', 'rf_hash' );

	/**
	 * The count column `shared_identities()` returns.
	 *
	 * A CONSTANT rather than a literal because a consumer has to read it by
	 * name, and a consumer reading a name this class does not emit gets an
	 * empty column with nothing to say it is empty. That shipped: the export
	 * looked for `identifier_count` while this class emitted `identity_count`,
	 * and the test that should have caught it supplied the wrong name too --
	 * asserting against the value the test itself provides, which is the shape
	 * `AssertionCoverageTest` exists for and which no static checker sees.
	 *
	 * @var string
	 */
	public const ALIAS_USER_COUNT = 'user_count';

	/**
	 * The count column `multiple_identities()` returns. See above.
	 *
	 * @var string
	 */
	public const ALIAS_IDENTITY_COUNT = 'identity_count';

	/**
	 * The column naming which stores an identifier was found in.
	 *
	 * @var string
	 */
	public const COLUMN_STORES = 'stores';

	/**
	 * The column carrying the values the count above only COUNTED.
	 *
	 * `grouped()` aliases whatever it grouped BY as `subject` and aggregates
	 * the other half away, so each direction used to destroy exactly what the
	 * other half needs: the shared checks said an identifier belongs to two
	 * accounts without naming them, and the multiple checks said an account
	 * holds two identifiers without naming those. Neither is a lead anybody
	 * can act on, which is the property this class's own note claims for the
	 * report (#1344).
	 *
	 * @var string
	 */
	public const COLUMN_RELATED = 'related';

	/**
	 * Set when `related` holds FEWER values than the count beside it.
	 *
	 * `GROUP_CONCAT` stops at `group_concat_max_len` -- 1024 bytes by default
	 * -- and drops the tail WITHOUT an error, so a short list reads exactly
	 * like a complete one. At 65 bytes per hash that is 15 identifiers, and
	 * production already holds an account with 11: the headroom is four, not a
	 * theoretical margin. The count is aggregated separately and is never
	 * truncated, so comparing the two costs nothing and is the only way to see
	 * it happen. Never count as clean what was not fully read.
	 *
	 * @var string
	 */
	public const COLUMN_RELATED_TRUNCATED = 'related_truncated';

	/**
	 * What separates the values inside `related` and `stores`.
	 *
	 * Safe for both payloads by construction: a hex hash and a decimal id can
	 * contain neither this character nor any other delimiter.
	 *
	 * @var string
	 */
	public const RELATED_SEPARATOR = '|';

	/**
	 * The stores that carry an identifier alongside a `user_id`.
	 *
	 * Resolved against the live schema rather than assumed: a site that never
	 * activated recruitment has no candidate table, and a `UNION` naming a
	 * missing table fails the whole query rather than skipping it.
	 *
	 * @return list<string>
	 */
	private function stores(): array {
		global $wpdb;

		$out = array();

		foreach ( array(
			'ffc_submissions',
			'ffc_self_scheduling_appointments',
			'ffc_recruitment_candidate',
			'ffc_user_profiles',
		) as $suffix ) {
			$table = $wpdb->prefix . $suffix;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema probe: the answer must reflect the live schema, so a cached one would be precisely wrong.
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
				$out[] = $table;
			}
		}

		return $out;
	}

	/**
	 * A store's name without the site's table prefix, for a report a person
	 * reads. `wp_ffc_submissions` becomes `submissions`.
	 *
	 * @param string $table Full table name.
	 * @return string
	 */
	private function label( string $table ): string {
		global $wpdb;

		$short = $table;
		if ( 0 === strpos( $short, $wpdb->prefix ) ) {
			$short = substr( $short, strlen( $wpdb->prefix ) );
		}
		if ( 0 === strpos( $short, 'ffc_' ) ) {
			$short = substr( $short, 4 );
		}

		return $short;
	}

	/**
	 * Every `(user_id, hash, src)` triple the plugin holds, as one derived
	 * table. `src` is the store's short label, so a finding can name where the
	 * rows are rather than only that they disagree.
	 *
	 * @param string $column Identifier column.
	 * @return array{sql: string, values: list<mixed>}|null Null when no store exists.
	 */
	private function pairs( string $column ): ?array {
		$stores = $this->stores();
		if ( array() === $stores ) {
			return null;
		}

		$parts  = array();
		$values = array();

		foreach ( $stores as $table ) {
			// The table name travels WITH the pair, so a finding can say where
			// to look. Without it the union answers "this person has two CPFs"
			// and leaves the operator to search four screens for the rows --
			// the same "a lead you cannot act on is not a lead" that made the
			// export necessary in the first place (#1295).
			$parts[]  = "SELECT user_id, %i AS h, %s AS src FROM %i WHERE user_id IS NOT NULL AND user_id <> 0 AND %i IS NOT NULL AND %i <> ''";
			$values[] = $column;
			$values[] = $this->label( $table );
			$values[] = $table;
			$values[] = $column;
			$values[] = $column;
		}

		return array(
			'sql'    => implode( ' UNION ', $parts ),
			'values' => $values,
		);
	}

	/**
	 * One identifier held by more than one user, anywhere.
	 *
	 * The sharpest reading of "two accounts, one person". It is what #1313's
	 * acceptance criteria ask to be counted, and what a merge policy would have
	 * to be designed against.
	 *
	 * @param int $limit Sample size.
	 * @return array<int, array<string, mixed>>
	 */
	public function shared_identities( int $limit = 50 ): array {
		return $this->grouped( 'h', 'user_id', self::ALIAS_USER_COUNT, max( 1, $limit ) );
	}

	/**
	 * One user holding more than one identifier of the same kind, anywhere.
	 *
	 * The mirror image, and a different defect: not two accounts for one
	 * person, but one account that has accumulated two people -- or one person
	 * whose CPF was typed differently twice before #1313's canonicalisation.
	 *
	 * @param int $limit Sample size.
	 * @return array<int, array<string, mixed>>
	 */
	public function multiple_identities( int $limit = 50 ): array {
		return $this->grouped( 'user_id', 'h', self::ALIAS_IDENTITY_COUNT, max( 1, $limit ) );
	}

	/**
	 * Linked identifiers the index does not carry.
	 *
	 * What #1313's backfill exists to empty, reported for a real install: until
	 * this reads zero, resolving a person still falls through to scanning the
	 * module tables.
	 *
	 * `HAVING COUNT(DISTINCT p.h) = 1` IS THE WHOLE POINT, AND IT WAS MISSING
	 *
	 * The backfill leaves a column empty when the user carries more than one
	 * distinct hash for it, on purpose -- picking would destroy the evidence
	 * that the conflict exists. Without this clause every such account is ALSO
	 * reported here, so the check could never reach zero and read as a failure
	 * to an operator who had just run the backfill to completion. It did, on
	 * the first production run (#1333).
	 *
	 * Narrowed to what the backfill COULD have resolved, zero means what it was
	 * always supposed to mean, and this check becomes disjoint from
	 * `multiple_identities()` rather than a second voice for the same rows.
	 *
	 * @param int $limit Sample size.
	 * @return array<int, array<string, mixed>>
	 */
	public function unindexed_links( int $limit = 50 ): array {
		global $wpdb;

		$limit    = max( 1, $limit );
		$profiles = $wpdb->prefix . 'ffc_user_profiles';
		$out      = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema probe, as above.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $profiles ) ) !== $profiles ) {
			return $out;
		}

		foreach ( self::COLUMNS as $column ) {
			$pairs = $this->pairs( $column );
			if ( null === $pairs ) {
				continue;
			}

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The union is built from the hard-coded fragment in `pairs()`, once per table this class resolved itself, with its placeholders and values filled in the same loop. An audit read must reflect the live rows, never a cache.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.user_id,
                            GROUP_CONCAT(DISTINCT p.src ORDER BY p.src SEPARATOR '|') AS stores,
                            %s AS identifier_column
                     FROM ({$pairs['sql']}) AS p
                     LEFT JOIN %i AS idx ON idx.user_id = p.user_id
                     WHERE idx.user_id IS NULL OR idx.%i IS NULL OR idx.%i = ''
                     GROUP BY p.user_id
                     HAVING COUNT(DISTINCT p.h) = 1
                     ORDER BY p.user_id ASC
                     LIMIT %d",
					...array_merge( array( $column ), $pairs['values'], array( $profiles, $column, $column, $limit ) )
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			foreach ( (array) $rows as $row ) {
				$out[] = (array) $row;
			}
		}

		return array_slice( $out, 0, $limit );
	}

	/**
	 * Group the `(user_id, hash)` pairs one way and report the collisions.
	 *
	 * @param string $group_by   Column to group by, `h` or `user_id`.
	 * @param string $count_over Column whose distinct values are counted.
	 * @param string $alias      Name of the count in the result.
	 * @param int    $limit      Sample size.
	 * @return array<int, array<string, mixed>>
	 */
	private function grouped( string $group_by, string $count_over, string $alias, int $limit ): array {
		global $wpdb;

		$out = array();

		foreach ( self::COLUMNS as $column ) {
			$pairs = $this->pairs( $column );
			if ( null === $pairs ) {
				continue;
			}

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- As in `unindexed_links()`: `$group_by`, `$count_over` and `$alias` are this class's own literals, never request data, and the union comes from `pairs()`.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT {$group_by} AS subject, COUNT(DISTINCT {$count_over}) AS {$alias},
                            GROUP_CONCAT(DISTINCT {$count_over} ORDER BY {$count_over} SEPARATOR '|') AS related,
                            GROUP_CONCAT(DISTINCT p.src ORDER BY p.src SEPARATOR '|') AS stores,
                            %s AS identifier_column
                     FROM ({$pairs['sql']}) AS p
                     GROUP BY {$group_by}
                     HAVING COUNT(DISTINCT {$count_over}) > 1
                     ORDER BY {$alias} DESC
                     LIMIT %d",
					...array_merge( array( $column ), $pairs['values'], array( $limit ) )
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			foreach ( (array) $rows as $row ) {
				$out[] = self::flag_truncated_list( (array) $row, $alias );
			}
		}

		return array_slice( $out, 0, $limit );
	}

	/**
	 * Mark a row whose `related` list is shorter than its own count.
	 *
	 * See {@see self::COLUMN_RELATED_TRUNCATED}: the concatenation truncates in
	 * silence and the count does not, so their disagreement is the only signal
	 * that exists. The row is kept -- dropping it would hide the finding
	 * entirely -- and the flag is what stops a partial list from being read as
	 * the whole one.
	 *
	 * @param array<string, mixed> $row   One grouped row.
	 * @param string               $alias Name of the count column on that row.
	 * @return array<string, mixed>
	 */
	private static function flag_truncated_list( array $row, string $alias ): array {
		$joined  = $row[ self::COLUMN_RELATED ] ?? '';
		$related = is_string( $joined ) ? $joined : '';
		$listed  = '' === $related ? 0 : count( explode( self::RELATED_SEPARATOR, $related ) );

		$total   = $row[ $alias ] ?? 0;
		$counted = is_numeric( $total ) ? (int) $total : 0;

		$row[ self::COLUMN_RELATED_TRUNCATED ] = ( $listed !== $counted );

		return $row;
	}
}

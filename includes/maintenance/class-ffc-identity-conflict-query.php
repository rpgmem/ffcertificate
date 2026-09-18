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
	 * Every `(user_id, hash)` pair the plugin holds, as one derived table.
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
			$parts[]  = "SELECT user_id, %i AS h FROM %i WHERE user_id IS NOT NULL AND user_id <> 0 AND %i IS NOT NULL AND %i <> ''";
			$values[] = $column;
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
		return $this->grouped( 'h', 'user_id', 'user_count', max( 1, $limit ) );
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
		return $this->grouped( 'user_id', 'h', 'identity_count', max( 1, $limit ) );
	}

	/**
	 * Linked identifiers the index does not carry.
	 *
	 * What #1313's backfill exists to empty, reported for a real install: until
	 * this reads zero, resolving a person still falls through to scanning the
	 * module tables. A row whose user holds a DIFFERENT hash is not counted
	 * here -- that is a conflict, and `shared_identities()` above is where it
	 * belongs.
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
					"SELECT DISTINCT p.user_id, %s AS identifier_column
                     FROM ({$pairs['sql']}) AS p
                     LEFT JOIN %i AS idx ON idx.user_id = p.user_id
                     WHERE idx.user_id IS NULL OR idx.%i IS NULL OR idx.%i = ''
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
					"SELECT {$group_by} AS subject, COUNT(DISTINCT {$count_over}) AS {$alias}, %s AS identifier_column
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
				$out[] = (array) $row;
			}
		}

		return array_slice( $out, 0, $limit );
	}
}

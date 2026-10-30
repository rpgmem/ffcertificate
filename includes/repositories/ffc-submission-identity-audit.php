<?php
/**
 * SubmissionIdentityAudit
 *
 * The read-only queries behind the identity audit (orphan links, accounts
 * holding several identities, unlinked rows that match a linked identity, one
 * identity on several accounts). They lived in `SubmissionReader` until #2241
 * moved them out: they answer an integrity question about the whole table,
 * not a lookup a submission screen makes, and the reader was over a thousand
 * lines. `SubmissionRepository` still exposes them, so no caller changed.
 *
 * @package FreeFormCertificate\Repositories
 * @since   6.43.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- The sniff only recognises the global $wpdb->prepare(); this class binds wpdb as a property, so every $this->wpdb->prepare() call reads as unprepared SQL. Table names go through %i and every value through a placeholder; {$post_id} is an int-typed parameter.
/**
 * Identity-audit queries over the submissions table.
 */
final class SubmissionIdentityAudit {

	/**
	 * Database handle.
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Submissions table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->wpdb  = $wpdb;
		$this->table = SubmissionRepository::get_submissions_table();
	}

	/**
	 * Audit: submissions whose `user_id` points to a WP user that no longer
	 * exists (orphaned link after the user was deleted). Read-only.
	 *
	 * @param int $limit Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function find_orphan_user_links( int $limit = 50 ): array {
		$limit = max( 1, $limit );
		$rows  = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT s.id, s.user_id, s.form_id FROM %i s LEFT JOIN %i u ON u.ID = s.user_id WHERE s.user_id IS NOT NULL AND s.user_id <> 0 AND u.ID IS NULL ORDER BY s.id DESC LIMIT %d',
				$this->table,
				$this->wpdb->users,
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Audit: WP users linked to submissions carrying more than one distinct
	 * `cpf_hash` (or `rf_hash`) — i.e. a single user bound to multiple
	 * identities, the detectable form of "the submission CPF doesn't match the
	 * linked user" (CPF/RF are not stored on the user side to compare against).
	 * Read-only.
	 *
	 * CPF AND RF ARE COUNTED SEPARATELY, AND THAT IS THE POINT
	 *
	 * One CPF plus one RF is an ordinary person, not a conflict. Each column
	 * gets its own `COUNT(DISTINCT)` and the `HAVING` asks for more than one
	 * of EITHER, so a user with one of each scores 1 and 1 and is not reported.
	 *
	 * `NULLIF( …, '' )` IS NOT DECORATION
	 *
	 * `COUNT(DISTINCT)` ignores NULL but counts the empty string as a value, so
	 * a user with a real hash on some rows and `''` on others scored 2 and was
	 * reported as holding two identities. Both siblings in this class filter
	 * `<> ''` and this one did not, which is how the asymmetry was found; the
	 * fix is here rather than in a `WHERE` because a row whose CPF is empty may
	 * still carry a real RF, and a `WHERE` would drop it from both counts
	 * (#1295).
	 *
	 * IT NAMES THE HASHES, NOT ONLY HOW MANY
	 *
	 * A count alone says an account holds two identifiers and withholds which
	 * two, so there is nothing to choose between -- the same half-answer the
	 * cross-store sibling gave until #1344. The `GROUP_CONCAT` carries the
	 * values the `COUNT` aggregates away, per column, so the consumer that
	 * picks a column by count picks the matching list with it.
	 *
	 * @param int $limit Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function find_users_with_multiple_identities( int $limit = 50 ): array {
		$limit = max( 1, $limit );
		$rows  = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT user_id, COUNT(DISTINCT NULLIF( cpf_hash, '' )) AS cpf_count, COUNT(DISTINCT NULLIF( rf_hash, '' )) AS rf_count, GROUP_CONCAT(DISTINCT NULLIF( cpf_hash, '' ) ORDER BY cpf_hash SEPARATOR '|') AS cpf_related, GROUP_CONCAT(DISTINCT NULLIF( rf_hash, '' ) ORDER BY rf_hash SEPARATOR '|') AS rf_related FROM %i WHERE user_id IS NOT NULL AND user_id <> 0 GROUP BY user_id HAVING COUNT(DISTINCT NULLIF( cpf_hash, '' )) > 1 OR COUNT(DISTINCT NULLIF( rf_hash, '' )) > 1 ORDER BY user_id ASC LIMIT %d",
				$this->table,
				$limit
			),
			ARRAY_A
		);

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row                          = (array) $row;
			$row['cpf_related_truncated'] = self::list_is_short( $row, 'cpf_related', 'cpf_count' );
			$row['rf_related_truncated']  = self::list_is_short( $row, 'rf_related', 'rf_count' );
			$out[]                        = $row;
		}

		return $out;
	}

	/**
	 * Whether a `GROUP_CONCAT` list holds fewer values than its own count.
	 *
	 * `GROUP_CONCAT` stops at `group_concat_max_len` and drops the tail with no
	 * error, so a short list reads exactly like a complete one; the count is
	 * aggregated separately and is never truncated, which makes their
	 * disagreement the only signal that exists (#1344).
	 *
	 * `IdentityConflictQuery` carries the same three lines rather than sharing
	 * them: it lives in `Maintenance` and this class in `Repositories`, and a
	 * helper would buy four lines at the price of a new edge on the
	 * module-boundary baseline. The producer flags its own aggregate, because
	 * a consumer cannot tell a short list from a complete one.
	 *
	 * @param array<string, mixed> $row    One grouped row.
	 * @param string               $values Key holding the concatenated values.
	 * @param string               $count  Key holding the count of those values.
	 * @return bool
	 */
	private static function list_is_short( array $row, string $values, string $count ): bool {
		$raw    = $row[ $values ] ?? '';
		$joined = is_string( $raw ) ? $raw : '';
		$listed = '' === $joined ? 0 : count( explode( '|', $joined ) );

		$total   = $row[ $count ] ?? 0;
		$counted = is_numeric( $total ) ? (int) $total : 0;

		return $counted !== $listed;
	}

	/**
	 * Audit: submissions with no `user_id` whose `cpf_hash` matches another
	 * submission that IS linked — they probably should be linked too. Read-only.
	 *
	 * Matching is by `cpf_hash` (the primary identity in this system); RF-only
	 * matches are not considered here.
	 *
	 * @param int $limit Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function find_unlinked_with_matching_identity( int $limit = 50 ): array {
		$limit = max( 1, $limit );
		$rows  = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT s.id, s.form_id, s.cpf_hash FROM %i s WHERE ( s.user_id IS NULL OR s.user_id = 0 ) AND s.cpf_hash IS NOT NULL AND s.cpf_hash <> '' AND EXISTS ( SELECT 1 FROM %i s2 WHERE s2.cpf_hash = s.cpf_hash AND s2.user_id IS NOT NULL AND s2.user_id <> 0 ) ORDER BY s.id DESC LIMIT %d",
				$this->table,
				$this->table,
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Audit: a single `cpf_hash` shared across more than one linked `user_id`
	 * — an identity collision (same CPF on multiple WP accounts). Read-only.
	 *
	 * IT NAMES THE ACCOUNTS, NOT ONLY HOW MANY
	 *
	 * Knowing that a CPF belongs to two accounts without knowing which two is
	 * not something anyone can act on, and a merge cannot start from it. The
	 * `GROUP_CONCAT` carries the ids the `COUNT` aggregates away (#1344).
	 *
	 * @param int $limit Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function find_shared_identities( int $limit = 50 ): array {
		$limit = max( 1, $limit );
		$rows  = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT cpf_hash, COUNT(DISTINCT user_id) AS user_count, GROUP_CONCAT(DISTINCT user_id ORDER BY user_id SEPARATOR '|') AS related FROM %i WHERE cpf_hash IS NOT NULL AND cpf_hash <> '' AND user_id IS NOT NULL AND user_id <> 0 GROUP BY cpf_hash HAVING COUNT(DISTINCT user_id) > 1 ORDER BY user_count DESC LIMIT %d",
				$this->table,
				$limit
			),
			ARRAY_A
		);

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row                      = (array) $row;
			$row['related_truncated'] = self::list_is_short( $row, 'related', 'user_count' );
			$out[]                    = $row;
		}

		return $out;
	}
}

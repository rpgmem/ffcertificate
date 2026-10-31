<?php
/**
 * Reregistration Submission Reader
 *
 * Read-side of the reregistration-submission repository split (#563 backlog,
 * Sprint D2). Holds every SELECT / lookup / derived-read query and the pure
 * status-label helpers. Writes live in {@see ReregistrationSubmissionWriter}.
 * Callers depend on this reader (reads) and the writer (writes) directly; the
 * delegating façade was retired in #563 B3-A.
 *
 * @since   6.12.0
 * @package FreeFormCertificate\Reregistration
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Table names go through %i and every request value through a placeholder. The interpolated fragments are built here: {$orderby}/{$order} from an in_array() allowlist, {$where_clause}/{$limit_clause} from fragments assembled in this class, {$id} from an int cast.

/**
 * Read queries for reregistration submission records.
 *
 * @since 6.12.0
 *
 * @phpstan-type ReregistrationSubmissionRow \stdClass&object{id: string, reregistration_id: string, user_id: string, status: string, submitted_at: numeric-string|int|null, reviewed_at: numeric-string|int|null, reviewed_by: string|null, notes: string|null, auth_code: string|null, magic_token: string|null, invited_at: numeric-string|int|null, created_at: string, updated_at: string, data?: string|null}
 *
 * The import source submission row (#1213): the submission row plus the two
 * columns the JOIN brings from the source campaign, and `data` as NON-optional
 * (the query is `SELECT s.*`, so the column always comes; the shape above
 * declares it optional because some queries select individual columns).
 *
 * Written out in full rather than as `ReregistrationSubmissionRow&object{...}`:
 * an intersection CANNOT make required a key the other side declares optional,
 * and PHPStan rejects the whole alias with `typeAlias.unresolvableType` -- which
 * degrades the signature back to `object` and brings back exactly the errors
 * this alias exists to remove. The duplication is the price; if a column enters
 * the shape above, it has to enter here too.
 * @phpstan-type ReregistrationImportSourceRow \stdClass&object{id: string, reregistration_id: string, user_id: string, status: string, submitted_at: numeric-string|int|null, reviewed_at: numeric-string|int|null, reviewed_by: string|null, notes: string|null, auth_code: string|null, magic_token: string|null, invited_at: numeric-string|int|null, created_at: string, updated_at: string, data: string|null, reregistration_title: string, start_date: string|null}
 */
class ReregistrationSubmissionReader {
	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Valid submission status values.
	 */
	public const STATUSES = array( 'pending', 'in_progress', 'submitted', 'approved', 'rejected', 'expired' );

	/**
	 * Cache group for reregistration submission queries.
	 *
	 * Must match {@see ReregistrationSubmissionWriter::cache_group()} so writes
	 * invalidate the entries reads populate.
	 *
	 * @return string
	 */
	protected static function cache_group(): string {
		return 'ffc_rereg_submissions';
	}

	/**
	 * Get human-readable status labels.
	 *
	 * @return array<string, string> Status key => translated label.
	 */
	public static function get_status_labels(): array {
		return array(
			'pending'     => __( 'Pending', 'ffcertificate' ),
			'in_progress' => __( 'In Progress', 'ffcertificate' ),
			'submitted'   => __( 'Submitted — Pending Review', 'ffcertificate' ),
			'approved'    => __( 'Approved', 'ffcertificate' ),
			'rejected'    => __( 'Rejected', 'ffcertificate' ),
			'expired'     => __( 'Expired', 'ffcertificate' ),
		);
	}

	/**
	 * Get a single status label.
	 *
	 * @param string $status Status key.
	 * @return string Translated label (falls back to the key).
	 */
	public static function get_status_label( string $status ): string {
		$labels = self::get_status_labels();
		return $labels[ $status ] ?? $status;
	}

	/**
	 * Get table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		return self::db()->prefix . 'ffc_reregistration_submissions';
	}

	/**
	 * Get a submission by ID.
	 *
	 * @param int $id Submission ID.
	 * @return ReregistrationSubmissionRow|null
	 */
	public static function get_by_id( int $id ): ?object {
		/**
		 * The object cache is untyped — `wp_cache_get()` returns mixed — and
		 * this key is the one written a few lines below, so the assertion is
		 * checkable against the `cache_set()` in the same method. It is stated
		 * per key rather than on the trait's `cache_get()` because the cache
		 * is heterogeneous: this class also stores other shapes under other
		 * keys, and one type on the accessor would be a lie for those.
		 *
		 * @var ReregistrationSubmissionRow|false $cached
		 */
		$cached = static::cache_get( "id_{$id}" );
		if ( false !== $cached ) {
			return $cached;
		}

		$wpdb  = self::db();
		$table = self::get_table_name();

		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var ReregistrationSubmissionRow|null $result
		 */
		$result = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This is the cache-miss path — the hit is served by the cache_get() above and the result is stored below.
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id )
		);

		if ( $result ) {
			static::cache_set( "id_{$id}", $result );
		}

		return $result;
	}

	/**
	 * Get a submission by its auth_code.
	 *
	 * @since 4.12.0
	 * @param string $auth_code Cleaned auth code (uppercase, no hyphens).
	 * @return ReregistrationSubmissionRow|null
	 */
	public static function get_by_auth_code( string $auth_code ): ?object {
		if ( empty( $auth_code ) ) {
			return null;
		}

		$wpdb  = self::db();
		$table = self::get_table_name();

		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var ReregistrationSubmissionRow|null $row
		 */
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Token lookup: a consumed or revoked auth code must never be served from cache, so this read is deliberately uncached.
			// Only an APPROVED record verifies (#2242). The code is kept on a
			// row returned to draft, so the same code returns on re-approval;
			// until then it must not confirm anything. An approved row keeps
			// `approved` when its campaign ends — the daily run expires only
			// rows that never submitted — so the record stays verifiable.
			$wpdb->prepare( "SELECT * FROM %i WHERE auth_code = %s AND status = 'approved'", $table, $auth_code )
		);
		return $row;
	}

	/**
	 * Get a submission by its magic_token.
	 *
	 * @since 4.12.0
	 * @param string $token Magic token (64 hex chars).
	 * @return ReregistrationSubmissionRow|null
	 */
	public static function get_by_magic_token( string $token ): ?object {
		if ( empty( $token ) ) {
			return null;
		}

		$wpdb  = self::db();
		$table = self::get_table_name();

		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var ReregistrationSubmissionRow|null $row
		 */
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Token lookup: a consumed or revoked magic token must never be served from cache, so this read is deliberately uncached.
			// Approved only, as get_by_auth_code() above (#2242).
			$wpdb->prepare( "SELECT * FROM %i WHERE magic_token = %s AND status = 'approved'", $table, $token )
		);
		return $row;
	}

	/**
	 * Get submission for a specific reregistration and user.
	 *
	 * @param int $reregistration_id Reregistration ID.
	 * @param int $user_id           User ID.
	 * @return ReregistrationSubmissionRow|null
	 */
	public static function get_by_reregistration_and_user( int $reregistration_id, int $user_id ): ?object {
		$wpdb  = self::db();
		$table = self::get_table_name();

		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var ReregistrationSubmissionRow|null $row
		 */
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uncached by decision (#1034): the three callers are separate AJAX entry points that read it once each, and the fourth is a loop keyed on a different campaign per iteration — so a cache would never be hit.
			$wpdb->prepare(
				'SELECT * FROM %i WHERE reregistration_id = %d AND user_id = %d',
				$table,
				$reregistration_id,
				$user_id
			)
		);
		return $row;
	}

	/**
	 * Get all submissions for a user across all reregistrations.
	 *
	 * Joins with reregistrations table to include title and dates.
	 *
	 * @since 4.12.0
	 * @param int $user_id User ID.
	 * @return list<ReregistrationSubmissionRow>
	 */
	public static function get_all_by_user( int $user_id ): array {
		$wpdb        = self::db();
		$table       = self::get_table_name();
		$rereg_table = ReregistrationRepository::get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- JOIN across two of the plugin's own ffc_* tables, which WordPress has no API for; this reader's cache group is invalidated by the matching writer.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT s.*, r.title AS reregistration_title, r.start_date, r.end_date, r.status AS reregistration_status
                 FROM %i s
                 INNER JOIN %i r ON s.reregistration_id = r.id
                 WHERE s.user_id = %d
                 ORDER BY r.start_date DESC, s.created_at DESC',
				$table,
				$rereg_table,
				$user_id
			)
		);

		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var list<ReregistrationSubmissionRow>
		 */
		return is_array( $results ) ? $results : array();
	}

	/**
	 * The user's most recent APPROVED submission, outside the current campaign.
	 *
	 * This is #1213's import source. Only `approved` counts: a draft, a returned
	 * and a rejected submission do not represent data the institution accepted,
	 * and offering their content would invite the participant to resend what has
	 * already been refused.
	 *
	 * The ordering is by `r.start_date DESC` -- the most recent cycle -- and not
	 * by submission date: when the user has submissions in different campaigns,
	 * what matters is which CYCLE is the newest, not who typed last.
	 *
	 * @since 6.25.0
	 * @param int $user_id                    The user who owns the submissions.
	 * @param int $exclude_reregistration_id  The current campaign, which is not its own source.
	 * @return ReregistrationImportSourceRow|null The submission row with the campaign title, or null.
	 */
	public static function get_latest_approved_for_user( int $user_id, int $exclude_reregistration_id ): ?object {
		if ( $user_id <= 0 ) {
			return null;
		}

		$wpdb        = self::db();
		$table       = self::get_table_name();
		$rereg_table = ReregistrationRepository::get_table_name();

		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var ReregistrationImportSourceRow|null $row
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- JOIN across two of the plugin's own ffc_* tables, which WordPress has no API for; this reader's cache group is invalidated by the matching writer.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT s.*, r.title AS reregistration_title, r.start_date
                 FROM %i s
                 INNER JOIN %i r ON s.reregistration_id = r.id
                 WHERE s.user_id = %d
                   AND s.reregistration_id != %d
                   AND s.status = %s
                 ORDER BY r.start_date DESC, s.created_at DESC
                 LIMIT 1',
				$table,
				$rereg_table,
				$user_id,
				$exclude_reregistration_id,
				'approved'
			)
		);

		return $row;
	}

	/**
	 * The answers the user gave most recently in another campaign, for the
	 * form to open with (#2225): the latest-updated submission in any status
	 * but rejected that carries at least one answer. A rejected one is left
	 * out because its answers were refused, and a row without answers (an
	 * invitation never opened) says nothing to prefill.
	 *
	 * @param int $user_id                   User ID.
	 * @param int $exclude_reregistration_id The campaign being answered.
	 * @return array<string, mixed> field_key => value as stored (sensitive ones still encrypted).
	 */
	public static function get_latest_answers_for_user( int $user_id, int $exclude_reregistration_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$wpdb = self::db();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own ffc_* table, which WordPress has no API for; read once per form render.
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT data FROM %i
                 WHERE user_id = %d
                   AND reregistration_id != %d
                   AND status != %s
                   AND data IS NOT NULL
                 ORDER BY updated_at DESC, id DESC
                 LIMIT 10',
				self::get_table_name(),
				$user_id,
				$exclude_reregistration_id,
				'rejected'
			)
		);

		foreach ( $rows as $raw ) {
			$data   = is_string( $raw ) ? json_decode( $raw, true ) : null;
			$fields = is_array( $data ) && is_array( $data['fields'] ?? null ) ? $data['fields'] : array();
			foreach ( $fields as $value ) {
				if ( '' !== $value && null !== $value && array() !== $value ) {
					return $fields;
				}
			}
		}

		return array();
	}

	/**
	 * The status filter value that stands for "has not finished" (#2242).
	 *
	 * Not a status: it selects {@see self::NOT_FINISHED_STATUSES} together, the
	 * people the operator still has to chase.
	 */
	public const NOT_FINISHED_FILTER = 'not_finished';

	/**
	 * What the "not finished" filter selects: nothing submitted yet.
	 *
	 * Narrower than {@see self::UNFINISHED_STATUSES}, which decides who a
	 * deadline extension re-invites: a rejected or expired submission was
	 * already answered once, and the operator reads it under its own status.
	 *
	 * @var array<int, string>
	 */
	public const NOT_FINISHED_STATUSES = array( 'pending', 'in_progress' );

	/**
	 * The list filters in their one accepted shape (#2242).
	 *
	 * The submissions screen, its pagination links and the CSV export read the
	 * same request keys, so they pass through here and cannot disagree about
	 * what a value means. An unknown status is dropped rather than matched, so a
	 * stale or hand-edited URL lists everything instead of nothing.
	 *
	 * @param string $status      A status, {@see self::NOT_FINISHED_FILTER} or ''.
	 * @param string $search      Name, e-mail, CPF or RF; '' for none.
	 * @param int    $audience_id Audience whose members (descendants included) to keep; 0 for all.
	 * @return array{status: string|null, search: string|null, audience_id: int}
	 */
	public static function normalize_filters( string $status, string $search, int $audience_id ): array {
		$status = trim( $status );
		$search = trim( $search );

		return array(
			'status'      => ( self::NOT_FINISHED_FILTER === $status || in_array( $status, self::STATUSES, true ) ) ? $status : null,
			'search'      => '' !== $search ? $search : null,
			'audience_id' => max( 0, $audience_id ),
		);
	}

	/**
	 * Get submissions for a reregistration with optional filters.
	 *
	 * @param int                  $reregistration_id Reregistration ID.
	 * @param array<string, mixed> $filters { Optional. Query filters.
	 *     @type string $status      A status, or {@see self::NOT_FINISHED_FILTER}.
	 *     @type string $search      Name or e-mail, or a CPF / RF matched through its hash.
	 *     @type int    $audience_id Keep members of this audience, descendants included.
	 *     @type string $orderby     Column to order by. Default 'created_at'.
	 *     @type string $order       ASC or DESC. Default 'ASC'.
	 *     @type int    $limit       Max results. Default 0.
	 *     @type int    $offset      Offset. Default 0.
	 * }
	 * @return list<ReregistrationSubmissionRow>
	 */
	public static function get_by_reregistration( int $reregistration_id, array $filters = array() ): array {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$defaults = array(
			'orderby' => 'created_at',
			'order'   => 'ASC',
			'limit'   => 0,
			'offset'  => 0,
		);
		$filters  = wp_parse_args( $filters, $defaults );

		list( $where, $values ) = self::filter_where( $reregistration_id, $filters );
		$where_clause           = 'WHERE ' . implode( ' AND ', $where );

		$allowed_orderby = array( 'created_at', 'submitted_at', 'reviewed_at', 'status' );
		$orderby         = in_array( $filters['orderby'], $allowed_orderby, true ) ? 's.' . $filters['orderby'] : 's.created_at';
		$order           = strtoupper( $filters['order'] ) === 'DESC' ? 'DESC' : 'ASC';
		$limit_clause    = $filters['limit'] > 0 ? sprintf( 'LIMIT %d OFFSET %d', $filters['limit'], $filters['offset'] ) : '';

		// `s.id` breaks ties in the order, so a page boundary between rows
		// created in the same second never shows one twice or skips one.
		$sql = "SELECT s.*, u.display_name AS user_name, u.user_email AS user_email
                FROM %i s
                LEFT JOIN %i u ON s.user_id = u.ID
                {$where_clause}
                ORDER BY {$orderby} {$order}, s.id {$order}
                {$limit_clause}";

		$prepare_values = array_merge( array( $table, $wpdb->users ), $values );

		/**
		 * `$sql` interpolates `$where_clause`, `$orderby`, `$order` and
		 * `$limit_clause`, so it is a runtime string rather than the
		 * `literal-string` the stub declares. Nothing request-derived reaches
		 * the SQL text: the WHERE fragments are literals carrying `%d` / `%s`
		 * (values travel in `$prepare_values`), `$orderby` is picked from
		 * `$allowed_orderby`, `$order` collapses to `ASC`/`DESC`, and the two
		 * table names are bound with `%i`.
		 *
		 * @phpstan-ignore argument.type
		 */
		$results = $wpdb->get_results( $wpdb->prepare( $sql, $prepare_values ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Filtered and sorted list keyed on the caller's arguments; the key cardinality is effectively unbounded.
		/**
		 * Cast wpdb result to the typed row shape. `get_results()` returns null
		 * on a failed query, which this method's `: array` return type would
		 * turn into a TypeError — the narrowed suppression above is what
		 * surfaced that; the broad form had been hiding it.
		 *
		 * @var list<ReregistrationSubmissionRow>
		 */
		return is_array( $results ) ? $results : array();
	}

	/**
	 * How many submissions {@see self::get_by_reregistration()} would list
	 * with these filters and no limit: the total the pagination pages through.
	 *
	 * @since 6.43.0
	 * @param int                  $reregistration_id Reregistration ID.
	 * @param array<string, mixed> $filters           `status`, `search`, `audience_id`.
	 * @return int
	 */
	public static function count_filtered( int $reregistration_id, array $filters = array() ): int {
		$wpdb = self::db();

		list( $where, $values ) = self::filter_where( $reregistration_id, $filters );
		$where_clause           = 'WHERE ' . implode( ' AND ', $where );

		$sql = "SELECT COUNT(*) FROM %i s LEFT JOIN %i u ON s.user_id = u.ID {$where_clause}";

		/**
		 * Same runtime SQL as {@see self::get_by_reregistration()}; the WHERE
		 * fragments are literals and every value is a placeholder.
		 *
		 * @phpstan-ignore argument.type
		 */
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, array_merge( array( self::get_table_name(), $wpdb->users ), $values ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One count per screen render or export job, keyed on the request's filters.
	}

	/**
	 * The WHERE fragments and their values for the list filters (#2242).
	 *
	 * One builder for the screen, its count and the CSV export, so the export
	 * holds exactly the rows the operator filtered on screen. Every fragment is
	 * a literal with placeholders; the values travel apart.
	 *
	 * A search that reads as a CPF (11 digits) or an RF (7 digits) is also
	 * matched through the identifier hash: the submission stores the value
	 * encrypted, so the hash is the only way to find it, and it resolves to the
	 * one account that carries it. An audience narrows to its members,
	 * descendants included -- the reach the campaign itself uses.
	 *
	 * @param int                  $reregistration_id Reregistration ID.
	 * @param array<string, mixed> $filters           `status`, `search`, `audience_id`.
	 * @return array{0: list<string>, 1: list<int|string>}
	 */
	private static function filter_where( int $reregistration_id, array $filters ): array {
		$wpdb   = self::db();
		$where  = array( 's.reregistration_id = %d' );
		$values = array( $reregistration_id );

		$status     = isset( $filters['status'] ) && is_string( $filters['status'] ) ? $filters['status'] : '';
		$status_set = self::NOT_FINISHED_FILTER === $status ? self::NOT_FINISHED_STATUSES : ( '' !== $status ? array( $status ) : array() );
		if ( array() !== $status_set ) {
			$where[] = 's.status IN (' . implode( ', ', array_fill( 0, count( $status_set ), '%s' ) ) . ')';
			array_push( $values, ...$status_set );
		}

		$search = isset( $filters['search'] ) && is_string( $filters['search'] ) ? trim( $filters['search'] ) : '';
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$by_id    = self::user_id_for_identifier( $search );
			$where[]  = $by_id > 0
				? '(u.display_name LIKE %s OR u.user_email LIKE %s OR s.user_id = %d)'
				: '(u.display_name LIKE %s OR u.user_email LIKE %s)';
			$values[] = $like;
			$values[] = $like;
			if ( $by_id > 0 ) {
				$values[] = $by_id;
			}
		}

		$audience_raw = $filters['audience_id'] ?? 0;
		$audience_id  = is_numeric( $audience_raw ) ? (int) $audience_raw : 0;
		if ( $audience_id > 0 ) {
			$user_ids = array_values( array_map( 'intval', ReregistrationRepository::get_user_ids_for_audiences( array( $audience_id ) ) ) );
			if ( array() === $user_ids ) {
				$where[] = '1 = 0';
			} else {
				$where[] = 's.user_id IN (' . implode( ', ', array_fill( 0, count( $user_ids ), '%d' ) ) . ')';
				array_push( $values, ...$user_ids );
			}
		}

		return array( $where, $values );
	}

	/**
	 * The account a CPF or RF search term belongs to, or 0.
	 *
	 * Only a term made of digits and the usual mask characters is tried, and
	 * only at the lengths an identifier has, so a name search never pays for a
	 * hash lookup.
	 *
	 * @param string $search The search term.
	 * @return int
	 */
	private static function user_id_for_identifier( string $search ): int {
		if ( 1 !== preg_match( '/^[\d.\-\/\s]+$/', $search ) ) {
			return 0;
		}
		$digits = \FreeFormCertificate\Core\DataSanitizer::normalize_cpf_rf( $search );
		if ( 7 !== strlen( $digits ) && 11 !== strlen( $digits ) ) {
			return 0;
		}

		$kind = \FreeFormCertificate\Core\DataSanitizer::classify_cpf_rf( $digits );
		$hash = \FreeFormCertificate\Core\SensitiveFieldRegistry::hash_identifier( $kind, $digits );
		if ( null === $hash ) {
			return 0;
		}

		return \FreeFormCertificate\UserDashboard\UserManager::resolve_existing_user(
			'cpf' === $kind ? $hash : null,
			'rf' === $kind ? $hash : null,
			''
		);
	}

	/**
	 * Get statistics for a reregistration.
	 *
	 * @param int $reregistration_id Reregistration ID.
	 * @return array<string, int> Counts keyed by status.
	 */
	public static function get_statistics( int $reregistration_id ): array {
		$wpdb  = self::db();
		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uncached by decision (#1034): two callers, both in the campaign admin renderer. Marginal at best — one saved query on an admin screen.
		$results_raw = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT status, COUNT(*) as count FROM %i
                WHERE reregistration_id = %d GROUP BY status',
				$table,
				$reregistration_id
			)
		);
		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var list<\stdClass&object{status: string, count: numeric-string}> $results
		 */
		$results = is_array( $results_raw ) ? $results_raw : array();

		$stats = array(
			'total'       => 0,
			'pending'     => 0,
			'in_progress' => 0,
			'submitted'   => 0,
			'approved'    => 0,
			'rejected'    => 0,
			'expired'     => 0,
		);

		foreach ( $results as $row ) {
			$stats[ $row->status ] = (int) $row->count;
			$stats['total']       += (int) $row->count;
		}

		return $stats;
	}

	/**
	 * Get submissions for CSV export.
	 *
	 * @param int                  $reregistration_id Reregistration ID.
	 * @param array<string, mixed> $filters           Optional filters (status, search).
	 * @return list<ReregistrationSubmissionRow>
	 *
	 * @deprecated 6.43.0 Nothing in the plugin calls it (#2089).
	 * @removal    6.45.0
	 */
	public static function get_for_export( int $reregistration_id, array $filters = array() ): array {
		_deprecated_function( __METHOD__, '6.43.0' );

		$filters['limit']  = 0;
		$filters['offset'] = 0;
		return self::get_by_reregistration( $reregistration_id, $filters );
	}

	/**
	 * Stream submissions for CSV export in chunks of $chunk_size rows
	 * to keep memory bounded for large reregistrations. Yields rows
	 * one at a time so the caller can pipe into `Csv::writer->rows()`.
	 *
	 * @since 6.5.0
	 * @param int                  $reregistration_id Reregistration ID.
	 * @param array<string, mixed> $filters           Filters (status, search, orderby, order).
	 * @param int                  $chunk_size        Rows per database round-trip.
	 * @return \Generator<int, ReregistrationSubmissionRow>
	 *
	 * @deprecated 6.43.0 Nothing in the plugin calls it (#2089).
	 * @removal    6.45.0
	 */
	public static function stream_for_export( int $reregistration_id, array $filters = array(), int $chunk_size = 500 ): \Generator {
		_deprecated_function( __METHOD__, '6.43.0' );

		$offset = 0;
		while ( true ) {
			$filters['limit']  = $chunk_size;
			$filters['offset'] = $offset;
			$rows              = self::get_by_reregistration( $reregistration_id, $filters );
			if ( empty( $rows ) ) {
				return;
			}
			foreach ( $rows as $row ) {
				yield $row;
			}
			if ( count( $rows ) < $chunk_size ) {
				return;
			}
			$offset += $chunk_size;
		}
	}


	/**
	 * Keyset page for the batched CSV export: submissions of one reregistration
	 * with `s.id < $cursor`, newest first (`s.id DESC`), limited to `$size`, with
	 * the user display-name + email joined (like {@see self::get_by_reregistration()}).
	 * Keyset (not LIMIT/OFFSET) so paging stays stable across concurrent inserts
	 * during a long export.
	 *
	 * @since 6.17.0
	 * The filters are the screen's (#2242), through the same builder, so the
	 * file holds exactly the rows the operator filtered on.
	 *
	 * @param int                  $reregistration_id Reregistration ID.
	 * @param int                  $cursor            Exclusive upper-bound id (PHP_INT_MAX on the first page).
	 * @param int                  $size              Page size.
	 * @param array<string, mixed> $filters           `status`, `search`, `audience_id`.
	 * @return list<ReregistrationSubmissionRow>
	 */
	public static function find_by_cursor_for_export( int $reregistration_id, int $cursor, int $size, array $filters = array() ): array {
		$wpdb  = self::db();
		$table = self::get_table_name();

		list( $where, $values ) = self::filter_where( $reregistration_id, $filters );
		$where[]                = 's.id < %d';
		$values[]               = $cursor;
		$where_clause           = 'WHERE ' . implode( ' AND ', $where );

		$sql = "SELECT s.*, u.display_name AS user_name, u.user_email AS user_email
                FROM %i s
                LEFT JOIN %i u ON s.user_id = u.ID
                {$where_clause}
                ORDER BY s.id DESC
                LIMIT %d";

		$prepare_values = array_merge( array( $table, $wpdb->users ), $values, array( $size ) );

		/**
		 * Same runtime SQL as {@see self::get_by_reregistration()}; the WHERE
		 * fragments are literals and every value is a placeholder.
		 *
		 * @phpstan-ignore argument.type
		 */
		$results = $wpdb->get_results( $wpdb->prepare( $sql, $prepare_values ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Keyset pagination over an export; each page is read exactly once, so there is nothing a cache could serve twice.
		/**
		 * Cast wpdb result to the typed row shape.
		 *
		 * @var list<ReregistrationSubmissionRow>
		 */
		return is_array( $results ) ? $results : array();
	}
	/**
	 * Statuses that count as "has not finished" for a re-invitation.
	 *
	 * `submitted` and `approved` are out: the person did their part, and a
	 * longer deadline is not news to them. `rejected` is IN — a rejection means
	 * the process is not finished, so the new deadline is exactly the chance to
	 * redo it, and they are the ones who most need to hear about it (#1190).
	 *
	 * @var array<int, string>
	 */
	public const UNFINISHED_STATUSES = array( 'pending', 'in_progress', 'expired', 'rejected' );

	/**
	 * Who the reminder reaches. A deliberate subset of
	 * {@see self::UNFINISHED_STATUSES} -- see
	 * {@see self::get_awaiting_reminder()} for why.
	 *
	 * `rejected` joined in #2242: a rejected member still has to resubmit
	 * before the deadline, and the rejection clears the reminder mark so the
	 * next sweep reaches them. `expired` stays out -- the window is closed.
	 *
	 * @var array<int, string>
	 */
	public const REMINDABLE_STATUSES = array( 'pending', 'in_progress', 'rejected' );

	/**
	 * Statuses in which the member may still save a draft or submit (#2242).
	 *
	 * The one list the dashboard button, the REST `can_submit` and the three
	 * frontend handlers all read, and the one the conditional write in
	 * {@see ReregistrationSubmissionWriter::update_if_status()} tests. Before,
	 * the handlers refused only `approved` and `expired`, so a stale tab could
	 * pull a `submitted` row back into a draft or submit it twice.
	 *
	 * @var array<int, string>
	 */
	public const OPEN_STATUSES = array( 'pending', 'in_progress', 'rejected' );

	/**
	 * Statuses the CSV import may write over (#2242): nothing submitted yet.
	 *
	 * Narrower than {@see self::OPEN_STATUSES}: a rejected submission was
	 * reviewed, and the import does not replace a reviewed answer.
	 *
	 * @var array<int, string>
	 */
	public const IMPORTABLE_STATUSES = array( 'pending', 'in_progress' );

	/**
	 * Submissions the invitation should reach right now.
	 *
	 * Two populations, and they are different questions:
	 *
	 * 1. **Never invited** (`invited_at IS NULL`) — always. Covers the member
	 *    who joined the audience after the campaign started, which is the
	 *    #1190 case, and every row created before this column existed.
	 * 2. **Invited before the deadline moved forward**, and still unfinished —
	 *    only when the campaign records an extension.
	 *
	 * What this replaces is the reason it exists: the caller used to ask for
	 * `status = 'pending'` and treat that as "needs an invitation". It is a
	 * proxy that fails in both directions — somebody invited who never acted
	 * stays `pending` and would be invited again on every run, and somebody who
	 * started and stalled is never re-invited even when the deadline moves.
	 *
	 * The cursor and the limit serve the daily batch (#1762), in the shape
	 * {@see self::get_awaiting_reminder()} already has: a keyset on `id`, so a
	 * row whose send failed is stepped past instead of being read again on the
	 * same run.
	 *
	 * @param int      $reregistration_id Campaign ID.
	 * @param int|null $extended_at       Unix seconds of the last deadline
	 *                                    extension, or null when there is none.
	 * @param int      $after_id          Keyset cursor: only rows with an `id` greater than this.
	 * @param int      $limit             Page size; `0` means no limit.
	 * @return list<ReregistrationSubmissionRow>
	 */
	public static function get_awaiting_invitation( int $reregistration_id, ?int $extended_at = null, int $after_id = 0, int $limit = 0 ): array {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$where  = array( 'invited_at IS NULL' );
		$values = array( $table, $reregistration_id );

		if ( null !== $extended_at && $extended_at > 0 ) {
			$placeholders = implode( ',', array_fill( 0, count( self::UNFINISHED_STATUSES ), '%s' ) );
			$where[]      = "( invited_at < %d AND status IN ({$placeholders}) )";
			$values[]     = $extended_at;
			$values       = array_merge( $values, self::UNFINISHED_STATUSES );
		}

		$clause = implode( ' OR ', $where );

		// Cursor and limit come after the clauses because that is where their
		// placeholders sit in the SQL; `prepare()` substitutes in order.
		$cursor_sql = '';
		if ( $after_id > 0 ) {
			$cursor_sql = ' AND id > %d';
			$values[]   = $after_id;
		}

		$limit_sql = '';
		if ( $limit > 0 ) {
			$limit_sql = ' LIMIT %d';
			$values[]  = $limit;
		}

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The sniff counts the literal's placeholders and does not know `prepare()` accepts a single array of arguments, which is how the table, the id and the statuses arrive.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A read immediately before the write that changes the same rows; a cached answer would resend email.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE reregistration_id = %d AND ( {$clause} ){$cursor_sql} ORDER BY id ASC{$limit_sql}",
				$values
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		/**
		 * Cast wpdb result to the typed row shape.
		 *
		 * @var list<ReregistrationSubmissionRow>
		 */
		return is_array( $results ) ? $results : array();
	}

	/**
	 * The submissions that are still owed a REMINDER.
	 *
	 * Mirrors {@see self::get_awaiting_invitation()} with `reminder_sent_at` in
	 * place of `invited_at`, because the rule is the same: one per campaign,
	 * plus one on every deadline extension.
	 *
	 * WHAT THIS FIXES
	 *
	 * Until now there was no mark at all, and the cron is DAILY while the
	 * campaign query uses `DATEDIFF(end_date, <site date>) <= reminder_days` -- a
	 * WINDOW, not a day. With `reminder_days = 7`, every pending participant
	 * received seven emails, one a day (#1232).
	 *
	 * TWO DELIBERATE DIFFERENCES FROM THE INVITATION
	 *
	 * 1. The base audience is {@see self::REMINDABLE_STATUSES} (`pending`,
	 *    `in_progress` and, since #2242, `rejected`). The invitation uses
	 *    `UNFINISHED_STATUSES`, which also includes `expired`: a closed window
	 *    is not something to be reminded of.
	 * 2. On the reopening by extension, however, the set is the invitation's --
	 *    if the deadline moved forward, whoever did not finish becomes
	 *    remindable by the same criterion that makes them invitable.
	 *
	 * THE BATCHING, AND WHY THE CURSOR IS NOT OPTIONAL (#1232 step 2)
	 *
	 * `$after_id` + `$limit` form a keyset on `id`, the same pattern #772's
	 * export contract uses, and here it is not merely a matter of stable
	 * pagination: it is what guarantees PROGRESS.
	 *
	 * The temptation is to drop the cursor, because `reminder_sent_at IS NULL`
	 * already looks like one -- every send stamps the row, so the next page
	 * naturally excludes whoever already received it. That fails when the send
	 * does NOT stamp, and there is a documented case where it never will:
	 * `send_to_user()` returns `false` when `get_userdata()` cannot find the
	 * user, and `user_id` here is `NOT NULL` and an ACCEPTED ORPHAN (#822) --
	 * deleting the WordPress account leaves the submission pointing at a user
	 * that no longer exists. That row stays `reminder_sent_at IS NULL` forever.
	 *
	 * With no cursor the next batch fetches the same row again, the driver sees
	 * a full page, reschedules, and the cycle repeats every 60 seconds without
	 * end. With the cursor it is stepped past inside the day's sweep and is only
	 * retried on the next daily run -- which is the right retry policy for a
	 * failure that may be transient.
	 *
	 * @param int      $reregistration_id The campaign ID.
	 * @param int|null $extended_at       Unix time of the last deadline
	 *                                    extension, or null when there was none.
	 * @param int      $after_id          Only rows with an `id` GREATER than
	 *                                    this. `0` starts from the beginning.
	 * @param int      $limit             Maximum page size. `0` means no limit,
	 *                                    which is the manual path.
	 * @return list<ReregistrationSubmissionRow>
	 */
	public static function get_awaiting_reminder( int $reregistration_id, ?int $extended_at = null, int $after_id = 0, int $limit = 0 ): array {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$base         = self::REMINDABLE_STATUSES;
		$placeholders = implode( ',', array_fill( 0, count( $base ), '%s' ) );

		$where  = array( 'reminder_sent_at IS NULL' );
		$values = array( $table, $reregistration_id );
		$values = array_merge( $values, $base );

		if ( null !== $extended_at && $extended_at > 0 ) {
			$unfinished = implode( ',', array_fill( 0, count( self::UNFINISHED_STATUSES ), '%s' ) );
			$where[]    = "( reminder_sent_at < %d AND status IN ({$unfinished}) )";
			$values[]   = $extended_at;
			$values     = array_merge( $values, self::UNFINISHED_STATUSES );
		}

		$clause = implode( ' OR ', $where );

		// ORDER MATTERS: `prepare()` takes a single array and substitutes in the
		// order the placeholders appear in the SQL. Cursor and limit come AFTER
		// the clauses above because that is where their placeholders are.
		$cursor_sql = '';
		if ( $after_id > 0 ) {
			$cursor_sql = ' AND id > %d';
			$values[]   = $after_id;
		}

		$limit_sql = '';
		if ( $limit > 0 ) {
			$limit_sql = ' LIMIT %d';
			$values[]  = $limit;
		}

		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The sniff counts the literal's placeholders and does not know `prepare()` accepts a single array of arguments, which is how the table, the id and the statuses arrive.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A read immediately before the write that changes the same rows; a cached answer would resend email.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE reregistration_id = %d AND status IN ({$placeholders}) AND ( {$clause} ){$cursor_sql} ORDER BY id ASC{$limit_sql}",
				$values
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		/**
		 * Cast wpdb result to the typed row shape.
		 *
		 * @var list<ReregistrationSubmissionRow>
		 */
		return is_array( $results ) ? $results : array();
	}
}

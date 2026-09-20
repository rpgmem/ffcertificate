<?php
/**
 * SubmissionLinkAuditor
 *
 * Report-only maintenance tool that scans for submissions wrongly linked (or
 * not linked) to WordPress users. It NEVER writes — there is no apply step
 * ({@see self::is_actionable()} returns false) — because re-linking identity
 * records automatically is too risky; the admin reviews the report and acts
 * case by case.
 *
 * Four checks, all driven by the deterministic `cpf_hash` / `rf_hash` columns
 * (so no decryption is needed) plus a `wp_users` existence join:
 *
 *   - `orphan_links`        — `user_id` points to a deleted WP user.
 *   - `multiple_identities` — one user linked to more than one distinct CPF/RF.
 *   - `should_be_linked`    — no `user_id`, but the CPF matches a linked row.
 *   - `shared_identities`   — one CPF shared across more than one user.
 *
 * @package FreeFormCertificate\Maintenance
 * @since 6.8.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Maintenance;

use FreeFormCertificate\Repositories\SubmissionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only auditor for submission ↔ user links.
 */
class SubmissionLinkAuditor implements MaintenanceToolInterface {

	/**
	 * Max rows fetched (and shown) per check.
	 */
	const SAMPLE_LIMIT = 50;

	/**
	 * Where each check's row keeps the accounts it names.
	 *
	 * ONE MAP, BECAUSE ONLY THE PRODUCER KNOWS
	 *
	 * `IdentityConflictQuery::grouped()` aliases whatever it grouped BY as
	 * `subject`, so that key holds a user id in one check and a hash in the
	 * other, and the half it aggregated away lands in `related` the other way
	 * round. Nothing in a row says which it is -- and shape cannot decide it
	 * either, since a 16-character hex prefix can be all digits and read as
	 * numeric. This class is the one place that knows what the checks ARE, so
	 * the answer lives here rather than being re-derived by every consumer.
	 *
	 * `should_be_linked` is absent deliberately: its rows are submissions with
	 * NO user, which is the whole finding. An entry naming a key it does not
	 * carry would annotate nothing while claiming to.
	 *
	 * @var array<string, string>
	 */
	public const ACCOUNTS_IN = array(
		'orphan_links'                    => 'user_id',
		'multiple_identities'             => 'user_id',
		'shared_identities'               => 'related',
		'cross_store_shared_identities'   => 'related',
		'cross_store_multiple_identities' => 'subject',
		'unindexed_links'                 => 'user_id',
	);

	/**
	 * Lazily-built data access layer.
	 *
	 * @var SubmissionRepository|null
	 */
	private ?SubmissionRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param SubmissionRepository|null $repository Injected for tests; lazily created when null.
	 */
	public function __construct( ?SubmissionRepository $repository = null ) {
		$this->repository = $repository;
	}

	/**
	 * Resolve the repository, creating a default one on first use.
	 *
	 * @return SubmissionRepository
	 */
	private function repository(): SubmissionRepository {
		if ( null === $this->repository ) {
			$this->repository = new SubmissionRepository();
		}
		return $this->repository;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return 'submission_link_audit';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_title(): string {
		return __( 'Submission ↔ user link audit', 'ffcertificate' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return __( 'Report-only scan of how people are linked to WordPress users. Four checks over certificate submissions: links to deleted users, one user bound to multiple CPF/RF identities, unlinked submissions whose CPF matches a linked one, and a single CPF shared across multiple users. Three more across every store — submissions, appointments, recruitment candidacies and the identity index — reporting one identifier held by two accounts, one account holding two identifiers, and identifiers the index does not yet carry. Nothing is changed: review and fix each case manually.', 'ffcertificate' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Report-only: there is no destructive apply step.
	 */
	public function is_actionable(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function get_default_options(): array {
		return array();
	}

	/**
	 * Run the seven read-only checks and return a structured report.
	 *
	 * THE LIMIT IS AN ARGUMENT BECAUSE THE EXPORT NEEDS A DIFFERENT ONE
	 *
	 * The screen wants a cheap sample -- 50 per check, enough to say whether a
	 * problem exists. The CSV export (#1295) wants the list itself, because a
	 * lead nobody can take out of the page is not a lead. Rather than a second
	 * class issuing the same seven queries with its own number, the caller
	 * passes `limit` and this stays the one place that knows what the checks
	 * ARE -- the `cpf_rf_encrypted` rule against a parallel reader of one fact.
	 *
	 * `truncated` is per check and says the cap was reached, so a consumer can
	 * state that its list is partial instead of implying it is complete.
	 *
	 * @param array<string, mixed> $options Optional `limit` (defaults to {@see self::SAMPLE_LIMIT}).
	 * @return array{
	 *     checks: array<string, array{count:int, truncated:bool, rows:array<int, array<string, mixed>>}>,
	 *     total: int
	 * }
	 */
	public function run( array $options ): array {
		$limit = isset( $options['limit'] ) && is_numeric( $options['limit'] )
			? max( 1, (int) $options['limit'] )
			: self::SAMPLE_LIMIT;

		$repo = $this->repository();

		$conflicts = $this->conflicts();

		$checks = array(
			// The four submission-scoped checks. `should_be_linked` in
			// particular is a question about `ffc_submissions` rows and only
			// makes sense there.
			'orphan_links'                    => $repo->find_orphan_user_links( $limit ),
			'multiple_identities'             => $repo->find_users_with_multiple_identities( $limit ),
			'should_be_linked'                => $repo->find_unlinked_with_matching_identity( $limit ),
			'shared_identities'               => $repo->find_shared_identities( $limit ),

			// The three that only exist ACROSS the stores (#1313 PR 10). The
			// submission-scoped pair above cannot see a person whose two
			// accounts were created by different modules, which is the case
			// #1313 exists to make visible -- and the one its backfill
			// deliberately declines to resolve.
			'cross_store_shared_identities'   => $conflicts->shared_identities( $limit ),
			'cross_store_multiple_identities' => $conflicts->multiple_identities( $limit ),
			'unindexed_links'                 => $conflicts->unindexed_links( $limit ),
		);

		$checks = $this->with_account_facts( $checks );

		$report = array(
			'checks' => array(),
			'total'  => 0,
		);

		foreach ( $checks as $key => $rows ) {
			$count                    = count( $rows );
			$report['checks'][ $key ] = array(
				'count'     => $count,
				'truncated' => $count >= $limit,
				'rows'      => $rows,
			);
			$report['total']         += $count;
		}

		return $report;
	}

	/**
	 * Annotate every finding with what is true of the accounts it names.
	 *
	 * ONE PASS OVER ALL SEVEN CHECKS, NOT ONE PER CHECK
	 *
	 * The same account is named by several checks -- production's export had
	 * 66 of the 73 multi-identifier findings duplicated between the
	 * submissions-scoped check and the cross-store one -- so collecting the
	 * ids across the whole report before asking is both fewer statements and
	 * one consistent answer. Asking per check would let two rows about account
	 * 9129 disagree if a user were deleted between them.
	 *
	 * `orphan_links` is the built-in control: its query already selects rows
	 * whose `user_id` has no `wp_users` match, so every one of its findings
	 * must come back `missing`. An `exists` there means this annotation is
	 * reading the wrong rows, which is what `SubmissionLinkAuditorTest` pins.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $checks Rows per check.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function with_account_facts( array $checks ): array {
		$wanted = array();

		foreach ( $checks as $check => $rows ) {
			foreach ( $rows as $row ) {
				foreach ( self::accounts_named_by( (string) $check, (array) $row ) as $id ) {
					$wanted[ $id ] = true;
				}
			}
		}

		if ( array() === $wanted ) {
			return $checks;
		}

		$facts = $this->conflicts()->account_facts( array_keys( $wanted ) );

		foreach ( $checks as $check => $rows ) {
			foreach ( $rows as $index => $row ) {
				$accounts = self::accounts_named_by( (string) $check, (array) $row );

				if ( array() === $accounts ) {
					continue;
				}

				$statuses = array();
				$counts   = array();

				foreach ( $accounts as $id ) {
					$fact = $facts[ $id ] ?? array();

					// An account the facts pass did not answer for stays
					// `missing`, which is the same default `account_facts()`
					// seeds: an absent answer must never read as a live
					// account.
					$statuses[] = $fact['status'] ?? IdentityConflictQuery::STATUS_MISSING;

					$rows_for = $fact['rows'] ?? array();

					$counts[] = (string) $id
						. IdentityConflictQuery::ACCOUNT_ROWS_ASSIGN
						. IdentityConflictQuery::format_account_rows( $rows_for );
				}

				$checks[ $check ][ $index ][ IdentityConflictQuery::COLUMN_ACCOUNT_STATUS ] = implode(
					IdentityConflictQuery::RELATED_SEPARATOR,
					$statuses
				);
				$checks[ $check ][ $index ][ IdentityConflictQuery::COLUMN_ACCOUNT_ROWS ]   = implode(
					IdentityConflictQuery::RELATED_SEPARATOR,
					$counts
				);
			}
		}

		return $checks;
	}

	/**
	 * The accounts one finding names, in the order the report lists them.
	 *
	 * Order matters: the status and row-count columns are POSITIONAL against
	 * `user_ids` in the export, so a set here would silently misalign them.
	 *
	 * @param string               $check Check key.
	 * @param array<string, mixed> $row   One finding.
	 * @return array<int, int>
	 */
	public static function accounts_named_by( string $check, array $row ): array {
		$key = self::ACCOUNTS_IN[ $check ] ?? '';

		if ( '' === $key || ! isset( $row[ $key ] ) ) {
			return array();
		}

		$raw = $row[ $key ];

		$values = is_string( $raw ) && false !== strpos( $raw, IdentityConflictQuery::RELATED_SEPARATOR )
			? explode( IdentityConflictQuery::RELATED_SEPARATOR, $raw )
			: array( $raw );

		$out = array();

		foreach ( $values as $value ) {
			$id = is_numeric( $value ) ? (int) $value : 0;

			if ( $id > 0 && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}

		return $out;
	}

	/**
	 * The cross-store identity questions.
	 *
	 * A seam for the same reason the repository above has one: these reach four
	 * tables through the global `$wpdb`, and a test needs to drive the report
	 * without standing all four up.
	 *
	 * @return IdentityConflictQuery
	 */
	protected function conflicts(): IdentityConflictQuery {
		return new IdentityConflictQuery();
	}
}

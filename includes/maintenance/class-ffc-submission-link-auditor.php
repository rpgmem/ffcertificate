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
	 * Run the four read-only checks and return a structured report.
	 *
	 * @param array<string, mixed> $options Unused (report-only).
	 * @return array{
	 *     checks: array<string, array{count:int, truncated:bool, rows:array<int, array<string, mixed>>}>,
	 *     total: int
	 * }
	 */
	public function run( array $options ): array {
		$repo = $this->repository();

		$conflicts = $this->conflicts();

		$checks = array(
			// The four submission-scoped checks. `should_be_linked` in
			// particular is a question about `ffc_submissions` rows and only
			// makes sense there.
			'orphan_links'                    => $repo->find_orphan_user_links( self::SAMPLE_LIMIT ),
			'multiple_identities'             => $repo->find_users_with_multiple_identities( self::SAMPLE_LIMIT ),
			'should_be_linked'                => $repo->find_unlinked_with_matching_identity( self::SAMPLE_LIMIT ),
			'shared_identities'               => $repo->find_shared_identities( self::SAMPLE_LIMIT ),

			// The three that only exist ACROSS the stores (#1313 PR 10). The
			// submission-scoped pair above cannot see a person whose two
			// accounts were created by different modules, which is the case
			// #1313 exists to make visible -- and the one its backfill
			// deliberately declines to resolve.
			'cross_store_shared_identities'   => $conflicts->shared_identities( self::SAMPLE_LIMIT ),
			'cross_store_multiple_identities' => $conflicts->multiple_identities( self::SAMPLE_LIMIT ),
			'unindexed_links'                 => $conflicts->unindexed_links( self::SAMPLE_LIMIT ),
		);

		$report = array(
			'checks' => array(),
			'total'  => 0,
		);

		foreach ( $checks as $key => $rows ) {
			$count                    = count( $rows );
			$report['checks'][ $key ] = array(
				'count'     => $count,
				'truncated' => $count >= self::SAMPLE_LIMIT,
				'rows'      => $rows,
			);
			$report['total']         += $count;
		}

		return $report;
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

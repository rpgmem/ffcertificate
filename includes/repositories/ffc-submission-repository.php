<?php
/**
 * Submission Repository
 * Handles all database operations for submissions
 *
 * Since the #563 backlog read/write split (A6) this class is a thin façade:
 * domain reads live in {@see SubmissionReader}, domain writes in
 * {@see SubmissionWriter}. The generic CRUD (findById/findAll/insert/update/
 * delete/count/transactions) inherited from {@see AbstractRepository} stays here
 * so existing callers that use it directly are unaffected. The façade, reader and
 * writer all bind the same global $wpdb, so transactions and locks remain
 * coherent.
 *
 * Tech-debt (#563 B3): migrate call sites to depend on SubmissionReader /
 * SubmissionWriter directly, then retire this delegating façade.
 *
 * V3.3.0: Added strict types and type hints for better code safety
 * v3.2.0: Migrated to namespace (Phase 2)
 * v3.0.2: Fixed search to work with encrypted data (removed data_encrypted LIKE, added auth_code/magic_token search)
 * v3.0.1: Added methods for CSV export
 *
 * @package FreeFormCertificate\Repositories
 * @since 3.0.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Public façade over {@see SubmissionReader} + {@see SubmissionWriter}.
 */
class SubmissionRepository extends AbstractRepository {

	/**
	 * Read-side collaborator.
	 *
	 * @var SubmissionReader
	 */
	private SubmissionReader $reader;

	/**
	 * Write-side collaborator.
	 *
	 * @var SubmissionWriter
	 */
	private SubmissionWriter $writer;

	/**
	 * Constructor — wires up the read/write collaborators.
	 */
	public function __construct() {
		parent::__construct();
		$this->reader = new SubmissionReader();
		$this->writer = new SubmissionWriter();
	}

	/**
	 * Submissions table name, with the current site prefix.
	 *
	 * Static accessor for callers that don't hold a repository instance — the
	 * relocation target for the former `Core\Utils::get_submissions_table()`
	 * (#563 Sprint 5 phase 2). Works correctly on Multisite (per-site prefix).
	 *
	 * @since 6.11.3
	 * @return string Fully-prefixed table name.
	 */
	public static function get_submissions_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ffc_submissions';
	}

	/**
	 * Get table name.
	 *
	 * @return string
	 */
	protected function get_table_name(): string {
		return self::get_submissions_table();
	}

	/**
	 * Get cache group.
	 *
	 * @return string
	 */
	protected function get_cache_group(): string {
		return 'ffc_submissions';
	}

	/**
	 * Get allowed order columns.
	 *
	 * @return array<int, string>
	 */
	protected function get_allowed_order_columns(): array {
		return array( 'id', 'form_id', 'auth_code', 'status', 'submission_date', 'created_at', 'updated_at' );
	}

	// ─────────────────────────────────────────────.
	// Reads — delegate to SubmissionReader.
	// ─────────────────────────────────────────────.

	/**
	 * Audit: submissions whose `user_id` points to a WP user that no longer
	 * exists (orphaned link after the user was deleted). Read-only.
	 *
	 * @param int $limit Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function find_orphan_user_links( int $limit = 50 ): array {
		return $this->reader->find_orphan_user_links( $limit );
	}

	/**
	 * Audit: WP users linked to submissions carrying more than one distinct
	 * `cpf_hash` (or `rf_hash`). Read-only.
	 *
	 * @param int $limit Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function find_users_with_multiple_identities( int $limit = 50 ): array {
		return $this->reader->find_users_with_multiple_identities( $limit );
	}

	/**
	 * Audit: unlinked submissions whose `cpf_hash` matches a linked one. Read-only.
	 *
	 * @param int $limit Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function find_unlinked_with_matching_identity( int $limit = 50 ): array {
		return $this->reader->find_unlinked_with_matching_identity( $limit );
	}

	/**
	 * Audit: a single `cpf_hash` shared across more than one linked `user_id`. Read-only.
	 *
	 * @param int $limit Max rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function find_shared_identities( int $limit = 50 ): array {
		return $this->reader->find_shared_identities( $limit );
	}

	/**
	 * Find by auth code
	 *
	 * @param string $auth_code Auth code.
	 * @return array<string, mixed>|null
	 */
	public function findByAuthCode( string $auth_code ) {
		return $this->reader->findByAuthCode( $auth_code );
	}

	/**
	 * Find by magic token
	 *
	 * @param string $token Token.
	 * @return array<string, mixed>|null
	 */
	public function findByToken( string $token ) {
		return $this->reader->findByToken( $token );
	}

	/**
	 * Find by email
	 *
	 * @param string $email Email address.
	 * @param int    $limit Limit.
	 * @return array<int, array<string, mixed>>
	 */
	public function findByEmail( string $email, int $limit = 10 ): array {
		return $this->reader->findByEmail( $email, $limit );
	}

	/**
	 * Find by CPF/RF
	 *
	 * @param string $cpf Cpf.
	 * @param int    $limit Limit.
	 * @return array<int, array<string, mixed>>
	 */
	public function findByCpfRf( string $cpf, int $limit = 10 ): array {
		return $this->reader->findByCpfRf( $cpf, $limit );
	}

	/**
	 * Find by form ID
	 *
	 * @param int $form_id Form ID.
	 * @param int $limit Limit.
	 * @param int $offset Offset.
	 * @return array<int, array<string, mixed>>
	 */
	public function findByFormId( int $form_id, int $limit = 100, int $offset = 0 ): array {
		return $this->reader->findByFormId( $form_id, $limit, $offset );
	}

	/**
	 * Get all submissions by form_id(s) and status for export.
	 *
	 * @param int|array<int, int>|null $form_ids Single form ID, array of IDs, or null for all forms.
	 * @param string|null              $status Status filter (publish, trash, null = all).
	 * @return array<int, array<string, mixed>> Array of submissions
	 */
	public function getForExport( $form_ids = null, ?string $status = 'publish' ): array {
		return $this->reader->getForExport( $form_ids, $status );
	}

	/**
	 * Get a batch of submissions for export using cursor-based pagination.
	 *
	 * @since 5.0.0
	 * @param array<int, int>|null $form_ids  Form IDs filter (null = all forms).
	 * @param string|null          $status    Status filter.
	 * @param int                  $cursor_id Cursor: fetch rows with id < this value.
	 * @param int                  $limit     Batch size.
	 * @return array<int, array<string, mixed>>
	 */
	public function getExportBatch( ?array $form_ids, ?string $status, int $cursor_id, int $limit ): array {
		return $this->reader->getExportBatch( $form_ids, $status, $cursor_id, $limit );
	}

	/**
	 * Get only JSON data columns in batches for dynamic-key discovery.
	 *
	 * @since 5.0.0
	 * @param array<int, int>|null $form_ids  Form IDs filter.
	 * @param string|null          $status    Status filter.
	 * @param int                  $cursor_id Cursor: fetch rows with id < this value.
	 * @param int                  $limit     Batch size.
	 * @return array<int, array<string, mixed>>
	 */
	public function getExportKeysBatch( ?array $form_ids, ?string $status, int $cursor_id, int $limit ): array {
		return $this->reader->getExportKeysBatch( $form_ids, $status, $cursor_id, $limit );
	}

	/**
	 * Count total matching rows for export progress reporting.
	 *
	 * @since 5.0.0
	 * @param array<int, int>|null $form_ids Form IDs filter.
	 * @param string|null          $status   Status filter.
	 * @return int
	 */
	public function countForExport( ?array $form_ids, ?string $status ): int {
		return $this->reader->countForExport( $form_ids, $status );
	}

	/**
	 * Check if any submission has edit information.
	 *
	 * @return bool True if edited_at column exists and has data
	 */
	public function hasEditInfo(): bool {
		return $this->reader->hasEditInfo();
	}

	/**
	 * Find with pagination and filters.
	 *
	 * @param array<string, mixed> $args Arguments.
	 * @return array<string, mixed>
	 */
	public function findPaginated( array $args = array() ): array {
		return $this->reader->findPaginated( $args );
	}

	/**
	 * Count by status.
	 *
	 * @return array<string, int>
	 */
	public function countByStatus(): array {
		return $this->reader->countByStatus();
	}

	/**
	 * Fetch a single submission's `magic_token` column.
	 *
	 * @since 6.6.2
	 * @param int $submission_id Submission row ID.
	 * @return string|null
	 */
	public function findMagicTokenById( int $submission_id ): ?string {
		return $this->reader->findMagicTokenById( $submission_id );
	}

	/**
	 * Count submissions matching `(form_id, cpf_hash)`.
	 *
	 * @since 6.6.2
	 * @param int    $form_id  Form ID.
	 * @param string $cpf_hash SHA-256 hash of the CPF digits.
	 * @return int Always >= 0.
	 */
	public function countByFormAndCpfHash( int $form_id, string $cpf_hash ): int {
		return $this->reader->countByFormAndCpfHash( $form_id, $cpf_hash );
	}

	/**
	 * SQL fragment for the WP_User_Query orderby rewrite.
	 *
	 * @since 6.6.2
	 * @return string SQL subquery fragment (already including the surrounding parentheses).
	 */
	public function sql_user_certificate_count_subquery(): string {
		return $this->reader->sql_user_certificate_count_subquery();
	}

	// ─────────────────────────────────────────────.
	// Writes — delegate to SubmissionWriter.
	// ─────────────────────────────────────────────.

	/**
	 * Bulk-clear the `qr_code_cache` column across every submission row.
	 *
	 * @since 6.6.2
	 * @return int Number of rows whose cache was dropped.
	 */
	public function clearQrCodeCache(): int {
		return $this->writer->clearQrCodeCache();
	}

	/**
	 * Insert a submission row and drop the status-count cache.
	 *
	 * @param array<string, mixed> $data Data.
	 * @return int|false Insert ID on success, false on failure.
	 */
	public function insert( array $data ) {
		return $this->writer->insert( $data );
	}

	/**
	 * Update a submission and drop the status-count cache when status changes.
	 *
	 * @param int                  $id   Record ID.
	 * @param array<string, mixed> $data Data.
	 * @return int|false Rows updated, or false on error.
	 */
	public function update( int $id, array $data ) {
		return $this->writer->update( $id, $data );
	}

	/**
	 * Update status.
	 *
	 * @param int    $id Record ID.
	 * @param string $status Status.
	 * @return int|false
	 */
	public function updateStatus( int $id, string $status ) {
		return $this->writer->updateStatus( $id, $status );
	}

	/**
	 * Bulk update status.
	 *
	 * @param array<int, int> $ids    Submission IDs.
	 * @param string          $status Status.
	 * @return int|false
	 */
	public function bulkUpdateStatus( array $ids, string $status ) {
		return $this->writer->bulkUpdateStatus( $ids, $status );
	}

	/**
	 * Bulk delete.
	 *
	 * @param array<int, int> $ids Submission IDs.
	 * @return int|false
	 */
	public function bulkDelete( array $ids ) {
		return $this->writer->bulkDelete( $ids );
	}

	/**
	 * Move submissions to a different form, skipping conflicts.
	 *
	 * @param int             $from_form_id Source form ID.
	 * @param int             $to_form_id   Target form ID.
	 * @param array<int, int> $ids          Submission IDs.
	 * @return array{moved: list<int>, conflicts: list<int>}
	 */
	public function moveBetweenForms( int $from_form_id, int $to_form_id, array $ids ): array {
		return $this->writer->moveBetweenForms( $from_form_id, $to_form_id, $ids );
	}

	/**
	 * Delete by form ID.
	 *
	 * @param int $form_id Form ID.
	 * @return int|false
	 */
	public function deleteByFormId( int $form_id ) {
		return $this->writer->deleteByFormId( $form_id );
	}

	/**
	 * Update submission with edit tracking.
	 *
	 * @param int                  $id Submission ID.
	 * @param array<string, mixed> $data Data to update.
	 * @return int|false Number of rows updated or false on error
	 */
	public function updateWithEditTracking( int $id, array $data ) {
		return $this->writer->updateWithEditTracking( $id, $data );
	}
}

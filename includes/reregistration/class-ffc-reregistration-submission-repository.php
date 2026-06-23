<?php
/**
 * Reregistration Submission Repository
 *
 * Handles database operations for individual user responses to reregistration campaigns.
 *
 * Delegating façade over the read/write split introduced in #563 (Sprint D2):
 * reads live in {@see ReregistrationSubmissionReader}, writes in
 * {@see ReregistrationSubmissionWriter}. This class keeps the public contract
 * (constants + method signatures) and forwards each call to the appropriate
 * side.
 *
 * Tech-debt (#563 B3): migrate call sites to depend on
 * ReregistrationSubmissionReader / ReregistrationSubmissionWriter directly,
 * then retire this delegating façade.
 *
 * @package FreeFormCertificate\Reregistration
 * @since 4.11.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Reregistration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database repository for reregistration submission records.
 *
 * @phpstan-type ReregistrationSubmissionRow \stdClass&object{id: string, reregistration_id: string, user_id: string, status: string, submitted_at: numeric-string|int|null, reviewed_at: numeric-string|int|null, reviewed_by: string|null, notes: string|null, auth_code: string|null, magic_token: string|null, created_at: string, updated_at: string, data?: string|null}
 */
class ReregistrationSubmissionRepository {

	/**
	 * Valid submission statuses.
	 */
	public const STATUSES = array( 'pending', 'in_progress', 'submitted', 'approved', 'rejected', 'expired' );

	/**
	 * Get human-readable status labels.
	 *
	 * @return array<string, string> Status key => translated label.
	 */
	public static function get_status_labels(): array {
		return ReregistrationSubmissionReader::get_status_labels();
	}

	/**
	 * Get a single status label.
	 *
	 * @param string $status Status key.
	 * @return string Translated label (falls back to the key).
	 */
	public static function get_status_label( string $status ): string {
		return ReregistrationSubmissionReader::get_status_label( $status );
	}

	/**
	 * Get table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		return ReregistrationSubmissionReader::get_table_name();
	}

	/**
	 * Get a submission by ID.
	 *
	 * @param int $id Submission ID.
	 * @return ReregistrationSubmissionRow|null
	 */
	public static function get_by_id( int $id ): ?object {
		return ReregistrationSubmissionReader::get_by_id( $id );
	}

	/**
	 * Get a submission by its auth_code.
	 *
	 * @since 4.12.0
	 * @param string $auth_code Cleaned auth code (uppercase, no hyphens).
	 * @return ReregistrationSubmissionRow|null
	 */
	public static function get_by_auth_code( string $auth_code ): ?object {
		return ReregistrationSubmissionReader::get_by_auth_code( $auth_code );
	}

	/**
	 * Get a submission by its magic_token.
	 *
	 * @since 4.12.0
	 * @param string $token Magic token (64 hex chars).
	 * @return ReregistrationSubmissionRow|null
	 */
	public static function get_by_magic_token( string $token ): ?object {
		return ReregistrationSubmissionReader::get_by_magic_token( $token );
	}

	/**
	 * Ensure a submission has a magic_token, generating one if missing.
	 *
	 * @param object $submission Submission row object.
	 * @phpstan-param ReregistrationSubmissionRow $submission
	 * @return string The magic_token (existing or newly generated).
	 */
	public static function ensure_magic_token( object $submission ): string {
		return ReregistrationSubmissionWriter::ensure_magic_token( $submission );
	}

	/**
	 * Get submission for a specific reregistration and user.
	 *
	 * @param int $reregistration_id Reregistration ID.
	 * @param int $user_id           User ID.
	 * @return ReregistrationSubmissionRow|null
	 */
	public static function get_by_reregistration_and_user( int $reregistration_id, int $user_id ): ?object {
		return ReregistrationSubmissionReader::get_by_reregistration_and_user( $reregistration_id, $user_id );
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
		return ReregistrationSubmissionReader::get_all_by_user( $user_id );
	}

	/**
	 * Get submissions for a reregistration with optional filters.
	 *
	 * @param int                  $reregistration_id Reregistration ID.
	 * @param array<string, mixed> $filters { Optional. Query filters.
	 *     @type string $status  Filter by status.
	 *     @type string $search  Search in user display_name or email.
	 *     @type string $orderby Column to order by. Default 'created_at'.
	 *     @type string $order   ASC or DESC. Default 'ASC'.
	 *     @type int    $limit   Max results. Default 0.
	 *     @type int    $offset  Offset. Default 0.
	 * }
	 * @return list<ReregistrationSubmissionRow>
	 */
	public static function get_by_reregistration( int $reregistration_id, array $filters = array() ): array {
		return ReregistrationSubmissionReader::get_by_reregistration( $reregistration_id, $filters );
	}

	/**
	 * Create a submission record.
	 *
	 * @param array<string, mixed> $data Submission data.
	 * @return int|false Submission ID or false.
	 */
	public static function create( array $data ) {
		return ReregistrationSubmissionWriter::create( $data );
	}

	/**
	 * Update a submission.
	 *
	 * @param int                  $id   Submission ID.
	 * @param array<string, mixed> $data Update data.
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		return ReregistrationSubmissionWriter::update( $id, $data );
	}

	/**
	 * Approve a submission.
	 *
	 * @param int $id          Submission ID.
	 * @param int $reviewer_id Reviewer user ID.
	 * @return bool
	 */
	public static function approve( int $id, int $reviewer_id ): bool {
		return ReregistrationSubmissionWriter::approve( $id, $reviewer_id );
	}

	/**
	 * Reject a submission.
	 *
	 * @param int    $id          Submission ID.
	 * @param int    $reviewer_id Reviewer user ID.
	 * @param string $notes       Rejection reason.
	 * @return bool
	 */
	public static function reject( int $id, int $reviewer_id, string $notes = '' ): bool {
		return ReregistrationSubmissionWriter::reject( $id, $reviewer_id, $notes );
	}

	/**
	 * Return a submission to draft (in_progress) so the user can revise it.
	 *
	 * Clears the review metadata and resets submitted_at so the user
	 * sees it as an editable draft again.
	 *
	 * @param int $id          Submission ID.
	 * @param int $reviewer_id Admin user ID performing the action.
	 * @return bool
	 */
	public static function return_to_draft( int $id, int $reviewer_id ): bool {
		return ReregistrationSubmissionWriter::return_to_draft( $id, $reviewer_id );
	}

	/**
	 * Bulk return multiple submissions to draft.
	 *
	 * @param array<int> $ids         Submission IDs.
	 * @param int        $reviewer_id Admin user ID performing the action.
	 * @return int Number of submissions returned to draft.
	 */
	public static function bulk_return_to_draft( array $ids, int $reviewer_id ): int {
		return ReregistrationSubmissionWriter::bulk_return_to_draft( $ids, $reviewer_id );
	}

	/**
	 * Bulk approve multiple submissions.
	 *
	 * @param array<int> $ids         Submission IDs.
	 * @param int        $reviewer_id Reviewer user ID.
	 * @return int Number of approved submissions.
	 */
	public static function bulk_approve( array $ids, int $reviewer_id ): int {
		return ReregistrationSubmissionWriter::bulk_approve( $ids, $reviewer_id );
	}

	/**
	 * Get statistics for a reregistration.
	 *
	 * @param int $reregistration_id Reregistration ID.
	 * @return array<string, int> Counts keyed by status.
	 */
	public static function get_statistics( int $reregistration_id ): array {
		return ReregistrationSubmissionReader::get_statistics( $reregistration_id );
	}

	/**
	 * Get submissions for CSV export.
	 *
	 * @param int                  $reregistration_id Reregistration ID.
	 * @param array<string, mixed> $filters           Optional filters (status, search).
	 * @return list<ReregistrationSubmissionRow>
	 */
	public static function get_for_export( int $reregistration_id, array $filters = array() ): array {
		return ReregistrationSubmissionReader::get_for_export( $reregistration_id, $filters );
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
	 */
	public static function stream_for_export( int $reregistration_id, array $filters = array(), int $chunk_size = 500 ): \Generator {
		return ReregistrationSubmissionReader::stream_for_export( $reregistration_id, $filters, $chunk_size );
	}

	/**
	 * Create pending submissions for all affected users of a reregistration.
	 *
	 * Skips users who already have a submission for this reregistration.
	 *
	 * @param int        $reregistration_id Reregistration ID.
	 * @param array<int> $audience_ids      Audience IDs.
	 * @return int Number of submissions created.
	 */
	public static function create_for_audience_members( int $reregistration_id, array $audience_ids ): int {
		return ReregistrationSubmissionWriter::create_for_audience_members( $reregistration_id, $audience_ids );
	}

	/**
	 * Count submissions for a reregistration.
	 *
	 * @param int         $reregistration_id Reregistration ID.
	 * @param string|null $status            Optional status filter.
	 * @return int
	 */
	public static function count_by_reregistration( int $reregistration_id, ?string $status = null ): int {
		return ReregistrationSubmissionReader::count_by_reregistration( $reregistration_id, $status );
	}
}

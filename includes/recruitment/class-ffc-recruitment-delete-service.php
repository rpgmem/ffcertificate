<?php
/**
 * Recruitment Delete Service
 *
 * Centralizes the §7-bis deletion gates so the REST controller (sprint 9.1)
 * doesn't reimplement them and so each gate exposes a typed result envelope
 * with the blocking reference count when applicable. Three flavors:
 *
 *   - {@see self::delete_candidate} — candidate hard-delete. Allowed only
 *     when the candidate has zero rows in `ffc_recruitment_classification`.
 *     The linked `wp_user` (if any) is preserved untouched. ActivityLog
 *     entries referencing the now-defunct `candidate_id` survive — sprint 8
 *     ensures their payloads are already redacted.
 *
 *   - {@see self::delete_classification} — classification individual delete.
 *     Allowed only when the row is in `status='empty'` AND the notice is in
 *     `draft` or `preliminary`. Calls (`ffc_recruitment_call`) cannot exist
 *     for an `empty` classification — the state machine ensures any call
 *     creation transitions to `called` first — so no orphan rows are left
 *     behind.
 *
 *   - {@see self::delete_adjutancy} — adjutancy delete. Allowed only when
 *     zero rows reference the adjutancy in `ffc_recruitment_notice_adjutancy`
 *     (no notice still uses it) AND zero rows reference it in
 *     `ffc_recruitment_classification` (no historical classification
 *     references either, including in closed notices).
 *
 * All gates are advisory: the underlying repositories' `delete()` methods
 * are unconditional CRUD primitives. A caller bypassing this service can
 * still corrupt referential integrity, so all REST/admin paths MUST go
 * through this service.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.0.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service: gated deletion for candidate / classification / adjutancy.
 *
 * Result envelope:
 *
 *   array{
 *     success:  bool,
 *     errors:   list<string>,                 // stable error code(s)
 *     blocked_by?: array<string, int>,         // present when a gate fired;
 *                                              // map of "what blocks" → count
 *   }
 *
 * On a successful delete, `blocked_by` is omitted. On failure, `blocked_by`
 * may be present with concrete reference counts so the REST controller can
 * relay them (e.g. 409 with `{ "blocked_by": { "classifications": 3 } }`).
 *
 * @phpstan-type DeleteResult array{success: bool, errors: list<string>, blocked_by?: array<string, int>}
 */
final class RecruitmentDeleteService {

	/**
	 * Hard-delete a candidate row.
	 *
	 * Gate: zero `ffc_recruitment_classification` rows reference the
	 * candidate. `user_id` (if non-null) is preserved on `wp_users` —
	 * the recruitment module never deletes WordPress users.
	 *
	 * @param int $candidate_id Candidate ID.
	 * @return DeleteResult
	 */
	public static function delete_candidate( int $candidate_id ): array {
		$candidate = RecruitmentCandidateRepository::get_by_id( $candidate_id );
		if ( null === $candidate ) {
			return self::failure( 'recruitment_candidate_not_found' );
		}

		$classification_count = RecruitmentClassificationRepository::count_for_candidate( $candidate_id );
		if ( $classification_count > 0 ) {
			return array(
				'success'    => false,
				'errors'     => array( 'recruitment_candidate_has_classifications' ),
				'blocked_by' => array( 'classifications' => $classification_count ),
			);
		}

		$ok = RecruitmentCandidateRepository::delete( $candidate_id );
		if ( ! $ok ) {
			return self::failure( 'recruitment_candidate_delete_failed' );
		}

		return self::success();
	}

	/**
	 * Delete a single classification row.
	 *
	 * Gate: status must be `empty` AND the parent notice must be in
	 * `draft` or `preliminary`. Both gates protect against destroying
	 * audit-relevant history: a non-empty status implies a call exists or
	 * existed; a notice past `preliminary` has been promoted, so removing
	 * a classification would silently rewrite the published list.
	 *
	 * @param int $classification_id Classification ID.
	 * @return DeleteResult
	 */
	public static function delete_classification( int $classification_id ): array {
		$classification = RecruitmentClassificationRepository::get_by_id( $classification_id );
		if ( null === $classification ) {
			return self::failure( 'recruitment_classification_not_found' );
		}

		if ( 'empty' !== $classification->status ) {
			return self::failure( 'recruitment_classification_not_empty_for_delete' );
		}

		$notice = RecruitmentNoticeRepository::get_by_id( (int) $classification->notice_id );
		if ( null === $notice ) {
			return self::failure( 'recruitment_notice_not_found' );
		}

		if ( ! in_array( $notice->status, array( 'draft', 'preliminary' ), true ) ) {
			return self::failure( 'recruitment_classification_delete_requires_draft_or_preliminary' );
		}

		$ok = RecruitmentClassificationRepository::delete( $classification_id );
		if ( ! $ok ) {
			return self::failure( 'recruitment_classification_delete_failed' );
		}

		return self::success();
	}

	/**
	 * Delete an adjutancy row.
	 *
	 * Gate: zero references in `ffc_recruitment_notice_adjutancy` AND zero
	 * references in `ffc_recruitment_classification`. Both checks together
	 * mean "no notice currently uses this adjutancy AND no historical
	 * classification ever did either" — safe to drop.
	 *
	 * @param int $adjutancy_id Adjutancy ID.
	 * @return DeleteResult
	 */
	public static function delete_adjutancy( int $adjutancy_id ): array {
		$adjutancy = RecruitmentAdjutancyRepository::get_by_id( $adjutancy_id );
		if ( null === $adjutancy ) {
			return self::failure( 'recruitment_adjutancy_not_found' );
		}

		$attached_notice_ids = RecruitmentNoticeAdjutancyRepository::get_notice_ids_for_adjutancy( $adjutancy_id );
		$attached_count      = count( $attached_notice_ids );

		$classification_count = RecruitmentClassificationRepository::count_for_adjutancy( $adjutancy_id );

		if ( $attached_count > 0 || $classification_count > 0 ) {
			return array(
				'success'    => false,
				'errors'     => array( 'recruitment_adjutancy_in_use' ),
				'blocked_by' => array(
					'notice_adjutancies' => $attached_count,
					'classifications'    => $classification_count,
				),
			);
		}

		$ok = RecruitmentAdjutancyRepository::delete( $adjutancy_id );
		if ( ! $ok ) {
			return self::failure( 'recruitment_adjutancy_delete_failed' );
		}

		return self::success();
	}

	/**
	 * Build a successful delete envelope.
	 *
	 * @return DeleteResult
	 */
	private static function success(): array {
		return array(
			'success' => true,
			'errors'  => array(),
		);
	}

	/**
	 * Build a failed delete envelope with a single error code.
	 *
	 * @param string $code Stable error code.
	 * @return DeleteResult
	 */
	private static function failure( string $code ): array {
		return array(
			'success' => false,
			'errors'  => array( $code ),
		);
	}
}

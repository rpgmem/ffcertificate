<?php
/**
 * Recruitment Promotion Service
 *
 * Encapsulates the §5.1 `preliminary → definitive` promotion flow. The transition
 * itself goes through {@see RecruitmentNoticeStateMachine}; this service
 * adds the listing-side mechanics:
 *
 *   - **Snapshot mode** ({@see self::snapshot_to_definitive}) — duplicates
 *     every `list_type='preview'` classification row into
 *     `list_type='definitive'`, atomically, before flipping the notice
 *     status. Wipes any existing `definitive` rows first (legacy from a
 *     prior `preliminary → definitive → preliminary` cycle, which is the
 *     only way `definitive` can pre-exist when promoting).
 *
 *   - **Definitive-import mode** is handled directly by
 *     {@see RecruitmentCsvImporter::import_definitive()} — this service
 *     only orchestrates the snapshot path. The REST controller (sprint 9.1)
 *     dispatches between the two modes based on the request body.
 *
 * Both paths run inside a single InnoDB transaction so a failure during
 * the row-copy or status flip rolls everything back, leaving the previous
 * `preview` and `definitive` lists untouched.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.0.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
/**
 * Service: orchestrate the promote-preview snapshot path.
 *
 * Result envelope is the same as the importer / state machines:
 *
 *   array{
 *     success:  bool,
 *     copied:   int,            // classification rows copied to `definitive`
 *     errors:   list<string>,
 *   }
 *
 * @phpstan-type SnapshotResult array{success: bool, copied: int, errors: list<string>}
 */
final class RecruitmentPromotionService {

	/**
	 * Snapshot the current `preview` rows into `definitive`, then flip the
	 * notice status to `definitive`.
	 *
	 * The order of operations within the transaction is:
	 *
	 *   1. Verify the notice exists and is in `preliminary`.
	 *   2. Wipe any pre-existing `list_type='definitive'` rows for this notice.
	 *   3. Copy each `list_type='preview'` row into `list_type='definitive'`,
	 *      preserving (candidate_id, adjutancy_id, rank, score) and resetting
	 *      `status='empty'` (the §5.2 invariant: convocation acts only on
	 *      definitive rows that start empty).
	 *   4. Transition the notice via the state machine.
	 *
	 * Step 4 is delegated to {@see RecruitmentNoticeStateMachine::transition_to},
	 * which performs the atomic conditional UPDATE and the lifecycle stamping
	 * (`opened_at`).
	 *
	 * @param int $notice_id Notice ID to promote.
	 * @return SnapshotResult
	 */
	public static function snapshot_to_definitive( int $notice_id ): array {
		$notice = RecruitmentNoticeRepository::get_by_id( $notice_id );
		if ( null === $notice ) {
			return self::failure( 'recruitment_notice_not_found' );
		}

		if ( 'preliminary' !== $notice->status ) {
			return self::failure( 'recruitment_promotion_requires_preliminary_state' );
		}

		$preview_rows = RecruitmentClassificationRepository::get_for_notice( $notice_id, 'preview' );
		if ( empty( $preview_rows ) ) {
			return self::failure( 'recruitment_promotion_no_preview_rows' );
		}

		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );

		try {
			RecruitmentClassificationRepository::delete_all_for_notice_list( $notice_id, 'definitive' );

			$copied = 0;
			foreach ( $preview_rows as $row ) {
				$new_id = RecruitmentClassificationRepository::create(
					array(
						'candidate_id' => (int) $row->candidate_id,
						'adjutancy_id' => (int) $row->adjutancy_id,
						'notice_id'    => $notice_id,
						'list_type'    => 'definitive',
						'rank'         => (int) $row->rank,
						'score'        => $row->score,
					)
				);
				if ( false === $new_id ) {
					$wpdb->query( 'ROLLBACK' );
					return self::failure( 'recruitment_promotion_copy_failed' );
				}
				++$copied;
			}

			// Flip notice status to `definitive` via the state machine. This
			// stamps `opened_at` on the first promotion (idempotent on
			// subsequent ones) and runs the race-safe conditional UPDATE.
			$transition = RecruitmentNoticeStateMachine::transition_to( $notice_id, 'definitive' );
			if ( ! $transition['success'] ) {
				$wpdb->query( 'ROLLBACK' );
				return array(
					'success' => false,
					'copied'  => 0,
					'errors'  => $transition['errors'],
				);
			}

			$wpdb->query( 'COMMIT' );

			RecruitmentActivityLogger::notice_promoted( $notice_id, 'snapshot', $copied );

			return array(
				'success' => true,
				'copied'  => $copied,
				'errors'  => array(),
			);
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return self::failure( 'recruitment_promotion_unexpected_error: ' . $e->getMessage() );
		}
	}

	/**
	 * Build a failed snapshot envelope.
	 *
	 * @param string $code Stable error code.
	 * @return SnapshotResult
	 */
	private static function failure( string $code ): array {
		return array(
			'success' => false,
			'copied'  => 0,
			'errors'  => array( $code ),
		);
	}
}

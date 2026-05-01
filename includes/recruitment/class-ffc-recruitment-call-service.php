<?php
/**
 * Recruitment Call Service
 *
 * The convocation orchestrator: pairs each new `ffc_recruitment_call` row
 * with the corresponding atomic classification status transition
 * (`empty → called`), and pairs each cancellation with the inverse flow
 * (`called|accepted → empty` plus stamping `cancelled_at` on the call row).
 *
 * Sprint 5's state machine handles status-only transitions; this service
 * exists because call creation/cancellation needs both the status flip AND
 * a row insert / column stamp on `ffc_recruitment_call` to land or not
 * land together. Both are wrapped in InnoDB transactions and use the
 * conditional UPDATE primitive on the repository so a lost race cleanly
 * surfaces as `recruitment_state_locked` without leaving an orphan call
 * row.
 *
 * Public surface:
 *
 *   - {@see self::call_single} — convocation for a single classification.
 *     Detects "out of order" automatically by comparing the target row's
 *     id to the result of {@see RecruitmentClassificationRepository::find_lowest_rank_empty}.
 *     Out-of-order calls REQUIRE a non-empty `out_of_order_reason`; in-order
 *     calls require none. Sets `out_of_order = 1` on the call row.
 *
 *   - {@see self::call_bulk} — atomic all-or-nothing bulk convocation. All
 *     classifications must be `empty` and pass the same per-row gates as
 *     `call_single`. Out-of-order reasons are looked up per-row in the
 *     `$out_of_order_reasons` map (keyed by classification ID); a missing
 *     reason for an out-of-order row aborts the bulk.
 *
 *   - {@see self::cancel_call} — append-only cancellation: the call row is
 *     stamped (`cancelled_at` / `cancelled_by` / `cancellation_reason`)
 *     instead of deleted, so the audit trail is preserved. The
 *     classification atomically transitions back to `empty`.
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
 * Service: orchestrate convocation creation and cancellation.
 *
 * Public methods return:
 *
 *   array{
 *     success:  bool,
 *     call_ids: list<int>,     // committed call row IDs (single: one element)
 *     errors:   list<string>,
 *   }
 *
 * @phpstan-type CallResult array{success: bool, call_ids: list<int>, errors: list<string>}
 */
final class RecruitmentCallService {

	/**
	 * Issue a single convocation for one classification.
	 *
	 * @param int         $classification_id   Target classification (must be `empty`).
	 * @param string      $date_to_assume      `YYYY-MM-DD`.
	 * @param string      $time_to_assume      `HH:MM` or `HH:MM:SS`.
	 * @param int         $created_by          WP user ID issuing the call.
	 * @param string|null $out_of_order_reason Required when the target is not the lowest-rank `empty` row.
	 * @param string|null $notes               Optional.
	 * @return CallResult
	 */
	public static function call_single(
		int $classification_id,
		string $date_to_assume,
		string $time_to_assume,
		int $created_by,
		?string $out_of_order_reason = null,
		?string $notes = null
	): array {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );

		$result = self::call_one(
			$classification_id,
			$date_to_assume,
			$time_to_assume,
			$created_by,
			$out_of_order_reason,
			$notes
		);

		if ( ! $result['success'] ) {
			$wpdb->query( 'ROLLBACK' );
			return array(
				'success'  => false,
				'call_ids' => array(),
				'errors'   => $result['errors'],
			);
		}

		$wpdb->query( 'COMMIT' );

		return array(
			'success'  => true,
			'call_ids' => array( $result['call_id'] ),
			'errors'   => array(),
		);
	}

	/**
	 * Issue a bulk convocation across N classifications, atomic.
	 *
	 * The shared `date_to_assume` / `time_to_assume` are applied to every
	 * call row. Per-row out-of-order reasons are read from
	 * `$out_of_order_reasons[$classification_id]` (string-keyed for
	 * transport-friendliness when this surfaces as a REST body). Any
	 * single-row failure rolls back the entire batch.
	 *
	 * @param array                 $classification_ids   Classifications to convocate (list<int>; must all be `empty`).
	 * @phpstan-param list<int>     $classification_ids
	 * @param string                $date_to_assume       `YYYY-MM-DD` shared across the batch.
	 * @param string                $time_to_assume       `HH:MM` or `HH:MM:SS` shared across the batch.
	 * @param int                   $created_by           WP user ID issuing the bulk call.
	 * @param array<string, string> $out_of_order_reasons Keyed by classification ID (string).
	 * @param string|null           $notes                Optional, applied to every call row.
	 * @return CallResult
	 */
	public static function call_bulk(
		array $classification_ids,
		string $date_to_assume,
		string $time_to_assume,
		int $created_by,
		array $out_of_order_reasons = array(),
		?string $notes = null
	): array {
		if ( empty( $classification_ids ) ) {
			return self::failure( 'recruitment_bulk_call_empty_id_list' );
		}

		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );

		$call_ids = array();

		foreach ( $classification_ids as $classification_id ) {
			$reason = $out_of_order_reasons[ (string) $classification_id ] ?? null;

			$result = self::call_one(
				(int) $classification_id,
				$date_to_assume,
				$time_to_assume,
				$created_by,
				$reason,
				$notes
			);

			if ( ! $result['success'] ) {
				$wpdb->query( 'ROLLBACK' );
				return array(
					'success'  => false,
					'call_ids' => array(),
					'errors'   => array_merge(
						array( 'recruitment_bulk_call_failed_at_classification: ' . $classification_id ),
						$result['errors']
					),
				);
			}

			$call_ids[] = $result['call_id'];
		}

		$wpdb->query( 'COMMIT' );

		return array(
			'success'  => true,
			'call_ids' => $call_ids,
			'errors'   => array(),
		);
	}

	/**
	 * Cancel an active call. Atomic: classification rolls back to `empty`
	 * AND the call row gets `cancelled_at` stamped, both inside one
	 * transaction.
	 *
	 * @param int    $call_id      Active call row ID.
	 * @param string $reason       Cancellation reason (REQUIRED, non-empty).
	 * @param int    $cancelled_by WP user ID performing the cancel.
	 * @return CallResult
	 */
	public static function cancel_call( int $call_id, string $reason, int $cancelled_by ): array {
		if ( '' === trim( $reason ) ) {
			return self::failure( 'recruitment_cancel_reason_required' );
		}

		$call = RecruitmentCallRepository::get_by_id( $call_id );
		if ( null === $call ) {
			return self::failure( 'recruitment_call_not_found' );
		}

		if ( null !== $call->cancelled_at ) {
			return self::failure( 'recruitment_call_already_cancelled' );
		}

		$classification_id = (int) $call->classification_id;
		$classification    = RecruitmentClassificationRepository::get_by_id( $classification_id );
		if ( null === $classification ) {
			return self::failure( 'recruitment_classification_not_found' );
		}

		$current_status = $classification->status;
		if ( ! in_array( $current_status, array( 'called', 'accepted' ), true ) ) {
			// `not_shown`/`hired`/`empty` mean the call has already moved on
			// — admin should use a status transition instead.
			return self::failure( 'recruitment_cancel_only_from_called_or_accepted' );
		}

		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );

		try {
			$transition = RecruitmentClassificationStateMachine::transition_to(
				$classification_id,
				'empty',
				$reason
			);
			if ( ! $transition['success'] ) {
				$wpdb->query( 'ROLLBACK' );
				return array(
					'success'  => false,
					'call_ids' => array(),
					'errors'   => $transition['errors'],
				);
			}

			$affected = RecruitmentCallRepository::mark_cancelled( $call_id, $reason, $cancelled_by );
			if ( 1 !== $affected ) {
				$wpdb->query( 'ROLLBACK' );
				return self::failure( 'recruitment_call_cancel_race_lost' );
			}

			$wpdb->query( 'COMMIT' );

			return array(
				'success'  => true,
				'call_ids' => array( $call_id ),
				'errors'   => array(),
			);
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return self::failure( 'recruitment_cancel_unexpected_error: ' . $e->getMessage() );
		}
	}

	/**
	 * Inner per-classification call flow. Caller is responsible for
	 * wrapping with `START TRANSACTION` / `COMMIT` / `ROLLBACK`.
	 *
	 * Performs:
	 *
	 *   1. Load classification (must exist, must be `empty`).
	 *   2. Determine out-of-order via `find_lowest_rank_empty` comparison.
	 *   3. Validate `out_of_order_reason` requirement.
	 *   4. Conditional UPDATE classification status `empty → called`.
	 *      If 0 affected, the row is no longer empty (concurrent writer)
	 *      → return race-lost error.
	 *   5. INSERT the call row with `out_of_order` flag set appropriately.
	 *
	 * @param int         $classification_id   Target classification ID.
	 * @param string      $date_to_assume      Date the candidate is summoned to assume.
	 * @param string      $time_to_assume      Time of day for the assumption.
	 * @param int         $created_by          WP user ID issuing the call.
	 * @param string|null $out_of_order_reason Required iff the row is not the lowest-rank empty.
	 * @param string|null $notes               Optional notes attached to the call row.
	 * @return array{success: bool, call_id: int, errors: list<string>}
	 */
	private static function call_one(
		int $classification_id,
		string $date_to_assume,
		string $time_to_assume,
		int $created_by,
		?string $out_of_order_reason,
		?string $notes
	): array {
		$classification = RecruitmentClassificationRepository::get_by_id( $classification_id );
		if ( null === $classification ) {
			return self::inner_failure( 'recruitment_classification_not_found' );
		}

		if ( 'empty' !== $classification->status ) {
			return self::inner_failure( 'recruitment_classification_not_empty' );
		}

		// Out-of-order check.
		$lowest_empty    = RecruitmentClassificationRepository::find_lowest_rank_empty(
			(int) $classification->notice_id,
			(int) $classification->adjutancy_id,
			$classification->list_type
		);
		$is_out_of_order = ( null === $lowest_empty ) || ( (int) $lowest_empty->id !== $classification_id );

		if ( $is_out_of_order ) {
			if ( null === $out_of_order_reason || '' === trim( $out_of_order_reason ) ) {
				return self::inner_failure( 'recruitment_out_of_order_requires_reason' );
			}
		}

		// Atomic empty → called.
		$affected = RecruitmentClassificationRepository::set_status(
			$classification_id,
			'empty',
			'called'
		);
		if ( 1 !== $affected ) {
			return self::inner_failure( 'recruitment_state_locked' );
		}

		// Insert the call row.
		$payload = array(
			'classification_id' => $classification_id,
			'date_to_assume'    => $date_to_assume,
			'time_to_assume'    => $time_to_assume,
			'created_by'        => $created_by,
			'out_of_order'      => $is_out_of_order ? 1 : 0,
		);
		if ( $is_out_of_order ) {
			$payload['out_of_order_reason'] = $out_of_order_reason;
		}
		if ( null !== $notes && '' !== $notes ) {
			$payload['notes'] = $notes;
		}

		$call_id = RecruitmentCallRepository::create( $payload );
		if ( false === $call_id ) {
			return self::inner_failure( 'recruitment_call_insert_failed' );
		}

		return array(
			'success' => true,
			'call_id' => (int) $call_id,
			'errors'  => array(),
		);
	}

	/**
	 * Public-facing failure envelope.
	 *
	 * @param string $code Stable error code.
	 * @return CallResult
	 */
	private static function failure( string $code ): array {
		return array(
			'success'  => false,
			'call_ids' => array(),
			'errors'   => array( $code ),
		);
	}

	/**
	 * Inner-flow failure envelope (single classification).
	 *
	 * @param string $code Stable error code.
	 * @return array{success: bool, call_id: int, errors: list<string>}
	 */
	private static function inner_failure( string $code ): array {
		return array(
			'success' => false,
			'call_id' => 0,
			'errors'  => array( $code ),
		);
	}
}

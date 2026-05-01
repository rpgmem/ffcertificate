<?php
/**
 * Recruitment Notice State Machine
 *
 * Authoritative source of truth for the §5.1 notice lifecycle:
 *
 *   draft → preliminary → active → closed
 *                ↑           ↓
 *                └───────────┘    (active → preliminary if zero calls)
 *                            ↓
 *                          closed → active   (with reopen reason)
 *
 * Atomic transitions go through {@see RecruitmentNoticeRepository::set_status},
 * which uses a `WHERE status = '<expected>'` guard so concurrent transition
 * attempts safely fail with `affected_rows = 0`. Side-effects (stamping
 * `opened_at` on the first → active, `closed_at` on every → closed,
 * flipping `was_reopened = 1` on the first closed → active) are wired here
 * after the conditional UPDATE wins.
 *
 * Reason gating mirrors §5.1:
 *
 *   - draft → preliminary  : no reason
 *   - preliminary → active : no reason (snapshot/definitive-import handled
 *                            by {@see RecruitmentPromotionService}, not here)
 *   - active → preliminary : no reason; gated on `count_calls_for_notice = 0`
 *   - active → closed      : no reason
 *   - closed → active      : reason REQUIRED (logged); flips `was_reopened`
 *
 * Once `notice.was_reopened = 1` the classification-level reopen of
 * `not_shown → empty` is permanently blocked for that notice — see
 * {@see RecruitmentClassificationStateMachine}.
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
 * Service: transition a notice's status with full lifecycle rules.
 *
 * All public methods return the same envelope:
 *
 *   array{
 *     success: bool,
 *     errors:  list<string>,
 *   }
 *
 * Errors use stable `recruitment_*` codes so REST controllers (sprint 9.1)
 * can map them to user-facing messages.
 *
 * @phpstan-type TransitionResult array{success: bool, errors: list<string>}
 */
final class RecruitmentNoticeStateMachine {

	/**
	 * Allowed transitions, expressed as `from => list<to>`.
	 *
	 * @var array<string, list<string>>
	 */
	private const TRANSITIONS = array(
		'draft'       => array( 'preliminary' ),
		'preliminary' => array( 'active' ),
		'active'      => array( 'preliminary', 'closed' ),
		'closed'      => array( 'active' ),
	);

	/**
	 * Transitions that require a non-empty reason string.
	 *
	 * @var array<string, list<string>>
	 */
	private const REASON_REQUIRED = array(
		'closed' => array( 'active' ),
	);

	/**
	 * Attempt to transition a notice to a new status.
	 *
	 * @param int         $notice_id Target notice.
	 * @param string      $new_status `draft|preliminary|active|closed`.
	 * @param string|null $reason     Required when transitioning closed → active.
	 * @return TransitionResult
	 */
	public static function transition_to( int $notice_id, string $new_status, ?string $reason = null ): array {
		$notice = RecruitmentNoticeRepository::get_by_id( $notice_id );
		if ( null === $notice ) {
			return self::failure( 'recruitment_notice_not_found' );
		}

		$current = $notice->status;

		// Same-state transition is a no-op success — idempotent for callers
		// that don't track the current state.
		if ( $current === $new_status ) {
			return self::success();
		}

		// Validate the requested transition.
		$allowed = self::TRANSITIONS[ $current ] ?? array();
		if ( ! in_array( $new_status, $allowed, true ) ) {
			return self::failure( 'recruitment_invalid_transition: ' . $current . '->' . $new_status );
		}

		// Reason gating.
		if ( self::is_reason_required( $current, $new_status ) ) {
			if ( null === $reason || '' === trim( $reason ) ) {
				return self::failure( 'recruitment_transition_reason_required' );
			}
		}

		// `active → preliminary` requires zero calls in the notice's history.
		if ( 'active' === $current && 'preliminary' === $new_status ) {
			$call_count = RecruitmentClassificationRepository::count_calls_for_notice( $notice_id );
			if ( $call_count > 0 ) {
				return self::failure( 'recruitment_active_to_preliminary_blocked_by_calls' );
			}
		}

		// Atomic transition (race-safe).
		$affected = RecruitmentNoticeRepository::set_status( $notice_id, $current, $new_status );
		if ( 1 !== $affected ) {
			return self::failure( 'recruitment_transition_race_lost' );
		}

		// Side-effects after the UPDATE wins.
		self::apply_side_effects( $notice_id, $new_status );

		return self::success();
	}

	/**
	 * Run the per-target-state lifecycle stamping.
	 *
	 * - `active`  : stamp `opened_at` on the first → active (idempotent
	 *               via the WHERE guard in {@see RecruitmentNoticeRepository::mark_opened}).
	 *               When the notice was previously `closed`, also flip
	 *               `was_reopened = 1` (one-way) so the classification
	 *               reopen-freeze rule kicks in.
	 * - `closed`  : stamp `closed_at` (overwrites on each closure).
	 * - other     : no-op.
	 *
	 * Determining "previously closed" is done by re-reading the notice
	 * after the status transition committed: if `closed_at` is non-null,
	 * the notice has been closed at least once. The first → active sets
	 * `opened_at` for the first time and does NOT flip `was_reopened`
	 * (because `closed_at IS NULL`); subsequent active transitions after
	 * a close do flip the flag.
	 *
	 * @param int    $notice_id Notice ID.
	 * @param string $new_status Target status.
	 * @return void
	 */
	private static function apply_side_effects( int $notice_id, string $new_status ): void {
		switch ( $new_status ) {
			case 'active':
				$notice = RecruitmentNoticeRepository::get_by_id( $notice_id );
				if ( null === $notice ) {
					return;
				}

				if ( null === $notice->opened_at ) {
					RecruitmentNoticeRepository::mark_opened( $notice_id );
				}

				if ( null !== $notice->closed_at ) {
					// We arrived at active via reopen — set the flag.
					RecruitmentNoticeRepository::mark_reopened( $notice_id );
				}
				break;
			case 'closed':
				RecruitmentNoticeRepository::mark_closed( $notice_id );
				break;
		}
	}

	/**
	 * Whether the given transition pair requires a reason.
	 *
	 * @param string $from From status.
	 * @param string $to   To status.
	 * @return bool
	 */
	private static function is_reason_required( string $from, string $to ): bool {
		$gated = self::REASON_REQUIRED[ $from ] ?? array();
		return in_array( $to, $gated, true );
	}

	/**
	 * Build a successful transition envelope.
	 *
	 * @return TransitionResult
	 */
	private static function success(): array {
		return array(
			'success' => true,
			'errors'  => array(),
		);
	}

	/**
	 * Build a failed transition envelope with a single error code.
	 *
	 * @param string $code Stable error code.
	 * @return TransitionResult
	 */
	private static function failure( string $code ): array {
		return array(
			'success' => false,
			'errors'  => array( $code ),
		);
	}
}

<?php
/**
 * Recruitment Classification State Machine
 *
 * Authoritative source of truth for the §5.2 classification lifecycle:
 *
 *   empty → called → accepted → hired
 *                ↓         ↓        (terminal)
 *                ↓         not_shown → empty (reopen)
 *                ↓
 *                empty (cancel)
 *
 * Atomic transitions go through {@see RecruitmentClassificationRepository::set_status},
 * which uses a `WHERE status = '<expected>'` guard. Lost races (concurrent
 * writer claimed the row first) surface as `recruitment_state_locked`.
 *
 * Reason gating mirrors §5.2:
 *
 *   - empty → called (in order)     : no reason
 *   - empty → called (out of order) : reason REQUIRED — handled by sprint 6
 *                                     {@see RecruitmentCallService}, which
 *                                     calls `transition_to()` only after
 *                                     building the call row with
 *                                     `out_of_order_reason` set.
 *   - called → accepted             : no reason
 *   - called → not_shown            : no reason
 *   - called → hired                : no reason
 *   - called → empty (cancel)       : reason REQUIRED
 *   - accepted → hired              : no reason
 *   - accepted → not_shown          : no reason
 *   - accepted → empty (cancel)     : reason REQUIRED
 *   - not_shown → empty (reopen)    : reason REQUIRED — BUT BLOCKED if
 *                                     the parent notice's `was_reopened = 1`
 *                                     (§5.1 reopen-freeze rule)
 *   - hired → *                     : BLOCKED (terminal)
 *
 * The reopen-freeze rule: once a notice has gone through `closed → active`
 * at least once, all classifications in `hired` or `not_shown` are
 * permanently locked for that notice. Realized outcomes shouldn't be
 * retroactively undone by a reopen.
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
 * Service: transition a classification's status with full lifecycle rules.
 *
 * Returns the same `array{success, errors}` envelope as
 * {@see RecruitmentNoticeStateMachine}.
 *
 * @phpstan-type TransitionResult array{success: bool, errors: list<string>}
 */
final class RecruitmentClassificationStateMachine {

	/**
	 * Allowed transitions, expressed as `from => list<to>`.
	 *
	 * @var array<string, list<string>>
	 */
	private const TRANSITIONS = array(
		'empty'     => array( 'called' ),
		'called'    => array( 'accepted', 'not_shown', 'hired', 'empty' ),
		'accepted'  => array( 'hired', 'not_shown', 'empty' ),
		'not_shown' => array( 'empty' ),
		'hired'     => array(), // Terminal — no transitions out.
	);

	/**
	 * Transitions that require a non-empty reason string.
	 *
	 * @var array<string, list<string>>
	 */
	private const REASON_REQUIRED = array(
		'called'    => array( 'empty' ),
		'accepted'  => array( 'empty' ),
		'not_shown' => array( 'empty' ),
	);

	/**
	 * Attempt to transition a classification to a new status.
	 *
	 * Out-of-order calls (`empty → called` with a reason) are handled by
	 * the convocation service in sprint 6, which performs the atomic UPDATE
	 * directly on the repository to keep the call-creation and
	 * status-change in a single conditional UPDATE per row. This method is
	 * for the post-call lifecycle (called/accepted/hired/not_shown/cancel/reopen).
	 *
	 * @param int         $classification_id Target classification.
	 * @param string      $new_status `empty|called|accepted|not_shown|hired`.
	 * @param string|null $reason     Required when cancelling (`* → empty`).
	 * @return TransitionResult
	 */
	public static function transition_to( int $classification_id, string $new_status, ?string $reason = null ): array {
		$classification = RecruitmentClassificationRepository::get_by_id( $classification_id );
		if ( null === $classification ) {
			return self::failure( 'recruitment_classification_not_found' );
		}

		$current = $classification->status;

		// Idempotent same-state.
		if ( $current === $new_status ) {
			return self::success();
		}

		// Validate the requested transition.
		$allowed = self::TRANSITIONS[ $current ] ?? array();
		if ( ! in_array( $new_status, $allowed, true ) ) {
			if ( 'hired' === $current ) {
				return self::failure( 'recruitment_state_terminal_hired' );
			}
			return self::failure( 'recruitment_invalid_transition: ' . $current . '->' . $new_status );
		}

		// Reason gating.
		if ( self::is_reason_required( $current, $new_status ) ) {
			if ( null === $reason || '' === trim( $reason ) ) {
				return self::failure( 'recruitment_transition_reason_required' );
			}
		}

		// Reopen-freeze rule: once `notice.was_reopened = 1`, transitions
		// out of `hired` and `not_shown` are blocked. `hired` is already
		// caught by the terminal check above; `not_shown → empty` would
		// otherwise be allowed — gate it here.
		if ( 'not_shown' === $current && 'empty' === $new_status ) {
			$notice_id = (int) $classification->notice_id;
			$notice    = RecruitmentNoticeRepository::get_by_id( $notice_id );
			if ( null !== $notice && '1' === $notice->was_reopened ) {
				return self::failure( 'recruitment_reopen_freeze_active' );
			}
		}

		// Atomic transition (race-safe).
		$affected = RecruitmentClassificationRepository::set_status(
			$classification_id,
			$current,
			$new_status
		);
		if ( 1 !== $affected ) {
			return self::failure( 'recruitment_state_locked' );
		}

		return self::success();
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
	 * Build a failed transition envelope.
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

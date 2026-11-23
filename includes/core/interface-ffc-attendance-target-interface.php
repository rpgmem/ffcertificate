<?php
/**
 * AttendanceTargetInterface
 *
 * What a module implements to receive attendance through
 * {@see Attendance::record()}. The module owns where the record is stored;
 * Core only routes to it, so a caller such as the self-service terminal never
 * needs to know which module it serves (#2740).
 *
 * @package FreeFormCertificate\Core
 * @since   6.46.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A kind of thing a person can be present at.
 */
interface AttendanceTargetInterface {

	/**
	 * Stable type id, as registered in {@see AttendanceTargetRegistry}.
	 *
	 * @return string
	 */
	public function type(): string;

	/**
	 * Record a person present at a target.
	 *
	 * One record per person, target and day: a second reading on the same
	 * day must answer {@see Attendance::ALREADY} and change nothing.
	 *
	 * @param int      $target_id  Id within the type (a booking id, a form id).
	 * @param int      $user_id    The person.
	 * @param int      $checked_at Unix UTC seconds of the reading itself, not of the sync.
	 * @param string   $method     One of {@see Attendance::METHODS}.
	 * @param int|null $marked_by  The operator, for a mark by hand.
	 * @return string {@see Attendance::RECORDED}, {@see Attendance::ALREADY} or {@see Attendance::FAILED}.
	 */
	public function record( int $target_id, int $user_id, int $checked_at, string $method, ?int $marked_by ): string;
}

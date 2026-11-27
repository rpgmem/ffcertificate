<?php
/**
 * Terminal Target Interface
 *
 * What a self-service terminal needs from an attendance target (#2890): when
 * it can be read, what to show, and what one reading means. A module that
 * implements it can be put in a terminal's queue; the terminal code reads
 * nothing of the module's own tables.
 *
 * @package FreeFormCertificate\Core
 * @since   6.52.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An attendance target a terminal can check in.
 *
 * @phpstan-import-type Verdict from TerminalVerdict
 * @phpstan-type TerminalWindow array{date: string, opens: int, closes: int}
 * @phpstan-type TerminalEntry array{title: string, place: string, hours: string, open: bool, closes: int, present: int, enrolled: int, ticket?: bool}
 * @phpstan-type TerminalReading array{user_id: int, name: string, ticket: bool, at: int, terminal_id: int, queued: bool, ticket_code: string}
 */
interface TerminalTargetInterface extends AttendanceTargetInterface {

	/**
	 * The planned check-in windows of a target, one per day it counts.
	 *
	 * A window is the stretch a terminal may read the target in: the overlap
	 * rule and the terminal's "next event" screen are built from it.
	 *
	 * @param int $target_id Id within the type.
	 * @return list<TerminalWindow> Empty when the target is gone or inactive.
	 */
	public function terminal_windows( int $target_id ): array;

	/**
	 * When the target ends, which is when a terminal holding it may stop.
	 *
	 * @param int $target_id Id within the type.
	 * @return int|null Unix time, or null when the target is gone or inactive.
	 */
	public function terminal_ends_at( int $target_id ): ?int;

	/**
	 * Why the target may not join a terminal's queue, beyond being active and
	 * ending in time, which the caller checks through {@see self::terminal_ends_at()}.
	 *
	 * @param int $target_id Id within the type.
	 * @return \WP_Error|null Null when it may.
	 */
	public function terminal_queue_error( int $target_id ): ?\WP_Error;

	/**
	 * The target's name, as the terminal, the operator and the overlap
	 * refusal show it.
	 *
	 * @param int $target_id Id within the type.
	 * @return string
	 */
	public function terminal_title( int $target_id ): string;

	/**
	 * What the terminal shows for one of the target's windows.
	 *
	 * `closes` may come before the window's planned close, when an operator
	 * closed check-in early; `open` says whether reading is open right now.
	 *
	 * @param int   $target_id Id within the type.
	 * @param array $window    One of {@see self::terminal_windows()}.
	 * @param int   $now       Unix time.
	 * @phpstan-param TerminalWindow $window
	 * @return TerminalEntry
	 */
	public function terminal_entry( int $target_id, array $window, int $now ): array;

	/**
	 * What one verified reading means for the target, recorded or refused.
	 *
	 * The caller has already verified the code and that the terminal holds
	 * the target. A verdict whose status is {@see TerminalVerdict::RECORDED}
	 * or {@see TerminalVerdict::PENDING} wrote a record; the caller counts it
	 * on the terminal.
	 *
	 * The reading carries the person the code names (`user_id`, and their
	 * short `name`), whether the code carries the site's presence ticket for
	 * the target (`ticket`), its moment (`at`), the terminal that read it,
	 * whether it was queued offline and sent later (`queued`), and a code the
	 * person typed at the terminal for the target, or '' (`ticket_code`).
	 *
	 * @param int   $target_id Id within the type.
	 * @param array $reading   The reading.
	 * @phpstan-param TerminalReading $reading
	 * @return Verdict
	 */
	public function terminal_read( int $target_id, array $reading ): array;
}

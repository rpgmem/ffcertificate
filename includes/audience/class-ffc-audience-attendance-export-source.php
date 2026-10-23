<?php
/**
 * AudienceAttendanceExportSource
 *
 * The attendance list of one booking as CSV (#1947).
 *
 * @package FreeFormCertificate\Audience
 * @since 6.42.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\FilenameHelper;
use FreeFormCertificate\Core\SyncSourceInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One row per participant, one column per counted day.
 *
 * Synchronous on purpose (CLAUDE.md "CSV export architecture"): the rows are
 * one booking's participants, which the attendance screen already loads
 * whole, so the size is bounded by what that screen draws, and streaming
 * keeps the list off disk. The file names people, never their e-mail or
 * document numbers: what it records is attendance, not identity. It reads
 * the same {@see AudienceAttendanceRoster} the screen draws, so the two
 * cannot disagree.
 *
 * Authorisation is the caller's ({@see AudienceAdminAttendance}): nonce and
 * the export capability are checked before this source is built.
 *
 * @since 6.42.0
 */
class AudienceAttendanceExportSource implements SyncSourceInterface {

	/**
	 * Constructor.
	 *
	 * @param object $booking Booking row.
	 */
	public function __construct( private object $booking ) {}

	/**
	 * Checked by the caller.
	 *
	 * @return void
	 */
	public function authorize(): void {
	}

	/**
	 * File name.
	 *
	 * @return string
	 */
	public function filename(): string {
		return FilenameHelper::get_export_filename( 'attendance-' . (int) ( $this->booking->id ?? 0 ) );
	}

	/**
	 * Header row.
	 *
	 * @return array<int, string>
	 */
	public function header(): array {
		return array_merge(
			array( __( 'Name', 'ffcertificate' ) ),
			AudienceAttendanceRule::counted_days( $this->booking ),
			array( __( 'Days present', 'ffcertificate' ), __( 'Attendance rule met', 'ffcertificate' ) )
		);
	}

	/**
	 * Data rows.
	 *
	 * @return iterable<array<int, string>>
	 */
	public function rows(): iterable {
		$days   = AudienceAttendanceRule::counted_days( $this->booking );
		$roster = AudienceAttendanceRoster::for_day( $this->booking, $days[0] ?? '' );

		foreach ( $roster['rows'] as $row ) {
			$cells = array( $row['name'] );
			foreach ( $days as $day ) {
				$cells[] = in_array( $day, $row['days'], true ) ? __( 'Present', 'ffcertificate' ) : '';
			}
			$cells[] = (string) count( $row['days'] );
			$cells[] = $row['met'] ? __( 'Yes', 'ffcertificate' ) : __( 'No', 'ffcertificate' );

			yield $cells;
		}
	}
}

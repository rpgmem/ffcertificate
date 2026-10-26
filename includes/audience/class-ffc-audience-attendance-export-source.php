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

use FreeFormCertificate\Core\DateFormatter;
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
 * When the booking has a post-event form, or anyone was invited to one, two
 * columns follow (#2026): when the last invitation went out, and what came
 * of it -- the same readings as the screen's Invitations table.
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
	 * Whether the post-event columns are part of this file. Decided once, so
	 * the header and every row agree.
	 *
	 * @return bool
	 */
	private function with_post_event(): bool {
		return AudiencePostEventForm::is_form( AudiencePostEventForm::settings( $this->booking )['form_id'] )
			|| array() !== AudienceFormInviteReader::for_booking( (int) ( $this->booking->id ?? 0 ) );
	}

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
		$header = array_merge(
			array( __( 'Name', 'ffcertificate' ) ),
			AudienceAttendanceRule::counted_days( $this->booking ),
			array( __( 'Days present', 'ffcertificate' ), __( 'Attendance rule met', 'ffcertificate' ) )
		);
		if ( $this->with_post_event() ) {
			$header[] = __( 'Invited', 'ffcertificate' );
			$header[] = __( 'Post-event form', 'ffcertificate' );
		}

		return $header;
	}

	/**
	 * Data rows.
	 *
	 * @return iterable<array<int, string>>
	 */
	public function rows(): iterable {
		$days       = AudienceAttendanceRule::counted_days( $this->booking );
		$roster     = AudienceAttendanceRoster::for_day( $this->booking, $days[0] ?? '' );
		$post_event = $this->with_post_event();
		$booking_id = (int) ( $this->booking->id ?? 0 );
		$invited    = $post_event ? AudienceFormInviteReader::for_booking( $booking_id ) : array();
		$answers    = $post_event ? AudienceFormInviteReader::answers( $booking_id ) : array();

		foreach ( $roster['rows'] as $row ) {
			$cells = array( $row['name'] );
			foreach ( $days as $day ) {
				$cells[] = in_array( $day, $row['days'], true ) ? __( 'Present', 'ffcertificate' ) : '';
			}
			$cells[] = (string) count( $row['days'] );
			$cells[] = $row['met'] ? __( 'Yes', 'ffcertificate' ) : __( 'No', 'ffcertificate' );
			if ( $post_event ) {
				$invite  = $invited[ $row['user_id'] ] ?? null;
				$answer  = $answers[ $row['user_id'] ] ?? null;
				$cells[] = null === $invite ? '' : DateFormatter::format_datetime( $invite['sent_at'] );
				$cells[] = null === $answer ? '' : AudienceAdminAttendance::answer_label( $answer );
			}

			yield $cells;
		}
	}
}

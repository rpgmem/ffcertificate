<?php
/**
 * The .ics behind a "My Calls" card's "Add to calendar" button.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.41.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

use FreeFormCertificate\Scheduling\IcsGenerator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `admin-post.php?action=ffc_recruitment_call_ics&call=<id>` (#1889).
 *
 * Logged-in only — there is no `admin_post_nopriv_` variant — and gated
 * twice: a nonce bound to the call id and the session, and ownership, read
 * from the call up to the candidate's linked WordPress account and compared
 * with {@see get_current_user_id()}, never with anything the request names.
 * The one other reader is a holder of `ffc_view_as_user`, who sees the same
 * card while viewing the dashboard as its owner.
 *
 * The file is built by the same `IcsGenerator` the scheduling invitations
 * use, with METHOD:PUBLISH: it is an event to save, not an invitation to
 * answer. The presentation is a wall-clock date and time (Category B), so
 * it is written as floating local time, and it lasts one hour, since a call
 * records when to show up and not how long it takes.
 *
 * @phpstan-import-type CallRow from RecruitmentCallReader
 * @phpstan-import-type ClassificationRow from RecruitmentClassificationRepository
 */
final class RecruitmentCallIcs {

	/** The `admin-post.php` action. */
	public const ACTION = 'ffc_recruitment_call_ics';

	/**
	 * Register the handler.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * The nonce action of one call.
	 *
	 * @param int $call_id Call id.
	 * @return string
	 */
	public static function nonce_action( int $call_id ): string {
		return self::ACTION . '_' . $call_id;
	}

	/**
	 * The download link of one call, with its nonce.
	 *
	 * @param int $call_id Call id.
	 * @return string
	 */
	public static function url( int $call_id ): string {
		return add_query_arg(
			array(
				'action'   => self::ACTION,
				'call'     => $call_id,
				'_wpnonce' => wp_create_nonce( self::nonce_action( $call_id ) ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Send the file, or refuse: 403 for a missing or wrong nonce, 404 for a
	 * call that is not the user's, is cancelled, or does not exist — one
	 * answer for all three, so the response does not say which ids exist.
	 *
	 * @return void
	 */
	public static function handle(): void {
		$call_id = absint( \FreeFormCertificate\Core\RequestInput::get_get_string( 'call' ) );
		$nonce   = \FreeFormCertificate\Core\RequestInput::get_get_string( '_wpnonce' );

		if ( $call_id <= 0 || ! wp_verify_nonce( $nonce, self::nonce_action( $call_id ) ) ) {
			wp_die( esc_html__( 'This calendar link is not valid.', 'ffcertificate' ), '', array( 'response' => 403 ) );
		}

		$ics = self::build(
			$call_id,
			(int) get_current_user_id(),
			\FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_view_as_user' )
		);
		if ( null === $ics ) {
			wp_die( esc_html__( 'This call is not available.', 'ffcertificate' ), '', array( 'response' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="call-' . $call_id . '.ics"' );
		echo $ics; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- an iCalendar file, not HTML; every text value is escaped by IcsGenerator.
		exit;
	}

	/**
	 * The iCalendar body of a call the user may read, or null.
	 *
	 * @param int  $call_id  Call id.
	 * @param int  $user_id  The requesting user.
	 * @param bool $any_user Whether the user may read any candidate's call
	 *                       (a `ffc_view_as_user` holder).
	 * @return string|null
	 */
	public static function build( int $call_id, int $user_id, bool $any_user ): ?string {
		if ( $call_id <= 0 || $user_id <= 0 ) {
			return null;
		}

		$call = RecruitmentCallReader::get_by_id( $call_id );
		if ( null === $call || null !== $call->cancelled_at ) {
			return null;
		}

		$cls = RecruitmentClassificationRepository::get_by_id( (int) $call->classification_id );
		if ( null === $cls ) {
			return null;
		}

		$candidate = RecruitmentCandidateReader::get_by_id( (int) $cls->candidate_id );
		if ( null === $candidate ) {
			return null;
		}
		if ( ! $any_user && (int) $candidate->user_id !== $user_id ) {
			return null;
		}

		return self::content( $call, $cls );
	}

	/**
	 * The iCalendar body of an active call, with no owner check.
	 *
	 * Shared by the download, which checks the owner first, and the call
	 * e-mail, which attaches it for the candidate it is addressed to (#2245).
	 *
	 * @param object $call Call row.
	 * @param object $cls  Its classification row.
	 * @phpstan-param CallRow $call
	 * @phpstan-param ClassificationRow $cls
	 * @return string|null Null for a cancelled call, a draft notice or an unreadable date.
	 */
	public static function content( object $call, object $cls ): ?string {
		if ( null !== $call->cancelled_at ) {
			return null;
		}
		$call_id = (int) $call->id;

		$notice = RecruitmentNoticeReader::get_by_id( (int) $cls->notice_id );
		if ( null === $notice || 'draft' === $notice->status ) {
			return null;
		}

		$adjutancy = RecruitmentAdjutancyReader::get_by_id( (int) $cls->adjutancy_id );
		$subject   = null === $adjutancy ? '' : (string) $adjutancy->name;

		$start = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) $call->date_to_assume . ' ' . self::full_time( (string) $call->time_to_assume ) );
		if ( false === $start ) {
			return null;
		}
		$end = $start->modify( '+1 hour' );
		if ( $end->format( 'Y-m-d' ) !== $start->format( 'Y-m-d' ) ) {
			// The generator writes one date for both ends: stay on the day.
			$end = $start->setTime( 23, 59, 59 );
		}

		$summary = '' === $subject
			/* translators: %s: notice code */
			? sprintf( __( 'Presentation: %s', 'ffcertificate' ), (string) $notice->code )
			/* translators: 1: notice code, 2: subject */
			: sprintf( __( 'Presentation: %1$s — %2$s', 'ffcertificate' ), (string) $notice->code, $subject );

		$description = (string) $notice->name;
		$notes       = null === $call->notes ? '' : trim( (string) $call->notes );
		if ( '' !== $notes ) {
			$description .= "\n" . $notes;
		}

		return IcsGenerator::generate(
			array(
				// Stable per call: importing it again updates the event.
				'uid'         => 'ffc-recruitment-call-' . $call_id,
				'summary'     => $summary,
				'description' => $description,
				'date'        => $start->format( 'Y-m-d' ),
				'start_time'  => $start->format( 'H:i:s' ),
				'end_time'    => $end->format( 'H:i:s' ),
			),
			'PUBLISH'
		);
	}

	/**
	 * A TIME value with its seconds, which `H:i` input may omit.
	 *
	 * @param string $time `H:i` or `H:i:s`.
	 * @return string
	 */
	private static function full_time( string $time ): string {
		return 1 === preg_match( '/^\d{2}:\d{2}$/', $time ) ? $time . ':00' : $time;
	}
}

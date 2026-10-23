<?php
/**
 * Audience Attendance AJAX
 *
 * The live state of a booking's check-in (#1947).
 *
 * @package FreeFormCertificate\Audience
 * @since 6.42.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\Capabilities;
use FreeFormCertificate\Core\DateFormatter;
use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One read-only action that both the attendance screen and the projected QR
 * poll: who is present now, whether check-in is open and, for the
 * projection, the code of the current rotation.
 *
 * The QR is drawn on the server so the signing key never leaves it; the
 * browser only swaps one SVG for the next at the boundary the response
 * names.
 *
 * @since 6.42.0
 */
class AudienceAttendanceAjax {

	/**
	 * AJAX action.
	 *
	 * @var string
	 */
	public const ACTION = 'ffc_audience_attendance_state';

	/**
	 * Nonce action.
	 *
	 * @var string
	 */
	public const NONCE = 'ffc_audience_attendance_state';

	/**
	 * Register the action. Logged-in only: there is no `nopriv` half.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Answer a poll.
	 *
	 * @return void
	 */
	public static function handle(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! Capabilities::current_user_can_admin_or( AudienceAdminAttendance::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to run check-in.', 'ffcertificate' ) ), 403 );
		}

		$booking = AudienceBookingReader::get_by_id( RequestInput::get_post_int( 'booking_id' ) );
		$date    = RequestInput::get_post_string( 'date' );
		if ( null === $booking || ! in_array( $date, AudienceAttendanceRule::counted_days( $booking ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Booking not found.', 'ffcertificate' ) ), 404 );
		}

		wp_send_json_success( self::state( $booking, $date, RequestInput::get_post_bool( 'qr' ), time() ) );
	}

	/**
	 * The state a poll answers.
	 *
	 * @param object $booking Booking row.
	 * @param string $date    Day (Y-m-d).
	 * @param bool   $with_qr Whether to draw the current code.
	 * @param int    $now     Unix time.
	 * @return array<string, mixed>
	 */
	public static function state( object $booking, string $date, bool $with_qr, int $now ): array {
		$roster = AudienceAttendanceRoster::for_day( $booking, $date );
		$window = AudienceCheckinWindowReader::open_for( (int) ( $booking->id ?? 0 ), $date, $now );

		$present = array();
		foreach ( $roster['rows'] as $row ) {
			if ( null !== $row['present_at'] ) {
				$present[] = array(
					'user_id' => $row['user_id'],
					'time'    => DateFormatter::format_time( $row['present_at'] ),
					'method'  => (string) $row['method'],
					'days'    => count( $row['days'] ),
					'met'     => $row['met'],
				);
			}
		}

		$state = array(
			'present'      => $roster['present'],
			'total'        => $roster['total'],
			'rows'         => $present,
			'open'         => null !== $window,
			'closes_label' => null === $window ? '' : DateFormatter::format_time( $window['closes_at'] ),
		);

		if ( $with_qr && null !== $window ) {
			$state['qr']                = \FreeFormCertificate\Generators\QrSvgRenderer::render(
				AudienceCheckinCode::url( $window, $now ),
				\FreeFormCertificate\Generators\QrDesign::plain(),
				'M',
				4
			);
			$state['seconds_remaining'] = AudienceCheckinCode::seconds_remaining( $window['rotation_seconds'], $now );
			$state['rotation']          = $window['rotation_seconds'];
		}

		return $state;
	}
}

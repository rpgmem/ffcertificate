<?php
/**
 * AudienceBookingSeries
 *
 * Cancelling a weekly series from one of its dates on (#2243). Shared by
 * the participant's cancel request and the bookings list, so both take the
 * same dates and announce them the same way: one e-mail for the series.
 *
 * @package FreeFormCertificate\Audience
 * @since   6.43.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The "this date and the following ones" half of a series cancel.
 *
 * @phpstan-import-type BookingRow from AudienceBookingReader
 */
class AudienceBookingSeries {

	/**
	 * The cancel scope that takes the date and every later one of its series.
	 */
	public const SCOPE_FOLLOWING = 'following';

	/**
	 * Cancel a booking's date and every later active date of its series.
	 *
	 * Each date goes through the same conditional cancel as a single
	 * booking, so a date another request cancelled meanwhile is skipped.
	 * The cancellation hook fires once per date for other listeners, with a
	 * context naming the dates and the first one cancelled, which is the
	 * one that sends the series e-mail.
	 *
	 * @param object $booking The booking the request named.
	 * @phpstan-param BookingRow $booking
	 * @param string $reason  The cancellation reason.
	 * @return list<int> The ids this call cancelled, first date first; empty
	 *                   for a booking outside a series.
	 */
	public static function cancel_following( object $booking, string $reason ): array {
		$series_id = (int) ( $booking->series_id ?? 0 );
		if ( $series_id <= 0 ) {
			return array();
		}

		$from      = (string) $booking->booking_date;
		$cancelled = array();
		$dates     = array();
		foreach ( AudienceBookingReader::get_series( $series_id ) as $occurrence ) {
			if ( (string) $occurrence->booking_date < $from ) {
				continue;
			}
			if ( AudienceBookingWriter::cancel( (int) $occurrence->id, $reason ) ) {
				$cancelled[] = (int) $occurrence->id;
				$dates[]     = (string) $occurrence->booking_date;
			}
		}

		if ( array() === $cancelled ) {
			return array();
		}

		$context = array(
			'series_lead'  => $cancelled[0],
			'series_ids'   => $cancelled,
			'series_dates' => $dates,
		);
		foreach ( $cancelled as $booking_id ) {
			/** This action is documented in includes/audience/class-ffc-audience-rest-controller.php */
			do_action( 'ffcertificate_audience_booking_cancelled', $booking_id, $reason, $context );
		}

		return $cancelled;
	}
}

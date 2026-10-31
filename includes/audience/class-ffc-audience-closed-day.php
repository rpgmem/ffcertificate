<?php
/**
 * AudienceClosedDay
 *
 * Closing a day that already holds audience bookings (#2243): the bookings
 * on the date, the confirmation that lists them, and their cancellation.
 * Shared by a calendar's Closed dates tab and the global holidays on the
 * Scheduling settings, so both ask the same question the same way.
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
 * The bookings a closed day takes away, asked about before it is saved.
 *
 * @phpstan-import-type BookingRow from AudienceBookingReader
 */
class AudienceClosedDay {

	/**
	 * The POST field that confirms the cancellation.
	 */
	public const CONFIRM_FIELD = 'ffc_confirm_close_day';

	/**
	 * The closure waiting for confirmation in this request, if any.
	 *
	 * @var array{date: string, kind: string, description: string, bookings: list<BookingRow>, appointments: array<int, array<string, mixed>>, fields: array<string, string>}|null
	 */
	private static ?array $pending = null;

	/**
	 * The active bookings that occupy a date.
	 *
	 * @param string   $date        Date (Y-m-d).
	 * @param int|null $schedule_id A calendar, or null for every calendar.
	 * @return list<BookingRow>
	 */
	public static function bookings_on( string $date, ?int $schedule_id = null ): array {
		if ( ! \FreeFormCertificate\Core\DateFormatter::is_valid_date( $date ) ) {
			return array();
		}

		$args = array(
			'booking_date' => $date,
			'status'       => 'active',
		);
		if ( null !== $schedule_id ) {
			$args['schedule_id'] = $schedule_id;
		}

		return AudienceBookingReader::get_all( $args );
	}

	/**
	 * Whether the request confirmed cancelling the day's bookings.
	 *
	 * @return bool
	 */
	public static function confirmed(): bool {
		return '1' === \FreeFormCertificate\Core\RequestInput::get_post_string( self::CONFIRM_FIELD );
	}

	/**
	 * Hold a closure for the confirmation the screen renders in this request.
	 *
	 * @param string                           $date        Date (Y-m-d).
	 * @param string                           $kind        Closed-date kind.
	 * @param string                           $description Description, may be empty.
	 * @param array<int, object>               $bookings    The bookings on that date.
	 * @param array<string, string>            $fields      The form fields to post again, by name.
	 * @param array<int, array<string, mixed>> $appointments The personal-calendar appointments on that date, for a global holiday (#2244).
	 * @phpstan-param list<BookingRow> $bookings
	 * @return void
	 */
	public static function hold( string $date, string $kind, string $description, array $bookings, array $fields, array $appointments = array() ): void {
		self::$pending = array(
			'date'         => $date,
			'kind'         => $kind,
			'description'  => $description,
			'bookings'     => $bookings,
			'appointments' => $appointments,
			'fields'       => $fields,
		);
	}

	/**
	 * The closure waiting for confirmation, if any.
	 *
	 * @return array{date: string, kind: string, description: string, bookings: list<BookingRow>, appointments: array<int, array<string, mixed>>, fields: array<string, string>}|null
	 */
	public static function pending(): ?array {
		return self::$pending;
	}

	/**
	 * Forget the held closure.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$pending = null;
	}

	/**
	 * The reason each cancelled booking carries.
	 *
	 * @param string $date        Date (Y-m-d).
	 * @param string $description The closed date's description, may be empty.
	 * @return string
	 */
	public static function reason( string $date, string $description ): string {
		$label = '' !== trim( $description ) ? trim( $description ) : \FreeFormCertificate\Core\DateFormatter::format_wallclock_date( $date );

		/* translators: %s: the closed date's description, or the date when it has none */
		return sprintf( __( 'Closed day: %s', 'ffcertificate' ), $label );
	}

	/**
	 * Cancel the bookings of a day that was closed, each with the closure as
	 * its reason. The cancellation hook sends each booking's participants the
	 * usual cancellation e-mail; a booking another request already cancelled
	 * is skipped and notifies nobody.
	 *
	 * @param array<int, object> $bookings    The bookings on the date.
	 * @param string             $date        Date (Y-m-d).
	 * @param string             $description The closed date's description.
	 * @phpstan-param list<BookingRow> $bookings
	 * @return int How many bookings were cancelled.
	 */
	public static function cancel_bookings( array $bookings, string $date, string $description ): int {
		$reason    = self::reason( $date, $description );
		$cancelled = 0;

		foreach ( $bookings as $booking ) {
			$booking_id = (int) $booking->id;
			if ( ! AudienceBookingWriter::cancel( $booking_id, $reason ) ) {
				continue;
			}
			++$cancelled;

			/** This action is documented in includes/audience/class-ffc-audience-rest-controller.php */
			do_action( 'ffcertificate_audience_booking_cancelled', $booking_id, $reason );
		}

		return $cancelled;
	}

	/**
	 * Print the held confirmation, if any: the bookings the closure cancels
	 * and the button that confirms it.
	 *
	 * @return void
	 */
	public static function render_pending(): void {
		$pending = self::$pending;
		if ( null === $pending ) {
			return;
		}

		include FFC_PLUGIN_DIR . 'templates/admin/audience/closed-day-confirm.php';
	}
}

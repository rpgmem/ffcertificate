<?php
/**
 * Audience Booking Repository
 *
 * Handles database operations for audience bookings.
 * Manages the booking records and N:N relationships with audiences and users.
 *
 * Since the #563 backlog read/write split (A6) this class is a thin façade:
 * reads live in {@see AudienceBookingReader}, writes in {@see AudienceBookingWriter}.
 * It is kept as the public entry point so existing call sites and the
 * public constants / typed shape below need no change.
 *
 * Tech-debt (#563 B3): migrate call sites to depend on AudienceBookingReader /
 * AudienceBookingWriter directly, then retire this delegating façade.
 *
 * @package FreeFormCertificate\Audience
 * @since 4.5.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public façade over {@see AudienceBookingReader} + {@see AudienceBookingWriter}.
 *
 * @since 4.5.0
 *
 * @phpstan-type BookingRow \stdClass&object{id: numeric-string, environment_id: numeric-string, booking_date: string, start_time: string, end_time: string, booking_type: string, description: string, status: string, created_by: numeric-string, created_at: string, cancelled_by: numeric-string|null, cancelled_at: string|null, cancellation_reason: string|null, is_all_day?: numeric-string, environment_name?: string|null, schedule_id?: numeric-string|null, audience_name?: string, audience_id?: numeric-string, title?: string, audiences?: array<int, mixed>, users?: array<int, int>}
 */
class AudienceBookingRepository {

	/**
	 * Get bookings table name
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		return AudienceBookingReader::get_table_name();
	}

	/**
	 * Get booking audiences table name
	 *
	 * @return string
	 */
	public static function get_booking_audiences_table_name(): string {
		return AudienceBookingReader::get_booking_audiences_table_name();
	}

	/**
	 * Get booking users table name
	 *
	 * @return string
	 */
	public static function get_booking_users_table_name(): string {
		return AudienceBookingReader::get_booking_users_table_name();
	}

	// ─────────────────────────────────────────────.
	// Reads — delegate to AudienceBookingReader.
	// ─────────────────────────────────────────────.

	/**
	 * Get all bookings
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return list<BookingRow>
	 */
	public static function get_all( array $args = array() ): array {
		return AudienceBookingReader::get_all( $args );
	}

	/**
	 * Get booking by ID
	 *
	 * @param int $id Booking ID.
	 * @return BookingRow|null
	 */
	public static function get_by_id( int $id ): ?object {
		return AudienceBookingReader::get_by_id( $id );
	}

	/**
	 * Get bookings for a specific date and environment
	 *
	 * @param int         $environment_id Environment ID.
	 * @param string      $date Date (Y-m-d).
	 * @param string|null $status Optional status filter.
	 * @return list<BookingRow>
	 */
	public static function get_by_date( int $environment_id, string $date, ?string $status = null ): array {
		return AudienceBookingReader::get_by_date( $environment_id, $date, $status );
	}

	/**
	 * Get bookings for a date range
	 *
	 * @param int         $environment_id Environment ID.
	 * @param string      $start_date Start date (Y-m-d).
	 * @param string      $end_date End date (Y-m-d).
	 * @param string|null $status Optional status filter.
	 * @return list<BookingRow>
	 */
	public static function get_by_date_range( int $environment_id, string $start_date, string $end_date, ?string $status = null ): array {
		return AudienceBookingReader::get_by_date_range( $environment_id, $start_date, $end_date, $status );
	}

	/**
	 * Get bookings created by a user
	 *
	 * @param int                  $user_id User ID.
	 * @param array<string, mixed> $args Additional query arguments.
	 * @return list<BookingRow>
	 */
	public static function get_by_creator( int $user_id, array $args = array() ): array {
		return AudienceBookingReader::get_by_creator( $user_id, $args );
	}

	/**
	 * Get bookings for a user (as participant, not creator)
	 *
	 * @param int                  $user_id User ID.
	 * @param array<string, mixed> $args Additional query arguments.
	 * @return list<BookingRow>
	 */
	public static function get_by_participant( int $user_id, array $args = array() ): array {
		return AudienceBookingReader::get_by_participant( $user_id, $args );
	}

	/**
	 * Get audiences for a booking
	 *
	 * @param int $booking_id Booking ID.
	 * @return list<\stdClass>
	 */
	public static function get_booking_audiences( int $booking_id ): array {
		return AudienceBookingReader::get_booking_audiences( $booking_id );
	}

	/**
	 * Get users for a booking
	 *
	 * @param int $booking_id Booking ID.
	 * @return array<int> User IDs
	 */
	public static function get_booking_users( int $booking_id ): array {
		return AudienceBookingReader::get_booking_users( $booking_id );
	}

	/**
	 * Get all affected users for a booking (from audiences + individual users)
	 *
	 * @param int $booking_id Booking ID.
	 * @return array<int> Unique user IDs
	 */
	public static function get_all_affected_users( int $booking_id ): array {
		return AudienceBookingReader::get_all_affected_users( $booking_id );
	}

	/**
	 * Check for time conflicts
	 *
	 * @param int      $environment_id Environment ID.
	 * @param string   $date Date (Y-m-d).
	 * @param string   $start_time Start time (H:i).
	 * @param string   $end_time End time (H:i).
	 * @param int|null $exclude_booking_id Booking ID to exclude (for updates).
	 * @return list<BookingRow> Conflicting bookings
	 */
	public static function get_conflicts( int $environment_id, string $date, string $start_time, string $end_time, ?int $exclude_booking_id = null ): array {
		return AudienceBookingReader::get_conflicts( $environment_id, $date, $start_time, $end_time, $exclude_booking_id );
	}

	/**
	 * Check for user conflicts across environments
	 *
	 * @param string     $date Date (Y-m-d).
	 * @param string     $start_time Start time (H:i).
	 * @param string     $end_time End time (H:i).
	 * @param array<int> $audience_ids Audience IDs to check.
	 * @param array<int> $user_ids Individual user IDs to check.
	 * @param int|null   $exclude_booking_id Booking ID to exclude.
	 * @param int|null   $scope_schedule_id When set, restrict to this schedule only.
	 * @return array{bookings: list<BookingRow>, affected_users: array<int>}
	 */
	public static function get_user_conflicts(
		string $date,
		string $start_time,
		string $end_time,
		array $audience_ids,
		array $user_ids,
		?int $exclude_booking_id = null,
		?int $scope_schedule_id = null
	): array {
		return AudienceBookingReader::get_user_conflicts( $date, $start_time, $end_time, $audience_ids, $user_ids, $exclude_booking_id, $scope_schedule_id );
	}

	/**
	 * Find bookings on the same date that include any of the given audience groups
	 *
	 * @param string     $date Date (Y-m-d).
	 * @param array<int> $audience_ids Audience IDs to check.
	 * @param int|null   $exclude_booking_id Booking ID to exclude (for updates).
	 * @param int|null   $scope_schedule_id When set, restrict to this schedule only.
	 * @return list<BookingRow> Bookings with matched audience info
	 */
	public static function get_audience_same_day_bookings(
		string $date,
		array $audience_ids,
		?int $exclude_booking_id = null,
		?int $scope_schedule_id = null
	): array {
		return AudienceBookingReader::get_audience_same_day_bookings( $date, $audience_ids, $exclude_booking_id, $scope_schedule_id );
	}

	/**
	 * Count bookings
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return int
	 */
	public static function count( array $args = array() ): int {
		return AudienceBookingReader::count( $args );
	}

	// ─────────────────────────────────────────────.
	// Writes — delegate to AudienceBookingWriter.
	// ─────────────────────────────────────────────.

	/**
	 * Create a booking
	 *
	 * @param array<string, mixed> $data Booking data.
	 * @return int|false Booking ID or false on failure
	 */
	public static function create( array $data ) {
		return AudienceBookingWriter::create( $data );
	}

	/**
	 * Update a booking
	 *
	 * @param int                  $id Booking ID.
	 * @param array<string, mixed> $data Update data.
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		return AudienceBookingWriter::update( $id, $data );
	}

	/**
	 * Cancel a booking
	 *
	 * @param int    $id Booking ID.
	 * @param string $reason Cancellation reason (required).
	 * @return bool
	 */
	public static function cancel( int $id, string $reason ): bool {
		return AudienceBookingWriter::cancel( $id, $reason );
	}

	/**
	 * Delete a booking
	 *
	 * @param int $id Booking ID.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		return AudienceBookingWriter::delete( $id );
	}

	/**
	 * Add an audience to a booking
	 *
	 * @param int $booking_id Booking ID.
	 * @param int $audience_id Audience ID.
	 * @return bool
	 */
	public static function add_booking_audience( int $booking_id, int $audience_id ): bool {
		return AudienceBookingWriter::add_booking_audience( $booking_id, $audience_id );
	}

	/**
	 * Remove an audience from a booking
	 *
	 * @param int $booking_id Booking ID.
	 * @param int $audience_id Audience ID.
	 * @return bool
	 */
	public static function remove_booking_audience( int $booking_id, int $audience_id ): bool {
		return AudienceBookingWriter::remove_booking_audience( $booking_id, $audience_id );
	}

	/**
	 * Set audiences for a booking (replace all)
	 *
	 * @param int        $booking_id Booking ID.
	 * @param array<int> $audience_ids Audience IDs.
	 * @return bool
	 */
	public static function set_booking_audiences( int $booking_id, array $audience_ids ): bool {
		return AudienceBookingWriter::set_booking_audiences( $booking_id, $audience_ids );
	}

	/**
	 * Add a user to a booking
	 *
	 * @param int $booking_id Booking ID.
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function add_booking_user( int $booking_id, int $user_id ): bool {
		return AudienceBookingWriter::add_booking_user( $booking_id, $user_id );
	}

	/**
	 * Remove a user from a booking
	 *
	 * @param int $booking_id Booking ID.
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function remove_booking_user( int $booking_id, int $user_id ): bool {
		return AudienceBookingWriter::remove_booking_user( $booking_id, $user_id );
	}

	/**
	 * Set users for a booking (replace all)
	 *
	 * @param int        $booking_id Booking ID.
	 * @param array<int> $user_ids User IDs.
	 * @return bool
	 */
	public static function set_booking_users( int $booking_id, array $user_ids ): bool {
		return AudienceBookingWriter::set_booking_users( $booking_id, $user_ids );
	}
}

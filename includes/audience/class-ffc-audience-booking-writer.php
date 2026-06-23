<?php
/**
 * Audience Booking Writer
 *
 * Write-side of the audience-booking repository split (#563 backlog, A6). Holds
 * every INSERT / UPDATE / DELETE, the N:N junction mutators, and the
 * transactional `FOR UPDATE` conflict check that guards {@see self::create()}.
 * Reads live in {@see AudienceBookingReader}. Callers depend on the reader
 * (reads) and this writer (writes) directly; the delegating façade was retired
 * in #563 B3-A.
 *
 * @package FreeFormCertificate\Audience
 * @since 6.11.3
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
/**
 * Write operations for audience booking records.
 *
 * @since 6.11.3
 */
class AudienceBookingWriter {
	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Cache group for this repository.
	 *
	 * Must match {@see AudienceBookingReader::cache_group()} so writes invalidate
	 * the entries reads populate.
	 *
	 * @return string
	 */
	protected static function cache_group(): string {
		return 'ffc_audience_bookings';
	}

	/**
	 * Get bookings table name
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		return self::db()->prefix . 'ffc_audience_bookings';
	}

	/**
	 * Get booking audiences table name
	 *
	 * @return string
	 */
	public static function get_booking_audiences_table_name(): string {
		return self::db()->prefix . 'ffc_audience_booking_audiences';
	}

	/**
	 * Get booking users table name
	 *
	 * @return string
	 */
	public static function get_booking_users_table_name(): string {
		return self::db()->prefix . 'ffc_audience_booking_users';
	}

	/**
	 * Create a booking
	 *
	 * @param array<string, mixed> $data Booking data.
	 * @return int|false Booking ID or false on failure
	 */
	public static function create( array $data ) {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$defaults = array(
			'environment_id' => 0,
			'booking_date'   => '',
			'start_time'     => '',
			'end_time'       => '',
			'is_all_day'     => 0,
			'booking_type'   => 'audience',
			'description'    => '',
			'status'         => 'active',
			'created_by'     => get_current_user_id(),
		);
		$data     = wp_parse_args( $data, $defaults );

		// Validate required fields.
		if ( ! $data['environment_id'] || ! $data['booking_date'] || ! $data['start_time'] || ! $data['end_time'] || ! $data['description'] ) {
			return false;
		}

		// Race protection: conflict-check + insert run inside a single
		// transaction so two concurrent requests for the same slot can't
		// both pass the check and both insert. Mirrors the pattern at
		// `SelfSchedulingAppointmentHandler::create_or_update():140`.
		// `idx_env_date_status (environment_id, booking_date, status)`
		// (declared on the table) lets InnoDB take row + gap locks on the
		// matched range so the second transaction blocks until the first
		// commits. The REST layer still calls `get_conflicts()` first for
		// a friendly error, but this is the authoritative check.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'START TRANSACTION' );

		$conflicts = self::get_conflicts_for_update(
			(int) $data['environment_id'],
			(string) $data['booking_date'],
			(string) $data['start_time'],
			(string) $data['end_time']
		);
		if ( ! empty( $conflicts ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'ROLLBACK' );
			return false;
		}

		$result = $wpdb->insert(
			$table,
			array(
				'environment_id' => $data['environment_id'],
				'booking_date'   => $data['booking_date'],
				'start_time'     => $data['start_time'],
				'end_time'       => $data['end_time'],
				'is_all_day'     => $data['is_all_day'] ? 1 : 0,
				'booking_type'   => $data['booking_type'],
				'description'    => $data['description'],
				'status'         => $data['status'],
				'created_by'     => $data['created_by'],
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d' )
		);

		if ( ! $result ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'ROLLBACK' );
			return false;
		}

		$booking_id = (int) $wpdb->insert_id;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'COMMIT' );

		// Add audience associations if provided.
		if ( isset( $data['audience_ids'] ) && is_array( $data['audience_ids'] ) ) {
			foreach ( $data['audience_ids'] as $audience_id ) {
				self::add_booking_audience( $booking_id, (int) $audience_id );
			}
		}

		// Add user associations if provided.
		if ( isset( $data['user_ids'] ) && is_array( $data['user_ids'] ) ) {
			foreach ( $data['user_ids'] as $user_id ) {
				self::add_booking_user( $booking_id, (int) $user_id );
			}
		}

		return $booking_id;
	}

	/**
	 * Conflict-check used by {@see self::create()} inside a transaction.
	 * Identical predicate to {@see AudienceBookingReader::get_conflicts()} but
	 * with the `FOR UPDATE` row + gap lock that prevents concurrent inserts in
	 * the same `(environment_id, booking_date)` range from completing
	 * until the holding transaction commits. Must run inside a
	 * `START TRANSACTION ... COMMIT` block.
	 *
	 * @since 6.5.0
	 * @param int    $environment_id Environment ID.
	 * @param string $date           Booking date (Y-m-d).
	 * @param string $start_time     Start time (H:i).
	 * @param string $end_time       End time (H:i).
	 * @return array<int, mixed>
	 */
	private static function get_conflicts_for_update( int $environment_id, string $date, string $start_time, string $end_time ): array {
		$wpdb  = self::db();
		$table = self::get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM %i
                WHERE environment_id = %d
                AND booking_date = %s
                AND status = 'active'
                AND (
                    (start_time < %s AND end_time > %s) OR
                    (start_time >= %s AND start_time < %s) OR
                    (end_time > %s AND end_time <= %s)
                )
                FOR UPDATE",
				$table,
				$environment_id,
				$date,
				$end_time,
				$start_time,
				$start_time,
				$end_time,
				$start_time,
				$end_time
			)
		);
		return is_array( $results ) ? $results : array();
	}

	/**
	 * Update a booking
	 *
	 * @param int                  $id Booking ID.
	 * @param array<string, mixed> $data Update data.
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		$wpdb  = self::db();
		$table = self::get_table_name();

		// Remove fields that shouldn't be updated.
		unset( $data['id'], $data['created_by'], $data['created_at'] );

		// Handle audience_ids separately.
		$audience_ids = null;
		if ( isset( $data['audience_ids'] ) ) {
			$audience_ids = $data['audience_ids'];
			unset( $data['audience_ids'] );
		}

		// Handle user_ids separately.
		$user_ids = null;
		if ( isset( $data['user_ids'] ) ) {
			$user_ids = $data['user_ids'];
			unset( $data['user_ids'] );
		}

		// Update main booking record.
		if ( ! empty( $data ) ) {
			$update_data = array();
			$format      = array();

			$field_formats = array(
				'environment_id'      => '%d',
				'booking_date'        => '%s',
				'start_time'          => '%s',
				'end_time'            => '%s',
				'booking_type'        => '%s',
				'description'         => '%s',
				'status'              => '%s',
				'cancelled_by'        => '%d',
				'cancelled_at'        => '%s',
				'cancellation_reason' => '%s',
			);

			foreach ( $data as $key => $value ) {
				if ( isset( $field_formats[ $key ] ) ) {
					$update_data[ $key ] = $value;
					$format[]            = $field_formats[ $key ];
				}
			}

			if ( ! empty( $update_data ) ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$table,
					$update_data,
					array( 'id' => $id ),
					$format,
					array( '%d' )
				);
			}
		}

		// Update audience associations.
		if ( null !== $audience_ids ) {
			self::set_booking_audiences( $id, $audience_ids );
		}

		// Update user associations.
		if ( null !== $user_ids ) {
			self::set_booking_users( $id, $user_ids );
		}

		static::cache_delete( "id_{$id}" );

		return true;
	}

	/**
	 * Cancel a booking
	 *
	 * @param int    $id Booking ID.
	 * @param string $reason Cancellation reason (required).
	 * @return bool
	 */
	public static function cancel( int $id, string $reason ): bool {
		if ( empty( $reason ) ) {
			return false;
		}

		$result = self::update(
			$id,
			array(
				'status'              => 'cancelled',
				'cancelled_by'        => get_current_user_id(),
				'cancelled_at'        => current_time( 'mysql' ),
				'cancellation_reason' => $reason,
			)
		);

		static::cache_delete( "id_{$id}" );

		return $result;
	}

	/**
	 * Delete a booking
	 *
	 * @param int $id Booking ID.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		$wpdb            = self::db();
		$table           = self::get_table_name();
		$audiences_table = self::get_booking_audiences_table_name();
		$users_table     = self::get_booking_users_table_name();

		// Delete associations first.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $audiences_table, array( 'booking_id' => $id ), array( '%d' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $users_table, array( 'booking_id' => $id ), array( '%d' ) );

		// Delete the booking.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		static::cache_delete( "id_{$id}" );

		return false !== $result;
	}

	/**
	 * Add an audience to a booking
	 *
	 * @param int $booking_id Booking ID.
	 * @param int $audience_id Audience ID.
	 * @return bool
	 */
	public static function add_booking_audience( int $booking_id, int $audience_id ): bool {
		$wpdb  = self::db();
		$table = self::get_booking_audiences_table_name();

		$result = $wpdb->insert(
			$table,
			array(
				'booking_id'  => $booking_id,
				'audience_id' => $audience_id,
			),
			array( '%d', '%d' )
		);

		return false !== $result;
	}

	/**
	 * Remove an audience from a booking
	 *
	 * @param int $booking_id Booking ID.
	 * @param int $audience_id Audience ID.
	 * @return bool
	 */
	public static function remove_booking_audience( int $booking_id, int $audience_id ): bool {
		$wpdb  = self::db();
		$table = self::get_booking_audiences_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$table,
			array(
				'booking_id'  => $booking_id,
				'audience_id' => $audience_id,
			),
			array( '%d', '%d' )
		);

		return false !== $result;
	}

	/**
	 * Set audiences for a booking (replace all)
	 *
	 * @param int        $booking_id Booking ID.
	 * @param array<int> $audience_ids Audience IDs.
	 * @return bool
	 */
	public static function set_booking_audiences( int $booking_id, array $audience_ids ): bool {
		$wpdb  = self::db();
		$table = self::get_booking_audiences_table_name();

		// Remove all existing.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $table, array( 'booking_id' => $booking_id ), array( '%d' ) );

		// Add new ones.
		foreach ( $audience_ids as $audience_id ) {
			self::add_booking_audience( $booking_id, (int) $audience_id );
		}

		return true;
	}

	/**
	 * Add a user to a booking
	 *
	 * @param int $booking_id Booking ID.
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function add_booking_user( int $booking_id, int $user_id ): bool {
		$wpdb  = self::db();
		$table = self::get_booking_users_table_name();

		$result = $wpdb->insert(
			$table,
			array(
				'booking_id' => $booking_id,
				'user_id'    => $user_id,
			),
			array( '%d', '%d' )
		);

		return false !== $result;
	}

	/**
	 * Remove a user from a booking
	 *
	 * @param int $booking_id Booking ID.
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function remove_booking_user( int $booking_id, int $user_id ): bool {
		$wpdb  = self::db();
		$table = self::get_booking_users_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$table,
			array(
				'booking_id' => $booking_id,
				'user_id'    => $user_id,
			),
			array( '%d', '%d' )
		);

		return false !== $result;
	}

	/**
	 * Set users for a booking (replace all)
	 *
	 * @param int        $booking_id Booking ID.
	 * @param array<int> $user_ids User IDs.
	 * @return bool
	 */
	public static function set_booking_users( int $booking_id, array $user_ids ): bool {
		$wpdb  = self::db();
		$table = self::get_booking_users_table_name();

		// Remove all existing.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $table, array( 'booking_id' => $booking_id ), array( '%d' ) );

		// Add new ones.
		foreach ( $user_ids as $user_id ) {
			self::add_booking_user( $booking_id, (int) $user_id );
		}

		return true;
	}
}

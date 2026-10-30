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
 * @since 6.12.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$day_sql} is the fixed predicate from AudienceBookingRules::occupies_day_clause(), with its values bound through the spread arguments.
// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The argument list is built by array_merge() and spread into prepare(), so the sniff cannot count its placeholders.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement in this class runs against one of the plugin's own ffc_* tables, which WordPress exposes no API for. Caching is decided per read at the repository layer, not per statement (#1042).
/**
 * Write operations for audience booking records.
 *
 * @since 6.12.0
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
			'end_date'       => '',
			'time_mode'      => 'daily',
			'skip_blocked'   => false,
			'override_ids'   => array(),
			'override_note'  => '',
		);
		$data     = wp_parse_args( $data, $defaults );

		// Validate required fields.
		if ( ! $data['environment_id'] || ! $data['booking_date'] || ! $data['start_time'] || ! $data['end_time'] || ! $data['description'] ) {
			return false;
		}

		// A range is a booking whose end date differs from its start. Its days
		// are planned once here; a single day is one occurrence and never skips.
		$end_date     = '' !== (string) $data['end_date'] ? (string) $data['end_date'] : (string) $data['booking_date'];
		$is_range     = $end_date !== (string) $data['booking_date'];
		$time_mode    = $is_range ? (string) $data['time_mode'] : 'daily';
		$skip_blocked = $is_range && ! empty( $data['skip_blocked'] );
		$plan         = AudienceBookingRules::occurrences(
			(int) $data['environment_id'],
			(string) $data['booking_date'],
			$end_date,
			(string) $data['start_time'],
			(string) $data['end_time'],
			$time_mode,
			$skip_blocked
		);

		// Race protection: conflict-check + insert run inside a single
		// transaction so two concurrent requests for the same slot can't
		// both pass the check and both insert. Mirrors the pattern at
		// `SelfSchedulingAppointmentHandler::create_or_update():140`.
		// `idx_env_date_status (environment_id, booking_date, status)`
		// (declared on the table) lets InnoDB take row + gap locks on the
		// matched range so the second transaction blocks until the first
		// commits. The REST layer still calls `get_conflicts()` first for
		// a friendly error, but this is the authoritative check.
		$wpdb->query( 'START TRANSACTION' );

		// Every day of the range is checked, and one clash refuses the whole
		// booking, except a clash with a booking the caller confirmed it
		// overrides: that one is cancelled below, in this same transaction,
		// so the slot is never held by two active bookings.
		$override_ids = is_array( $data['override_ids'] ) ? self::numeric_ids( $data['override_ids'] ) : array();
		foreach ( $plan['days'] as $day ) {
			$conflicts = self::get_conflicts_for_update(
				(int) $data['environment_id'],
				$day['date'],
				$day['start_time'],
				$day['end_time']
			);
			foreach ( $conflicts as $conflict ) {
				if ( ! in_array( (int) ( $conflict->id ?? 0 ), $override_ids, true ) ) {
					$wpdb->query( 'ROLLBACK' );
					return false;
				}
			}
		}

		foreach ( $override_ids as $override_id ) {
			if ( ! self::cancel( $override_id, (string) $data['override_note'] ) ) {
				$wpdb->query( 'ROLLBACK' );
				return false;
			}
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
				'end_date'       => $is_range ? $end_date : null,
				'time_mode'      => $is_range ? $time_mode : null,
				'skipped_dates'  => ! empty( $plan['skipped'] ) ? wp_json_encode( $plan['skipped'] ) : null,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( ! $result ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}

		$booking_id = (int) $wpdb->insert_id;

		// The participants are written in the same transaction (#2243): a
		// booking whose links failed held the slot with nobody on it, and
		// nothing reported it. `$data` is caller-supplied, so the ids are only
		// known to be mixed; a non-numeric entry is dropped, never cast to 0.
		$audience_ids = isset( $data['audience_ids'] ) && is_array( $data['audience_ids'] ) ? self::numeric_ids( $data['audience_ids'] ) : array();
		$user_ids     = isset( $data['user_ids'] ) && is_array( $data['user_ids'] ) ? self::numeric_ids( $data['user_ids'] ) : array();
		foreach ( $audience_ids as $audience_id ) {
			if ( ! self::add_booking_audience( $booking_id, $audience_id ) ) {
				$wpdb->query( 'ROLLBACK' );
				return false;
			}
		}
		foreach ( $user_ids as $user_id ) {
			if ( ! self::add_booking_user( $booking_id, $user_id ) ) {
				$wpdb->query( 'ROLLBACK' );
				return false;
			}
		}

		$wpdb->query( 'COMMIT' );

		return $booking_id;
	}

	/**
	 * Conflict-check used by {@see self::create()} inside a transaction.
	 * Same rows as {@see AudienceBookingReader::get_conflicts()}, read with
	 * the `FOR UPDATE` row + gap lock that keeps a concurrent insert in the
	 * same `(environment_id, booking_date)` range waiting until the holding
	 * transaction commits. Must run inside a `START TRANSACTION ... COMMIT`
	 * block.
	 *
	 * The query locks every active row that occupies the day; the hours are
	 * compared afterwards by {@see AudienceBookingRules::overlapping_on_day()},
	 * because a span row's stored times are not the hours it takes on a
	 * middle day.
	 *
	 * @since 6.5.0
	 * @param int    $environment_id Environment ID.
	 * @param string $date           Booking date (Y-m-d).
	 * @param string $start_time     Start time (H:i).
	 * @param string $end_time       End time (H:i).
	 * @return list<object>
	 */
	private static function get_conflicts_for_update( int $environment_id, string $date, string $start_time, string $end_time ): array {
		$wpdb  = self::db();
		$table = self::get_table_name();

		list( $day_sql, $day_args ) = AudienceBookingRules::occupies_day_clause( $date );
		$args                       = array_merge( array( $table, $environment_id ), $day_args );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				/**
				 * The SQL is a literal except for `{$day_sql}`, a fixed predicate from
				 * `AudienceBookingRules::occupies_day_clause()`. Its placeholders are bound
				 * through the spread arguments below.
				 *
				 * @phpstan-ignore argument.type
				 */
				"SELECT id, environment_id, booking_date, end_date, time_mode, skipped_dates, start_time, end_time FROM %i
                WHERE environment_id = %d
                AND {$day_sql}
                AND status = 'active'
                FOR UPDATE",
				...$args
			)
		);
		if ( ! is_array( $results ) ) {
			return array();
		}

		return AudienceBookingRules::overlapping_on_day( array_values( array_filter( $results, 'is_object' ) ), $date, $start_time, $end_time );
	}

	/**
	 * Update a booking
	 *
	 * @param int                  $id Booking ID.
	 * @param array<string, mixed> $data Update data.
	 * @return bool False when the database refused the booking row's write.
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

			// A failed write stops here, so a caller never reports a change
			// the database refused (#2183). Zero rows changed is not a failure.
			if ( ! empty( $update_data ) ) {
				$updated = $wpdb->update(
					$table,
					$update_data,
					array( 'id' => $id ),
					$format,
					array( '%d' )
				);
				if ( false === $updated ) {
					return false;
				}
			}
		}

		// Update audience associations.
		if ( is_array( $audience_ids ) ) {
			self::set_booking_audiences( $id, self::numeric_ids( $audience_ids ) );
		}

		// Update user associations.
		if ( is_array( $user_ids ) ) {
			self::set_booking_users( $id, self::numeric_ids( $user_ids ) );
		}

		static::cache_delete( "id_{$id}" );

		return true;
	}

	/**
	 * Store a booking's attendance rule (#1947).
	 *
	 * The rule is normalised by {@see AudienceAttendanceRule::normalize()}: a
	 * *single* rule stores NULL in both columns, which is also what a booking
	 * that never had a rule reads as.
	 *
	 * @param int    $id      Booking ID.
	 * @param string $mode    `single` or `total`.
	 * @param int    $percent Minimum share of the booking's days, for `total`.
	 * @return bool Whether the row was written.
	 */
	public static function set_attendance_rule( int $id, string $mode, int $percent ): bool {
		$rule = AudienceAttendanceRule::normalize( $mode, $percent );

		$written = self::db()->update(
			self::get_table_name(),
			array(
				'attendance_mode'        => AudienceAttendanceRule::TOTAL === $rule['mode'] ? AudienceAttendanceRule::TOTAL : null,
				'attendance_min_percent' => AudienceAttendanceRule::TOTAL === $rule['mode'] ? $rule['percent'] : null,
			),
			array( 'id' => $id ),
			array( '%s', '%d' ),
			array( '%d' )
		);

		static::cache_delete( "id_{$id}" );

		return false !== $written;
	}

	/**
	 * Store a booking's own post-event form settings (#1948).
	 *
	 * With `$custom` off the booking follows its calendar again, and its three
	 * columns are cleared so a stale choice cannot come back by surprise when
	 * the switch is turned on later. With it on, a form ID of 0 means the
	 * booking has no form even when its calendar does.
	 *
	 * @param int  $id             Booking ID.
	 * @param bool $custom         Whether the booking reads its own settings.
	 * @param int  $form_id        Form post ID, 0 for none.
	 * @param int  $window_days    Days the form stays open after the event.
	 * @param bool $attendees_only Whether only those present may answer.
	 * @return bool Whether the row was written.
	 */
	public static function set_post_event_form( int $id, bool $custom, int $form_id, int $window_days, bool $attendees_only ): bool {
		$written = self::db()->update(
			self::get_table_name(),
			array(
				'post_event_custom'         => $custom ? 1 : 0,
				'post_event_form_id'        => $custom ? max( 0, $form_id ) : null,
				'post_event_window_days'    => $custom ? AudiencePostEventForm::normalize_window( $window_days ) : null,
				'post_event_attendees_only' => $custom ? ( $attendees_only ? 1 : 0 ) : null,
			),
			array( 'id' => $id ),
			array( '%d', '%d', '%d', '%d' ),
			array( '%d' )
		);

		static::cache_delete( "id_{$id}" );

		return false !== $written;
	}

	/**
	 * Cancel a booking
	 *
	 * @param int    $id Booking ID.
	 * @param string $reason Cancellation reason (required).
	 * @return bool True when this call cancelled it; false when the reason is
	 *              empty, the write failed or the booking was not active.
	 */
	public static function cancel( int $id, string $reason ): bool {
		if ( empty( $reason ) ) {
			return false;
		}

		// Only an active booking is cancelled (#2243): two requests racing
		// on one booking used to both pass, both overwrite who cancelled it
		// and both send the e-mails. The second now changes no row.
		$wpdb = self::db();
		$sql  = $wpdb->prepare(
			"UPDATE %i SET status = 'cancelled', cancelled_by = %d, cancelled_at = %s, cancellation_reason = %s WHERE id = %d AND status = 'active'",
			self::get_table_name(),
			get_current_user_id(),
			current_time( 'mysql' ),
			$reason,
			$id
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The argument is the string $wpdb->prepare() returned above; the sniff cannot follow a prepared string across an assignment.
		$changed = null === $sql ? false : $wpdb->query( $sql );

		static::cache_delete( "id_{$id}" );

		return 1 === $changed;
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
		$wpdb->delete( $audiences_table, array( 'booking_id' => $id ), array( '%d' ) );
		$wpdb->delete( $users_table, array( 'booking_id' => $id ), array( '%d' ) );

		// Delete the booking.
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
	 *
	 * @deprecated 6.43.0 Nothing in the plugin calls it (#2089).
	 * @removal    6.45.0
	 */
	public static function remove_booking_audience( int $booking_id, int $audience_id ): bool {
		_deprecated_function( __METHOD__, '6.43.0' );

		$wpdb  = self::db();
		$table = self::get_booking_audiences_table_name();

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
	 * Keep only the entries that are actually ids.
	 *
	 * The id arrays reach this class inside a caller-supplied `$data`, so
	 * their elements are `mixed`. Casting each one straight to `int` turned
	 * anything non-numeric — a stray string, an array, null — into `0` and
	 * wrote a junction row pointing at nothing. Dropping those is the
	 * behaviour the callers already assumed.
	 *
	 * @param array<array-key, mixed> $ids Raw ids from caller-supplied data.
	 * @return list<int>
	 */
	private static function numeric_ids( array $ids ): array {
		$out = array();

		foreach ( $ids as $id ) {
			if ( is_numeric( $id ) ) {
				$out[] = (int) $id;
			}
		}

		return $out;
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
	 *
	 * @deprecated 6.43.0 Nothing in the plugin calls it (#2089).
	 * @removal    6.45.0
	 */
	public static function remove_booking_user( int $booking_id, int $user_id ): bool {
		_deprecated_function( __METHOD__, '6.43.0' );

		$wpdb  = self::db();
		$table = self::get_booking_users_table_name();

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
		$wpdb->delete( $table, array( 'booking_id' => $booking_id ), array( '%d' ) );

		// Add new ones.
		foreach ( $user_ids as $user_id ) {
			self::add_booking_user( $booking_id, (int) $user_id );
		}

		return true;
	}
}

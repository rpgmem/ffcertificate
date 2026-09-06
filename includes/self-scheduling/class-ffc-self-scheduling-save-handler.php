<?php
/**
 * Self-Scheduling Editor — Save Handler
 *
 * Extracted from SelfSchedulingEditor (Sprint 15 refactoring).
 * Handles saving calendar configuration, working hours, and email
 * settings when the admin saves a ffc_self_scheduling post.
 *
 * @since   4.12.16
 * @package FreeFormCertificate\SelfScheduling
 */

declare(strict_types=1);

namespace FreeFormCertificate\SelfScheduling;

use FreeFormCertificate\Core\ArrayValue;
use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles saving calendar configuration, working hours, and email settings.
 *
 * @since 4.12.16
 * @phpstan-import-type CustomBlock from CustomSlots
 */
class SelfSchedulingSaveHandler {

	/**
	 * Register save hook.
	 */
	public function __construct() {
		add_action( 'save_post_ffc_self_scheduling', array( $this, 'save_calendar_data' ), 10, 3 );
	}

	/**
	 * Save calendar data.
	 *
	 * @param int    $post_id Post ID.
	 * @param object $post    Post object.
	 * @param bool   $update  Whether this is an update.
	 * @return void
	 */
	public function save_calendar_data( int $post_id, object $post, bool $update ): void {
		// Security checks.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! wp_verify_nonce( RequestInput::get_post_string( 'ffc_self_scheduling_config_nonce' ), 'ffc_self_scheduling_config_nonce' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$this->save_config( $post_id );
		$this->save_working_hours( $post_id );
		$this->save_custom_slots( $post_id );
		$this->save_email_config( $post_id );

		\FreeFormCertificate\Submissions\FormCache::purge_page_cache( $post_id, 'ffc_self_scheduling' );
	}

	/**
	 * Save calendar configuration.
	 *
	 * The stored value is **rebuilt from the declared key list**, not
	 * mutated in place. Reading `$_POST` and overwriting the keys it knows
	 * left every key it does not know stored verbatim, so a forged submit
	 * wrote arbitrary data into the post meta — and no shape could be
	 * declared for an array carrying whatever the request had. The list
	 * below is the same one the two editor metaboxes render defaults for
	 * (#1075).
	 *
	 * @param int $post_id Post ID.
	 */
	private function save_config( int $post_id ): void {
		if ( ! RequestInput::has_post( 'ffc_self_scheduling_config' ) ) {
			return;
		}

		$raw = RequestInput::get_post_raw_array( 'ffc_self_scheduling_config' );

		$config = array(
			'description'                       => sanitize_textarea_field( ArrayValue::string( $raw, 'description' ) ),
			'slot_duration'                     => self::config_int( $raw, 'slot_duration', 30 ),
			'slot_interval'                     => self::config_int( $raw, 'slot_interval', 0 ),
			'slots_per_day'                     => self::config_int( $raw, 'slots_per_day', 0 ),
			'max_appointments_per_slot'         => self::config_int( $raw, 'max_appointments_per_slot', 1 ),
			'advance_booking_min'               => self::config_int( $raw, 'advance_booking_min', 0 ),
			'advance_booking_max'               => self::config_int( $raw, 'advance_booking_max', 30 ),
			'allow_cancellation'                => isset( $raw['allow_cancellation'] ) ? 1 : 0,
			'cancellation_min_hours'            => self::config_int( $raw, 'cancellation_min_hours', 24 ),
			'minimum_interval_between_bookings' => self::config_int( $raw, 'minimum_interval_between_bookings', 24 ),
			'requires_approval'                 => isset( $raw['requires_approval'] ) ? 1 : 0,
			'status'                            => sanitize_text_field( ArrayValue::string( $raw, 'status', 'active' ) ),

			// Waitlist (#941 phase 2): both scheduling modes. When enabled, a
			// full slot/block offers a queue instead of rejecting; capacity
			// 0 = unlimited.
			'waitlist_enabled'                  => isset( $raw['waitlist_enabled'] ) ? 1 : 0,
			'waitlist_capacity'                 => self::config_int( $raw, 'waitlist_capacity', 0 ),

			// Per-user block cap (#941 phase 3): custom mode only; 0 = disabled.
			'max_blocks_per_user'               => self::config_int( $raw, 'max_blocks_per_user', 0 ),

			// Scheduling mode (#941): 'regular' (weekly working hours) or
			// 'custom' (explicit date/time blocks). Unknown values fall back
			// to 'regular'.
			'schedule_type'                     => ( 'custom' === ArrayValue::string( $raw, 'schedule_type', 'regular' ) ) ? 'custom' : 'regular',

			// Visibility controls.
			'visibility'                        => self::one_of( ArrayValue::string( $raw, 'visibility' ), array( 'public', 'private' ), 'public' ),
			'scheduling_visibility'             => self::one_of( ArrayValue::string( $raw, 'scheduling_visibility' ), array( 'public', 'private' ), 'public' ),

			// Business hours restriction toggles.
			'restrict_viewing_to_hours'         => isset( $raw['restrict_viewing_to_hours'] ) ? 1 : 0,
			'restrict_booking_to_hours'         => isset( $raw['restrict_booking_to_hours'] ) ? 1 : 0,

			// Per-calendar admin bypass toggle. Once the key is written the
			// stored value is authoritative; defaulting-to-on for legacy
			// calendars happens in the consumer
			// (CalendarRepository::userHasSchedulingBypass).
			'admin_bypass'                      => isset( $raw['admin_bypass'] ) ? 1 : 0,
		);

		// Lock the mode once the calendar has bookings — switching would orphan them.
		if ( $this->calendar_has_appointments( $post_id ) ) {
			$stored = get_post_meta( $post_id, '_ffc_self_scheduling_config', true );
			if ( is_array( $stored ) && ! empty( $stored['schedule_type'] ) ) {
				$config['schedule_type'] = ( 'custom' === ArrayValue::string( $stored, 'schedule_type' ) ) ? 'custom' : 'regular';
			}
		}

		// If visibility is private, scheduling must also be private.
		if ( 'private' === $config['visibility'] ) {
			$config['scheduling_visibility'] = 'private';
		}

		update_post_meta( $post_id, '_ffc_self_scheduling_config', $config );
	}

	/**
	 * Read a non-negative integer out of an untyped request container.
	 *
	 * `absint()` keeps the old clamping (a negative becomes its absolute
	 * value); `ArrayValue::int()` is what makes the value an int at all.
	 * A non-numeric now yields the declared default rather than `absint()`'s
	 * `0`, which for several of these fields is not a usable setting (#1075).
	 *
	 * @param array<array-key, mixed> $data    Request container.
	 * @param string                  $key     Field name.
	 * @param int                     $default Value when absent or non-numeric.
	 * @return int
	 */
	private static function config_int( array $data, string $key, int $default ): int {
		return absint( ArrayValue::int( $data, $key, $default ) );
	}

	/**
	 * Constrain a value to an allowlist.
	 *
	 * @param string        $value    Candidate.
	 * @param array<string> $allowed  Accepted values.
	 * @param string        $fallback Returned when the candidate is not in the list.
	 * @return string
	 */
	private static function one_of( string $value, array $allowed, string $fallback ): string {
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * Save working hours.
	 *
	 * @param int $post_id Post ID.
	 */
	private function save_working_hours( int $post_id ): void {
		if ( ! RequestInput::has_post( 'ffc_self_scheduling_working_hours' ) ) {
			return;
		}

		// An empty container is not a write. `get_post_raw_array()` returns
		// `array()` both for a posted empty array and for a posted non-array,
		// and the old guard refused the second — so refusing both keeps the
		// safer half: a forged `…working_hours=x` cannot wipe the stored
		// hours. Neither case is reachable from the editor, which posts no
		// key at all when there are no rows (#1075).
		$rows = RequestInput::get_post_raw_array( 'ffc_self_scheduling_working_hours' );
		if ( empty( $rows ) ) {
			return;
		}

		$working_hours = array();
		foreach ( $rows as $hours ) {
			// A row that is not an array is not a row. Indexing a string by
			// `['day']` reads character 0 with a warning, so the old code
			// stored junk for it rather than skipping it (#1075).
			if ( ! is_array( $hours ) ) {
				continue;
			}
			$working_hours[] = array(
				'day'   => self::config_int( $hours, 'day', 0 ),
				'start' => sanitize_text_field( ArrayValue::string( $hours, 'start', '09:00' ) ),
				'end'   => sanitize_text_field( ArrayValue::string( $hours, 'end', '17:00' ) ),
			);
		}
		update_post_meta( $post_id, '_ffc_self_scheduling_working_hours', $working_hours );
	}

	/**
	 * Save custom scheduling blocks (#941).
	 *
	 * Parses the repeatable block rows (date/start/end/capacity/label) into the
	 * `_ffc_self_scheduling_custom_slots` meta as a normalized array. Invalid rows
	 * (bad date/time, start >= end) are dropped; capacity is clamped to >= 1;
	 * duplicate (date, start) pairs keep the first occurrence — that pair is the
	 * slot-capacity key. Blocks are sorted by date then start.
	 *
	 * @param int $post_id Post ID.
	 */
	private function save_custom_slots( int $post_id ): void {
		if ( ! RequestInput::has_post( 'ffc_self_scheduling_custom_slots' ) ) {
			// Field absent (e.g. saving a regular calendar) — leave existing blocks untouched.
			return;
		}

		// Same reasoning as `save_working_hours()`: an empty container is not
		// a write, so a forged non-array value cannot clear the blocks.
		$rows = RequestInput::get_post_raw_array( 'ffc_self_scheduling_custom_slots' );
		if ( empty( $rows ) ) {
			return;
		}

		$blocks = array();
		$seen   = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$date  = sanitize_text_field( ArrayValue::string( $row, 'date' ) );
			$start = self::normalize_block_time( sanitize_text_field( ArrayValue::string( $row, 'start' ) ) );
			$end   = self::normalize_block_time( sanitize_text_field( ArrayValue::string( $row, 'end' ) ) );

			if ( ! \FreeFormCertificate\Core\DateFormatter::is_valid_date( $date ) || '' === $start || '' === $end || $start >= $end ) {
				continue;
			}

			$key = $date . '|' . $start;
			if ( isset( $seen[ $key ] ) ) {
				continue; // Duplicate (date, start) — the capacity key must be unique.
			}
			$seen[ $key ] = true;

			$blocks[] = array(
				'date'     => $date,
				'start'    => $start,
				'end'      => $end,
				'capacity' => max( 1, self::config_int( $row, 'capacity', 1 ) ),
				'label'    => sanitize_text_field( ArrayValue::string( $row, 'label' ) ),
			);
		}

		// Once bookings exist, a booked block cannot be removed or retimed (#941).
		if ( $this->calendar_has_appointments( $post_id ) ) {
			$blocks = $this->preserve_booked_blocks( $post_id, $blocks );
		}

		usort(
			$blocks,
			static function ( $a, $b ) {
				return array( $a['date'], $a['start'] ) <=> array( $b['date'], $b['start'] );
			}
		);

		update_post_meta( $post_id, '_ffc_self_scheduling_custom_slots', $blocks );
	}

	/**
	 * Whether the calendar (by post id) already has at least one appointment.
	 *
	 * @param int $post_id Calendar post id.
	 * @return bool
	 */
	private function calendar_has_appointments( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}
		global $wpdb;
		$calendars    = $wpdb->prefix . 'ffc_self_scheduling_calendars';
		$appointments = $wpdb->prefix . 'ffc_self_scheduling_appointments';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Guard count joining the plugin's own calendar and appointment tables while the post is saved; a cached number would let a calendar be shrunk with live appointments in it.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(a.id) FROM %i a INNER JOIN %i c ON c.id = a.calendar_id WHERE c.post_id = %d',
				$appointments,
				$calendars,
				$post_id
			)
		);
		return (int) $count > 0;
	}

	/**
	 * Protect blocks that already carry bookings (#941): a booked (date, start)
	 * cannot be removed or retimed. The admin may still change a booked block's
	 * capacity/label and freely edit unbooked blocks. Removed booked blocks are
	 * re-added with their original window; retimed ones keep their original end.
	 *
	 * @param int                              $post_id  Calendar post id.
	 * @param array<int, array<string, mixed>> $incoming Parsed incoming blocks.
	 * @phpstan-param list<CustomBlock> $incoming
	 * @return array<int, array<string, mixed>>
	 * @phpstan-return list<CustomBlock>
	 */
	private function preserve_booked_blocks( int $post_id, array $incoming ): array {
		global $wpdb;
		$calendars    = $wpdb->prefix . 'ffc_self_scheduling_calendars';
		$appointments = $wpdb->prefix . 'ffc_self_scheduling_appointments';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the live appointment slots joined from the plugin's own tables while the post is saved, so the save can refuse to drop a slot that is already booked.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT a.appointment_date AS d, a.start_time AS s FROM %i a INNER JOIN %i c ON c.id = a.calendar_id WHERE c.post_id = %d AND a.status IN ('confirmed','pending')",
				$appointments,
				$calendars,
				$post_id
			),
			ARRAY_A
		);
		if ( empty( $rows ) ) {
			return $incoming;
		}

		$booked = array();
		/**
		 * Both columns are aliased in the SELECT above and `$wpdb` returns
		 * each as a string; `start_time` is `TIME NOT NULL` in the
		 * appointments table (#1060).
		 *
		 * @var array{d: string, s: string} $row
		 */
		foreach ( $rows as $row ) {
			$booked[ $row['d'] . '|' . substr( $row['s'], 0, 5 ) ] = true;
		}

		// `get_post_meta()` is mixed; `decode()` accepts the stored JSON
		// string or the stored array, and nothing else is a custom-slots
		// value (#1075).
		$stored = get_post_meta( $post_id, '_ffc_self_scheduling_custom_slots', true );
		$old    = array();
		foreach ( CustomSlots::decode( is_array( $stored ) || is_string( $stored ) ? $stored : null ) as $block ) {
			$old[ $block['date'] . '|' . CustomSlots::hm( $block['start'] ) ] = $block;
		}

		$by_key = array();
		foreach ( $incoming as $block ) {
			$by_key[ $block['date'] . '|' . $block['start'] ] = $block;
		}

		foreach ( array_keys( $booked ) as $key ) {
			if ( ! isset( $old[ $key ] ) ) {
				continue;
			}
			if ( isset( $by_key[ $key ] ) ) {
				// Present: allow capacity/label change, but no retime.
				$by_key[ $key ]['end'] = $old[ $key ]['end'];
			} else {
				// Removed: restore the original block.
				$by_key[ $key ] = $old[ $key ];
			}
		}

		return array_values( $by_key );
	}

	/**
	 * Validate + normalize a block time to `H:i` (returns '' if invalid).
	 *
	 * @param string $time Raw time string.
	 * @return string
	 */
	private static function normalize_block_time( string $time ): string {
		if ( 1 !== preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $time, $m ) ) {
			return '';
		}
		return sprintf( '%02d:%s', (int) $m[1], $m[2] );
	}

	/**
	 * Save email configuration.
	 *
	 * @param int $post_id Post ID.
	 */
	private function save_email_config( int $post_id ): void {
		if ( ! RequestInput::has_post( 'ffc_self_scheduling_email_config' ) ) {
			return;
		}

		$raw = RequestInput::get_post_raw_array( 'ffc_self_scheduling_email_config' );

		// Rebuilt from the declared key list, same as `save_config()` — see
		// the note there for why mutating the posted array in place is what
		// let unknown keys reach the post meta (#1075).
		$email_config = array(
			'send_user_confirmation'         => isset( $raw['send_user_confirmation'] ) ? 1 : 0,
			'send_admin_notification'        => isset( $raw['send_admin_notification'] ) ? 1 : 0,
			'send_approval_notification'     => isset( $raw['send_approval_notification'] ) ? 1 : 0,
			'send_cancellation_notification' => isset( $raw['send_cancellation_notification'] ) ? 1 : 0,
			'send_reminder'                  => isset( $raw['send_reminder'] ) ? 1 : 0,
			'reminder_hours_before'          => self::config_int( $raw, 'reminder_hours_before', 24 ),
			'admin_emails'                   => sanitize_text_field( ArrayValue::string( $raw, 'admin_emails' ) ),
			'user_confirmation_subject'      => sanitize_text_field( ArrayValue::string( $raw, 'user_confirmation_subject' ) ),
			// The confirmation body is rich HTML authored in the teeny
			// wp_editor (details box + {{receipt_button}} / {{cancel_button}}
			// tokens), so it is sanitised with wp_kses_post like every other
			// editable email body (#965) rather than the tag-stripping
			// sanitize_textarea_field. It is also why this payload cannot go
			// through `RequestInput::get_post_array()`, which would strip the
			// markup before this line ever ran.
			'user_confirmation_body'         => wp_kses_post( ArrayValue::string( $raw, 'user_confirmation_body' ) ),
		);

		update_post_meta( $post_id, '_ffc_self_scheduling_email_config', $email_config );
	}
}

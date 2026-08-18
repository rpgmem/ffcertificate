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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles saving calendar configuration, working hours, and email settings.
 *
 * @since 4.12.16
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

		if ( ! wp_verify_nonce( \FreeFormCertificate\Core\RequestInput::get_post_string( 'ffc_self_scheduling_config_nonce' ), 'ffc_self_scheduling_config_nonce' ) ) {
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
	 * @param int $post_id Post ID.
	 */
	private function save_config( int $post_id ): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in save_calendar_data(); isset() check only; value unslashed below.
		if ( ! isset( $_POST['ffc_self_scheduling_config'] ) ) {
			return;
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in save_calendar_data(); each field sanitized individually below.
		$config = wp_unslash( $_POST['ffc_self_scheduling_config'] );

		// Sanitize.
		$config['description']                       = sanitize_textarea_field( $config['description'] ?? '' );
		$config['slot_duration']                     = absint( $config['slot_duration'] ?? 30 );
		$config['slot_interval']                     = absint( $config['slot_interval'] ?? 0 );
		$config['slots_per_day']                     = absint( $config['slots_per_day'] ?? 0 );
		$config['max_appointments_per_slot']         = absint( $config['max_appointments_per_slot'] ?? 1 );
		$config['advance_booking_min']               = absint( $config['advance_booking_min'] ?? 0 );
		$config['advance_booking_max']               = absint( $config['advance_booking_max'] ?? 30 );
		$config['allow_cancellation']                = isset( $config['allow_cancellation'] ) ? 1 : 0;
		$config['cancellation_min_hours']            = absint( $config['cancellation_min_hours'] ?? 24 );
		$config['minimum_interval_between_bookings'] = absint( $config['minimum_interval_between_bookings'] ?? 24 );
		$config['requires_approval']                 = isset( $config['requires_approval'] ) ? 1 : 0;
		$config['status']                            = sanitize_text_field( $config['status'] ?? 'active' );

		// Waitlist (#941 phase 2): both scheduling modes. When enabled, a full
		// slot/block offers a queue instead of rejecting; capacity 0 = unlimited.
		$config['waitlist_enabled']  = isset( $config['waitlist_enabled'] ) ? 1 : 0;
		$config['waitlist_capacity'] = absint( $config['waitlist_capacity'] ?? 0 );

		// Per-user block cap (#941 phase 3): custom mode only; 0 = disabled.
		$config['max_blocks_per_user'] = absint( $config['max_blocks_per_user'] ?? 0 );

		// Scheduling mode (#941): 'regular' (weekly working hours) or 'custom'
		// (explicit date/time blocks). Unknown values fall back to 'regular'.
		$config['schedule_type'] = ( 'custom' === ( $config['schedule_type'] ?? 'regular' ) ) ? 'custom' : 'regular';

		// Lock the mode once the calendar has bookings — switching would orphan them.
		if ( $this->calendar_has_appointments( $post_id ) ) {
			$stored = get_post_meta( $post_id, '_ffc_self_scheduling_config', true );
			if ( is_array( $stored ) && ! empty( $stored['schedule_type'] ) ) {
				$config['schedule_type'] = ( 'custom' === $stored['schedule_type'] ) ? 'custom' : 'regular';
			}
		}

		// Visibility controls.
		$config['visibility']            = in_array( ( $config['visibility'] ?? '' ), array( 'public', 'private' ), true ) ? $config['visibility'] : 'public';
		$config['scheduling_visibility'] = in_array( ( $config['scheduling_visibility'] ?? '' ), array( 'public', 'private' ), true ) ? $config['scheduling_visibility'] : 'public';

		// If visibility is private, scheduling must also be private.
		if ( 'private' === $config['visibility'] ) {
			$config['scheduling_visibility'] = 'private';
		}

		// Business hours restriction toggles.
		$config['restrict_viewing_to_hours'] = isset( $config['restrict_viewing_to_hours'] ) ? 1 : 0;
		$config['restrict_booking_to_hours'] = isset( $config['restrict_booking_to_hours'] ) ? 1 : 0;

		// Per-calendar admin bypass toggle. Once the key is written the stored
		// value is authoritative; defaulting-to-on for legacy calendars happens
		// in the consumer (CalendarRepository::userHasSchedulingBypass).
		$config['admin_bypass'] = isset( $config['admin_bypass'] ) ? 1 : 0;

		update_post_meta( $post_id, '_ffc_self_scheduling_config', $config );
	}

	/**
	 * Save working hours.
	 *
	 * @param int $post_id Post ID.
	 */
	private function save_working_hours( int $post_id ): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in save_calendar_data(); isset()/is_array() check only; value unslashed below.
		if ( ! isset( $_POST['ffc_self_scheduling_working_hours'] ) || ! is_array( $_POST['ffc_self_scheduling_working_hours'] ) ) {
			return;
		}

		$working_hours = array();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in save_calendar_data(); each field sanitized individually below.
		foreach ( wp_unslash( $_POST['ffc_self_scheduling_working_hours'] ) as $hours ) {
			$working_hours[] = array(
				'day'   => absint( $hours['day'] ?? 0 ),
				'start' => sanitize_text_field( $hours['start'] ?? '09:00' ),
				'end'   => sanitize_text_field( $hours['end'] ?? '17:00' ),
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
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in save_calendar_data(); isset()/is_array() check only; value unslashed below.
		if ( ! isset( $_POST['ffc_self_scheduling_custom_slots'] ) || ! is_array( $_POST['ffc_self_scheduling_custom_slots'] ) ) {
			// Field absent (e.g. saving a regular calendar) — leave existing blocks untouched.
			return;
		}

		$blocks = array();
		$seen   = array();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in save_calendar_data(); each field sanitized individually below.
		foreach ( wp_unslash( $_POST['ffc_self_scheduling_custom_slots'] ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$date  = sanitize_text_field( $row['date'] ?? '' );
			$start = self::normalize_block_time( sanitize_text_field( $row['start'] ?? '' ) );
			$end   = self::normalize_block_time( sanitize_text_field( $row['end'] ?? '' ) );

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
				'capacity' => max( 1, absint( $row['capacity'] ?? 1 ) ),
				'label'    => sanitize_text_field( $row['label'] ?? '' ),
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
	 * @return array<int, array<string, mixed>>
	 */
	private function preserve_booked_blocks( int $post_id, array $incoming ): array {
		global $wpdb;
		$calendars    = $wpdb->prefix . 'ffc_self_scheduling_calendars';
		$appointments = $wpdb->prefix . 'ffc_self_scheduling_appointments';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
		foreach ( $rows as $row ) {
			$booked[ $row['d'] . '|' . substr( (string) $row['s'], 0, 5 ) ] = true;
		}

		$old = array();
		foreach ( \FreeFormCertificate\SelfScheduling\CustomSlots::decode( get_post_meta( $post_id, '_ffc_self_scheduling_custom_slots', true ) ) as $block ) {
			$old[ $block['date'] . '|' . \FreeFormCertificate\SelfScheduling\CustomSlots::hm( $block['start'] ) ] = $block;
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
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in save_calendar_data(); isset() check only; value unslashed below.
		if ( ! isset( $_POST['ffc_self_scheduling_email_config'] ) ) {
			return;
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in save_calendar_data(); each field sanitized individually below.
		$email_config = wp_unslash( $_POST['ffc_self_scheduling_email_config'] );

		$email_config['send_user_confirmation']         = isset( $email_config['send_user_confirmation'] ) ? 1 : 0;
		$email_config['send_admin_notification']        = isset( $email_config['send_admin_notification'] ) ? 1 : 0;
		$email_config['send_approval_notification']     = isset( $email_config['send_approval_notification'] ) ? 1 : 0;
		$email_config['send_cancellation_notification'] = isset( $email_config['send_cancellation_notification'] ) ? 1 : 0;
		$email_config['send_reminder']                  = isset( $email_config['send_reminder'] ) ? 1 : 0;
		$email_config['reminder_hours_before']          = absint( $email_config['reminder_hours_before'] ?? 24 );
		$email_config['admin_emails']                   = sanitize_text_field( $email_config['admin_emails'] ?? '' );
		$email_config['user_confirmation_subject']      = sanitize_text_field( $email_config['user_confirmation_subject'] ?? '' );
		$email_config['user_confirmation_body']         = sanitize_textarea_field( $email_config['user_confirmation_body'] ?? '' );

		update_post_meta( $post_id, '_ffc_self_scheduling_email_config', $email_config );
	}
}

<?php
/**
 * AudienceRecordAutosave
 *
 * Registers the audience calendar and the audience with the record autosave
 * (#1757), so their toggles save the moment they flip.
 *
 * @package FreeFormCertificate\Audience
 * @since   6.40.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\RecordAutosave;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The calendar and audience entities of the record autosave.
 */
class AudienceRecordAutosave {

	/**
	 * Calendar columns saved as `1` / `0`.
	 *
	 * @var list<string>
	 */
	public const SCHEDULE_FLAGS = array(
		'notify_on_booking',
		'notify_on_cancellation',
		'include_ics',
		'notify_admin_on_booking',
		'notify_admin_on_cancellation',
		'show_event_list',
		'is_isolated',
		'admin_bypass',
		'post_event_attendees_only',
	);

	/**
	 * Register both entities.
	 *
	 * @return void
	 */
	public static function register(): void {
		$schedule_fields = array_fill_keys( self::SCHEDULE_FLAGS, array( 'type' => 'bool' ) );

		$schedule_fields['status']     = array(
			'type' => 'map',
			'map'  => array(
				1 => 'active',
				0 => 'inactive',
			),
		);
		$schedule_fields['visibility'] = array(
			'type' => 'map',
			'map'  => array(
				1 => 'public',
				0 => 'private',
			),
		);

		RecordAutosave::register(
			'audience_schedule',
			array(
				'cap'    => 'ffc_manage_audiences',
				'fields' => $schedule_fields,
				'exists' => static fn( int $id ): bool => null !== AudienceScheduleRepository::get_by_id( $id ),
				'save'   => array( self::class, 'save_schedule' ),
			)
		);

		RecordAutosave::register(
			'audience',
			array(
				'cap'    => 'ffc_manage_audiences',
				'fields' => array(
					'status'             => array(
						'type' => 'map',
						'map'  => array(
							1 => 'active',
							0 => 'inactive',
						),
					),
					'allow_self_join'    => array( 'type' => 'bool' ),
					'self_join_scope'    => array(
						'type'    => 'enum',
						'options' => AudienceSelfJoinScope::values(),
					),
					'members_only'       => array( 'type' => 'bool' ),
					'require_membership' => array( 'type' => 'bool' ),
				),
				'exists' => static fn( int $id ): bool => null !== AudienceReader::get_by_id( $id ),
				'save'   => array( self::class, 'save_audience' ),
			)
		);
		// The Attendance screen's rule and post-event cards (#2022). A booking
		// is edited by the module's managers, the same capability its Save
		// buttons checked.
		RecordAutosave::register(
			'audience_booking',
			array(
				'cap'       => 'ffc_manage_audiences',
				'fields'    => array(
					'attendance_mode'           => array(
						'type'    => 'enum',
						'options' => array( AudienceAttendanceRule::SINGLE, AudienceAttendanceRule::TOTAL ),
					),
					'attendance_min_percent'    => array(
						'type' => 'int',
						'min'  => 1,
						'max'  => 100,
					),
					'post_event_custom'         => array( 'type' => 'bool' ),
					'post_event_form_id'        => array(
						'type' => 'int',
						'min'  => 0,
					),
					'post_event_window_days'    => array(
						'type' => 'int',
						'min'  => 1,
						'max'  => AudiencePostEventForm::MAX_WINDOW_DAYS,
					),
					'post_event_attendees_only' => array( 'type' => 'bool' ),
				),
				'exists'    => static fn( int $id ): bool => null !== AudienceBookingReader::get_by_id( $id ),
				'save'      => array( self::class, 'save_booking' ),
				'fragments' => array( self::class, 'booking_fragments' ),
			)
		);
	}

	/**
	 * Save one calendar column.
	 *
	 * @param int    $id    Calendar id.
	 * @param string $key   Column.
	 * @param mixed  $value Typed value.
	 * @return true|\WP_Error
	 */
	public static function save_schedule( int $id, string $key, $value ) {
		if ( ! AudienceScheduleRepository::update( $id, array( $key => $value ) ) ) {
			return new \WP_Error( 'ffc_record_not_saved', __( 'The calendar could not be saved.', 'ffcertificate' ) );
		}
		return true;
	}

	/**
	 * Save one audience field. Self-join is set on a top-level audience only
	 * (#1901): `allow_self_join` is the single switch of an audience without
	 * children, and `self_join_scope` the four-option choice of one with
	 * children, which writes the audience's own flag and every child's and
	 * grandchild's.
	 *
	 * @param int    $id    Audience id.
	 * @param string $key   Column, or `self_join_scope`.
	 * @param mixed  $value Typed value.
	 * @return true|\WP_Error
	 */
	public static function save_audience( int $id, string $key, $value ) {
		if ( 'allow_self_join' === $key || 'self_join_scope' === $key ) {
			$audience = AudienceReader::get_by_id( $id );
			if ( null === $audience || ! empty( $audience->parent_id ) ) {
				return new \WP_Error( 'ffc_record_not_saved', __( 'Self-join is set on the parent audience.', 'ffcertificate' ) );
			}
		}

		// The tree-access pair (#1918) is set on the top-level audience, and
		// "Keep at least one group" only exists under "Visible to members only".
		if ( 'members_only' === $key || 'require_membership' === $key ) {
			$audience = AudienceReader::get_by_id( $id );
			if ( null === $audience || ! empty( $audience->parent_id ) ) {
				return new \WP_Error( 'ffc_record_not_saved', __( 'This setting is set on the top-level audience.', 'ffcertificate' ) );
			}
			$members_only = 'members_only' === $key ? (bool) $value : ! empty( $audience->members_only );
			$require      = 'require_membership' === $key ? (bool) $value : ! empty( $audience->require_membership );
			if ( 'require_membership' === $key && $require && ! $members_only ) {
				return new \WP_Error( 'ffc_record_not_saved', __( 'Turn on "Visible to members only" first.', 'ffcertificate' ) );
			}
			if ( ! AudienceWriter::set_tree_access( $id, $members_only, $require ) ) {
				return new \WP_Error( 'ffc_record_not_saved', __( 'The audience could not be saved.', 'ffcertificate' ) );
			}
			return true;
		}

		// No column holds it: the choice is written as two flags.
		if ( 'self_join_scope' === $key ) {
			if ( ! AudienceWriter::apply_self_join_scope( $id, (string) $value ) ) {
				return new \WP_Error( 'ffc_record_not_saved', __( 'The audience could not be saved.', 'ffcertificate' ) );
			}
			return true;
		}

		if ( ! AudienceWriter::update( $id, array( $key => $value ) ) ) {
			return new \WP_Error( 'ffc_record_not_saved', __( 'The audience could not be saved.', 'ffcertificate' ) );
		}
		return true;
	}

	/**
	 * What the Attendance screen shows about a booking after a save (#2026),
	 * read back from the stored row.
	 *
	 * @param int $id Booking id.
	 * @return array<string, string>
	 */
	public static function booking_fragments( int $id ): array {
		$booking = AudienceBookingReader::get_by_id( $id );

		return null === $booking ? array() : AudienceAdminAttendance::fragments( $booking );
	}

	/**
	 * Save one field of a booking's attendance rule or post-event form.
	 *
	 * Both are written as a whole, so the field changed is merged into what
	 * the booking holds now. The rule keeps its share when the mode changes,
	 * and *Share of days* starts from the default share when none was set.
	 * The post-event settings start from the calendar's while the booking
	 * still follows it, so switching it to its own settings keeps what was
	 * shown; a field changed while it follows its calendar switches it.
	 *
	 * @param int    $id    Booking id.
	 * @param string $key   Field.
	 * @param mixed  $value Typed value.
	 * @return true|\WP_Error
	 */
	public static function save_booking( int $id, string $key, $value ) {
		$booking = AudienceBookingReader::get_by_id( $id );
		if ( null === $booking ) {
			return new \WP_Error( 'ffc_record_not_saved', __( 'The booking could not be saved.', 'ffcertificate' ) );
		}

		if ( 'attendance_mode' === $key || 'attendance_min_percent' === $key ) {
			$rule    = AudienceAttendanceRule::of( $booking );
			$mode    = 'attendance_mode' === $key ? (string) $value : AudienceAttendanceRule::TOTAL;
			$percent = 'attendance_min_percent' === $key ? (int) $value : ( $rule['percent'] > 0 ? $rule['percent'] : AudienceAttendanceRule::DEFAULT_PERCENT );
			if ( ! AudienceBookingWriter::set_attendance_rule( $id, $mode, $percent ) ) {
				return new \WP_Error( 'ffc_record_not_saved', __( 'The booking could not be saved.', 'ffcertificate' ) );
			}
			return true;
		}

		if ( 'post_event_form_id' === $key && 0 !== (int) $value && ! AudiencePostEventForm::is_form( (int) $value ) ) {
			return new \WP_Error( 'ffc_record_not_saved', __( 'Choose a published certificate form.', 'ffcertificate' ) );
		}

		$custom = AudiencePostEventForm::is_custom( $booking );
		if ( $custom ) {
			$settings = AudiencePostEventForm::settings( $booking );
		} else {
			$schedule_id = (int) ( $booking->schedule_id ?? 0 );
			$settings    = AudiencePostEventForm::of_schedule( $schedule_id > 0 ? AudienceScheduleRepository::get_by_id( $schedule_id ) : null );
		}

		switch ( $key ) {
			case 'post_event_custom':
				$custom = (bool) $value;
				break;
			case 'post_event_form_id':
				$custom              = true;
				$settings['form_id'] = (int) $value;
				break;
			case 'post_event_window_days':
				$custom                  = true;
				$settings['window_days'] = (int) $value;
				break;
			case 'post_event_attendees_only':
				$custom                     = true;
				$settings['attendees_only'] = (bool) $value;
				break;
		}

		if ( ! AudienceBookingWriter::set_post_event_form( $id, $custom, $settings['form_id'], $settings['window_days'], $settings['attendees_only'] ) ) {
			return new \WP_Error( 'ffc_record_not_saved', __( 'The booking could not be saved.', 'ffcertificate' ) );
		}
		return true;
	}
}

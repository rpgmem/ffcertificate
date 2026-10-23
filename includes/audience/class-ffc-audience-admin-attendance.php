<?php
/**
 * Audience Admin Attendance
 *
 * The attendance screen of one booking (#1947).
 *
 * @package FreeFormCertificate\Audience
 * @since 6.42.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Admin\AdminUI;
use FreeFormCertificate\Core\AssetHelper;
use FreeFormCertificate\Core\Capabilities;
use FreeFormCertificate\Core\DateFormatter;
use FreeFormCertificate\Core\RecordAutosave;
use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Opens and closes check-in, projects the rotating QR, lists who is present,
 * lets the organiser mark or remove an attendance by hand, sets the
 * booking's attendance rule and exports the list.
 *
 * Reached from the Attendance row action of the bookings list; it has no
 * menu row of its own (the slug is registered without a parent, like the
 * Environments screen).
 *
 * Every write is a POST carrying a nonce bound to the booking, handled on
 * `admin_init` and answered with a redirect, so a reload never repeats it.
 *
 * @since 6.42.0
 */
class AudienceAdminAttendance {

	/**
	 * Capability that runs check-in: open, close, project, mark by hand.
	 *
	 * @var string
	 */
	public const CAP = 'ffc_checkin_audiences';

	/**
	 * View that prints the projected QR as a document of its own.
	 *
	 * @var string
	 */
	public const VIEW_PROJECT = 'project';

	/**
	 * Menu slug prefix.
	 *
	 * @var string
	 */
	private string $menu_slug;

	/**
	 * Constructor.
	 *
	 * @param string $menu_slug Menu slug prefix.
	 */
	public function __construct( string $menu_slug ) {
		$this->menu_slug = $menu_slug;
	}

	/**
	 * This screen's `?page=` value.
	 *
	 * @return string
	 */
	public function slug(): string {
		return $this->menu_slug . '-attendance';
	}

	/**
	 * The screen's URL.
	 *
	 * @param int                   $booking_id Booking.
	 * @param array<string, string> $args       Further query arguments.
	 * @return string
	 */
	public function url( int $booking_id, array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page'       => $this->slug(),
					'booking_id' => (string) $booking_id,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Nonce action for one booking's writes.
	 *
	 * @param int $booking_id Booking.
	 * @return string
	 */
	public static function nonce_action( int $booking_id ): string {
		return 'ffc_audience_attendance_' . $booking_id;
	}

	/**
	 * The day the screen shows: the one asked for when the booking has it,
	 * else today when it is a booked day, else the next booked day, else the
	 * last one.
	 *
	 * @param string[] $days      Counted days, oldest first.
	 * @param string   $requested Day asked for.
	 * @param string   $today     Today (Y-m-d).
	 * @return string '' when the booking has no counted day.
	 */
	public static function resolve_day( array $days, string $requested, string $today ): string {
		if ( in_array( $requested, $days, true ) ) {
			return $requested;
		}
		foreach ( $days as $day ) {
			if ( $day >= $today ) {
				return $day;
			}
		}
		$last = end( $days );

		return false === $last ? '' : $last;
	}

	/**
	 * Handle the screen's writes and the projected view. Runs on `admin_init`.
	 *
	 * @return void
	 */
	public function handle_actions(): void {
		if ( $this->slug() !== RequestInput::get_get_key( 'page' ) ) {
			return;
		}

		if ( ! Capabilities::current_user_can_admin_or( self::CAP ) ) {
			return;
		}

		$booking_id = RequestInput::get_get_int( 'booking_id' );
		$booking    = $booking_id > 0 ? AudienceBookingReader::get_by_id( $booking_id ) : null;
		if ( null === $booking ) {
			return;
		}

		if ( self::VIEW_PROJECT === RequestInput::get_get_key( 'view' ) ) {
			$this->render_projection( $booking );
			exit;
		}

		$action = RequestInput::get_post_string( 'ffc_attendance_action' );
		if ( '' === $action ) {
			return;
		}

		check_admin_referer( self::nonce_action( $booking_id ) );

		// One form carries every row's button; the button pressed names the
		// person and whether they are marked or removed.
		if ( 'mark' === $action && RequestInput::has_post( 'unmark_user' ) ) {
			$action = 'unmark';
		}
		if ( 'invite' === $action && RequestInput::has_post( 'invite_all' ) ) {
			$action = 'invite_all';
		}

		$days = AudienceAttendanceRule::counted_days( $booking );
		$date = RequestInput::get_post_string( 'date' );
		if ( ! in_array( $date, $days, true ) ) {
			$date = self::resolve_day( $days, '', AudienceBookingRules::today() );
		}

		$message = $this->perform( $action, $booking, $date, time() );

		wp_safe_redirect(
			$this->url(
				$booking_id,
				array(
					'date'    => $date,
					'message' => $message,
				)
			)
		);
		exit;
	}

	/**
	 * Carry out one write.
	 *
	 * @param string $action  Action.
	 * @param object $booking Booking row.
	 * @param string $date    Day (Y-m-d).
	 * @param int    $now     Unix time.
	 * @return string Message key for the redirect.
	 */
	public function perform( string $action, object $booking, string $date, int $now ): string {
		$booking_id = (int) ( $booking->id ?? 0 );
		$today      = AudienceBookingRules::today();
		$user       = get_current_user_id();

		switch ( $action ) {
			case 'open':
				if ( 'active' !== ( $booking->status ?? '' ) || $date !== $today ) {
					return 'not_today';
				}
				$closes = AudienceCheckinWindowWriter::auto_close_at( $booking, $date );
				if ( $closes <= $now ) {
					return 'ended';
				}
				$known    = array_map( static fn( array $l ): string => (string) ( $l['id'] ?? '' ), \FreeFormCertificate\Security\GeofenceLocationRegistry::get_all() );
				$geofence = array_values( array_intersect( RequestInput::get_post_array( 'geofence_locations' ), $known ) );
				$id       = AudienceCheckinWindowWriter::open(
					$booking_id,
					$date,
					RequestInput::get_post_int( 'rotation_seconds', 60 ),
					$closes,
					$user,
					$now,
					$geofence
				);
				return $id > 0 ? 'opened' : 'failed';

			case 'close':
				$window = AudienceCheckinWindowReader::open_for( $booking_id, $date, $now );
				if ( null === $window ) {
					return 'not_open';
				}
				return AudienceCheckinWindowWriter::close( $window['id'], $user, $now ) ? 'closed' : 'failed';

			case 'mark':
				if ( $date > $today ) {
					return 'future';
				}
				// Only someone the booking expects can be marked: a forged
				// user id must not create a line for a stranger.
				if ( ! in_array( RequestInput::get_post_int( 'mark_user' ), array_map( 'intval', AudienceBookingReader::get_all_affected_users( $booking_id ) ), true ) ) {
					return 'failed';
				}
				$status = AudienceAttendanceWriter::record( $booking_id, RequestInput::get_post_int( 'mark_user' ), $date, AudienceAttendanceWriter::METHOD_MANUAL, $user, $now );
				return AudienceAttendanceWriter::FAILED === $status ? 'failed' : 'marked';

			case 'unmark':
				return AudienceAttendanceWriter::remove( $booking_id, RequestInput::get_post_int( 'unmark_user' ), $date ) ? 'unmarked' : 'failed';

			case 'rule':
				if ( ! Capabilities::current_user_can_admin_or( 'ffc_manage_audiences' ) ) {
					return 'denied';
				}
				$rule = AudienceAttendanceRule::normalize( RequestInput::get_post_string( 'attendance_mode' ), RequestInput::get_post_int( 'attendance_min_percent', AudienceAttendanceRule::DEFAULT_PERCENT ) );
				return AudienceBookingWriter::set_attendance_rule( $booking_id, $rule['mode'], $rule['percent'] ) ? 'rule_saved' : 'failed';

			case 'post_event':
				if ( ! Capabilities::current_user_can_admin_or( 'ffc_manage_audiences' ) ) {
					return 'denied';
				}
				$form_id = RequestInput::get_post_int( 'post_event_form' );
				if ( 0 !== $form_id && ! AudiencePostEventForm::is_form( $form_id ) ) {
					return 'failed';
				}
				return AudienceBookingWriter::set_post_event_form(
					$booking_id,
					isset( $_POST['post_event_custom'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle_actions() verified the nonce before dispatching here.
					$form_id,
					RequestInput::get_post_int( 'post_event_window_days', AudiencePostEventForm::DEFAULT_WINDOW_DAYS ),
					isset( $_POST['post_event_attendees_only'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle_actions() verified the nonce before dispatching here.
				) ? 'post_event_saved' : 'failed';

			case 'invite':
				$invitee = RequestInput::get_post_int( 'invite_user' );
				$allowed = AudiencePostEventForm::eligibility( $booking, $invitee, $now );
				if ( $allowed instanceof \WP_Error ) {
					return 'invite_not_eligible';
				}
				$result = AudienceFormInviteMailer::send( $booking, $invitee, $now );
				if ( true === $result ) {
					return 'invite_sent';
				}
				return 'no_page' === $result->get_error_code() ? 'invite_no_page' : 'invite_failed';

			case 'invite_all':
				$answers = AudienceFormInviteReader::answers( $booking_id );
				$sent    = 0;
				foreach ( AudiencePostEventForm::participants( $booking_id ) as $invitee ) {
					if ( isset( $answers[ $invitee ] ) || true !== AudiencePostEventForm::eligibility( $booking, $invitee, $now ) ) {
						continue;
					}
					$result = AudienceFormInviteMailer::send( $booking, $invitee, $now );
					if ( true === $result ) {
						++$sent;
					} elseif ( 'no_page' === $result->get_error_code() ) {
						return 'invite_no_page';
					}
				}
				return $sent > 0 ? 'invite_sent_all' : 'invite_none';

			case 'export':
				if ( ! Capabilities::current_user_can_admin_or( 'ffc_export_audiences' ) ) {
					return 'denied';
				}
				( new \FreeFormCertificate\Core\SyncCsvExport() )->handle( new AudienceAttendanceExportSource( $booking ) );
				exit;
		}

		return 'failed';
	}

	/**
	 * Messages the redirect can carry.
	 *
	 * @return array<string, array{0: string, 1: string}> Key => [text, notice type].
	 */
	public static function messages(): array {
		return array(
			'opened'              => array( __( 'Check-in is open. Project the QR code where participants can scan it.', 'ffcertificate' ), 'success' ),
			'closed'              => array( __( 'Check-in is closed.', 'ffcertificate' ), 'success' ),
			'marked'              => array( __( 'Attendance marked.', 'ffcertificate' ), 'success' ),
			'unmarked'            => array( __( 'Attendance removed.', 'ffcertificate' ), 'success' ),
			'rule_saved'          => array( __( 'Attendance rule saved.', 'ffcertificate' ), 'success' ),
			'post_event_saved'    => array( __( 'Post-event form saved.', 'ffcertificate' ), 'success' ),
			'invite_sent'         => array( __( 'Invitation sent.', 'ffcertificate' ), 'success' ),
			'invite_sent_all'     => array( __( 'Invitations sent to everyone eligible who has not answered.', 'ffcertificate' ), 'success' ),
			'invite_none'         => array( __( 'Nobody was sent an invitation: everyone eligible has answered, or the form is not open.', 'ffcertificate' ), 'warning' ),
			'invite_not_eligible' => array( __( 'This person cannot be invited now: the form is not open, or they are not eligible.', 'ffcertificate' ), 'warning' ),
			'invite_no_page'      => array( __( 'No published page embeds the post-event form. Add the form to a page, then send again.', 'ffcertificate' ), 'error' ),
			'invite_failed'       => array( __( 'The invitation could not be sent.', 'ffcertificate' ), 'error' ),
			'ended'               => array( __( 'Check-in can no longer be opened today: the booking has ended. Mark attendance by hand below.', 'ffcertificate' ), 'warning' ),
			'not_today'           => array( __( 'Check-in by QR code opens only on the day itself, for an active booking.', 'ffcertificate' ), 'warning' ),
			'not_open'            => array( __( 'Check-in was not open.', 'ffcertificate' ), 'warning' ),
			'future'              => array( __( 'Attendance cannot be marked for a day that has not come yet.', 'ffcertificate' ), 'warning' ),
			'denied'              => array( __( 'You do not have permission to do that.', 'ffcertificate' ), 'error' ),
			'failed'              => array( __( 'The change could not be saved.', 'ffcertificate' ), 'error' ),
		);
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public function render_page(): void {
		$booking = AudienceBookingReader::get_by_id( RequestInput::get_get_int( 'booking_id' ) );

		echo '<div class="wrap ffc-admin-page ffc-page-scheduling-attendance">';

		if ( null === $booking ) {
			echo '<h1>' . esc_html__( 'Attendance', 'ffcertificate' ) . '</h1>';
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Booking not found.', 'ffcertificate' ) . '</p></div></div>';
			return;
		}

		$days   = AudienceAttendanceRule::counted_days( $booking );
		$today  = AudienceBookingRules::today();
		$date   = self::resolve_day( $days, RequestInput::get_get_string( 'date' ), $today );
		$roster = AudienceAttendanceRoster::for_day( $booking, $date );
		$now    = time();
		$window = AudienceCheckinWindowReader::open_for( (int) ( $booking->id ?? 0 ), $date, $now );

		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Attendance', 'ffcertificate' ) . '</h1>';
		AdminUI::render_button(
			array(
				'label' => __( 'Back to bookings', 'ffcertificate' ),
				'icon'  => 'chevron-left',
				'href'  => admin_url( 'admin.php?page=' . $this->menu_slug . '-bookings' ),
			)
		);
		echo '<hr class="wp-header-end">';

		$message = self::messages()[ RequestInput::get_get_key( 'message' ) ] ?? null;
		if ( null !== $message ) {
			echo '<div class="notice notice-' . esc_attr( $message[1] ) . ' is-dismissible"><p>' . esc_html( $message[0] ) . '</p></div>';
		}

		echo '<p class="ffc-attendance-event"><strong>' . esc_html( (string) ( $booking->description ?? '' ) ) . '</strong> · ' . esc_html( (string) ( $booking->environment_name ?? '' ) ) . '</p>';

		if ( count( $days ) > 1 ) {
			$this->render_day_picker( (int) ( $booking->id ?? 0 ), $days, $date );
		}

		echo '<div class="ffc-boxed ffc-attendance">';
		$this->render_checkin_card( $booking, $date, $today, $window, $now );
		$this->render_rule_card( $booking, $date );
		$this->render_post_event_card( $booking, $date, $roster, $now );
		$this->render_participants_card( $booking, $date, $today, $roster );
		echo '</div></div>';
	}

	/**
	 * The day selector of a booking over several days.
	 *
	 * @param int      $booking_id Booking.
	 * @param string[] $days       Counted days.
	 * @param string   $date       Day shown.
	 * @return void
	 */
	private function render_day_picker( int $booking_id, array $days, string $date ): void {
		echo '<form method="get" class="ffc-attendance-day">';
		echo '<input type="hidden" name="page" value="' . esc_attr( $this->slug() ) . '">';
		echo '<input type="hidden" name="booking_id" value="' . esc_attr( (string) $booking_id ) . '">';
		echo '<label for="ffc-attendance-date">' . esc_html__( 'Day', 'ffcertificate' ) . '</label> ';
		echo '<select id="ffc-attendance-date" name="date">';
		foreach ( $days as $day ) {
			echo '<option value="' . esc_attr( $day ) . '"' . selected( $day, $date, false ) . '>' . esc_html( DateFormatter::format_wallclock_date( $day ) ) . '</option>';
		}
		echo '</select> ';
		AdminUI::render_button(
			array(
				'label' => __( 'Show', 'ffcertificate' ),
				'icon'  => 'calendar',
				'type'  => 'submit',
			)
		);
		echo '</form>';
	}

	/**
	 * Hidden fields every write form carries.
	 *
	 * @param int    $booking_id Booking.
	 * @param string $action     Action.
	 * @param string $date       Day.
	 * @return string
	 */
	private function form_fields( int $booking_id, string $action, string $date ): string {
		return wp_nonce_field( self::nonce_action( $booking_id ), '_wpnonce', true, false )
			. '<input type="hidden" name="ffc_attendance_action" value="' . esc_attr( $action ) . '">'
			. '<input type="hidden" name="date" value="' . esc_attr( $date ) . '">';
	}

	/**
	 * The check-in card: open, project, close.
	 *
	 * @param object                    $booking Booking row.
	 * @param string                    $date    Day shown.
	 * @param string                    $today   Today.
	 * @param array<string, mixed>|null $window  The window open now, if any.
	 * @param int                       $now     Unix time.
	 * @return void
	 */
	private function render_checkin_card( object $booking, string $date, string $today, ?array $window, int $now ): void {
		$booking_id = (int) ( $booking->id ?? 0 );

		echo '<div class="card"><h2 class="ffc-icon-qr">' . esc_html__( 'Check-in by QR code', 'ffcertificate' ) . '</h2>';

		if ( 'active' !== ( $booking->status ?? '' ) ) {
			echo '<p>' . esc_html__( 'This booking is cancelled, so it takes no check-in.', 'ffcertificate' ) . '</p></div>';
			return;
		}

		if ( $date !== $today ) {
			echo '<p>' . esc_html__( 'Check-in by QR code opens only on the day itself. Attendance on other days can be marked by hand below.', 'ffcertificate' ) . '</p></div>';
			return;
		}

		if ( null !== $window ) {
			/* translators: 1: time check-in closes on its own, 2: rotation in seconds */
			echo '<p class="ffc-attendance-open"><span class="ffc-attendance-badge ffc-attendance-badge-open">' . esc_html__( 'Open', 'ffcertificate' ) . '</span> ' . esc_html( sprintf( __( 'Closes on its own at %1$s. The code changes every %2$d seconds.', 'ffcertificate' ), DateFormatter::format_time( $window['closes_at'] ), $window['rotation_seconds'] ) ) . '</p>';
			if ( array() !== $window['geofence_locations'] ) {
				echo '<p class="ffc-icon-map-pin">' . esc_html__( 'Participants must be inside one of the selected areas.', 'ffcertificate' ) . '</p>';
			}
			echo '<form method="post" class="ffc-attendance-actions">' . $this->form_fields( $booking_id, 'close', $date ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts by form_fields().
			AdminUI::render_button(
				array(
					'label'    => __( 'Project the QR code', 'ffcertificate' ),
					'category' => 'primary',
					'icon'     => 'monitor',
					'href'     => $this->url(
						$booking_id,
						array(
							'view' => self::VIEW_PROJECT,
							'date' => $date,
						)
					),
					'attrs'    => array(
						'target' => '_blank',
						'rel'    => 'noopener',
					),
				)
			);
			AdminUI::render_button(
				array(
					'label'    => __( 'Close check-in', 'ffcertificate' ),
					'category' => 'caution',
					'icon'     => 'lock',
					'type'     => 'submit',
				)
			);
			echo '</form></div>';
			return;
		}

		$closes = AudienceCheckinWindowWriter::auto_close_at( $booking, $date );
		if ( $closes <= $now ) {
			/* translators: %s: time check-in closed on its own */
			echo '<p>' . esc_html( sprintf( __( 'Check-in closed on its own at %s, 30 minutes after the booking ended today. Attendance can still be marked by hand below.', 'ffcertificate' ), DateFormatter::format_time( $closes ) ) ) . '</p></div>';
			return;
		}

		echo '<form method="post" class="ffc-attendance-open-form">' . $this->form_fields( $booking_id, 'open', $date ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts by form_fields().
		echo '<p><label for="ffc-attendance-rotation">' . esc_html__( 'The code changes every', 'ffcertificate' ) . '</label> <select id="ffc-attendance-rotation" name="rotation_seconds">';
		foreach ( AudienceCheckinCode::ROTATIONS as $seconds ) {
			$label = 60 === $seconds
				? __( '1 minute', 'ffcertificate' )
				/* translators: %d: number of minutes */
				: sprintf( __( '%d minutes', 'ffcertificate' ), intdiv( $seconds, 60 ) );
			echo '<option value="' . esc_attr( (string) $seconds ) . '"' . selected( 60, $seconds, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';

		$locations = \FreeFormCertificate\Security\GeofenceLocationRegistry::get_all();
		if ( array() !== $locations ) {
			echo '<fieldset class="ffc-attendance-geofence"><legend>' . esc_html__( 'Require the participant to be inside (optional)', 'ffcertificate' ) . '</legend>';
			foreach ( $locations as $location ) {
				$id = (string) ( $location['id'] ?? '' );
				echo '<label><input type="checkbox" name="geofence_locations[]" value="' . esc_attr( $id ) . '"> ' . esc_html( (string) ( $location['name'] ?? $id ) ) . '</label>';
			}
			echo '<p class="description">' . esc_html__( 'The position comes from the participant\'s browser and can be faked: an area makes checking in from elsewhere harder, it does not prove presence.', 'ffcertificate' ) . '</p></fieldset>';
		}

		/* translators: %s: time check-in closes on its own */
		echo '<p class="description">' . esc_html( sprintf( __( 'Check-in closes on its own at %s, 30 minutes after the booking ends today.', 'ffcertificate' ), DateFormatter::format_time( $closes ) ) ) . '</p>';
		AdminUI::render_button(
			array(
				'label'    => __( 'Open check-in', 'ffcertificate' ),
				'category' => 'primary',
				'icon'     => 'unlock',
				'type'     => 'submit',
			)
		);
		echo '</form></div>';
	}

	/**
	 * The attendance-rule card.
	 *
	 * @param object $booking Booking row.
	 * @param string $date    Day shown.
	 * @return void
	 */
	private function render_rule_card( object $booking, string $date ): void {
		$rule     = AudienceAttendanceRule::of( $booking );
		$can_edit = Capabilities::current_user_can_admin_or( 'ffc_manage_audiences' );
		$days     = count( AudienceAttendanceRule::counted_days( $booking ) );
		$id       = $can_edit ? (int) ( $booking->id ?? 0 ) : 0;

		echo '<div class="card"><h2 class="ffc-icon-checkmark">' . esc_html__( 'Attendance rule', 'ffcertificate' ) . '</h2>';
		echo '<form method="post">' . $this->form_fields( (int) ( $booking->id ?? 0 ), 'rule', $date ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts by form_fields().
		AdminUI::render_segmented(
			array(
				'name'         => 'attendance_mode',
				'id'           => 'ffc-attendance-mode',
				'data'         => RecordAutosave::attrs( 'audience_booking', $id, 'attendance_mode' ),
				'legend'       => __( 'Attendance rule', 'ffcertificate' ),
				'value'        => $rule['mode'],
				'disabled'     => ! $can_edit,
				'options'      => array(
					AudienceAttendanceRule::SINGLE => __( 'Any day', 'ffcertificate' ),
					AudienceAttendanceRule::TOTAL  => __( 'Share of days', 'ffcertificate' ),
				),
				'descriptions' => array(
					AudienceAttendanceRule::SINGLE => __( 'Present on at least one day counts as attended.', 'ffcertificate' ),
					AudienceAttendanceRule::TOTAL  => __( 'Present on at least the share below of the booked days counts as attended. Skipped days do not count.', 'ffcertificate' ),
				),
			)
		);
		echo '<p class="ffc-collapsed-target" data-ffc-master="ffc-attendance-mode" data-ffc-master-value="' . esc_attr( AudienceAttendanceRule::TOTAL ) . '"><label for="ffc-attendance-percent">' . esc_html__( 'Minimum share of days (%)', 'ffcertificate' ) . '</label> ';
		echo '<input type="number" id="ffc-attendance-percent" name="attendance_min_percent" min="1" max="100" step="1" required class="small-text" value="' . esc_attr( (string) ( $rule['percent'] > 0 ? $rule['percent'] : AudienceAttendanceRule::DEFAULT_PERCENT ) ) . '"' . self::data_attrs( RecordAutosave::attrs( 'audience_booking', $id, 'attendance_min_percent' ) ) . disabled( ! $can_edit, true, false ) . '></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- data_attrs() escapes each name and value.
		/* translators: 1: days required, 2: days booked */
		echo '<p class="description">' . esc_html( sprintf( __( 'As saved, %1$d of the %2$d booked day(s) are required.', 'ffcertificate' ), AudienceAttendanceRule::required_days( $booking ), $days ) ) . '</p>';
		echo '</form></div>';
	}

	/**
	 * The post-event form card (#1948): what applies to this booking, and the
	 * switch that gives it settings of its own instead of its calendar's.
	 *
	 * @param object               $booking Booking row.
	 * @param string               $date    Day shown.
	 * @param array<string, mixed> $roster  Roster, for the names and the rule.
	 * @param int                  $now     Unix time.
	 * @phpstan-param array{rows: list<array{user_id: int, name: string, present_at: int|null, method: string|null, days: list<string>, met: bool}>, present: int, total: int, required: int, days: list<string>} $roster
	 * @return void
	 */
	private function render_post_event_card( object $booking, string $date, array $roster, int $now ): void {
		$can_edit    = Capabilities::current_user_can_admin_or( 'ffc_manage_audiences' );
		$custom      = AudiencePostEventForm::is_custom( $booking );
		$applied     = AudiencePostEventForm::settings( $booking );
		$schedule_id = (int) ( $booking->schedule_id ?? 0 );
		$schedule    = $schedule_id > 0 ? AudienceScheduleRepository::get_by_id( $schedule_id ) : null;
		// The fields start from what applies now, so turning the switch on
		// begins from the calendar's choice rather than from nothing.
		$fields = $custom ? $applied : AudiencePostEventForm::of_schedule( $schedule );
		$id     = $can_edit ? (int) ( $booking->id ?? 0 ) : 0;

		echo '<div class="card"><h2 class="ffc-icon-award">' . esc_html__( 'Post-event form', 'ffcertificate' ) . '</h2>';
		echo '<p class="ffc-attendance-post-event-status">' . esc_html( self::post_event_status( $booking, $applied, $custom ) ) . '</p>';
		echo AudienceFormInviteMailer::missing_page_notice( $applied['form_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by missing_page_notice().
		echo '<form method="post">' . $this->form_fields( (int) ( $booking->id ?? 0 ), 'post_event', $date ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts by form_fields().
		echo '<p>';
		AdminUI::render_toggle(
			array(
				'name'     => 'post_event_custom',
				'id'       => 'ffc-post-event-custom',
				'data'     => RecordAutosave::attrs( 'audience_booking', $id, 'post_event_custom' ),
				'checked'  => $custom,
				'disabled' => ! $can_edit,
				'label'    => __( 'Use settings of its own for this booking', 'ffcertificate' ),
			)
		);
		echo '</p><p class="description">' . esc_html__( 'Off, the booking follows its calendar. On, the three settings below apply to this booking only; choose None to invite nobody.', 'ffcertificate' ) . '</p>';
		echo '<div class="ffc-collapsed-target" data-ffc-master="ffc-post-event-custom">';
		echo '<p><label for="ffc-post-event-form">' . esc_html__( 'Certificate form', 'ffcertificate' ) . '</label> ';
		AudienceAdminCalendar::render_form_select( 'post_event_form', 'ffc-post-event-form', $fields['form_id'], ! $can_edit, RecordAutosave::attrs( 'audience_booking', $id, 'post_event_form_id' ) );
		echo '</p><p><label for="ffc-post-event-days">' . esc_html__( 'Days open', 'ffcertificate' ) . '</label> ';
		echo '<input type="number" id="ffc-post-event-days" name="post_event_window_days" min="1" max="' . esc_attr( (string) AudiencePostEventForm::MAX_WINDOW_DAYS ) . '" step="1" required class="small-text" value="' . esc_attr( (string) $fields['window_days'] ) . '"' . self::data_attrs( RecordAutosave::attrs( 'audience_booking', $id, 'post_event_window_days' ) ) . disabled( ! $can_edit, true, false ) . '></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- data_attrs() escapes each name and value.
		echo '<p>';
		AdminUI::render_toggle(
			array(
				'name'     => 'post_event_attendees_only',
				'data'     => RecordAutosave::attrs( 'audience_booking', $id, 'post_event_attendees_only' ),
				'checked'  => $fields['attendees_only'],
				'disabled' => ! $can_edit,
				'label'    => __( 'Invite only people whose attendance meets the rule', 'ffcertificate' ),
			)
		);
		echo '</p></div>';
		if ( $can_edit ) {
			echo '<p class="description">' . esc_html__( 'Changes save as you make them. Reload the page to see which form applies and the invitations.', 'ffcertificate' ) . '</p>';
		}
		echo '</form>';

		if ( AudiencePostEventForm::is_form( $applied['form_id'] ) && array() !== $roster['rows'] ) {
			$this->render_invite_tracking( $booking, $date, $roster, $now );
		}
		echo '</div>';
	}

	/**
	 * Who was invited to the post-event form, and what they did with it.
	 *
	 * @param object               $booking Booking row.
	 * @param string               $date    Day shown.
	 * @param array<string, mixed> $roster  Roster.
	 * @param int                  $now     Unix time.
	 * @phpstan-param array{rows: list<array{user_id: int, name: string, present_at: int|null, method: string|null, days: list<string>, met: bool}>, present: int, total: int, required: int, days: list<string>} $roster
	 * @return void
	 */
	private function render_invite_tracking( object $booking, string $date, array $roster, int $now ): void {
		$booking_id = (int) ( $booking->id ?? 0 );
		$invited    = AudienceFormInviteReader::for_booking( $booking_id );
		$answers    = AudienceFormInviteReader::answers( $booking_id );
		$opens      = AudiencePostEventForm::opens_at( $booking );
		$open       = 'cancelled' !== ( $booking->status ?? '' ) && 0 !== $opens && $now >= $opens
			&& $now < AudiencePostEventForm::closes_at( $booking, AudiencePostEventForm::settings( $booking )['window_days'] );

		echo '<h3>' . esc_html__( 'Invitations', 'ffcertificate' ) . '</h3>';
		echo '<form method="post">' . $this->form_fields( $booking_id, 'invite', $date ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts by form_fields().
		echo '<table class="widefat striped ffc-attendance-invites"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Name', 'ffcertificate' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Attendance', 'ffcertificate' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Invited', 'ffcertificate' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Answer', 'ffcertificate' ) . '</th>';
		echo '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'ffcertificate' ) . '</span></th>';
		echo '</tr></thead><tbody>';

		foreach ( $roster['rows'] as $row ) {
			$user_id = $row['user_id'];
			echo '<tr data-user-id="' . esc_attr( (string) $user_id ) . '">';
			echo '<td>' . esc_html( $row['name'] ) . '</td>';
			echo '<td>' . ( $row['met'] ? '<span class="ffc-attendance-badge ffc-attendance-badge-open">' . esc_html__( 'Met', 'ffcertificate' ) . '</span>' : '—' ) . '</td>';
			echo '<td>' . esc_html( self::invite_cell( $invited[ $user_id ] ?? null ) ) . '</td>';
			echo '<td>' . esc_html( self::answer_label( $answers[ $user_id ] ?? null ) ) . '</td>';
			echo '<td class="ffc-attendance-row-actions">';
			if ( $open && ! isset( $answers[ $user_id ] ) && true === AudiencePostEventForm::eligibility( $booking, $user_id, $now ) ) {
				AdminUI::render_button(
					array(
						'label' => isset( $invited[ $user_id ] ) ? __( 'Resend', 'ffcertificate' ) : __( 'Send', 'ffcertificate' ),
						'icon'  => 'send',
						'type'  => 'submit',
						'name'  => 'invite_user',
						'value' => (string) $user_id,
					)
				);
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		if ( $open ) {
			echo '<p>';
			AdminUI::render_button(
				array(
					'label' => __( 'Send to everyone who has not answered', 'ffcertificate' ),
					'icon'  => 'send',
					'type'  => 'submit',
					'name'  => 'invite_all',
					'value' => '1',
				)
			);
			echo '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Invitations go out on their own once the event ends, and can be sent again here while the form is open.', 'ffcertificate' ) . '</p>';
		}
		echo '</form>';
	}

	/**
	 * The "Invited" cell.
	 *
	 * @param array{sent_at: int, send_count: int}|null $invite The record, if any.
	 * @return string
	 */
	public static function invite_cell( ?array $invite ): string {
		if ( null === $invite ) {
			return __( 'Not yet', 'ffcertificate' );
		}
		if ( $invite['send_count'] > 1 ) {
			/* translators: 1: date and time of the last send, 2: number of sends */
			return sprintf( __( '%1$s (sent %2$d times)', 'ffcertificate' ), DateFormatter::format_datetime( $invite['sent_at'] ), $invite['send_count'] );
		}
		return DateFormatter::format_datetime( $invite['sent_at'] );
	}

	/**
	 * The "Answer" cell: nothing yet, answered, or the certificate issued.
	 *
	 * @param string|null $status Submission status, if any.
	 * @return string
	 */
	public static function answer_label( ?string $status ): string {
		if ( null === $status ) {
			return __( 'Not yet', 'ffcertificate' );
		}
		return 'publish' === $status ? __( 'Certificate issued', 'ffcertificate' ) : __( 'Answered', 'ffcertificate' );
	}

	/**
	 * `data-*` attributes for a hand-written control.
	 *
	 * @param array<string, string> $data Name (without `data-`) => value.
	 * @return string Escaped attributes, each with a leading space.
	 */
	private static function data_attrs( array $data ): string {
		$out = '';
		foreach ( $data as $name => $value ) {
			$out .= ' data-' . esc_attr( $name ) . '="' . esc_attr( $value ) . '"';
		}
		return $out;
	}

	/**
	 * One sentence saying which form applies to a booking and when it is open.
	 *
	 * @param object                                                      $booking Booking row.
	 * @param array{form_id: int, window_days: int, attendees_only: bool} $applied Settings in force.
	 * @param bool                                                        $custom  Whether they are the booking's own.
	 * @return string
	 */
	public static function post_event_status( object $booking, array $applied, bool $custom ): string {
		if ( ! AudiencePostEventForm::is_form( $applied['form_id'] ) ) {
			return $custom
				? __( 'This booking invites nobody to a form.', 'ffcertificate' )
				: __( 'Its calendar names no form, so this booking invites nobody.', 'ffcertificate' );
		}

		$opens  = AudiencePostEventForm::opens_at( $booking );
		$closes = AudiencePostEventForm::closes_at( $booking, $applied['window_days'] );

		return sprintf(
			/* translators: 1: form title, 2: date and time the form opens, 3: date and time it closes */
			__( 'Participants are invited to "%1$s", open from %2$s to %3$s.', 'ffcertificate' ),
			get_the_title( $applied['form_id'] ),
			DateFormatter::format_datetime( $opens ),
			DateFormatter::format_datetime( $closes )
		);
	}

	/**
	 * The participants card: who is present on the day shown, and by hand.
	 *
	 * @param object               $booking Booking row.
	 * @param string               $date    Day shown.
	 * @param string               $today   Today.
	 * @param array<string, mixed> $roster  Roster.
	 * @phpstan-param array{rows: list<array{user_id: int, name: string, present_at: int|null, method: string|null, days: list<string>, met: bool}>, present: int, total: int, required: int, days: list<string>} $roster
	 * @return void
	 */
	private function render_participants_card( object $booking, string $date, string $today, array $roster ): void {
		$booking_id = (int) ( $booking->id ?? 0 );
		$multi      = count( $roster['days'] ) > 1;
		$can_mark   = $date <= $today;

		echo '<div class="card ffc-attendance-participants" data-booking-id="' . esc_attr( (string) $booking_id ) . '" data-date="' . esc_attr( $date ) . '">';
		echo '<h2 class="ffc-icon-users">' . esc_html__( 'Participants', 'ffcertificate' ) . '</h2>';
		/* translators: 1: people present, 2: people expected, 3: the day */
		echo '<p class="ffc-attendance-count">' . wp_kses_post( sprintf( __( '%1$s of %2$s present on %3$s', 'ffcertificate' ), '<strong class="ffc-attendance-present">' . (int) $roster['present'] . '</strong>', '<strong>' . (int) $roster['total'] . '</strong>', esc_html( DateFormatter::format_wallclock_date( $date ) ) ) ) . '</p>';

		if ( Capabilities::current_user_can_admin_or( 'ffc_export_audiences' ) ) {
			echo '<form method="post" class="ffc-attendance-export">' . $this->form_fields( $booking_id, 'export', $date ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts by form_fields().
			AdminUI::render_button(
				array(
					'label' => __( 'Export CSV', 'ffcertificate' ),
					'icon'  => 'download',
					'type'  => 'submit',
				)
			);
			echo '</form>';
		}

		if ( array() === $roster['rows'] ) {
			echo '<p>' . esc_html__( 'This booking has no participants.', 'ffcertificate' ) . '</p></div>';
			return;
		}

		echo '<form method="post">' . $this->form_fields( $booking_id, 'mark', $date ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts by form_fields().
		echo '<table class="widefat striped ffc-attendance-table"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Name', 'ffcertificate' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'This day', 'ffcertificate' ) . '</th>';
		if ( $multi ) {
			echo '<th scope="col">' . esc_html__( 'Days present', 'ffcertificate' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Rule', 'ffcertificate' ) . '</th>';
		}
		echo '<th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'ffcertificate' ) . '</span></th>';
		echo '</tr></thead><tbody>';

		foreach ( $roster['rows'] as $row ) {
			$present = null !== $row['present_at'];
			echo '<tr data-user-id="' . esc_attr( (string) $row['user_id'] ) . '">';
			echo '<td>' . esc_html( $row['name'] ) . '</td>';
			echo '<td class="ffc-attendance-cell">' . self::status_html( $row['present_at'], $row['method'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts by status_html().
			if ( $multi ) {
				echo '<td class="ffc-attendance-days">' . esc_html( count( $row['days'] ) . ' / ' . $roster['required'] ) . '</td>';
				echo '<td class="ffc-attendance-met">' . ( $row['met'] ? '<span class="ffc-attendance-badge ffc-attendance-badge-open">' . esc_html__( 'Met', 'ffcertificate' ) . '</span>' : '—' ) . '</td>';
			}
			echo '<td class="ffc-attendance-row-actions">';
			if ( $can_mark ) {
				AdminUI::render_button(
					$present
						? array(
							'label'    => __( 'Remove', 'ffcertificate' ),
							'category' => 'danger',
							'icon'     => 'user-minus',
							'type'     => 'submit',
							'name'     => 'unmark_user',
							'value'    => (string) $row['user_id'],
							'class'    => 'ffc-attendance-unmark',
						)
						: array(
							'label'    => __( 'Mark present', 'ffcertificate' ),
							'category' => 'create',
							'icon'     => 'user-check',
							'type'     => 'submit',
							'name'     => 'mark_user',
							'value'    => (string) $row['user_id'],
							'class'    => 'ffc-attendance-mark',
						)
				);
			}
			echo '</td></tr>';
		}

		echo '</tbody></table></form></div>';
	}

	/**
	 * A day's status cell.
	 *
	 * @param int|null    $present_at When recorded.
	 * @param string|null $method     How.
	 * @return string
	 */
	public static function status_html( ?int $present_at, ?string $method ): string {
		if ( null === $present_at ) {
			return '<span class="ffc-attendance-absent">—</span>';
		}

		$how = AudienceAttendanceWriter::METHOD_MANUAL === $method ? __( 'by hand', 'ffcertificate' ) : __( 'QR', 'ffcertificate' );

		return '<span class="ffc-attendance-badge ffc-attendance-badge-open">' . esc_html( DateFormatter::format_time( $present_at ) ) . '</span> <span class="ffc-attendance-method">' . esc_html( $how ) . '</span>';
	}

	/**
	 * The projected QR: a document of its own, sized for a projector.
	 *
	 * The first code is drawn on the server so the page works before its
	 * script runs; the script then swaps the code at each rotation boundary.
	 *
	 * @param object $booking Booking row.
	 * @return void
	 */
	private function render_projection( object $booking ): void {
		$date  = AudienceBookingRules::today();
		$state = AudienceAttendanceAjax::state( $booking, $date, true, time() );

		nocache_headers();
		AssetHelper::enqueue_common_style();
		AssetHelper::enqueue_dark_mode();
		$this->enqueue_screen_assets( $booking, $date, true );

		echo '<!DOCTYPE html><html ' . get_language_attributes() . '><head>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_language_attributes() returns a safe attribute string.
		echo '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">';
		echo '<title>' . esc_html__( 'Check-in', 'ffcertificate' ) . ' — ' . esc_html( (string) ( $booking->description ?? '' ) ) . '</title>';
		wp_print_styles();
		echo '</head><body class="ffc-attendance-projection">';
		echo '<main class="ffc-attendance-stage" data-booking-id="' . esc_attr( (string) ( $booking->id ?? 0 ) ) . '" data-date="' . esc_attr( $date ) . '">';
		echo '<h1 class="ffc-attendance-stage-title">' . esc_html( (string) ( $booking->description ?? '' ) ) . '</h1>';

		if ( empty( $state['open'] ) ) {
			echo '<p class="ffc-attendance-stage-closed" role="status">' . esc_html__( 'Check-in is closed.', 'ffcertificate' ) . '</p>';
		}

		echo '<div class="ffc-attendance-qr" aria-label="' . esc_attr__( 'Check-in QR code', 'ffcertificate' ) . '" role="img">' . wp_kses( (string) ( $state['qr'] ?? '' ), self::svg_kses() ) . '</div>';
		echo '<p class="ffc-attendance-stage-hint">' . esc_html__( 'Scan with your phone camera to check in.', 'ffcertificate' ) . '</p>';
		/* translators: 1: people present, 2: people expected */
		echo '<p class="ffc-attendance-stage-count">' . wp_kses_post( sprintf( __( '%1$s of %2$s present', 'ffcertificate' ), '<strong class="ffc-attendance-present">' . (int) $state['present'] . '</strong>', '<strong>' . (int) $state['total'] . '</strong>' ) ) . '</p>';
		echo '<progress class="ffc-attendance-timer" aria-hidden="true" max="' . esc_attr( (string) ( $state['rotation'] ?? 60 ) ) . '" value="' . esc_attr( (string) ( $state['seconds_remaining'] ?? 0 ) ) . '"></progress>';
		echo '</main>';
		wp_print_footer_scripts();
		echo '</body></html>';
	}

	/**
	 * What a server-drawn QR SVG may carry.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function svg_kses(): array {
		$shape = array(
			'd'               => true,
			'fill'            => true,
			'x'               => true,
			'y'               => true,
			'width'           => true,
			'height'          => true,
			'rx'              => true,
			'ry'              => true,
			'cx'              => true,
			'cy'              => true,
			'r'               => true,
			'transform'       => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'shape-rendering' => true,
			'fill-rule'       => true,
			'class'           => true,
		);

		return array(
			'svg'    => array(
				'xmlns'               => true,
				'viewbox'             => true,
				'width'               => true,
				'height'              => true,
				'role'                => true,
				'aria-hidden'         => true,
				'shape-rendering'     => true,
				'preserveaspectratio' => true,
			),
			'g'      => $shape,
			'path'   => $shape,
			'rect'   => $shape,
			'circle' => $shape,
		);
	}

	/**
	 * Enqueue the screen's assets when the current admin request is it.
	 *
	 * @return void
	 */
	public function maybe_enqueue(): void {
		if ( $this->slug() !== RequestInput::get_get_key( 'page' ) ) {
			return;
		}
		$booking = AudienceBookingReader::get_by_id( RequestInput::get_get_int( 'booking_id' ) );
		if ( null === $booking ) {
			return;
		}
		$date = self::resolve_day( AudienceAttendanceRule::counted_days( $booking ), RequestInput::get_get_string( 'date' ), AudienceBookingRules::today() );
		$this->enqueue_screen_assets( $booking, $date, false );
	}

	/**
	 * Enqueue the screen's sheet and script.
	 *
	 * @param object $booking    Booking row.
	 * @param string $date       Day.
	 * @param bool   $projection Whether this is the projected view.
	 * @return void
	 */
	public function enqueue_screen_assets( object $booking, string $date, bool $projection ): void {
		$s = AssetHelper::asset_suffix();

		wp_enqueue_style( 'ffc-audience-attendance', FFC_PLUGIN_URL . "assets/css/ffc-audience-attendance{$s}.css", array( 'ffc-common' ), FFC_VERSION );
		wp_enqueue_script( 'ffc-audience-attendance', FFC_PLUGIN_URL . "assets/js/ffc-audience-attendance{$s}.js", array(), FFC_VERSION, true );
		if ( ! $projection && Capabilities::current_user_can_admin_or( 'ffc_manage_audiences' ) ) {
			RecordAutosave::enqueue();
		}
		wp_localize_script(
			'ffc-audience-attendance',
			'ffcAttendance',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'action'     => AudienceAttendanceAjax::ACTION,
				'nonce'      => wp_create_nonce( AudienceAttendanceAjax::NONCE ),
				'bookingId'  => (int) ( $booking->id ?? 0 ),
				'date'       => $date,
				'projection' => $projection,
				'strings'    => array(
					'byHand' => __( 'by hand', 'ffcertificate' ),
					'qr'     => __( 'QR', 'ffcertificate' ),
					'closed' => __( 'Check-in is closed.', 'ffcertificate' ),
				),
			)
		);
	}
}

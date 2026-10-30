<?php
/**
 * Audience Notification Handler
 *
 * Handles email notifications for audience booking system.
 * Sends notifications to affected users when bookings are created or cancelled.
 *
 * Features:
 * - Email to all affected users (via audiences or individual selection)
 * - Email to booking creator
 * - Optional .ics calendar file attachment
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
 * Handler for audience notification operations.
 *
 * @phpstan-import-type ScheduleRow from AudienceScheduleRepository
 * @phpstan-import-type BookingRow from AudienceBookingReader
 */
class AudienceNotificationHandler {

	/**
	 * The single event that sends one batch of a booking's e-mails (#2243).
	 */
	public const BATCH_HOOK = 'ffc_audience_notification_batch';

	/**
	 * Participants e-mailed per batch. One `wp_mail()` each, which Total Mail
	 * Queue, when active, only queues; the batch exists so a request never
	 * builds thousands of messages and their `.ics` files at once.
	 */
	public const BATCH_SIZE = 50;

	/**
	 * Initialize notification hooks
	 *
	 * A booking created, cancelled or overridden only schedules its e-mails
	 * (#2243): sending one per member inside the request timed out on shared
	 * hosting when the booking was for a large audience. The batches run on
	 * WP-Cron, so this registration must not sit behind `is_admin()`.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'ffcertificate_audience_booking_created', array( __CLASS__, 'queue_booking_created' ) );
		add_action( 'ffcertificate_audience_booking_cancelled', array( __CLASS__, 'queue_booking_cancelled' ), 10, 2 );
		add_action( 'ffcertificate_audience_booking_overridden', array( __CLASS__, 'queue_booking_overridden' ), 10, 4 );
		add_action( self::BATCH_HOOK, array( __CLASS__, 'run_batch' ) );
	}

	/**
	 * Queue the e-mails of a booking just created.
	 *
	 * @param int $booking_id Booking ID.
	 * @return void
	 */
	public static function queue_booking_created( int $booking_id ): void {
		self::schedule_batch(
			array(
				'kind'       => 'created',
				'booking_id' => $booking_id,
			)
		);
	}

	/**
	 * Queue the e-mails of a booking just cancelled.
	 *
	 * @param int    $booking_id Booking ID.
	 * @param string $reason     Cancellation reason.
	 * @return void
	 */
	public static function queue_booking_cancelled( int $booking_id, string $reason ): void {
		self::schedule_batch(
			array(
				'kind'       => 'cancelled',
				'booking_id' => $booking_id,
				'reason'     => $reason,
			)
		);
	}

	/**
	 * Queue the e-mails of a booking that gave way to an override. The
	 * operator is carried in the job, since the batch runs with nobody
	 * signed in and a booking that lost only some days is not itself
	 * cancelled by anyone.
	 *
	 * @param int      $booking_id     The booking that gave way.
	 * @param int      $new_booking_id The booking that took the slot.
	 * @param string   $reason         The operator's reason.
	 * @param string[] $lost_days      The days it lost; empty when cancelled whole.
	 * @return void
	 */
	public static function queue_booking_overridden( int $booking_id, int $new_booking_id, string $reason, array $lost_days = array() ): void {
		self::schedule_batch(
			array(
				'kind'           => 'overridden',
				'booking_id'     => $booking_id,
				'new_booking_id' => $new_booking_id,
				'reason'         => $reason,
				'lost_days'      => array_values( array_map( 'strval', $lost_days ) ),
				'actor'          => get_current_user_id(),
			)
		);
	}

	/**
	 * Send one batch of a queued job and schedule the next when people remain.
	 *
	 * The cursor is the last participant id the batch reached, sent or not,
	 * so a failing address or a user deleted meanwhile never holds the job:
	 * it advances and the job ends when no id lies beyond it.
	 *
	 * @param array<string, mixed> $job The job {@see self::schedule_batch()} queued.
	 * @return void
	 */
	public static function run_batch( array $job ): void {
		$booking_id = (int) ( $job['booking_id'] ?? 0 );
		$after      = (int) ( $job['after'] ?? 0 );
		$reason     = (string) ( $job['reason'] ?? '' );
		$lost_days  = isset( $job['lost_days'] ) && is_array( $job['lost_days'] ) ? array_values( array_map( 'strval', $job['lost_days'] ) ) : array();
		$actor      = (int) ( $job['actor'] ?? 0 );

		switch ( $job['kind'] ?? '' ) {
			case 'created':
				$next = self::send_booking_created_notification( $booking_id, $after );
				break;
			case 'cancelled':
				$next = self::send_booking_cancelled_notification( $booking_id, $reason, array(), array(), $after );
				break;
			case 'overridden':
				$next = self::send_booking_overridden_notification( $booking_id, (int) ( $job['new_booking_id'] ?? 0 ), $reason, $lost_days, $after, $actor );
				break;
			default:
				$next = null;
		}

		if ( null !== $next ) {
			$job['after'] = $next;
			self::schedule_batch( $job );
		}
	}

	/**
	 * Schedule a batch to run on the next WP-Cron tick.
	 *
	 * @param array<string, mixed> $job Job: `kind`, `booking_id`, and per kind `reason`, `new_booking_id`, `lost_days`, `actor`, `after`.
	 * @return void
	 */
	private static function schedule_batch( array $job ): void {
		$job['after'] = (int) ( $job['after'] ?? 0 );
		$args         = array( $job );
		if ( false !== wp_next_scheduled( self::BATCH_HOOK, $args ) ) {
			return;
		}
		wp_schedule_single_event( time(), self::BATCH_HOOK, $args );
	}

	/**
	 * The ids of one batch: the first {@see self::BATCH_SIZE} past the
	 * cursor, and the cursor that follows them, or NULL when none remain.
	 *
	 * @param int[] $user_ids Every recipient.
	 * @param int   $after    The last id an earlier batch reached.
	 * @return array{0: list<int>, 1: int|null}
	 */
	private static function slice_batch( array $user_ids, int $after ): array {
		$ids = array_values( array_unique( array_map( 'intval', $user_ids ) ) );
		sort( $ids );
		$pending = array_values( array_filter( $ids, static fn( int $id ): bool => $id > $after ) );
		$batch   = array_slice( $pending, 0, self::BATCH_SIZE );
		$next    = count( $pending ) > self::BATCH_SIZE ? (int) end( $batch ) : null;
		return array( $batch, $next );
	}

	/**
	 * Send one batch of the e-mails of a booking just created.
	 *
	 * The administrators' notice goes with the first batch only. A booking
	 * cancelled before its batch ran is not announced.
	 *
	 * @param int $booking_id Booking ID.
	 * @param int $after      The last participant id an earlier batch reached.
	 * @return int|null The cursor of the next batch, or NULL when done.
	 */
	public static function send_booking_created_notification( int $booking_id, int $after = 0 ): ?int {
		$booking = AudienceBookingReader::get_by_id( $booking_id );
		if ( ! $booking || 'active' !== ( $booking->status ?? 'active' ) ) {
			return null;
		}

		$environment = AudienceEnvironmentRepository::get_by_id( (int) $booking->environment_id );
		if ( ! $environment ) {
			return null;
		}

		$schedule = AudienceScheduleRepository::get_by_id( (int) $environment->schedule_id );
		if ( ! $schedule ) {
			return null;
		}

		// Get booking creator info.
		$creator      = get_user_by( 'id', $booking->created_by );
		$creator_name = $creator ? $creator->display_name : __( 'Unknown', 'ffcertificate' );

		// Prepare booking data (shared by the admin and user notifications).
		$environment_label = AudienceScheduleRepository::get_environment_label( $schedule, true );
		$booking_data      = array(
			'booking_id'        => $booking_id,
			'environment_name'  => $environment->name,
			'environment_label' => $environment_label,
			'schedule_name'     => $schedule->name,
			'booking_date'      => self::booking_date_label( $booking ),
			'booking_date_raw'  => $booking->booking_date,
			'end_date_raw'      => $booking->end_date ?? null,
			'time_mode'         => $booking->time_mode ?? null,
			'skipped_raw'       => $booking->skipped_dates ?? null,
			'start_time'        => self::format_time( $booking->start_time ),
			'end_time'          => self::format_time( $booking->end_time ),
			'time'              => \FreeFormCertificate\Audience\AudienceBookingRules::time_label( (string) $booking->start_time, (string) $booking->end_time, \FreeFormCertificate\Audience\AudienceBookingRules::is_span( $booking ), static fn( string $time ): string => self::format_time( $time ) ),
			'start_time_raw'    => $booking->start_time,
			'end_time_raw'      => $booking->end_time,
			'description'       => $booking->description,
			'creator_name'      => $creator_name,
		);

		// Get audiences for the booking.
		$audiences                 = AudienceBookingReader::get_booking_audiences( $booking_id );
		$audience_names            = array_map(
			function ( $a ) {
				return $a->name;
			},
			$audiences
		);
		$booking_data['audiences'] = implode( ', ', $audience_names );

		// Admin notification — opt-in (default off), independent of the
		// per-schedule user toggle and of whether any users are affected.
		if ( 0 === $after ) {
			self::maybe_notify_admin_of_booking( $schedule, $booking_data );
		}

		// User notifications — gated on the per-schedule user toggle.
		if ( ! $schedule->notify_on_booking ) {
			return null;
		}

		list( $affected_users, $next ) = self::slice_batch( AudienceBookingReader::get_all_affected_users( $booking_id ), $after );
		if ( empty( $affected_users ) ) {
			return null;
		}

		// Generate ICS if enabled.
		$ics_content = null;
		if ( $schedule->include_ics ) {
			$ics_content = self::generate_ics( $booking_data, 'created' );
		}

		// Get email template or use default.
		$template = $schedule->email_template_booking ? $schedule->email_template_booking : self::get_default_booking_template();

		// Send email to each affected user.
		foreach ( $affected_users as $user_id ) {
			$user = get_user_by( 'id', $user_id );
			if ( ! $user || ! $user->user_email ) {
				continue;
			}

			self::send_email(
				$user->user_email,
				self::render_subject( \FreeFormCertificate\Core\EmailTemplates::effective_body( 'audience-booking', 'subject' ), $booking_data ),
				self::render_template( $template, $booking_data, $user ),
				$ics_content
			);
		}

		return $next;
	}

	/**
	 * Send one batch of the e-mails of a cancelled booking.
	 *
	 * The administrators' notice goes with the first batch only.
	 *
	 * @param int        $booking_id       Booking ID.
	 * @param string     $reason           Cancellation reason.
	 * @param array<int> $exclude_user_ids Users told some other way, skipped here.
	 * @param string[]   $lost_days        Only these days were cancelled (#2243); empty for the whole booking.
	 * @param int        $after            The last participant id an earlier batch reached.
	 * @param int        $actor            Who took the days, when only some were cancelled.
	 * @return int|null The cursor of the next batch, or NULL when done.
	 */
	public static function send_booking_cancelled_notification( int $booking_id, string $reason, array $exclude_user_ids = array(), array $lost_days = array(), int $after = 0, int $actor = 0 ): ?int {
		$context = self::cancelled_booking_context( $booking_id, $reason, $lost_days, $actor );
		if ( null === $context ) {
			return null;
		}
		$schedule     = $context['schedule'];
		$booking_data = $context['data'];

		// Admin notification — opt-in (default off), independent of the
		// per-schedule user toggle and of whether any users are affected.
		if ( 0 === $after ) {
			self::maybe_notify_admin_of_cancellation( $schedule, $booking_data );
		}

		// User notifications — gated on the per-schedule user toggle.
		if ( ! $schedule->notify_on_cancellation ) {
			return null;
		}

		list( $affected_users, $next ) = self::slice_batch( AudienceBookingReader::get_booking_users( (int) $booking_id ), $after );
		if ( empty( $affected_users ) ) {
			return null;
		}

		// Generate ICS cancellation if enabled. A booking that lost only some
		// days is still on: a cancellation .ics would take all of it off the
		// participant's calendar.
		$ics_content = null;
		if ( $schedule->include_ics && empty( $lost_days ) ) {
			$ics_content = self::generate_ics( $booking_data, 'cancelled' );
		}

		// Get email template or use default.
		$template = $schedule->email_template_cancellation ? $schedule->email_template_cancellation : self::get_default_cancellation_template();

		$exclude = array_map( 'intval', $exclude_user_ids );

		// Send email to each affected user.
		foreach ( $affected_users as $user_id ) {
			if ( in_array( (int) $user_id, $exclude, true ) ) {
				continue;
			}
			$user = get_user_by( 'id', (int) $user_id );
			if ( ! $user || ! $user->user_email ) {
				continue;
			}

			self::send_email(
				$user->user_email,
				self::render_subject( \FreeFormCertificate\Core\EmailTemplates::effective_body( 'audience-cancellation', 'subject' ), $booking_data ),
				self::render_template( $template, $booking_data, $user ),
				$ics_content
			);
		}

		return $next;
	}

	/**
	 * Tell the owner of a booking that an administrator's booking took its
	 * slot (#1859).
	 *
	 * The owner always receives the "cancelled by an administrator" e-mail —
	 * whatever the calendar's own cancellation toggle says, because they did
	 * not cancel it and would otherwise not know. The people the booking was
	 * for then receive the calendar's ordinary cancellation notice, under its
	 * toggle, the owner excepted so nobody gets both.
	 *
	 * A booking that gave way only on the days that clashed (#2243) is told
	 * the same way, the e-mails naming those days and saying the rest of it
	 * stands, with no cancellation `.ics`.
	 *
	 * @param int      $booking_id     The booking that gave way.
	 * @param int      $new_booking_id The booking that took the slot (carried for other listeners; the e-mail does not name it).
	 * @param string   $reason         The operator's reason.
	 * @param string[] $lost_days      The days it lost (Y-m-d); empty when it was cancelled whole.
	 * @param int      $after          The last participant id an earlier batch reached; the owner is told with the first batch.
	 * @param int      $actor          Who took the days, when only some were cancelled.
	 * @return int|null The cursor of the next batch, or NULL when done.
	 */
	public static function send_booking_overridden_notification( int $booking_id, int $new_booking_id, string $reason, array $lost_days = array(), int $after = 0, int $actor = 0 ): ?int {
		$context = self::cancelled_booking_context( $booking_id, $reason, $lost_days, $actor );
		if ( null === $context ) {
			return null;
		}

		$owner_id = (int) $context['booking']->created_by;
		$owner    = 0 === $after && $owner_id > 0 ? get_user_by( 'id', $owner_id ) : false;

		if ( $owner && $owner->user_email ) {
			$booking_data = $context['data'];
			$ics_content  = $context['schedule']->include_ics && empty( $lost_days ) ? self::generate_ics( $booking_data, 'cancelled' ) : null;

			self::send_email(
				$owner->user_email,
				self::render_subject( \FreeFormCertificate\Core\EmailTemplates::effective_body( 'audience-admin-cancellation', 'subject' ), $booking_data ),
				self::render_template( \FreeFormCertificate\Core\EmailTemplates::effective_body( 'audience-admin-cancellation' ), $booking_data, $owner ),
				$ics_content
			);
		}

		return self::send_booking_cancelled_notification( $booking_id, $reason, $owner_id > 0 ? array( $owner_id ) : array(), $lost_days, $after, $actor );
	}

	/**
	 * Load a cancelled booking with its space and calendar, and the token
	 * values its e-mails read.
	 *
	 * When only some days were cancelled, the date names those days, the
	 * reason says the rest of the booking stands, and "cancelled by" is the
	 * operator, since the row itself was not cancelled.
	 *
	 * @param int      $booking_id Booking ID.
	 * @param string   $reason     Cancellation reason.
	 * @param string[] $lost_days  The days cancelled (Y-m-d); empty for the whole booking.
	 * @param int      $actor      Who took the days, when only some were cancelled; the signed-in user when 0.
	 * @return array{booking: BookingRow, schedule: ScheduleRow, data: array<string, mixed>}|null
	 */
	private static function cancelled_booking_context( int $booking_id, string $reason, array $lost_days = array(), int $actor = 0 ): ?array {
		$booking = AudienceBookingReader::get_by_id( $booking_id );
		if ( ! $booking ) {
			return null;
		}

		$environment = AudienceEnvironmentRepository::get_by_id( (int) $booking->environment_id );
		if ( ! $environment ) {
			return null;
		}

		$schedule = AudienceScheduleRepository::get_by_id( (int) $environment->schedule_id );
		if ( ! $schedule ) {
			return null;
		}

		// Get cancelled by info.
		$cancelled_by_id   = empty( $lost_days ) ? (int) $booking->cancelled_by : ( $actor > 0 ? $actor : get_current_user_id() );
		$cancelled_by      = $cancelled_by_id > 0 ? get_user_by( 'id', $cancelled_by_id ) : false;
		$cancelled_by_name = $cancelled_by ? $cancelled_by->display_name : __( 'Unknown', 'ffcertificate' );

		$date_label = self::booking_date_label( $booking );
		if ( ! empty( $lost_days ) ) {
			$date_label = implode( ', ', array_map( static fn( string $day ): string => self::format_date( $day ), $lost_days ) );
			/* translators: %s: the operator's reason for taking some days of a booking. */
			$reason = sprintf( __( '%s Only the days listed were cancelled; the rest of the booking stands.', 'ffcertificate' ), $reason );
		}

		// Prepare booking data.
		$environment_label = AudienceScheduleRepository::get_environment_label( $schedule, true );
		$booking_data      = array(
			'booking_id'          => $booking_id,
			'environment_name'    => $environment->name,
			'environment_label'   => $environment_label,
			'schedule_name'       => $schedule->name,
			'booking_date'        => $date_label,
			'booking_date_raw'    => $booking->booking_date,
			'end_date_raw'        => $booking->end_date ?? null,
			'time_mode'           => $booking->time_mode ?? null,
			'skipped_raw'         => $booking->skipped_dates ?? null,
			'start_time'          => self::format_time( $booking->start_time ),
			'end_time'            => self::format_time( $booking->end_time ),
			'time'                => \FreeFormCertificate\Audience\AudienceBookingRules::time_label( (string) $booking->start_time, (string) $booking->end_time, \FreeFormCertificate\Audience\AudienceBookingRules::is_span( $booking ), static fn( string $time ): string => self::format_time( $time ) ),
			'start_time_raw'      => $booking->start_time,
			'end_time_raw'        => $booking->end_time,
			'description'         => $booking->description,
			'cancelled_by_name'   => $cancelled_by_name,
			'cancellation_reason' => $reason,
		);

		// Get audiences for the booking.
		$audiences                 = AudienceBookingReader::get_booking_audiences( $booking_id );
		$audience_names            = array_map(
			function ( $a ) {
				return $a->name;
			},
			$audiences
		);
		$booking_data['audiences'] = implode( ', ', $audience_names );

		return array(
			'booking'  => $booking,
			'schedule' => $schedule,
			'data'     => $booking_data,
		);
	}

	/**
	 * Send email with optional ICS attachment
	 *
	 * @param string      $to Recipient email.
	 * @param string      $subject Email subject.
	 * @param string      $body Email body (HTML).
	 * @param string|null $ics_content ICS file content.
	 * @return bool
	 */
	private static function send_email( string $to, string $subject, string $body, ?string $ics_content = null ): bool {
		$attachments = array();
		$temp_files  = array();

		// Create temporary ICS file in the system temp dir (not the publicly-accessible uploads dir).
		if ( $ics_content ) {
			$ics_file = function_exists( 'wp_tempnam' )
				? wp_tempnam( 'ffc-ics-' )
				: tempnam( sys_get_temp_dir(), 'ffc-ics-' );

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes the generated .ics to a wp_tempnam() path for the mail attachment; WP_Filesystem would need credentials on a mail-send path.
			if ( $ics_file && false !== file_put_contents( $ics_file, $ics_content ) ) {
				$attachments[] = $ics_file;
				$temp_files[]  = $ics_file;
			}
		}

		// Delegate to shared email service. Use try/finally so temp files are always cleaned up,
		// even if the email service throws.
		try {
			$result = \FreeFormCertificate\Scheduling\SchedulingMailer::send( $to, $subject, $body, $attachments, true, \FreeFormCertificate\Core\EmailSource::AUDIENCE );
		} finally {
			foreach ( $temp_files as $file ) {
				if ( file_exists( $file ) ) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes the temp .ics this method created, in its own finally block.
					unlink( $file );
				}
			}
		}

		return $result;
	}

	/**
	 * Generate ICS calendar file content
	 *
	 * @param array<string, mixed> $booking_data Booking data.
	 * @param string               $action Action type (created, cancelled).
	 * @return string ICS content
	 */
	private static function generate_ics( array $booking_data, string $action ): string {
		$summary = $booking_data['description'];
		if ( 'cancelled' === $action ) {
			$summary = '[' . __( 'CANCELLED', 'ffcertificate' ) . '] ' . $summary;
		}

		$method = ( 'cancelled' === $action ) ? 'CANCEL' : 'REQUEST';

		return \FreeFormCertificate\Scheduling\IcsGenerator::generate(
			array_merge(
				array(
					'uid'         => 'ffc-booking-' . $booking_data['booking_id'],
					'summary'     => $summary,
					'description' => $booking_data['description'],
					'location'    => $booking_data['environment_name'],
					'start_time'  => $booking_data['start_time_raw'],
					'end_time'    => $booking_data['end_time_raw'],
				),
				\FreeFormCertificate\Audience\AudienceBookingRules::ics_fields(
					(string) $booking_data['booking_date_raw'],
					isset( $booking_data['end_date_raw'] ) ? (string) $booking_data['end_date_raw'] : null,
					isset( $booking_data['time_mode'] ) ? (string) $booking_data['time_mode'] : null,
					isset( $booking_data['skipped_raw'] ) ? (string) $booking_data['skipped_raw'] : null
				)
			),
			$method
		);
	}

	/**
	 * The booking's dates for a message: one date, or `start – end` for a range.
	 *
	 * @param object $booking Booking row.
	 * @phpstan-param object{booking_date: string, end_date?: string|null} $booking
	 * @return string
	 */
	private static function booking_date_label( object $booking ): string {
		return \FreeFormCertificate\Audience\AudienceBookingRules::date_label(
			(string) $booking->booking_date,
			isset( $booking->end_date ) ? (string) $booking->end_date : null,
			static fn( string $date ): string => self::format_date( $date )
		);
	}

	// escape_ics_text() removed — ICS escaping lives in Scheduling\IcsGenerator (#653).

	/**
	 * Render email template with variables
	 *
	 * @param string               $template Template content.
	 * @param array<string, mixed> $booking_data Booking data.
	 * @param \WP_User             $user Recipient user.
	 * @return string Rendered template
	 */
	private static function render_template( string $template, array $booking_data, \WP_User $user ): string {
		$replacements = array(
			'{{user_name}}'           => \esc_html( (string) $user->display_name ),
			'{{user_email}}'          => \esc_html( (string) $user->user_email ),
			'{{environment_name}}'    => \esc_html( (string) $booking_data['environment_name'] ),
			'{{environment_label}}'   => \esc_html( (string) ( $booking_data['environment_label'] ?? __( 'Environment', 'ffcertificate' ) ) ),
			'{{schedule_name}}'       => \esc_html( (string) $booking_data['schedule_name'] ),
			'{{booking_date}}'        => \esc_html( (string) $booking_data['booking_date'] ),
			'{{start_time}}'          => \esc_html( (string) $booking_data['start_time'] ),
			'{{end_time}}'            => \esc_html( (string) $booking_data['end_time'] ),
			'{{time}}'                => \esc_html( (string) ( $booking_data['time'] ?? $booking_data['start_time'] . ' - ' . $booking_data['end_time'] ) ),
			'{{description}}'         => \esc_html( (string) $booking_data['description'] ),
			'{{audiences}}'           => \esc_html( (string) ( $booking_data['audiences'] ?? '' ) ),
			'{{creator_name}}'        => \esc_html( (string) ( $booking_data['creator_name'] ?? '' ) ),
			'{{cancelled_by_name}}'   => \esc_html( (string) ( $booking_data['cancelled_by_name'] ?? '' ) ),
			'{{cancellation_reason}}' => \esc_html( (string) ( $booking_data['cancellation_reason'] ?? '' ) ),
			'{{site_name}}'           => \esc_html( (string) get_bloginfo( 'name' ) ),
			'{{site_url}}'            => esc_url( home_url() ),
		);

		return \FreeFormCertificate\Core\TokenResolver::resolve( $template, $replacements );
	}

	/**
	 * Get default booking-created email body — the effective GLOBAL: the admin's
	 * SMTP email-body-hub override when set, else the shipped
	 * `templates/emails/audience-booking.php` default (#662, hub #964). A schedule
	 * with its own custom body still overrides this.
	 *
	 * @return string Template HTML
	 */
	private static function get_default_booking_template(): string {
		return \FreeFormCertificate\Core\EmailTemplates::effective_body( 'audience-booking' );
	}

	/**
	 * Get default booking-cancelled email body — the effective GLOBAL: the admin's
	 * SMTP email-body-hub override when set, else the shipped
	 * `templates/emails/audience-cancellation.php` default (#662, hub #964). A
	 * schedule with its own custom body still overrides this.
	 *
	 * @return string Template HTML
	 */
	private static function get_default_cancellation_template(): string {
		return \FreeFormCertificate\Core\EmailTemplates::effective_body( 'audience-cancellation' );
	}

	/**
	 * Resolve a hub-editable email SUBJECT template's tokens (#976). Unlike
	 * {@see self::render_template()} (which `esc_html`s for an HTML body), the
	 * subject is a plain-text mail header, so values are substituted raw. The
	 * subject is global-hub-only (a schedule customises the body, not the subject).
	 *
	 * @param string               $template     Subject template (with {{tokens}}).
	 * @param array<string, mixed> $booking_data Booking data.
	 * @return string Resolved subject.
	 */
	private static function render_subject( string $template, array $booking_data ): string {
		return \FreeFormCertificate\Core\TokenResolver::resolve(
			$template,
			array(
				'{{schedule_name}}'     => (string) ( $booking_data['schedule_name'] ?? '' ),
				'{{environment_name}}'  => (string) ( $booking_data['environment_name'] ?? '' ),
				'{{environment_label}}' => (string) ( $booking_data['environment_label'] ?? '' ),
				'{{booking_date}}'      => (string) ( $booking_data['booking_date'] ?? '' ),
				'{{site_name}}'         => (string) get_bloginfo( 'name' ),
			)
		);
	}

	/**
	 * Send the admin notification for a new booking when the schedule opted in.
	 *
	 * Independent of the per-schedule user toggle: an admin can be notified even
	 * when end users are not (or when the audience is empty).
	 *
	 * @param object               $schedule     Schedule row.
	 * @param array<string, mixed> $booking_data Prepared booking data.
	 * @phpstan-param ScheduleRow $schedule
	 * @return void
	 */
	private static function maybe_notify_admin_of_booking( object $schedule, array $booking_data ): void {
		if ( empty( $schedule->notify_admin_on_booking ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: 1: Environment name, 2: Date */
			__( '[Admin] New booking in %1$s - %2$s', 'ffcertificate' ),
			$booking_data['environment_name'],
			$booking_data['booking_date']
		);

		$body = self::admin_details_html(
			__( 'New audience booking', 'ffcertificate' ),
			array(
				__( 'Calendar', 'ffcertificate' )    => (string) $booking_data['schedule_name'],
				__( 'Environment', 'ffcertificate' ) => (string) $booking_data['environment_name'],
				__( 'Date', 'ffcertificate' )        => (string) $booking_data['booking_date'],
				__( 'Time', 'ffcertificate' )        => $booking_data['time'] ?? $booking_data['start_time'] . ' - ' . $booking_data['end_time'],
				__( 'Audiences', 'ffcertificate' )   => (string) ( $booking_data['audiences'] ?? '' ),
				__( 'Booked by', 'ffcertificate' )   => (string) ( $booking_data['creator_name'] ?? '' ),
				__( 'Description', 'ffcertificate' ) => (string) ( $booking_data['description'] ?? '' ),
			)
		);

		self::send_admin_email( $schedule, $subject, $body );
	}

	/**
	 * Send the admin notification for a cancellation when the schedule opted in.
	 *
	 * @param object               $schedule     Schedule row.
	 * @param array<string, mixed> $booking_data Prepared booking data.
	 * @phpstan-param ScheduleRow $schedule
	 * @return void
	 */
	private static function maybe_notify_admin_of_cancellation( object $schedule, array $booking_data ): void {
		if ( empty( $schedule->notify_admin_on_cancellation ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: 1: Environment name, 2: Date */
			__( '[Admin] Booking cancelled in %1$s - %2$s', 'ffcertificate' ),
			$booking_data['environment_name'],
			$booking_data['booking_date']
		);

		$body = self::admin_details_html(
			__( 'Audience booking cancelled', 'ffcertificate' ),
			array(
				__( 'Calendar', 'ffcertificate' )     => (string) $booking_data['schedule_name'],
				__( 'Environment', 'ffcertificate' )  => (string) $booking_data['environment_name'],
				__( 'Date', 'ffcertificate' )         => (string) $booking_data['booking_date'],
				__( 'Time', 'ffcertificate' )         => $booking_data['time'] ?? $booking_data['start_time'] . ' - ' . $booking_data['end_time'],
				__( 'Audiences', 'ffcertificate' )    => (string) ( $booking_data['audiences'] ?? '' ),
				__( 'Cancelled by', 'ffcertificate' ) => (string) ( $booking_data['cancelled_by_name'] ?? '' ),
				__( 'Reason', 'ffcertificate' )       => (string) ( $booking_data['cancellation_reason'] ?? '' ),
			)
		);

		self::send_admin_email( $schedule, $subject, $body );
	}

	/**
	 * Send an admin notification email to every configured recipient.
	 *
	 * @param object $schedule Schedule row.
	 * @param string $subject  Email subject.
	 * @param string $body     Inner HTML body (wrapped by SchedulingMailer).
	 * @phpstan-param ScheduleRow $schedule
	 * @return void
	 */
	private static function send_admin_email( object $schedule, string $subject, string $body ): void {
		foreach ( self::admin_recipients( $schedule ) as $recipient ) {
			\FreeFormCertificate\Scheduling\SchedulingMailer::send( $recipient, $subject, $body, array(), true, \FreeFormCertificate\Core\EmailSource::AUDIENCE );
		}
	}

	/**
	 * Resolve the admin-notification recipients for a schedule.
	 *
	 * Parses the comma-separated `admin_notification_emails`; when empty (or all
	 * invalid) falls back to the site admin email.
	 *
	 * @param object $schedule Schedule row.
	 * @phpstan-param ScheduleRow $schedule
	 * @return array<int, string>
	 */
	private static function admin_recipients( object $schedule ): array {
		$raw        = isset( $schedule->admin_notification_emails ) ? (string) $schedule->admin_notification_emails : '';
		$recipients = array();
		foreach ( explode( ',', $raw ) as $email ) {
			$email = trim( $email );
			if ( '' !== $email && is_email( $email ) ) {
				$recipients[] = $email;
			}
		}

		if ( empty( $recipients ) ) {
			$fallback = (string) get_option( 'admin_email' );
			if ( '' !== $fallback && is_email( $fallback ) ) {
				$recipients[] = $fallback;
			}
		}

		return $recipients;
	}

	/**
	 * Build a compact admin-notification HTML body from label/value rows.
	 *
	 * Inline-styled (Gmail/Outlook-safe) so it renders correctly inside the
	 * single configurable chrome (SchedulingMailer::send → ffc_email_document).
	 *
	 * @param string                $heading Section heading.
	 * @param array<string, string> $rows    Label => value pairs (escaped here).
	 * @return string Inner HTML (wrapped by the shared chrome at send).
	 */
	private static function admin_details_html( string $heading, array $rows ): string {
		$html  = '<h2>' . esc_html( $heading ) . '</h2>';
		$html .= '<div style="background:#f0f6fc;padding:15px;border-radius:4px;margin:20px 0;">';
		foreach ( $rows as $label => $value ) {
			$html .= '<div style="margin:8px 0;"><span style="font-weight:600;">' . esc_html( $label ) . ':</span> ' . esc_html( $value ) . '</div>';
		}
		$html .= '</div>';
		return $html;
	}

	/**
	 * Format a wall-clock booking date for display.
	 *
	 * `booking_date` is a Category B wall-clock DATE — render the literal value
	 * with no timezone conversion (passing it through the instant API would
	 * parse it as UTC midnight and re-apply wp_timezone(), rolling the date
	 * back a day on sub-UTC sites).
	 *
	 * @param string $date Wall-clock date string (Y-m-d).
	 */
	private static function format_date( string $date ): string {
		return \FreeFormCertificate\Core\DateFormatter::format_wallclock_date( $date );
	}

	/**
	 * Format a wall-clock booking time for display (Category B, no TZ shift).
	 *
	 * @param string $time Wall-clock time string (H:i:s).
	 */
	private static function format_time( string $time ): string {
		return \FreeFormCertificate\Core\DateFormatter::format_wallclock_time( $time );
	}
}

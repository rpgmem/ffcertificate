<?php
/**
 * Appointment Email Handler
 *
 * Handles email notifications for calendar appointments.
 * Supports: booking confirmation, admin notifications, approval, cancellation, reminders.
 *
 * @package FreeFormCertificate\SelfScheduling
 * @since 4.1.0
 * @version 4.1.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\SelfScheduling;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handler for appointment email operations.
 */
class AppointmentEmailHandler {

	use \FreeFormCertificate\Core\EmailHelperTrait;

	/**
	 * Constructor
	 */
	public function __construct() {
		// Hook into appointment events.
		add_action( 'ffcertificate_self_scheduling_appointment_created_email', array( $this, 'send_booking_confirmation' ), 10, 2 );
		add_action( 'ffcertificate_self_scheduling_appointment_admin_notification', array( $this, 'send_admin_notification' ), 10, 2 );
		add_action( 'ffcertificate_self_scheduling_appointment_confirmed_email', array( $this, 'send_approval_notification' ), 10, 2 );
		add_action( 'ffcertificate_self_scheduling_appointment_cancelled_email', array( $this, 'send_cancellation_notification' ), 10, 2 );
		add_action( 'ffcertificate_self_scheduling_appointment_reminder_email', array( $this, 'send_reminder' ), 10, 2 );
		// Waitlist lifecycle (#941 phase 2).
		add_action( 'ffcertificate_self_scheduling_appointment_waitlisted_email', array( $this, 'send_waitlist_notification' ), 10, 2 );
		add_action( 'ffcertificate_self_scheduling_appointment_promoted_email', array( $this, 'send_promotion_notification' ), 10, 2 );
	}

	/**
	 * Check if emails are globally disabled
	 *
	 * @return bool
	 */
	private function are_emails_disabled(): bool {
		return self::ffc_emails_disabled();
	}

	/**
	 * Get decrypted email
	 *
	 * @param array<string, mixed> $appointment Appointment.
	 * @return string
	 */
	private function get_appointment_email( array $appointment ): string {
		return \FreeFormCertificate\Core\Encryption::decrypt_field( $appointment, 'email' );
	}

	/**
	 * Send booking confirmation to user
	 *
	 * @param array<string, mixed> $appointment Appointment data.
	 * @param array<string, mixed> $calendar Calendar data.
	 * @return void
	 */
	public function send_booking_confirmation( array $appointment, array $calendar ): void {
		if ( $this->are_emails_disabled() ) {
			return;
		}

		$email = $this->get_appointment_email( $appointment );
		if ( empty( $email ) || ! is_email( $email ) ) {
			return;
		}

		$email_config   = json_decode( (string) ( $calendar['email_config'] ?? '' ), true );
		$email_config   = is_array( $email_config ) ? $email_config : array();
		$custom_body    = trim( (string) ( $email_config['user_confirmation_body'] ?? '' ) );
		$custom_subject = trim( (string) ( $email_config['user_confirmation_subject'] ?? '' ) );

		// The status/notes/receipt/cancel pieces the rich default carries are now
		// PRE-RENDERED tokens (#965): a per-calendar custom body OR the effective
		// global default resolves them through the one token map, so an admin can
		// keep, move or drop the buttons via `{{receipt_button}}` etc. Empty URL /
		// value ⇒ empty token ⇒ the block simply disappears (no regression).
		$status_message = $calendar['requires_approval']
			? __( 'Your appointment is pending approval. You will receive a confirmation email once it is approved.', 'ffcertificate' )
			: __( 'Your appointment has been confirmed!', 'ffcertificate' );

		$receipt_url = '';
		if ( class_exists( '\FreeFormCertificate\SelfScheduling\AppointmentReceiptHandler' ) ) {
			$receipt_url = \FreeFormCertificate\SelfScheduling\AppointmentReceiptHandler::get_receipt_url(
				$appointment['id'],
				$appointment['confirmation_token'] ?? ''
			);
		}
		$cancel_url = $calendar['allow_cancellation'] ? $this->get_cancellation_url( $appointment ) : '';

		$extra_tokens = array(
			'{{status_message}}'   => esc_html( $status_message ),
			'{{status_label}}'     => esc_html( $this->get_status_label( $appointment['status'] ) ),
			'{{user_notes_block}}' => $this->build_notes_block( (string) ( $appointment['user_notes'] ?? '' ) ),
			'{{receipt_button}}'   => self::ffc_email_button(
				$receipt_url,
				'📄 ' . __( 'View/Print Receipt', 'ffcertificate' ),
				array(
					'bg'        => '#0073aa',
					'padding'   => '12px 24px',
					'font_size' => '16px',
					'bold'      => true,
				)
			),
			'{{cancel_button}}'    => self::ffc_email_button(
				$cancel_url,
				__( 'Cancel Appointment', 'ffcertificate' ),
				array(
					'bg'        => '#dc3545',
					'padding'   => '10px 20px',
					'font_size' => '14px',
					'lead_in'   => __( 'Need to cancel?', 'ffcertificate' ),
				)
			),
		);

		// Subject + body: the admin-edited per-calendar template, else the effective
		// global (hub override → shipped file default). Both go through the one
		// token resolver, so the default reproduces the former echo partial exactly.
		$subject_template = '' !== $custom_subject
			? $custom_subject
			: \FreeFormCertificate\Core\EmailTemplates::effective_body( 'selfscheduling-confirmation', 'subject' );
		$body_template    = '' !== $custom_body
			? $custom_body
			: \FreeFormCertificate\Core\EmailTemplates::effective_body( 'selfscheduling-confirmation', 'body' );

		$subject = $this->render_confirmation_template( $subject_template, $appointment, $calendar, $extra_tokens );
		$content = $this->render_confirmation_template( $body_template, $appointment, $calendar, $extra_tokens );

		$this->send_mail( $email, $subject, self::ffc_email_document( $content, array( 'recipient' => $email ) ) );
	}

	/**
	 * Resolve the editable confirmation template's tokens.
	 *
	 * Supports {@see self::default_confirmation_body()}'s placeholder set:
	 * {{user_name}}, {{user_email}}, {{calendar_title}}, {{appointment_date}},
	 * {{appointment_time}}.
	 *
	 * Scalar text tokens are `esc_html`-escaped here (they carry user- and
	 * admin-supplied values — name, calendar title — so an editable body never
	 * injects raw HTML). Pre-rendered HTML tokens ({{…_button}}, {{user_notes_block}})
	 * arrive via `$extra` already built + escaped and pass through verbatim.
	 *
	 * @param string                $template    Raw template (subject or body).
	 * @param array<string, mixed>  $appointment Appointment data.
	 * @param array<string, mixed>  $calendar    Calendar data.
	 * @param array<string, string> $extra       Pre-rendered HTML tokens to merge in.
	 * @return string
	 */
	private function render_confirmation_template( string $template, array $appointment, array $calendar, array $extra = array() ): string {
		$tokens = array(
			'{{user_name}}'        => esc_html( \FreeFormCertificate\Core\Encryption::decrypt_field( $appointment, 'name' ) ),
			'{{user_email}}'       => esc_html( $this->get_appointment_email( $appointment ) ),
			'{{calendar_title}}'   => esc_html( (string) $calendar['title'] ),
			'{{appointment_date}}' => esc_html( \FreeFormCertificate\Core\DateFormatter::format_wallclock_date( $appointment['appointment_date'] ) ),
			'{{appointment_time}}' => esc_html( \FreeFormCertificate\Core\DateFormatter::format_wallclock_time( $appointment['start_time'] ) ),
		);
		return \FreeFormCertificate\Core\TokenResolver::resolve( $template, array_merge( $tokens, $extra ) );
	}

	/**
	 * Pre-rendered `{{user_notes_block}}` value: the "Your Notes" box (escaped),
	 * or `''` when the booking carries no notes — so the block disappears exactly
	 * like the former conditional in the echo partial.
	 *
	 * @param string $user_notes Raw user-supplied notes.
	 * @return string Notes box HTML, or ''.
	 */
	private function build_notes_block( string $user_notes ): string {
		if ( '' === trim( $user_notes ) ) {
			return '';
		}
		return '<div style="margin: 20px 0;">'
			. '<p style="margin: 0 0 5px 0; font-weight: bold; color: #666;">' . esc_html__( 'Your Notes:', 'ffcertificate' ) . '</p>'
			. '<p style="margin: 0; color: #333;">' . esc_html( $user_notes ) . '</p>'
			. '</div>';
	}

	/**
	 * Default confirmation-email body seeded by the editor's "Restore Default
	 * Text" button — the effective GLOBAL (hub override → shipped file default,
	 * #965), so Restore mirrors what an empty per-calendar body actually sends
	 * (details box + receipt/cancel button tokens included).
	 *
	 * @return string
	 */
	public static function default_confirmation_body(): string {
		return \FreeFormCertificate\Core\EmailTemplates::effective_body( 'selfscheduling-confirmation' );
	}

	/**
	 * Send admin notification
	 *
	 * @param array<string, mixed> $appointment Appointment data.
	 * @param array<string, mixed> $calendar Calendar data.
	 * @return void
	 */
	public function send_admin_notification( array $appointment, array $calendar ): void {
		if ( $this->are_emails_disabled() ) {
			return;
		}

		// Get admin emails from calendar config or default.
		$email_config = json_decode( $calendar['email_config'], true );
		$admin_emails = self::ffc_parse_admin_emails( $email_config['admin_email'] ?? '' );

		// Email subject.
		$subject = sprintf(
			/* translators: %s: calendar title */
			__( 'New Appointment: %s', 'ffcertificate' ),
			$calendar['title']
		);

		$date_formatted = \FreeFormCertificate\Core\DateFormatter::format_wallclock_date( $appointment['appointment_date'] );
		$time_formatted = \FreeFormCertificate\Core\DateFormatter::format_wallclock_time( $appointment['start_time'] );

		$decrypted_phone = \FreeFormCertificate\Core\Encryption::decrypt_field( $appointment, 'phone' );

		$details_table = self::ffc_admin_notification_table(
			array(
				__( 'Calendar', 'ffcertificate' ) => $calendar['title'],
				__( 'Date', 'ffcertificate' )     => $date_formatted,
				__( 'Time', 'ffcertificate' )     => $time_formatted,
				__( 'Status', 'ffcertificate' )   => $this->get_status_label( $appointment['status'] ),
				__( 'Name', 'ffcertificate' )     => $appointment['name'] ?? '-',
				__( 'Email', 'ffcertificate' )    => $this->get_appointment_email( $appointment ),
				__( 'Phone', 'ffcertificate' )    => $decrypted_phone ? $decrypted_phone : '-',
				__( 'Notes', 'ffcertificate' )    => $appointment['user_notes'] ?? '-',
			)
		);

		$body = self::ffc_email_document(
			self::ffc_render_email_partial(
				'appointment-admin-notification',
				array(
					'details_table' => $details_table,
					'manage_url'    => admin_url( 'edit.php?post_type=ffc_self_scheduling' ),
				)
			)
		);

		// Send to all admin emails.
		foreach ( $admin_emails as $admin_email ) {
			if ( is_email( $admin_email ) ) {
				$this->send_mail( $admin_email, $subject, $body );
			}
		}
	}

	/**
	 * Send approval notification to user
	 *
	 * @param array<string, mixed> $appointment Appointment data.
	 * @param array<string, mixed> $calendar Calendar data.
	 * @return void
	 */
	public function send_approval_notification( array $appointment, array $calendar ): void {
		if ( $this->are_emails_disabled() ) {
			return;
		}

		$email = $this->get_appointment_email( $appointment );
		if ( empty( $email ) || ! is_email( $email ) ) {
			return;
		}

		// Email subject.
		$subject = sprintf(
			/* translators: %s: calendar title */
			__( 'Appointment Approved: %s', 'ffcertificate' ),
			$calendar['title']
		);

		$date_formatted = \FreeFormCertificate\Core\DateFormatter::format_wallclock_date( $appointment['appointment_date'] );
		$time_formatted = \FreeFormCertificate\Core\DateFormatter::format_wallclock_time( $appointment['start_time'] );

		$receipt_url = '';
		if ( class_exists( '\FreeFormCertificate\SelfScheduling\AppointmentReceiptHandler' ) ) {
			$receipt_url = AppointmentReceiptHandler::get_receipt_url(
				$appointment['id'],
				$appointment['confirmation_token'] ?? ''
			);
		}

		$content = self::ffc_render_email_partial(
			'appointment-approval',
			array(
				'calendar_title' => $calendar['title'],
				'date_formatted' => $date_formatted,
				'time_formatted' => $time_formatted,
				'receipt_url'    => $receipt_url,
			)
		);

		// Send email.
		$this->send_mail( $email, $subject, self::ffc_email_document( $content ) );
	}

	/**
	 * Send cancellation notification to user
	 *
	 * @param array<string, mixed> $appointment Appointment data.
	 * @param array<string, mixed> $calendar Calendar data.
	 * @return void
	 */
	public function send_cancellation_notification( array $appointment, array $calendar ): void {
		if ( $this->are_emails_disabled() ) {
			return;
		}

		$email = $this->get_appointment_email( $appointment );
		if ( empty( $email ) || ! is_email( $email ) ) {
			return;
		}

		// Email subject.
		$subject = sprintf(
			/* translators: %s: calendar title */
			__( 'Appointment Cancelled: %s', 'ffcertificate' ),
			$calendar['title']
		);

		$date_formatted = \FreeFormCertificate\Core\DateFormatter::format_wallclock_date( $appointment['appointment_date'] );
		$time_formatted = \FreeFormCertificate\Core\DateFormatter::format_wallclock_time( $appointment['start_time'] );

		$content = self::ffc_render_email_partial(
			'appointment-cancellation',
			array(
				'calendar_title'      => $calendar['title'],
				'date_formatted'      => $date_formatted,
				'time_formatted'      => $time_formatted,
				'cancellation_reason' => $appointment['cancellation_reason'] ?? '',
			)
		);

		// Send email.
		$this->send_mail( $email, $subject, self::ffc_email_document( $content ) );
	}

	/**
	 * Send "you're on the waitlist" notification to the user (#941 phase 2).
	 *
	 * Fired when a booking for a full slot is queued instead of confirmed. The
	 * user keeps their cancellation link (leaving the queue) and is told they'll
	 * be notified automatically if a spot opens.
	 *
	 * @param array<string, mixed> $appointment Appointment data.
	 * @param array<string, mixed> $calendar Calendar data.
	 * @return void
	 */
	public function send_waitlist_notification( array $appointment, array $calendar ): void {
		if ( $this->are_emails_disabled() ) {
			return;
		}

		$email = $this->get_appointment_email( $appointment );
		if ( empty( $email ) || ! is_email( $email ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: calendar title */
			__( 'Added to Waitlist: %s', 'ffcertificate' ),
			$calendar['title']
		);

		$content = self::ffc_render_email_partial(
			'appointment-waitlisted',
			array(
				'calendar_title' => $calendar['title'],
				'date_formatted' => \FreeFormCertificate\Core\DateFormatter::format_wallclock_date( $appointment['appointment_date'] ),
				'time_formatted' => \FreeFormCertificate\Core\DateFormatter::format_wallclock_time( $appointment['start_time'] ),
				'cancel_url'     => $calendar['allow_cancellation'] ? $this->get_cancellation_url( $appointment ) : '',
			)
		);

		$this->send_mail( $email, $subject, self::ffc_email_document( $content, array( 'recipient' => $email ) ) );
	}

	/**
	 * Send "a spot opened — you're in" notification to a promoted user (#941 phase 2).
	 *
	 * Fired when the FIFO promoter moves a waitlisted booking into the active
	 * pool. The message reflects whether the promoted booking is now confirmed or
	 * still pending admin approval.
	 *
	 * @param array<string, mixed> $appointment Appointment data (post-promotion).
	 * @param array<string, mixed> $calendar Calendar data.
	 * @return void
	 */
	public function send_promotion_notification( array $appointment, array $calendar ): void {
		if ( $this->are_emails_disabled() ) {
			return;
		}

		$email = $this->get_appointment_email( $appointment );
		if ( empty( $email ) || ! is_email( $email ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: calendar title */
			__( 'A Spot Opened Up: %s', 'ffcertificate' ),
			$calendar['title']
		);

		$is_pending     = 'pending' === ( $appointment['status'] ?? '' );
		$status_message = $is_pending
			? __( 'A spot opened up and you have moved off the waitlist. Your booking is now pending approval — you will receive a confirmation once it is approved.', 'ffcertificate' )
			: __( 'A spot opened up and your booking is now confirmed!', 'ffcertificate' );

		$receipt_url = '';
		if ( class_exists( '\FreeFormCertificate\SelfScheduling\AppointmentReceiptHandler' ) ) {
			$receipt_url = AppointmentReceiptHandler::get_receipt_url(
				$appointment['id'],
				$appointment['confirmation_token'] ?? ''
			);
		}

		$content = self::ffc_render_email_partial(
			'appointment-promoted',
			array(
				'calendar_title' => $calendar['title'],
				'status_message' => $status_message,
				'date_formatted' => \FreeFormCertificate\Core\DateFormatter::format_wallclock_date( $appointment['appointment_date'] ),
				'time_formatted' => \FreeFormCertificate\Core\DateFormatter::format_wallclock_time( $appointment['start_time'] ),
				'receipt_url'    => $receipt_url,
				'cancel_url'     => $calendar['allow_cancellation'] ? $this->get_cancellation_url( $appointment ) : '',
			)
		);

		$this->send_mail( $email, $subject, self::ffc_email_document( $content, array( 'recipient' => $email ) ) );
	}

	/**
	 * Send appointment reminder
	 *
	 * @param array<string, mixed> $appointment Appointment data.
	 * @param array<string, mixed> $calendar Calendar data.
	 * @return void
	 */
	public function send_reminder( array $appointment, array $calendar ): void {
		if ( $this->are_emails_disabled() ) {
			return;
		}

		$email = $this->get_appointment_email( $appointment );
		if ( empty( $email ) || ! is_email( $email ) ) {
			return;
		}

		// Email subject.
		$subject = sprintf(
			/* translators: %s: calendar title */
			__( 'Reminder: Appointment Tomorrow - %s', 'ffcertificate' ),
			$calendar['title']
		);

		$date_formatted = \FreeFormCertificate\Core\DateFormatter::format_wallclock_date( $appointment['appointment_date'] );
		$time_formatted = \FreeFormCertificate\Core\DateFormatter::format_wallclock_time( $appointment['start_time'] );

		$cancel_url = $calendar['allow_cancellation'] ? $this->get_cancellation_url( $appointment ) : '';

		$content = self::ffc_render_email_partial(
			'appointment-reminder',
			array(
				'calendar_title' => $calendar['title'],
				'date_formatted' => $date_formatted,
				'time_formatted' => $time_formatted,
				'cancel_url'     => $cancel_url,
			)
		);

		// Send email.
		$this->send_mail( $email, $subject, self::ffc_email_document( $content ) );
	}

	/**
	 * Send email with failure logging.
	 *
	 * @since 4.6.6
	 * @param string $to      Recipient email.
	 * @param string $subject Email subject.
	 * @param string $body    Email body HTML.
	 * @return bool Whether the email was sent.
	 */
	private function send_mail( string $to, string $subject, string $body ): bool {
		return self::ffc_send_mail( $to, $subject, $body, array(), \FreeFormCertificate\Core\EmailSource::SCHEDULING );
	}

	/**
	 * Get status label
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private function get_status_label( string $status ): string {
		$labels = array(
			'pending'   => __( 'Pending Approval', 'ffcertificate' ),
			'confirmed' => __( 'Confirmed', 'ffcertificate' ),
			'cancelled' => __( 'Cancelled', 'ffcertificate' ),
			'completed' => __( 'Completed', 'ffcertificate' ),
			'no_show'   => __( 'No Show', 'ffcertificate' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * Get cancellation URL
	 *
	 * @param array<string, mixed> $appointment Appointment.
	 * @return string
	 */
	private function get_cancellation_url( array $appointment ): string {
		// #Item9 — login-free cancellation via the appointment's confirmation
		// token. AppointmentCancellationHandler renders the public confirm
		// page and re-validates the token before cancelling. Falls back to the
		// dashboard appointments tab when no token is present (e.g. legacy
		// rows created before tokens were issued).
		$appointment_id = (int) ( $appointment['id'] ?? 0 );
		$token          = isset( $appointment['confirmation_token'] ) && is_string( $appointment['confirmation_token'] )
			? $appointment['confirmation_token']
			: '';

		if ( '' !== $token && class_exists( '\FreeFormCertificate\SelfScheduling\AppointmentCancellationHandler' ) ) {
			return AppointmentCancellationHandler::get_cancellation_url( $appointment_id, $token );
		}

		$dashboard_page_id = get_option( 'ffc_dashboard_page_id' );
		$base_url          = $dashboard_page_id ? get_permalink( $dashboard_page_id ) : home_url( '/dashboard' );

		return add_query_arg(
			array(
				'tab' => 'appointments',
			),
			$base_url
		);
	}
}

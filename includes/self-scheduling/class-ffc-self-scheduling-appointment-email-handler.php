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

		// Subject: the admin-edited template (token-resolved) or the built-in default.
		$subject = '' !== $custom_subject
			? $this->render_confirmation_template( $custom_subject, $appointment, $calendar )
			: sprintf(
				/* translators: %s: calendar title */
				__( 'Appointment Confirmation: %s', 'ffcertificate' ),
				$calendar['title']
			);

		if ( '' !== $custom_body ) {
			// Admin-edited "email body" (#662 PR-6): the confirmation body is now the
			// editable template, wrapped by the shared chrome like every other email.
			$content = $this->render_confirmation_template( $custom_body, $appointment, $calendar );
		} else {
			// No custom body → the built-in rich default (status + receipt/cancel
			// buttons), unchanged from before the orphan was wired.
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

			$content = self::ffc_render_email_partial(
				'appointment-booking-confirmation',
				array(
					'calendar_title' => $calendar['title'],
					'status_message' => $status_message,
					'date_formatted' => \FreeFormCertificate\Core\DateFormatter::format_wallclock_date( $appointment['appointment_date'] ),
					'time_formatted' => \FreeFormCertificate\Core\DateFormatter::format_wallclock_time( $appointment['start_time'] ),
					'status_label'   => $this->get_status_label( $appointment['status'] ),
					'user_notes'     => $appointment['user_notes'] ?? '',
					'receipt_url'    => $receipt_url,
					'cancel_url'     => $calendar['allow_cancellation'] ? $this->get_cancellation_url( $appointment ) : '',
				)
			);
		}

		$this->send_mail( $email, $subject, self::ffc_email_document( $content, array( 'recipient' => $email ) ) );
	}

	/**
	 * Resolve the editable confirmation template's tokens.
	 *
	 * Supports {@see self::default_confirmation_body()}'s placeholder set:
	 * {{user_name}}, {{user_email}}, {{calendar_title}}, {{appointment_date}},
	 * {{appointment_time}}.
	 *
	 * @param string               $template    Raw template (subject or body).
	 * @param array<string, mixed> $appointment Appointment data.
	 * @param array<string, mixed> $calendar    Calendar data.
	 * @return string
	 */
	private function render_confirmation_template( string $template, array $appointment, array $calendar ): string {
		$tokens = array(
			'{{user_name}}'        => \FreeFormCertificate\Core\Encryption::decrypt_field( $appointment, 'name' ),
			'{{user_email}}'       => $this->get_appointment_email( $appointment ),
			'{{calendar_title}}'   => (string) $calendar['title'],
			'{{appointment_date}}' => \FreeFormCertificate\Core\DateFormatter::format_wallclock_date( $appointment['appointment_date'] ),
			'{{appointment_time}}' => \FreeFormCertificate\Core\DateFormatter::format_wallclock_time( $appointment['start_time'] ),
		);
		return \FreeFormCertificate\Core\TokenResolver::resolve( $template, $tokens );
	}

	/**
	 * Default confirmation-email body seeded by the editor's "Restore Default
	 * Text" button. Token-based (see {@see self::render_confirmation_template()})
	 * and translatable so pt-BR `.po` files ship a localized default.
	 *
	 * @return string
	 */
	public static function default_confirmation_body(): string {
		return \FreeFormCertificate\Core\EmailTemplates::body( 'selfscheduling-confirmation' );
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
		return self::ffc_send_mail( $to, $subject, $body );
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

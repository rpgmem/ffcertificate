<?php
/**
 * Audience Form Invite Mailer
 *
 * Sends one participant the personal link to a booking's post-event
 * certificate form (#1948).
 *
 * @package FreeFormCertificate\Audience
 * @since 6.42.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\EmailSource;
use FreeFormCertificate\Core\EmailTemplates;
use FreeFormCertificate\Core\TokenResolver;
use FreeFormCertificate\Scheduling\SchedulingMailer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Composes and sends a post-event invitation, and records it.
 *
 * @since 6.42.0
 */
class AudienceFormInviteMailer {

	/**
	 * The personal link: the page that embeds the form, carrying the code.
	 *
	 * @param int $booking_id Booking ID.
	 * @param int $user_id    User ID.
	 * @param int $form_id    Form post ID.
	 * @return string '' when no published page embeds the form.
	 */
	public static function link( int $booking_id, int $user_id, int $form_id ): string {
		$page = \FreeFormCertificate\Frontend\ScheduleExceptionAction::find_form_page_url( $form_id );
		if ( '' === $page ) {
			return '';
		}

		return add_query_arg( AudienceFormInvite::QUERY_VAR, AudienceFormInvite::code( $booking_id, $user_id, $form_id ), $page );
	}

	/**
	 * A warning for a form the invitations cannot link to (#2024): the
	 * hourly run skips a booking whose form no published page embeds, so
	 * the screens that choose the form say so instead.
	 *
	 * @param int $form_id Form post ID.
	 * @return string Escaped notice markup, or '' when there is nothing to say.
	 */
	public static function missing_page_notice( int $form_id ): string {
		if ( ! AudiencePostEventForm::is_form( $form_id ) || '' !== \FreeFormCertificate\Frontend\ScheduleExceptionAction::find_form_page_url( $form_id ) ) {
			return '';
		}

		return '<div class="notice notice-warning inline ffc-post-event-no-page"><p>' . esc_html(
			sprintf(
				/* translators: %s: the shortcode to put on a page */
				__( 'No published page embeds this form, so no invitation can be sent. Publish a page holding %s.', 'ffcertificate' ),
				'[ffc_form id="' . $form_id . '"]'
			)
		) . '</p></div>';
	}

	/**
	 * Send one invitation.
	 *
	 * Eligibility is the caller's to check; this only refuses what it cannot
	 * send: an account without a valid e-mail, or a form no page embeds. A
	 * send the mailer accepted is recorded, a resend counted.
	 *
	 * @param object $booking Booking row.
	 * @param int    $user_id User ID.
	 * @param int    $now     Unix time.
	 * @return true|\WP_Error `no_email`, `no_page` or `send_failed`.
	 */
	public static function send( object $booking, int $user_id, int $now ) {
		$user = get_userdata( $user_id );
		if ( false === $user || ! is_email( (string) $user->user_email ) ) {
			return new \WP_Error( 'no_email', __( 'The participant has no valid e-mail address.', 'ffcertificate' ) );
		}

		$booking_id = (int) ( $booking->id ?? 0 );
		$settings   = AudiencePostEventForm::settings( $booking );
		$link       = self::link( $booking_id, $user_id, $settings['form_id'] );
		if ( '' === $link ) {
			return new \WP_Error( 'no_page', __( 'No published page embeds the post-event form, so there is no link to send.', 'ffcertificate' ) );
		}

		$tokens = self::tokens( $booking, $settings, (string) $user->display_name, $link );
		$sent   = SchedulingMailer::send(
			(string) $user->user_email,
			TokenResolver::resolve( EmailTemplates::effective_body( 'audience-post-event-invite', 'subject' ), $tokens['plain'] ),
			TokenResolver::resolve( EmailTemplates::effective_body( 'audience-post-event-invite' ), $tokens['html'] ),
			array(),
			true,
			EmailSource::AUDIENCE_POST_EVENT
		);
		if ( ! $sent ) {
			return new \WP_Error( 'send_failed', __( 'The e-mail could not be sent.', 'ffcertificate' ) );
		}

		AudienceFormInviteWriter::record_sent( $booking_id, $user_id, $settings['form_id'], $now );

		return true;
	}

	/**
	 * The token values, escaped for the body and plain for the subject.
	 *
	 * @param object                                                      $booking  Booking row.
	 * @param array{form_id: int, window_days: int, attendees_only: bool} $settings Settings that apply.
	 * @param string                                                      $name     Participant's display name.
	 * @param string                                                      $link     Personal link.
	 * @return array{plain: array<string, string>, html: array<string, string>}
	 */
	private static function tokens( object $booking, array $settings, string $name, string $link ): array {
		$schedule_id = (int) ( $booking->schedule_id ?? 0 );
		$schedule    = $schedule_id > 0 ? AudienceScheduleRepository::get_by_id( $schedule_id ) : null;
		$closes      = AudiencePostEventForm::closes_at( $booking, $settings['window_days'] );

		$plain = array(
			'{{user_name}}'     => $name,
			'{{event}}'         => (string) ( $booking->description ?? '' ),
			'{{schedule_name}}' => null === $schedule ? '' : (string) ( $schedule->name ?? '' ),
			'{{form_title}}'    => get_the_title( $settings['form_id'] ),
			'{{closes_at}}'     => 0 === $closes ? '' : \FreeFormCertificate\Core\DateFormatter::format_datetime( $closes ),
			'{{site_name}}'     => (string) get_bloginfo( 'name' ),
		);

		$html                   = array_map( 'esc_html', $plain );
		$html['{{form_link}}']  = esc_url( $link );
		$plain['{{form_link}}'] = $link;

		return array(
			'plain' => $plain,
			'html'  => $html,
		);
	}
}

<?php
/**
 * CertificateCompletionLink
 *
 * The personal link a participant gets when a terminal recorded their
 * attendance but the profile could not fill every required field of the
 * certificate form (#2891).
 *
 * @package FreeFormCertificate\Frontend
 * @since 6.52.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

use FreeFormCertificate\Core\EmailHelperTrait;
use FreeFormCertificate\Core\EmailSource;
use FreeFormCertificate\Core\EmailTemplates;
use FreeFormCertificate\Core\TokenResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Issues, resolves and spends the "complete your certificate" link.
 *
 * The link is the form's page with `?ffc_invite=c.<user>.<signature>`, the
 * same query variable a personal invitation uses, so the form fills in the
 * person's name and e-mail and locks them through {@see FormInvite}. It
 * answers that filter for codes it recognises and leaves every other code
 * to the next callback.
 *
 * The code holds no expiry: the reading does. It opens for seven days
 * after the terminal last read the person, and only while that reading
 * still waits on data, so a link is spent by the certificate it issues.
 *
 * Opened, it lifts the form's opening hours, its GPS area and its password,
 * which the terminal already proved, and nothing else: a ticket, the
 * deny and allow lists and the per-person limits still apply.
 */
final class CertificateCompletionLink {

	use EmailHelperTrait;

	/** How long a link stays open after the reading. */
	public const TTL = 7 * DAY_IN_SECONDS;

	/** Prefix that marks a completion code among invitation codes. */
	public const PREFIX = 'c.';

	/**
	 * Query variable naming the form the Certificates screen opens (#2936).
	 *
	 * @var string
	 */
	public const SCREEN_VAR = 'ffc_complete';

	/**
	 * Id of the app's Certificates screen (`DashboardAppScreens`).
	 *
	 * @var string
	 */
	public const APP_SCREEN = 'certificates';

	/** Length of the signature, in base64url characters. */
	private const SIGNATURE_LENGTH = 22;

	/** Separates this signature from every other HMAC under the same salt. */
	private const CONTEXT = 'ffc_certificate_completion_v1';

	/** Template key of the e-mail. */
	public const TEMPLATE = 'certificate-complete';

	/**
	 * Hook the resolver.
	 *
	 * Priority 5, before the audience invitation's resolver at 10, because
	 * it answers only its own prefix and the audience one refuses any code
	 * it cannot parse.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( FormInvite::FILTER, array( self::class, 'filter' ), 5, 4 );
		// A certificate saved through the form's own page, without the link,
		// ends the wait too. Default priority: nothing here depends on the
		// order of the other listeners.
		add_action( 'ffcertificate_after_submission_save', array( self::class, 'on_saved' ), 10, 4 );
	}

	/**
	 * The `ffcertificate_after_submission_save` callback: a certificate the
	 * person saved for a form a terminal left waiting marks that reading
	 * issued, so the link closes.
	 *
	 * @param mixed $submission_id New submission id.
	 * @param mixed $form_id       Form id.
	 * @param mixed $data          Submission data; not used.
	 * @param mixed $user_email    The e-mail it was issued to.
	 * @return void
	 */
	public static function on_saved( $submission_id, $form_id, $data = null, $user_email = '' ): void {
		unset( $data );
		if ( ! is_numeric( $submission_id ) || ! is_numeric( $form_id ) || ! is_string( $user_email ) || '' === $user_email ) {
			return;
		}
		$user = get_user_by( 'email', $user_email );
		if ( ! $user instanceof \WP_User ) {
			return;
		}
		$reading = CertificateTerminalReadingRepository::for_person( (int) $form_id, (int) $user->ID );
		if ( null !== $reading && CertificateTerminalReadingRepository::AWAITING_DATA === $reading['result'] ) {
			self::complete( (int) $form_id, (int) $user->ID, (int) $submission_id );
		}
	}

	/**
	 * The code for a person and a form.
	 *
	 * @param int $form_id Form id.
	 * @param int $user_id Account id.
	 * @return string
	 */
	public static function code( int $form_id, int $user_id ): string {
		return self::PREFIX . $user_id . '.' . self::signature( $form_id, $user_id );
	}

	/**
	 * The person a code is for, or null when it is not a valid completion
	 * code for this form.
	 *
	 * @param string $code    Code from the request.
	 * @param int    $form_id Form id.
	 * @return int|null Account id.
	 */
	public static function user_of( string $code, int $form_id ): ?int {
		if ( $form_id <= 0 || 1 !== preg_match( '/^c\.([1-9]\d{0,19})\.([A-Za-z0-9_-]{' . self::SIGNATURE_LENGTH . '})$/', $code, $m ) ) {
			return null;
		}
		$user_id = (int) $m[1];
		return hash_equals( self::signature( $form_id, $user_id ), $m[2] ) ? $user_id : null;
	}

	/**
	 * The `ffcertificate_form_invite` callback.
	 *
	 * @param mixed $answer  What an earlier callback answered.
	 * @param mixed $form_id Form post ID.
	 * @param mixed $code    Code from the request.
	 * @param mixed $cpf_rf  The CPF/RF typed, if any; not used.
	 * @return mixed
	 */
	public static function filter( $answer, $form_id, $code, $cpf_rf = null ) {
		unset( $cpf_rf );
		if ( null !== $answer || ! is_string( $code ) || 0 !== strpos( $code, self::PREFIX ) ) {
			return $answer;
		}
		return self::resolve( is_numeric( $form_id ) ? (int) $form_id : 0, $code, time() );
	}

	/**
	 * Who a completion code is for, or why it is refused.
	 *
	 * @param int    $form_id Form id.
	 * @param string $code    Code.
	 * @param int    $now     Unix time.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function resolve( int $form_id, string $code, int $now ) {
		$user_id = self::user_of( $code, $form_id );
		if ( null === $user_id ) {
			return new \WP_Error( 'invalid', __( 'This link is not valid.', 'ffcertificate' ) );
		}
		$reading = CertificateTerminalReadingRepository::for_person( $form_id, $user_id );
		if ( null !== $reading && CertificateTerminalReadingRepository::ISSUED === $reading['result'] ) {
			return new \WP_Error( 'used', __( 'Your certificate was already issued. Open the app, under Certificates, to download it.', 'ffcertificate' ) );
		}
		if ( null === $reading || CertificateTerminalReadingRepository::AWAITING_DATA !== $reading['result'] ) {
			return new \WP_Error( 'invalid', __( 'This link is not valid.', 'ffcertificate' ) );
		}
		if ( $now > self::expires_at( $reading['read_at'] ) ) {
			return new \WP_Error( 'expired', __( 'This link has expired. Ask the event organisers for help.', 'ffcertificate' ) );
		}
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User || ! is_email( (string) $user->user_email ) ) {
			return new \WP_Error( 'invalid', __( 'This link is not valid.', 'ffcertificate' ) );
		}
		return array(
			'booking_id' => 0,
			'user_id'    => $user_id,
			'name'       => trim( (string) $user->display_name ),
			'email'      => (string) $user->user_email,
			'event'      => get_the_title( $form_id ),
			'completion' => true,
		);
	}

	/**
	 * When a link opened by a reading at `$read_at` closes.
	 *
	 * @param int $read_at Moment of the reading.
	 * @return int
	 */
	public static function expires_at( int $read_at ): int {
		return $read_at + self::TTL;
	}

	/**
	 * Whether the current request opens this form through a valid
	 * completion link, which lifts the form's hours and GPS area in the
	 * browser as well.
	 *
	 * @param int $form_id Form id.
	 * @return bool
	 */
	public static function opens( int $form_id ): bool {
		$code = \FreeFormCertificate\Core\RequestInput::get_get_string( FormInvite::QUERY_VAR );
		if ( '' === $code || 0 !== strpos( $code, self::PREFIX ) ) {
			return false;
		}
		return is_array( self::resolve( $form_id, $code, time() ) );
	}

	/**
	 * Mark the reading issued once the certificate the link led to is saved.
	 *
	 * @param int $form_id       Form id.
	 * @param int $user_id       Account id.
	 * @param int $submission_id The certificate.
	 * @return void
	 */
	public static function complete( int $form_id, int $user_id, int $submission_id ): void {
		$reading = CertificateTerminalReadingRepository::for_person( $form_id, $user_id );
		if ( null === $reading ) {
			return;
		}
		CertificateTerminalReadingRepository::record( $form_id, $user_id, $reading['terminal_id'], $reading['read_at'], CertificateTerminalReadingRepository::ISSUED, '', array(), $submission_id, time() );
	}

	/**
	 * The link for a person, or '' when there is nowhere to complete the form.
	 *
	 * With Site PWA it is the app's Certificates screen, which opens the form
	 * itself (#2936): a form used only at the terminal has no page, and the
	 * person finishes where they showed the QR. Without Site PWA it is the
	 * form's own page, when one is published.
	 *
	 * @param int $form_id Form id.
	 * @param int $user_id Account id.
	 * @return string
	 */
	public static function link( int $form_id, int $user_id ): string {
		$screen = self::app_screen_url();
		if ( '' !== $screen ) {
			return self::screen_link( $screen, $form_id, $user_id );
		}
		$page = ScheduleExceptionAction::find_form_page_url( $form_id );
		return '' === $page ? '' : add_query_arg( FormInvite::QUERY_VAR, self::code( $form_id, $user_id ), $page );
	}

	/**
	 * A screen's address with the form and the person's code on it.
	 *
	 * @param string $base    The screen's address.
	 * @param int    $form_id Form id.
	 * @param int    $user_id Account id.
	 * @return string
	 */
	public static function screen_link( string $base, int $form_id, int $user_id ): string {
		return add_query_arg(
			array(
				self::SCREEN_VAR      => $form_id,
				FormInvite::QUERY_VAR => self::code( $form_id, $user_id ),
			),
			$base
		);
	}

	/**
	 * The address of the app's Certificates screen, or '' without Site PWA.
	 *
	 * Site PWA draws a screen at `<app>/s/<id>/` (its `docs/screens.md`), and
	 * `Routes::url( 'home' )` is the app's address under the prefix the site
	 * chose. The class is asked for by name and the call is guarded, so an
	 * older or absent Site PWA leaves the form's page as the link
	 * (`.claude/rules/pwa-contract.md` "The look: offered, not read").
	 *
	 * @param string $routes Site PWA's routes class; a parameter so a test can name a double.
	 * @return string
	 */
	public static function app_screen_url( string $routes = '\\SitePwa\\App\\Routes' ): string {
		$routes = array( $routes, 'url' );
		if ( ! is_callable( $routes ) ) {
			return '';
		}
		$home = call_user_func( $routes, 'home' );
		return is_string( $home ) && '' !== $home ? trailingslashit( $home ) . 's/' . self::APP_SCREEN . '/' : '';
	}

	/**
	 * What the Certificates screen asked to complete, or null when it asked
	 * for nothing (#2936).
	 *
	 * The form opens only for the signed-in person its code was signed for,
	 * and only while the code is open; otherwise the answer carries the
	 * reason the screen shows instead.
	 *
	 * @param int $user_id The signed-in account.
	 * @param int $now     Unix time.
	 * @return array{form_id: int, error: string, code: string}|null
	 */
	public static function requested( int $user_id, int $now ): ?array {
		$form_id = absint( \FreeFormCertificate\Core\RequestInput::get_get_string( self::SCREEN_VAR ) );
		if ( $form_id <= 0 ) {
			return null;
		}
		$code = \FreeFormCertificate\Core\RequestInput::get_get_string( FormInvite::QUERY_VAR );
		$for  = self::user_of( $code, $form_id );
		if ( null === $for ) {
			return array(
				'form_id' => $form_id,
				'error'   => __( 'This link is not valid.', 'ffcertificate' ),
				'code'    => '',
			);
		}
		if ( $for !== $user_id ) {
			return array(
				'form_id' => $form_id,
				'error'   => __( 'This certificate belongs to another account. Sign in with the account that showed the QR.', 'ffcertificate' ),
				'code'    => '',
			);
		}
		$resolved = self::resolve( $form_id, $code, $now );
		$reading  = $resolved instanceof \WP_Error ? null : CertificateTerminalReadingRepository::for_person( $form_id, $user_id );
		return array(
			'form_id' => $form_id,
			'error'   => $resolved instanceof \WP_Error ? $resolved->get_error_message() : '',
			'code'    => null === $reading ? '' : self::formatted_code( $reading['reserved_code'] ),
		);
	}

	/**
	 * What a terminal made of a person's reading of a form since a moment,
	 * and where the app takes them (#2939).
	 *
	 * The Presence screen asks while it shows the code of a certificate
	 * form, so the person lands on the certificate the moment the terminal
	 * answers: the Certificates screen when it was issued, the form to
	 * complete when it waits on profile data. A refusal keeps them on the
	 * code, since the terminal says why. A reading from before the moment
	 * is not this one, and answers `none`.
	 *
	 * @param int $form_id Form id.
	 * @param int $user_id Account id.
	 * @param int $since   Unix time the code went on screen.
	 * @return array{status: string, url: string}
	 */
	public static function reading_outcome( int $form_id, int $user_id, int $since ): array {
		$reading = CertificateTerminalReadingRepository::for_person( $form_id, $user_id );
		if ( null === $reading || $reading['updated_at'] < $since ) {
			return array(
				'status' => 'none',
				'url'    => '',
			);
		}
		switch ( $reading['result'] ) {
			case CertificateTerminalReadingRepository::ISSUED:
				return array(
					'status' => 'issued',
					'url'    => self::app_screen_url(),
				);
			case CertificateTerminalReadingRepository::AWAITING_DATA:
				return array(
					'status' => 'awaiting',
					'url'    => self::link( $form_id, $user_id ),
				);
			default:
				return array(
					'status' => 'refused',
					'url'    => '',
				);
		}
	}

	/**
	 * The certificates a person still has to complete (#2893): each reading
	 * that recorded attendance but waits on profile data, while its link is
	 * open, with the fields to fill and the link the e-mail carries. The
	 * app lists them, so a lost e-mail does not lose the certificate.
	 *
	 * @param int $user_id Account id.
	 * @param int $now     Unix time.
	 * @return list<array{form_id: int, title: string, missing: list<string>, url: string, until: string, code: string}>
	 */
	public static function pending_for_user( int $user_id, int $now ): array {
		$out = array();
		foreach ( CertificateTerminalReadingRepository::awaiting_for_user( $user_id, $now - self::TTL ) as $reading ) {
			// Listed even without a link, so nothing waits out of sight (#2936).
			$url    = self::link( $reading['form_id'], $user_id );
			$fields = get_post_meta( $reading['form_id'], '_ffc_form_fields', true );
			$out[]  = array(
				'form_id' => $reading['form_id'],
				'title'   => ( new CertificateFormTarget() )->terminal_title( $reading['form_id'] ),
				'missing' => CertificateFormTarget::missing_labels( is_array( $fields ) ? array_values( array_filter( $fields, 'is_array' ) ) : array(), $reading['missing'] ),
				'url'     => $url,
				'until'   => \FreeFormCertificate\Core\DateFormatter::format_date( self::expires_at( $reading['read_at'] ) ),
				'code'    => self::formatted_code( $reading['reserved_code'] ),
			);
		}
		return $out;
	}

	/**
	 * A reserved code as the certificate prints it (#2938), or ''.
	 *
	 * @param string $code The stored code.
	 * @return string
	 */
	private static function formatted_code( string $code ): string {
		return '' === $code ? '' : \FreeFormCertificate\Core\DocumentFormatter::format_auth_code( $code, \FreeFormCertificate\Core\DocumentFormatter::PREFIX_CERTIFICATE );
	}

	/**
	 * Send the "complete your certificate" e-mail.
	 *
	 * @param int   $form_id Form id.
	 * @param int   $user_id Account id.
	 * @param array $missing Labels of the fields still to fill.
	 * @param int   $read_at Moment of the reading.
	 * @phpstan-param list<string> $missing
	 * @return bool Whether it was sent.
	 */
	public static function send( int $form_id, int $user_id, array $missing, int $read_at ): bool {
		$user = get_userdata( $user_id );
		$link = self::link( $form_id, $user_id );
		if ( ! $user instanceof \WP_User || ! is_email( (string) $user->user_email ) || '' === $link ) {
			return false;
		}

		$plain = array(
			'user_name'  => (string) $user->display_name,
			'form_title' => get_the_title( $form_id ),
			'form_link'  => $link,
			'missing'    => implode( ', ', $missing ),
			'closes_at'  => \FreeFormCertificate\Core\DateFormatter::format_datetime( self::expires_at( $read_at ) ),
			'site_name'  => (string) get_bloginfo( 'name' ),
		);
		$html  = array_map( 'esc_html', $plain );

		$html['form_link'] = esc_url( $link );

		return self::ffc_send_mail(
			(string) $user->user_email,
			TokenResolver::resolve( EmailTemplates::effective_body( self::TEMPLATE, 'subject' ), $plain ),
			self::ffc_email_document( TokenResolver::resolve( EmailTemplates::effective_body( self::TEMPLATE ), $html ), array( 'recipient' => (string) $user->user_email ) ),
			array(),
			EmailSource::CERTIFICATE
		);
	}

	/**
	 * The signature of a person and a form.
	 *
	 * @param int $form_id Form id.
	 * @param int $user_id Account id.
	 * @return string
	 */
	private static function signature( int $form_id, int $user_id ): string {
		$key = hash_hmac( 'sha256', self::CONTEXT, wp_salt( 'nonce' ) );
		$raw = hash_hmac( 'sha256', $form_id . '|' . $user_id, $key, true );
		return substr( rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ), 0, self::SIGNATURE_LENGTH ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url of an HMAC digest, not obfuscation.
	}
}

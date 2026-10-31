<?php
/**
 * Password-set link for an invited account.
 *
 * @package FreeFormCertificate\Core
 * @since 6.25.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

use FreeFormCertificate\Settings\SettingsReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emits and consumes the link that lets an invited user define their password.
 *
 * **Why this exists.** `UserCreator::create_ffc_user()` creates the account
 * with `wp_generate_password( 24 )` and never tells that password to anybody.
 * Whoever is invited to a reregistration therefore has no way in at all: the
 * email's button leads to the dashboard, which requires a login. This is the
 * missing half of user creation, not a convenience (#1212).
 *
 * **The link does NOT create a session.** It opens the password-setting screen;
 * the session is only born once the password exists and has been chosen by the
 * user themselves, in the same request. A link that logged straight in would be
 * a token equivalent to a credential travelling by email, in a system under the
 * LGPD with encrypted CPF and RF.
 *
 * **The token is WordPress's, on purpose.** `get_password_reset_key()` stores
 * `time() . ':' . $wp_hasher->HashPassword( $key )` in `user_activation_key`
 * -- that is, HASHED and with an issue stamp --, `check_password_reset_key()`
 * compares against the expiry, and `reset_password()` calls `wp_set_password()`,
 * which writes `user_activation_key = ''`. Single use comes for free. A token
 * of our own would store the secret IN CLEAR where core stores a hash: strictly
 * worse, besides being the facade trap `CLAUDE.md` describes.
 *
 * **The expiry filter is global, which is why it is scoped.**
 * `password_reset_expiration` does not receive the user
 * (`apply_filters( 'password_reset_expiration', DAY_IN_SECONDS )`, verified in
 * core 6.4), so registering it permanently would change the expiry of EVERY
 * reset on the site -- including an administrator using WordPress's own "lost
 * my password". It is registered immediately before our call and removed right
 * after: the blast radius is one function call.
 *
 * **When the token dies.** On the successful reset, not on the form's display.
 * A validation that fails -- the two passwords do not match -- leaves the token
 * alive on purpose, otherwise a typo would make the invitation unrecoverable.
 * It is the same principle `CLAUDE.md` already fixes for the captcha: *the
 * challenge is consumed by the action it authorises, not by the read that
 * precedes it*.
 *
 * **The key leaves the URL at the first request (#2246).** A URL carrying a
 * credential ends up in the browser history, in proxy logs and in the
 * `Referer` of anything the page loads. `capture_link()` reads the pair on
 * `template_redirect`, moves it into an HttpOnly cookie and redirects to the
 * dashboard with no key in it, sending `Referrer-Policy: no-referrer` on the
 * way. From then on the form, the submit and the retries read the cookie.
 *
 * **Staff accounts never get a link.** An account that can reveal personal
 * data (`Capabilities::is_staff_account()`) sets its password through
 * WordPress's own reset, so an invitation e-mail can never be turned into a
 * way into a staff account; `issue_for()` refuses to issue and `validate()`
 * refuses a key issued before the account became staff.
 */
class PasswordInvite {

	/** Action name for the `admin_post` submit. */
	public const ACTION = 'ffc_set_invite_password';

	/** Query arg carrying the reset key. */
	public const ARG_KEY = 'ffc_key';

	/** Query arg carrying the user login the key belongs to. */
	public const ARG_LOGIN = 'ffc_login';

	/** Cookie that carries the pair once it has left the URL. */
	public const COOKIE = 'ffc_password_invite';

	/**
	 * Cookie lifetime, in seconds. Short on purpose: the e-mail's link stays
	 * valid for the whole window and captures the pair again when opened.
	 */
	public const COOKIE_LIFETIME = 3600;

	/** Nonce action for the form. */
	private const NONCE = 'ffc_set_invite_password';

	/** The smallest accepted window. Under an hour the invitation dies before it is read. */
	public const MIN_HOURS = 1;

	/** The largest accepted window: 30 days. Above that the link stops being an invitation. */
	public const MAX_HOURS = 720;

	/** Default window, in hours: seven days. Declared in `Settings::get_default_settings()`. */
	public const DEFAULT_HOURS = 168;

	/** The shortest accepted password. WordPress imposes no minimum; this does. */
	public const MIN_PASSWORD_LENGTH = 8;

	/**
	 * Register the submit handler.
	 *
	 * `nopriv` is the normal path -- whoever arrives here has no session. The
	 * logged-in pair exists because a user who is already signed in may open the
	 * link from their own email, and a silent 400 there would be worse than a
	 * redirect.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_post_nopriv_' . self::ACTION, array( __CLASS__, 'handle_submit' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_submit' ) );
		// `template_redirect` is the last front-end hook before output, so the
		// cookie and the redirect can still be sent; priority 1 so it runs
		// before anything else on the page can print or leak the key.
		add_action( 'template_redirect', array( __CLASS__, 'capture_link' ), 1 );
	}

	/**
	 * Move the pair from the URL into a cookie and redirect to a clean URL.
	 *
	 * Runs on every front-end request and returns at once unless both query
	 * args are present. The pair is not validated here: an invalid key is
	 * answered by the dashboard, which reads it from the cookie like any other.
	 *
	 * @return void
	 */
	public static function capture_link(): void {
		$key   = RequestInput::get_get_string( self::ARG_KEY );
		$login = RequestInput::get_get_string( self::ARG_LOGIN );
		if ( '' === $key || '' === $login ) {
			return;
		}

		self::set_cookie( rawurlencode( $login ) . ':' . rawurlencode( $key ), time() + self::COOKIE_LIFETIME );
		self::send_no_referrer();
		nocache_headers();

		wp_safe_redirect( self::dashboard_url() );
		exit;
	}

	/**
	 * Expiration window for the link, in hours.
	 *
	 * Clamped on READ as well as on write, as `CLAUDE.md` requires: a value
	 * stored before a bound moved still has to land somewhere the code can use.
	 *
	 * @return int
	 */
	public static function expiration_hours(): int {
		return self::clamp_hours( SettingsReader::invite_password_link_hours() );
	}

	/**
	 * Clamp an hours value into the supported range.
	 *
	 * @param int $hours Raw value.
	 * @return int
	 */
	public static function clamp_hours( int $hours ): int {
		return max( self::MIN_HOURS, min( self::MAX_HOURS, $hours ) );
	}

	/**
	 * Issue a password-set link for a user.
	 *
	 * Issuing a key INVALIDATES any previous key for that user --
	 * `user_activation_key` is a single column. A "lost my password" requested
	 * minutes before the invitation dies here; it is WordPress's own behaviour
	 * and not worth working around.
	 *
	 * @param int $user_id User to invite.
	 * @return string URL, or '' when the key could not be issued.
	 */
	public static function issue_for( int $user_id ): string {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return '';
		}

		// See the class docblock: a staff account uses WordPress's own reset.
		if ( Capabilities::is_staff_account( $user_id ) ) {
			return '';
		}

		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return '';
		}

		self::log( 'password_invite_issued', $user_id );

		return add_query_arg(
			array(
				self::ARG_KEY   => rawurlencode( (string) $key ),
				self::ARG_LOGIN => rawurlencode( $user->user_login ),
			),
			self::dashboard_url()
		);
	}

	/**
	 * Validate a key/login pair.
	 *
	 * @param string $key   Reset key from the request.
	 * @param string $login User login from the request.
	 * @return \WP_User|\WP_Error
	 */
	public static function validate( string $key, string $login ) {
		if ( '' === $key || '' === $login ) {
			return new \WP_Error( 'invalid_key', __( 'This link is not valid.', 'ffcertificate' ) );
		}

		$seconds = self::expiration_hours() * HOUR_IN_SECONDS;
		$filter  = static function () use ( $seconds ): int {
			return $seconds;
		};

		// Scoped: core's filter does not receive the user, so it can only hold
		// during our own call. See the class docblock.
		add_filter( 'password_reset_expiration', $filter );
		$user = check_password_reset_key( $key, $login );
		remove_filter( 'password_reset_expiration', $filter );

		// A key issued before the account became staff stops working here.
		if ( $user instanceof \WP_User && Capabilities::is_staff_account( (int) $user->ID ) ) {
			return new \WP_Error( 'invalid_key', __( 'This link is not valid.', 'ffcertificate' ) );
		}

		return $user;
	}

	/**
	 * Whether the current request carries a captured password-set link.
	 *
	 * @return bool
	 */
	public static function request_has_link(): bool {
		$pair = self::request_pair();

		return '' !== $pair['key'] && '' !== $pair['login'];
	}

	/**
	 * The key and login the cookie carries.
	 *
	 * Both halves were `rawurlencode()`d when written, so the `:` that joins
	 * them cannot appear inside either.
	 *
	 * @return array{key: string, login: string}
	 */
	public static function request_pair(): array {
		$raw   = ArrayValue::string( wp_unslash( $_COOKIE ), self::COOKIE );
		$parts = explode( ':', $raw, 2 );
		if ( 2 !== count( $parts ) ) {
			return array(
				'key'   => '',
				'login' => '',
			);
		}

		return array(
			'key'   => sanitize_text_field( rawurldecode( $parts[1] ) ),
			'login' => sanitize_user( rawurldecode( $parts[0] ) ),
		);
	}

	/**
	 * Drop the cookie, in the response and in this request.
	 *
	 * @return void
	 */
	public static function clear_cookie(): void {
		self::set_cookie( '', time() - HOUR_IN_SECONDS );
		unset( $_COOKIE[ self::COOKIE ] );
	}

	/**
	 * Nonce field markup for the form.
	 *
	 * @return string
	 */
	public static function nonce_field(): string {
		return wp_nonce_field( self::NONCE, '_ffc_nonce', true, false );
	}

	/**
	 * Handle the submitted password.
	 *
	 * @return void
	 */
	public static function handle_submit(): void {
		$redirect = self::dashboard_url();

		if ( ! wp_verify_nonce( RequestInput::get_post_string( '_ffc_nonce' ), self::NONCE ) ) {
			self::bail( $redirect, 'nonce' );
		}

		$pair = self::request_pair();

		// The user comes from the TOKEN, never from a request-supplied id.
		$user = self::validate( $pair['key'], $pair['login'] );
		if ( is_wp_error( $user ) ) {
			self::clear_cookie();
			self::bail( $redirect, 'expired_key' === $user->get_error_code() ? 'expired' : 'invalid' );
		}

		// `get_post_string()` sanitises; a password cannot be sanitised without
		// being altered, so it is read raw and validated by length.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- a password cannot be sanitised without being altered; the nonce and the reset key were both verified above, and this read is exactly the value they authorise.
		$pass1 = isset( $_POST['ffc_pass1'] ) ? (string) wp_unslash( $_POST['ffc_pass1'] ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- same reason as the line above: sanitising the confirmation would change the value being compared.
		$pass2 = isset( $_POST['ffc_pass2'] ) ? (string) wp_unslash( $_POST['ffc_pass2'] ) : '';

		if ( '' === $pass1 || $pass1 !== $pass2 ) {
			// The token and its cookie stay alive: a typo must not burn the
			// invitation. See the class docblock.
			self::bail( $redirect, 'mismatch' );
		}

		if ( strlen( $pass1 ) < self::MIN_PASSWORD_LENGTH ) {
			self::bail( $redirect, 'short' );
		}

		reset_password( $user, $pass1 );
		self::clear_cookie();
		self::log( 'password_invite_consumed', (int) $user->ID );

		// The session is born HERE, once the password exists and has been chosen
		// by the user themselves in this same request (#1212).
		wp_set_current_user( (int) $user->ID );
		wp_set_auth_cookie( (int) $user->ID, false );

		wp_safe_redirect( add_query_arg( 'ffc_password', 'set', $redirect ) );
		exit;
	}

	/**
	 * Write the cookie.
	 *
	 * HttpOnly so no script on the page can read the key, `Lax` so it rides
	 * the top-level redirect and the form's POST, Secure whenever the site is.
	 *
	 * @param string $value   Cookie value ('' to clear).
	 * @param int    $expires Unix expiry.
	 * @return void
	 */
	private static function set_cookie( string $value, int $expires ): void {
		// Both callers run before any output: `template_redirect` and the
		// `admin_post` handler.
		setcookie(
			self::COOKIE,
			$value,
			array(
				'expires'  => $expires,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Ask the browser not to send the current URL as a referrer.
	 *
	 * Sent on the response that still carries the key in its URL, so the
	 * redirect it answers with cannot leak that URL either.
	 *
	 * @return void
	 */
	private static function send_no_referrer(): void {
		if ( ! headers_sent() ) {
			header( 'Referrer-Policy: no-referrer' );
		}
	}

	/**
	 * Redirect with an error code and stop.
	 *
	 * @param string $url    Destination.
	 * @param string $reason Error slug rendered by the form.
	 * @return never
	 */
	private static function bail( string $url, string $reason ): void {
		wp_safe_redirect( add_query_arg( 'ffc_password_error', $reason, $url ) );
		exit;
	}

	/**
	 * The dashboard page URL.
	 *
	 * The same resolution `ReregistrationEmailHandler::send_to_user()` uses: the
	 * option is written by the activator as a post id, and anything that is not
	 * a number falls back instead of being converted (#1060).
	 *
	 * @return string
	 */
	public static function dashboard_url(): string {
		$page_id = get_option( 'ffc_dashboard_page_id' );
		if ( is_numeric( $page_id ) && (int) $page_id > 0 ) {
			$url = get_permalink( (int) $page_id );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}

		return home_url( '/dashboard' );
	}

	/**
	 * Record an action on a credential.
	 *
	 * @param string $action  Action slug.
	 * @param int    $user_id Subject.
	 * @return void
	 */
	private static function log( string $action, int $user_id ): void {
		if ( class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			ActivityLog::log( $action, ActivityLog::LEVEL_INFO, array(), $user_id );
		}
	}
}

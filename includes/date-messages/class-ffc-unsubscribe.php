<?php
/**
 * Date-message one-click unsubscribe.
 *
 * @package FreeFormCertificate\DateMessages
 * @since 6.33.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\DateMessages;

use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The unsubscribe link every date message carries, and the page it opens
 * (#1538).
 *
 * THE TOKEN HOLDS NO STATE. It is an HMAC of the user id under a key derived
 * from `wp_salt()`, the way `ChallengeSigner` derives the captcha key, so
 * there is nothing stored to leak or to clean up, and a link stays valid for
 * as long as the salt does. It authorises one thing only: turning date
 * messages off for that one account.
 *
 * A GET NEVER CHANGES ANYTHING. Mail scanners and link previewers fetch every
 * URL in a message, so the link opens a confirmation page and only its POST,
 * carrying the same token plus a nonce, records the choice.
 */
final class Unsubscribe {

	/**
	 * `admin_post_*` action, logged-in and anonymous.
	 */
	public const ACTION = 'ffc_date_messages_unsubscribe';

	/**
	 * Domain separation for the derived key; bump to invalidate every link.
	 */
	private const CONTEXT = 'ffc_date_messages_unsub_v1';

	/**
	 * Register the handler for both audiences.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_post_nopriv_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * The link for one account.
	 *
	 * @param int $user_id Account.
	 * @return string
	 */
	public static function url( int $user_id ): string {
		return add_query_arg(
			array(
				'action' => self::ACTION,
				'u'      => $user_id,
				't'      => self::token( $user_id ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Token for one account.
	 *
	 * @param int $user_id Account.
	 * @return string
	 */
	public static function token( int $user_id ): string {
		return hash_hmac( 'sha256', 'unsubscribe|' . $user_id, self::secret() );
	}

	/**
	 * Whether a token belongs to an account.
	 *
	 * @param int    $user_id Account.
	 * @param string $token   Presented token.
	 * @return bool
	 */
	public static function verify( int $user_id, string $token ): bool {
		return $user_id > 0 && '' !== $token && hash_equals( self::token( $user_id ), $token );
	}

	/**
	 * GET shows the confirmation; POST records the opt-out.
	 *
	 * @return void
	 */
	public static function handle(): void {
		$is_post = RequestInput::has_post( 't' );
		$user_id = $is_post ? RequestInput::get_post_int( 'u' ) : RequestInput::get_get_int( 'u' );
		$token   = $is_post ? RequestInput::get_post_string( 't' ) : RequestInput::get_get_string( 't' );
		$title   = __( 'Date Messages', 'ffcertificate' );

		if ( ! self::verify( $user_id, $token ) || false === get_userdata( $user_id ) ) {
			wp_die( esc_html__( 'This unsubscribe link is not valid.', 'ffcertificate' ), esc_html( $title ), array( 'response' => 403 ) );
		}

		if ( ! $is_post ) {
			wp_die( self::confirmation_form( $user_id, $token ), esc_html( $title ), array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- confirmation_form() escapes every value it prints.
		}

		if ( ! wp_verify_nonce( RequestInput::get_post_string( '_wpnonce' ), self::nonce_action( $user_id ) ) ) {
			wp_die( esc_html__( 'This page expired. Open the link in the message again.', 'ffcertificate' ), esc_html( $title ), array( 'response' => 403 ) );
		}

		OptOut::set( $user_id, false );

		wp_die(
			esc_html__( 'Done. You will no longer receive date messages. You can turn them back on in your dashboard profile.', 'ffcertificate' ),
			esc_html( $title ),
			array( 'response' => 200 )
		);
	}

	/**
	 * The confirmation page body.
	 *
	 * @param int    $user_id Account.
	 * @param string $token   Its token.
	 * @return string Escaped HTML.
	 */
	private static function confirmation_form( int $user_id, string $token ): string {
		return '<p>' . esc_html__( 'Stop receiving date messages, such as birthday greetings, at this account?', 'ffcertificate' ) . '</p>'
			. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">'
			. '<input type="hidden" name="u" value="' . esc_attr( (string) $user_id ) . '">'
			. '<input type="hidden" name="t" value="' . esc_attr( $token ) . '">'
			. wp_nonce_field( self::nonce_action( $user_id ), '_wpnonce', true, false )
			. '<p><button type="submit" class="button button-primary">' . esc_html__( 'Unsubscribe', 'ffcertificate' ) . '</button></p>'
			. '</form>';
	}

	/**
	 * Nonce action, per account.
	 *
	 * @param int $user_id Account.
	 * @return string
	 */
	private static function nonce_action( int $user_id ): string {
		return self::ACTION . '_' . $user_id;
	}

	/**
	 * Derived key.
	 *
	 * @return string
	 */
	private static function secret(): string {
		return hash_hmac( 'sha256', self::CONTEXT, wp_salt( 'nonce' ) );
	}
}

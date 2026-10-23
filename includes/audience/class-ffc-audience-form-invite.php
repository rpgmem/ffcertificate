<?php
/**
 * Audience Form Invite
 *
 * The signed code a post-event invitation carries (#1948).
 *
 * @package FreeFormCertificate\Audience
 * @since 6.42.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Signs and verifies the code in a post-event invitation link.
 *
 * A code is `<booking>.<user>.<signature>`, carried as `?ffc_invite=` on the
 * page that embeds the form. The signature is an HMAC over booking, user and
 * form, base64url, truncated to 22 characters (132 bits). Nothing is stored:
 * the code is the credential, and how long it works is the booking's form
 * window, which {@see AudiencePostEventForm::eligibility()} checks on every
 * use. Naming the form in the signature means that pointing the booking at
 * another form voids the links already sent.
 *
 * The key is derived from `wp_salt( 'nonce' )` under its own context, the
 * shape of `AudienceCheckinCode`; rotating the site's salts voids the links.
 *
 * @since 6.42.0
 */
class AudienceFormInvite {

	/**
	 * Query variable the code travels in.
	 *
	 * @var string
	 */
	public const QUERY_VAR = 'ffc_invite';

	/**
	 * Characters of signature a code carries.
	 *
	 * @var int
	 */
	public const SIGNATURE_LENGTH = 22;

	/**
	 * HMAC context, so the key differs from every other signer in the plugin.
	 *
	 * @var string
	 */
	private const CONTEXT = 'ffc_audience_form_invite_v1';

	/**
	 * The code inviting one person of a booking to one form.
	 *
	 * @param int $booking_id Booking ID.
	 * @param int $user_id    User ID.
	 * @param int $form_id    Form post ID.
	 * @return string
	 */
	public static function code( int $booking_id, int $user_id, int $form_id ): string {
		return $booking_id . '.' . $user_id . '.' . self::signature( $booking_id, $user_id, $form_id );
	}

	/**
	 * Split a code into its parts, without checking the signature.
	 *
	 * @param string $code Code from the URL.
	 * @return array{booking_id: int, user_id: int, signature: string}|null Null when malformed.
	 */
	public static function parse( string $code ): ?array {
		if ( ! preg_match( '/^([1-9]\d{0,19})\.([1-9]\d{0,19})\.([A-Za-z0-9_-]{' . self::SIGNATURE_LENGTH . '})$/', $code, $m ) ) {
			return null;
		}

		return array(
			'booking_id' => (int) $m[1],
			'user_id'    => (int) $m[2],
			'signature'  => $m[3],
		);
	}

	/**
	 * The booking and person a code names, when it is signed for this form.
	 *
	 * Only the signature is checked here; whether the person may still
	 * answer is {@see AudiencePostEventForm::eligibility()}'s question.
	 *
	 * @param string $code    Code from the URL.
	 * @param int    $form_id The form being filled in.
	 * @return array{booking_id: int, user_id: int}|null
	 */
	public static function verify( string $code, int $form_id ): ?array {
		$parts = self::parse( $code );
		if ( null === $parts || $form_id <= 0 ) {
			return null;
		}

		if ( ! hash_equals( self::signature( $parts['booking_id'], $parts['user_id'], $form_id ), $parts['signature'] ) ) {
			return null;
		}

		return array(
			'booking_id' => $parts['booking_id'],
			'user_id'    => $parts['user_id'],
		);
	}

	/**
	 * The truncated signature of one code.
	 *
	 * @param int $booking_id Booking ID.
	 * @param int $user_id    User ID.
	 * @param int $form_id    Form post ID.
	 * @return string
	 */
	private static function signature( int $booking_id, int $user_id, int $form_id ): string {
		$raw = hash_hmac( 'sha256', $booking_id . '|' . $user_id . '|' . $form_id, self::key(), true );

		return substr( rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ), 0, self::SIGNATURE_LENGTH ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url of an HMAC digest, not obfuscation.
	}

	/**
	 * The signing key.
	 *
	 * @return string
	 */
	private static function key(): string {
		return hash_hmac( 'sha256', self::CONTEXT, wp_salt( 'nonce' ) );
	}
}

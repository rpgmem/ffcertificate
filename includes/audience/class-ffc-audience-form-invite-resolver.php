<?php
/**
 * Audience Form Invite Resolver
 *
 * Answers a certificate form's question "who is this invitation for" (#1948).
 *
 * @package FreeFormCertificate\Audience
 * @since 6.42.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\UserDashboard\UserManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves a post-event invitation code for the form being filled in.
 *
 * Hooked on `ffcertificate_form_invite` ({@see \FreeFormCertificate\Frontend\FormInvite::FILTER}),
 * which the form fires with the code from the link or the hidden field; the
 * literal is repeated here rather than read off that class so this module
 * does not depend on the front end. An answer is the person the invitation
 * is for, or the reason it is refused, checked in this order:
 *
 * 1. the code is signed for this form;
 * 2. the booking still leads to this form, so a link sent before the
 *    operator chose another form stops working;
 * 3. {@see AudiencePostEventForm::eligibility()}: on the booking, inside the
 *    window, present when the booking asks for attendees only;
 * 4. the CPF/RF typed, when there is one, is the person's.
 *
 * The fourth check is what stands in for filling the CPF/RF in. The profile
 * holds it hashed, so there is nothing to show; the typed value is refused
 * when it belongs to another account, or when it is unknown while the person
 * already has an identifier of that kind on record. An unknown value from a
 * person with none is accepted, and the submission's own user link then
 * records it on their profile.
 *
 * @since 6.42.0
 */
class AudienceFormInviteResolver {

	/**
	 * The filter the certificate form fires.
	 *
	 * @var string
	 */
	public const FILTER = 'ffcertificate_form_invite';

	/**
	 * Hook the resolver.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( self::FILTER, array( self::class, 'filter' ), 10, 4 );
	}

	/**
	 * The filter callback.
	 *
	 * @param mixed       $answer  What an earlier callback answered.
	 * @param int         $form_id Form post ID.
	 * @param string      $code    Code from the request.
	 * @param string|null $cpf_rf  The CPF/RF typed, normalised, or null.
	 * @return mixed
	 */
	public static function filter( $answer, $form_id, $code, $cpf_rf = null ) {
		if ( null !== $answer ) {
			return $answer;
		}

		return self::resolve( (int) $form_id, (string) $code, is_string( $cpf_rf ) && '' !== $cpf_rf ? $cpf_rf : null, time() );
	}

	/**
	 * Who an invitation is for, or why it is refused.
	 *
	 * @param int         $form_id Form post ID.
	 * @param string      $code    Code from the request.
	 * @param string|null $cpf_rf  The CPF/RF typed, normalised, or null.
	 * @param int         $now     Unix time.
	 * @return array{booking_id: int, user_id: int, name: string, email: string, event: string}|\WP_Error
	 */
	public static function resolve( int $form_id, string $code, ?string $cpf_rf, int $now ) {
		$parts = AudienceFormInvite::verify( $code, $form_id );
		if ( null === $parts ) {
			return self::error( 'invalid' );
		}

		$booking = AudienceBookingReader::get_by_id( $parts['booking_id'] );
		$user    = get_userdata( $parts['user_id'] );
		if ( null === $booking || false === $user ) {
			return self::error( 'invalid' );
		}

		if ( AudiencePostEventForm::settings( $booking )['form_id'] !== $form_id ) {
			return self::error( 'no_form' );
		}

		$eligible = AudiencePostEventForm::eligibility( $booking, $parts['user_id'], $now );
		if ( $eligible instanceof \WP_Error ) {
			return $eligible;
		}

		if ( null !== $cpf_rf ) {
			$identity = self::check_identifier( $parts['user_id'], $cpf_rf );
			if ( $identity instanceof \WP_Error ) {
				return $identity;
			}
		}

		return array(
			'booking_id' => $parts['booking_id'],
			'user_id'    => $parts['user_id'],
			'name'       => (string) $user->display_name,
			'email'      => (string) $user->user_email,
			'event'      => (string) ( $booking->description ?? '' ),
		);
	}

	/**
	 * Whether a typed CPF/RF may be the invited person's.
	 *
	 * @param int    $user_id The invited person.
	 * @param string $cpf_rf  CPF (11 digits) or RF (7 digits), normalised.
	 * @return true|\WP_Error
	 */
	public static function check_identifier( int $user_id, string $cpf_rf ) {
		$is_rf = 7 === strlen( $cpf_rf );
		$hash  = \FreeFormCertificate\Core\SensitiveFieldRegistry::hash_identifier( $is_rf ? 'rf' : 'cpf', $cpf_rf );
		if ( null === $hash ) {
			// No key to hash with: nothing can be compared, and the
			// submission itself would store the value unhashed anyway.
			return true;
		}

		$owner = UserManager::resolve_existing_user( $is_rf ? null : $hash, $is_rf ? $hash : null, '' );
		if ( $owner === $user_id ) {
			return true;
		}
		if ( $owner > 0 ) {
			return self::error( 'identity_taken' );
		}

		$on_record = UserManager::get_user_identifiers_masked( $user_id );
		if ( array() !== $on_record[ $is_rf ? 'rfs' : 'cpfs' ] ) {
			return self::error( 'identity_mismatch' );
		}

		return true;
	}

	/**
	 * What to tell a person an invitation refused.
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	public static function message( string $code ): string {
		switch ( $code ) {
			case 'invalid':
				return __( 'This invitation link is not valid.', 'ffcertificate' );
			case 'identity_taken':
				return __( 'This CPF/RF belongs to another registration. Type your own, or ask the organiser.', 'ffcertificate' );
			case 'identity_mismatch':
				return __( 'This CPF/RF does not match your registration. Type the one you registered with, or ask the organiser.', 'ffcertificate' );
			default:
				return AudiencePostEventForm::message( $code );
		}
	}

	/**
	 * A refusal.
	 *
	 * @param string $code Error code.
	 * @return \WP_Error
	 */
	private static function error( string $code ): \WP_Error {
		return new \WP_Error( $code, self::message( $code ) );
	}
}

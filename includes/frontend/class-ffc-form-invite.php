<?php
/**
 * Form Invite
 *
 * The certificate form's side of a personal invitation (#1948).
 *
 * @package FreeFormCertificate\Frontend
 * @since 6.42.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

use FreeFormCertificate\Core\DataSanitizer;
use FreeFormCertificate\Core\DocumentFormatter;
use FreeFormCertificate\Core\SubmitterName;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asks whoever issued an invitation who it is for, and fills the form with it.
 *
 * The form knows nothing about bookings: an invitation code arrives as
 * `?ffc_invite=` on the page and in a hidden field, and the form asks the
 * {@see self::FILTER} filter what it means. The audience module answers it
 * when a booking leads to a form (#1948), so this module never references
 * that one, the dependency inversion `Core\SourceRegistry` uses for exports.
 *
 * An answer is the person the invitation is for. Their name and e-mail are
 * written into the form and locked, and the submission stage overwrites the
 * posted values with them, because a locked field in the browser is only a
 * presentation. The CPF/RF is never filled in: the profile holds it hashed,
 * and a link that works without logging in must not print it. The person
 * types it, and the answer to the filter says whether it is theirs.
 *
 * @phpstan-type Invite array{booking_id: int, user_id: int, name: string, email: string, event: string, completion: bool, cpf_rf: string}
 *
 * @since 6.42.0
 */
final class FormInvite {

	/**
	 * Query variable and hidden field the code travels in.
	 *
	 * @var string
	 */
	public const QUERY_VAR = 'ffc_invite';

	/**
	 * Filter that resolves a code: `( null, int $form_id, string $code, ?string $cpf_rf )`
	 * returns an invite array or a `WP_Error`.
	 *
	 * @var string
	 */
	public const FILTER = 'ffcertificate_form_invite';

	/**
	 * Key of the form restriction that admits invitations only.
	 *
	 * @var string
	 */
	public const RESTRICTION = 'invite';

	/**
	 * Whether a form admits invited people only.
	 *
	 * @param array<string, mixed> $form_config `_ffc_form_config`.
	 * @return bool
	 */
	public static function invite_only( array $form_config ): bool {
		$restrictions = isset( $form_config['restrictions'] ) && is_array( $form_config['restrictions'] ) ? $form_config['restrictions'] : array();

		return '1' === (string) ( $restrictions[ self::RESTRICTION ] ?? '' );
	}

	/**
	 * What a code means for a form.
	 *
	 * @param int         $form_id Form post ID.
	 * @param string      $code    Code from the request.
	 * @param string|null $cpf_rf  The CPF/RF the person typed, when there is one to check.
	 * @return array<string, mixed>|\WP_Error
	 * @phpstan-return Invite|\WP_Error
	 */
	public static function resolve( int $form_id, string $code, ?string $cpf_rf = null ) {
		$answer = apply_filters( self::FILTER, null, $form_id, $code, $cpf_rf ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- self::FILTER is 'ffcertificate_form_invite', prefixed.

		if ( $answer instanceof \WP_Error ) {
			return $answer;
		}

		if ( ! is_array( $answer ) || empty( $answer['user_id'] ) || empty( $answer['email'] ) ) {
			return new \WP_Error( 'invalid', __( 'This invitation link is not valid.', 'ffcertificate' ) );
		}

		return array(
			'booking_id' => (int) ( $answer['booking_id'] ?? 0 ),
			'user_id'    => (int) $answer['user_id'],
			'name'       => (string) ( $answer['name'] ?? '' ),
			'email'      => (string) $answer['email'],
			'event'      => (string) ( $answer['event'] ?? '' ),
			// A terminal's completion link (#2891): the terminal already
			// proved the hours, the GPS area and the password.
			'completion' => ! empty( $answer['completion'] ),
			// A completion link's CPF/RF, from the profile; '' otherwise.
			'cpf_rf'     => ! empty( $answer['completion'] ) ? DataSanitizer::normalize_cpf_rf( (string) ( $answer['cpf_rf'] ?? '' ) ) : '',
		);
	}

	/**
	 * Whether a field holds the person's name.
	 *
	 * @param array<string, mixed> $field Stored field.
	 * @return bool
	 */
	public static function is_name_field( array $field ): bool {
		return in_array( (string) ( $field['name'] ?? '' ), SubmitterName::CANDIDATE_KEYS, true );
	}

	/**
	 * Whether a field holds the person's e-mail.
	 *
	 * @param array<string, mixed> $field Stored field.
	 * @return bool
	 */
	public static function is_email_field( array $field ): bool {
		return 'email' === ( $field['type'] ?? '' );
	}

	/**
	 * The form's fields with the invited person's name and e-mail filled in
	 * and locked.
	 *
	 * A completion link (#2944) also carries the CPF/RF the profile holds,
	 * drawn masked and posted by nobody: {@see Submission\InviteGuard} writes
	 * the real value. The fields that fill from the profile take what it
	 * holds, locked as well; only what the profile cannot answer is left
	 * open.
	 *
	 * @param array<int, array<string, mixed>> $fields Stored fields.
	 * @param array<string, mixed>             $invite Resolved invite.
	 * @phpstan-param Invite $invite
	 * @return array<int, array<string, mixed>>
	 */
	public static function fill_fields( array $fields, array $invite ): array {
		foreach ( $fields as $i => $field ) {
			if ( self::is_name_field( $field ) && '' !== $invite['name'] ) {
				$fields[ $i ]['default_value'] = $invite['name'];
				$fields[ $i ]['locked']        = true;
			} elseif ( self::is_email_field( $field ) ) {
				$fields[ $i ]['default_value'] = $invite['email'];
				$fields[ $i ]['locked']        = true;
			} elseif ( 'cpf_rf' === ( $field['name'] ?? '' ) && '' !== $invite['cpf_rf'] ) {
				$fields[ $i ]['default_value'] = self::masked_identifier( $invite['cpf_rf'] );
				$fields[ $i ]['profile_held']  = true;
			}
		}

		$held = self::profile_values( $fields, $invite );
		foreach ( $fields as $i => $field ) {
			$name = (string) ( $field['name'] ?? '' );
			if ( isset( $held[ $name ] ) ) {
				$fields[ $i ]['default_value'] = is_array( $held[ $name ] ) ? (string) reset( $held[ $name ] ) : $held[ $name ];
				$fields[ $i ]['locked']        = true;
			}
		}

		return $fields;
	}

	/**
	 * What a completion link's form takes from the profile, by field name.
	 *
	 * Only the fields set to fill from the profile, and only those the
	 * profile answers: what it cannot answer stays for the person to fill.
	 * The form draws these locked and {@see Submission\InviteGuard} writes
	 * them again, so the profile is what the certificate carries (#2944).
	 *
	 * @param array<int, array<string, mixed>> $fields Stored fields.
	 * @param array<string, mixed>             $invite Resolved invite.
	 * @phpstan-param Invite $invite
	 * @return array<string, string|list<string>>
	 */
	public static function profile_values( array $fields, array $invite ): array {
		if ( ! $invite['completion'] ) {
			return array();
		}
		return self::pick_profile_values( $fields, CertificateProfileSource::values( $invite['user_id'], array_values( $fields ) ) );
	}

	/**
	 * The fields a profile answers, by name, from what it holds.
	 *
	 * @param array<int, array<string, mixed>> $fields  Stored fields.
	 * @param array<string, string>            $profile Source => value, from {@see CertificateProfileSource::values()}.
	 * @return array<string, string|list<string>>
	 */
	public static function pick_profile_values( array $fields, array $profile ): array {
		if ( array() === $profile ) {
			return array();
		}
		$out = array();
		foreach ( $fields as $field ) {
			$name = (string) ( $field['name'] ?? '' );
			if ( '' === $name || CertificateProfileSource::CONSENT === CertificateProfileSource::of( $field ) ) {
				continue;
			}
			$value = CertificateProfileSource::value_for( $field, $profile );
			if ( null !== $value ) {
				$out[ $name ] = $value;
			}
		}
		return $out;
	}

	/**
	 * A CPF or an RF as the form shows it: masked, by its own shape.
	 *
	 * @param string $cpf_rf Digits only.
	 * @return string
	 */
	public static function masked_identifier( string $cpf_rf ): string {
		$is_rf = 'rf' === DataSanitizer::classify_cpf_rf( $cpf_rf );
		return DocumentFormatter::mask( $is_rf ? DocumentFormatter::MASK_RF : DocumentFormatter::MASK_CPF, $cpf_rf );
	}
}

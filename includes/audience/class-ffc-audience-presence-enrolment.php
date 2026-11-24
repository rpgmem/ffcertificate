<?php
/**
 * Audience Presence Enrolment
 *
 * The rules a presence device is activated and renewed under (#2741).
 *
 * @package FreeFormCertificate\Audience
 * @since 6.50.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\ActivityLog;
use FreeFormCertificate\Core\DateFormatter;
use FreeFormCertificate\Core\EcdsaP256;
use FreeFormCertificate\Core\EmailSource;
use FreeFormCertificate\Core\EmailTemplates;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Core\SigningKeys;
use FreeFormCertificate\Core\TokenResolver;
use FreeFormCertificate\Scheduling\SchedulingMailer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activates a phone's presence key and renews its attestation.
 *
 * The rules, recorded in #2738 under "Devices":
 *
 * - **One active device per user.** Activating a new key revokes the user's
 *   active one and e-mails them.
 * - **A device stays reserved to its account for 30 days** after it was last
 *   active there. Another account activating on it is refused until an
 *   administrator releases it.
 * - **At most 3 device changes per user in 30 days.** The first device a user
 *   ever activates is not a change, and neither is a new key on the same
 *   device (the app's data cleared and set up again). A fourth needs an
 *   administrator's approval, which lets one activation through.
 * - **Matching device signals across accounts raise an alert, never a
 *   block**, because identical school-issued phones report identical signals.
 *
 * Every activation, revocation and refusal goes to the activity log with the
 * device id and the key id, which name a phone and a key, never a person.
 * A refusal an administrator can lift also leaves a request for them.
 *
 * The rules gate presence enrolment only: logging in, certificates and
 * bookings keep working on any device.
 *
 * Both calls prove possession of the device key: the app signs a statement
 * naming the account, the device and the moment, so a public key copied from
 * somewhere else cannot be enrolled, and a request replayed later is stale.
 */
final class AudiencePresenceEnrolment {

	/**
	 * How long a device stays reserved to its account, in seconds: 30 days.
	 */
	public const RESERVATION = 2592000;

	/**
	 * The window the change limit counts over, in seconds: 30 days.
	 */
	public const CHANGE_WINDOW = 2592000;

	/**
	 * Device changes allowed inside the window.
	 */
	public const MAX_CHANGES = 3;

	/**
	 * How far a proof's timestamp may sit from the server's clock, in seconds.
	 */
	public const PROOF_SKEW = 300;

	/**
	 * Refusal reason: the device is reserved to another account.
	 */
	public const REASON_RESERVED = 'device_reserved';

	/**
	 * Refusal reason: the user reached the change limit.
	 */
	public const REASON_CHANGE_LIMIT = 'change_limit';

	/**
	 * What an enrolment proof signs ahead of its fields.
	 */
	public const ENROL_DOMAIN = "FFC-PRESENCE-ENROL\x00";

	/**
	 * What a renewal proof signs ahead of its fields.
	 */
	public const RENEW_DOMAIN = "FFC-PRESENCE-RENEW\x00";

	/**
	 * Activate a device key for a user.
	 *
	 * @param int    $user_id   The user, taken from the session by the caller.
	 * @param string $device_id The id the installed app keeps.
	 * @param string $point    Base64url raw P-256 public point.
	 * @param int    $timestamp When the app signed the proof.
	 * @param string $proof     Base64url signature, by the device key, of the enrolment statement.
	 * @param string $signals   Device signals, or '' when the app sent none.
	 * @param int    $now       Unix time.
	 * @param string $agent     The app's User-Agent; only its platform and browser families are kept (#2781).
	 * @return array{attestation: string, key_id: string, expires: int, name: string}|\WP_Error
	 */
	public static function enrol( int $user_id, string $device_id, string $point, int $timestamp, string $proof, string $signals, int $now, string $agent = '' ) {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return self::error( 'ffc_presence_no_user', __( 'Log in to activate this device.', 'ffcertificate' ), 401 );
		}
		if ( ! self::valid_device_id( $device_id ) ) {
			return self::error( 'ffc_presence_bad_device', __( 'This device could not be identified. Reinstall the app and try again.', 'ffcertificate' ), 400 );
		}
		$raw = SigningKeys::unb64( $point );
		if ( ! EcdsaP256::valid_public( $raw ) ) {
			return self::error( 'ffc_presence_bad_key', __( 'This device created a key the site cannot use.', 'ffcertificate' ), 400 );
		}
		if ( ! self::proof_holds( self::ENROL_DOMAIN . $user_id . '|' . $device_id . '|' . $timestamp, $proof, $raw, $timestamp, $now ) ) {
			return self::error( 'ffc_presence_bad_proof', __( 'This device could not prove it holds its key. Check the phone\'s clock and try again.', 'ffcertificate' ), 400 );
		}

		$key_id   = AudiencePresenceAttestation::key_id( $raw );
		$existing = AudiencePresenceReader::by_key_id( $key_id );
		if ( null !== $existing ) {
			if ( $existing['user_id'] === $user_id && $existing['device_id'] === $device_id && null === $existing['revoked_at'] ) {
				// The app asked twice for a key it already activated.
				return self::attest( $user, $raw, $key_id, $now );
			}
			return self::error( 'ffc_presence_key_used', __( 'This key cannot be activated again. Reinstall the app and try again.', 'ffcertificate' ), 409 );
		}

		$reserving = AudiencePresenceReader::reserving_user( $device_id, $user_id, $now );
		if ( null !== $reserving ) {
			return self::refuse( $user_id, $device_id, $key_id, self::REASON_RESERVED, $now );
		}

		$devices   = AudiencePresenceReader::for_user( $user_id );
		$is_change = array() !== $devices && $devices[0]['device_id'] !== $device_id;
		$approval  = null;
		if ( $is_change && AudiencePresenceReader::changes_since( $user_id, $now - self::CHANGE_WINDOW ) >= self::MAX_CHANGES ) {
			$approval = AudiencePresenceReader::request_id( $user_id, $device_id, self::REASON_CHANGE_LIMIT, AudiencePresenceWriter::REQUEST_APPROVED );
			if ( null === $approval ) {
				return self::refuse( $user_id, $device_id, $key_id, self::REASON_CHANGE_LIMIT, $now );
			}
		}

		// Sign before writing: a site key that cannot sign must leave the
		// user's current device untouched.
		$attestation = self::attest( $user, $raw, $key_id, $now );
		if ( is_wp_error( $attestation ) ) {
			return $attestation;
		}

		$signals      = substr( $signals, 0, 1024 );
		$signals_hash = '' === $signals ? null : Encryption::keyed_hash( $signals, 'presence_signals' );
		$family       = AudiencePresenceAgent::describe( $agent );
		$row_id       = AudiencePresenceWriter::activate( $user_id, $device_id, $key_id, $point, $signals_hash, $is_change, $now, $family['platform'], $family['browser'] );
		if ( $row_id <= 0 ) {
			return self::error( 'ffc_presence_not_saved', __( 'The device could not be activated. Try again.', 'ffcertificate' ), 500 );
		}
		if ( null !== $approval ) {
			AudiencePresenceWriter::use_request( $approval );
		}

		// A new key on the same phone retires the old key without moving the
		// presence anywhere, so only another phone warrants the e-mail.
		$replaced = false;
		foreach ( $devices as $device ) {
			if ( null === $device['revoked_at'] && AudiencePresenceWriter::revoke( $device['id'], AudiencePresenceWriter::REVOKED_REPLACED, null, $now ) ) {
				$replaced = $replaced || $device['device_id'] !== $device_id;
				self::log(
					'presence_device_revoked',
					ActivityLog::LEVEL_INFO,
					$user_id,
					array(
						'device_id' => $device['device_id'],
						'key_id'    => $device['key_id'],
						'reason'    => AudiencePresenceWriter::REVOKED_REPLACED,
					)
				);
			}
		}

		self::log(
			'presence_device_activated',
			ActivityLog::LEVEL_INFO,
			$user_id,
			array(
				'device_id'  => $device_id,
				'key_id'     => $key_id,
				'is_change'  => $is_change,
				'by_request' => null !== $approval,
			)
		);

		if ( null !== $signals_hash ) {
			$shared = AudiencePresenceReader::devices_sharing_signals( $signals_hash, $user_id );
			if ( array() !== $shared ) {
				self::log(
					'presence_signals_match',
					ActivityLog::LEVEL_WARNING,
					$user_id,
					array(
						'device_id'     => $device_id,
						'key_id'        => $key_id,
						'other_devices' => $shared,
					)
				);
			}
		}

		if ( $replaced ) {
			self::mail_replaced( $user, $now );
		}

		return $attestation;
	}

	/**
	 * Renew the attestation of an active device.
	 *
	 * @param int    $user_id   The user, taken from the session by the caller.
	 * @param string $key_id    The device key's id.
	 * @param int    $timestamp When the app signed the proof.
	 * @param string $proof     Base64url signature, by the device key, of the renewal statement.
	 * @param int    $now       Unix time.
	 * @return array{attestation: string, key_id: string, expires: int, name: string}|\WP_Error
	 */
	public static function renew( int $user_id, string $key_id, int $timestamp, string $proof, int $now ) {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return self::error( 'ffc_presence_unknown_key', __( 'This device is not activated for your account. Activate it again.', 'ffcertificate' ), 404 );
		}
		$device = self::active_device( $user_id, $key_id );
		if ( is_wp_error( $device ) ) {
			return $device;
		}
		$raw = SigningKeys::unb64( $device['public_key'] );
		if ( ! self::proof_holds( self::RENEW_DOMAIN . $user_id . '|' . $key_id . '|' . $timestamp, $proof, $raw, $timestamp, $now ) ) {
			return self::error( 'ffc_presence_bad_proof', __( 'This device could not prove it holds its key. Check the phone\'s clock and try again.', 'ffcertificate' ), 400 );
		}
		$attestation = self::attest( $user, $raw, $key_id, $now );
		if ( ! is_wp_error( $attestation ) ) {
			AudiencePresenceWriter::attested( $device['id'], $now );
		}
		return $attestation;
	}

	/**
	 * What the app's Activate presence screen shows (#2781): the phone active
	 * on the account now (its platform and browser families and since when),
	 * the device changes used in the change window, and how long a phone
	 * works offline.
	 *
	 * @param int $user_id User.
	 * @param int $now     Unix time.
	 * @return array{current: array{device_id: string, label: string, since: string}|null, changes: int, max_changes: int, window_days: int, offline_days: int}
	 */
	public static function status( int $user_id, int $now ): array {
		$current = null;
		foreach ( AudiencePresenceReader::for_user( $user_id ) as $device ) {
			if ( null === $device['revoked_at'] ) {
				$current = array(
					'device_id' => $device['device_id'],
					'label'     => AudiencePresenceAgent::label( $device['platform'], $device['browser'] ),
					'since'     => DateFormatter::format_date( (string) wp_date( 'Y-m-d', $device['activated_at'] ) ),
				);
				break;
			}
		}
		return array(
			'current'      => $current,
			'changes'      => AudiencePresenceReader::changes_since( $user_id, $now - self::CHANGE_WINDOW ),
			'max_changes'  => self::MAX_CHANGES,
			'window_days'  => (int) ( self::CHANGE_WINDOW / DAY_IN_SECONDS ),
			'offline_days' => (int) ( AudiencePresenceAttestation::TTL / DAY_IN_SECONDS ),
		);
	}

	/**
	 * The user's device that holds this key, while it still holds the code.
	 *
	 * Renewing asks it, and so does the app's event list (#2797), so a phone
	 * replaced by another stops drawing its code the next time it reaches the
	 * site instead of when its attestation comes due.
	 *
	 * @param int    $user_id The user, taken from the session by the caller.
	 * @param string $key_id  The device key's id.
	 * @return array<string, mixed>|\WP_Error The device row, or why it no longer counts.
	 */
	public static function active_device( int $user_id, string $key_id ) {
		$device = '' === $key_id ? null : AudiencePresenceReader::by_key_id( $key_id );
		if ( null === $device || $device['user_id'] !== $user_id ) {
			return self::error( 'ffc_presence_unknown_key', __( 'This device is not activated for your account. Activate it again.', 'ffcertificate' ), 404 );
		}
		if ( null !== $device['revoked_at'] ) {
			return self::error( 'ffc_presence_revoked', __( 'Your presence is now active on another phone, so this one stopped making the code.', 'ffcertificate' ), 410 );
		}
		return $device;
	}

	/**
	 * Whether a device id has the shape the app writes.
	 *
	 * @param string $device_id Device id.
	 */
	public static function valid_device_id( string $device_id ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{16,64}$/', $device_id );
	}

	/**
	 * Whether a proof is a fresh signature of a statement by a key.
	 *
	 * @param string $statement The signed bytes.
	 * @param string $proof     Base64url signature.
	 * @param string $raw       Raw public point.
	 * @param int    $timestamp When the app signed it.
	 * @param int    $now       Unix time.
	 */
	private static function proof_holds( string $statement, string $proof, string $raw, int $timestamp, int $now ): bool {
		return abs( $now - $timestamp ) <= self::PROOF_SKEW && EcdsaP256::verify( $statement, SigningKeys::unb64( $proof ), $raw );
	}

	/**
	 * Sign an attestation for the response.
	 *
	 * @param \WP_User $user   User.
	 * @param string   $raw    Raw public point.
	 * @param string   $key_id Device key id.
	 * @param int      $now    Unix time.
	 * @return array{attestation: string, key_id: string, expires: int, name: string}|\WP_Error
	 */
	private static function attest( \WP_User $user, string $raw, string $key_id, int $now ) {
		$name   = AudiencePresenceAttestation::display_name( $user );
		$issued = AudiencePresenceAttestation::issue( (int) $user->ID, $name, $raw, $now );
		if ( null === $issued ) {
			return self::error( 'ffc_presence_no_site_key', __( 'The site cannot sign presence codes right now. Contact the administrator.', 'ffcertificate' ), 500 );
		}
		return array(
			'attestation' => $issued['attestation'],
			'key_id'      => $key_id,
			'expires'     => $issued['expires'],
			'name'        => $name,
		);
	}

	/**
	 * Refuse an enrolment an administrator can unblock.
	 *
	 * @param int    $user_id   User.
	 * @param string $device_id Device id.
	 * @param string $key_id    Device key id.
	 * @param string $reason    One of the REASON_* constants.
	 * @param int    $now       Unix time.
	 * @return \WP_Error
	 */
	private static function refuse( int $user_id, string $device_id, string $key_id, string $reason, int $now ): \WP_Error {
		AudiencePresenceWriter::request( $user_id, $device_id, $reason, $now );
		self::log(
			'presence_enrolment_refused',
			ActivityLog::LEVEL_WARNING,
			$user_id,
			array(
				'device_id' => $device_id,
				'key_id'    => $key_id,
				'reason'    => $reason,
			)
		);

		return self::REASON_RESERVED === $reason
			? self::error( 'ffc_presence_device_reserved', __( 'This device is in use by another account. An administrator can release it; your request was sent.', 'ffcertificate' ), 403 )
			: self::error( 'ffc_presence_change_limit', __( 'You changed devices too many times in the last 30 days. An administrator can allow one more; your request was sent.', 'ffcertificate' ), 403 );
	}

	/**
	 * Tell the user a new device replaced theirs.
	 *
	 * @param \WP_User $user User.
	 * @param int      $now  Unix time.
	 */
	private static function mail_replaced( \WP_User $user, int $now ): void {
		if ( ! is_email( (string) $user->user_email ) ) {
			return;
		}
		$tokens = array(
			'user_name'    => (string) $user->display_name,
			'activated_at' => DateFormatter::format_datetime( $now ),
			'site_name'    => (string) get_bloginfo( 'name' ),
		);
		$html   = array_map( 'esc_html', $tokens );
		SchedulingMailer::send(
			(string) $user->user_email,
			TokenResolver::resolve( EmailTemplates::effective_body( 'presence-device-replaced', 'subject' ), $tokens ),
			TokenResolver::resolve( EmailTemplates::effective_body( 'presence-device-replaced' ), $html ),
			array(),
			true,
			EmailSource::ACCOUNT
		);
	}

	/**
	 * Write an activity-log entry.
	 *
	 * @param string               $action  Action.
	 * @param string               $level   Level.
	 * @param int                  $user_id The user the device belongs to.
	 * @param array<string, mixed> $context Context.
	 */
	private static function log( string $action, string $level, int $user_id, array $context ): void {
		ActivityLog::log( $action, $level, $context, $user_id );
	}

	/**
	 * A refusal with its HTTP status.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 */
	private static function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}
}

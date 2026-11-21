<?php
/**
 * CertificateSigner
 *
 * Signs a certificate's canonical record when it is issued or edited, and
 * tells the verification page whether the certificate still matches what was
 * signed (#2639).
 *
 * @package FreeFormCertificate\Submissions
 * @since 6.45.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Submissions;

use FreeFormCertificate\Core\ActivityLog;
use FreeFormCertificate\Core\CertificateRecord;
use FreeFormCertificate\Core\CertificateTemplateLink;
use FreeFormCertificate\Core\EventDate;
use FreeFormCertificate\Core\SigningKeys;
use FreeFormCertificate\Core\SubmitterName;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Signs certificates and checks them against their signed record.
 *
 * A form opts in through its QR mode (`_ffc_form_config['qr_mode']`): Link
 * only, the default, signs nothing; both signed modes sign every certificate
 * the form issues once a signing key exists. Signing never blocks issuing:
 * a failure issues the certificate as before and is recorded in the activity
 * log.
 */
final class CertificateSigner {

	/** QR mode: the link only, as before #2639. */
	public const MODE_LINK = 'link';

	/** QR mode: link plus a signature over code, date and record hash. */
	public const MODE_COMPACT = 'compact';

	/** QR mode: link plus a signature over code, date, name, course, hours and record hash. */
	public const MODE_FULL = 'full';

	/** The form config key holding the mode. */
	public const CONFIG_KEY = 'qr_mode';

	/** Activity log action of a certificate that could not be signed. */
	public const LOG_FAILED = 'certificate_signing_failed';

	/** Integrity: the certificate was never signed. */
	public const STATE_UNSIGNED = 'unsigned';

	/** Integrity: the signature holds and the certificate matches it. */
	public const STATE_INTACT = 'intact';

	/** Integrity: the signature holds and the certificate no longer matches it. */
	public const STATE_CHANGED = 'changed';

	/** Integrity: the stored signature itself does not verify. */
	public const STATE_INVALID = 'invalid';

	/**
	 * Every mode, in the order the form editor offers them.
	 *
	 * @return list<string>
	 */
	public static function modes(): array {
		return array( self::MODE_LINK, self::MODE_COMPACT, self::MODE_FULL );
	}

	/**
	 * A mode from any input; Link only when it is not one of the three.
	 *
	 * @param mixed $value Stored or posted value.
	 */
	public static function sanitize_mode( $value ): string {
		return is_string( $value ) && in_array( $value, self::modes(), true ) ? $value : self::MODE_LINK;
	}

	/**
	 * A form's QR mode.
	 *
	 * @param int $form_id Form post id.
	 */
	public static function mode( int $form_id ): string {
		$config = $form_id > 0 ? get_post_meta( $form_id, '_ffc_form_config', true ) : array();
		return self::sanitize_mode( is_array( $config ) ? ( $config[ self::CONFIG_KEY ] ?? '' ) : '' );
	}

	/**
	 * Register the hooks: a certificate is signed when it is issued and signed
	 * again when it is edited.
	 *
	 * Priority 20 on save so the activity log's own listener (10) has recorded
	 * the issue first; the update and delete hooks only need the default.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'ffcertificate_after_submission_save', array( self::class, 'on_saved' ), 20, 2 );
		add_action( 'ffcertificate_after_submission_update', array( self::class, 'on_updated' ), 10, 1 );
		add_action( 'ffcertificate_after_submission_delete', array( CertificateSignatureRepository::class, 'delete_for' ), 10, 1 );
	}

	/**
	 * A certificate was issued.
	 *
	 * @param int $submission_id Submission id.
	 * @param int $form_id       Form id.
	 * @return void
	 */
	public static function on_saved( $submission_id, $form_id = 0 ): void {
		if ( self::MODE_LINK === self::mode( (int) $form_id ) || ! SigningKeys::has_active() ) {
			return;
		}
		self::sign( (int) $submission_id );
	}

	/**
	 * A certificate was edited. Only a certificate already signed is signed
	 * again: editing does not opt a form in.
	 *
	 * @param int $submission_id Submission id.
	 * @return void
	 */
	public static function on_updated( $submission_id ): void {
		if ( null === CertificateSignatureRepository::current( (int) $submission_id ) || ! SigningKeys::has_active() ) {
			return;
		}
		$submission = ( new SubmissionHandler() )->get_submission( (int) $submission_id );
		if ( is_array( $submission ) ) {
			self::sign_if_changed( (int) $submission_id, $submission );
		}
	}

	/**
	 * Sign an edited certificate again, only when something its record
	 * covers changed: signing an unchanged record would only move the hash a
	 * printed QR code carries, and after a key rotation that reads as "data
	 * changed" on paper (#2696).
	 *
	 * @param int                  $submission_id Submission id.
	 * @param array<string, mixed> $submission    The decrypted row as it is now.
	 * @return bool Whether it was signed again.
	 */
	public static function sign_if_changed( int $submission_id, array $submission ): bool {
		$signed = CertificateSignatureRepository::current( $submission_id );
		if ( null === $signed || array() === self::changes_since( $signed, $submission ) ) {
			return false;
		}
		return self::sign( $submission_id, $submission );
	}

	/**
	 * Sign a certificate's current record with the active key.
	 *
	 * @param int                       $submission_id Submission id.
	 * @param array<string, mixed>|null $submission    The decrypted row, when the caller has it.
	 * @return bool Whether a signed record was stored.
	 */
	public static function sign( int $submission_id, ?array $submission = null ): bool {
		$key_id = SigningKeys::active_id();
		if ( '' !== $key_id && null === $submission ) {
			$submission = ( new SubmissionHandler() )->get_submission( $submission_id );
		}
		if ( ! is_array( $submission ) ) {
			return self::failed( $submission_id, '' === $key_id ? 'no_key' : 'not_found' );
		}

		$fields  = self::fields( $submission, $key_id );
		$record  = CertificateRecord::record( $fields );
		$hash    = CertificateRecord::hash( $record );
		$full    = SigningKeys::sign( CertificateRecord::full_payload( $key_id, $fields, $hash ) );
		$compact = SigningKeys::sign( CertificateRecord::compact_payload( $key_id, $fields, $hash ) );
		if ( null === $full || null === $compact ) {
			return self::failed( $submission_id, 'sign' );
		}

		if ( ! CertificateSignatureRepository::store( $submission_id, $key_id, $record, $hash, $full['signature'], $compact['signature'] ) ) {
			return self::failed( $submission_id, 'store' );
		}
		return true;
	}

	/**
	 * Whether a certificate still matches the record it was signed with.
	 *
	 * @param array<string, mixed> $submission The decrypted submission row.
	 * @return array{state: string, changed: list<string>, key: string, signed_at: int, issued: int}
	 */
	public static function integrity( array $submission ): array {
		$id = (int) ( $submission['id'] ?? 0 );
		// No key was ever generated: nothing can be signed, so the table is
		// not asked on every verification of an install that never opted in.
		$signed = $id > 0 && array() !== SigningKeys::public_keys() ? CertificateSignatureRepository::current( $id ) : null;
		$out    = array(
			'state'     => self::STATE_UNSIGNED,
			'changed'   => array(),
			'key'       => '',
			'signed_at' => 0,
			'issued'    => 0,
		);
		if ( null === $signed ) {
			return $out;
		}

		$out['key']       = $signed['key_id'];
		$out['signed_at'] = $signed['signed_at'];

		$signed_fields = CertificateRecord::fields_of( $signed['record'] );
		// The issue instant the record carries, so the page can tell a
		// signature made at issue from one the backfill card made later (#2674).
		$out['issued'] = isset( $signed_fields['issued'] ) && is_numeric( $signed_fields['issued'] ) ? (int) $signed_fields['issued'] : 0;
		$valid         = array() !== $signed_fields
			&& CertificateRecord::hash( $signed['record'] ) === $signed['record_hash']
			&& self::signature_holds( $signed, $signed_fields );
		if ( ! $valid ) {
			$out['state'] = self::STATE_INVALID;
			return $out;
		}

		$out['changed'] = self::changes_since( $signed, $submission );
		$out['state']   = array() === $out['changed'] ? self::STATE_INTACT : self::STATE_CHANGED;
		return $out;
	}

	/**
	 * Whether the stored signature covers the stored record.
	 *
	 * The payload's day is the one the site's timezone gave when it signed,
	 * so every day the issue instant can fall on is tried (#2696). The
	 * instant itself is in the record the hash covers, so this admits no
	 * other certificate.
	 *
	 * @param array{key_id: string, record_hash: string, signature_full: string} $signed        The stored signature row.
	 * @param array<string, string>                                              $signed_fields The record's fields.
	 */
	private static function signature_holds( array $signed, array $signed_fields ): bool {
		foreach ( CertificateRecord::dates_of_instant( $signed_fields['issued'] ?? '' ) as $day ) {
			if ( SigningKeys::verify( CertificateRecord::full_payload( $signed['key_id'], $signed_fields, $signed['record_hash'], $day ), $signed['signature_full'], $signed['key_id'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The record fields that differ between the signed record and the
	 * certificate as it is now.
	 *
	 * A record signed before the name was read through
	 * {@see SubmitterName::from()} carries the old reading, only `name`
	 * (#2696); it is compared with that reading, so the fix does not flag
	 * those certificates as changed.
	 *
	 * @param array{key_id: string, record: string} $signed     The stored signature row.
	 * @param array<string, mixed>                  $submission The decrypted submission row.
	 * @return list<string>
	 */
	private static function changes_since( array $signed, array $submission ): array {
		$signed_fields = CertificateRecord::fields_of( $signed['record'] );
		if ( array() === $signed_fields ) {
			return CertificateRecord::RECORD_FIELDS;
		}
		$current = self::fields( $submission, $signed['key_id'] );
		if ( '' === $signed_fields['name'] ) {
			$data            = SubmissionHandler::submission_data( $submission );
			$current['name'] = is_scalar( $data['name'] ?? null ) ? (string) $data['name'] : '';
		}
		return CertificateRecord::changed( $signed_fields, $current );
	}

	/**
	 * The record hash a certificate is signed with now, or '' when it is not
	 * signed. The verification page compares it with the hash a signed QR code
	 * carries, to tell a QR code printed for an earlier version (#2639).
	 *
	 * @param int $submission_id Submission id.
	 */
	public static function current_hash( int $submission_id ): string {
		if ( $submission_id <= 0 || array() === SigningKeys::public_keys() ) {
			return '';
		}
		$signed = CertificateSignatureRepository::current( $submission_id );
		return null === $signed ? '' : $signed['record_hash'];
	}

	/**
	 * A certificate's record fields as the database holds them now.
	 *
	 * @param array<string, mixed> $submission The decrypted submission row.
	 * @param string               $key_id     The key whose identifier MAC is used.
	 * @return array<string, string>
	 */
	public static function fields( array $submission, string $key_id ): array {
		$form_id             = (int) ( $submission['form_id'] ?? 0 );
		$data                = SubmissionHandler::submission_data( $submission );
		list( $start, $end ) = EventDate::range( $form_id );
		$values              = CertificateTemplateLink::values( $form_id );

		return array(
			'form_id'       => (string) $form_id,
			'submission_id' => (string) (int) ( $submission['id'] ?? 0 ),
			'auth_code'     => (string) ( $submission['auth_code'] ?? '' ),
			'issued'        => (string) (int) ( $submission['submission_date'] ?? 0 ),
			// The form's own name field, whatever its key (#2696).
			'name'          => SubmitterName::from( $data ),
			'identifier'    => SigningKeys::identifier_mac( (string) ( $submission['cpf_rf'] ?? '' ), $key_id ),
			'course'        => self::form_title( $form_id ),
			'workload'      => (string) ( $values['workload'] ?? '' ),
			'event_start'   => $start,
			'event_end'     => $end,
		);
	}

	/**
	 * A form's title as stored: `get_the_title()` would run display filters
	 * and turn a dash into an entity, which is not what the certificate says.
	 *
	 * @param int $form_id Form post id.
	 */
	private static function form_title( int $form_id ): string {
		$post = $form_id > 0 ? get_post( $form_id ) : null;
		return $post instanceof \WP_Post ? (string) $post->post_title : '';
	}

	/**
	 * Record a certificate that could not be signed. Issuing goes on.
	 *
	 * @param int    $submission_id Submission id.
	 * @param string $reason        Short machine reason.
	 * @return false
	 */
	private static function failed( int $submission_id, string $reason ): bool {
		ActivityLog::log( self::LOG_FAILED, ActivityLog::LEVEL_WARNING, array( 'reason' => $reason ), 0, $submission_id );
		return false;
	}
}

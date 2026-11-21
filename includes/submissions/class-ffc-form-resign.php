<?php
/**
 * Signing a form's certificates again after the form itself changed.
 *
 * @package FreeFormCertificate\Submissions
 */

declare(strict_types=1);

namespace FreeFormCertificate\Submissions;

use FreeFormCertificate\Core\ActivityLog;
use FreeFormCertificate\Core\CertificateRecord;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Re-sign with current data" for one form (#2696).
 *
 * The title, the workload and the event dates are the form's, and every one
 * of its certificates carries them in its signed record. Editing them on the
 * form makes every signed certificate read as "changed after signing", with
 * nothing wrong with any of them. Signing them again is the operator's call,
 * made explicitly and recorded in the activity log, because a signature
 * attests what the site holds when it signs.
 *
 * The walk keeps a cursor in the form's meta and advances over every
 * certificate it EXAMINES, so a certificate that cannot be signed never
 * stops it (the #1378 rule).
 */
final class FormResign {

	/**
	 * The walk's cursor and totals while it runs.
	 */
	public const META = '_ffc_resign_state';

	/**
	 * Certificates examined per request.
	 */
	public const BATCH = 100;

	/**
	 * The activity-log action of a finished walk.
	 */
	public const LOG_ACTION = 'certificates_resigned';

	/**
	 * Whether the form's certificates were signed with form fields it no
	 * longer has, read from the most recently signed one.
	 *
	 * @param int $form_id Form id.
	 */
	public static function is_stale( int $form_id ): bool {
		$latest = CertificateSignatureRepository::latest_for_form( $form_id );
		if ( null === $latest ) {
			return false;
		}
		$signed = CertificateRecord::fields_of( $latest['record'] );
		if ( array() === $signed ) {
			return false;
		}
		foreach ( CertificateSigner::form_fields( $form_id ) as $name => $value ) {
			if ( CertificateRecord::normalize( $signed[ $name ] ?? '' ) !== CertificateRecord::normalize( $value ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a walk started and has certificates left.
	 *
	 * @param int $form_id Form id.
	 */
	public static function in_progress( int $form_id ): bool {
		return array() !== self::state( $form_id );
	}

	/**
	 * Sign the next batch of the form's signed certificates again.
	 *
	 * @param int           $form_id Form id.
	 * @param callable|null $load    Reads a decrypted submission row by id; the handler's lookup when null.
	 * @return array{examined: int, signed: int, done: bool} The walk's totals so far.
	 */
	public static function run_batch( int $form_id, ?callable $load = null ): array {
		$state = self::state( $form_id ) + array(
			'cursor'   => 0,
			'examined' => 0,
			'signed'   => 0,
		);
		$ids   = CertificateSignatureRepository::signed_for_form( $form_id, $state['cursor'], self::BATCH );

		if ( null === $load ) {
			$handler = new SubmissionHandler();
			$load    = static fn( int $id ) => $handler->get_submission( $id );
		}
		foreach ( $ids as $id ) {
			$state['cursor'] = $id;
			++$state['examined'];
			$submission = $load( $id );
			if ( is_array( $submission ) && CertificateSigner::sign_if_changed( $id, $submission ) ) {
				++$state['signed'];
			}
		}

		$done = count( $ids ) < self::BATCH;
		if ( $done ) {
			delete_post_meta( $form_id, self::META );
			ActivityLog::log(
				self::LOG_ACTION,
				ActivityLog::LEVEL_INFO,
				array(
					'form_id'  => $form_id,
					'examined' => $state['examined'],
					'signed'   => $state['signed'],
				)
			);
		} else {
			update_post_meta( $form_id, self::META, $state );
		}

		return array(
			'examined' => $state['examined'],
			'signed'   => $state['signed'],
			'done'     => $done,
		);
	}

	/**
	 * The stored walk, or an empty array when none runs.
	 *
	 * @param int $form_id Form id.
	 * @return array{cursor: int, examined: int, signed: int}|array{}
	 */
	private static function state( int $form_id ): array {
		$stored = get_post_meta( $form_id, self::META, true );
		if ( ! is_array( $stored ) || ! isset( $stored['cursor'] ) ) {
			return array();
		}
		return array(
			'cursor'   => (int) $stored['cursor'],
			'examined' => (int) ( $stored['examined'] ?? 0 ),
			'signed'   => (int) ( $stored['signed'] ?? 0 ),
		);
	}
}

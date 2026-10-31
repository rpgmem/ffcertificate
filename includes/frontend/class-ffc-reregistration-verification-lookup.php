<?php
/**
 * ReregistrationVerificationLookup
 *
 * Finds a reregistration record for the verification page (by auth code) and
 * the magic link (by magic token). Split out of `VerificationHandler`
 * (#2241), which keeps the routing between kinds; the two near-identical
 * result builders it carried are one here.
 *
 * @package FreeFormCertificate\Frontend
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

use FreeFormCertificate\Reregistration\ReregistrationRepository;
use FreeFormCertificate\Reregistration\ReregistrationSubmissionReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reregistration lookup for verification.
 *
 * @phpstan-import-type ReregistrationRow from \FreeFormCertificate\Reregistration\ReregistrationRepository
 * @phpstan-import-type ReregistrationSubmissionRow from \FreeFormCertificate\Reregistration\ReregistrationSubmissionReader
 */
class ReregistrationVerificationLookup {

	/**
	 * The reregistration record an auth code names.
	 *
	 * @since 4.12.0
	 * @param string $code Cleaned auth code (no hyphens, uppercase).
	 * @return array<string, mixed> Result array with 'found', 'submission', 'data', 'type'.
	 */
	public function by_code( string $code ): array {
		if ( ! class_exists( '\\FreeFormCertificate\\Reregistration\\ReregistrationSubmissionReader' ) ) {
			return VerificationResult::not_found();
		}

		$submission = ReregistrationSubmissionReader::get_by_auth_code( $code );

		return $submission ? $this->result( $submission, false, 'auth_code' ) : VerificationResult::not_found();
	}

	/**
	 * The reregistration record a magic token names.
	 *
	 * @since 4.12.0
	 * @param string $token Magic token (64 hex chars).
	 * @return array<string, mixed> Result array with 'found', 'submission', 'data', 'type', 'magic_token'.
	 */
	public function by_token( string $token ): array {
		if ( ! class_exists( '\\FreeFormCertificate\\Reregistration\\ReregistrationSubmissionReader' ) ) {
			return VerificationResult::not_found();
		}

		$submission = ReregistrationSubmissionReader::get_by_magic_token( $token );

		return $submission ? $this->result( $submission, true, 'magic_link' ) : VerificationResult::not_found();
	}

	/**
	 * Build the result from a reregistration submission row.
	 *
	 * THE PUBLIC PAGE GETS THE BASICS, NOT THE RECORD (#2242)
	 *
	 * Verification confirms that an approved record exists and whose it is:
	 * the campaign, the name, the masked CPF and e-mail. The answers stay out
	 * of the result (`data` is empty), and the Record PDF is downloaded only
	 * by a logged-in owner or staff member from the dashboard or the
	 * submissions screen. Every hit writes its own reregistration log row, on
	 * the code path and the magic-link path alike.
	 *
	 * @param object $submission       Submission row.
	 * @param bool   $with_magic_token Whether the result carries the record's magic token (the magic-link path).
	 * @param string $method           How the record was reached: `auth_code` or `magic_link`.
	 * @phpstan-param ReregistrationSubmissionRow $submission
	 * @return array<string, mixed>
	 */
	private function result( object $submission, bool $with_magic_token, string $method ): array {
		$rereg  = ReregistrationRepository::get_by_id( (int) $submission->reregistration_id );
		$user   = get_userdata( (int) $submission->user_id );
		$fields = $this->decode_submission_fields( $submission, $rereg );

		$status_labels = ReregistrationSubmissionReader::get_status_labels();

		if ( class_exists( '\\FreeFormCertificate\\Core\\ActivityLog' ) ) {
			\FreeFormCertificate\Core\ActivityLog::log(
				'reregistration_record_verified',
				\FreeFormCertificate\Core\ActivityLog::LEVEL_INFO,
				array(
					'method'            => $method,
					'reregistration_id' => (int) $submission->reregistration_id,
				),
				0,
				(int) $submission->id
			);
		}

		$result = array(
			'found'      => true,
			'submission' => $submission,
			'data'       => array(),
			'type'       => 'reregistration',
		);
		if ( $with_magic_token ) {
			$result['magic_token'] = $submission->magic_token;
		}
		$result['reregistration'] = array(
			'title'         => $rereg ? $rereg->title : '',
			'display_name'  => $fields['display_name'] ?? ( $user ? $user->display_name : '' ),
			'cpf'           => $fields['cpf'] ?? '',
			'email'         => $user ? $user->user_email : '',
			'status'        => $submission->status,
			'status_label'  => $status_labels[ $submission->status ] ?? $submission->status,
			'auth_code'     => $submission->auth_code,
			'submitted_at'  => $submission->submitted_at ?? '',
			'submission_id' => (int) $submission->id,
			// 6.7.5 — Campaign window dates surfaced so the /valid renderer can
			// show "Period: X — Y" alongside the status. Wall-clock dates
			// (Category B per CLAUDE.md).
			'start_date'    => $rereg ? (string) ( $rereg->start_date ?? '' ) : '',
			'end_date'      => $rereg ? (string) ( $rereg->end_date ?? '' ) : '',
		);

		return $result;
	}

	/**
	 * Decode a reregistration submission's stored fields, decrypting any
	 * values whose field definition is marked as sensitive.
	 *
	 * @since 5.0.0
	 * @param object      $submission Submission row.
	 * @param object|null $rereg      Reregistration row.
	 * @phpstan-param ReregistrationSubmissionRow $submission
	 * @phpstan-param ReregistrationRow|null $rereg
	 * @return array<string, mixed> field_key => plain value.
	 */
	private function decode_submission_fields( object $submission, $rereg ): array {
		$sub_data = $submission->data ? json_decode( $submission->data, true ) : array();
		$values   = is_array( $sub_data['fields'] ?? null ) ? $sub_data['fields'] : array();

		if ( ! $rereg || ! class_exists( '\\FreeFormCertificate\\Reregistration\\CustomFieldReader' ) ) {
			return $values;
		}

		if ( ! class_exists( '\\FreeFormCertificate\\Core\\Encryption' ) ) {
			return $values;
		}

		// Gather every active field for the reregistration's audiences, then hand
		// the map to the shared reader.
		//
		// THE DECRYPTION LOOP THAT USED TO BE HERE WAS A THIRD COPY (#1509), and
		// it read `is_sensitive` to decide — a flag an administrator edits, while
		// the value in the row was written under whatever it said then. Turning it
		// off made this method return CIPHERTEXT, which the public verification
		// page then printed as the person's CPF. The reader decides from the
		// envelope now, so the flag is not consulted and neither direction of a
		// flag change can misread. Only the field KEYS are gathered here.
		$fields = \FreeFormCertificate\Reregistration\CustomFieldReader::get_for_audiences(
			ReregistrationRepository::get_audience_ids( (int) $rereg->id )
		);

		return \FreeFormCertificate\Reregistration\RecordGenerator::decrypt_field_values( $fields, $values );
	}
}

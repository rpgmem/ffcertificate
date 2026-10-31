<?php
/**
 * Recruitment Candidate Editor
 *
 * The one write path for a candidate's General fields, shared by the edit
 * screen and `PATCH /candidates/{id}` (#2245).
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.44.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

use FreeFormCertificate\Core\DataSanitizer;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Core\SensitiveFieldRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies the edit screen's rules to a candidate update and logs the diff.
 *
 * The REST route used to write CPF, RF and e-mail for any holder of the
 * manage capability, with no tier check and no change log, while the edit
 * screen allowed neither. Both now go through here, so the rules are one:
 *
 * - name, phone and notes are written by whoever may edit the candidate;
 * - the e-mail is written only by whoever may see it — the candidate, or a
 *   holder of the reveal tier ({@see RecruitmentPiiAccessPolicy});
 * - CPF and RF are never written here: they are the candidate's identity
 *   across every notice, and an import never overwrites them either;
 * - every change is logged as `recruitment_candidate_fields_edited`, with
 *   the e-mail as hashes, never in clear.
 *
 * @phpstan-import-type CandidateRow from RecruitmentCandidateReader
 */
final class RecruitmentCandidateEditor {

	/**
	 * Fields the editor writes.
	 *
	 * @var list<string>
	 */
	public const FIELDS = array( 'name', 'phone', 'notes', 'email' );

	/**
	 * Identifier fields no edit may change.
	 *
	 * @var list<string>
	 */
	public const IDENTIFIERS = array( 'cpf', 'rf' );

	/**
	 * Apply an update to a candidate.
	 *
	 * Only the keys present in `$input` change. An empty phone, notes or
	 * e-mail clears the stored value.
	 *
	 * @param object               $before Candidate row before the update.
	 * @phpstan-param CandidateRow $before
	 * @param array<string, mixed> $input  Field → raw value.
	 * @return true|\WP_Error
	 */
	public static function update( object $before, array $input ) {
		foreach ( self::IDENTIFIERS as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				return new \WP_Error( 'recruitment_candidate_identifier_not_editable', '', array( 'status' => 400 ) );
			}
		}

		$id      = (int) $before->id;
		$update  = array();
		$changes = array();

		foreach ( array( 'name', 'phone', 'notes' ) as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$raw   = is_scalar( $input[ $key ] ) ? (string) $input[ $key ] : '';
			$value = 'notes' === $key ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw );

			$update[ $key ] = 'name' === $key || '' !== $value ? $value : null;

			$old = null === $before->{$key} ? '' : (string) $before->{$key};
			if ( $old !== $value ) {
				$changes[ $key ] = array(
					'old' => $old,
					'new' => $value,
				);
			}
		}

		if ( array_key_exists( 'email', $input ) ) {
			$tier = RecruitmentPiiAccessPolicy::resolve( $before, get_current_user_id() );
			if ( RecruitmentPiiAccessPolicy::TIER_MASKED === $tier ) {
				return new \WP_Error( 'recruitment_candidate_email_not_editable', '', array( 'status' => 403 ) );
			}
			$raw   = is_scalar( $input['email'] ) ? (string) $input['email'] : '';
			$email = DataSanitizer::normalize_email( sanitize_email( $raw ) );
			if ( '' === $email ) {
				$update['email_encrypted'] = null;
				$update['email_hash']      = null;
				$new_hash                  = '';
			} else {
				$update['email_encrypted'] = Encryption::encrypt( $email );
				$new_hash                  = (string) SensitiveFieldRegistry::hash_identifier( 'email', $email );
				$update['email_hash']      = $new_hash;
			}
			$old_hash = null === $before->email_hash ? '' : (string) $before->email_hash;
			if ( $old_hash !== $new_hash ) {
				$changes['email_hash'] = array(
					'old' => $old_hash,
					'new' => $new_hash,
				);
			}
		}

		if ( array() === $update ) {
			return new \WP_Error( 'recruitment_candidate_update_no_writable_fields', '', array( 'status' => 400 ) );
		}

		if ( ! RecruitmentCandidateWriter::update( $id, $update ) ) {
			return new \WP_Error( 'recruitment_candidate_update_failed', '', array( 'status' => 409 ) );
		}

		RecruitmentActivityLogger::candidate_fields_edited( $id, $changes );
		return true;
	}
}

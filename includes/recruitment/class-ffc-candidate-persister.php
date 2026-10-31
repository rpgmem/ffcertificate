<?php
/**
 * Recruitment Candidate Persister
 *
 * Shared candidate-persistence cluster extracted from
 * {@see RecruitmentCsvImporter} (#563 Sprint 6, PR 6c). Both the synchronous
 * import flow (`RecruitmentCsvImporter::run()`) and the staged batched flow
 * (`CsvStagingService::promote_batch()`) write candidates through this one
 * class, so the lookup-then-create logic and the encryption/hash handling live
 * in a single place rather than being duplicated across the two flows. PCD
 * is not among them: it is written on the classification (#2245). Neither flow creates or links a WordPress
 * account any more: {@see RecruitmentAccountLinker} does that after the
 * import has committed, and only for a notice past `draft` (#2245).
 *
 * Also holds the per-notice adjutancy slug→id resolver, which both flows
 * need before they can map a row's `adjutancy` column to a foreign key.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.12.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Core\SensitiveFieldRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Candidate upsert + adjutancy-map service for recruitment imports.
 */
final class CandidatePersister {

	/**
	 * Find or create a candidate row for the given input row.
	 *
	 * Existing candidate (matched by cpf_hash, then rf_hash) is reused —
	 * additional rows in `classification` will reference it, and its stored
	 * name and identifiers are kept. Returns false when the CPF and the RF
	 * belong to different candidates.
	 *
	 * PCD is not written here (#2245): it belongs to the notice
	 * classification the importer creates, because the same person can be
	 * PCD in one notice and general in another. The candidate's `pcd_hash`
	 * column is left empty on new rows and untouched on existing ones.
	 *
	 * No account is created or linked here (#2245). This ran inside the
	 * import transaction, so a rolled-back import still sent every new user
	 * its welcome e-mail, and a draft notice created accounts nobody had
	 * been told about.
	 *
	 * @param array<string, mixed> $row Validated row.
	 * @return int|false Candidate ID, or false on DB failure.
	 */
	public static function upsert_candidate( array $row ) {
		$cpf   = is_string( $row['cpf'] ) ? trim( $row['cpf'] ) : '';
		$rf    = is_string( $row['rf'] ) ? trim( $row['rf'] ) : '';
		$email = is_string( $row['email'] ) ? \FreeFormCertificate\Core\DataSanitizer::normalize_email( $row['email'] ) : '';
		$name  = is_string( $row['name'] ) ? trim( $row['name'] ) : '';
		$phone = is_string( $row['phone'] ) ? trim( $row['phone'] ) : '';

		$cpf_hash   = SensitiveFieldRegistry::hash_identifier( 'cpf', $cpf );
		$rf_hash    = SensitiveFieldRegistry::hash_identifier( 'rf', $rf );
		$email_hash = SensitiveFieldRegistry::hash_identifier( 'email', $email );

		// Look up existing candidate by cpf, then rf.
		$by_cpf = null !== $cpf_hash ? RecruitmentCandidateReader::get_by_cpf_hash( $cpf_hash ) : null;
		$by_rf  = null !== $rf_hash ? RecruitmentCandidateReader::get_by_rf_hash( $rf_hash ) : null;

		// A CPF of one candidate and an RF of another: no write can be right
		// about both. RecruitmentImportIdentityCheck refuses the file before
		// it gets here; this stops a caller that skipped it (#2245).
		if ( null !== $by_cpf && null !== $by_rf && (int) $by_cpf->id !== (int) $by_rf->id ) {
			return false;
		}

		$existing = $by_cpf ?? $by_rf;

		if ( null !== $existing ) {
			$candidate_id = (int) $existing->id;

			// The stored identity is never overwritten (#2245): a CSV for one
			// notice used to rewrite the name, CPF, RF and e-mail the
			// candidate has in every notice. An identifier is only filled in
			// where none is stored; a differing one was reported to the
			// operator by RecruitmentImportIdentityCheck and stays as it is.
			// The phone is contact data and follows the latest list.
			RecruitmentCandidateWriter::update(
				$candidate_id,
				array_filter(
					array(
						'phone'           => '' !== $phone ? $phone : null,
						'cpf_encrypted'   => '' !== $cpf && empty( $existing->cpf_hash ) ? Encryption::encrypt( $cpf ) : null,
						'cpf_hash'        => empty( $existing->cpf_hash ) ? $cpf_hash : null,
						'rf_encrypted'    => '' !== $rf && empty( $existing->rf_hash ) ? Encryption::encrypt( $rf ) : null,
						'rf_hash'         => empty( $existing->rf_hash ) ? $rf_hash : null,
						'email_encrypted' => '' !== $email && empty( $existing->email_hash ) ? Encryption::encrypt( $email ) : null,
						'email_hash'      => empty( $existing->email_hash ) ? $email_hash : null,
					),
					static fn( $v ): bool => null !== $v
				)
			);

			return $candidate_id;
		}

		// New candidate. `pcd_hash` is NOT NULL on the table and no longer
		// carries PCD (#2245), so it is stored empty.
		$insert_payload = array(
			'name'     => $name,
			'pcd_hash' => '',
		);
		if ( '' !== $cpf ) {
			$insert_payload['cpf_encrypted'] = Encryption::encrypt( $cpf );
			$insert_payload['cpf_hash']      = $cpf_hash;
		}
		if ( '' !== $rf ) {
			$insert_payload['rf_encrypted'] = Encryption::encrypt( $rf );
			$insert_payload['rf_hash']      = $rf_hash;
		}
		if ( '' !== $email ) {
			$insert_payload['email_encrypted'] = Encryption::encrypt( $email );
			$insert_payload['email_hash']      = $email_hash;
		}
		if ( '' !== $phone ) {
			$insert_payload['phone'] = $phone;
		}

		$candidate_id = RecruitmentCandidateWriter::create( $insert_payload );
		if ( false === $candidate_id ) {
			return false;
		}

		return (int) $candidate_id;
	}

	/**
	 * Build the slug → id map for adjutancies attached to a notice.
	 *
	 * @param int $notice_id Notice ID.
	 * @return array<string, int> Slug → adjutancy ID.
	 */
	public static function build_adjutancy_map( int $notice_id ): array {
		$ids = RecruitmentNoticeAdjutancyRepository::get_adjutancy_ids_for_notice( $notice_id );
		if ( empty( $ids ) ) {
			return array();
		}

		$map = array();
		foreach ( $ids as $id ) {
			$adjutancy = RecruitmentAdjutancyReader::get_by_id( $id );
			if ( null !== $adjutancy ) {
				$map[ $adjutancy->slug ] = $id;
			}
		}

		return $map;
	}
}

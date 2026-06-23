<?php
/**
 * Recruitment Candidate Persister
 *
 * Shared candidate-persistence cluster extracted from
 * {@see RecruitmentCsvImporter} (#563 Sprint 6, PR 6c). Both the synchronous
 * import flow (`RecruitmentCsvImporter::run()`) and the staged batched flow
 * (`CsvStagingService::promote_batch()`) write candidates through this one
 * class, so the lookup-then-create logic, the encryption/hash handling, the
 * `pcd_hash` recompute, and the best-effort wp_user promotion live in a
 * single place rather than being duplicated across the two flows.
 *
 * Also holds the per-notice adjutancy slug→id resolver, which both flows
 * need before they can map a row's `adjutancy` column to a foreign key.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.11.3
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\UserDashboard\CapabilityManager;
use FreeFormCertificate\UserDashboard\UserCreator;

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
	 * additional rows in `classification` will reference it. New candidates
	 * are inserted with a placeholder `pcd_hash`, then updated with the
	 * proper HMAC once `candidate_id` is known.
	 *
	 * Promotion to wp_user is delegated to {@see UserCreator::get_or_create_user}
	 * with the recruitment context — failures are silent (candidate stays
	 * with `user_id = NULL`, which is the intended "not yet promoted" state).
	 *
	 * @param array<string, mixed> $row Validated row.
	 * @return int|false Candidate ID, or false on DB failure.
	 */
	public static function upsert_candidate( array $row ) {
		$cpf   = is_string( $row['cpf'] ) ? trim( $row['cpf'] ) : '';
		$rf    = is_string( $row['rf'] ) ? trim( $row['rf'] ) : '';
		$email = is_string( $row['email'] ) ? strtolower( trim( $row['email'] ) ) : '';
		$name  = is_string( $row['name'] ) ? trim( $row['name'] ) : '';
		$phone = is_string( $row['phone'] ) ? trim( $row['phone'] ) : '';
		$pcd   = CsvParser::parse_pcd_flag( $row['pcd'] );

		$cpf_hash   = '' !== $cpf ? Encryption::hash( $cpf ) : null;
		$rf_hash    = '' !== $rf ? Encryption::hash( $rf ) : null;
		$email_hash = '' !== $email ? Encryption::hash( $email ) : null;

		// Look up existing candidate by cpf, then rf.
		$existing = null;
		if ( null !== $cpf_hash ) {
			$existing = RecruitmentCandidateReader::get_by_cpf_hash( $cpf_hash );
		}
		if ( null === $existing && null !== $rf_hash ) {
			$existing = RecruitmentCandidateReader::get_by_rf_hash( $rf_hash );
		}

		if ( null !== $existing ) {
			$candidate_id = (int) $existing->id;

			// Refresh mutable + previously-empty fields on the existing row;
			// re-derive PCD hash so the new value (if any) takes effect.
			RecruitmentCandidateWriter::update(
				$candidate_id,
				array_filter(
					array(
						'name'            => '' !== $name ? $name : null,
						'phone'           => '' !== $phone ? $phone : null,
						'cpf_encrypted'   => '' !== $cpf ? Encryption::encrypt( $cpf ) : null,
						'cpf_hash'        => $cpf_hash,
						'rf_encrypted'    => '' !== $rf ? Encryption::encrypt( $rf ) : null,
						'rf_hash'         => $rf_hash,
						'email_encrypted' => '' !== $email ? Encryption::encrypt( $email ) : null,
						'email_hash'      => $email_hash,
					),
					static fn( $v ): bool => null !== $v
				)
			);

			self::refresh_pcd_hash( $candidate_id, $pcd );
			self::maybe_promote_candidate( $candidate_id, $cpf_hash, $rf_hash, $email );

			return $candidate_id;
		}

		// New candidate: insert with placeholder pcd_hash, then UPDATE.
		$insert_payload = array(
			'name'     => $name,
			'pcd_hash' => 'pending',
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

		self::refresh_pcd_hash( (int) $candidate_id, $pcd );
		self::maybe_promote_candidate( (int) $candidate_id, $cpf_hash, $rf_hash, $email );

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
			$adjutancy = RecruitmentAdjutancyRepository::get_by_id( $id );
			if ( null !== $adjutancy ) {
				$map[ $adjutancy->slug ] = $id;
			}
		}

		return $map;
	}

	/**
	 * Recompute and persist `pcd_hash` for a candidate.
	 *
	 * @param int  $candidate_id Candidate ID.
	 * @param bool $is_pcd       Whether the candidate is registered as PCD.
	 * @return void
	 */
	private static function refresh_pcd_hash( int $candidate_id, bool $is_pcd ): void {
		global $wpdb;
		$table = RecruitmentCandidateReader::get_table_name();
		$hash  = RecruitmentPcdHasher::compute( $candidate_id, $is_pcd );

		$wpdb->update(
			$table,
			array(
				'pcd_hash'   => $hash,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $candidate_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Best-effort wp_user link via UserCreator (no-op when nothing matches).
	 *
	 * UserCreator handles the §4 trigger logic: hash lookup against
	 * `ffc_submissions`, email lookup against `wp_users`, and (for new emails)
	 * user creation. Failures (e.g. `wp_create_user` rejecting an empty email)
	 * are intentionally swallowed — the candidate stays `user_id=NULL`, which
	 * is the documented "not yet promoted" state.
	 *
	 * @param int         $candidate_id Candidate row ID.
	 * @param string|null $cpf_hash     SHA-256 hash of CPF (or null).
	 * @param string|null $rf_hash      SHA-256 hash of RF (or null).
	 * @param string      $email        Lowercased email (may be empty).
	 * @return void
	 */
	private static function maybe_promote_candidate( int $candidate_id, ?string $cpf_hash, ?string $rf_hash, string $email ): void {
		if ( null === $cpf_hash && null === $rf_hash && '' === $email ) {
			return;
		}

		if ( ! class_exists( UserCreator::class ) ) {
			return;
		}

		// Recruitment is the only flow where a single row can carry BOTH
		// a CPF and an RF. The legacy single-hash entry point would miss
		// a previously-registered submission keyed on whichever hash we
		// didn't pass — `get_or_create_user_dual` checks both columns in
		// a single SQL pass and falls back to email matching identically.
		$user_id = UserCreator::get_or_create_user_dual(
			$cpf_hash,
			$rf_hash,
			$email,
			array(),
			CapabilityManager::CONTEXT_RECRUITMENT
		);

		if ( is_int( $user_id ) && $user_id > 0 ) {
			RecruitmentCandidateWriter::set_user_id( $candidate_id, $user_id );
			RecruitmentActivityLogger::candidate_promoted( $candidate_id, $user_id );
		}
	}
}

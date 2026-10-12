<?php
/**
 * Candidacy merge
 *
 * Folds one person's mistyped candidacy into their sound one, so that an
 * identity consolidation can complete where `ffc_recruitment_candidate`
 * holds both identifiers.
 *
 * @package FreeFormCertificate\Maintenance
 * @since 6.38.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Maintenance;

use FreeFormCertificate\Recruitment\RecruitmentPcdHasher;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement here targets the plugin's own ffc_recruitment_* tables, for which WordPress exposes no API, and a merge must read and write the live rows inside the caller's transaction: a cached answer would be precisely wrong.
/**
 * Move a candidacy's classifications onto another candidacy of the same person.
 *
 * WHY A CONSOLIDATION NEEDS THIS AT ALL
 *
 * `ffc_recruitment_candidate` declares `UNIQUE KEY uq_cpf_hash` and
 * `uq_rf_hash`: one candidacy per person, reused across notices through
 * `ffc_recruitment_classification`. When a CSV import carried a mistyped
 * number, the same person got a SECOND candidacy, and correcting its number
 * collides with the first. `IdentityRepair` used to refuse there and tell the
 * operator to "decide what becomes of the second record" -- with no screen
 * anywhere able to make that decision, so the finding could never leave the
 * queue.
 *
 * WHAT MOVES AND WHAT NEVER CHANGES
 *
 * Classifications move by `candidate_id` alone: rank, score, status, preview
 * status and list stay byte-for-byte as they were. Calls hang from the
 * classification, not the candidacy, so they follow without being touched.
 * The emptied candidacy is then deleted, and the survivor only gains values
 * it did not have -- it never loses or overwrites one.
 *
 * WHAT IS REFUSED, BECAUSE IT IS A DECISION AND NOT A CORRECTION
 *
 * - Both candidacies classified on the same notice, adjutancy and list with
 *   DIFFERENT standings: the unique key allows one per person there, and
 *   which one counts is for HR to decide. Merging would mean deleting one.
 *
 * Two classifications on one notice, adjutancy and list that are IDENTICAL
 * -- same rank, score, time points, EMEBS flag, preview status and reason,
 * never called -- are the exception, and the common one: a preliminary list
 * promoted under the mistyped number and a definitive list re-imported under
 * the right one. Dropping the merged candidacy's copy loses nothing, because
 * the survivor holds the same row; any difference, or any call, refuses.
 * - A different PCD status. PCD is an attribute of the CANDIDACY, so moving a
 *   classification would silently change the condition it competes under.
 * - The other identifier filled on both with different values: that is a
 *   finding of its own, not a gap to fill.
 *
 * Everything runs inside the caller's transaction: {@see self::apply()} never
 * opens, commits or rolls back one.
 */
class CandidacyMerge {

	/**
	 * Candidate columns the survivor takes from the merged candidacy when it
	 * has none of its own. Pairs travel together: a ciphertext without its
	 * hash is unsearchable, and a hash without its ciphertext unreadable.
	 *
	 * @var array<int, array<int, string>>
	 */
	private const FILLABLE = array(
		array( 'cpf_encrypted', 'cpf_hash' ),
		array( 'rf_encrypted', 'rf_hash' ),
		array( 'email_encrypted', 'email_hash' ),
		array( 'phone' ),
	);

	/**
	 * Whether two candidacies can be merged, without writing anything.
	 *
	 * @param int    $from  The candidacy that goes away.
	 * @param int    $into  The candidacy that stays.
	 * @param string $field The identifier being consolidated, `rf` or `cpf`.
	 * @return true|WP_Error
	 */
	public function check( int $from, int $into, string $field ): bool|WP_Error {
		$pair = $this->load( $from, $into );

		if ( is_wp_error( $pair ) ) {
			return $pair;
		}

		list( $source, $target ) = $pair;

		if ( (int) ( $source['user_id'] ?? 0 ) !== (int) ( $target['user_id'] ?? 0 ) ) {
			return new WP_Error(
				'ffc_identity_candidacy_accounts',
				__( 'The two candidacies belong to different accounts, so folding one into the other would merge two people. Decide the merge under "Two accounts, one number" first.', 'ffcertificate' )
			);
		}

		$pcd = $this->is_pcd( $target );

		if ( null === $pcd || $this->is_pcd( $source ) !== $pcd ) {
			return new WP_Error(
				'ffc_identity_candidacy_pcd',
				__( 'The two candidacies do not record the same PCD condition, and PCD belongs to the candidacy: merging them would change the condition one of the classifications competes under. Correct the candidacy first.', 'ffcertificate' ),
				array( 'candidacies' => array( $from, $into ) )
			);
		}

		$other = 'cpf' === $field ? 'rf_hash' : 'cpf_hash';
		$mine  = (string) ( $source[ $other ] ?? '' );
		$kept  = (string) ( $target[ $other ] ?? '' );

		if ( '' !== $mine && '' !== $kept && $mine !== $kept ) {
			return new WP_Error(
				'ffc_identity_candidacy_identifier',
				__( 'The two candidacies carry different values for the other identifier, so they disagree about who this is. Resolve that finding first.', 'ffcertificate' ),
				array( 'candidacies' => array( $from, $into ) )
			);
		}

		$conflict = $this->conflict( $this->overlaps( $from, $into ) );

		if ( null !== $conflict ) {
			return new WP_Error(
				'ffc_identity_candidacy_overlap',
				sprintf(
					/* translators: 1: notice code, 2: list type. */
					__( 'Both candidacies are classified on notice %1$s for the same adjutancy and list (%2$s) with different standings, or one of them was already called, and one person holds one standing there. Merging would mean deleting one of the two classifications, which is HR\'s decision, not a correction. Nothing was changed.', 'ffcertificate' ),
					$conflict['notice'],
					$conflict['list_type']
				),
				array( 'candidacies' => array( $from, $into ) )
			);
		}

		return true;
	}

	/**
	 * Merge, inside a transaction the caller owns.
	 *
	 * The order is the unique keys': the merged candidacy is deleted BEFORE
	 * the survivor takes any of its identifiers, or the survivor's update
	 * would collide with the row it is absorbing.
	 *
	 * @param int $from The candidacy that goes away.
	 * @param int $into The candidacy that stays.
	 * @return array{classifications: int, duplicates: int}|WP_Error
	 */
	public function apply( int $from, int $into ): array|WP_Error {
		global $wpdb;

		$pair = $this->load( $from, $into );

		if ( is_wp_error( $pair ) ) {
			return $pair;
		}

		list( $source, $target ) = $pair;

		// RE-MEASURED, NEVER TRUSTED FROM THE CHECK: a call may have been
		// made between the check and this write, and that turns a duplicate
		// into a decision.
		$overlaps = $this->overlaps( $from, $into );

		if ( null !== $this->conflict( $overlaps ) ) {
			return new WP_Error(
				'ffc_identity_candidacy_overlap',
				__( 'The two candidacies\' classifications changed since the check and no longer agree. Nothing was changed; reload the queue.', 'ffcertificate' )
			);
		}

		$classification_table = $wpdb->prefix . 'ffc_recruitment_classification';

		// The merged candidacy's copy of an identical classification goes
		// first, or moving it onto the survivor would collide with the
		// survivor's own row on the unique key.
		foreach ( $overlaps as $overlap ) {
			if ( false === $wpdb->delete( $classification_table, array( 'id' => (int) $overlap['from_id'] ), array( '%d' ) ) ) {
				return $this->failed( $classification_table );
			}
		}

		$classifications = $wpdb->update(
			$classification_table,
			array( 'candidate_id' => $into ),
			array( 'candidate_id' => $from ),
			array( '%d' ),
			array( '%d' )
		);

		if ( false === $classifications ) {
			return $this->failed( $classification_table );
		}

		// A finished import keeps its staging rows until cleanup, and one
		// pointing at a deleted candidacy would make its report name nobody.
		$staging = $wpdb->prefix . 'ffc_recruitment_import_staging';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $staging ) ) === $staging
			&& false === $wpdb->update( $staging, array( 'candidate_id' => $into ), array( 'candidate_id' => $from ), array( '%d' ), array( '%d' ) ) ) {
			return $this->failed( $staging );
		}

		$candidates = $wpdb->prefix . 'ffc_recruitment_candidate';

		if ( false === $wpdb->delete( $candidates, array( 'id' => $from ), array( '%d' ) ) ) {
			return $this->failed( $candidates );
		}

		$gains = $this->gains( $source, $target );

		if ( array() !== $gains ) {
			$gains['updated_at'] = current_time( 'mysql' );

			if ( false === $wpdb->update( $candidates, $gains, array( 'id' => $into ), null, array( '%d' ) ) ) {
				return $this->failed( $candidates );
			}
		}

		return array(
			'classifications' => (int) $classifications,
			'duplicates'      => count( $overlaps ),
		);
	}

	/**
	 * What the survivor takes from the merged candidacy.
	 *
	 * Only values it lacks: the survivor's own data is what the person
	 * confirmed most recently, so it is never overwritten. Notes are the one
	 * exception, kept from both so nothing an operator wrote is lost.
	 *
	 * @param array<string, mixed> $source The candidacy that goes away.
	 * @param array<string, mixed> $target The candidacy that stays.
	 * @return array<string, string>
	 */
	private function gains( array $source, array $target ): array {
		$gains = array();

		foreach ( self::FILLABLE as $columns ) {
			$lead = $columns[ count( $columns ) - 1 ];

			if ( '' !== (string) ( $target[ $lead ] ?? '' ) || '' === (string) ( $source[ $lead ] ?? '' ) ) {
				continue;
			}

			foreach ( $columns as $column ) {
				$gains[ $column ] = (string) ( $source[ $column ] ?? '' );
			}
		}

		$theirs = trim( (string) ( $source['notes'] ?? '' ) );
		$ours   = trim( (string) ( $target['notes'] ?? '' ) );

		if ( '' !== $theirs && $theirs !== $ours ) {
			$gains['notes'] = '' === $ours ? $theirs : $ours . "\n\n" . $theirs;
		}

		return $gains;
	}

	/**
	 * Every notice, adjutancy and list both candidacies are classified on.
	 *
	 * One row per colliding pair, with both sides' standing and how many
	 * calls either side has -- cancelled ones included, because a cancelled
	 * call is still history that belongs to one specific classification.
	 *
	 * @param int $from The candidacy that goes away.
	 * @param int $into The candidacy that stays.
	 * @return array<int, array<string, mixed>>
	 */
	private function overlaps( int $from, int $into ): array {
		global $wpdb;

		$classifications = $wpdb->prefix . 'ffc_recruitment_classification';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT a.id AS from_id, b.id AS into_id, a.notice_id, a.list_type, n.code,
					a.`rank` AS from_rank, b.`rank` AS into_rank, a.score AS from_score, b.score AS into_score,
					a.time_points AS from_time_points, b.time_points AS into_time_points,
					a.hab_emebs AS from_hab_emebs, b.hab_emebs AS into_hab_emebs,
					a.status AS from_status, b.status AS into_status,
					a.preview_status AS from_preview_status, b.preview_status AS into_preview_status,
					a.preview_reason_id AS from_preview_reason_id, b.preview_reason_id AS into_preview_reason_id,
					(SELECT COUNT(*) FROM %i c WHERE c.classification_id IN (a.id, b.id)) AS calls
				FROM %i a
				INNER JOIN %i b ON b.notice_id = a.notice_id AND b.adjutancy_id = a.adjutancy_id AND b.list_type = a.list_type
				LEFT JOIN %i n ON n.id = a.notice_id
				WHERE a.candidate_id = %d AND b.candidate_id = %d',
				$wpdb->prefix . 'ffc_recruitment_call',
				$classifications,
				$classifications,
				$wpdb->prefix . 'ffc_recruitment_notice',
				$from,
				$into
			),
			ARRAY_A
		);

		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * The first colliding pair that is not a harmless duplicate, or null.
	 *
	 * A duplicate is the same standing twice and never called. Scores are
	 * compared as numbers, because `decimal(10,4)` comes back as a string
	 * whose spelling is the driver's and not the value's.
	 *
	 * @param array<int, array<string, mixed>> $overlaps From {@see self::overlaps()}.
	 * @return array{notice: string, list_type: string}|null
	 */
	private function conflict( array $overlaps ): ?array {
		foreach ( $overlaps as $row ) {
			$same = (int) ( $row['calls'] ?? 1 ) === 0
				&& 'empty' === (string) ( $row['from_status'] ?? '' )
				&& 'empty' === (string) ( $row['into_status'] ?? '' )
				&& (int) ( $row['from_rank'] ?? -1 ) === (int) ( $row['into_rank'] ?? -2 )
				&& abs( (float) ( $row['from_score'] ?? 0 ) - (float) ( $row['into_score'] ?? 0 ) ) < 0.00005
				&& abs( (float) ( $row['from_time_points'] ?? 0 ) - (float) ( $row['into_time_points'] ?? 0 ) ) < 0.00005
				&& (int) ( $row['from_hab_emebs'] ?? 0 ) === (int) ( $row['into_hab_emebs'] ?? 0 )
				&& (string) ( $row['from_preview_status'] ?? '' ) === (string) ( $row['into_preview_status'] ?? '' )
				&& (int) ( $row['from_preview_reason_id'] ?? 0 ) === (int) ( $row['into_preview_reason_id'] ?? 0 );

			if ( ! $same ) {
				$code = (string) ( $row['code'] ?? '' );

				return array(
					'notice'    => '' !== $code ? $code : '#' . (int) ( $row['notice_id'] ?? 0 ),
					'list_type' => (string) ( $row['list_type'] ?? '' ),
				);
			}
		}

		return null;
	}

	/**
	 * Both candidacy rows, or why they cannot be read.
	 *
	 * @param int $from The candidacy that goes away.
	 * @param int $into The candidacy that stays.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}|WP_Error
	 */
	private function load( int $from, int $into ): array|WP_Error {
		global $wpdb;

		if ( $from <= 0 || $into <= 0 || $from === $into ) {
			return new WP_Error(
				'ffc_identity_candidacy_missing',
				__( 'The candidacies to merge could not be found. Reload the queue.', 'ffcertificate' )
			);
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id IN (%d, %d)',
				$wpdb->prefix . 'ffc_recruitment_candidate',
				$from,
				$into
			),
			ARRAY_A
		);

		$by_id = array();

		foreach ( (array) $rows as $row ) {
			$by_id[ (int) ( $row['id'] ?? 0 ) ] = (array) $row;
		}

		if ( ! isset( $by_id[ $from ], $by_id[ $into ] ) ) {
			return new WP_Error(
				'ffc_identity_candidacy_missing',
				__( 'The candidacies to merge could not be found. Reload the queue.', 'ffcertificate' )
			);
		}

		return array( $by_id[ $from ], $by_id[ $into ] );
	}

	/**
	 * A candidacy's PCD condition, as its own hash records it.
	 *
	 * Null when neither domain matches -- a rotated salt or a tampered row --
	 * and that is refused rather than guessed: an unreadable condition cannot
	 * be shown to agree with anything.
	 *
	 * @param array<string, mixed> $row A candidacy row.
	 * @return bool|null
	 */
	protected function is_pcd( array $row ): ?bool {
		return RecruitmentPcdHasher::verify( (string) ( $row['pcd_hash'] ?? '' ), (int) ( $row['id'] ?? 0 ) );
	}

	/**
	 * The refusal for a store that would not take the write.
	 *
	 * @param string $table The store.
	 * @return WP_Error
	 */
	private function failed( string $table ): WP_Error {
		return new WP_Error(
			'ffc_identity_repair_failed',
			sprintf(
				/* translators: %s: the store whose update failed. */
				__( 'The store %s refused the write, so nothing was changed.', 'ffcertificate' ),
				$table
			)
		);
	}
}

<?php
/**
 * Recruitment Re-import Diff
 *
 * Compares a list already stored for a notice with the list a new file would
 * put in its place (#2245). A re-import replaces the whole list, so before
 * this the operator learned what a corrected file changed only by reading
 * the result, and every preliminary status and reason set by hand was wiped
 * without a word. Now a re-import over a list that has rows answers with
 * this comparison first, and writes only once the operator confirms it.
 *
 * Rows are matched by candidate and adjutancy: a candidate may sit in more
 * than one adjutancy of a notice, and each of those is a row of its own.
 *
 * The same pair is what carries a manual preliminary status and reason
 * across the import: {@see self::marks()} reads them off the old list, and
 * the importers write them back onto the new rows. A mark is lost only when
 * its row leaves the list, and the comparison says how many do.
 *
 * Shared by the single-request import and the batched one, so both paths
 * report and preserve the same way.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.44.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Comparison of a stored classification list with its replacement.
 *
 * @phpstan-type DiffInput array{candidate_id: int, adjutancy_id: int, rank: int, score: string}
 * @phpstan-type DiffEntry array{candidate_id: int, adjutancy_id: int, rank: int, score: string, old_rank: int, old_score: string, marked: bool}
 * @phpstan-type Diff array{added: list<DiffEntry>, removed: list<DiffEntry>, changed: list<DiffEntry>, unchanged: int, marks_kept: int, marks_lost: int}
 * @phpstan-type Mark array{status: string, reason_id: int|null}
 */
final class RecruitmentReimportDiff {

	/**
	 * Error code returned while the comparison waits for the operator.
	 */
	public const CONFIRM_CODE = 'recruitment_reimport_needs_confirmation';

	/**
	 * Most rows listed per group; the counts always cover every row.
	 */
	public const LIST_LIMIT = 50;

	/**
	 * Matching key of a row.
	 *
	 * @param int $candidate_id Candidate ID.
	 * @param int $adjutancy_id Adjutancy ID.
	 * @return string
	 */
	public static function key( int $candidate_id, int $adjutancy_id ): string {
		return $candidate_id . '|' . $adjutancy_id;
	}

	/**
	 * The manual preliminary marks of a stored list, by matching key.
	 *
	 * A row whose status is `empty` and that carries no reason has nothing
	 * to keep and is left out.
	 *
	 * @param array<int, object> $rows Stored classification rows.
	 * @return array<string, array{status: string, reason_id: int|null}>
	 */
	public static function marks( array $rows ): array {
		$marks = array();
		foreach ( $rows as $row ) {
			$status = isset( $row->preview_status ) ? (string) $row->preview_status : 'empty';
			$reason = isset( $row->preview_reason_id ) && '' !== (string) $row->preview_reason_id
				? (int) $row->preview_reason_id
				: null;
			if ( 'empty' === $status && null === $reason ) {
				continue;
			}
			$key           = self::key( (int) ( $row->candidate_id ?? 0 ), (int) ( $row->adjutancy_id ?? 0 ) );
			$marks[ $key ] = array(
				'status'    => $status,
				'reason_id' => $reason,
			);
		}
		return $marks;
	}

	/**
	 * Compare a stored list with the rows that would replace it.
	 *
	 * @param array<int, object> $old Stored classification rows.
	 * @param array              $new Rows of the new list.
	 * @phpstan-param list<DiffInput> $new
	 * @return array{added: list<array{candidate_id: int, adjutancy_id: int, rank: int, score: string, old_rank: int, old_score: string, marked: bool}>, removed: list<array{candidate_id: int, adjutancy_id: int, rank: int, score: string, old_rank: int, old_score: string, marked: bool}>, changed: list<array{candidate_id: int, adjutancy_id: int, rank: int, score: string, old_rank: int, old_score: string, marked: bool}>, unchanged: int, marks_kept: int, marks_lost: int}
	 * @phpstan-return Diff
	 */
	public static function compute( array $old, array $new ): array {
		$marks  = self::marks( $old );
		$before = array();
		foreach ( $old as $row ) {
			$key            = self::key( (int) ( $row->candidate_id ?? 0 ), (int) ( $row->adjutancy_id ?? 0 ) );
			$before[ $key ] = array(
				'rank'  => (int) ( $row->rank ?? 0 ),
				'score' => (string) ( $row->score ?? '' ),
			);
		}

		$diff = array(
			'added'      => array(),
			'removed'    => array(),
			'changed'    => array(),
			'unchanged'  => 0,
			'marks_kept' => 0,
			'marks_lost' => 0,
		);

		$seen = array();
		foreach ( $new as $row ) {
			$key          = self::key( $row['candidate_id'], $row['adjutancy_id'] );
			$seen[ $key ] = true;
			$entry        = array(
				'candidate_id' => $row['candidate_id'],
				'adjutancy_id' => $row['adjutancy_id'],
				'rank'         => $row['rank'],
				'score'        => $row['score'],
				'old_rank'     => 0,
				'old_score'    => '',
				'marked'       => isset( $marks[ $key ] ),
			);
			if ( ! isset( $before[ $key ] ) ) {
				$diff['added'][] = $entry;
				continue;
			}
			if ( $entry['marked'] ) {
				++$diff['marks_kept'];
			}
			$entry['old_rank']  = $before[ $key ]['rank'];
			$entry['old_score'] = $before[ $key ]['score'];
			if ( $entry['old_rank'] !== $entry['rank'] || ! self::same_score( $entry['old_score'], $entry['score'] ) ) {
				$diff['changed'][] = $entry;
			} else {
				++$diff['unchanged'];
			}
		}

		foreach ( $before as $key => $values ) {
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			list( $candidate_id, $adjutancy_id ) = array_map( 'intval', explode( '|', $key ) );

			$marked = isset( $marks[ $key ] );
			if ( $marked ) {
				++$diff['marks_lost'];
			}
			$diff['removed'][] = array(
				'candidate_id' => $candidate_id,
				'adjutancy_id' => $adjutancy_id,
				'rank'         => 0,
				'score'        => '',
				'old_rank'     => $values['rank'],
				'old_score'    => $values['score'],
				'marked'       => $marked,
			);
		}

		return $diff;
	}

	/**
	 * Write the kept marks back onto the rows of the new list.
	 *
	 * @param array<string, array{status: string, reason_id: int|null}> $marks       From {@see self::marks()}.
	 * @param array<string, int>                                        $ids_by_key  New classification IDs by matching key.
	 * @return bool False when a write failed.
	 */
	public static function restore_marks( array $marks, array $ids_by_key ): bool {
		foreach ( $marks as $key => $mark ) {
			if ( ! isset( $ids_by_key[ $key ] ) ) {
				continue;
			}
			if ( ! RecruitmentClassificationRepository::set_preview_status( $ids_by_key[ $key ], $mark['status'], $mark['reason_id'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether the comparison found nothing that changes the list.
	 *
	 * @param array $diff From {@see self::compute()}.
	 * @phpstan-param Diff $diff
	 * @return bool
	 */
	public static function is_identical( array $diff ): bool {
		return empty( $diff['added'] ) && empty( $diff['removed'] ) && empty( $diff['changed'] );
	}

	/**
	 * The comparison as sentences for the operator: one per count, then one
	 * per row that enters, leaves or changes, up to {@see self::LIST_LIMIT}
	 * per group.
	 *
	 * @param array $diff From {@see self::compute()}.
	 * @phpstan-param Diff $diff
	 * @return array{summary: list<string>, lines: list<string>}
	 */
	public static function messages( array $diff ): array {
		$summary = array(
			sprintf(
				/* translators: %s: number of rows */
				_n( '%s row enters the list.', '%s rows enter the list.', count( $diff['added'] ), 'ffcertificate' ),
				number_format_i18n( count( $diff['added'] ) )
			),
			sprintf(
				/* translators: %s: number of rows */
				_n( '%s row leaves the list.', '%s rows leave the list.', count( $diff['removed'] ), 'ffcertificate' ),
				number_format_i18n( count( $diff['removed'] ) )
			),
			sprintf(
				/* translators: %s: number of rows */
				_n( '%s row changes rank or score.', '%s rows change rank or score.', count( $diff['changed'] ), 'ffcertificate' ),
				number_format_i18n( count( $diff['changed'] ) )
			),
			sprintf(
				/* translators: %s: number of rows */
				_n( '%s row is unchanged.', '%s rows are unchanged.', $diff['unchanged'], 'ffcertificate' ),
				number_format_i18n( $diff['unchanged'] )
			),
		);
		if ( $diff['marks_kept'] + $diff['marks_lost'] > 0 ) {
			$summary[] = sprintf(
				/* translators: 1: number of manual statuses kept, 2: number lost */
				__( 'Manual preliminary statuses: %1$s kept, %2$s lost with the rows that leave.', 'ffcertificate' ),
				number_format_i18n( $diff['marks_kept'] ),
				number_format_i18n( $diff['marks_lost'] )
			);
		}

		$entries = array_merge(
			array_slice( $diff['removed'], 0, self::LIST_LIMIT ),
			array_slice( $diff['added'], 0, self::LIST_LIMIT ),
			array_slice( $diff['changed'], 0, self::LIST_LIMIT )
		);
		$names   = self::candidate_names( array_column( $entries, 'candidate_id' ) );
		$subject = self::adjutancy_names();

		$lines = array();
		foreach ( array_slice( $diff['removed'], 0, self::LIST_LIMIT ) as $entry ) {
			$format = $entry['marked']
				/* translators: 1: candidate name, 2: adjutancy, 3: rank */
				? __( 'Leaves: %1$s, %2$s, rank %3$s. Its manual status is lost.', 'ffcertificate' )
				/* translators: 1: candidate name, 2: adjutancy, 3: rank */
				: __( 'Leaves: %1$s, %2$s, rank %3$s.', 'ffcertificate' );
			$lines[] = sprintf(
				$format,
				$names[ $entry['candidate_id'] ] ?? '#' . $entry['candidate_id'],
				$subject[ $entry['adjutancy_id'] ] ?? '—',
				number_format_i18n( $entry['old_rank'] )
			);
		}
		foreach ( array_slice( $diff['added'], 0, self::LIST_LIMIT ) as $entry ) {
			$lines[] = sprintf(
				/* translators: 1: candidate name, 2: adjutancy, 3: rank */
				__( 'Enters: %1$s, %2$s, rank %3$s.', 'ffcertificate' ),
				$names[ $entry['candidate_id'] ] ?? '#' . $entry['candidate_id'],
				$subject[ $entry['adjutancy_id'] ] ?? '—',
				number_format_i18n( $entry['rank'] )
			);
		}
		foreach ( array_slice( $diff['changed'], 0, self::LIST_LIMIT ) as $entry ) {
			$lines[] = sprintf(
				/* translators: 1: candidate name, 2: adjutancy, 3: old rank, 4: new rank, 5: old score, 6: new score */
				__( 'Changes: %1$s, %2$s, rank %3$s → %4$s, score %5$s → %6$s.', 'ffcertificate' ),
				$names[ $entry['candidate_id'] ] ?? '#' . $entry['candidate_id'],
				$subject[ $entry['adjutancy_id'] ] ?? '—',
				number_format_i18n( $entry['old_rank'] ),
				number_format_i18n( $entry['rank'] ),
				$entry['old_score'],
				$entry['score']
			);
		}
		$hidden = max( 0, count( $diff['removed'] ) - self::LIST_LIMIT )
			+ max( 0, count( $diff['added'] ) - self::LIST_LIMIT )
			+ max( 0, count( $diff['changed'] ) - self::LIST_LIMIT );
		if ( $hidden > 0 ) {
			$lines[] = sprintf(
				/* translators: %s: number of rows not listed */
				_n( '%s more row is not listed.', '%s more rows are not listed.', $hidden, 'ffcertificate' ),
				number_format_i18n( $hidden )
			);
		}

		return array(
			'summary' => $summary,
			'lines'   => $lines,
		);
	}

	/**
	 * Two scores are the same number written alike or differently (`87.5`
	 * and `87.50`).
	 *
	 * @param string $a Score.
	 * @param string $b Score.
	 * @return bool
	 */
	private static function same_score( string $a, string $b ): bool {
		if ( is_numeric( $a ) && is_numeric( $b ) ) {
			return abs( (float) $a - (float) $b ) < 0.00001;
		}
		return trim( $a ) === trim( $b );
	}

	/**
	 * Candidate names by ID, in one query.
	 *
	 * @param array<int, int> $ids Candidate IDs.
	 * @return array<int, string>
	 */
	private static function candidate_names( array $ids ): array {
		$names = array();
		foreach ( RecruitmentCandidateReader::get_by_ids( $ids ) as $id => $candidate ) {
			$names[ (int) $id ] = (string) $candidate->name;
		}
		return $names;
	}

	/**
	 * Adjutancy names by ID.
	 *
	 * @return array<int, string>
	 */
	private static function adjutancy_names(): array {
		$names = array();
		foreach ( RecruitmentAdjutancyReader::get_all() as $adjutancy ) {
			$names[ (int) $adjutancy->id ] = (string) $adjutancy->name;
		}
		return $names;
	}
}

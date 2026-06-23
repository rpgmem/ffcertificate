<?php
/**
 * Recruitment Classification Filter Manager.
 *
 * Filter resolution + application helpers split out of
 * {@see RecruitmentNoticeEditPage} per the sprint S1 god-object refactor
 * (rpgmem/ffcertificate#141). Reads the `ffc_cls_*` GET params, normalizes
 * them (digits-only CPF/RF, encrypted-hash candidate lookup) and applies
 * the resolved filters to a classification rows array. No behavior changes
 * — the methods are byte-identical to the originals, just relocated and
 * renamed (`read_classification_filters` → `read_filters`,
 * `apply_classification_filters` → `apply_filters`).
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.1.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classification-listing filter resolver + applier.
 *
 * @phpstan-import-type ClassificationRow from RecruitmentClassificationRepository
 */
final class RecruitmentClassificationFilterManager {

	/**
	 * Read the classification-listing filters from the request.
	 *
	 * Mirrors the GET-param shape used by the Candidates list table
	 * (`adjutancy_id`, `s`, `cpf`, `rf`) but namespaced under `ffc_cls_*`
	 * so it can't collide with the Candidates-tab params if both pages
	 * share state. CPF / RF are normalized to digits only and resolved
	 * to a candidate id via the encrypted-hash lookup; the result is
	 * cached on the array so the row filter doesn't re-hash on every
	 * pass.
	 *
	 * @param int $notice_id Notice id (only used for the form's hidden field).
	 * @return array<string, mixed> Map of normalized filter values.
	 */
	public static function read_filters( int $notice_id ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$adj_id = isset( $_GET['ffc_cls_adj'] ) ? absint( wp_unslash( (string) $_GET['ffc_cls_adj'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$query = isset( $_GET['ffc_cls_q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['ffc_cls_q'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$cpf = isset( $_GET['ffc_cls_cpf'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['ffc_cls_cpf'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$rf = isset( $_GET['ffc_cls_rf'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['ffc_cls_rf'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$sub_raw = isset( $_GET['ffc_cls_sub'] ) ? sanitize_key( wp_unslash( (string) $_GET['ffc_cls_sub'] ) ) : '';
		$sub     = in_array( $sub_raw, array( 'pcd', 'geral' ), true ) ? $sub_raw : '';

		// Resolve CPF / RF to a candidate id (or 0 = no match) via the
		// encrypted-hash lookup. Doing it once here avoids per-row hashing
		// inside apply_classification_filters().
		$cpf_candidate_id = 0;
		$rf_candidate_id  = 0;
		$digits           = static fn( string $v ): string => \FreeFormCertificate\Core\DataSanitizer::normalize_cpf_rf( $v );
		if ( '' !== $cpf ) {
			$cpf_digits = $digits( $cpf );
			if ( '' !== $cpf_digits ) {
				$hash             = (string) \FreeFormCertificate\Core\Encryption::hash( $cpf_digits );
				$candidate        = RecruitmentCandidateReader::get_by_cpf_hash( $hash );
				$cpf_candidate_id = null === $candidate ? -1 : (int) $candidate->id;
			}
		}
		if ( '' !== $rf ) {
			$rf_digits = $digits( $rf );
			if ( '' !== $rf_digits ) {
				$hash            = (string) \FreeFormCertificate\Core\Encryption::hash( $rf_digits );
				$candidate       = RecruitmentCandidateReader::get_by_rf_hash( $hash );
				$rf_candidate_id = null === $candidate ? -1 : (int) $candidate->id;
			}
		}

		return array(
			'notice_id'        => $notice_id,
			'adjutancy_id'     => $adj_id,
			'query'            => $query,
			'cpf'              => $cpf,
			'rf'               => $rf,
			'cpf_candidate_id' => $cpf_candidate_id,
			'rf_candidate_id'  => $rf_candidate_id,
			'subscription'     => $sub,
		);
	}

	/**
	 * Apply the resolved filters to a classifications array.
	 *
	 * Filters compose with AND: a row must match every active filter to
	 * survive. Rows whose candidate name doesn't substring-match the
	 * query, or whose adjutancy/candidate id doesn't match the resolved
	 * CPF/RF candidate, are dropped.
	 *
	 * @param array<int, object>   $rows    Classification rows.
	 * @phpstan-param list<ClassificationRow> $rows
	 * @param array<string, mixed> $filters Resolved filters.
	 * @return array<int, object> Filtered classification rows.
	 * @phpstan-return list<ClassificationRow>
	 */
	public static function apply_filters( array $rows, array $filters ): array {
		$adj_id           = (int) ( $filters['adjutancy_id'] ?? 0 );
		$query            = (string) ( $filters['query'] ?? '' );
		$cpf_candidate_id = (int) ( $filters['cpf_candidate_id'] ?? 0 );
		$rf_candidate_id  = (int) ( $filters['rf_candidate_id'] ?? 0 );
		$subscription     = (string) ( $filters['subscription'] ?? '' );
		$has_q            = '' !== $query;
		$has_sub          = 'pcd' === $subscription || 'geral' === $subscription;
		$needle           = $has_q
			? ( function_exists( 'mb_strtolower' ) ? mb_strtolower( $query, 'UTF-8' ) : strtolower( $query ) )
			: '';

		// CPF/RF that didn't resolve to any candidate (`-1`) shrinks the
		// set to empty: the operator typed a number that doesn't exist
		// in this notice, so no row can match.
		if ( -1 === $cpf_candidate_id || -1 === $rf_candidate_id ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			if ( $adj_id > 0 && (int) $row->adjutancy_id !== $adj_id ) {
				continue;
			}
			$candidate_id = (int) $row->candidate_id;
			if ( $cpf_candidate_id > 0 && $candidate_id !== $cpf_candidate_id ) {
				continue;
			}
			if ( $rf_candidate_id > 0 && $candidate_id !== $rf_candidate_id ) {
				continue;
			}
			// Both the name search and the subscription filter need the
			// candidate row; fetch it once when either is active.
			$candidate = ( $has_q || $has_sub )
				? RecruitmentCandidateReader::get_by_id( $candidate_id )
				: null;
			if ( ( $has_q || $has_sub ) && null === $candidate ) {
				continue;
			}
			if ( $has_q ) {
				$name = (string) ( $candidate->name ?? '' );
				$hay  = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );
				if ( false === strpos( $hay, $needle ) ) {
					continue;
				}
			}
			if ( $has_sub ) {
				// `verify()` returns null on hash decode failure — treat
				// that as GERAL on the filter side, same defensive
				// normalization the public shortcode uses for the badge.
				$is_pcd           = true === RecruitmentPcdHasher::verify( (string) ( $candidate->pcd_hash ?? '' ), $candidate_id );
				$row_subscription = $is_pcd ? 'pcd' : 'geral';
				if ( $row_subscription !== $subscription ) {
					continue;
				}
			}
			$out[] = $row;
		}
		return $out;
	}
}

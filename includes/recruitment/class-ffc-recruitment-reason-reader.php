<?php
/**
 * Reason Reader
 *
 * Read-side of the reason repository split (#563 backlog, B3). Holds every
 * SELECT / lookup query for `ffc_recruitment_reason`, the deletion-gate
 * reference count, and the pure `applies_to` decode helper. Writes live in
 * {@see RecruitmentReasonWriter}; {@see RecruitmentReasonRepository} remains
 * the public façade that delegates to both.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.11.3
 *
 * @phpstan-import-type ReasonRow from RecruitmentReasonRepository
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read queries for `ffc_recruitment_reason` rows.
 *
 * @since 6.11.3
 *
 * @phpstan-import-type ReasonRow from RecruitmentReasonRepository
 */
class RecruitmentReasonReader {

	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Cache group for this repository.
	 *
	 * Must match {@see RecruitmentReasonWriter::cache_group()} so writes
	 * invalidate the entries reads populate.
	 *
	 * @return string
	 */
	protected static function cache_group(): string {
		return 'ffc_recruitment_reason';
	}

	/**
	 * Get the fully-prefixed table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		return self::db()->prefix . 'ffc_recruitment_reason';
	}

	/**
	 * Get a reason row by ID.
	 *
	 * @param int $id Reason ID.
	 * @return ReasonRow|null
	 */
	public static function get_by_id( int $id ): ?object {
		$cached = static::cache_get( "id_{$id}" );
		if ( false !== $cached ) {
			/**
			 * Object-cache return cast.
			 *
			 * @var ReasonRow|null $cached
			 */
			return $cached;
		}

		$wpdb  = self::db();
		$table = self::get_table_name();

		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var ReasonRow|null $result
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached above and below; %i for table identifier.
		$result = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id )
		);

		if ( $result ) {
			static::cache_set( "id_{$id}", $result );
		}

		return $result;
	}

	/**
	 * List all reasons, ordered by label ASC.
	 *
	 * @return list<ReasonRow>
	 */
	public static function get_all(): array {
		$wpdb  = self::db();
		$table = self::get_table_name();

		/**
		 * Cast wpdb results to typed shape.
		 *
		 * @var list<ReasonRow>|null $results
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only listing; small cardinality.
		$results = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY label ASC', $table )
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Decode a stored applies_to CSV back into a list. An empty stored
	 * value yields {@see RecruitmentReasonRepository::APPLIES_TO_VALUES}
	 * ("applies to all").
	 *
	 * @param string $stored CSV value from the row.
	 * @return list<string>
	 */
	public static function decode_applies_to( string $stored ): array {
		$stored = trim( $stored );
		if ( '' === $stored ) {
			return RecruitmentReasonRepository::APPLIES_TO_VALUES;
		}
		$parts = array_filter( array_map( 'trim', explode( ',', $stored ) ) );
		$out   = array();
		foreach ( $parts as $candidate ) {
			if ( in_array( $candidate, RecruitmentReasonRepository::APPLIES_TO_VALUES, true ) && ! in_array( $candidate, $out, true ) ) {
				$out[] = $candidate;
			}
		}
		return empty( $out ) ? RecruitmentReasonRepository::APPLIES_TO_VALUES : $out;
	}

	/**
	 * Count how many classification rows currently reference this
	 * reason via `preview_reason_id`. Used by the deletion gate on the
	 * Reasons admin tab.
	 *
	 * @param int $id Reason ID.
	 * @return int
	 */
	public static function count_references( int $id ): int {
		global $wpdb;
		$cls_table = $wpdb->prefix . 'ffc_recruitment_classification';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only deletion gate.
		$count = $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE preview_reason_id = %d', $cls_table, $id )
		);

		return null === $count ? 0 : (int) $count;
	}
}

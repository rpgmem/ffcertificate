<?php
/**
 * Notice Reader
 *
 * Read-side of the notice repository split (#563 backlog, B3). Holds every
 * SELECT / lookup query for `ffc_recruitment_notice`. Writes live in
 * {@see RecruitmentNoticeWriter}. Callers depend on this reader (reads) and the
 * writer (writes) directly; the delegating façade was retired in #563 B3-A.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.11.3
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read queries for `ffc_recruitment_notice` rows.
 *
 * @since 6.11.3
 *
 * @phpstan-type NoticeRow \stdClass&object{id: numeric-string, code: string, name: string, status: string, opened_at: string|null, closed_at: string|null, was_reopened: numeric-string, public_columns_config: string, created_at: string, updated_at: string}
 */
class RecruitmentNoticeReader {

	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Default per-notice public column visibility, applied when a notice's
	 * `public_columns_config` is empty. JSON string by construction so it can
	 * seed the column directly on create/update.
	 */
	public const DEFAULT_PUBLIC_COLUMNS_CONFIG = '{"rank":true,"name":true,"adjutancy":true,"status":true,"pcd_badge":true,"date_to_assume":true,"time_to_assume":true,"score":false,"time_points":false,"hab_emebs":false,"cpf_masked":false,"rf_masked":false,"email_masked":false,"preview_reason":false}';

	/**
	 * Cache group for this repository.
	 *
	 * Must match {@see RecruitmentNoticeWriter::cache_group()} so writes
	 * invalidate the entries reads populate.
	 *
	 * @return string
	 */
	protected static function cache_group(): string {
		return 'ffc_recruitment_notice';
	}

	/**
	 * Get the fully-prefixed table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		return self::db()->prefix . 'ffc_recruitment_notice';
	}

	/**
	 * Get a notice by ID.
	 *
	 * @param int $id Notice ID.
	 * @return NoticeRow|null
	 */
	public static function get_by_id( int $id ): ?object {
		$cached = static::cache_get( "id_{$id}" );
		if ( false !== $cached ) {
			/**
			 * Object-cache return cast.
			 *
			 * @var NoticeRow|null $cached
			 */
			return $cached;
		}

		$wpdb  = self::db();
		$table = self::get_table_name();

		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var NoticeRow|null $result
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Object-cached above; %i for table identifier.
		$result = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id )
		);

		if ( $result ) {
			static::cache_set( "id_{$id}", $result );
		}

		return $result;
	}

	/**
	 * Get a notice by `code` (case-insensitive — input is uppercased before lookup).
	 *
	 * Used by the public shortcode to resolve `notice="EDITAL-2026-01"` and
	 * by the admin import flow.
	 *
	 * @param string $code Notice code (any case; normalized internally).
	 * @return NoticeRow|null
	 */
	public static function get_by_code( string $code ): ?object {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$normalized = strtoupper( $code );

		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var NoticeRow|null $result
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lookup by indexed UNIQUE column.
		$result = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE code = %s LIMIT 1', $table, $normalized )
		);

		return $result ? $result : null;
	}

	/**
	 * List notices, optionally filtered by status.
	 *
	 * @param string|null $status One of {draft, preliminary, active, closed} or null for all.
	 * @return list<NoticeRow>
	 */
	public static function get_all( ?string $status = null ): array {
		$wpdb  = self::db();
		$table = self::get_table_name();

		if ( null !== $status ) {
			/**
			 * Cast wpdb results to typed shape.
			 *
			 * @var list<NoticeRow>|null $results
			 */
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin listing; status column is indexed.
			$results = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE status = %s ORDER BY created_at DESC', $table, $status )
			);
		} else {
			/**
			 * Cast wpdb results to typed shape.
			 *
			 * @var list<NoticeRow>|null $results
			 */
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin listing; small cardinality.
			$results = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i ORDER BY created_at DESC', $table )
			);
		}

		return is_array( $results ) ? $results : array();
	}
}

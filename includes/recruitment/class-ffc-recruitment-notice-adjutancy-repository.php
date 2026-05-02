<?php
/**
 * Notice ↔ Adjutancy Junction Repository
 *
 * Manages the N:N association declared by `ffc_recruitment_notice_adjutancy`.
 * No `id` column on the junction — primary key is the composite
 * `(notice_id, adjutancy_id)`. The repository exposes attach/detach/list
 * primitives only; no `update`.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.0.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database repository for the `ffc_recruitment_notice_adjutancy` junction.
 */
class RecruitmentNoticeAdjutancyRepository {

	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Cache group for this repository.
	 *
	 * @return string
	 */
	protected static function cache_group(): string {
		return 'ffc_recruitment_notice_adjutancy';
	}

	/**
	 * Get the fully-prefixed table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		return self::db()->prefix . 'ffc_recruitment_notice_adjutancy';
	}

	/**
	 * Attach an adjutancy to a notice.
	 *
	 * Idempotent under DB constraints: a duplicate `(notice_id, adjutancy_id)`
	 * pair returns `false` (PK conflict). Callers that want "ensure attached"
	 * semantics should check {@see self::is_attached()} first or treat
	 * `false` as a no-op.
	 *
	 * @param int $notice_id Notice ID.
	 * @param int $adjutancy_id Adjutancy ID.
	 * @return bool True on insert, false on PK conflict or DB failure.
	 */
	public static function attach( int $notice_id, int $adjutancy_id ): bool {
		$wpdb  = self::db();
		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Insert via wpdb helper; explicit formats.
		$result = $wpdb->insert(
			$table,
			array(
				'notice_id'    => $notice_id,
				'adjutancy_id' => $adjutancy_id,
			),
			array( '%d', '%d' )
		);

		$ok = false !== $result && $result > 0;
		if ( $ok ) {
			do_action( 'ffc_recruitment_public_cache_dirty' );
		}

		return $ok;
	}

	/**
	 * Detach an adjutancy from a notice.
	 *
	 * Returns `false` if the pair didn't exist (or DB failure). Detach is
	 * NOT gated by classification existence — that gate lives at the service
	 * layer (sprint 7) so it can return a typed 409 with the blocking
	 * classification count.
	 *
	 * @param int $notice_id Notice ID.
	 * @param int $adjutancy_id Adjutancy ID.
	 * @return bool
	 */
	public static function detach( int $notice_id, int $adjutancy_id ): bool {
		$wpdb  = self::db();
		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Delete via wpdb helper.
		$result = $wpdb->delete(
			$table,
			array(
				'notice_id'    => $notice_id,
				'adjutancy_id' => $adjutancy_id,
			),
			array( '%d', '%d' )
		);

		$ok = false !== $result && $result > 0;
		if ( $ok ) {
			do_action( 'ffc_recruitment_public_cache_dirty' );
		}

		return $ok;
	}

	/**
	 * Check whether the pair `(notice_id, adjutancy_id)` exists.
	 *
	 * @param int $notice_id Notice ID.
	 * @param int $adjutancy_id Adjutancy ID.
	 * @return bool
	 */
	public static function is_attached( int $notice_id, int $adjutancy_id ): bool {
		$wpdb  = self::db();
		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- PK lookup; small cardinality.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE notice_id = %d AND adjutancy_id = %d',
				$table,
				$notice_id,
				$adjutancy_id
			)
		);

		return $count > 0;
	}

	/**
	 * Get all adjutancy IDs attached to a notice.
	 *
	 * Used by the CSV importer (to validate that the imported adjutancy
	 * belongs to the target notice) and by the admin UI (to render the
	 * adjutancy list in the notice detail).
	 *
	 * @param int $notice_id Notice ID.
	 * @return array<int> List of adjutancy IDs.
	 */
	public static function get_adjutancy_ids_for_notice( int $notice_id ): array {
		$wpdb  = self::db();
		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Indexed by composite PK.
		$results = $wpdb->get_col(
			$wpdb->prepare( 'SELECT adjutancy_id FROM %i WHERE notice_id = %d', $table, $notice_id )
		);

		return array_map( 'intval', $results );
	}

	/**
	 * Get all notice IDs that include a given adjutancy.
	 *
	 * Powers the deletion gate for adjutancies (sprint 7): an adjutancy can
	 * only be hard-deleted when zero notices reference it.
	 *
	 * @param int $adjutancy_id Adjutancy ID.
	 * @return array<int> List of notice IDs.
	 */
	public static function get_notice_ids_for_adjutancy( int $adjutancy_id ): array {
		$wpdb  = self::db();
		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reverse-lookup index on adjutancy_id.
		$results = $wpdb->get_col(
			$wpdb->prepare( 'SELECT notice_id FROM %i WHERE adjutancy_id = %d', $table, $adjutancy_id )
		);

		return array_map( 'intval', $results );
	}

	/**
	 * Detach all adjutancies from a notice.
	 *
	 * Convenience method used by the notice deletion path (if/when notice
	 * deletion is added) and by tests. Not used in v1's main flows.
	 *
	 * @param int $notice_id Notice ID.
	 * @return int Number of pairs removed.
	 */
	public static function detach_all_for_notice( int $notice_id ): int {
		$wpdb  = self::db();
		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk delete; bounded by per-notice cardinality.
		$result = $wpdb->delete( $table, array( 'notice_id' => $notice_id ), array( '%d' ) );

		$rows = is_int( $result ) ? $result : 0;
		if ( $rows > 0 ) {
			do_action( 'ffc_recruitment_public_cache_dirty' );
		}

		return $rows;
	}
}

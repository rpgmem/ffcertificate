<?php
/**
 * Adjutancy Reader
 *
 * Read-side of the adjutancy repository split (#563 backlog, B3). Holds every
 * SELECT / lookup query for `ffc_recruitment_adjutancy`. Writes live in
 * {@see RecruitmentAdjutancyWriter}; {@see RecruitmentAdjutancyRepository}
 * remains the public façade that delegates to both.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.11.3
 *
 * @phpstan-import-type AdjutancyRow from RecruitmentAdjutancyRepository
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read queries for `ffc_recruitment_adjutancy` rows.
 *
 * @since 6.11.3
 *
 * @phpstan-import-type AdjutancyRow from RecruitmentAdjutancyRepository
 */
class RecruitmentAdjutancyReader {

	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Cache group for this repository.
	 *
	 * Must match {@see RecruitmentAdjutancyWriter::cache_group()} so writes
	 * invalidate the entries reads populate.
	 *
	 * @return string
	 */
	protected static function cache_group(): string {
		return 'ffc_recruitment_adjutancy';
	}

	/**
	 * Get the fully-prefixed table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		return self::db()->prefix . 'ffc_recruitment_adjutancy';
	}

	/**
	 * Get an adjutancy row by ID.
	 *
	 * Cached per-row in the object cache; cache is invalidated by
	 * {@see RecruitmentAdjutancyWriter::update()} and
	 * {@see RecruitmentAdjutancyWriter::delete()}.
	 *
	 * @param int $id Adjutancy ID.
	 * @return AdjutancyRow|null
	 */
	public static function get_by_id( int $id ): ?object {
		$cached = static::cache_get( "id_{$id}" );
		if ( false !== $cached ) {
			/**
			 * Object-cache return cast.
			 *
			 * @var AdjutancyRow|null $cached
			 */
			return $cached;
		}

		$wpdb  = self::db();
		$table = self::get_table_name();

		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var AdjutancyRow|null $result
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
	 * Get an adjutancy row by slug.
	 *
	 * Used by the CSV importer (which receives the slug in the `adjutancy`
	 * column) and by the public shortcode (which accepts the slug in its
	 * `adjutancy=` attribute).
	 *
	 * @param string $slug Adjutancy slug (lowercase, unique).
	 * @return AdjutancyRow|null
	 */
	public static function get_by_slug( string $slug ): ?object {
		$wpdb  = self::db();
		$table = self::get_table_name();

		/**
		 * Cast wpdb result to typed shape.
		 *
		 * @var AdjutancyRow|null $result
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Slug lookup is uncached intentionally; called rarely on CSV import / shortcode render.
		$result = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE slug = %s LIMIT 1', $table, $slug )
		);

		return $result ? $result : null;
	}

	/**
	 * List all adjutancies, ordered by name ASC.
	 *
	 * @return list<AdjutancyRow>
	 */
	public static function get_all(): array {
		$wpdb  = self::db();
		$table = self::get_table_name();

		/**
		 * Cast wpdb results to typed shape.
		 *
		 * @var list<AdjutancyRow>|null $results
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only listing; small cardinality (~ tens of rows).
		$results = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY name ASC', $table )
		);

		return is_array( $results ) ? $results : array();
	}
}

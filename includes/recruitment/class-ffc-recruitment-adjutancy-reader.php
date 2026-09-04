<?php
/**
 * Adjutancy Reader
 *
 * Read-side of the adjutancy repository split (#563 backlog, B3). Holds every
 * SELECT / lookup query for `ffc_recruitment_adjutancy`. Writes live in
 * {@see RecruitmentAdjutancyWriter}. Callers depend on this reader (reads) and
 * the writer (writes) directly; the delegating façade was retired in #563 B3-A.
 *
 * @package FreeFormCertificate\Recruitment
 * @since   6.12.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Recruitment;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement in this class runs against one of the plugin's own ffc_* tables, which WordPress exposes no API for. Caching is decided per read at the repository layer, not per statement (#1042).
/**
 * Read queries for `ffc_recruitment_adjutancy` rows.
 *
 * @since 6.12.0
 *
 * @phpstan-type AdjutancyRow \stdClass&object{id: numeric-string, slug: string, name: string, color: string, created_at: string, updated_at: string}
 */
class RecruitmentAdjutancyReader {

	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Default adjutancy badge color, applied when a row's `color` is empty.
	 */
	public const DEFAULT_COLOR = '#e9ecef';

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
		$results = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY name ASC', $table )
		);

		return is_array( $results ) ? $results : array();
	}
}

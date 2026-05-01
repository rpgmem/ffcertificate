<?php
/**
 * Adjutancy Repository
 *
 * CRUD for the global Adjutancy ("matéria") catalog. Adjutancies are reusable
 * across notices via the `ffc_recruitment_notice_adjutancy` junction.
 *
 * Schema-level invariants enforced here:
 *
 * - `slug` is UNIQUE (DB constraint). Insert/update operations rely on the
 *   constraint to surface duplicates as a `false` return.
 * - Deletion gating (zero references in `notice_adjutancy` and zero references
 *   in `classification`) is enforced by the service layer, not here — this
 *   repository's `delete()` is unconditional. The deletion gate lives in the
 *   REST controller / service so the gate can return a typed 409 with
 *   reference counts instead of a silent failure.
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
 * Database repository for `ffc_recruitment_adjutancy` rows.
 *
 * @phpstan-type AdjutancyRow \stdClass&object{id: numeric-string, slug: string, name: string, created_at: string, updated_at: string}
 */
class RecruitmentAdjutancyRepository {

	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Cache group for this repository.
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
	 * {@see self::update()} and {@see self::delete()}.
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

	/**
	 * Create a new adjutancy row.
	 *
	 * Caller is responsible for slug normalization. Returns `false` on
	 * insert failure (e.g. duplicate slug rejected by the UNIQUE constraint).
	 *
	 * @param string $slug Unique slug.
	 * @param string $name Display name.
	 * @return int|false New adjutancy ID or false on failure.
	 */
	public static function create( string $slug, string $name ) {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$now = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Insert via wpdb helper; format array supplied.
		$result = $wpdb->insert(
			$table,
			array(
				'slug'       => $slug,
				'name'       => $name,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%s' )
		);

		if ( ! $result ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update name and/or slug on an existing adjutancy.
	 *
	 * Only `slug` and `name` are accepted; any other key in `$data` is
	 * silently ignored. `updated_at` is refreshed automatically.
	 *
	 * @param int                  $id   Adjutancy ID.
	 * @param array<string, mixed> $data Subset of {slug, name}.
	 * @return bool True on successful update (zero or more rows affected).
	 */
	public static function update( int $id, array $data ): bool {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$update = array();
		$format = array();

		if ( isset( $data['slug'] ) && is_string( $data['slug'] ) ) {
			$update['slug'] = $data['slug'];
			$format[]       = '%s';
		}

		if ( isset( $data['name'] ) && is_string( $data['name'] ) ) {
			$update['name'] = $data['name'];
			$format[]       = '%s';
		}

		if ( empty( $update ) ) {
			return false;
		}

		$update['updated_at'] = current_time( 'mysql' );
		$format[]             = '%s';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Update via wpdb helper.
		$result = $wpdb->update( $table, $update, array( 'id' => $id ), $format, array( '%d' ) );

		static::cache_delete( "id_{$id}" );

		return false !== $result;
	}

	/**
	 * Delete an adjutancy row unconditionally.
	 *
	 * Deletion gating (zero references in `notice_adjutancy` / `classification`)
	 * lives in the REST controller / service layer; this method is a pure CRUD
	 * primitive and assumes the caller has already verified the gate.
	 *
	 * @param int $id Adjutancy ID.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		$wpdb  = self::db();
		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Delete via wpdb helper.
		$result = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		static::cache_delete( "id_{$id}" );

		return false !== $result;
	}
}

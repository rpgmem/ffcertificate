<?php
/**
 * Adjutancy Writer
 *
 * Write-side of the adjutancy repository split (#563 backlog, B3). Holds every
 * INSERT / UPDATE / DELETE plus the color-normalization helper. Reads live in
 * {@see RecruitmentAdjutancyReader}. Callers depend on the reader (reads) and
 * this writer (writes) directly; the delegating façade was retired in #563 B3-A.
 *
 * Schema-level invariants enforced here:
 *
 * - `slug` is UNIQUE (DB constraint). Insert/update operations rely on the
 *   constraint to surface duplicates as a `false` return.
 * - Deletion gating (zero references in `notice_adjutancy` and zero references
 *   in `classification`) is enforced by the service layer, not here — this
 *   writer's `delete()` is unconditional.
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
 * Write operations for `ffc_recruitment_adjutancy` rows.
 *
 * @since 6.11.3
 */
class RecruitmentAdjutancyWriter {

	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Cache group for this repository.
	 *
	 * Must match {@see RecruitmentAdjutancyReader::cache_group()} so writes
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
	 * Create a new adjutancy row.
	 *
	 * Caller is responsible for slug normalization. Returns `false` on
	 * insert failure (e.g. duplicate slug rejected by the UNIQUE constraint).
	 *
	 * @param string $slug  Unique slug.
	 * @param string $name  Display name.
	 * @param string $color Optional badge background color (#RGB / #RRGGBB / #RRGGBBAA);
	 *                      falls back to {@see RecruitmentAdjutancyReader::DEFAULT_COLOR} on empty input.
	 * @return int|false New adjutancy ID or false on failure.
	 */
	public static function create( string $slug, string $name, string $color = '' ) {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$now = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Insert via wpdb helper; format array supplied.
		$result = $wpdb->insert(
			$table,
			array(
				'slug'       => $slug,
				'name'       => $name,
				'color'      => self::normalize_color( $color ),
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $result ) {
			return false;
		}

		do_action( 'ffc_recruitment_public_cache_dirty' );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Normalize a color string into the canonical lowercase hex form.
	 *
	 * Accepts `#RGB`, `#RRGGBB`, or `#RRGGBBAA`; anything else falls back
	 * to {@see RecruitmentAdjutancyReader::DEFAULT_COLOR}. Mirrors the
	 * validator on {@see RecruitmentSettings::sanitize_color()} so the
	 * per-adjutancy picker and the per-status picker enforce identical input
	 * rules.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function normalize_color( string $value ): string {
		return \FreeFormCertificate\Core\ColorValidator::normalize( $value, RecruitmentAdjutancyReader::DEFAULT_COLOR );
	}

	/**
	 * Update name and/or slug on an existing adjutancy.
	 *
	 * Only `slug`, `name`, and `color` are accepted; any other key in `$data`
	 * is silently ignored. `updated_at` is refreshed automatically.
	 *
	 * @param int                  $id   Adjutancy ID.
	 * @param array<string, mixed> $data Subset of {slug, name, color}.
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

		if ( isset( $data['color'] ) && is_string( $data['color'] ) ) {
			$update['color'] = self::normalize_color( $data['color'] );
			$format[]        = '%s';
		}

		if ( empty( $update ) ) {
			return false;
		}

		$update['updated_at'] = current_time( 'mysql' );
		$format[]             = '%s';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Update via wpdb helper.
		$result = $wpdb->update( $table, $update, array( 'id' => $id ), $format, array( '%d' ) );

		static::cache_delete( "id_{$id}" );

		if ( false !== $result ) {
			do_action( 'ffc_recruitment_public_cache_dirty' );
		}

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

		if ( false !== $result ) {
			do_action( 'ffc_recruitment_public_cache_dirty' );
		}

		return false !== $result;
	}
}

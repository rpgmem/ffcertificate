<?php
/**
 * Reason Writer
 *
 * Write-side of the reason repository split (#563 backlog, B3). Holds every
 * INSERT / UPDATE / DELETE plus the color- and applies_to-normalization
 * helpers. Reads live in {@see RecruitmentReasonReader}. Callers depend on the
 * reader (reads) and this writer (writes) directly; the delegating façade was
 * retired in #563 B3-A.
 *
 * Schema-level invariants enforced here:
 *
 * - `slug` is UNIQUE (DB constraint). Insert/update operations rely on
 *   the constraint to surface duplicates as a `false` return.
 * - Deletion gating (zero references in `classification.preview_reason_id`)
 *   is enforced by the service layer, not here — this writer's `delete()`
 *   is unconditional.
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
 * Write operations for `ffc_recruitment_reason` rows.
 *
 * @since 6.11.3
 */
class RecruitmentReasonWriter {

	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Cache group for this repository.
	 *
	 * Must match {@see RecruitmentReasonReader::cache_group()} so writes
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
	 * Create a new reason row.
	 *
	 * @param string            $slug       Unique slug.
	 * @param string            $label      Display label.
	 * @param string            $color      Optional badge color (#RGB / #RRGGBB / #RRGGBBAA).
	 * @param array<int,string> $applies_to Subset of {@see RecruitmentReasonReader::APPLIES_TO_VALUES}.
	 *                                  Empty array = applies to every preview status.
	 * @return int|false New reason ID or false on failure.
	 */
	public static function create( string $slug, string $label, string $color = '', array $applies_to = array() ) {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$now = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Insert via wpdb helper.
		$result = $wpdb->insert(
			$table,
			array(
				'slug'       => $slug,
				'label'      => $label,
				'color'      => self::normalize_color( $color ),
				'applies_to' => self::normalize_applies_to( $applies_to ),
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $result ) {
			return false;
		}

		do_action( 'ffc_recruitment_public_cache_dirty' );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update slug / label / color / applies_to on an existing reason.
	 *
	 * @param int                  $id   Reason ID.
	 * @param array<string, mixed> $data Subset of {slug, label, color, applies_to (array)}.
	 * @return bool
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
		if ( isset( $data['label'] ) && is_string( $data['label'] ) ) {
			$update['label'] = $data['label'];
			$format[]        = '%s';
		}
		if ( isset( $data['color'] ) && is_string( $data['color'] ) ) {
			$update['color'] = self::normalize_color( $data['color'] );
			$format[]        = '%s';
		}
		if ( isset( $data['applies_to'] ) && is_array( $data['applies_to'] ) ) {
			$update['applies_to'] = self::normalize_applies_to( $data['applies_to'] );
			$format[]             = '%s';
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
	 * Delete a reason row unconditionally.
	 *
	 * Caller must verify the deletion gate (zero `classification.preview_reason_id`
	 * references) before calling.
	 *
	 * @param int $id Reason ID.
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

	/**
	 * Normalize a color string into the canonical lowercase hex form.
	 * Mirrors {@see RecruitmentAdjutancyWriter::normalize_color()}.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function normalize_color( string $value ): string {
		return \FreeFormCertificate\Core\ColorValidator::normalize( $value, RecruitmentReasonReader::DEFAULT_COLOR );
	}

	/**
	 * Normalize an applies_to selection into the canonical CSV form.
	 *
	 * Filters out values that aren't in
	 * {@see RecruitmentReasonReader::APPLIES_TO_VALUES}, deduplicates, and
	 * joins with commas. An empty input becomes the empty string
	 * (= "applies to every preview status").
	 *
	 * @param array<int, mixed> $value Raw selection.
	 * @return string
	 */
	public static function normalize_applies_to( array $value ): string {
		$allowed = RecruitmentReasonReader::APPLIES_TO_VALUES;
		$out     = array();
		foreach ( $value as $candidate ) {
			if ( ! is_string( $candidate ) ) {
				continue;
			}
			if ( in_array( $candidate, $allowed, true ) && ! in_array( $candidate, $out, true ) ) {
				$out[] = $candidate;
			}
		}
		return implode( ',', $out );
	}
}

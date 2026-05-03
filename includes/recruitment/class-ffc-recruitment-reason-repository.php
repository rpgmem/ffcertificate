<?php
/**
 * Reason Repository
 *
 * CRUD for the global "Reason" catalog — operator-defined labels
 * attached to a preliminary-list classification's `preview_status`
 * (e.g. "appeal granted because …", "denied because …"). Like
 * adjutancies in shape but reusable across every notice without an
 * attach junction.
 *
 * Schema-level invariants enforced here:
 *
 * - `slug` is UNIQUE (DB constraint). Insert/update operations rely on
 *   the constraint to surface duplicates as a `false` return.
 * - Deletion gating (zero references in `classification.preview_reason_id`)
 *   is enforced by the service layer, not here — this repository's
 *   `delete()` is unconditional.
 *
 * `applies_to` is an empty-or-CSV list of preview-status enum values
 * (`denied,granted,appeal_denied,appeal_granted`). Empty string means
 * "applies to every preview status"; a non-empty list narrows the
 * dropdown when the admin picks a status.
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
 * Database repository for `ffc_recruitment_reason` rows.
 *
 * @phpstan-type ReasonRow \stdClass&object{id: numeric-string, slug: string, label: string, color: string, applies_to: string, created_at: string, updated_at: string}
 */
class RecruitmentReasonRepository {

	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/** Default badge color used when admins haven't picked one yet. */
	public const DEFAULT_COLOR = '#e9ecef';

	/** Preview-status enum values that a reason can be tagged with. */
	public const APPLIES_TO_VALUES = array( 'denied', 'granted', 'appeal_denied', 'appeal_granted' );

	/**
	 * Cache group for this repository.
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
	 * Create a new reason row.
	 *
	 * @param string            $slug       Unique slug.
	 * @param string            $label      Display label.
	 * @param string            $color      Optional badge color (#RGB / #RRGGBB / #RRGGBBAA).
	 * @param array<int,string> $applies_to Subset of {@see self::APPLIES_TO_VALUES}.
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
	 * Mirrors {@see RecruitmentAdjutancyRepository::normalize_color()}.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function normalize_color( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return self::DEFAULT_COLOR;
		}
		if ( 1 === preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value ) ) {
			return strtolower( $value );
		}
		return self::DEFAULT_COLOR;
	}

	/**
	 * Normalize an applies_to selection into the canonical CSV form.
	 *
	 * Filters out values that aren't in {@see self::APPLIES_TO_VALUES},
	 * deduplicates, and joins with commas. An empty input becomes the
	 * empty string (= "applies to every preview status").
	 *
	 * @param array<int, mixed> $value Raw selection.
	 * @return string
	 */
	public static function normalize_applies_to( array $value ): string {
		$allowed = self::APPLIES_TO_VALUES;
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

	/**
	 * Decode a stored applies_to CSV back into a list. An empty stored
	 * value yields {@see self::APPLIES_TO_VALUES} ("applies to all").
	 *
	 * @param string $stored CSV value from the row.
	 * @return list<string>
	 */
	public static function decode_applies_to( string $stored ): array {
		$stored = trim( $stored );
		if ( '' === $stored ) {
			return self::APPLIES_TO_VALUES;
		}
		$parts = array_filter( array_map( 'trim', explode( ',', $stored ) ) );
		$out   = array();
		foreach ( $parts as $candidate ) {
			if ( in_array( $candidate, self::APPLIES_TO_VALUES, true ) && ! in_array( $candidate, $out, true ) ) {
				$out[] = $candidate;
			}
		}
		return empty( $out ) ? self::APPLIES_TO_VALUES : $out;
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

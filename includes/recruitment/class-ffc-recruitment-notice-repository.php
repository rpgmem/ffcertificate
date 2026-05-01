<?php
/**
 * Notice Repository
 *
 * CRUD for the `ffc_recruitment_notice` table — the edital lifecycle
 * (draft → preliminary → active → closed).
 *
 * State transitions are NOT performed here: the repository exposes raw status
 * setters used by the {@see NoticeStateMachine} (sprint 5). This separation
 * keeps the repository as a thin CRUD primitive and the state machine as the
 * single source of truth for transition validity, reason gating, and the
 * `was_reopened` flag flip.
 *
 * The `code` column is normalized to UPPERCASE on insert/update; lookups via
 * {@see self::get_by_code()} re-uppercase the input for consistency.
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
 * Database repository for `ffc_recruitment_notice` rows.
 *
 * `public_columns_config` is exposed as the raw JSON string; decoding into an
 * associative array is the caller's responsibility (typically the renderer or
 * the REST controller, which validates the shape against the schema in
 * §3.2 / §8.2 of the implementation plan).
 *
 * @phpstan-type NoticeRow \stdClass&object{id: numeric-string, code: string, name: string, status: string, opened_at: string|null, closed_at: string|null, was_reopened: numeric-string, public_columns_config: string, created_at: string, updated_at: string}
 */
class NoticeRepository {

	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Default `public_columns_config` JSON applied to new notices.
	 *
	 * Mirrors §3.2 of the implementation plan. `rank` and `name` are the
	 * mandatory columns and cannot be toggled off via PATCH (validation
	 * lives in the REST controller, not here).
	 *
	 * @var string
	 */
	public const DEFAULT_PUBLIC_COLUMNS_CONFIG = '{"rank":true,"name":true,"status":true,"pcd_badge":true,"date_to_assume":true,"score":false,"cpf_masked":false,"rf_masked":false,"email_masked":false}';

	/**
	 * Cache group for this repository.
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

	/**
	 * Create a new notice in `draft` status.
	 *
	 * `code` is uppercased on store. `public_columns_config` defaults to
	 * {@see self::DEFAULT_PUBLIC_COLUMNS_CONFIG} when omitted; callers may
	 * override by passing a `public_columns_config` JSON string.
	 *
	 * State-transition timestamps (`opened_at`, `closed_at`) and the
	 * `was_reopened` flag are NOT set here — those are managed by the state
	 * machine in sprint 5.
	 *
	 * @param string $code Notice code (will be uppercased).
	 * @param string $name Human-readable name.
	 * @param string $public_columns_config Optional JSON config; defaults to schema's defaults.
	 * @return int|false New notice ID or false on failure (e.g. duplicate code).
	 */
	public static function create( string $code, string $name, string $public_columns_config = '' ) {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$now    = current_time( 'mysql' );
		$config = '' === $public_columns_config ? self::DEFAULT_PUBLIC_COLUMNS_CONFIG : $public_columns_config;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Insert via wpdb helper; explicit formats.
		$result = $wpdb->insert(
			$table,
			array(
				'code'                  => strtoupper( $code ),
				'name'                  => $name,
				'status'                => 'draft',
				'was_reopened'          => 0,
				'public_columns_config' => $config,
				'created_at'            => $now,
				'updated_at'            => $now,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( ! $result ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update mutable notice metadata.
	 *
	 * Accepted keys: `name`, `code` (uppercased), `public_columns_config`.
	 * The state column and lifecycle timestamps go through dedicated
	 * setters used by the state machine (sprint 5):
	 * {@see self::set_status()}, {@see self::mark_opened()},
	 * {@see self::mark_closed()}, {@see self::mark_reopened()}.
	 *
	 * @param int                  $id   Notice ID.
	 * @param array<string, mixed> $data Update payload (see allowed keys above).
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$update = array();
		$format = array();

		if ( isset( $data['name'] ) && is_string( $data['name'] ) ) {
			$update['name'] = $data['name'];
			$format[]       = '%s';
		}

		if ( isset( $data['code'] ) && is_string( $data['code'] ) ) {
			$update['code'] = strtoupper( $data['code'] );
			$format[]       = '%s';
		}

		if ( isset( $data['public_columns_config'] ) && is_string( $data['public_columns_config'] ) ) {
			$update['public_columns_config'] = $data['public_columns_config'];
			$format[]                        = '%s';
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
	 * Set the notice status atomically, gated by an expected current status.
	 *
	 * Used by the state machine to enforce state-transition rules under
	 * concurrency: the UPDATE only succeeds if the row is currently in
	 * `$expected_current`. Returns the affected-row count (0 or 1) so the
	 * caller can detect a lost race.
	 *
	 * @param int    $id Notice ID.
	 * @param string $expected_current Current status the caller observed.
	 * @param string $new_status Target status.
	 * @return int Number of rows affected (1 on success, 0 if race lost).
	 */
	public static function set_status( int $id, string $expected_current, string $new_status ): int {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$prepared = $wpdb->prepare(
			'UPDATE %i SET status = %s, updated_at = %s WHERE id = %d AND status = %s',
			$table,
			$new_status,
			current_time( 'mysql' ),
			$id,
			$expected_current
		);

		if ( ! is_string( $prepared ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Atomic transition primitive; $prepared came from $wpdb->prepare() on the line above.
		$affected = $wpdb->query( $prepared );

		static::cache_delete( "id_{$id}" );

		return is_int( $affected ) ? $affected : 0;
	}

	/**
	 * Stamp `opened_at` on the first transition to `active`.
	 *
	 * Intended to be called only when `opened_at IS NULL` (state machine
	 * enforces this; the WHERE clause double-guards). Subsequent reopens do
	 * not touch this column.
	 *
	 * @param int $id Notice ID.
	 * @return int Number of rows affected.
	 */
	public static function mark_opened( int $id ): int {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$now      = current_time( 'mysql' );
		$prepared = $wpdb->prepare(
			'UPDATE %i SET opened_at = %s, updated_at = %s WHERE id = %d AND opened_at IS NULL',
			$table,
			$now,
			$now,
			$id
		);

		if ( ! is_string( $prepared ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One-shot timestamp setter; $prepared came from $wpdb->prepare() on the line above.
		$affected = $wpdb->query( $prepared );

		static::cache_delete( "id_{$id}" );

		return is_int( $affected ) ? $affected : 0;
	}

	/**
	 * Stamp `closed_at` on every transition to `closed` (overwrites).
	 *
	 * @param int $id Notice ID.
	 * @return int Number of rows affected.
	 */
	public static function mark_closed( int $id ): int {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$now      = current_time( 'mysql' );
		$prepared = $wpdb->prepare(
			'UPDATE %i SET closed_at = %s, updated_at = %s WHERE id = %d',
			$table,
			$now,
			$now,
			$id
		);

		if ( ! is_string( $prepared ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Timestamp setter; $prepared came from $wpdb->prepare() on the line above.
		$affected = $wpdb->query( $prepared );

		static::cache_delete( "id_{$id}" );

		return is_int( $affected ) ? $affected : 0;
	}

	/**
	 * Flip `was_reopened` to 1 on the first `closed → active` transition.
	 *
	 * One-way flip: once `was_reopened = 1`, this method is a no-op (the
	 * WHERE clause guards against re-flipping). Drives the reopen-freeze
	 * rule (§5.1 / §5.2) — once set, all transitions out of `hired` and
	 * `not_shown` are blocked for this notice.
	 *
	 * @param int $id Notice ID.
	 * @return int Number of rows affected (1 on first reopen, 0 otherwise).
	 */
	public static function mark_reopened( int $id ): int {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$prepared = $wpdb->prepare(
			'UPDATE %i SET was_reopened = 1, updated_at = %s WHERE id = %d AND was_reopened = 0',
			$table,
			current_time( 'mysql' ),
			$id
		);

		if ( ! is_string( $prepared ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- One-way flag flip; $prepared came from $wpdb->prepare() on the line above.
		$affected = $wpdb->query( $prepared );

		static::cache_delete( "id_{$id}" );

		return is_int( $affected ) ? $affected : 0;
	}

	/**
	 * Delete a notice unconditionally.
	 *
	 * No deletion gates exist for notices in v1 — admin notices are kept
	 * indefinitely (LGPD anonymization is a future issue per §16). This
	 * method is provided for completeness and used only by tests.
	 *
	 * @param int $id Notice ID.
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

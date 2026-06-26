<?php
/**
 * Notice Writer
 *
 * Write-side of the notice repository split (#563 backlog, B3). Holds every
 * INSERT / UPDATE / DELETE and the atomic state-transition primitives used by
 * the {@see NoticeStateMachine}. Reads live in {@see RecruitmentNoticeReader}.
 * Callers depend on the reader (reads) and this writer (writes) directly; the
 * delegating façade was retired in #563 B3-A.
 *
 * State transitions are NOT performed here: the writer exposes raw status
 * setters used by the state machine. This separation keeps the writer as a
 * thin CRUD primitive and the state machine as the single source of truth for
 * transition validity, reason gating, and the `was_reopened` flag flip.
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
 * Write operations for `ffc_recruitment_notice` rows.
 *
 * @since 6.11.3
 */
class RecruitmentNoticeWriter {

	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Cache group for this repository.
	 *
	 * Must match {@see RecruitmentNoticeReader::cache_group()} so writes
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
	 * Create a new notice in `draft` status.
	 *
	 * `code` is uppercased on store. `public_columns_config` defaults to
	 * {@see RecruitmentNoticeReader::DEFAULT_PUBLIC_COLUMNS_CONFIG} when
	 * omitted; callers may override by passing a `public_columns_config` JSON
	 * string.
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
		$config = '' === $public_columns_config ? RecruitmentNoticeReader::DEFAULT_PUBLIC_COLUMNS_CONFIG : $public_columns_config;

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

		do_action( 'ffc_recruitment_public_cache_dirty' );

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

		if ( false !== $result ) {
			do_action( 'ffc_recruitment_public_cache_dirty' );
		}

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

		$rows = is_int( $affected ) ? $affected : 0;
		if ( $rows > 0 ) {
			do_action( 'ffc_recruitment_public_cache_dirty' );
		}

		return $rows;
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

		if ( false !== $result ) {
			do_action( 'ffc_recruitment_public_cache_dirty' );
		}

		return false !== $result;
	}
}

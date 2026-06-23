<?php
/**
 * Candidate Writer
 *
 * Write-side of the candidate repository split (#563 phase-2, Sprint D1). Holds
 * every INSERT / UPDATE / DELETE for `ffc_recruitment_candidate` rows plus the
 * cache invalidation that accompanies them. Reads live in
 * {@see RecruitmentCandidateReader}. Callers depend on the reader (reads) and
 * this writer (writes) directly; the delegating façade was retired in #563 B3-A.
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
 * Write operations for `ffc_recruitment_candidate` rows.
 *
 * @since 6.0.0
 */
class RecruitmentCandidateWriter {

	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Cache group for this repository.
	 *
	 * Must match {@see RecruitmentCandidateReader::cache_group()} so writes
	 * invalidate the entries reads populate.
	 *
	 * @return string
	 */
	protected static function cache_group(): string {
		return 'ffc_recruitment_candidate';
	}

	/**
	 * Get the fully-prefixed table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		return self::db()->prefix . 'ffc_recruitment_candidate';
	}

	/**
	 * Insert a new candidate row.
	 *
	 * Required keys: `name`, `pcd_hash`. At least one of `cpf_hash` or
	 * `rf_hash` must be present (caller's responsibility; the schema does
	 * NOT enforce this — both columns allow NULL because UNIQUE indexes
	 * permit multiple NULLs in MySQL).
	 *
	 * Optional keys: `user_id`, `cpf_encrypted`, `cpf_hash`, `rf_encrypted`,
	 * `rf_hash`, `email_encrypted`, `email_hash`, `phone`, `notes`.
	 *
	 * Returns `false` on UNIQUE collision (`cpf_hash` or `rf_hash` already
	 * present on another row) or other DB failure.
	 *
	 * @param array<string, mixed> $data Candidate payload (see allowed keys above).
	 * @return int|false New candidate ID or false on failure.
	 */
	public static function create( array $data ) {
		$wpdb  = self::db();
		$table = self::get_table_name();

		if ( ! isset( $data['name'], $data['pcd_hash'] ) || ! is_string( $data['name'] ) || ! is_string( $data['pcd_hash'] ) ) {
			return false;
		}

		$now = current_time( 'mysql' );

		$insert = array(
			'name'       => $data['name'],
			'pcd_hash'   => $data['pcd_hash'],
			'created_at' => $now,
			'updated_at' => $now,
		);
		$format = array( '%s', '%s', '%s', '%s' );

		$optional_columns = array(
			'user_id'         => '%d',
			'cpf_encrypted'   => '%s',
			'cpf_hash'        => '%s',
			'rf_encrypted'    => '%s',
			'rf_hash'         => '%s',
			'email_encrypted' => '%s',
			'email_hash'      => '%s',
			'phone'           => '%s',
			'notes'           => '%s',
		);

		foreach ( $optional_columns as $column => $column_format ) {
			if ( array_key_exists( $column, $data ) && null !== $data[ $column ] ) {
				$insert[ $column ] = $data[ $column ];
				$format[]          = $column_format;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Insert via wpdb helper.
		$result = $wpdb->insert( $table, $insert, $format );

		if ( ! $result ) {
			return false;
		}

		do_action( 'ffc_recruitment_public_cache_dirty' );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update mutable candidate fields.
	 *
	 * Accepted keys: `name`, `phone`, `notes`, `cpf_encrypted` + `cpf_hash`
	 * (must be supplied together), `rf_encrypted` + `rf_hash` (together),
	 * `email_encrypted` + `email_hash` (together).
	 *
	 * `user_id` is NOT writable here — use {@see self::set_user_id()} for
	 * promotion. `pcd_hash` is NOT writable — PCD value is set on creation
	 * only (sprint 4 enforces "PCD is CSV-only" per §12).
	 *
	 * @param int                  $id   Candidate ID.
	 * @param array<string, mixed> $data Update payload.
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$update = array();
		$format = array();

		$writable = array(
			'name'            => '%s',
			'phone'           => '%s',
			'notes'           => '%s',
			'cpf_encrypted'   => '%s',
			'cpf_hash'        => '%s',
			'rf_encrypted'    => '%s',
			'rf_hash'         => '%s',
			'email_encrypted' => '%s',
			'email_hash'      => '%s',
		);

		foreach ( $writable as $column => $column_format ) {
			if ( array_key_exists( $column, $data ) ) {
				$update[ $column ] = $data[ $column ];
				$format[]          = $column_format;
			}
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
	 * Set or clear the linked `wp_users.ID` (promotion / un-link).
	 *
	 * Called by the service layer after `UserCreator::get_or_create_user()`
	 * resolves a `wp_user` ID. Pass `null` to detach (rare; mostly for tests).
	 *
	 * @param int      $id Candidate ID.
	 * @param int|null $user_id WP user ID, or null to clear.
	 * @return bool
	 */
	public static function set_user_id( int $id, ?int $user_id ): bool {
		$wpdb  = self::db();
		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Update via wpdb helper.
		$result = $wpdb->update(
			$table,
			array(
				'user_id'    => $user_id,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		static::cache_delete( "id_{$id}" );

		return false !== $result;
	}

	/**
	 * Hard-delete a candidate row unconditionally.
	 *
	 * Deletion gating (zero classifications) lives in the REST controller
	 * (sprint 7); this method is a pure CRUD primitive and assumes the
	 * caller has already verified the gate. The linked `wp_user` (if any)
	 * is preserved — the recruitment module never deletes WP users.
	 *
	 * @param int $id Candidate ID.
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

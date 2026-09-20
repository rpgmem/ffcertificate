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

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement in this class runs against one of the plugin's own ffc_* tables, which WordPress exposes no API for. Caching is decided per read at the repository layer, not per statement (#1042).
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
	 * The `ffc_adopt_orphaned_identity_records` entry point.
	 *
	 * A thin `void` wrapper, because a WordPress action callback must not
	 * return a value while {@see self::link_orphans_by_hash()} deliberately
	 * does: the count is what a caller reports, and what the tests assert
	 * against. Named for the hook so the registration in `Loader` reads as
	 * what it is, and so the pair stays removable by `remove_action()`.
	 *
	 * @since 6.28.0
	 * @param string|null $cpf_hash CPF hash, or null.
	 * @param string|null $rf_hash  RF hash, or null.
	 * @param int         $user_id  The resolved WP user.
	 * @return void
	 */
	public static function adopt_orphaned_identity_records( ?string $cpf_hash, ?string $rf_hash, int $user_id ): void {
		self::link_orphans_by_hash( $cpf_hash, $rf_hash, $user_id );
	}

	/**
	 * Claim every unlinked candidacy carrying one of these identifier hashes.
	 *
	 * The recruitment half of identity adoption (#1345).
	 * `UserCreator::link_orphaned_records_dual()` adopts unlinked submissions
	 * and appointments the moment a person is resolved, and nothing did the
	 * same for candidacies -- while `UserCleanup` happily NULLs `user_id` here
	 * on account deletion. So the plugin could drop the link and never restore
	 * it, which made detaching a candidacy permanent loss. Fifteen of the 73
	 * conflicting accounts measured in production touch this table, two of
	 * them exclusively.
	 *
	 * EVERY MATCHING ROW, NEVER ONE
	 *
	 * One person applying to two positions is two rows under the same
	 * identifier -- legitimate, and the reason `COUNT(DISTINCT)` does not flag
	 * them as a conflict. A `LIMIT 1` here would adopt one candidacy and leave
	 * its sibling orphaned, which is the defect wearing a smaller size.
	 *
	 * THE IDS ARE READ BEFORE THE WRITE, FOR THE CACHE
	 *
	 * {@see RecruitmentCandidateReader::get_by_id()} caches under `id_<id>`,
	 * so a bulk `UPDATE` alone would leave a cached row still claiming no
	 * user -- invisible without a persistent object cache and permanent with
	 * one. Reading the ids first is what makes the invalidation possible, and
	 * it is why this lives on the writer rather than being issued as SQL by
	 * the caller: the cache group is this class's own.
	 *
	 * @since 6.28.0
	 * @param string|null $cpf_hash CPF hash, or null to skip that column.
	 * @param string|null $rf_hash  RF hash, or null to skip that column.
	 * @param int         $user_id  The resolved WP user.
	 * @return int Rows claimed.
	 */
	public static function link_orphans_by_hash( ?string $cpf_hash, ?string $rf_hash, int $user_id ): int {
		$cpf_hash = ( is_string( $cpf_hash ) && '' !== $cpf_hash ) ? $cpf_hash : null;
		$rf_hash  = ( is_string( $rf_hash ) && '' !== $rf_hash ) ? $rf_hash : null;

		if ( $user_id <= 0 || ( null === $cpf_hash && null === $rf_hash ) ) {
			return 0;
		}

		$wpdb  = self::db();
		$table = self::get_table_name();

		$where  = array();
		$values = array();
		if ( null !== $cpf_hash ) {
			$where[]  = 'cpf_hash = %s';
			$values[] = $cpf_hash;
		}
		if ( null !== $rf_hash ) {
			$where[]  = 'rf_hash = %s';
			$values[] = $rf_hash;
		}
		$clause = implode( ' OR ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- `$clause` is built from this method's own fragments with matching placeholders, and `$sql` holds the result of `prepare()` two statements up -- held in a variable only so a null return can be refused before it reaches `query()`. `WordPress.DB.DirectDatabaseQuery` is deliberately NOT named here: the file-level disable already covers it, and re-enabling it below would switch it back on for the rest of the file no matter who turned it off.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM %i WHERE ({$clause}) AND user_id IS NULL",
				$table,
				...$values
			)
		);

		if ( empty( $ids ) ) {
			return 0;
		}

		// `prepare()` answers null when its placeholders and values disagree,
		// and `query( null )` would be a silent no-op wearing a success.
		$sql = $wpdb->prepare(
			"UPDATE %i SET user_id = %d, updated_at = %s WHERE ({$clause}) AND user_id IS NULL",
			$table,
			$user_id,
			current_time( 'mysql' ),
			...$values
		);

		if ( ! is_string( $sql ) ) {
			return 0;
		}

		$updated = $wpdb->query( $sql );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		foreach ( $ids as $id ) {
			static::cache_delete( 'id_' . (int) $id );
		}

		return is_numeric( $updated ) ? (int) $updated : 0;
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

		$result = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		static::cache_delete( "id_{$id}" );

		if ( false !== $result ) {
			do_action( 'ffc_recruitment_public_cache_dirty' );
		}

		return false !== $result;
	}
}

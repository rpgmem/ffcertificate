<?php
/**
 * User Profile Repository
 *
 * Data access layer for the `ffc_user_profiles` table — the FFC-side
 * extension to `wp_users` that carries the plugin's custom-field
 * payload (display_name override, custom_data blob, etc.).
 *
 * Centralizes the queries that previously lived inline in
 * `UserManager::get_profile()` and `UserCreator::create_user_profile()`
 * (issue #340 cleanup). `UserService` is the higher-level aggregator
 * that merges WP-core user data + this profile row + capability map
 * into a view-model — it can layer on top of this CRUD primitive when
 * its production wire-up lands (tracked in #322).
 *
 * @package FreeFormCertificate\Repositories
 * @since   6.6.2
 */

declare(strict_types=1);

namespace FreeFormCertificate\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
/**
 * Database repository for `ffc_user_profiles` rows.
 */
class UserProfileRepository extends AbstractRepository {

	/**
	 * Get table name.
	 *
	 * @return string
	 */
	protected function get_table_name(): string {
		return $this->wpdb->prefix . 'ffc_user_profiles';
	}

	/**
	 * Cache group for the inherited cache helpers.
	 *
	 * @return string
	 */
	protected function get_cache_group(): string {
		return 'ffc_user_profiles';
	}

	/**
	 * Find a profile row by its WP user id.
	 *
	 * @since 6.6.2
	 * @param int $user_id WordPress user ID.
	 * @return array<string, mixed>|null
	 */
	public function findByUserId( int $user_id ): ?array {
		if ( $user_id <= 0 ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single-row indexed lookup.
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d', $this->table, $user_id ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Whether a profile row exists for the supplied user_id.
	 * Cheaper than `findByUserId()` when the caller only needs the
	 * boolean (no SELECT *).
	 *
	 * @since 6.6.2
	 * @param int $user_id WordPress user ID.
	 * @return bool
	 */
	public function existsForUserId( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence-style lookup; pk-indexed.
		$id = $this->wpdb->get_var(
			$this->wpdb->prepare( 'SELECT id FROM %i WHERE user_id = %d', $this->table, $user_id )
		);
		// `$id` is a string ("42") in real wpdb when the row exists,
		// or null when missing. Mocks sometimes return 0 / "0" — treat
		// any non-positive integer interpretation as "missing".
		return null !== $id && '' !== $id && (int) $id > 0;
	}

	/**
	 * Create a profile row for a freshly-promoted WP user. No-op when
	 * a row already exists for the same user_id (race-safe — the call
	 * is idempotent).
	 *
	 * @since 6.6.2
	 * @param int    $user_id      WordPress user ID.
	 * @param string $display_name Initial display_name value.
	 * @return int|false Insert id, or false on failure / when a row
	 *                   already existed.
	 */
	public function createForUser( int $user_id, string $display_name ) {
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( $this->existsForUserId( $user_id ) ) {
			return false;
		}

		$now = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Insert via wpdb helper; cache invalidated below.
		$inserted = $this->wpdb->insert(
			$this->table,
			array(
				'user_id'      => $user_id,
				'display_name' => $display_name,
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%d', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return false;
		}
		$this->clear_cache();
		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Upsert a column slice for the supplied `user_id`. Inserts when
	 * no row exists for the user, otherwise UPDATEs in place. Used by
	 * `UserProfileService::update_profile_table()` to persist dynamic
	 * column sets (which fields land here depends on the field map,
	 * not on a fixed schema view).
	 *
	 * No-op when `$data` is empty.
	 *
	 * Issue #340 centralization.
	 *
	 * @since 6.6.2
	 * @param int                  $user_id WP user id.
	 * @param array<string, mixed> $data    Column => value slice to persist.
	 * @return bool True on a successful write, false on failure / invalid input.
	 */
	public function upsertForUserId( int $user_id, array $data ): bool {
		if ( $user_id <= 0 || empty( $data ) ) {
			return false;
		}

		if ( $this->existsForUserId( $user_id ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single-row update keyed by user_id.
			$affected = $this->wpdb->update( $this->table, $data, array( 'user_id' => $user_id ) );
			if ( false === $affected ) {
				return false;
			}
			$this->clear_cache( "user_{$user_id}" );
			return true;
		}

		$data['user_id'] = $user_id;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Insert via wpdb helper; cache invalidated below.
		$inserted = $this->wpdb->insert( $this->table, $data );
		if ( false === $inserted ) {
			return false;
		}
		$this->clear_cache();
		return true;
	}
}

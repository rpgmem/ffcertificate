<?php
/**
 * Audience Writer
 *
 * Write-side of the audience repository split (#563 backlog, A6). Holds every
 * INSERT / UPDATE / DELETE and the membership mutation helpers. Reads live in
 * {@see AudienceReader}. Callers depend on the reader (reads) and this writer
 * (writes) directly; the delegating façade was retired in #563 B3-A.
 *
 * @since   6.12.0
 * @package FreeFormCertificate\Audience
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- {$placeholders} is %d repeated to match count() of the bound id array; $wpdb->prepare() takes them as a single array argument, which the sniff counts as one replacement.
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Every statement in this class runs against one of the plugin's own ffc_* tables, which WordPress exposes no API for. Caching is decided per read at the repository layer, not per statement (#1042).
/**
 * Write operations for audience records.
 *
 * @since 6.12.0
 */
class AudienceWriter {
	use \FreeFormCertificate\Core\StaticRepositoryTrait;

	/**
	 * Cache group for this repository.
	 *
	 * Must match {@see AudienceReader::cache_group()} so writes invalidate
	 * the entries reads populate.
	 *
	 * @return string
	 */
	protected static function cache_group(): string {
		return 'ffc_audiences';
	}

	/**
	 * Cache-version domain shared by every audience query/aggregate cache.
	 * Bumped on every write so {@see AudienceReader::count()} /
	 * {@see AudienceReader::search()} (md5-keyed, un-enumerable) recompute
	 * after a mutation (#644).
	 *
	 * @var string
	 */
	private const CACHE_DOMAIN = 'audience';

	/**
	 * Get audiences table name
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		return self::db()->prefix . 'ffc_audiences';
	}

	/**
	 * Get members table name
	 *
	 * @return string
	 */
	public static function get_members_table_name(): string {
		return self::db()->prefix . 'ffc_audience_members';
	}

	/**
	 * Create an audience
	 *
	 * @param array<string, mixed> $data Audience data.
	 * @return int|false Audience ID or false on failure
	 */
	public static function create( array $data ) {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$defaults = array(
			'name'            => '',
			'color'           => '#3788d8',
			'parent_id'       => null,
			'status'          => 'active',
			'allow_self_join' => 0,
			'created_by'      => get_current_user_id(),
		);
		$data     = wp_parse_args( $data, $defaults );

		$insert_data   = array(
			'name'       => $data['name'],
			'color'      => $data['color'],
			'parent_id'  => $data['parent_id'],
			'status'     => $data['status'],
			'created_by' => $data['created_by'],
		);
		$insert_format = array( '%s', '%s', '%d', '%s', '%d' );

		// Only include allow_self_join if column exists (migration may not have run).
		if ( isset( $data['allow_self_join'] ) ) {
			$insert_data['allow_self_join'] = (int) $data['allow_self_join'];
			$insert_format[]                = '%d';
		}

		$result = $wpdb->insert( $table, $insert_data, $insert_format );

		if ( ! $result ) {
			return false;
		}

		$new_id = (int) $wpdb->insert_id;

		\FreeFormCertificate\Core\CacheVersion::bump( self::CACHE_DOMAIN );

		/**
		 * Fires after an audience is successfully created.
		 *
		 * Subscribers can perform secondary provisioning such as seeding
		 * reregistration standard fields for the new audience.
		 *
		 * @since 5.0.0
		 * @param int                  $audience_id New audience ID.
		 * @param array<string, mixed> $data        Normalized creation data.
		 */
		do_action( 'ffc_audience_created', $new_id, $data );

		return $new_id;
	}

	/**
	 * Update an audience
	 *
	 * @param int                  $id Audience ID.
	 * @param array<string, mixed> $data Update data.
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		$wpdb  = self::db();
		$table = self::get_table_name();

		// Remove fields that shouldn't be updated.
		unset( $data['id'], $data['created_by'], $data['created_at'] );

		if ( empty( $data ) ) {
			return false;
		}

		// Build update data and format arrays.
		$update_data = array();
		$format      = array();

		$field_formats = array(
			'name'            => '%s',
			'color'           => '%s',
			'parent_id'       => '%d',
			'status'          => '%s',
			'allow_self_join' => '%d',
		);

		foreach ( $data as $key => $value ) {
			if ( isset( $field_formats[ $key ] ) ) {
				$update_data[ $key ] = $value;
				$format[]            = $field_formats[ $key ];
			}
		}

		// Two of the updatable columns change what `get_user_audiences()`
		// answers, for people whose membership did not change at all: the query
		// filters `status = 'active'`, and its `include_parents` variant walks
		// `parent_id` upward. Read the affected users before the write —
		// membership is the same either side of it, but doing it here keeps the
		// order identical to delete(), where it genuinely matters.
		$touches_user_lists = isset( $update_data['status'] ) || array_key_exists( 'parent_id', $update_data );
		$affected           = $touches_user_lists ? AudienceReader::get_members( $id, true ) : array();

		$result = $wpdb->update(
			$table,
			$update_data,
			array( 'id' => $id ),
			$format,
			array( '%d' )
		);

		static::cache_delete( "id_{$id}" );
		\FreeFormCertificate\Core\CacheVersion::bump( self::CACHE_DOMAIN );
		self::invalidate_user_audiences( $affected );

		return false !== $result;
	}

	/**
	 * Cascade allow_self_join flag from parent to all descendants
	 *
	 * @since 4.9.10
	 * @param int $parent_id Parent audience ID.
	 * @param int $value     1 or 0.
	 * @return void
	 */
	public static function cascade_self_join( int $parent_id, int $value ): void {
		$wpdb  = self::db();
		$table = self::get_table_name();

		$children = AudienceReader::get_children( $parent_id );
		if ( empty( $children ) ) {
			return;
		}

		$child_ids    = array_map(
			function ( $c ) {
				return (int) $c->id;
			},
			$children
		);
		$placeholders = implode( ',', array_fill( 0, count( $child_ids ), '%d' ) );

		$update_sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$placeholders} is %d repeated to match count( $child_ids ); the table goes through %i and the ids are bound as a single array.
			"UPDATE %i SET allow_self_join = %d WHERE id IN ({$placeholders})",
			array_merge( array( $table, $value ), $child_ids )
		);
		if ( is_string( $update_sql ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The argument is the string $wpdb->prepare() returned above; the sniff cannot follow a prepared string across an assignment.
			$wpdb->query( $update_sql );
		}

		// `allow_self_join` is one of the columns `get_user_audiences()` returns,
		// so every member of every touched child was reading a stale flag. This
		// method invalidated nothing at all — not the by-id entries, not the
		// query version, not the per-user lists (#1127).
		//
		// The recursion below repeats this for each level, so the top call's
		// `include_children` sweep is redone for subsets. Left as-is: the extra
		// work is one query per level of an admin-triggered cascade, and
		// threading a "top-level only" flag through a public recursive method
		// costs more clarity than it saves.
		foreach ( $child_ids as $child_id ) {
			static::cache_delete( "id_{$child_id}" );
		}
		self::invalidate_user_audiences( AudienceReader::get_members( $parent_id, true ) );
		\FreeFormCertificate\Core\CacheVersion::bump( self::CACHE_DOMAIN );

		// Recurse into each child.
		foreach ( $children as $child ) {
			self::cascade_self_join( (int) $child->id, $value );
		}
	}

	/**
	 * Delete an audience
	 *
	 * Note: This also deletes all child audiences and member associations.
	 *
	 * @param int $id Audience ID.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		$wpdb          = self::db();
		$table         = self::get_table_name();
		$members_table = self::get_members_table_name();

		// Collect the affected users BEFORE anything is deleted: the recursion
		// below empties the members table of every descendant, so asking
		// afterwards returns nothing. `include_children` is required — a user
		// who is only in a child audience still sees this one through the
		// `include_parents` variant of the cached query.
		$affected = AudienceReader::get_members( $id, true );

		// Delete children first.
		$children = AudienceReader::get_children( $id );
		foreach ( $children as $child ) {
			self::delete( (int) $child->id );
		}

		// Delete member associations.
		$wpdb->delete( $members_table, array( 'audience_id' => $id ), array( '%d' ) );

		// Delete the audience.
		$result = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		static::cache_delete( "id_{$id}" );
		\FreeFormCertificate\Core\CacheVersion::bump( self::CACHE_DOMAIN );
		self::invalidate_user_audiences( $affected );

		return false !== $result;
	}

	/**
	 * Drop the cached audience list of every affected user.
	 *
	 * `AudienceReader::get_user_audiences()` answers "which audiences is this
	 * user in", and its result changes on **membership** (a row in the members
	 * table) *and* on the **audience row itself** — the query filters
	 * `status = 'active'`, returns `allow_self_join`, and the `include_parents`
	 * variant walks `parent_id` upward. So a mutator touching any of those has
	 * to invalidate here, not only the ones that write the members table.
	 *
	 * It is deliberately **explicit per user** rather than a
	 * {@see \FreeFormCertificate\Core\CacheVersion} bump. A version bump writes
	 * an option, so a 500-user bulk add would be 500 `update_option()` calls,
	 * and it would retire every audience query cache for every user. The
	 * versioned group exists for keys that *cannot* be enumerated at write
	 * time (`md5( args )` hashes); this key can — a mutator always knows whose
	 * membership it changed.
	 *
	 * @param array<int|string> $user_ids Affected user IDs.
	 * @return void
	 */
	private static function invalidate_user_audiences( array $user_ids ): void {
		foreach ( array_unique( array_map( 'absint', $user_ids ) ) as $uid ) {
			if ( $uid <= 0 ) {
				continue;
			}
			static::cache_delete( AudienceReader::user_audiences_cache_key( $uid, false ) );
			static::cache_delete( AudienceReader::user_audiences_cache_key( $uid, true ) );
		}
	}

	/**
	 * Add a member to an audience
	 *
	 * @param int $audience_id Audience ID.
	 * @param int $user_id User ID.
	 * @return int|false Member ID or false on failure
	 */
	public static function add_member( int $audience_id, int $user_id ) {
		$wpdb  = self::db();
		$table = self::get_members_table_name();

		// Check if already a member.
		if ( AudienceReader::is_member( $audience_id, $user_id ) ) {
			return false;
		}

		$result = $wpdb->insert(
			$table,
			array(
				'audience_id' => $audience_id,
				'user_id'     => $user_id,
			),
			array( '%d', '%d' )
		);

		if ( ! $result ) {
			return false;
		}

		// Invalidating here rather than in the bulk wrappers is what covers
		// `bulk_add_members()` too — it is a loop over this method.
		self::invalidate_user_audiences( array( $user_id ) );

		return $wpdb->insert_id;
	}

	/**
	 * Remove a member from an audience
	 *
	 * @param int $audience_id Audience ID.
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function remove_member( int $audience_id, int $user_id ): bool {
		$wpdb  = self::db();
		$table = self::get_members_table_name();

		$result = $wpdb->delete(
			$table,
			array(
				'audience_id' => $audience_id,
				'user_id'     => $user_id,
			),
			array( '%d', '%d' )
		);

		if ( false === $result ) {
			return false;
		}

		// As in add_member(): this is what covers `bulk_remove_members()`.
		self::invalidate_user_audiences( array( $user_id ) );

		return true;
	}

	/**
	 * Bulk add members to an audience
	 *
	 * @param int        $audience_id Audience ID.
	 * @param array<int> $user_ids User IDs.
	 * @return int Number of members added
	 */
	public static function bulk_add_members( int $audience_id, array $user_ids ): int {
		$added = 0;
		foreach ( $user_ids as $user_id ) {
			if ( self::add_member( $audience_id, (int) $user_id ) ) {
				++$added;
			}
		}
		return $added;
	}

	/**
	 * Bulk remove members from an audience
	 *
	 * @param int        $audience_id Audience ID.
	 * @param array<int> $user_ids User IDs.
	 * @return int Number of members removed
	 */
	public static function bulk_remove_members( int $audience_id, array $user_ids ): int {
		$removed = 0;
		foreach ( $user_ids as $user_id ) {
			if ( self::remove_member( $audience_id, (int) $user_id ) ) {
				++$removed;
			}
		}
		return $removed;
	}

	/**
	 * Replace all members of an audience
	 *
	 * @param int        $audience_id Audience ID.
	 * @param array<int> $user_ids User IDs.
	 * @return bool
	 */
	public static function set_members( int $audience_id, array $user_ids ): bool {
		$wpdb  = self::db();
		$table = self::get_members_table_name();

		// Read the current members BEFORE the wipe. Whoever is dropped by it is
		// affected exactly as much as whoever is added, and the previous version
		// of this method invalidated only the incoming list — so a user removed
		// from an audience kept seeing it until the entry expired (#1127).
		$previous = AudienceReader::get_members( $audience_id );

		$wpdb->delete( $table, array( 'audience_id' => $audience_id ), array( '%d' ) );

		// Add new members. Each add_member() invalidates its own user; the
		// sweep below is what covers the ones that were dropped.
		foreach ( $user_ids as $user_id ) {
			self::add_member( $audience_id, (int) $user_id );
		}

		self::invalidate_user_audiences( array_merge( $previous, $user_ids ) );

		return true;
	}
}

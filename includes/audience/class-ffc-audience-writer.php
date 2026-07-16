<?php
/**
 * Audience Writer
 *
 * Write-side of the audience repository split (#563 backlog, A6). Holds every
 * INSERT / UPDATE / DELETE and the membership mutation helpers. Reads live in
 * {@see AudienceReader}. Callers depend on the reader (reads) and this writer
 * (writes) directly; the delegating façade was retired in #563 B3-A.
 *
 * @since   6.11.3
 * @package FreeFormCertificate\Audience
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
/**
 * Write operations for audience records.
 *
 * @since 6.11.3
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
		 * @since 4.13.0
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$table,
			$update_data,
			array( 'id' => $id ),
			$format,
			array( '%d' )
		);

		static::cache_delete( "id_{$id}" );
		\FreeFormCertificate\Core\CacheVersion::bump( self::CACHE_DOMAIN );

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
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"UPDATE %i SET allow_self_join = %d WHERE id IN ({$placeholders})",
			array_merge( array( $table, $value ), $child_ids )
		);
		if ( is_string( $update_sql ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $update_sql );
		}

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

		// Delete children first.
		$children = AudienceReader::get_children( $id );
		foreach ( $children as $child ) {
			self::delete( (int) $child->id );
		}

		// Delete member associations.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $members_table, array( 'audience_id' => $id ), array( '%d' ) );

		// Delete the audience.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		static::cache_delete( "id_{$id}" );
		\FreeFormCertificate\Core\CacheVersion::bump( self::CACHE_DOMAIN );

		return false !== $result;
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

		return $result ? $wpdb->insert_id : false;
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$table,
			array(
				'audience_id' => $audience_id,
				'user_id'     => $user_id,
			),
			array( '%d', '%d' )
		);

		return false !== $result;
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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Intentional delete-and-reinsert for member sync.
		$wpdb->delete( $table, array( 'audience_id' => $audience_id ), array( '%d' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// Add new members.
		foreach ( $user_ids as $user_id ) {
			self::add_member( $audience_id, (int) $user_id );
		}

		// Invalidate audience membership caches for affected users.
		foreach ( $user_ids as $uid ) {
			wp_cache_delete( 'ffcertificate_user_aud_' . (int) $uid . '_0', 'ffcertificate' );
			wp_cache_delete( 'ffcertificate_user_aud_' . (int) $uid . '_1', 'ffcertificate' );
		}

		return true;
	}
}

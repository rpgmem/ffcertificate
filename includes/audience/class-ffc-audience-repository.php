<?php
/**
 * Audience Repository
 *
 * Handles database operations for audience groups (públicos-alvo).
 * Supports 3-level hierarchy (parent / child / grandchild).
 *
 * Since the #563 backlog read/write split (A6) this class is a thin façade:
 * reads live in {@see AudienceReader}, writes in {@see AudienceWriter}. It is
 * kept as the public entry point so the existing call sites and the
 * `@phpstan-type AudienceRow` shape below need no change.
 *
 * Tech-debt (#563 B3): migrate call sites to depend on AudienceReader /
 * AudienceWriter directly, then retire this delegating façade.
 *
 * @package FreeFormCertificate\Audience
 * @since 4.5.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public façade over {@see AudienceReader} + {@see AudienceWriter}.
 *
 * @since 4.5.0
 *
 * @phpstan-type AudienceRow \stdClass&object{id: numeric-string, name: string, color: string, parent_id: numeric-string|null, status: string, created_by: numeric-string, created_at: string, updated_at: string, allow_self_join?: numeric-string, children?: list<\stdClass>, depth?: int<0, 2>}
 */
class AudienceRepository {

	/**
	 * Get audiences table name
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		return AudienceReader::get_table_name();
	}

	/**
	 * Get members table name
	 *
	 * @return string
	 */
	public static function get_members_table_name(): string {
		return AudienceReader::get_members_table_name();
	}

	// ─────────────────────────────────────────────.
	// Reads — delegate to AudienceReader.
	// ─────────────────────────────────────────────.

	/**
	 * List every audience id regardless of status.
	 *
	 * @since 6.6.2
	 * @return list<int>
	 */
	public static function get_all_ids(): array {
		return AudienceReader::get_all_ids();
	}

	/**
	 * Audiences (active only) the user is a member of, narrow column set.
	 *
	 * @since 6.6.2
	 * @param int $user_id WP user id.
	 * @return list<array{name: string, color: string}>
	 */
	public static function get_user_audience_badges( int $user_id ): array {
		return AudienceReader::get_user_audience_badges( $user_id );
	}

	/**
	 * Get all audiences
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return list<AudienceRow>
	 */
	public static function get_all( array $args = array() ): array {
		return AudienceReader::get_all( $args );
	}

	/**
	 * Get audience by ID
	 *
	 * @param int $id Audience ID.
	 * @return AudienceRow|null
	 */
	public static function get_by_id( int $id ): ?object {
		return AudienceReader::get_by_id( $id );
	}

	/**
	 * Get parent audiences (top-level groups)
	 *
	 * @param string|null $status Optional status filter.
	 * @return list<AudienceRow>
	 */
	public static function get_parents( ?string $status = null ): array {
		return AudienceReader::get_parents( $status );
	}

	/**
	 * Get children of a parent audience
	 *
	 * @param int         $parent_id Parent audience ID.
	 * @param string|null $status Optional status filter.
	 * @return list<AudienceRow>
	 */
	public static function get_children( int $parent_id, ?string $status = null ): array {
		return AudienceReader::get_children( $parent_id, $status );
	}

	/**
	 * Get audiences with their children (hierarchical)
	 *
	 * @param string|null $status Optional status filter.
	 * @return list<AudienceRow> Parents with nested 'children' property
	 */
	public static function get_hierarchical( ?string $status = null ): array {
		return AudienceReader::get_hierarchical( $status );
	}

	/**
	 * Check if a user is a member of an audience
	 *
	 * @param int $audience_id Audience ID.
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_member( int $audience_id, int $user_id ): bool {
		return AudienceReader::is_member( $audience_id, $user_id );
	}

	/**
	 * Get members of an audience
	 *
	 * @param int  $audience_id Audience ID.
	 * @param bool $include_children Whether to include members of child audiences.
	 * @return array<int> User IDs
	 */
	public static function get_members( int $audience_id, bool $include_children = false ): array {
		return AudienceReader::get_members( $audience_id, $include_children );
	}

	/**
	 * Get audiences a user belongs to
	 *
	 * @param int  $user_id User ID.
	 * @param bool $include_parents Whether to include parent audiences (when user is in child).
	 * @return list<AudienceRow>
	 */
	public static function get_user_audiences( int $user_id, bool $include_parents = false ): array {
		return AudienceReader::get_user_audiences( $user_id, $include_parents );
	}

	/**
	 * Get member count for an audience
	 *
	 * @param int  $audience_id Audience ID.
	 * @param bool $include_children Whether to include members of child audiences.
	 * @return int
	 */
	public static function get_member_count( int $audience_id, bool $include_children = false ): int {
		return AudienceReader::get_member_count( $audience_id, $include_children );
	}

	/**
	 * Count audiences
	 *
	 * @param array<string, mixed> $args Query arguments (parent_id, status).
	 * @return int
	 */
	public static function count( array $args = array() ): int {
		return AudienceReader::count( $args );
	}

	/**
	 * Search audiences by name
	 *
	 * @param string $search Search term.
	 * @param int    $limit Max results.
	 * @return list<AudienceRow>
	 */
	public static function search( string $search, int $limit = 10 ): array {
		return AudienceReader::search( $search, $limit );
	}

	/**
	 * Get all descendant IDs of an audience (children, grandchildren, etc.)
	 *
	 * @param int $audience_id Audience ID.
	 * @return array<int>
	 */
	public static function get_descendant_ids( int $audience_id ): array {
		return AudienceReader::get_descendant_ids( $audience_id );
	}

	/**
	 * Get ancestor IDs walking up from a given audience ID.
	 *
	 * @param int $audience_id Starting audience ID.
	 * @return array<int>
	 */
	public static function get_ancestor_ids( int $audience_id ): array {
		return AudienceReader::get_ancestor_ids( $audience_id );
	}

	/**
	 * Get audiences that may serve as parents (3-level hierarchy).
	 *
	 * @param int $exclude_id Audience ID to exclude (along with descendants).
	 * @return list<AudienceRow> Flat list with a 'depth' property on each item
	 */
	public static function get_possible_parents( int $exclude_id = 0 ): array {
		return AudienceReader::get_possible_parents( $exclude_id );
	}

	/**
	 * Get the full ancestor chain for an audience (for breadcrumb display).
	 *
	 * @param int $audience_id Audience ID.
	 * @return list<AudienceRow>
	 */
	public static function get_ancestors( int $audience_id ): array {
		return AudienceReader::get_ancestors( $audience_id );
	}

	// ─────────────────────────────────────────────.
	// Writes — delegate to AudienceWriter.
	// ─────────────────────────────────────────────.

	/**
	 * Create an audience
	 *
	 * @param array<string, mixed> $data Audience data.
	 * @return int|false Audience ID or false on failure
	 */
	public static function create( array $data ) {
		return AudienceWriter::create( $data );
	}

	/**
	 * Update an audience
	 *
	 * @param int                  $id Audience ID.
	 * @param array<string, mixed> $data Update data.
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		return AudienceWriter::update( $id, $data );
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
		AudienceWriter::cascade_self_join( $parent_id, $value );
	}

	/**
	 * Delete an audience
	 *
	 * @param int $id Audience ID.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		return AudienceWriter::delete( $id );
	}

	/**
	 * Add a member to an audience
	 *
	 * @param int $audience_id Audience ID.
	 * @param int $user_id User ID.
	 * @return int|false Member ID or false on failure
	 */
	public static function add_member( int $audience_id, int $user_id ) {
		return AudienceWriter::add_member( $audience_id, $user_id );
	}

	/**
	 * Remove a member from an audience
	 *
	 * @param int $audience_id Audience ID.
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function remove_member( int $audience_id, int $user_id ): bool {
		return AudienceWriter::remove_member( $audience_id, $user_id );
	}

	/**
	 * Bulk add members to an audience
	 *
	 * @param int        $audience_id Audience ID.
	 * @param array<int> $user_ids User IDs.
	 * @return int Number of members added
	 */
	public static function bulk_add_members( int $audience_id, array $user_ids ): int {
		return AudienceWriter::bulk_add_members( $audience_id, $user_ids );
	}

	/**
	 * Bulk remove members from an audience
	 *
	 * @param int        $audience_id Audience ID.
	 * @param array<int> $user_ids User IDs.
	 * @return int Number of members removed
	 */
	public static function bulk_remove_members( int $audience_id, array $user_ids ): int {
		return AudienceWriter::bulk_remove_members( $audience_id, $user_ids );
	}

	/**
	 * Replace all members of an audience
	 *
	 * @param int        $audience_id Audience ID.
	 * @param array<int> $user_ids User IDs.
	 * @return bool
	 */
	public static function set_members( int $audience_id, array $user_ids ): bool {
		return AudienceWriter::set_members( $audience_id, $user_ids );
	}
}

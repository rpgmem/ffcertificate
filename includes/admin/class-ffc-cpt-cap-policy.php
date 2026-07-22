<?php
/**
 * CptCapPolicy
 *
 * Per-post write gate for the two FFC custom post types (`ffc_form`,
 * `ffc_self_scheduling`) that completes the read-only *viewer* tier (#739
 * §3.2). The CPTs map their list/read primitive caps (`edit_posts`,
 * `edit_others_posts`, `read_private_posts`) to the domain **view** cap so a
 * holder of `ffc_view_forms` / `ffc_view_calendars` sees the list read-only —
 * but WordPress resolves the per-post `edit_post` / `delete_post` *meta* caps
 * to `edit_others_posts` for another author's post, which would then also
 * resolve to the view cap and let a viewer edit others' (draft) posts.
 *
 * This filter closes that gap: for these two post types it forces the
 * write meta-caps back to the domain **manage** cap, so viewing never implies
 * editing. It is the authoritative per-post write gate; the primitive remap in
 * the CPT registration only governs list visibility.
 *
 * Distinct from {@see CptEditorCompat}, which is a temporary deprecation shim
 * (removed in 6.18.0) — this gate is permanent.
 *
 * @package FreeFormCertificate\Admin
 * @since   6.16.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Forces per-post write meta-caps for the FFC CPTs to the domain manage cap.
 */
final class CptCapPolicy {

	/**
	 * Post-type → manage-cap map for the FFC custom post types.
	 *
	 * @var array<string, string>
	 */
	private const MANAGE_CAP = array(
		'ffc_form'            => 'ffc_manage_forms',
		'ffc_self_scheduling' => 'ffc_manage_calendars',
	);

	/**
	 * The per-post write meta-caps this policy re-gates.
	 *
	 * @var list<string>
	 */
	private const WRITE_META_CAPS = array( 'edit_post', 'delete_post', 'publish_post' );

	/**
	 * Register the map_meta_cap filter.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'map_meta_cap', array( self::class, 'gate_cpt_writes' ), 10, 4 );
	}

	/**
	 * Re-gate a per-post write meta-cap for the FFC CPTs to the manage cap.
	 *
	 * Only touches the write meta-caps for `ffc_form` / `ffc_self_scheduling`;
	 * every other capability check passes through unchanged.
	 *
	 * @param array<int, string> $caps    The resolved primitive caps required.
	 * @param string             $cap     The meta cap being checked.
	 * @param int                $user_id The user the check is for.
	 * @param array<int, mixed>  $args    `$args[0]` is the post ID for meta caps.
	 * @return array<int, string>
	 */
	public static function gate_cpt_writes( array $caps, string $cap, int $user_id, array $args ): array {
		if ( ! in_array( $cap, self::WRITE_META_CAPS, true ) ) {
			return $caps;
		}
		$post_id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( $post_id <= 0 ) {
			return $caps;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return $caps;
		}
		if ( isset( self::MANAGE_CAP[ $post->post_type ] ) ) {
			return array( self::MANAGE_CAP[ $post->post_type ] );
		}
		return $caps;
	}
}

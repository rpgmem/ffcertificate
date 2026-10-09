<?php
/**
 * AudienceTransferList
 *
 * The two-column audience picker ("Available" / "Selected") an admin form
 * uses to choose several audiences (#1648).
 *
 * @package FreeFormCertificate\Audience
 * @since   6.35.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the picker and enqueues its assets.
 *
 * It lived in the reregistration module until Date Messages needed the same
 * choice; it belongs here, with the audiences it lists, so neither consumer
 * reaches into the other. The picker posts one hidden input per selected id
 * under the field name its caller gives, and announces every change as
 * `ffc:transfer-list-change` on its wrapper, so a screen can react (the
 * reregistration campaign counts the members) without the picker knowing.
 */
final class AudienceTransferList {

	/**
	 * Script and stylesheet handle.
	 */
	public const HANDLE = 'ffc-audience-transfer-list';

	/**
	 * Enqueue the picker's script and stylesheet.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		$suffix = \FreeFormCertificate\Core\AssetHelper::asset_suffix();

		wp_enqueue_style(
			self::HANDLE,
			FFC_PLUGIN_URL . "assets/css/ffc-audience-transfer-list{$suffix}.css",
			array( 'ffc-common' ),
			FFC_VERSION
		);
		wp_enqueue_script(
			self::HANDLE,
			FFC_PLUGIN_URL . "assets/js/ffc-audience-transfer-list{$suffix}.js",
			array( 'jquery' ),
			FFC_VERSION,
			true
		);
	}

	/**
	 * Print the picker over the active audiences.
	 *
	 * @param array<int, int|string> $selected_ids Audience ids already chosen.
	 * @param string                 $field_name   Name of each posted hidden input, e.g. `rule[audience_ids][]`.
	 * @param bool                   $required     Whether the form refuses to submit with nothing chosen.
	 * @return void
	 */
	public static function render( array $selected_ids, string $field_name, bool $required ): void {
		$flat          = self::flatten( AudienceReader::get_hierarchical( 'active' ) );
		$selected_ids  = array_values( array_unique( array_filter( array_map( 'intval', $selected_ids ), static fn( int $id ): bool => $id > 0 ) ) );
		$flat_json     = wp_json_encode( $flat );
		$selected_json = wp_json_encode( $selected_ids );

		include FFC_PLUGIN_DIR . 'templates/admin/audience/transfer-list.php';
	}

	/**
	 * The audience tree as a flat list in display order, each node naming its
	 * parent, its depth and its direct children.
	 *
	 * Recursive, so a grandchild is listed under its parent: the picker this
	 * replaced read two levels and dropped the third the tree can hold.
	 *
	 * @param array<mixed> $nodes     Audience nodes with `children`.
	 * @param int          $parent_id Parent id, 0 at the root.
	 * @param int          $depth     Nesting depth, 0 at the root.
	 * @return list<array{id: int, name: string, color: string, parent: int, depth: int, children: list<int>}>
	 */
	public static function flatten( array $nodes, int $parent_id = 0, int $depth = 0 ): array {
		$flat = array();
		foreach ( $nodes as $node ) {
			if ( ! is_object( $node ) || ! isset( $node->id ) ) {
				continue;
			}
			$children = isset( $node->children ) && is_array( $node->children ) ? $node->children : array();
			$flat[]   = array(
				'id'       => (int) $node->id,
				'name'     => (string) ( $node->name ?? '' ),
				'color'    => (string) ( $node->color ?? '#ccc' ),
				'parent'   => $parent_id,
				'depth'    => $depth,
				'children' => array_values(
					array_map(
						static fn( $child ): int => (int) $child->id,
						array_filter( $children, static fn( $child ): bool => is_object( $child ) && isset( $child->id ) )
					)
				),
			);
			foreach ( self::flatten( $children, (int) $node->id, $depth + 1 ) as $descendant ) {
				$flat[] = $descendant;
			}
		}
		return $flat;
	}
}

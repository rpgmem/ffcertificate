<?php
/**
 * ManagedPages
 *
 * The pages the plugin creates on activation: the certificate verification
 * page and the member dashboard.
 *
 * @package FreeFormCertificate\Core
 * @since 6.44.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates a page that hosts one shortcode, and remembers what it created.
 *
 * Both activators created their page the same way, with an English title
 * and post author 1, which may not be an account on the site, and said
 * nothing about it (#2343). The title is translated, the author is the
 * account that activated the plugin, and each page created is recorded so
 * `CreatedPagesNotice` can tell the administrator where it is.
 */
final class ManagedPages {

	/** Option holding the ids of the pages activation created (list of ints). */
	public const OPTION = 'ffc_created_pages';

	/** Post meta that marks a page the plugin created; uninstall reads it. */
	public const META = '_ffc_managed_page';

	/**
	 * Create the page at `$slug` unless one exists, and store its id.
	 *
	 * An existing page at the slug is adopted as it is: its id is stored and
	 * nothing about it changes.
	 *
	 * @param string $slug      Page slug, e.g. `valid`.
	 * @param string $title     Translated page title.
	 * @param string $shortcode The page body, e.g. `[ffc_verification]`.
	 * @param string $option    Option the page id is stored under.
	 * @return int The page id, or 0 when none could be created.
	 */
	public static function ensure( string $slug, string $title, string $shortcode, string $option ): int {
		$existing = get_page_by_path( $slug );
		if ( $existing instanceof \WP_Post ) {
			update_option( $option, $existing->ID );
			return (int) $existing->ID;
		}

		$page = array(
			'post_title'     => $title,
			'post_content'   => $shortcode,
			'post_status'    => 'publish',
			'post_type'      => 'page',
			'post_name'      => $slug,
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		);
		// The account that activated the plugin. Without one (WP-CLI), the
		// author is left to WordPress rather than guessed.
		$author = get_current_user_id();
		if ( $author > 0 ) {
			$page['post_author'] = $author;
		}

		$page_id = wp_insert_post( $page, true );
		if ( is_wp_error( $page_id ) ) {
			return 0;
		}

		update_option( $option, $page_id );
		update_post_meta( $page_id, self::META, '1' );
		self::record( $page_id );

		return $page_id;
	}

	/**
	 * The pages activation created that still exist, oldest first.
	 *
	 * @return list<int>
	 */
	public static function created(): array {
		$ids = get_option( self::OPTION, array() );
		if ( ! is_array( $ids ) ) {
			return array();
		}

		$live = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 && null !== get_post( $id ) ) {
				$live[] = $id;
			}
		}
		return $live;
	}

	/**
	 * Remember a page activation created.
	 *
	 * @param int $page_id Page id.
	 * @return void
	 */
	private static function record( int $page_id ): void {
		$ids = get_option( self::OPTION, array() );
		$ids = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
		if ( ! in_array( $page_id, $ids, true ) ) {
			$ids[] = $page_id;
			update_option( self::OPTION, $ids, false );
		}
	}
}

<?php
/**
 * ShortcodePage
 *
 * Finds the published page that embeds a shortcode for one record.
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
 * The lookup the form hand-off and the reschedule link share (#2244).
 *
 * A form or a calendar is a shortcode, not a page, so the only way to know
 * where a visitor reaches it is to search post content. The search narrows
 * to pages holding the tag at all, newest first, and the `id` attribute is
 * matched here in every spelling WordPress parses.
 */
final class ShortcodePage {

	/**
	 * Permalink of the newest published page or post that embeds
	 * `[$tag id=$id]`, or '' when none does.
	 *
	 * @param string $tag Shortcode tag, e.g. `ffc_form`.
	 * @param int    $id  Record id the shortcode names.
	 * @return string
	 */
	public static function url( string $tag, int $id ): string {
		if ( $id <= 0 ) {
			return '';
		}

		$pages = get_posts(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => 'publish',
				's'              => '[' . $tag,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'posts_per_page' => 50,
				'fields'         => 'ids',
			)
		);

		foreach ( $pages as $page_id ) {
			if ( ! self::embeds( (string) get_post_field( 'post_content', (int) $page_id ), $tag, $id ) ) {
				continue;
			}
			$url = get_permalink( (int) $page_id );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}

		return '';
	}

	/**
	 * Whether content carries `[$tag]` with this `id`, in double quotes,
	 * single quotes or none. A longer number does not match: `id="12"` is
	 * not record 1.
	 *
	 * @param string $content Post content.
	 * @param string $tag     Shortcode tag.
	 * @param int    $id      Record id.
	 * @return bool
	 */
	public static function embeds( string $content, string $tag, int $id ): bool {
		return 1 === preg_match( '/\[' . preg_quote( $tag, '/' ) . '\b[^\]]*?\bid\s*=\s*(["\']?)' . $id . '\1(?=[\s\]\/])/i', $content );
	}
}

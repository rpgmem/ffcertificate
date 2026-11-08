<?php
/**
 * ShortcodePage
 *
 * Finds the published page that embeds a shortcode for one record.
 *
 * @package FreeFormCertificate\Core
 * @since 6.43.0
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
 *
 * The answer is kept (#2341): the CSV screen asks on every info read, and
 * the search is a `LIKE` over post content. Saving a page records what it
 * embeds, so the kept answer is primed and follows the page; a page that
 * stops embedding a record, or leaves `publish`, drops its entries. A miss
 * searches every matching page, in batches, not only the newest fifty.
 */
final class ShortcodePage {

	/**
	 * The tags whose page is looked up.
	 */
	public const TAGS = array( 'ffc_form', 'ffc_self_scheduling' );

	/**
	 * Transient holding `"tag:id" => page id` (0 when no page embeds it).
	 */
	public const CACHE_KEY = 'ffc_shortcode_pages';

	/**
	 * Pages read per search batch.
	 */
	private const BATCH = 50;

	/**
	 * Batches a miss reads at most (so 1,000 matching pages).
	 */
	private const MAX_BATCHES = 20;

	/**
	 * Keep the kept answers in step with the pages (#2341).
	 *
	 * `save_post` at the default priority, after core has stored the
	 * content; `deleted_post` and `trashed_post` drop what the page held.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'save_post', array( self::class, 'on_save_post' ), 10, 2 );
		add_action( 'trashed_post', array( self::class, 'forget_post' ) );
		add_action( 'deleted_post', array( self::class, 'forget_post' ) );
	}

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

		$map = self::map();
		$key = $tag . ':' . $id;
		if ( array_key_exists( $key, $map ) ) {
			$page_id = (int) $map[ $key ];
			if ( 0 === $page_id ) {
				return '';
			}
			$url = self::url_if_embeds( $page_id, $tag, $id );
			if ( '' !== $url ) {
				return $url;
			}
		}

		$page_id     = self::search( $tag, $id );
		$map[ $key ] = $page_id;
		self::store( $map );
		if ( $page_id <= 0 ) {
			return '';
		}
		// The search read published pages and matched the content.
		$url = get_permalink( $page_id );
		return is_string( $url ) ? $url : '';
	}

	/**
	 * Record what a saved page embeds, and drop what it no longer does.
	 *
	 * @param int   $post_id Post id.
	 * @param mixed $post    The saved post; a hook callback checks it.
	 * @return void
	 */
	public static function on_save_post( $post_id, $post ): void {
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, array( 'page', 'post' ), true ) ) {
			return;
		}
		if ( wp_is_post_revision( (int) $post_id ) || wp_is_post_autosave( (int) $post_id ) ) {
			return;
		}
		$map = self::without_post( self::map(), (int) $post_id );
		if ( 'publish' === $post->post_status ) {
			foreach ( self::TAGS as $tag ) {
				foreach ( self::ids_in( (string) $post->post_content, $tag ) as $id ) {
					// The newest page wins, as the search orders them.
					$map[ $tag . ':' . $id ] = (int) $post_id;
				}
			}
		}
		self::store( $map );
	}

	/**
	 * Drop the answers that pointed at a page leaving the site.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public static function forget_post( $post_id ): void {
		$map  = self::map();
		$kept = self::without_post( $map, (int) $post_id );
		if ( count( $kept ) !== count( $map ) ) {
			self::store( $kept );
		}
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

	/**
	 * The record ids content embeds for a tag.
	 *
	 * @param string $content Post content.
	 * @param string $tag     Shortcode tag.
	 * @return array<int, int>
	 */
	public static function ids_in( string $content, string $tag ): array {
		if ( ! preg_match_all( '/\[' . preg_quote( $tag, '/' ) . '\b[^\]]*?\bid\s*=\s*(["\']?)(\d+)\1(?=[\s\]\/])/i', $content, $m ) ) {
			return array();
		}
		return array_values( array_unique( array_map( 'intval', $m[2] ) ) );
	}

	/**
	 * The newest published page that embeds the record, searching every
	 * page that holds the tag, or 0.
	 *
	 * @param string $tag Shortcode tag.
	 * @param int    $id  Record id.
	 * @return int
	 */
	private static function search( string $tag, int $id ): int {
		for ( $batch = 1; $batch <= self::MAX_BATCHES; $batch++ ) {
			$pages = get_posts(
				array(
					'post_type'      => array( 'page', 'post' ),
					'post_status'    => 'publish',
					's'              => '[' . $tag,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'posts_per_page' => self::BATCH,
					'paged'          => $batch,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			foreach ( $pages as $page_id ) {
				if ( self::embeds( (string) get_post_field( 'post_content', (int) $page_id ), $tag, $id ) ) {
					return (int) $page_id;
				}
			}
			if ( count( $pages ) < self::BATCH ) {
				break;
			}
		}
		return 0;
	}

	/**
	 * The page's permalink when it is still published and still embeds
	 * the record, or ''.
	 *
	 * @param int    $page_id Page id.
	 * @param string $tag     Shortcode tag.
	 * @param int    $id      Record id.
	 * @return string
	 */
	private static function url_if_embeds( int $page_id, string $tag, int $id ): string {
		if ( 'publish' !== get_post_status( $page_id ) || ! self::embeds( (string) get_post_field( 'post_content', $page_id ), $tag, $id ) ) {
			return '';
		}
		$url = get_permalink( $page_id );
		return is_string( $url ) ? $url : '';
	}

	/**
	 * The kept answers.
	 *
	 * @return array<string, int>
	 */
	private static function map(): array {
		$map = get_transient( self::CACHE_KEY );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Keep the answers for a day; a page save refreshes them sooner.
	 *
	 * @param array<string, int> $map Answers.
	 * @return void
	 */
	private static function store( array $map ): void {
		set_transient( self::CACHE_KEY, $map, DAY_IN_SECONDS );
	}

	/**
	 * The answers without the ones naming a page, misses included: a
	 * page that changed may now hold a record that read as missing.
	 *
	 * @param array<string, int> $map     Answers.
	 * @param int                $post_id Page id.
	 * @return array<string, int>
	 */
	private static function without_post( array $map, int $post_id ): array {
		return array_filter(
			$map,
			static function ( $page_id ) use ( $post_id ) {
				return (int) $page_id !== $post_id && 0 !== (int) $page_id;
			}
		);
	}
}

<?php
/**
 * ObsoleteShortcodeCleaner
 *
 * Sweeps the WordPress content tables for `[ffc_form id="..."]` shortcodes
 * that point to forms which ended more than `N` days ago, and removes those
 * shortcodes from `post_content`.
 *
 * Two container formats are handled:
 *   1. Classic editor: bare `[ffc_form id="42"]`
 *   2. Gutenberg block wrapper:
 *      `<!-- wp:shortcode -->[ffc_form id="42"]<!-- /wp:shortcode -->`
 *
 * The cleaner only rewrites `post_content` and lets `wp_update_post()`
 * create a standard WordPress revision so administrators can roll back
 * manually if needed.
 *
 * Intentionally NOT registered in `MigrationRegistry` — this is a one-shot
 * admin action, not a batched row migration with per-record progress.
 *
 * @package FreeFormCertificate\Migrations
 * @since 5.1.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Migrations;

use FreeFormCertificate\Security\Geofence;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ObsoleteShortcodeCleaner {

	/**
	 * Post types scanned for obsolete shortcodes.
	 *
	 * Decision locked with the user: `post`, `page` and `wp_block`. Reusable
	 * blocks are included because a single block can be embedded in many
	 * posts — cleaning the block cleans every embedding transitively.
	 *
	 * @var array<int, string>
	 */
	const SCANNED_POST_TYPES = array( 'post', 'page', 'wp_block' );

	/**
	 * Post statuses scanned. Decision locked with the user: only `publish`.
	 *
	 * @var array<int, string>
	 */
	const SCANNED_POST_STATUSES = array( 'publish' );

	/**
	 * Max number of affected posts included in the UI report.
	 * If more exist, a "… and N more" indicator is shown.
	 */
	const REPORT_LIMIT = 50;

	/**
	 * Regex matching `[ffc_form ...]` shortcodes in their classic form.
	 *
	 * Captures the numeric `id` attribute in group 1.
	 *
	 * @var string
	 */
	const REGEX_CLASSIC = '/\[ffc_form\b[^\]]*\bid\s*=\s*["\']?(\d+)["\']?[^\]]*\]/';

	/**
	 * Regex matching a Gutenberg `wp:shortcode` block wrapping an `ffc_form`
	 * shortcode. Captures the numeric `id` attribute in group 1. Uses the
	 * `s` modifier so `.` matches newlines between the opening and closing
	 * HTML comments.
	 *
	 * @var string
	 */
	const REGEX_GUTENBERG = '/<!--\s*wp:shortcode\s*-->\s*\[ffc_form\b[^\]]*\bid\s*=\s*["\']?(\d+)["\']?[^\]]*\]\s*<!--\s*\/wp:shortcode\s*-->/s';

	/**
	 * Find `ffc_form` form IDs that ended more than `$days` ago.
	 *
	 * Queries every published `ffc_form` post and filters through
	 * `Geofence::has_form_expired_by_days()` — the geofence helper is the
	 * single source of truth for "is this form over".
	 *
	 * @param int $days Grace window in days (>= 0).
	 * @return array<int, int> Form post IDs.
	 */
	public function find_expired_form_ids( int $days ): array {
		if ( $days < 0 ) {
			$days = 0;
		}

		$query = new WP_Query(
			array(
				'post_type'              => 'ffc_form',
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$expired = array();
		/** @var array<int, int> $post_ids */
		$post_ids = $query->posts;
		foreach ( $post_ids as $form_id ) {
			$form_id = (int) $form_id;
			if ( Geofence::has_form_expired_by_days( $form_id, $days ) ) {
				$expired[] = $form_id;
			}
		}

		return $expired;
	}

	/**
	 * Scan configured post types for posts whose `post_content` embeds any
	 * of the given expired form IDs.
	 *
	 * Does NOT write to the database — safe to call in preview/dry-run mode.
	 *
	 * @param array<int, int> $expired_ids List of form IDs to look for.
	 * @return array{
	 *     posts_scanned: int,
	 *     affected: array<int, array{post_id:int, post_type:string, post_title:string, matched_form_ids:array<int,int>, matches_count:int}>
	 * }
	 */
	public function scan_posts_for_expired_forms( array $expired_ids ): array {
		if ( empty( $expired_ids ) ) {
			return array(
				'posts_scanned' => 0,
				'affected'      => array(),
			);
		}

		$expired_lookup = array_flip( array_map( 'intval', $expired_ids ) );

		$query = new WP_Query(
			array(
				'post_type'              => self::SCANNED_POST_TYPES,
				'post_status'            => self::SCANNED_POST_STATUSES,
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// Cheap MySQL prefilter to skip posts that can't possibly match.
				's'                      => '[ffc_form',
			)
		);

		/** @var array<int, int> $post_ids */
		$post_ids      = $query->posts;
		$affected      = array();
		$posts_scanned = count( $post_ids );

		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			$post    = get_post( $post_id );
			if ( ! $post || ! is_object( $post ) ) {
				continue;
			}

			$content = (string) $post->post_content;
			if ( '' === $content ) {
				continue;
			}

			$matched_ids = $this->extract_form_ids( $content );
			if ( empty( $matched_ids ) ) {
				continue;
			}

			$obsolete_ids = array();
			foreach ( $matched_ids as $matched_id ) {
				if ( isset( $expired_lookup[ $matched_id ] ) ) {
					$obsolete_ids[] = $matched_id;
				}
			}

			if ( empty( $obsolete_ids ) ) {
				continue;
			}

			$obsolete_ids = array_values( array_unique( $obsolete_ids ) );

			$affected[] = array(
				'post_id'          => $post_id,
				'post_type'        => (string) $post->post_type,
				'post_title'       => (string) $post->post_title,
				'matched_form_ids' => $obsolete_ids,
				'matches_count'    => count( $obsolete_ids ),
			);
		}

		return array(
			'posts_scanned' => $posts_scanned,
			'affected'      => $affected,
		);
	}

	/**
	 * Extract every `[ffc_form id="N"]` numeric ID from a chunk of content.
	 *
	 * Only the classic regex is used here because every Gutenberg-wrapped
	 * shortcode also contains the bare shortcode inside it.
	 *
	 * @param string $content Raw post_content.
	 * @return array<int, int> Zero-indexed list of form IDs (may contain duplicates).
	 */
	public function extract_form_ids( string $content ): array {
		if ( '' === $content ) {
			return array();
		}

		$ids = array();
		if ( preg_match_all( self::REGEX_CLASSIC, $content, $matches ) ) {
			foreach ( $matches[1] as $id_str ) {
				$ids[] = (int) $id_str;
			}
		}
		return $ids;
	}

	/**
	 * Remove every shortcode pointing at one of `$form_ids_to_remove` from
	 * the given content string. Returns the rewritten content and the
	 * number of shortcodes removed.
	 *
	 * The Gutenberg block wrapper is stripped FIRST so the surrounding
	 * `<!-- wp:shortcode -->` / `<!-- /wp:shortcode -->` comments don't
	 * become orphans once the inner shortcode is gone.
	 *
	 * @param string         $content            Original post_content.
	 * @param array<int,int> $form_ids_to_remove Form IDs whose shortcodes must be removed.
	 * @return array{content: string, removed: int}
	 */
	public function strip_shortcodes_from_content( string $content, array $form_ids_to_remove ): array {
		if ( '' === $content || empty( $form_ids_to_remove ) ) {
			return array(
				'content' => $content,
				'removed' => 0,
			);
		}

		$remove_lookup = array_flip( array_map( 'intval', $form_ids_to_remove ) );
		$removed       = 0;

		// 1. Strip Gutenberg `wp:shortcode` blocks whose inner id is obsolete.
		$content = (string) preg_replace_callback(
			self::REGEX_GUTENBERG,
			static function ( array $match ) use ( $remove_lookup, &$removed ): string {
				$id = (int) $match[1];
				if ( isset( $remove_lookup[ $id ] ) ) {
					++$removed;
					return '';
				}
				return $match[0];
			},
			$content
		);

		// 2. Strip remaining bare classic shortcodes.
		$content = (string) preg_replace_callback(
			self::REGEX_CLASSIC,
			static function ( array $match ) use ( $remove_lookup, &$removed ): string {
				$id = (int) $match[1];
				if ( isset( $remove_lookup[ $id ] ) ) {
					++$removed;
					return '';
				}
				return $match[0];
			},
			$content
		);

		// 3. Collapse up to two consecutive blank lines created by the removal.
		$content = (string) preg_replace( "/(\r?\n[ \t]*){3,}/", "\n\n", $content );

		return array(
			'content' => $content,
			'removed' => $removed,
		);
	}

	/**
	 * Rewrite a single post's content removing every obsolete shortcode.
	 *
	 * Uses `wp_update_post()` which automatically creates a revision when
	 * the post type supports it (pages/posts do; wp_block does not but the
	 * rollback path for a reusable block is re-editing the block itself).
	 *
	 * @param int            $post_id            Target post.
	 * @param array<int,int> $form_ids_to_remove Obsolete form IDs.
	 * @return int Number of shortcodes removed (0 if nothing changed).
	 */
	public function remove_shortcodes_from_post( int $post_id, array $form_ids_to_remove ): int {
		if ( $post_id <= 0 || empty( $form_ids_to_remove ) ) {
			return 0;
		}

		$post = get_post( $post_id );
		if ( ! $post || ! is_object( $post ) ) {
			return 0;
		}

		$result = $this->strip_shortcodes_from_content( (string) $post->post_content, $form_ids_to_remove );
		if ( 0 === $result['removed'] ) {
			return 0;
		}

		$update = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $result['content'],
			),
			true
		);

		if ( is_wp_error( $update ) || 0 === $update ) {
			return 0;
		}

		return $result['removed'];
	}

	/**
	 * Full pipeline: discover expired forms, scan posts, optionally rewrite
	 * each affected post, return an aggregated report.
	 *
	 * @param int                  $days    Grace window in days.
	 * @param array{dry_run?:bool} $options Options. `dry_run=true` skips the
	 *                                      `wp_update_post()` step.
	 * @return array{
	 *     dry_run: bool,
	 *     days: int,
	 *     expired_forms: int,
	 *     posts_scanned: int,
	 *     posts_affected: int,
	 *     shortcodes_removed: int,
	 *     truncated: bool,
	 *     affected: array<int, array{post_id:int, post_type:string, post_title:string, removed_count:int}>
	 * }
	 */
	public function run( int $days, array $options = array() ): array {
		$dry_run = ! empty( $options['dry_run'] );
		if ( $days < 0 ) {
			$days = 0;
		}

		$expired_ids = $this->find_expired_form_ids( $days );
		$scan        = $this->scan_posts_for_expired_forms( $expired_ids );

		$affected_report    = array();
		$shortcodes_removed = 0;

		foreach ( $scan['affected'] as $entry ) {
			$removed_here = $entry['matches_count'];

			if ( ! $dry_run ) {
				$removed_here = $this->remove_shortcodes_from_post(
					$entry['post_id'],
					$entry['matched_form_ids']
				);
				if ( 0 === $removed_here ) {
					// Actual removal failed or already clean — skip.
					continue;
				}
			}

			$shortcodes_removed += $removed_here;
			$affected_report[]   = array(
				'post_id'       => $entry['post_id'],
				'post_type'     => $entry['post_type'],
				'post_title'    => $entry['post_title'],
				'removed_count' => $removed_here,
			);
		}

		$posts_affected = count( $affected_report );
		$truncated      = $posts_affected > self::REPORT_LIMIT;
		if ( $truncated ) {
			$affected_report = array_slice( $affected_report, 0, self::REPORT_LIMIT );
		}

		return array(
			'dry_run'            => $dry_run,
			'days'               => $days,
			'expired_forms'      => count( $expired_ids ),
			'posts_scanned'      => (int) $scan['posts_scanned'],
			'posts_affected'     => $posts_affected,
			'shortcodes_removed' => $shortcodes_removed,
			'truncated'          => $truncated,
			'affected'           => $affected_report,
		);
	}
}

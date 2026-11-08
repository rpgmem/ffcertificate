<?php
/**
 * URL Shortener Post Lifecycle
 *
 * A post's short URL follows the post (#2337): trashing the post disables
 * the link, publishing it again brings the link back, and deleting the post
 * deletes the link and retires its code.
 *
 * @package FreeFormCertificate\UrlShortener
 */

declare(strict_types=1);

namespace FreeFormCertificate\UrlShortener;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps a post's short URL in step with the post.
 */
class UrlShortenerPostLifecycle {

	/**
	 * Post meta set when trashing the post switched its link off, so only a
	 * link the plugin switched off is switched back on. A link an operator
	 * disabled by hand stays disabled.
	 */
	public const PAUSED_META = '_ffc_short_url_paused_by_trash';

	/**
	 * Service.
	 *
	 * @var UrlShortenerService
	 */
	private UrlShortenerService $service;

	/**
	 * Constructor.
	 *
	 * @param UrlShortenerService $service Service.
	 */
	public function __construct( UrlShortenerService $service ) {
		$this->service = $service;
	}

	/**
	 * Register the hooks, in every context: posts are trashed, published and
	 * deleted over REST and WP-CLI as well as in wp-admin.
	 *
	 * All at the default priority 10. `trashed_post` and `deleted_post` fire
	 * after the post's own change, so the link never moves ahead of a change
	 * that failed. `transition_post_status` is the one hook every way into
	 * `publish` passes through, a restore from the trash included
	 * (wp_untrash_post() saves through wp_update_post()).
	 */
	public function init(): void {
		add_action( 'trashed_post', array( $this, 'on_trashed' ) );
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( 'deleted_post', array( $this, 'on_deleted' ) );
	}

	/**
	 * The post went to the trash: switch its live link off.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_trashed( int $post_id ): void {
		$record = $this->service->get_repository()->findByPostId( $post_id );
		if ( ! $record || 'active' !== ( $record['status'] ?? '' ) ) {
			return;
		}

		if ( $this->service->set_active( (int) $record['id'], false ) ) {
			update_post_meta( $post_id, self::PAUSED_META, 1 );
		}
	}

	/**
	 * The post is published again: bring back the link its trashing paused.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 */
	public function on_transition( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'publish' !== $new_status || ! get_post_meta( $post->ID, self::PAUSED_META, true ) ) {
			return;
		}

		$record = $this->service->get_repository()->findByPostId( $post->ID );
		if ( $record && 'disabled' === ( $record['status'] ?? '' ) ) {
			$this->service->set_active( (int) $record['id'], true );
		}
		delete_post_meta( $post->ID, self::PAUSED_META );
	}

	/**
	 * The post was deleted: delete its link and retire the code.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_deleted( int $post_id ): void {
		$record = $this->service->get_repository()->findByPostId( $post_id );
		if ( $record ) {
			$this->service->delete_short_url( (int) $record['id'] );
		}
	}
}

<?php
/**
 * URL Shortener Backfill Handler
 *
 * AJAX endpoint that generates the missing short URLs for already-published
 * posts of the shortened ("Encurtar") post types — the ones that predate the
 * feature or were never opened in the editor. Runs as a keyset-paged batch
 * (like the CSV export) so it stays timeout-safe on shared hosting: the browser
 * loops `cursor → next_cursor` until `done`.
 *
 * Backfill only *creates* the short URL records; it does not expose anything.
 * To publish them as shortlinks the admin ticks "Expose" for the post type.
 *
 * @package FreeFormCertificate\UrlShortener
 * @since 6.18.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\UrlShortener;

use FreeFormCertificate\Core\AjaxTrait;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Batched backfill of missing short URLs.
 */
class UrlShortenerBackfillHandler {

	use AjaxTrait;

	/**
	 * Rows processed per AJAX request. Bounded so a single request stays well
	 * under the PHP time limit on shared hosts where `set_time_limit()` is off.
	 */
	private const BATCH_SIZE = 50;

	/**
	 * Nonce action for the backfill endpoint.
	 */
	public const NONCE_ACTION = 'ffc_url_shortener_backfill';

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
	 * Register the AJAX hook.
	 */
	public function init(): void {
		add_action( 'wp_ajax_ffc_url_shortener_backfill', array( $this, 'ajax_backfill' ) );
	}

	/**
	 * AJAX: create one batch of missing short URLs.
	 *
	 * Response data: `{ created, processed, next_cursor, done, total? }`.
	 * `total` (the full backlog count) is only sent on the first request.
	 */
	public function ajax_backfill(): void {
		$this->verify_ajax_nonce( self::NONCE_ACTION );
		$this->check_ajax_admin_or( 'ffc_manage_url_shortener' );

		// Post types come from the server-side "Encurtar" list — never trust a
		// client-supplied type list (would let a caller mint short URLs for
		// arbitrary post types).
		$post_types = $this->service->get_enabled_post_types();

		// First page: the client sends cursor 0 (PHP_INT_MAX is not JS-safe);
		// translate it to the real upper bound and flag the first request so we
		// return the total backlog once.
		$incoming_cursor = $this->get_post_int( 'cursor', 0 );
		$is_first        = $incoming_cursor <= 0;
		$cursor          = $is_first ? PHP_INT_MAX : $incoming_cursor;

		$repository = $this->service->get_repository();
		$candidates = $repository->findBackfillCandidates( $post_types, $cursor, self::BATCH_SIZE );

		$created     = 0;
		$next_cursor = 0;
		foreach ( $candidates as $row ) {
			$post_id     = (int) $row['ID'];
			$next_cursor = $post_id; // Rows are ID-desc, so the last is the smallest.

			$permalink = get_permalink( $post_id );
			if ( ! $permalink ) {
				continue;
			}

			$result = $this->service->create_short_url(
				$permalink,
				isset( $row['post_title'] ) ? (string) $row['post_title'] : '',
				$post_id
			);
			if ( ! empty( $result['success'] ) ) {
				++$created;
			}
		}

		$processed = count( $candidates );
		$done      = $processed < self::BATCH_SIZE;

		$response = array(
			'created'     => $created,
			'processed'   => $processed,
			'next_cursor' => $next_cursor,
			'done'        => $done,
		);
		if ( $is_first ) {
			$response['total'] = $repository->countBackfillCandidates( $post_types );
		}

		wp_send_json_success( $response );
	}
}

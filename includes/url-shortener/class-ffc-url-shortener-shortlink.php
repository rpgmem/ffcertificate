<?php
/**
 * URL Shortener Shortlink Exposer
 *
 * Publishes a page's short URL as the site's canonical WordPress shortlink by
 * short-circuiting the `pre_get_shortlink` filter. Because WordPress core
 * already runs `wp_shortlink_wp_head()` (on `wp_head`) and `wp_shortlink_header()`
 * (on `template_redirect`) — both calling `wp_get_shortlink()` — filtering
 * `pre_get_shortlink` alone emits the `<link rel="shortlink">` tag *and* the
 * `Link: …; rel=shortlink` HTTP header, and also feeds the admin "Get Shortlink"
 * button and REST. No manual echo.
 *
 * Exposure is opt-in per post type (`url_shortener_expose_post_types`) and only
 * kicks in when an active short URL already exists for the post; otherwise the
 * filter passes through and WordPress falls back to its native `?p=ID` shortlink.
 *
 * @package FreeFormCertificate\UrlShortener
 * @since 6.18.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\UrlShortener;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes short URLs as the WordPress shortlink.
 */
class UrlShortenerShortlink {

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
	 * Register the shortlink filter (both front-end and admin contexts —
	 * the `<head>`/header path is front-end, the "Get Shortlink" button is admin).
	 */
	public function init(): void {
		add_filter( 'pre_get_shortlink', array( $this, 'filter_shortlink' ), 10, 3 );
	}

	/**
	 * Return the page's short URL when its post type is exposed and an active
	 * short URL exists; otherwise return the incoming value unchanged so
	 * WordPress computes its native shortlink.
	 *
	 * @param false|string $shortlink The short-circuit value (false lets core proceed).
	 * @param int          $id        Post ID (0 when resolved from the current query).
	 * @param string       $context   'post' (explicit id) or 'query' (current request).
	 * @return false|string
	 */
	public function filter_shortlink( $shortlink, $id = 0, $context = 'post' ) {
		// Scalar hints omitted so a third-party wp_get_shortlink() call with an
		// odd argument cannot raise a TypeError under strict_types; normalise here.
		$post_id = (int) $id;
		$context = (string) $context;

		// `wp_shortlink_wp_head()` / `wp_shortlink_header()` call with id 0 and
		// context 'query' — resolve to the queried singular post in that case.
		if ( $post_id <= 0 ) {
			if ( 'query' !== $context || ! is_singular() ) {
				return $shortlink;
			}
			$post_id = (int) get_queried_object_id();
		}

		if ( $post_id <= 0 ) {
			return $shortlink;
		}

		$post_type = get_post_type( $post_id );
		if ( ! $post_type || ! in_array( $post_type, $this->service->get_exposed_post_types(), true ) ) {
			return $shortlink;
		}

		$record = $this->service->get_repository()->findByPostId( $post_id );
		if ( ! $record || 'active' !== ( $record['status'] ?? '' ) ) {
			return $shortlink;
		}

		return $this->service->get_short_url( (string) $record['short_code'] );
	}
}

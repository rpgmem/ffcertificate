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
 * button. No manual echo.
 *
 * REST is **not** covered by that filter: `WP_REST_Posts_Controller` never calls
 * `wp_get_shortlink()`, so the `ffc_shortlink` field is registered explicitly
 * here for the same opted-in post types.
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
	 * REST field name. Prefixed to avoid colliding with core or another plugin
	 * ever claiming the unqualified `shortlink` key on core post types.
	 *
	 * @since 6.21.0
	 */
	public const REST_FIELD = 'ffc_shortlink';

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
	 * Register both exposure surfaces: the shortlink filter (front-end `<head>`/
	 * HTTP header plus the admin "Get Shortlink" button) and the REST field,
	 * which the filter does not reach.
	 */
	public function init(): void {
		add_filter( 'pre_get_shortlink', array( $this, 'filter_shortlink' ), 10, 3 );
		add_action( 'rest_api_init', array( $this, 'register_rest_field' ) );
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

	/**
	 * Register the read-only `ffc_shortlink` field on the REST responses of the
	 * opted-in post types.
	 *
	 * Hook: `rest_api_init` / priority 10 (default). Fires after `init` — post
	 * types registered, `ffc_settings` readable — and before route dispatch.
	 * Registering on `init` instead would load REST-only cost into every
	 * front-end request for nothing.
	 *
	 * @since 6.21.0
	 */
	public function register_rest_field(): void {
		foreach ( $this->service->get_exposed_post_types() as $post_type ) {
			register_rest_field(
				$post_type,
				self::REST_FIELD,
				array(
					'get_callback' => array( $this, 'get_rest_field' ),
					'schema'       => array(
						'description' => __( 'Short URL for this content, or an empty string when no active short URL exists.', 'ffcertificate' ),
						'type'        => 'string',
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
					),
				)
			);
		}
	}

	/**
	 * Resolve the short URL for a REST response.
	 *
	 * Delegates to {@see filter_shortlink()} rather than calling
	 * `wp_get_shortlink()`: going through core would round-trip back into this
	 * same filter and, on passthrough, return core's `?p=ID` fallback — which is
	 * not a short URL and is useless to the consumers this field exists for.
	 * Here the contract is unambiguous: the FFC short URL, or an empty string.
	 *
	 * @since 6.21.0
	 *
	 * @param array<string, mixed> $post Post representation prepared by the REST API.
	 * @return string Short URL, or '' when none is active.
	 */
	public function get_rest_field( array $post ): string {
		$post_id = isset( $post['id'] ) ? (int) $post['id'] : 0;

		if ( $post_id <= 0 ) {
			return '';
		}

		$short_url = $this->filter_shortlink( false, $post_id, 'post' );

		return is_string( $short_url ) ? $short_url : '';
	}
}

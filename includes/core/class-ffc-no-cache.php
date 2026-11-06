<?php
/**
 * No Cache
 *
 * Keeps a response that holds one visitor's state out of every page cache
 * between WordPress and the browser (#2341): the dashboard, a booking
 * restricted by business hours, and a page that shows a schedule exception
 * or an invitation. One place, so the three cannot drift on which caches
 * they reach.
 *
 * @package FreeFormCertificate\Core
 * @since   6.44.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Marks the current response as not cacheable.
 */
final class NoCache {

	/**
	 * Mark the response as not cacheable.
	 *
	 * DONOTCACHEPAGE is read by WP Rocket, W3 Total Cache, WP Super Cache,
	 * Batcache and most full-page caches when the page is stored, so it works
	 * from a shortcode's render too. The headers need a response whose
	 * headers are not yet sent, so they are skipped after output has begun
	 * rather than raising a warning; a caller that needs them hooks
	 * `template_redirect`.
	 *
	 * @param string $reason Short description, passed to LiteSpeed for its log.
	 * @return void
	 */
	public static function send( string $reason ): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		// LiteSpeed Cache: programmatic exclusion; the hook belongs to that plugin.
		do_action( 'litespeed_control_set_nocache', 'FFC ' . $reason );

		if ( headers_sent() ) {
			return;
		}

		nocache_headers();

		// Cloudflare (#921) ignores DONOTCACHEPAGE and honours Cache-Control,
		// and its edge-specific directives survive Cache Rules that would
		// override the browser one.
		header( 'Cloudflare-CDN-Cache-Control: no-store' );
		header( 'CDN-Cache-Control: no-store' );

		// Generic header recognised by LiteSpeed and other reverse proxies.
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
	}
}

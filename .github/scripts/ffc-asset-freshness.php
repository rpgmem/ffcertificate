<?php
/**
 * Whether the host serves the asset files the deploy just wrote (#2915).
 *
 * WHAT THIS CATCHES THAT THE VERSION CHECK CANNOT
 *
 * #2912: a tablet with no account received the current terminal page together
 * with a `ffc-terminal-kiosk.js` several days old, under the page's new
 * `?ver=`. The plugin's live `FFC_VERSION` was right, every table was there,
 * and the smoke passed -- because a cache between the visitor and PHP kept the
 * old body and nothing on the server ever reads its own assets over HTTP.
 * Purging the CDN and the host's cache fixed it, which says one of those layers
 * keyed the file on its path alone, or kept it past the version change.
 *
 * So the smoke fetches a few assets the way a visitor does -- the URL the site
 * prints, its `?ver=` included, with no cookies -- and compares each body with
 * the file on disk. A second fetch goes to the server itself (127.0.0.1, with
 * the site's `Host` header), which is what turns "stale" into "stale WHERE":
 *
 *   public fresh                  -> pass
 *   public stale, origin fresh    -> the CDN in front of the host
 *   public stale, origin stale    -> a cache on the server itself
 *   public stale, origin unknown  -> a cache, layer not identified
 *   public unreachable            -> warning: nothing was measured
 *
 * A fetch that failed is a WARNING, never a pass and never a failure: a
 * network that did not answer measured nothing, and reddening a deploy for it
 * is how an alarm becomes noise (the timeout argument in
 * `.claude/rules/release-and-deploy.md` "Post-deploy smoke (alarm, not a gate)").
 * A body that answered and differs is the defect, and fails.
 *
 * THE JUDGEMENT IS PURE so `AssetFreshnessCheckTest` can drive every branch
 * without a host; the fetching stays in `testes-smoke.php`.
 *
 * @package FreeFormCertificate\CI
 */

declare(strict_types=1);

/**
 * Judge one asset from the hashes of what each layer served.
 *
 * @param string      $deployed sha256 of the file on disk.
 * @param string|null $public   sha256 of the anonymous public fetch; null when it failed.
 * @param string|null $origin   sha256 of the fetch from the server itself; null when it failed or was not tried.
 * @return array{ok: bool, fatal: bool, detail: string}
 */
function ffc_asset_freshness_verdict( string $deployed, ?string $public, ?string $origin ): array {
	if ( null === $public ) {
		return array(
			'ok'     => false,
			'fatal'  => false,
			'detail' => 'the public fetch failed, nothing was compared',
		);
	}

	if ( hash_equals( $deployed, $public ) ) {
		return array(
			'ok'     => true,
			'fatal'  => false,
			'detail' => 'matches the deployed file',
		);
	}

	if ( null === $origin ) {
		$layer = 'a host cache serves an older file than the one deployed (the server itself did not answer, so the layer is unknown)';
	} elseif ( hash_equals( $deployed, $origin ) ) {
		$layer = 'the CDN serves an older file than the one deployed (the server itself serves the new one): purge the CDN';
	} else {
		$layer = 'the server\'s own cache serves an older file than the one deployed: purge the host cache';
	}

	return array(
		'ok'     => false,
		'fatal'  => true,
		'detail' => $layer,
	);
}

/**
 * The same URL, addressed to the server itself.
 *
 * The scheme, path and query stay; only the host becomes 127.0.0.1, and the
 * site's host travels in the `Host` header, so the web server picks the same
 * virtual host while the request never reaches the CDN.
 *
 * @param string $url A public asset URL.
 * @return array{url: string, host: string}|null Null when the URL has no host.
 */
function ffc_asset_origin_request( string $url ): ?array {
	$parts = parse_url( $url );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
		return null;
	}

	$host = (string) $parts['host'];
	if ( isset( $parts['port'] ) ) {
		$host .= ':' . (int) $parts['port'];
	}

	$origin = $parts['scheme'] . '://127.0.0.1'
		. ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' )
		. ( $parts['path'] ?? '/' )
		. ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );

	return array(
		'url'  => $origin,
		'host' => $host,
	);
}

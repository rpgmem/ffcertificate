<?php
/**
 * Personal Page Cache
 *
 * A page that renders one visitor's schedule exception (its banner, its
 * token) or an invitation's name and e-mail must never be stored by a page
 * cache, or the next visitor is served the first one's (#2341). The dashboard
 * and the scheduling shortcodes already keep themselves out; this does the
 * same for the certificate form when the request carries either.
 *
 * @package FreeFormCertificate\Frontend
 * @since   6.44.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

use FreeFormCertificate\Core\NoCache;
use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps requests that carry a visitor's own state out of page caches.
 */
final class PersonalPageCache {

	/**
	 * Hook the check.
	 *
	 * `template_redirect` is the last action before output starts, so the
	 * no-cache headers can still be sent, and the query is parsed by then.
	 * Default priority: nothing else here needs to run before it.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_forbid' ) );
	}

	/**
	 * Mark the response as not cacheable when the request carries a
	 * schedule exception or an invitation.
	 *
	 * @return void
	 */
	public static function maybe_forbid(): void {
		if ( self::carries_personal_state() ) {
			NoCache::send( 'page shows a schedule exception or an invitation' );
		}
	}

	/**
	 * Whether the request carries a schedule-exception cookie or an
	 * invitation code.
	 *
	 * @return bool
	 */
	public static function carries_personal_state(): bool {
		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( 0 === strpos( (string) $name, ScheduleExceptionSession::COOKIE_PREFIX ) ) {
				return true;
			}
		}
		return '' !== RequestInput::get_get_string( FormInvite::QUERY_VAR );
	}
}

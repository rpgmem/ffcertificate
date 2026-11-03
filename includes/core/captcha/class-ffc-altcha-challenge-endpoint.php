<?php
/**
 * AltchaChallengeEndpoint
 *
 * Serves ALTCHA challenges to the widget. It exists because a challenge
 * embedded in the page HTML goes stale behind a full-page cache — the same
 * problem the fragment refresh solves for the math strategy, solved here by
 * never putting the challenge in the HTML at all.
 *
 * Deliberately nonce-free: the widget fetches this before any form
 * interaction, a cached page would carry a stale nonce anyway, and the
 * response is a challenge — something the server is happy to hand anyone. It
 * grants nothing on its own; the signature is what makes a solution to it
 * worth anything, and that is checked at submission.
 *
 * @package FreeFormCertificate\Core\Captcha
 * @since 6.23.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core\Captcha;

use FreeFormCertificate\Core\RequestInput;
use FreeFormCertificate\Core\WindowThrottle;
use FreeFormCertificate\Settings\RateLimitSettingsReader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX endpoint issuing ALTCHA challenges.
 */
class AltchaChallengeEndpoint {

	/**
	 * AJAX action name.
	 *
	 * @var string
	 */
	public const AJAX_ACTION = 'ffc_altcha_challenge';

	/**
	 * Transient key prefix.
	 *
	 * `ffc_` with no leading underscore, so the stored option is
	 * `_transient_ffc_…` and the uninstall sweep matches it.
	 *
	 * @var string
	 */
	private const PREFIX = 'ffc_altcha_mint_';

	/**
	 * Register the AJAX handlers.
	 *
	 * Both privileged and unprivileged: the forms this guards are public.
	 *
	 * @return void
	 */
	public static function init(): void {
		\add_action( 'wp_ajax_' . self::AJAX_ACTION, array( self::class, 'handle' ) );
		\add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Issue a challenge.
	 *
	 * The body is the bare challenge object, not the `{success, data}`
	 * envelope every other endpoint here uses: the widget reads the response
	 * as an ALTCHA challenge and would not find the fields inside a wrapper.
	 *
	 * @return void
	 */
	public static function handle(): void {
		// A cached challenge is a shared challenge, and a shared challenge is
		// a single-use token the first solver spends for everyone else. This
		// endpoint must never be stored by a page cache or a CDN.
		\nocache_headers();

		if ( ! self::allow_mint() ) {
			self::refuse( WindowThrottle::seconds_left( CaptchaSettings::mint_window() ) );
		}

		// The context names the form the widget sits in. Read from the client,
		// which is safe for issuing: the verifier names the context it expects.
		\wp_send_json( AltchaCaptcha::create_challenge( CaptchaContext::sanitize( RequestInput::get_get_string( 'context' ) ) ) );
	}

	/**
	 * Refuse a mint over the cap, saying when the next one is allowed.
	 *
	 * The wait travels twice (#2339): as `Retry-After`, the header a client
	 * reads, and as `retryAfter` in the body beside the `error` the widget
	 * expects. The widget only throws on a refusal, so it is the plugin's
	 * glue (`ffc-captcha.js`) that reads the header and tells the visitor how
	 * long to wait, instead of asking for a reload, which would ask for one
	 * more challenge and be refused again.
	 *
	 * @param int $seconds Seconds until the caller's window ends.
	 * @return void
	 */
	private static function refuse( int $seconds ): void {
		$seconds = max( 1, $seconds );
		if ( ! \headers_sent() ) {
			\header( 'Retry-After: ' . $seconds );
		}
		\status_header( 429 );
		\wp_send_json(
			array(
				'error'      => \__( 'Too many verification requests from this network. Please wait before trying again.', 'ffcertificate' ),
				'retryAfter' => $seconds,
			),
			429
		);
	}

	/**
	 * Count this mint against the caller's window.
	 *
	 * Its own throttle rather than `RateLimiter::check_ip_limit()`, which
	 * records an attempt in the `ffc_rate_limit_*` tables: those count
	 * submissions, and spending a visitor's submission budget because their
	 * browser loaded a widget would throttle people who have not yet filled
	 * in a single field. Minting is also cheap — a random int, a hash — so
	 * what is bounded here is farming, not server load.
	 *
	 * Both halves are configurable (Rate Limit → IP), because no fixed
	 * per-address number can be right for both a home connection and the
	 * institutional NAT this plugin's audience sits behind (#1111) — and a
	 * cap whose window is a private constant is half a setting. At 0 the
	 * counter is skipped outright — no read, no write — so "no cap" costs
	 * nothing rather than counting into a ceiling that never applies.
	 *
	 * @return bool True when the caller may mint another challenge.
	 */
	private static function allow_mint(): bool {
		return WindowThrottle::take( self::PREFIX, CaptchaSettings::mint_cap(), CaptchaSettings::mint_window(), RateLimitSettingsReader::captcha_counts_unresolved() );
	}
}

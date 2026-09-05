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
	 * Challenges one address may mint per window.
	 *
	 * Generous on purpose: a visitor legitimately mints one per form render,
	 * plus one per retry, and a shared address behind institutional NAT — the
	 * normal case for this plugin's audience — multiplies that by everyone
	 * sitting behind it. The cap is a ceiling on farming, not a quota anyone
	 * should meet.
	 *
	 * @var int
	 */
	private const MAX_PER_WINDOW = 60;

	/**
	 * Length of the throttle window, in seconds.
	 *
	 * @var int
	 */
	private const WINDOW = 600;

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
			\status_header( 429 );
			\wp_send_json(
				array( 'error' => \__( 'Too many requests. Please wait.', 'ffcertificate' ) ),
				429
			);
		}

		\wp_send_json( AltchaCaptcha::create_challenge() );
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
	 * @return bool True when the caller may mint another challenge.
	 */
	private static function allow_mint(): bool {
		$ip = RequestInput::get_user_ip();
		if ( '' === $ip ) {
			return true;
		}

		/*
		 * Fixed windows, not a sliding one: the key carries the window number,
		 * so a steady caller crosses into a fresh bucket instead of holding
		 * one open. Re-setting a single transient on every mint would renew
		 * its expiry each time and never reset the count. This is the same
		 * bucketing `RateLimitChecker::check_global_limit()` already uses.
		 *
		 * The address is hashed and never stored raw — an option name is
		 * readable by anything that can list options, and a visitor IP is
		 * personal data.
		 */
		$bucket = (int) floor( time() / self::WINDOW );
		$key    = self::PREFIX . substr( hash( 'sha256', $ip ), 0, 32 ) . '_' . $bucket;
		$count  = \get_transient( $key );
		$count  = is_numeric( $count ) ? (int) $count : 0;

		if ( $count >= self::MAX_PER_WINDOW ) {
			return false;
		}

		\set_transient( $key, $count + 1, self::WINDOW );

		return true;
	}
}

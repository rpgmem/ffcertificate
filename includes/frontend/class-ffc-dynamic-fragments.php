<?php
/**
 * DynamicFragments
 * AJAX endpoint for refreshing cache-sensitive page fragments.
 *
 * When full-page caching (LiteSpeed, Varnish, etc.) is active, server-rendered
 * captchas and nonces become stale.  This lightweight endpoint returns fresh
 * values so JavaScript can patch the DOM immediately after page load.
 *
 * @since   4.12.0
 * @package FreeFormCertificate\Frontend
 */

declare(strict_types=1);

namespace FreeFormCertificate\Frontend;

use FreeFormCertificate\Core\Captcha;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX endpoint for refreshing cache-sensitive page fragments.
 *
 * @since 4.12.0
 */
class DynamicFragments {

	/**
	 * Ceiling on how many challenges one request may mint.
	 *
	 * The endpoint is public and nonce-free by design, so the count the
	 * client posts is attacker-controlled: without a bound, one request
	 * asks for as many signed challenges as it likes. A page with more
	 * security blocks than this does not exist in any shipped shortcode
	 * combination.
	 *
	 * @since 6.23.0
	 * @var int
	 */
	private const MAX_SECURITY_BLOCKS = 20;

	/**
	 * Ceiling on how many form ids one request may ask about.
	 *
	 * Same reasoning: each id costs a `get_post_meta()` read in the
	 * geofence loop below.
	 *
	 * @since 6.23.0
	 * @var int
	 */
	private const MAX_FORM_IDS = 20;

	/**
	 * Register AJAX handlers for dynamic fragments.
	 */
	public function __construct() {
		add_action( 'wp_ajax_ffc_get_dynamic_fragments', array( $this, 'handle' ) );
		add_action( 'wp_ajax_nopriv_ffc_get_dynamic_fragments', array( $this, 'handle' ) );
	}

	/**
	 * Return fresh captcha data and nonces.
	 *
	 * This endpoint intentionally does NOT require a nonce because its sole
	 * purpose is to *generate* fresh nonces for cached pages where the
	 * original nonce has expired.  The generated nonces are session-specific
	 * (tied to the visitor's cookies) and safe to expose.
	 */
	public function handle(): void {
		$fragments = array(
			// Forwarded verbatim from whichever strategy is configured. The
			// endpoint deliberately does not name the fields: a proof-of-work
			// challenge has no label and no answer hash, so any mapping here
			// would only hold for the math one. The client dispatches on the
			// payload's own `provider` key.
			'captcha' => Captcha\CaptchaProvider::resolve()->challenge_payload(),
			'nonces'  => array(
				'ffc_frontend_nonce'        => wp_create_nonce( 'ffc_frontend_nonce' ),
				'ffc_self_scheduling_nonce' => wp_create_nonce( 'ffc_self_scheduling_nonce' ),
				// Public CSV download: refresh the per-visitor nonce baked
				// into the cached HTML by wp_nonce_field() inside the
				// [ffc_csv_download] shortcode. Without this, cached pages
				// submit with a stale nonce and the AJAX info endpoint
				// responds with "Security check failed".
				'ffc_public_csv_download'   => wp_create_nonce( 'ffc_public_csv_download' ),
				// ffc_audience shortcode: the two nonces localised into the
				// `ffcAudience` JS global. Cached HTML for logged-in users
				// on hosts that cache per-user pages (LiteSpeed user-tier,
				// WP Rocket) would otherwise serve the nonces of whoever
				// the cache entry was generated for, and REST calls reject
				// the other visitor with 401.
				'wp_rest'                   => wp_create_nonce( 'wp_rest' ),
				'ffc_search_users'          => wp_create_nonce( 'ffc_search_users' ),
			),
		);

		// Include logged-in user data for booking form pre-fill.
		if ( is_user_logged_in() ) {
			$user              = wp_get_current_user();
			$fragments['user'] = array(
				'name'  => $user->display_name,
				'email' => $user->user_email,
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Intentionally nonce-free; see class docblock.
		$form_ids = isset( $_POST['form_ids'] ) ? array_map( 'absint', (array) $_POST['form_ids'] ) : array();
		$form_ids = array_slice( $form_ids, 0, self::MAX_FORM_IDS );

		// Issue a distinct challenge per *security block*, so no two blocks on
		// one page ever share a token — the resolver memoises the strategy
		// instance, not the challenge, so each call mints a fresh one.
		//
		// The count comes from the client because only the client can know it:
		// a block is whatever renders a challenge, and three of the shortcodes
		// that render one ([ffc_self_scheduling], [ffc_csv_download]) sit
		// outside any form wrapper. Counting form ids here instead was the
		// #1063 defect — one certificate form beside one of those reads as a
		// single form, no per-form map is emitted, and both blocks fall back
		// to the same default payload. Since #1054 the token is single-use, so
		// whoever submits first spends the other's.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Intentionally nonce-free; see class docblock.
		$blocks = isset( $_POST['blocks'] ) ? absint( wp_unslash( $_POST['blocks'] ) ) : 0;
		$blocks = min( $blocks, self::MAX_SECURITY_BLOCKS );

		if ( $blocks > 1 ) {
			$per_block = array();
			for ( $i = 0; $i < $blocks; $i++ ) {
				$per_block[] = Captcha\CaptchaProvider::resolve()->challenge_payload();
			}
			$fragments['captchas'] = $per_block;
		}

		// Include fresh geofence configs so cached pages get up-to-date
		// date/time windows if the admin changed them after the page was cached.
		if ( ! empty( $form_ids ) ) {
			$geofence = array();
			foreach ( $form_ids as $fid ) {
				if ( $fid > 0 ) {
					$config = \FreeFormCertificate\Security\Geofence::get_frontend_config( $fid );
					if ( null !== $config ) {
						$geofence[ $fid ] = $config;
					}
				}
			}
			if ( ! empty( $geofence ) ) {
				$fragments['geofence'] = $geofence;
			}
		}

		wp_send_json_success( $fragments );
	}
}

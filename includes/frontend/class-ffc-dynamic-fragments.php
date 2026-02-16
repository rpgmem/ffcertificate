<?php
declare(strict_types=1);

/**
 * DynamicFragments
 * AJAX endpoint for refreshing cache-sensitive page fragments.
 *
 * When full-page caching (LiteSpeed, Varnish, etc.) is active, server-rendered
 * captchas and nonces become stale.  This lightweight endpoint returns fresh
 * values so JavaScript can patch the DOM immediately after page load.
 *
 * @since 4.12.0
 */

namespace FreeFormCertificate\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DynamicFragments {

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
		$captcha = \FreeFormCertificate\Core\Utils::generate_simple_captcha();

		$fragments = array(
			'captcha' => array(
				'label' => $captcha['label'],
				'hash'  => $captcha['hash'],
			),
			'nonces'  => array(
				'ffc_frontend_nonce'        => wp_create_nonce( 'ffc_frontend_nonce' ),
				'ffc_self_scheduling_nonce' => wp_create_nonce( 'ffc_self_scheduling_nonce' ),
			),
		);

		// Include logged-in user data for booking form pre-fill
		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			$fragments['user'] = array(
				'name'  => $user->display_name,
				'email' => $user->user_email,
			);
		}

		wp_send_json_success( $fragments );
	}
}

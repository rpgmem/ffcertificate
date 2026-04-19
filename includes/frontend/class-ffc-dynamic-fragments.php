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
		$captcha = \FreeFormCertificate\Core\Utils::generate_simple_captcha();

		$fragments = array(
			'captcha' => array(
				'label' => $captcha['label'],
				'hash'  => $captcha['hash'],
			),
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

		// Generate a unique captcha per form so multiple forms on the same
		// page each show a different math question after cache refresh.
		if ( count( $form_ids ) > 1 ) {
			$per_form = array();
			foreach ( $form_ids as $fid ) {
				if ( $fid > 0 ) {
					$c                = \FreeFormCertificate\Core\Utils::generate_simple_captcha();
					$per_form[ $fid ] = array(
						'label' => $c['label'],
						'hash'  => $c['hash'],
					);
				}
			}
			if ( ! empty( $per_form ) ) {
				$fragments['captchas'] = $per_form;
			}
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

<?php
/**
 * Cache action AJAX endpoint — purge the page caches without a page reload.
 *
 * Mirrors the legacy admin_init handler in `Settings::handle_cache_actions()`
 * (which keeps working as the no-JS fallback) but returns JSON so the
 * cache-actions JS can pop a toast and stay on the same page.
 *
 * Security:
 *   - nonce verified against the AJAX action name (FFC.request supplies it).
 *   - capability gated via Capabilities::current_user_can_admin_or, matching the
 *     legacy handler's gate so privilege boundaries don't shift.
 *
 * @package FreeFormCertificate\Admin
 * @since 6.5.5
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX endpoint for the "Purge page caches" button. The "Warm cache now"
 * action left with the form-settings cache (#2241).
 */
class CacheActionsAjaxEndpoint {

	public const ACTION_CLEAR = 'ffc_cache_clear';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'wp_ajax_' . self::ACTION_CLEAR, array( self::class, 'handle_clear' ) );
	}

	/**
	 * Capability gate — same shape as the
	 * legacy admin_init handler so privilege boundaries don't shift.
	 *
	 * @param string $action AJAX action name (used by check_ajax_referer).
	 */
	private static function guard( string $action ): void {
		check_ajax_referer( $action, 'nonce' );
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_settings' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to manage the cache.', 'ffcertificate' ) ),
				403
			);
		}
	}

	/**
	 * Purge the page caches of every published form, so visitors see the
	 * current form rather than a cached copy.
	 */
	public static function handle_clear(): void {
		self::guard( self::ACTION_CLEAR );

		\FreeFormCertificate\Submissions\FormCache::purge_external_caches_for_all_forms( 'manual_clear_all' );

		wp_send_json_success(
			array(
				'message' => __( 'Page caches purged.', 'ffcertificate' ),
			)
		);
	}
}

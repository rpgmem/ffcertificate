<?php
/**
 * Cache action AJAX endpoints — Clear / Warm without page reload.
 *
 * Mirrors the legacy admin_init handler in `Settings::handle_cache_actions()`
 * (which keeps working as the no-JS fallback) but returns JSON so the
 * cache-actions JS can pop a toast and stay on the same page.
 *
 * Security:
 *   - nonce verified against the AJAX action name (FFC.request supplies it).
 *   - capability gated via Utils::current_user_can_admin_or, matching the
 *     legacy handler's gate so privilege boundaries don't shift.
 *
 * @package FreeFormCertificate\Admin
 * @since 6.5.5
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX endpoint for the "Warm cache now" / "Clear cache" buttons.
 */
class CacheActionsAjaxEndpoint {

	public const ACTION_WARM  = 'ffc_cache_warm';
	public const ACTION_CLEAR = 'ffc_cache_clear';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'wp_ajax_' . self::ACTION_WARM,  array( self::class, 'handle_warm' ) );
		add_action( 'wp_ajax_' . self::ACTION_CLEAR, array( self::class, 'handle_clear' ) );
	}

	/**
	 * Capability gate shared by both endpoints — same shape as the
	 * legacy admin_init handler so privilege boundaries don't shift.
	 *
	 * @param string $action AJAX action name (used by check_ajax_referer).
	 */
	private static function guard( string $action ): void {
		check_ajax_referer( $action, 'nonce' );
		if ( ! \FreeFormCertificate\Core\Utils::current_user_can_admin_or( 'ffc_manage_settings' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to manage the cache.', 'ffcertificate' ) ),
				403
			);
		}
	}

	/**
	 * Warm the form cache for every published form, return the count.
	 */
	public static function handle_warm(): void {
		self::guard( self::ACTION_WARM );

		$count = \FreeFormCertificate\Submissions\FormCache::warm_all_forms();

		wp_send_json_success(
			array(
				'count'   => $count,
				/* translators: %d: number of forms whose cache was warmed. */
				'message' => sprintf( _n( 'Cache warmed for %d form.', 'Cache warmed for %d forms.', $count, 'ffcertificate' ), $count ),
			)
		);
	}

	/**
	 * Drop the entire form cache.
	 */
	public static function handle_clear(): void {
		self::guard( self::ACTION_CLEAR );

		\FreeFormCertificate\Submissions\FormCache::clear_all_cache();

		wp_send_json_success(
			array(
				'message' => __( 'Cache cleared.', 'ffcertificate' ),
			)
		);
	}
}

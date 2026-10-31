<?php
/**
 * User Context Trait
 *
 * Shared user-context resolution for all user-facing REST sub-controllers.
 * Provides:
 *   - resolve_user_context()  – resolves effective user_id (supports admin view-as)
 *   - user_has_capability()   – capability check against the effective user
 *
 * @package FreeFormCertificate\API
 * @since 4.12.7
 */

declare(strict_types=1);

namespace FreeFormCertificate\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait UserContextTrait {

	/**
	 * Resolve effective user_id and whether view-as is active
	 *
	 * When admin uses view-as, capability checks must use the TARGET
	 * user's capabilities so the admin sees exactly what the user would see.
	 *
	 * @since 4.9.7
	 * @param \WP_REST_Request $request REST request.
	 * @return array{user_id: int, is_view_as: bool}
	 */
	private function resolve_user_context( $request ): array {
		$user_id    = get_current_user_id();
		$is_view_as = false;

		// The page's gate (#2246). rest_permission() already refused a
		// request that names an account this one may not view; this repeats
		// it so no route can read another account through a missed callback.
		$view_as_user_id = absint( $request->get_param( 'viewAsUserId' ) );
		if ( $view_as_user_id > 0 && \FreeFormCertificate\Core\ViewAs::can_view( $view_as_user_id ) ) {
			$user_id    = $view_as_user_id;
			$is_view_as = true;
		}

		return array(
			'user_id'    => $user_id,
			'is_view_as' => $is_view_as,
		);
	}

	/**
	 * Permission callback of every dashboard route (#2246).
	 *
	 * A logged-in caller is let through; one that names a view-as target is
	 * let through only when the shared gate allows that target. A request
	 * the gate refuses is refused outright (403) instead of answering with
	 * the caller's own data, which the page used to show under a link the
	 * administrator believed was someone else's.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return bool|\WP_Error
	 */
	public function rest_permission( $request ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$view_as_user_id = $request->get_param( 'viewAsUserId' );
		if ( null === $view_as_user_id || '' === $view_as_user_id || '0' === (string) $view_as_user_id ) {
			return true;
		}
		if ( \FreeFormCertificate\Core\ViewAs::can_view( absint( $view_as_user_id ) ) ) {
			return true;
		}
		return new \WP_Error( 'ffc_view_as_denied', __( 'You may not view the dashboard as this account.', 'ffcertificate' ), array( 'status' => 403 ) );
	}

	/**
	 * Check if a capability is granted for the effective user
	 *
	 * In view-as mode, checks the TARGET user's capabilities.
	 * Otherwise, checks the current user's capabilities.
	 *
	 * @since 4.9.7
	 * @param string $capability Capability name.
	 * @param int    $user_id Target user ID.
	 * @param bool   $is_view_as Whether view-as mode is active.
	 * @return bool
	 */
	private function user_has_capability( string $capability, int $user_id, bool $is_view_as ): bool {
		if ( $is_view_as ) {
			return user_can( $user_id, $capability );
		}
		return current_user_can( 'manage_options' ) || current_user_can( $capability );
	}
}

<?php
declare(strict_types=1);

/**
 * User Context Trait
 *
 * Shared user-context resolution for all user-facing REST sub-controllers.
 * Provides:
 *   - resolve_user_context()  – resolves effective user_id (supports admin view-as)
 *   - user_has_capability()   – capability check against the effective user
 *
 * @since 4.12.7
 * @package FreeFormCertificate\API
 */

namespace FreeFormCertificate\API;

if (!defined('ABSPATH')) exit;

trait UserContextTrait {

    /**
     * Resolve effective user_id and whether view-as is active
     *
     * When admin uses view-as, capability checks must use the TARGET
     * user's capabilities so the admin sees exactly what the user would see.
     *
     * @since 4.9.7
     * @param \WP_REST_Request $request
     * @return array{user_id: int, is_view_as: bool}
     */
    private function resolve_user_context($request): array {
        $user_id = get_current_user_id();
        $is_view_as = false;

        $view_as_user_id = $request->get_param('viewAsUserId');
        if ($view_as_user_id && current_user_can('manage_options')) {
            $user_id = absint($view_as_user_id);
            $is_view_as = true;
        }

        return array('user_id' => $user_id, 'is_view_as' => $is_view_as);
    }

    /**
     * Check if a capability is granted for the effective user
     *
     * In view-as mode, checks the TARGET user's capabilities.
     * Otherwise, checks the current user's capabilities.
     *
     * @since 4.9.7
     * @param string $capability Capability name
     * @param int $user_id Target user ID
     * @param bool $is_view_as Whether view-as mode is active
     * @return bool
     */
    private function user_has_capability(string $capability, int $user_id, bool $is_view_as): bool {
        if ($is_view_as) {
            return user_can($user_id, $capability);
        }
        return current_user_can('manage_options') || current_user_can($capability);
    }
}

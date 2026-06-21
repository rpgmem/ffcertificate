<?php
/**
 * Capabilities
 *
 * Current-user permission gate sliced out of the Core\Utils god-utility
 * (#563 Sprint 5, B1/B3): the inline `manage_options`-or-granular-cap checks
 * used across admin surfaces for the 3-state permission model. Thin facade
 * over WordPress `current_user_can()`.
 *
 * @package FreeFormCertificate\Core
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Current-user capability checks.
 */
class Capabilities {

	/**
	 * Check if current user can manage plugin
	 *
	 * @return bool True if can manage, false otherwise
	 */
	public static function current_user_can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Capability gate that grants access to admins (`manage_options`) OR
	 * to anyone holding a domain-specific cap.
	 *
	 * Used to swap blanket `manage_options` gates across the plugin for
	 * granular caps registered in 6.2.0, while keeping every site admin's
	 * existing access intact (all admins continue to pass every check
	 * because they have `manage_options`).
	 *
	 * Example:
	 *
	 *   if ( ! Capabilities::current_user_can_admin_or( 'ffc_view_activity_log' ) ) {
	 *       wp_die( __( 'Insufficient permissions', 'ffcertificate' ) );
	 *   }
	 *
	 * Site admins always pass; users with the granular cap pass; users
	 * with neither are rejected.
	 *
	 * @since 6.2.0
	 * @param string $granular_cap FFC-namespaced capability slug (e.g. `ffc_export_certificates`).
	 * @return bool
	 */
	public static function current_user_can_admin_or( string $granular_cap ): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return '' !== $granular_cap && current_user_can( $granular_cap );
	}
}

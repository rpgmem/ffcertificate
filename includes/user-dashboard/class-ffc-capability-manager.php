<?php
/**
 * CapabilityManager
 *
 * Manages FFC user capabilities, roles, and permission granting.
 * Extracted from UserManager (v4.12.2) for single-responsibility.
 *
 * @package FreeFormCertificate\UserDashboard
 * @since 4.12.2
 */

declare(strict_types=1);

namespace FreeFormCertificate\UserDashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manager for capability operations.
 */
class CapabilityManager {

	/**
	 * Context constants for capability granting
	 */
	public const CONTEXT_CERTIFICATE = 'certificate';
	public const CONTEXT_APPOINTMENT = 'appointment';
	public const CONTEXT_AUDIENCE    = 'audience';

	/**
	 * Context key: recruitment-module candidate promotion path.
	 *
	 * Used by `UserCreator::get_or_create_user()` when the candidate is
	 * being linked or created via the recruitment CSV importer (sprint 4) or
	 * a manual admin edit (sprint 9.1). No per-user caps are granted on this
	 * context — candidates rely on the `ffc_user` role's baseline `read` cap
	 * to access their dashboard "Minhas Convocações" section. The
	 * `ffc_manage_recruitment` admin cap is registered separately on plugin
	 * activation (see {@see self::register_recruitment_manager_role()}).
	 *
	 * @since 6.0.0
	 */
	public const CONTEXT_RECRUITMENT = 'recruitment';

	/**
	 * All certificate-related capabilities
	 */
	public const CERTIFICATE_CAPABILITIES = array(
		'view_own_certificates',
		'download_own_certificates',
		'view_certificate_history',
	);

	/**
	 * All appointment-related capabilities
	 */
	public const APPOINTMENT_CAPABILITIES = array(
		'ffc_book_appointments',
		'ffc_view_self_scheduling',
		'ffc_cancel_own_appointments',
	);

	/**
	 * All audience-related capabilities
	 *
	 * @since 4.9.3
	 */
	public const AUDIENCE_CAPABILITIES = array(
		'ffc_view_audience_bookings',
	);

	/**
	 * Admin-level capabilities (not granted by default).
	 *
	 * @since 4.9.3
	 * @since 6.2.0 Expanded with module-management caps + per-domain
	 *              recruitment caps to replace blanket `manage_options`
	 *              gates with delegable, granular permissions. The pre-6.2.0
	 *              caps (`ffc_scheduling_bypass`, `ffc_manage_reregistration`,
	 *              `ffc_manage_recruitment`) remain unchanged so any user
	 *              already holding them keeps their access; the new caps
	 *              add scoped delegation paths.
	 */
	public const ADMIN_CAPABILITIES = array(
		// Pre-6.2.0 caps.
		'ffc_scheduling_bypass',
		'ffc_manage_reregistration',
		'ffc_manage_recruitment',

		// Module-management caps (6.2.0). Each replaces a `manage_options`
		// gate at a module-admin entry point so site admins can delegate the
		// module to a dedicated operator without giving full WP admin.
		'ffc_manage_certificates',
		'ffc_export_certificates',
		'ffc_manage_self_scheduling',
		'ffc_manage_audiences',
		'ffc_view_activity_log',
		'ffc_manage_user_custom_fields',
		'ffc_view_as_user',
		'ffc_manage_settings',

		// Per-domain recruitment caps (6.2.0). Granular substitutes for the
		// catch-all `ffc_manage_recruitment` — operators can be wired with
		// "view only", "view + call candidates", "view + import CSV", etc.
		// without unlocking the entire admin surface. `ffc_manage_recruitment`
		// stays as the umbrella cap (catch-all backwards-compat) for any
		// endpoint that doesn't match one of the granular entries.
		'ffc_view_recruitment',
		'ffc_import_recruitment_csv',
		'ffc_call_recruitment_candidates',
		'ffc_view_recruitment_pii',
		'ffc_manage_recruitment_settings',
		'ffc_manage_recruitment_reasons',

		// Submission-edit cap (6.2.0). Reactivated from the legacy
		// `FUTURE_CAPABILITIES` placeholder; gates the admin submission
		// edit page so non-admin operators can fix typos in issued
		// certificates without holding `manage_options`.
		'ffc_certificate_update',
	);

	/**
	 * Slug for the dedicated recruitment-manager role.
	 *
	 * The role is granted `read` + `ffc_manage_recruitment` so site admins
	 * can delegate recruitment management without giving out full
	 * `manage_options`. Created on plugin activation, removed on uninstall.
	 *
	 * @since 6.0.0
	 */
	public const RECRUITMENT_MANAGER_ROLE = 'ffc_recruitment_manager';

	/**
	 * Future capabilities (disabled by default).
	 *
	 * Now empty — historical placeholders have been retired:
	 * - `ffc_reregistration` (4.9.3): never wired. Audience-targeting on
	 *   reregistration objects already filters who can submit each form;
	 *   adding a per-user cap on top was redundant. Removed in 6.2.0.
	 * - `ffc_certificate_update` (4.9.3): wired in 6.2.0 as a real admin
	 *   cap (see `ADMIN_CAPABILITIES` above).
	 *
	 * @since 4.9.3
	 * @since 6.2.0 Cleared. The constant remains so external code that
	 *              referenced it doesn't fatal.
	 */
	public const FUTURE_CAPABILITIES = array();

	/**
	 * Get all FFC capabilities consolidated
	 *
	 * @since 4.9.3
	 * @return array<int, string> All FFC capability names
	 */
	public static function get_all_capabilities(): array {
		return array_merge(
			self::CERTIFICATE_CAPABILITIES,
			self::APPOINTMENT_CAPABILITIES,
			self::AUDIENCE_CAPABILITIES,
			self::ADMIN_CAPABILITIES,
			self::FUTURE_CAPABILITIES
		);
	}

	/**
	 * Grant capabilities based on context
	 *
	 * @since 4.4.0
	 * @param int    $user_id WordPress user ID.
	 * @param string $context Context ('certificate', 'appointment', or 'audience').
	 * @return void
	 */
	public static function grant_context_capabilities( int $user_id, string $context ): void {
		switch ( $context ) {
			case self::CONTEXT_CERTIFICATE:
				self::grant_certificate_capabilities( $user_id );
				break;
			case self::CONTEXT_APPOINTMENT:
				self::grant_appointment_capabilities( $user_id );
				break;
			case self::CONTEXT_AUDIENCE:
				self::grant_audience_capabilities( $user_id );
				break;
			case self::CONTEXT_RECRUITMENT:
				// Intentional no-op: recruitment candidates rely on the
				// `ffc_user` role's baseline `read` cap. The admin-side
				// `ffc_manage_recruitment` cap is registered on activation,
				// not granted at promotion time.
				break;
		}
	}

	/**
	 * Grant certificate capabilities to a user
	 *
	 * @since 4.4.0
	 * @param int $user_id WordPress user ID.
	 * @return void
	 */
	public static function grant_certificate_capabilities( int $user_id ): void {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		$newly_granted = array();

		foreach ( self::CERTIFICATE_CAPABILITIES as $cap ) {
			if ( ! $user->has_cap( $cap ) ) {
				$user->add_cap( $cap, true );
				$newly_granted[] = $cap;
			}
		}

		if ( class_exists( '\FreeFormCertificate\Core\Debug' ) ) {
			\FreeFormCertificate\Core\Debug::log_user_manager(
				'Granted certificate capabilities',
				array(
					'user_id'      => $user_id,
					'capabilities' => self::CERTIFICATE_CAPABILITIES,
				)
			);
		}

		if ( ! empty( $newly_granted ) ) {
			self::log_and_notify_capability_grant( $user, 'certificate', $newly_granted );
		}
	}

	/**
	 * Grant appointment capabilities to a user
	 *
	 * @since 4.4.0
	 * @param int $user_id WordPress user ID.
	 * @return void
	 */
	public static function grant_appointment_capabilities( int $user_id ): void {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		$newly_granted = array();

		foreach ( self::APPOINTMENT_CAPABILITIES as $cap ) {
			if ( ! $user->has_cap( $cap ) ) {
				$user->add_cap( $cap, true );
				$newly_granted[] = $cap;
			}
		}

		if ( class_exists( '\FreeFormCertificate\Core\Debug' ) ) {
			\FreeFormCertificate\Core\Debug::log_user_manager(
				'Granted appointment capabilities',
				array(
					'user_id'      => $user_id,
					'capabilities' => self::APPOINTMENT_CAPABILITIES,
				)
			);
		}

		if ( ! empty( $newly_granted ) ) {
			self::log_and_notify_capability_grant( $user, 'appointment', $newly_granted );
		}
	}

	/**
	 * Grant audience capabilities to a user
	 *
	 * @since 4.9.3
	 * @param int $user_id WordPress user ID.
	 * @return void
	 */
	public static function grant_audience_capabilities( int $user_id ): void {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		$newly_granted = array();

		foreach ( self::AUDIENCE_CAPABILITIES as $cap ) {
			if ( ! $user->has_cap( $cap ) ) {
				$user->add_cap( $cap, true );
				$newly_granted[] = $cap;
			}
		}

		if ( class_exists( '\FreeFormCertificate\Core\Debug' ) ) {
			\FreeFormCertificate\Core\Debug::log_user_manager(
				'Granted audience capabilities',
				array(
					'user_id'      => $user_id,
					'capabilities' => self::AUDIENCE_CAPABILITIES,
				)
			);
		}

		if ( ! empty( $newly_granted ) ) {
			self::log_and_notify_capability_grant( $user, 'audience', $newly_granted );
		}
	}

	/**
	 * Log capability grant to activity log and send email notification
	 *
	 * @since 4.9.9
	 * @param \WP_User           $user         User who received capabilities.
	 * @param string             $context      Context: 'certificate', 'appointment', 'audience'.
	 * @param array<int, string> $capabilities Newly granted capabilities.
	 * @return void
	 */
	private static function log_and_notify_capability_grant( \WP_User $user, string $context, array $capabilities ): void {
		if ( class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			\FreeFormCertificate\Core\ActivityLog::log_capabilities_granted(
				$user->ID,
				$context,
				$capabilities
			);
		}

		$settings       = get_option( 'ffc_settings', array() );
		$notify_enabled = ! empty( $settings['notify_capability_grant'] );

		if ( ! $notify_enabled || empty( $user->user_email ) ) {
			return;
		}

		$site_name     = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
		$dashboard_url = get_permalink( get_option( 'ffc_dashboard_page_id' ) );

		$context_labels = array(
			'certificate' => __( 'Certificates', 'ffcertificate' ),
			'appointment' => __( 'Appointments', 'ffcertificate' ),
			'audience'    => __( 'Audience Groups', 'ffcertificate' ),
		);
		$context_label  = $context_labels[ $context ] ?? $context;

		/* translators: %1$s: site name, %2$s: feature name */
		$subject = sprintf( __( '[%1$s] Access granted: %2$s', 'ffcertificate' ), $site_name, $context_label );

		/* translators: %s: user display name */
		$message = sprintf( __( 'Hello %s,', 'ffcertificate' ), $user->display_name ) . "\n\n";
		/* translators: %1$s: feature name, %2$s: site name */
		$message .= sprintf( __( 'You now have access to %1$s on %2$s.', 'ffcertificate' ), $context_label, $site_name ) . "\n\n";

		if ( $dashboard_url ) {
			/* translators: %s: dashboard URL */
			$message .= sprintf( __( 'Access your dashboard: %s', 'ffcertificate' ), $dashboard_url ) . "\n\n";
		}

		$message .= __( 'This is an automated message.', 'ffcertificate' ) . "\n";

		wp_mail( $user->user_email, $subject, $message );
	}

	/**
	 * Check if user has any certificate capabilities
	 *
	 * @since 4.4.0
	 * @param int $user_id WordPress user ID.
	 * @return bool
	 */
	public static function has_certificate_access( int $user_id ): bool {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return false;
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		foreach ( self::CERTIFICATE_CAPABILITIES as $cap ) {
			if ( $user->has_cap( $cap ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if user has any appointment capabilities
	 *
	 * @since 4.4.0
	 * @param int $user_id WordPress user ID.
	 * @return bool
	 */
	public static function has_appointment_access( int $user_id ): bool {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return false;
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		foreach ( self::APPOINTMENT_CAPABILITIES as $cap ) {
			if ( $user->has_cap( $cap ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get all FFC capabilities for a user
	 *
	 * @since 4.4.0
	 * @param int $user_id WordPress user ID.
	 * @return array<string, bool> Associative array of capability => boolean
	 */
	public static function get_user_ffc_capabilities( int $user_id ): array {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return array();
		}

		$capabilities = array();

		foreach ( self::get_all_capabilities() as $cap ) {
			$capabilities[ $cap ] = $user->has_cap( $cap );
		}

		return $capabilities;
	}

	/**
	 * Set a specific FFC capability for a user
	 *
	 * @since 4.4.0
	 * @param int    $user_id    WordPress user ID.
	 * @param string $capability Capability name.
	 * @param bool   $grant      Whether to grant (true) or revoke (false).
	 * @return bool True on success
	 */
	public static function set_user_capability( int $user_id, string $capability, bool $grant ): bool {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return false;
		}

		$all_ffc_caps = self::get_all_capabilities();

		if ( ! in_array( $capability, $all_ffc_caps, true ) ) {
			return false;
		}

		if ( $grant ) {
			$user->add_cap( $capability, true );
		} else {
			$user->add_cap( $capability, false );
		}

		if ( class_exists( '\FreeFormCertificate\Core\Debug' ) ) {
			\FreeFormCertificate\Core\Debug::log_user_manager(
				'User capability changed',
				array(
					'user_id'    => $user_id,
					'capability' => $capability,
					'granted'    => $grant,
				)
			);
		}

		return true;
	}

	/**
	 * Reset all FFC capabilities for a user to false
	 *
	 * @since 4.4.0
	 * @param int $user_id WordPress user ID.
	 * @return void
	 */
	public static function reset_user_ffc_capabilities( int $user_id ): void {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return;
		}

		foreach ( self::get_all_capabilities() as $cap ) {
			$user->add_cap( $cap, false );
		}
	}

	/**
	 * Register ffc_user role on plugin activation
	 *
	 * @return void
	 */
	public static function register_role(): void {
		$existing_role = get_role( 'ffc_user' );

		if ( $existing_role ) {
			self::upgrade_role( $existing_role );
			return;
		}

		// `ffc_user` only owns the WordPress baseline `read` cap. FFC caps are
		// granted per-user via `grant_*_capabilities()` (user-meta `add_cap(true)`).
		//
		// Historically every FFC cap was added here as `=> false` to make the
		// role's surface explicit, but that breaks multi-role users (e.g. an
		// administrator who is also an `ffc_user` for their own certificates):
		// WP's `WP_User::get_role_caps()` merges role capability maps via
		// `array_merge()`, and an explicit `false` in one role overwrites a
		// `true` from another. An absent key, by contrast, lets the other
		// role's `true` survive. See issue #86.
		add_role(
			'ffc_user',
			__( 'FFC User', 'ffcertificate' ),
			array( 'read' => true )
		);
	}

	/**
	 * Upgrade existing ffc_user role with new capabilities
	 *
	 * @since 4.4.0
	 * @param \WP_Role $role Existing role object.
	 * @return void
	 */
	private static function upgrade_role( \WP_Role $role ): void {
		// Strip every legacy `=> false` entry for FFC caps so multi-role users
		// (e.g. administrator + ffc_user) don't have admin-granted caps masked
		// by ffc_user's explicit denial. New caps are NOT added — absent ≡
		// false for `current_user_can()` on single-role users, while letting
		// `array_merge()` preserve `true` from a peer role for multi-role
		// users. See issue #86 / register_role() for the full rationale.
		foreach ( self::get_all_capabilities() as $cap ) {
			if ( isset( $role->capabilities[ $cap ] ) && false === $role->capabilities[ $cap ] ) {
				$role->remove_cap( $cap );
			}
		}
	}

	/**
	 * Remove ffc_user role on plugin deactivation
	 *
	 * @return void
	 */
	public static function remove_role(): void {
		remove_role( 'ffc_user' );
	}

	/**
	 * Register the dedicated recruitment-manager role on plugin activation.
	 *
	 * Idempotent: if the role already exists, it is upgraded so any newly
	 * introduced caps are added without disturbing other caps the admin may
	 * have manually granted. Granted caps: `read` + `ffc_manage_recruitment`.
	 *
	 * Called from {@see \FreeFormCertificate\Activator::activate()}.
	 *
	 * @since 6.0.0
	 * @return void
	 */
	public static function register_recruitment_manager_role(): void {
		$existing_role = get_role( self::RECRUITMENT_MANAGER_ROLE );

		if ( $existing_role ) {
			// Upgrade path: ensure both caps are present without overwriting
			// any admin-customized capability map.
			if ( ! isset( $existing_role->capabilities['read'] ) ) {
				$existing_role->add_cap( 'read', true );
			}
			if ( ! isset( $existing_role->capabilities['ffc_manage_recruitment'] ) ) {
				$existing_role->add_cap( 'ffc_manage_recruitment', true );
			}
			return;
		}

		add_role(
			self::RECRUITMENT_MANAGER_ROLE,
			__( 'Recruitment Manager', 'ffcertificate' ),
			array(
				'read'                   => true,
				'ffc_manage_recruitment' => true,
			)
		);
	}

	/**
	 * Remove the recruitment-manager role on plugin uninstall.
	 *
	 * Called from `uninstall.php` after the cap-stripping loop has cleared
	 * `ffc_manage_recruitment` from any user that had been granted it
	 * directly.
	 *
	 * @since 6.0.0
	 * @return void
	 */
	public static function remove_recruitment_manager_role(): void {
		remove_role( self::RECRUITMENT_MANAGER_ROLE );
	}
}

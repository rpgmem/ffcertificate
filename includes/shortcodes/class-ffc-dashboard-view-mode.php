<?php
/**
 * DashboardViewMode
 *
 * Extracted from DashboardShortcode (Sprint 18 refactoring).
 * Handles admin "view as user" mode: validates the request and renders the banner.
 *
 * @package FreeFormCertificate\Shortcodes
 * @since 4.12.19
 */

declare(strict_types=1);

namespace FreeFormCertificate\Shortcodes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dashboard View Mode.
 */
class DashboardViewMode {

	/**
	 * Get user ID for view-as mode
	 *
	 * @return int|false User ID if valid view-as mode, false otherwise
	 */
	public static function get_view_as_user_id() {
		// Check if admin is trying to view as another user.
		if ( ! self::is_view_as_request() ) {
			return false;
		}

		$target_user_id = absint( wp_unslash( $_GET['ffc_view_as_user'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is verified on the next line.
		$nonce          = \FreeFormCertificate\Core\RequestInput::get_get_string( 'ffc_view_nonce' );

		if ( ! wp_verify_nonce( $nonce, 'ffc_view_as_user_' . $target_user_id ) ) {
			return false;
		}

		// The same gate every REST route the dashboard calls applies (#2246).
		if ( ! \FreeFormCertificate\Core\ViewAs::can_view( $target_user_id ) ) {
			return false;
		}

		return $target_user_id;
	}

	/**
	 * Whether the request carries a view-as link at all, valid or not.
	 *
	 * @return bool
	 */
	public static function is_view_as_request(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence only; get_view_as_user_id() verifies the nonce.
		return isset( $_GET['ffc_view_as_user'], $_GET['ffc_view_nonce'] );
	}

	/**
	 * The notice for a view-as link that is expired, tampered with or names
	 * an account the viewer may not open (#2246).
	 *
	 * It replaces the dashboard rather than sitting above the viewer's own:
	 * falling back silently showed an administrator their own data under a
	 * link they believed was someone else's.
	 *
	 * @return string HTML.
	 */
	public static function render_invalid_link_notice(): string {
		return \FreeFormCertificate\Core\Notice::render(
			'error',
			'eye',
			__( 'This view-as link has expired or is not valid for your account. Open it again from the Users screen.', 'ffcertificate' ),
			__( 'Admin View Mode', 'ffcertificate' ),
			array(
				'role' => 'alert',
				'hook' => 'view-as-invalid',
			)
		);
	}

	/**
	 * Render admin viewing banner
	 *
	 * @param int $user_id User ID being viewed.
	 * @return string HTML output
	 */
	public static function render_admin_viewing_banner( int $user_id ): string {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return '';
		}
		$admin = wp_get_current_user();

		// Get dashboard URL without view-as parameters.
		$dashboard_page_id = get_option( 'ffc_dashboard_page_id' );
		if ( $dashboard_page_id ) {
			$dashboard_permalink = get_permalink( $dashboard_page_id );
			$exit_url            = $dashboard_permalink ? $dashboard_permalink : home_url( '/dashboard' );
		} else {
			$exit_url = home_url( '/dashboard' );
		}

		return \FreeFormCertificate\Core\Notice::render(
			'warning',
			'eye',
			sprintf(
				/* translators: 1: Admin name, 2: User name */
				esc_html__( 'You (%1$s) are viewing the dashboard as: %2$s', 'ffcertificate' ),
				'<strong>' . esc_html( $admin->display_name ) . '</strong>',
				// The address is the viewed user's personal data (#1863).
				'<strong>' . esc_html( $user->display_name ) . ' (' . esc_html( \FreeFormCertificate\Core\DocumentFormatter::mask_email( (string) $user->user_email ) ) . ')</strong>'
			),
			__( 'Admin View Mode', 'ffcertificate' ),
			array(
				'role'    => 'note',
				'html'    => true,
				'hook'    => 'admin-viewing',
				'actions' => \FreeFormCertificate\Core\Button::html(
					array(
						'label'    => __( 'Exit View Mode', 'ffcertificate' ),
						'category' => 'neutral',
						'icon'     => 'logout',
						'href'     => $exit_url,
					)
				),
			)
		);
	}
}

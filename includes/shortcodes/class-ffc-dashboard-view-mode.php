<?php
declare(strict_types=1);

/**
 * DashboardViewMode
 *
 * Extracted from DashboardShortcode (Sprint 18 refactoring).
 * Handles admin "view as user" mode: validates the request and renders the banner.
 *
 * @since 4.12.19
 */

namespace FreeFormCertificate\Shortcodes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DashboardViewMode {

    /**
     * Get user ID for view-as mode
     *
     * @return int|false User ID if valid view-as mode, false otherwise
     */
    public static function get_view_as_user_id() {
        // Check if admin is trying to view as another user
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified below via wp_verify_nonce; isset() check only.
        if ( ! isset( $_GET['ffc_view_as_user'] ) || ! isset( $_GET['ffc_view_nonce'] ) ) {
            return false;
        }

        // Only admins can use view-as mode
        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below via wp_verify_nonce.
        $target_user_id = absint( wp_unslash( $_GET['ffc_view_as_user'] ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This IS the nonce value being extracted for verification.
        $nonce = sanitize_text_field( wp_unslash( $_GET['ffc_view_nonce'] ) );

        // Verify nonce
        if ( ! wp_verify_nonce( $nonce, 'ffc_view_as_user_' . $target_user_id ) ) {
            return false;
        }

        // Verify user exists
        $user = get_user_by( 'id', $target_user_id );
        if ( ! $user ) {
            return false;
        }

        return $target_user_id;
    }

    /**
     * Render admin viewing banner
     *
     * @param int $user_id User ID being viewed
     * @return string HTML output
     */
    public static function render_admin_viewing_banner( int $user_id ): string {
        $user = get_user_by( 'id', $user_id );
        $admin = wp_get_current_user();

        // Get dashboard URL without view-as parameters
        $dashboard_page_id = get_option( 'ffc_dashboard_page_id' );
        $exit_url = $dashboard_page_id ? get_permalink( $dashboard_page_id ) : home_url( '/dashboard' );

        ob_start();
        ?>
        <div class="ffc-dashboard-notice ffc-notice-admin-viewing">
            <div class="ffc-dashboard-header">
                <div>
                    <strong>🔍 <?php esc_html_e( 'Admin View Mode', 'ffcertificate' ); ?></strong>
                    <p class="ffc-m-5-0">
                        <?php
                        echo wp_kses_post( sprintf(
                            /* translators: 1: Admin name, 2: User name */
                            esc_html__( 'You (%1$s) are viewing the dashboard as: %2$s', 'ffcertificate' ),
                            '<strong>' . esc_html( $admin->display_name ) . '</strong>',
                            '<strong>' . esc_html( $user->display_name ) . ' (' . esc_html( $user->user_email ) . ')</strong>'
                        ) );
                        ?>
                    </p>
                </div>
                <div>
                    <a href="<?php echo esc_url( $exit_url ); ?>" class="button button-primary">
                        <?php esc_html_e( 'Exit View Mode', 'ffcertificate' ); ?>
                    </a>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

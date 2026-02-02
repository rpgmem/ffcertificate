<?php
declare(strict_types=1);

/**
 * AdminNoticeManager
 *
 * Manages admin notices and redirect messages.
 * Extracted from FFC_Admin class to follow Single Responsibility Principle.
 *
 * @since 3.1.1 (Extracted from FFC_Admin)
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 * @version 1.0.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AdminNoticeManager {

    /**
     * Display admin notices based on URL parameter
     *
     * Shows success/error messages after admin actions.
     */
    public function display_notices(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only URL parameters from admin redirects.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- isset() existence check only.
        if ( ! isset( $_GET['msg'] ) ) {
            return;
        }

        $msg = sanitize_key( wp_unslash( $_GET['msg'] ) );
        $text = '';
        $type = 'updated';

        switch ( $msg ) {
            case 'trash':
                $text = __( 'Item moved to trash.', 'wp-ffcertificate' );
                break;

            case 'restore':
                $text = __( 'Item restored.', 'wp-ffcertificate' );
                break;

            case 'delete':
                $text = __( 'Item permanently deleted.', 'wp-ffcertificate' );
                break;

            case 'bulk_done':
                $text = __( 'Bulk action completed.', 'wp-ffcertificate' );
                break;

            case 'updated':
                $text = __( 'Submission updated successfully.', 'wp-ffcertificate' );
                break;

            case 'migration_success':
                $migrated = isset( $_GET['migrated'] ) ? absint( wp_unslash( $_GET['migrated'] ) ) : 0;
                $migration_name = isset( $_GET['migration_name'] ) ? sanitize_text_field( wp_unslash( urldecode( $_GET['migration_name'] ) ) ) : __( 'Migration', 'wp-ffcertificate' );
                /* translators: 1: migration name, 2: number of records migrated */
                $text = sprintf( __( '%1$s: %2$d records migrated successfully.', 'wp-ffcertificate' ), $migration_name, $migrated );
                break;

            case 'migration_error':
                $error_msg = isset( $_GET['error_msg'] ) ? sanitize_text_field( wp_unslash( urldecode( $_GET['error_msg'] ) ) ) : __( 'Unknown error', 'wp-ffcertificate' );
                $text = __( 'Migration Error: ', 'wp-ffcertificate' ) . $error_msg;
                $type = 'error';
                break;
        }

        if ( $text ) {
            echo "<div class='" . esc_attr( $type ) . " notice is-dismissible'><p>" . esc_html( $text ) . "</p></div>";
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
    }

    /**
     * Redirect with message parameter
     *
     * Removes action parameters and adds msg parameter for notice display.
     *
     * @param string $msg Message type to display
     */
    public function redirect_with_message( string $msg ): void {
        $url = remove_query_arg(
            array( 'action', 'action2', 'submission_id', 'submission', '_wpnonce' ),
            isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : ''
        );

        wp_safe_redirect( add_query_arg( 'msg', $msg, $url ) );
        exit;
    }
}

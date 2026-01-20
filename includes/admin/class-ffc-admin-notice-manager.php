<?php
/**
 * FFC_Admin_Notice_Manager
 *
 * Manages admin notices and redirect messages.
 * Extracted from FFC_Admin class to follow Single Responsibility Principle.
 *
 * @since 3.1.1 (Extracted from FFC_Admin)
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FFC_Admin_Notice_Manager {

    /**
     * Display admin notices based on URL parameter
     *
     * Shows success/error messages after admin actions.
     */
    public function display_notices() {
        if ( ! isset( $_GET['msg'] ) ) {
            return;
        }

        $msg = $_GET['msg'];
        $text = '';
        $type = 'updated';

        switch ( $msg ) {
            case 'trash':
                $text = __( 'Item moved to trash.', 'ffc' );
                break;

            case 'restore':
                $text = __( 'Item restored.', 'ffc' );
                break;

            case 'delete':
                $text = __( 'Item permanently deleted.', 'ffc' );
                break;

            case 'bulk_done':
                $text = __( 'Bulk action completed.', 'ffc' );
                break;

            case 'updated':
                $text = __( 'Submission updated successfully.', 'ffc' );
                break;

            case 'migration_success':
                $migrated = isset( $_GET['migrated'] ) ? intval( $_GET['migrated'] ) : 0;
                $migration_name = isset( $_GET['migration_name'] ) ? urldecode( $_GET['migration_name'] ) : __( 'Migration', 'ffc' );
                $text = sprintf( __( '%s: %d records migrated successfully.', 'ffc' ), $migration_name, $migrated );
                break;

            case 'migration_error':
                $error_msg = isset( $_GET['error_msg'] ) ? urldecode( $_GET['error_msg'] ) : __( 'Unknown error', 'ffc' );
                $text = __( 'Migration Error: ', 'ffc' ) . $error_msg;
                $type = 'error';
                break;
        }

        if ( $text ) {
            echo "<div class='$type notice is-dismissible'><p>" . esc_html( $text ) . "</p></div>";
        }
    }

    /**
     * Redirect with message parameter
     *
     * Removes action parameters and adds msg parameter for notice display.
     *
     * @param string $msg Message type to display
     */
    public function redirect_with_message( $msg ) {
        $url = remove_query_arg(
            array( 'action', 'action2', 'submission_id', 'submission', '_wpnonce' ),
            $_SERVER['REQUEST_URI']
        );

        wp_redirect( add_query_arg( 'msg', $msg, $url ) );
        exit;
    }
}

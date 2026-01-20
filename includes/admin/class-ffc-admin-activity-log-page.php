<?php
/**
 * FFC_Admin_Activity_Log_Page
 * Displays activity logs with filtering and pagination
 *
 * @since 3.1.1
 * @version 3.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Load FFC_Activity_Log class
if ( ! class_exists( 'FFC_Activity_Log' ) ) {
    require_once FFC_PLUGIN_DIR . 'includes/core/class-ffc-activity-log.php';
}

class FFC_Admin_Activity_Log_Page {

    /**
     * Register admin menu
     */
    public function register_menu() {
        add_submenu_page(
            'edit.php?post_type=ffc_form',
            __( 'Activity Log', 'ffc' ),
            __( 'Activity Log', 'ffc' ),
            'manage_options',
            'ffc-activity-log',
            array( $this, 'render_page' )
        );
    }

    /**
     * Render activity log page
     */
    public function render_page() {
        // Check if Activity Log is enabled
        $settings = get_option( 'ffc_settings', array() );
        $is_enabled = isset( $settings['enable_activity_log'] ) && $settings['enable_activity_log'] == 1;

        if ( ! $is_enabled ) {
            $this->render_disabled_notice();
            return;
        }

        // Get filter parameters
        $current_page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $per_page = 50;
        $level = isset( $_GET['level'] ) ? sanitize_key( $_GET['level'] ) : '';
        $action = isset( $_GET['log_action'] ) ? sanitize_text_field( $_GET['log_action'] ) : '';
        $search = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';

        // Get logs
        $args = array(
            'limit' => $per_page,
            'offset' => ( $current_page - 1 ) * $per_page,
            'orderby' => 'created_at',
            'order' => 'DESC'
        );

        if ( $level ) {
            $args['level'] = $level;
        }

        if ( $action ) {
            $args['action'] = $action;
        }

        if ( $search ) {
            $args['search'] = $search;
        }

        $logs = FFC_Activity_Log::get_activities( $args );
        $total_logs = FFC_Activity_Log::count_activities( $args );
        $total_pages = ceil( $total_logs / $per_page );

        // Get unique actions for filter
        $unique_actions = $this->get_unique_actions();

        // Render view
        $view_file = FFC_PLUGIN_DIR . 'includes/admin/views/ffc-admin-activity-log.php';

        if ( file_exists( $view_file ) ) {
            include $view_file;
        } else {
            echo '<div class="wrap"><h1>' . esc_html__( 'Activity Log', 'ffc' ) . '</h1>';
            echo '<div class="notice notice-error"><p>' . esc_html__( 'View file not found.', 'ffc' ) . '</p></div></div>';
        }
    }

    /**
     * Render disabled notice
     */
    private function render_disabled_notice() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Activity Log', 'ffc' ); ?></h1>
            <div class="notice notice-warning">
                <p>
                    <strong><?php esc_html_e( 'Activity Log is currently disabled.', 'ffc' ); ?></strong>
                </p>
                <p>
                    <?php esc_html_e( 'To enable activity logging, go to:', 'ffc' ); ?>
                    <a href="<?php echo admin_url( 'edit.php?post_type=ffc_form&page=ffc-settings&tab=general' ); ?>">
                        <?php esc_html_e( 'Settings > General > Activity Log Settings', 'ffc' ); ?>
                    </a>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * Get unique actions from database
     */
    private function get_unique_actions() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ffc_activity_log';

        $actions = $wpdb->get_col(
            "SELECT DISTINCT action FROM {$table_name} ORDER BY action ASC"
        );

        return $actions;
    }

    /**
     * Get human-readable action name
     */
    public static function get_action_label( $action ) {
        $labels = array(
            'submission_created' => __( 'Submission Created', 'ffc' ),
            'submission_updated' => __( 'Submission Updated', 'ffc' ),
            'submission_deleted' => __( 'Submission Deleted', 'ffc' ),
            'data_accessed' => __( 'Data Accessed', 'ffc' ),
            'access_denied' => __( 'Access Denied', 'ffc' ),
            'settings_changed' => __( 'Settings Changed', 'ffc' )
        );

        return isset( $labels[ $action ] ) ? $labels[ $action ] : ucwords( str_replace( '_', ' ', $action ) );
    }

    /**
     * Get level badge HTML
     */
    public static function get_level_badge( $level ) {
        $classes = array(
            'info' => 'ffc-badge-info',
            'warning' => 'ffc-badge-warning',
            'error' => 'ffc-badge-error',
            'debug' => 'ffc-badge-debug'
        );

        $class = isset( $classes[ $level ] ) ? $classes[ $level ] : 'ffc-badge-info';

        return '<span class="ffc-badge ' . esc_attr( $class ) . '">' . esc_html( strtoupper( $level ) ) . '</span>';
    }
}

<?php
/**
 * Activity Log Page View
 * @version 3.1.1
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables scoped to this file

$ffcertificate_base_url = admin_url( 'edit.php?post_type=ffc_form&page=ffc-activity-log' );
?>

<div class="wrap">
    <h1 class="wp-heading-inline ffc-icon-clipboard"><?php esc_html_e( 'Activity Log', 'ffcertificate' ); ?></h1>

    <p class="description">
        <?php esc_html_e( 'Activity logs track important actions for audit and LGPD compliance.', 'ffcertificate' ); ?>
    </p>

    <!-- Filters -->
    <div class="tablenav top">
        <div class="alignleft actions">
            <form method="get">
                <input type="hidden" name="post_type" value="ffc_form">
                <input type="hidden" name="page" value="ffc-activity-log">

                <!-- Level Filter -->
                <select name="level" id="filter-by-level">
                    <option value=""><?php esc_html_e( 'All Levels', 'ffcertificate' ); ?></option>
                    <option value="info" <?php selected( $level, 'info' ); ?>><?php esc_html_e( 'Info', 'ffcertificate' ); ?></option>
                    <option value="warning" <?php selected( $level, 'warning' ); ?>><?php esc_html_e( 'Warning', 'ffcertificate' ); ?></option>
                    <option value="error" <?php selected( $level, 'error' ); ?>><?php esc_html_e( 'Error', 'ffcertificate' ); ?></option>
                    <option value="debug" <?php selected( $level, 'debug' ); ?>><?php esc_html_e( 'Debug', 'ffcertificate' ); ?></option>
                </select>

                <!-- Action Filter -->
                <?php if ( ! empty( $unique_actions ) ) : ?>
                    <select name="log_action" id="filter-by-action">
                        <option value=""><?php esc_html_e( 'All Actions', 'ffcertificate' ); ?></option>
                        <?php foreach ( $unique_actions as $ffcertificate_act ) : ?>
                            <option value="<?php echo esc_attr( $ffcertificate_act ); ?>" <?php selected( $action, $ffcertificate_act ); ?>>
                                <?php echo esc_html( \FreeFormCertificate\Admin\AdminActivityLogPage::get_action_label( $ffcertificate_act ) ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'ffcertificate' ); ?>">

                <?php if ( $level || $action || $search ) : ?>
                    <a href="<?php echo esc_url( $ffcertificate_base_url ); ?>" class="button">
                        <?php esc_html_e( 'Clear Filters', 'ffcertificate' ); ?>
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Search Box -->
        <div class="alignright actions">
            <form method="get">
                <input type="hidden" name="post_type" value="ffc_form">
                <input type="hidden" name="page" value="ffc-activity-log">
                <?php if ( $level ) : ?>
                    <input type="hidden" name="level" value="<?php echo esc_attr( $level ); ?>">
                <?php endif; ?>
                <?php if ( $action ) : ?>
                    <input type="hidden" name="log_action" value="<?php echo esc_attr( $action ); ?>">
                <?php endif; ?>
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search logs...', 'ffcertificate' ); ?>">
                <input type="submit" class="button" value="<?php esc_attr_e( 'Search', 'ffcertificate' ); ?>">
            </form>
        </div>
    </div>

    <!-- Logs Table -->
    <?php if ( empty( $logs ) ) : ?>
        <div class="notice notice-info">
            <p><?php esc_html_e( 'No activity logs found.', 'ffcertificate' ); ?></p>
        </div>
    <?php else : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th width="12%"><?php esc_html_e( 'Date/Time', 'ffcertificate' ); ?></th>
                    <th width="10%"><?php esc_html_e( 'Level', 'ffcertificate' ); ?></th>
                    <th width="18%"><?php esc_html_e( 'Action', 'ffcertificate' ); ?></th>
                    <th width="15%"><?php esc_html_e( 'User', 'ffcertificate' ); ?></th>
                    <th width="12%"><?php esc_html_e( 'IP Address', 'ffcertificate' ); ?></th>
                    <th width="33%"><?php esc_html_e( 'Context', 'ffcertificate' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $logs as $ffcertificate_log ) : ?>
                    <tr>
                        <!-- Date/Time -->
                        <td>
                            <strong><?php echo esc_html( date_i18n( 'Y-m-d', strtotime( $ffcertificate_log['created_at'] ) ) ); ?></strong><br>
                            <span class="description"><?php echo esc_html( date_i18n( 'H:i:s', strtotime( $ffcertificate_log['created_at'] ) ) ); ?></span>
                        </td>

                        <!-- Level -->
                        <td>
                            <?php echo wp_kses_post( \FreeFormCertificate\Admin\AdminActivityLogPage::get_level_badge( $ffcertificate_log['level'] ) ); ?>
                        </td>

                        <!-- Action -->
                        <td>
                            <strong><?php echo esc_html( \FreeFormCertificate\Admin\AdminActivityLogPage::get_action_label( $ffcertificate_log['action'] ) ); ?></strong><br>
                            <code class="description"><?php echo esc_html( $ffcertificate_log['action'] ); ?></code>
                        </td>

                        <!-- User -->
                        <td>
                            <?php
                            if ( $ffcertificate_log['user_id'] > 0 ) {
                                $ffcertificate_user = get_userdata( (int) $ffcertificate_log['user_id'] );
                                if ( $ffcertificate_user ) {
                                    echo '<strong>' . esc_html( $ffcertificate_user->display_name ) . '</strong><br>';
                                    echo '<span class="description">' . esc_html( $ffcertificate_user->user_login ) . '</span>';
                                } else {
                                    /* translators: %d: user ID */
                                    echo '<span class="description">' . esc_html( sprintf( __( 'User #%d (deleted)', 'ffcertificate' ), $ffcertificate_log['user_id'] ) ) . '</span>';
                                }
                            } else {
                                echo '<span class="description">' . esc_html__( 'System / Anonymous', 'ffcertificate' ) . '</span>';
                            }
                            ?>
                        </td>

                        <!-- IP Address -->
                        <td>
                            <code><?php echo esc_html( $ffcertificate_log['user_ip'] ); ?></code>
                        </td>

                        <!-- Context -->
                        <td>
                            <?php if ( ! empty( $ffcertificate_log['context'] ) ) : ?>
                                <details>
                                    <summary class="ffc-log-summary">
                                        <?php esc_html_e( 'View Details', 'ffcertificate' ); ?> ▼
                                    </summary>
                                    <pre class="ffc-log-pre"><?php echo esc_html( wp_json_encode( $ffcertificate_log['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre>
                                </details>
                            <?php else : ?>
                                <span class="description">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php if ( $total_pages > 1 ) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php
                        /* translators: %s: number of logs */
                        printf( esc_html( _n( '%s log', '%s logs', $total_logs, 'ffcertificate' ) ), esc_html( number_format_i18n( $total_logs ) ) );
                        ?>
                    </span>
                    <?php
                    $ffcertificate_pagination_args = array(
                        'base' => add_query_arg( 'paged', '%#%' ),
                        'format' => '',
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                        'total' => $total_pages,
                        'current' => $current_page
                    );

                    echo wp_kses_post( paginate_links( $ffcertificate_pagination_args ) );
                    ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Stats Summary -->
    <div class="card ffc-activity-card">
        <h2><?php esc_html_e( 'Activity Summary (Last 30 Days)', 'ffcertificate' ); ?></h2>
        <?php
        $ffcertificate_stats = \FreeFormCertificate\Core\ActivityLog::get_stats( 30 );
        ?>
        <p>
            <strong><?php esc_html_e( 'Total Activities:', 'ffcertificate' ); ?></strong> <?php echo esc_html( number_format_i18n( $ffcertificate_stats['total'] ) ); ?>
        </p>

        <?php if ( ! empty( $ffcertificate_stats['by_level'] ) ) : ?>
            <p><strong><?php esc_html_e( 'By Level:', 'ffcertificate' ); ?></strong></p>
            <ul class="ffc-ml-20">
                <?php foreach ( $ffcertificate_stats['by_level'] as $ffcertificate_level_stat ) : ?>
                    <li>
                        <?php echo wp_kses_post( \FreeFormCertificate\Admin\AdminActivityLogPage::get_level_badge( $ffcertificate_level_stat['level'] ) ); ?>
                        <?php echo esc_html( number_format_i18n( $ffcertificate_level_stat['count'] ) ); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ( ! empty( $ffcertificate_stats['top_actions'] ) ) : ?>
            <p><strong><?php esc_html_e( 'Top Actions:', 'ffcertificate' ); ?></strong></p>
            <ul class="ffc-ml-20">
                <?php foreach ( array_slice( $ffcertificate_stats['top_actions'], 0, 5 ) as $ffcertificate_action_stat ) : ?>
                    <li>
                        <strong><?php echo esc_html( \FreeFormCertificate\Admin\AdminActivityLogPage::get_action_label( $ffcertificate_action_stat['action'] ) ); ?>:</strong>
                        <?php echo esc_html( number_format_i18n( $ffcertificate_action_stat['count'] ) ); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

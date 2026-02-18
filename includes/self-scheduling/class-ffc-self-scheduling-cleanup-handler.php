<?php
declare(strict_types=1);

/**
 * Self-Scheduling Editor — Appointment Cleanup AJAX + Metabox
 *
 * Extracted from SelfSchedulingEditor (Sprint 15 refactoring).
 * Handles the AJAX endpoint for bulk-deleting appointments
 * and renders the sidebar cleanup metabox with appointment counts.
 *
 * @since 4.12.16
 */

namespace FreeFormCertificate\SelfScheduling;

if (!defined('ABSPATH')) exit;


class SelfSchedulingCleanupHandler {

    /**
     * Register AJAX hook
     */
    public function __construct() {
        add_action('wp_ajax_ffc_cleanup_appointments', array($this, 'handle_cleanup_appointments'));
    }

    /**
     * Handle appointment cleanup AJAX request
     *
     * @return void
     */
    public function handle_cleanup_appointments(): void {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'ffc_cleanup_appointments_nonce')) {
            wp_send_json_error(array(
                'message' => __('Security check failed', 'ffcertificate')
            ));
        }

        // Verify permissions
        if (!\FreeFormCertificate\Core\Utils::current_user_can_manage()) {
            wp_send_json_error(array(
                'message' => __('You do not have permission to perform this action', 'ffcertificate')
            ));
        }

        // Get parameters
        $calendar_id = isset($_POST['calendar_id']) ? absint(wp_unslash($_POST['calendar_id'])) : 0;
        $cleanup_action = isset($_POST['cleanup_action']) ? sanitize_text_field(wp_unslash($_POST['cleanup_action'])) : '';

        if (!$calendar_id || !$cleanup_action) {
            wp_send_json_error(array(
                'message' => __('Invalid parameters', 'ffcertificate')
            ));
        }

        // Verify calendar exists
        $calendar_repository = new \FreeFormCertificate\Repositories\CalendarRepository();
        $calendar = $calendar_repository->findById($calendar_id);

        if (!$calendar) {
            wp_send_json_error(array(
                'message' => __('Calendar not found', 'ffcertificate')
            ));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ffc_self_scheduling_appointments';
        $today = current_time('Y-m-d');

        $deleted = 0;

        // Build query based on action
        switch ($cleanup_action) {
            case 'all':
                // Delete all appointments for this calendar
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $deleted = $wpdb->delete($table, ['calendar_id' => $calendar_id], ['%d']);
                $message = sprintf(
                    /* translators: %d: number of deleted appointments */
                    __('Successfully deleted %d appointment(s).', 'ffcertificate'),
                    $deleted
                );
                break;

            case 'old':
                // Delete appointments before today
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $deleted = $wpdb->query($wpdb->prepare(
                    "DELETE FROM %i WHERE calendar_id = %d AND appointment_date < %s",
                    $table,
                    $calendar_id,
                    $today
                ));
                $message = sprintf(
                    /* translators: %d: number of deleted past appointments */
                    __('Successfully deleted %d past appointment(s).', 'ffcertificate'),
                    $deleted
                );
                break;

            case 'future':
                // Delete appointments today and after
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $deleted = $wpdb->query($wpdb->prepare(
                    "DELETE FROM %i WHERE calendar_id = %d AND appointment_date >= %s",
                    $table,
                    $calendar_id,
                    $today
                ));
                $message = sprintf(
                    /* translators: %d: number of deleted future appointments */
                    __('Successfully deleted %d future appointment(s).', 'ffcertificate'),
                    $deleted
                );
                break;

            case 'cancelled':
                // Delete only cancelled appointments
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $deleted = $wpdb->delete($table, [
                    'calendar_id' => $calendar_id,
                    'status' => 'cancelled'
                ], ['%d', '%s']);
                $message = sprintf(
                    /* translators: %d: number of deleted cancelled appointments */
                    __('Successfully deleted %d cancelled appointment(s).', 'ffcertificate'),
                    $deleted
                );
                break;

            default:
                wp_send_json_error(array(
                    'message' => __('Invalid cleanup action', 'ffcertificate')
                ));
        }

        // Log the action
        \FreeFormCertificate\Core\Utils::debug_log('Appointments cleaned up', array(
            'calendar_id' => $calendar_id,
            'calendar_title' => $calendar['title'],
            'action' => $cleanup_action,
            'deleted_count' => $deleted,
            'user_id' => get_current_user_id()
        ));

        wp_send_json_success(array(
            'message' => $message,
            'deleted' => $deleted
        ));
    }

    /**
     * Render cleanup appointments metabox
     *
     * Allows admins to bulk delete appointments based on criteria:
     * - All appointments
     * - Old/past appointments
     * - Future appointments
     * - Cancelled appointments
     *
     * @param object $post
     * @return void
     */
    public function render_cleanup_metabox(object $post): void {
        // Get calendar ID from database
        $calendar_repository = new \FreeFormCertificate\Repositories\CalendarRepository();
        $calendar = $calendar_repository->findByPostId($post->ID);

        if (!$calendar) {
            echo '<p>' . esc_html__('Calendar not found in database. Save the calendar first.', 'ffcertificate') . '</p>';
            return;
        }

        $calendar_id = (int) $calendar['id'];
        $appointment_repo = new \FreeFormCertificate\Repositories\AppointmentRepository();

        // Count appointments by category
        $today = current_time('Y-m-d');

        $count_all = $appointment_repo->count(['calendar_id' => $calendar_id]);

        // Count old appointments (before today)
        global $wpdb;
        $table = $wpdb->prefix . 'ffc_self_scheduling_appointments';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $count_old = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE calendar_id = %d AND appointment_date < %s",
            $table,
            $calendar_id,
            $today
        ));

        // Count future appointments (today and after)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $count_future = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE calendar_id = %d AND appointment_date >= %s",
            $table,
            $calendar_id,
            $today
        ));

        // Count cancelled appointments
        $count_cancelled = $appointment_repo->count([
            'calendar_id' => $calendar_id,
            'status' => 'cancelled'
        ]);

        wp_nonce_field('ffc_cleanup_appointments_nonce', 'ffc_cleanup_appointments_nonce');
        ?>
        <div class="ffc-cleanup-appointments">
            <p class="description">
                <?php esc_html_e('Permanently delete appointments from this calendar. This action cannot be undone.', 'ffcertificate'); ?>
            </p>

            <div class="ffc-cleanup-stats" style="margin: 15px 0;">
                <table class="widefat" style="border: none;">
                    <tr>
                        <td><strong><?php esc_html_e('Total:', 'ffcertificate'); ?></strong></td>
                        <td><?php echo esc_html((string) $count_all); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Past:', 'ffcertificate'); ?></strong></td>
                        <td><?php echo esc_html((string) $count_old); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Future:', 'ffcertificate'); ?></strong></td>
                        <td><?php echo esc_html((string) $count_future); ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Cancelled:', 'ffcertificate'); ?></strong></td>
                        <td><?php echo esc_html((string) $count_cancelled); ?></td>
                    </tr>
                </table>
            </div>

            <?php if ($count_all > 0) : ?>
                <div class="ffc-cleanup-actions">
                    <p><strong><?php esc_html_e('Delete appointments:', 'ffcertificate'); ?></strong></p>

                    <?php if ($count_cancelled > 0) : ?>
                        <button type="button"
                                class="button ffc-cleanup-btn"
                                data-action="cancelled"
                                data-calendar-id="<?php echo esc_attr((string) $calendar_id); ?>"
                                style="width: 100%; margin-bottom: 5px;">
                            <span class="ffc-icon-delete"></span><?php
                            /* translators: %d: number of cancelled appointments */
                            printf(esc_html__('Cancelled (%d)', 'ffcertificate'), intval($count_cancelled)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- printf with esc_html__ and %d integer format ?>
                        </button>
                    <?php endif; ?>

                    <?php if ($count_old > 0) : ?>
                        <button type="button"
                                class="button ffc-cleanup-btn"
                                data-action="old"
                                data-calendar-id="<?php echo esc_attr((string) $calendar_id); ?>"
                                style="width: 100%; margin-bottom: 5px;">
                            <span class="dashicons dashicons-calendar"></span> <?php
                            /* translators: %d: number of past appointments */
                            printf(esc_html__('Past (%d)', 'ffcertificate'), intval($count_old)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- printf with esc_html__ and %d integer format ?>
                        </button>
                    <?php endif; ?>

                    <?php if ($count_future > 0) : ?>
                        <button type="button"
                                class="button ffc-cleanup-btn"
                                data-action="future"
                                data-calendar-id="<?php echo esc_attr((string) $calendar_id); ?>"
                                style="width: 100%; margin-bottom: 5px;">
                            <span class="ffc-icon-skip"></span><?php
                            /* translators: %d: number of future appointments */
                            printf(esc_html__('Future (%d)', 'ffcertificate'), intval($count_future)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- printf with esc_html__ and %d integer format ?>
                        </button>
                    <?php endif; ?>

                    <button type="button"
                            class="button button-link-delete ffc-cleanup-btn"
                            data-action="all"
                            data-calendar-id="<?php echo esc_attr((string) $calendar_id); ?>"
                            style="width: 100%; margin-top: 10px;">
                        <?php
                        /* translators: %d: total number of appointments */
                        printf(esc_html__('All Appointments (%d)', 'ffcertificate'), intval($count_all)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- printf with esc_html__ and %d integer format
                        ?>
                    </button>
                </div>

                <p class="description" style="margin-top: 10px; color: #d63638;">
                    <span class="ffc-icon-warning"></span><?php esc_html_e('Warning: This action is permanent and cannot be undone!', 'ffcertificate'); ?>
                </p>
            <?php else : ?>
                <p><?php esc_html_e('No appointments to clean up.', 'ffcertificate'); ?></p>
            <?php endif; ?>
        </div>

        <!-- Cleanup scripts in ffc-calendar-editor.js -->
        <?php
    }
}

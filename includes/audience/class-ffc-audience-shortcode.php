<?php
declare(strict_types=1);

/**
 * Audience Shortcode
 *
 * Renders the audience booking calendar via [ffc_audience] shortcode.
 * Displays a monthly calendar grid with available slots and booking capabilities.
 *
 * Usage:
 * [ffc_audience]                          - Shows all calendars user has access to
 * [ffc_audience schedule_id="1"]          - Shows specific calendar
 * [ffc_audience environment_id="1"]       - Shows specific environment
 *
 * @since 4.5.0
 * @package FreeFormCertificate\Audience
 */

namespace FreeFormCertificate\Audience;

if (!defined('ABSPATH')) {
    exit;
}

class AudienceShortcode {

    /**
     * Register shortcode
     *
     * @return void
     */
    public static function init(): void {
        add_shortcode('ffc_audience', array(__CLASS__, 'render'));
    }

    /**
     * Render the shortcode
     *
     * @param array|string $atts Shortcode attributes
     * @return string HTML output
     */
    public static function render($atts = array()): string {
        // Ensure $atts is an array
        if (!is_array($atts)) {
            $atts = array();
        }

        // Parse attributes
        $atts = shortcode_atts(array(
            'schedule_id' => 0,
            'environment_id' => 0,
            'view' => 'month', // month, week
        ), $atts, 'ffc_audience');

        // Check if user is logged in
        if (!is_user_logged_in()) {
            return self::render_login_required();
        }

        $user_id = get_current_user_id();

        // Get schedules user can access
        $schedules = self::get_user_schedules($user_id, absint($atts['schedule_id']));

        if (empty($schedules)) {
            return self::render_no_access();
        }

        // Enqueue assets
        self::enqueue_assets();

        // Build configuration for JavaScript
        $config = array(
            'scheduleId' => absint($atts['schedule_id']),
            'environmentId' => absint($atts['environment_id']),
            'view' => sanitize_text_field($atts['view']),
            'schedules' => array_map(function($s) {
                return array(
                    'id' => (int) $s->id,
                    'name' => $s->name,
                    'environments' => self::get_schedule_environments((int) $s->id),
                    'futureDaysLimit' => isset($s->future_days_limit) ? (int) $s->future_days_limit : null,
                );
            }, $schedules),
            'canBook' => self::can_user_book($user_id, $schedules),
            'audiences' => self::get_user_audiences($user_id),
        );

        ob_start();
        ?>
        <div class="ffc-audience-calendar" id="ffc-audience-calendar" data-config="<?php echo esc_attr(wp_json_encode($config)); ?>">
            <!-- Header -->
            <div class="ffc-calendar-header">
                <div class="ffc-calendar-nav">
                    <button type="button" class="ffc-nav-btn ffc-prev-month" aria-label="<?php esc_attr_e('Previous month', 'wp-ffcertificate'); ?>">
                        &lsaquo;
                    </button>
                    <h2 class="ffc-current-month"></h2>
                    <button type="button" class="ffc-nav-btn ffc-next-month" aria-label="<?php esc_attr_e('Next month', 'wp-ffcertificate'); ?>">
                        &rsaquo;
                    </button>
                    <button type="button" class="ffc-nav-btn ffc-today-btn">
                        <?php esc_html_e('Today', 'wp-ffcertificate'); ?>
                    </button>
                </div>

                <div class="ffc-calendar-filters">
                    <?php if (count($schedules) > 1) : ?>
                        <select class="ffc-schedule-select" id="ffc-schedule-select">
                            <option value=""><?php esc_html_e('All Calendars', 'wp-ffcertificate'); ?></option>
                            <?php foreach ($schedules as $schedule) : ?>
                                <option value="<?php echo esc_attr($schedule->id); ?>" <?php selected(absint($atts['schedule_id']), $schedule->id); ?>>
                                    <?php echo esc_html($schedule->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>

                    <select class="ffc-environment-select" id="ffc-environment-select">
                        <option value=""><?php esc_html_e('All Environments', 'wp-ffcertificate'); ?></option>
                        <!-- Populated by JavaScript -->
                    </select>
                </div>
            </div>

            <!-- Calendar Grid -->
            <div class="ffc-calendar-grid">
                <!-- Day headers -->
                <div class="ffc-calendar-weekdays">
                    <div class="ffc-weekday"><?php esc_html_e('Sun', 'wp-ffcertificate'); ?></div>
                    <div class="ffc-weekday"><?php esc_html_e('Mon', 'wp-ffcertificate'); ?></div>
                    <div class="ffc-weekday"><?php esc_html_e('Tue', 'wp-ffcertificate'); ?></div>
                    <div class="ffc-weekday"><?php esc_html_e('Wed', 'wp-ffcertificate'); ?></div>
                    <div class="ffc-weekday"><?php esc_html_e('Thu', 'wp-ffcertificate'); ?></div>
                    <div class="ffc-weekday"><?php esc_html_e('Fri', 'wp-ffcertificate'); ?></div>
                    <div class="ffc-weekday"><?php esc_html_e('Sat', 'wp-ffcertificate'); ?></div>
                </div>

                <!-- Calendar days (populated by JavaScript) -->
                <div class="ffc-calendar-days" id="ffc-calendar-days">
                    <div class="ffc-loading">
                        <?php esc_html_e('Loading calendar...', 'wp-ffcertificate'); ?>
                    </div>
                </div>
            </div>

            <!-- Legend -->
            <div class="ffc-calendar-legend">
                <span class="ffc-legend-item"><span class="ffc-legend-dot ffc-available"></span> <?php esc_html_e('Available', 'wp-ffcertificate'); ?></span>
                <span class="ffc-legend-item"><span class="ffc-legend-dot ffc-booked"></span> <?php esc_html_e('Booked', 'wp-ffcertificate'); ?></span>
                <span class="ffc-legend-item"><span class="ffc-legend-dot ffc-holiday"></span> <?php esc_html_e('Holiday', 'wp-ffcertificate'); ?></span>
                <span class="ffc-legend-item"><span class="ffc-legend-dot ffc-closed"></span> <?php esc_html_e('Closed', 'wp-ffcertificate'); ?></span>
            </div>
        </div>

        <!-- Booking Modal -->
        <div class="ffc-modal" id="ffc-booking-modal" style="display: none;">
            <div class="ffc-modal-backdrop"></div>
            <div class="ffc-modal-content">
                <div class="ffc-modal-header">
                    <h3><?php esc_html_e('New Booking', 'wp-ffcertificate'); ?></h3>
                    <button type="button" class="ffc-modal-close">&times;</button>
                </div>
                <div class="ffc-modal-body">
                    <form id="ffc-booking-form">
                        <input type="hidden" name="booking_date" id="booking-date">

                        <div class="ffc-form-group">
                            <label><?php esc_html_e('Date', 'wp-ffcertificate'); ?></label>
                            <p class="ffc-booking-date-display"></p>
                        </div>

                        <div class="ffc-form-group">
                            <label for="booking-environment-id"><?php esc_html_e('Environment', 'wp-ffcertificate'); ?> *</label>
                            <select name="environment_id" id="booking-environment-id" required>
                                <!-- Populated by JavaScript -->
                            </select>
                        </div>

                        <div class="ffc-form-row">
                            <div class="ffc-form-group">
                                <label for="booking-start-time"><?php esc_html_e('Start Time', 'wp-ffcertificate'); ?> *</label>
                                <input type="time" name="start_time" id="booking-start-time" required>
                            </div>
                            <div class="ffc-form-group">
                                <label for="booking-end-time"><?php esc_html_e('End Time', 'wp-ffcertificate'); ?> *</label>
                                <input type="time" name="end_time" id="booking-end-time" required>
                            </div>
                        </div>

                        <div class="ffc-form-group">
                            <label for="booking-type"><?php esc_html_e('Booking Type', 'wp-ffcertificate'); ?> *</label>
                            <select name="booking_type" id="booking-type" required>
                                <option value="audience"><?php esc_html_e('Audience Groups', 'wp-ffcertificate'); ?></option>
                                <option value="individual"><?php esc_html_e('Individual Users', 'wp-ffcertificate'); ?></option>
                            </select>
                        </div>

                        <div class="ffc-form-group" id="audience-select-group">
                            <label for="booking-audiences"><?php esc_html_e('Select Audiences', 'wp-ffcertificate'); ?> *</label>
                            <select name="audience_ids[]" id="booking-audiences" multiple class="ffc-multiselect">
                                <!-- Populated by JavaScript -->
                            </select>
                        </div>

                        <div class="ffc-form-group" id="user-select-group" style="display: none;">
                            <label for="booking-users"><?php esc_html_e('Select Users', 'wp-ffcertificate'); ?> *</label>
                            <input type="text" id="booking-user-search" placeholder="<?php esc_attr_e('Search users...', 'wp-ffcertificate'); ?>">
                            <div id="booking-user-results" class="ffc-user-results"></div>
                            <div id="booking-selected-users" class="ffc-selected-users"></div>
                            <input type="hidden" name="user_ids" id="booking-user-ids">
                        </div>

                        <div class="ffc-form-group">
                            <label for="booking-description"><?php esc_html_e('Description', 'wp-ffcertificate'); ?> *</label>
                            <textarea name="description" id="booking-description" rows="3" required minlength="15" maxlength="300"
                                      placeholder="<?php esc_attr_e('Describe the purpose of this booking (15-300 characters)', 'wp-ffcertificate'); ?>"></textarea>
                            <span class="ffc-char-count"><span id="desc-char-count">0</span>/300</span>
                        </div>

                        <!-- Conflict Warning -->
                        <div class="ffc-conflict-warning" id="ffc-conflict-warning" style="display: none;">
                            <span class="dashicons dashicons-warning"></span>
                            <p><?php esc_html_e('Warning: Some selected members already have bookings at this time. You may proceed with the booking.', 'wp-ffcertificate'); ?></p>
                            <div class="ffc-conflict-details" id="ffc-conflict-details"></div>
                        </div>
                    </form>
                </div>
                <div class="ffc-modal-footer">
                    <button type="button" class="ffc-btn ffc-btn-secondary ffc-modal-cancel">
                        <?php esc_html_e('Cancel', 'wp-ffcertificate'); ?>
                    </button>
                    <button type="button" class="ffc-btn ffc-btn-primary" id="ffc-check-conflicts-btn">
                        <?php esc_html_e('Check Conflicts', 'wp-ffcertificate'); ?>
                    </button>
                    <button type="button" class="ffc-btn ffc-btn-success" id="ffc-create-booking-btn" style="display: none;">
                        <?php esc_html_e('Create Booking', 'wp-ffcertificate'); ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- Day Detail Modal -->
        <div class="ffc-modal" id="ffc-day-modal" style="display: none;">
            <div class="ffc-modal-backdrop"></div>
            <div class="ffc-modal-content ffc-modal-lg">
                <div class="ffc-modal-header">
                    <h3 class="ffc-day-modal-title"></h3>
                    <button type="button" class="ffc-modal-close">&times;</button>
                </div>
                <div class="ffc-modal-body">
                    <div class="ffc-day-filter">
                        <label>
                            <input type="checkbox" id="ffc-show-cancelled">
                            <?php esc_html_e('Show cancelled bookings', 'wp-ffcertificate'); ?>
                        </label>
                    </div>
                    <div class="ffc-day-bookings" id="ffc-day-bookings">
                        <div class="ffc-loading"><?php esc_html_e('Loading bookings...', 'wp-ffcertificate'); ?></div>
                    </div>
                </div>
                <div class="ffc-modal-footer">
                    <button type="button" class="ffc-btn ffc-btn-secondary ffc-modal-cancel">
                        <?php esc_html_e('Close', 'wp-ffcertificate'); ?>
                    </button>
                    <button type="button" class="ffc-btn ffc-btn-primary" id="ffc-new-booking-btn">
                        <?php esc_html_e('New Booking', 'wp-ffcertificate'); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Render login required message
     *
     * @return string HTML output
     */
    private static function render_login_required(): string {
        ob_start();
        ?>
        <div class="ffc-audience-notice ffc-notice-warning">
            <p><?php esc_html_e('You must be logged in to view the calendar.', 'wp-ffcertificate'); ?></p>
            <p>
                <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>" class="ffc-btn ffc-btn-primary">
                    <?php esc_html_e('Login', 'wp-ffcertificate'); ?>
                </a>
            </p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render no access message
     *
     * @return string HTML output
     */
    private static function render_no_access(): string {
        ob_start();
        ?>
        <div class="ffc-audience-notice ffc-notice-info">
            <p><?php esc_html_e('You do not have access to any calendars.', 'wp-ffcertificate'); ?></p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Get schedules user can access
     *
     * @param int $user_id User ID
     * @param int $specific_id Specific schedule ID (0 for all)
     * @return array<object>
     */
    private static function get_user_schedules(int $user_id, int $specific_id = 0): array {
        // Admin can access all
        if (user_can($user_id, 'manage_options')) {
            if ($specific_id > 0) {
                $schedule = AudienceScheduleRepository::get_by_id($specific_id);
                return $schedule ? array($schedule) : array();
            }
            return AudienceScheduleRepository::get_all(array('status' => 'active'));
        }

        // Get schedules user has explicit access to
        $schedules = AudienceScheduleRepository::get_by_user_access($user_id);

        if ($specific_id > 0) {
            $schedules = array_filter($schedules, function($s) use ($specific_id) {
                return (int) $s->id === $specific_id;
            });
        }

        return array_values($schedules);
    }

    /**
     * Get environments for a schedule
     *
     * @param int $schedule_id Schedule ID
     * @return array<array{id: int, name: string}>
     */
    private static function get_schedule_environments(int $schedule_id): array {
        $environments = AudienceEnvironmentRepository::get_by_schedule($schedule_id, 'active');

        return array_map(function($e) {
            return array(
                'id' => $e->id,
                'name' => $e->name,
            );
        }, $environments);
    }

    /**
     * Check if user can book on any of the schedules
     *
     * @param int $user_id User ID
     * @param array<object> $schedules Schedules
     * @return bool
     */
    private static function can_user_book(int $user_id, array $schedules): bool {
        if (user_can($user_id, 'manage_options')) {
            return true;
        }

        foreach ($schedules as $schedule) {
            if (AudienceScheduleRepository::user_can_book((int) $schedule->id, $user_id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get audiences user can book for
     *
     * @param int $user_id User ID
     * @return array
     */
    private static function get_user_audiences(int $user_id): array {
        // Admin can book any audience
        if (user_can($user_id, 'manage_options')) {
            $audiences = AudienceRepository::get_hierarchical('active');
        } else {
            // Regular users can only book for audiences they belong to
            $audiences = AudienceRepository::get_user_audiences($user_id, true);
        }

        $result = array();
        foreach ($audiences as $audience) {
            $item = array(
                'id' => $audience->id,
                'name' => $audience->name,
                'color' => $audience->color,
                'parent_id' => $audience->parent_id ?? null,
            );

            // Include children for hierarchical structure
            if (isset($audience->children) && !empty($audience->children)) {
                $item['children'] = array_map(function($c) {
                    return array(
                        'id' => $c->id,
                        'name' => $c->name,
                        'color' => $c->color,
                        'parent_id' => $c->parent_id,
                    );
                }, $audience->children);
            }

            $result[] = $item;
        }

        return $result;
    }

    /**
     * Enqueue frontend assets
     *
     * @return void
     */
    private static function enqueue_assets(): void {
        // CSS
        wp_enqueue_style(
            'ffc-audience',
            FFC_PLUGIN_URL . 'assets/css/ffc-audience.css',
            array(),
            FFC_VERSION
        );

        // JavaScript
        wp_enqueue_script(
            'ffc-audience',
            FFC_PLUGIN_URL . 'assets/js/ffc-audience.js',
            array('jquery'),
            FFC_VERSION,
            true
        );

        // Localize script
        wp_localize_script('ffc-audience', 'ffcAudience', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => rest_url('ffc/v1/audience/'),
            'nonce' => wp_create_nonce('wp_rest'),
            'locale' => get_locale(),
            'dateFormat' => get_option('date_format', 'Y-m-d'),
            'timeFormat' => get_option('time_format', 'H:i'),
            'firstDayOfWeek' => (int) get_option('start_of_week', 0),
            'strings' => array(
                'loading' => __('Loading...', 'wp-ffcertificate'),
                'error' => __('An error occurred. Please try again.', 'wp-ffcertificate'),
                'noBookings' => __('No bookings for this day.', 'wp-ffcertificate'),
                'noActiveBookings' => __('No active bookings for this day.', 'wp-ffcertificate'),
                'bookingCreated' => __('Booking created successfully!', 'wp-ffcertificate'),
                'bookingCancelled' => __('Booking cancelled successfully.', 'wp-ffcertificate'),
                'confirmCancel' => __('Are you sure you want to cancel this booking?', 'wp-ffcertificate'),
                'cancelReason' => __('Please provide a reason for cancellation:', 'wp-ffcertificate'),
                'invalidTime' => __('End time must be after start time.', 'wp-ffcertificate'),
                'selectAudience' => __('Please select at least one audience.', 'wp-ffcertificate'),
                'selectUser' => __('Please select at least one user.', 'wp-ffcertificate'),
                'descriptionRequired' => __('Description is required (15-300 characters).', 'wp-ffcertificate'),
                'conflictWarning' => __('Warning: Conflicts detected with existing bookings.', 'wp-ffcertificate'),
                'months' => array(
                    __('January', 'wp-ffcertificate'),
                    __('February', 'wp-ffcertificate'),
                    __('March', 'wp-ffcertificate'),
                    __('April', 'wp-ffcertificate'),
                    __('May', 'wp-ffcertificate'),
                    __('June', 'wp-ffcertificate'),
                    __('July', 'wp-ffcertificate'),
                    __('August', 'wp-ffcertificate'),
                    __('September', 'wp-ffcertificate'),
                    __('October', 'wp-ffcertificate'),
                    __('November', 'wp-ffcertificate'),
                    __('December', 'wp-ffcertificate'),
                ),
                'holiday' => __('Holiday', 'wp-ffcertificate'),
                'closed' => __('Closed', 'wp-ffcertificate'),
                'available' => __('Available', 'wp-ffcertificate'),
                'booked' => __('Booked', 'wp-ffcertificate'),
                'cancel' => __('Cancel', 'wp-ffcertificate'),
                'cancelled' => __('Cancelled', 'wp-ffcertificate'),
                'timeout' => __('Request timed out. Please try again.', 'wp-ffcertificate'),
                'checkConflicts' => __('Check Conflicts', 'wp-ffcertificate'),
            ),
        ));
    }
}

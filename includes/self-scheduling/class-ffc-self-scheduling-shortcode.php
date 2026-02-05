<?php
declare(strict_types=1);

/**
 * Self-Scheduling Shortcode
 *
 * Handles [ffc_self_scheduling id="X"] shortcode rendering.
 * Displays calendar booking interface with date picker and slot selection.
 *
 * @since 4.1.0
 * @version 4.1.0
 */

namespace FreeFormCertificate\SelfScheduling;

if (!defined('ABSPATH')) exit;

class SelfSchedulingShortcode {

    /**
     * Repositories
     */
    private $calendar_repository;
    private $appointment_handler;

    /**
     * Constructor
     */
    public function __construct() {
        $this->calendar_repository = new \FreeFormCertificate\Repositories\CalendarRepository();
        $this->appointment_handler = new AppointmentHandler();

        // Register shortcode
        add_shortcode('ffc_self_scheduling', array($this, 'render_calendar'));

        // Enqueue assets
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    /**
     * Enqueue frontend assets
     *
     * @return void
     */
    public function enqueue_assets(): void {
        // Only enqueue if shortcode is present in content
        if (!is_singular() && !is_page()) {
            return;
        }

        global $post;
        if (!$post || !has_shortcode($post->post_content, 'ffc_self_scheduling')) {
            return;
        }

        // Enqueue FFC common styles (includes CSS variables, honeypot, captcha, etc.)
        wp_enqueue_style(
            'ffc-common',
            FFC_PLUGIN_URL . 'assets/css/ffc-common.css',
            array(),
            FFC_VERSION
        );

        // Enqueue FFC frontend styles
        wp_enqueue_style(
            'ffc-frontend',
            FFC_PLUGIN_URL . 'assets/css/ffc-frontend.css',
            array('ffc-common'),
            FFC_VERSION
        );

        // Enqueue shared calendar styles (same as ffc-audience)
        wp_enqueue_style(
            'ffc-audience',
            FFC_PLUGIN_URL . 'assets/css/ffc-audience.css',
            array('ffc-common'),
            FFC_VERSION
        );

        // Enqueue calendar frontend styles (timeslots, form, confirmation)
        wp_enqueue_style(
            'ffc-calendar-frontend',
            FFC_PLUGIN_URL . 'assets/css/calendar-frontend.css',
            array('ffc-audience'),
            FFC_VERSION
        );

        // Enqueue FFC frontend helpers (for CPF/RF mask)
        wp_enqueue_script(
            'ffc-frontend-helpers',
            FFC_PLUGIN_URL . 'assets/js/ffc-frontend-helpers.js',
            array('jquery'),
            FFC_VERSION,
            true
        );

        // Enqueue shared calendar core component
        wp_enqueue_script(
            'ffc-calendar-core',
            FFC_PLUGIN_URL . 'assets/js/ffc-calendar-core.js',
            array('jquery'),
            FFC_VERSION,
            true
        );

        // Enqueue calendar frontend scripts
        wp_enqueue_script(
            'ffc-calendar-frontend',
            FFC_PLUGIN_URL . 'assets/js/calendar-frontend.js',
            array('jquery', 'ffc-calendar-core', 'ffc-frontend-helpers'),
            FFC_VERSION,
            true
        );

        // Localize script
        wp_localize_script('ffc-calendar-frontend', 'ffcCalendar', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ffc_self_scheduling_nonce'),
            'strings' => array(
                'selectDate' => __('Please select a date', 'wp-ffcertificate'),
                'selectTime' => __('Please select a time', 'wp-ffcertificate'),
                'fillRequired' => __('Please fill all required fields', 'wp-ffcertificate'),
                'consentRequired' => __('You must agree to the terms', 'wp-ffcertificate'),
                'loading' => __('Loading...', 'wp-ffcertificate'),
                'noSlots' => __('No available slots for this date', 'wp-ffcertificate'),
                'success' => __('Appointment booked successfully!', 'wp-ffcertificate'),
                'error' => __('An error occurred. Please try again.', 'wp-ffcertificate'),
                // Calendar strings
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
                'weekdays' => array(
                    __('Sun', 'wp-ffcertificate'),
                    __('Mon', 'wp-ffcertificate'),
                    __('Tue', 'wp-ffcertificate'),
                    __('Wed', 'wp-ffcertificate'),
                    __('Thu', 'wp-ffcertificate'),
                    __('Fri', 'wp-ffcertificate'),
                    __('Sat', 'wp-ffcertificate'),
                ),
                'today' => __('Today', 'wp-ffcertificate'),
                'holiday' => __('Holiday', 'wp-ffcertificate'),
                'closed' => __('Closed', 'wp-ffcertificate'),
                'available' => __('Available', 'wp-ffcertificate'),
                'booked' => __('Booked', 'wp-ffcertificate'),
                'booking' => __('booking', 'wp-ffcertificate'),
                'bookings' => __('bookings', 'wp-ffcertificate'),
                // Confirmation screen
                'date' => __('Date', 'wp-ffcertificate'),
                'time' => __('Time', 'wp-ffcertificate'),
                'name' => __('Name', 'wp-ffcertificate'),
                'email' => __('Email', 'wp-ffcertificate'),
                'status' => __('Status', 'wp-ffcertificate'),
                'confirmed' => __('Confirmed', 'wp-ffcertificate'),
                'pendingApproval' => __('Pending Approval', 'wp-ffcertificate'),
                'confirmationCode' => __('Confirmation Code', 'wp-ffcertificate'),
                'confirmationCodeHelp' => __('Save this code to manage your appointment.', 'wp-ffcertificate'),
                'downloadReceipt' => __('Download Receipt', 'wp-ffcertificate'),
            )
        ));
    }

    /**
     * Render calendar shortcode
     *
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public function render_calendar(array $atts): string {
        $atts = shortcode_atts(array(
            'id' => 0
        ), $atts, 'ffc_self_scheduling');

        $calendar_id = absint($atts['id']);

        if (!$calendar_id) {
            return '<p class="ffc-error">' . __('Calendar ID is required.', 'wp-ffcertificate') . '</p>';
        }

        // Try to get calendar by Post ID first (most common usage)
        $calendar = $this->calendar_repository->findByPostId($calendar_id);

        // If not found, try by table ID
        if (!$calendar) {
            $calendar = $this->calendar_repository->findById($calendar_id);
        }

        // Decode working hours if found
        if ($calendar) {
            if (!empty($calendar['working_hours'])) {
                $calendar['working_hours'] = json_decode($calendar['working_hours'], true);
            }
            if (!empty($calendar['allowed_roles'])) {
                $calendar['allowed_roles'] = json_decode($calendar['allowed_roles'], true);
            }
        }

        if (!$calendar) {
            return '<p class="ffc-error">' . __('Calendar not found.', 'wp-ffcertificate') . '</p>';
        }

        if ($calendar['status'] !== 'active') {
            return '<p class="ffc-error">' . __('This calendar is not accepting bookings.', 'wp-ffcertificate') . '</p>';
        }

        // Ensure working_hours is an array
        if (empty($calendar['working_hours']) || !is_array($calendar['working_hours'])) {
            return '<p class="ffc-error">' . __('Calendar has no working hours configured. Please contact the administrator.', 'wp-ffcertificate') . '</p>';
        }

        // Check login requirement
        if ($calendar['require_login'] && !is_user_logged_in()) {
            return '<p class="ffc-error">' . sprintf(
                /* translators: %s: value */
                __('You must be <a href="%s">logged in</a> to book this calendar.', 'wp-ffcertificate'),
                wp_login_url(get_permalink())
            ) . '</p>';
        }

        // Check role permissions
        if ($calendar['require_login'] && !empty($calendar['allowed_roles'])) {
            $user = wp_get_current_user();
            $has_role = array_intersect($user->roles, $calendar['allowed_roles']);
            if (empty($has_role)) {
                return '<p class="ffc-error">' . __('You do not have permission to book this calendar.', 'wp-ffcertificate') . '</p>';
            }
        }

        // Render calendar interface
        ob_start();
        $this->render_calendar_interface($calendar);
        return ob_get_clean();
    }

    /**
     * Render calendar booking interface
     *
     * @param array $calendar
     * @return void
     */
    private function render_calendar_interface(array $calendar): void {
        $user = wp_get_current_user();
        $is_logged_in = is_user_logged_in();

        // Calculate disabled weekdays (non-working days)
        $working_days = !empty($calendar['working_hours']) && is_array($calendar['working_hours'])
            ? array_column($calendar['working_hours'], 'day')
            : array();
        $disabled_days = array_diff(array(0, 1, 2, 3, 4, 5, 6), $working_days);

        ?>
        <div class="ffc-audience-calendar" data-calendar-id="<?php echo esc_attr($calendar['id']); ?>">

            <?php if (!empty($calendar['description'])): ?>
                <div class="ffc-calendar-description">
                    <p><?php echo esc_html($calendar['description']); ?></p>
                </div>
            <?php endif; ?>

            <!-- Calendar Grid (using shared component) -->
            <div id="ffc-calendar-container-<?php echo esc_attr($calendar['id']); ?>" class="ffc-calendar-container"></div>
            <input type="hidden" id="ffc-selected-date" name="selected_date" value="">

            <!-- Time Slots -->
            <div class="ffc-timeslots-wrapper" style="display: none;">
                <h3><?php esc_html_e('Available Times', 'wp-ffcertificate'); ?></h3>
                <div class="ffc-timeslots-loading">
                    <div class="ffc-spinner"></div>
                    <p><?php esc_html_e('Loading available slots...', 'wp-ffcertificate'); ?></p>
                </div>
                <div id="ffc-timeslots-container" class="ffc-timeslots-grid"></div>
            </div>

            <!-- Booking Form -->
            <div class="ffc-booking-form-wrapper" style="display: none;">
                <h3><?php esc_html_e('Your Information', 'wp-ffcertificate'); ?></h3>

                <form id="ffc-booking-form" class="ffc-booking-form" autocomplete="off">
                    <?php wp_nonce_field('ffc_self_scheduling_nonce', 'nonce'); ?>
                    <input type="hidden" name="action" value="ffc_book_appointment">
                    <input type="hidden" name="calendar_id" value="<?php echo esc_attr($calendar['id']); ?>">
                    <input type="hidden" name="date" id="ffc-form-date" value="">
                    <input type="hidden" name="time" id="ffc-form-time" value="">

                    <div class="ffc-form-row">
                        <label for="ffc-booking-name">
                            <?php esc_html_e('Name', 'wp-ffcertificate'); ?> <span class="required">*</span>
                        </label>
                        <input
                            type="text"
                            id="ffc-booking-name"
                            name="name"
                            value="<?php echo $is_logged_in ? esc_attr($user->display_name) : ''; ?>"
                            required
                            <?php echo esc_attr( $is_logged_in ? 'readonly' : '' ); ?>
                        >
                    </div>

                    <div class="ffc-form-row">
                        <label for="ffc-booking-email">
                            <?php esc_html_e('Email', 'wp-ffcertificate'); ?> <span class="required">*</span>
                        </label>
                        <input
                            type="email"
                            id="ffc-booking-email"
                            name="email"
                            value="<?php echo $is_logged_in ? esc_attr($user->user_email) : ''; ?>"
                            required
                            <?php echo esc_attr( $is_logged_in ? 'readonly' : '' ); ?>
                        >
                    </div>

                    <div class="ffc-form-row">
                        <label for="ffc-booking-cpf-rf">
                            <?php esc_html_e('CPF / RF', 'wp-ffcertificate'); ?> <span class="required">*</span>
                        </label>
                        <input
                            type="tel"
                            id="ffc-booking-cpf-rf"
                            name="cpf_rf"
                            maxlength="14"
                            required
                        >
                    </div>

                    <div class="ffc-form-row">
                        <label for="ffc-booking-notes">
                            <?php esc_html_e('Notes (optional)', 'wp-ffcertificate'); ?>
                        </label>
                        <textarea id="ffc-booking-notes" name="notes" rows="3"></textarea>
                    </div>

                    <!-- Security Fields (Honeypot + Math Captcha) -->
                    <?php
                    $captcha = \FreeFormCertificate\Core\Utils::generate_simple_captcha();
                    ?>
                    <div class="ffc-security-container">
                        <!-- Honeypot Field -->
                        <div class="ffc-honeypot-field">
                            <label><?php esc_html_e('Do not fill this field if you are human:', 'wp-ffcertificate'); ?></label>
                            <input type="text" name="ffc_honeypot_trap" value="" tabindex="-1" autocomplete="off">
                        </div>

                        <!-- Math Captcha -->
                        <div class="ffc-captcha-row">
                            <label for="ffc_captcha_ans">
                                <?php echo wp_kses_post( $captcha['label'] ); ?>
                            </label>
                            <input type="number" name="ffc_captcha_ans" id="ffc_captcha_ans" class="ffc-input" required>
                            <input type="hidden" name="ffc_captcha_hash" id="ffc_captcha_hash" value="<?php echo esc_attr($captcha['hash']); ?>">
                        </div>
                    </div>

                    <!-- LGPD Consent -->
                    <div class="ffc-form-row ffc-consent-row">
                        <label class="ffc-consent-label">
                            <input type="checkbox" id="ffc-booking-consent" name="consent" value="1" required>
                            <?php
                            echo wp_kses_post( sprintf(
                                /* translators: %s: value */
                                __('I agree to the collection and processing of my personal data in accordance with the <a href="%s" target="_blank">Privacy Policy</a> (LGPD).', 'wp-ffcertificate'),
                                esc_url( get_privacy_policy_url() )
                            ) );
                            ?>
                            <span class="required">*</span>
                        </label>
                        <input type="hidden" name="consent_text" value="<?php echo esc_attr(__('User consented to data collection for appointment booking.', 'wp-ffcertificate')); ?>">
                    </div>

                    <!-- Submit Button -->
                    <div class="ffc-form-row ffc-submit-row">
                        <button type="submit" class="ffc-btn ffc-btn-primary">
                            <?php esc_html_e('Book Appointment', 'wp-ffcertificate'); ?>
                        </button>
                        <button type="button" class="ffc-btn ffc-btn-secondary ffc-btn-back">
                            <?php esc_html_e('← Back to Date Selection', 'wp-ffcertificate'); ?>
                        </button>
                    </div>

                    <!-- Messages -->
                    <div class="ffc-form-messages"></div>
                </form>
            </div>

            <!-- Confirmation Message -->
            <div class="ffc-confirmation-wrapper" style="display: none;">
                <div class="ffc-confirmation-success">
                    <div class="ffc-success-icon">✓</div>
                    <h3><?php esc_html_e('Appointment Confirmed!', 'wp-ffcertificate'); ?></h3>
                    <div class="ffc-appointment-details"></div>

                    <?php if ($calendar['requires_approval']): ?>
                        <p class="ffc-approval-notice">
                            <?php esc_html_e('Your appointment is pending approval. You will receive an email confirmation once it is approved.', 'wp-ffcertificate'); ?>
                        </p>
                    <?php else: ?>
                        <p class="ffc-confirmation-notice">
                            <?php esc_html_e('A confirmation email has been sent to your email address.', 'wp-ffcertificate'); ?>
                        </p>
                    <?php endif; ?>

                    <button type="button" class="ffc-btn ffc-btn-primary ffc-btn-new-booking">
                        <?php esc_html_e('Book Another Appointment', 'wp-ffcertificate'); ?>
                    </button>
                </div>
            </div>
        </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            var calendarId = <?php echo intval( $calendar['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- intval() is safe ?>;
            var workingDays = <?php echo wp_json_encode(!empty($calendar['working_hours']) && is_array($calendar['working_hours']) ? array_column($calendar['working_hours'], 'day') : []); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode() is safe for JS context ?>;
            var minDateHours = <?php echo intval( isset($calendar['advance_booking_min']) ? $calendar['advance_booking_min'] : 0 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- intval() is safe ?>; // hours
            var maxDateDays = <?php echo intval( isset($calendar['advance_booking_max']) ? $calendar['advance_booking_max'] : 30 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- intval() is safe ?>; // days

            // Calculate disabled days (weekdays not in workingDays)
            var disabledDays = [];
            for (var i = 0; i < 7; i++) {
                if (workingDays.indexOf(i) === -1) {
                    disabledDays.push(i);
                }
            }

            // Calculate min/max dates
            var minDate = new Date();
            if (minDateHours > 0) {
                minDate.setHours(minDate.getHours() + minDateHours);
            }
            minDate.setHours(0, 0, 0, 0);

            var maxDate = new Date();
            maxDate.setDate(maxDate.getDate() + maxDateDays);
            maxDate.setHours(23, 59, 59, 999);

            // Store booking counts and tracking
            var bookingCounts = {};
            var lastFetchedMonth = null;
            var isFetching = false;

            // Function to fetch booking counts for a month
            function fetchBookingCounts(year, month, callback) {
                var monthKey = year + '-' + month;

                // Prevent duplicate fetches
                if (isFetching || lastFetchedMonth === monthKey) {
                    if (callback) callback();
                    return;
                }

                isFetching = true;

                $.ajax({
                    url: ffcCalendar.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ffc_get_month_bookings',
                        nonce: ffcCalendar.nonce,
                        calendar_id: calendarId,
                        year: year,
                        month: month
                    },
                    success: function(response) {
                        if (response.success && response.data.counts) {
                            bookingCounts = response.data.counts;
                        }
                        lastFetchedMonth = monthKey;
                        isFetching = false;
                        if (callback) callback();
                    },
                    error: function() {
                        isFetching = false;
                        if (callback) callback();
                    }
                });
            }

            // Initialize shared calendar component
            var $container = $('#ffc-calendar-container-' + calendarId);
            var calendar = new FFCCalendarCore($container, {
                showLegend: true,
                showTodayButton: true,
                minDate: minDate,
                maxDate: maxDate,
                disabledDays: disabledDays,
                strings: ffcCalendar.strings,
                legendItems: [
                    { class: 'ffc-available', label: ffcCalendar.strings.available || 'Available' },
                    { class: 'ffc-booked', label: ffcCalendar.strings.booked || 'Booked' },
                    { class: 'ffc-closed', label: ffcCalendar.strings.closed || 'Closed' }
                ],
                getDayClasses: function(dateStr, date) {
                    var classes = [];
                    var weekday = date.getDay();

                    // Mark working days as available
                    if (workingDays.indexOf(weekday) !== -1) {
                        classes.push('ffc-available');
                    }

                    return classes;
                },
                getDayContent: function(dateStr, date, isHoliday) {
                    var count = bookingCounts[dateStr] || 0;
                    if (count > 0) {
                        var label = count === 1 ? (ffcCalendar.strings.booking || 'booking') : (ffcCalendar.strings.bookings || 'bookings');
                        return '<span class="ffc-day-badge ffc-badge-bookings">' + count + ' ' + label + '</span>';
                    }
                    return '';
                },
                onMonthChange: function(year, month) {
                    // Fetch booking counts for the new month
                    fetchBookingCounts(year, month, function() {
                        calendar.refresh();
                    });
                },
                onDayClick: function(dateStr, $day) {
                    // Update hidden field
                    $('#ffc-selected-date').val(dateStr);

                    // Load time slots
                    ffcCalendarFrontend.loadTimeSlots(calendarId, dateStr);
                }
            });

            // Store calendar instance for later access
            // Note: Initial booking counts are fetched via onMonthChange during calendar init
            $container.data('calendar', calendar);
        });
        </script>
        <?php
    }
}

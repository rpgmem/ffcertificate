<?php
declare(strict_types=1);

/**
 * Calendar Shortcode
 *
 * Handles [ffc_calendar id="X"] shortcode rendering.
 * Displays calendar booking interface with date picker and slot selection.
 *
 * @since 4.1.0
 * @version 4.1.0
 */

namespace FreeFormCertificate\Calendars;

if (!defined('ABSPATH')) exit;

class CalendarShortcode {

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
        add_shortcode('ffc_calendar', array($this, 'render_calendar'));

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
        if (!$post || !has_shortcode($post->post_content, 'ffc_calendar')) {
            return;
        }

        // Enqueue jQuery UI Datepicker
        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_style('jquery-ui-theme', '//code.jquery.com/ui/1.12.1/themes/smoothness/jquery-ui.css');

        // Enqueue FFC common styles (includes honeypot, captcha, etc.)
        wp_enqueue_style(
            'ffc-frontend',
            FFC_PLUGIN_URL . 'assets/css/ffc-frontend.css',
            array(),
            FFC_VERSION
        );

        // Enqueue calendar frontend styles
        wp_enqueue_style(
            'ffc-calendar-frontend',
            FFC_PLUGIN_URL . 'assets/css/calendar-frontend.css',
            array('ffc-frontend'),
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

        // Enqueue calendar frontend scripts
        wp_enqueue_script(
            'ffc-calendar-frontend',
            FFC_PLUGIN_URL . 'assets/js/calendar-frontend.js',
            array('jquery', 'jquery-ui-datepicker', 'ffc-frontend-helpers'),
            FFC_VERSION,
            true
        );

        // Localize script
        wp_localize_script('ffc-calendar-frontend', 'ffcCalendar', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ffc_calendar_nonce'),
            'strings' => array(
                'selectDate' => __('Please select a date', 'ffc'),
                'selectTime' => __('Please select a time', 'ffc'),
                'fillRequired' => __('Please fill all required fields', 'ffc'),
                'consentRequired' => __('You must agree to the terms', 'ffc'),
                'loading' => __('Loading...', 'ffc'),
                'noSlots' => __('No available slots for this date', 'ffc'),
                'success' => __('Appointment booked successfully!', 'ffc'),
                'error' => __('An error occurred. Please try again.', 'ffc'),
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
        ), $atts, 'ffc_calendar');

        $calendar_id = absint($atts['id']);

        if (!$calendar_id) {
            return '<p class="ffc-error">' . __('Calendar ID is required.', 'ffc') . '</p>';
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
            return '<p class="ffc-error">' . __('Calendar not found.', 'ffc') . '</p>';
        }

        if ($calendar['status'] !== 'active') {
            return '<p class="ffc-error">' . __('This calendar is not accepting bookings.', 'ffc') . '</p>';
        }

        // Ensure working_hours is an array
        if (empty($calendar['working_hours']) || !is_array($calendar['working_hours'])) {
            return '<p class="ffc-error">' . __('Calendar has no working hours configured. Please contact the administrator.', 'ffc') . '</p>';
        }

        // Check login requirement
        if ($calendar['require_login'] && !is_user_logged_in()) {
            return '<p class="ffc-error">' . sprintf(
                __('You must be <a href="%s">logged in</a> to book this calendar.', 'ffc'),
                wp_login_url(get_permalink())
            ) . '</p>';
        }

        // Check role permissions
        if ($calendar['require_login'] && !empty($calendar['allowed_roles'])) {
            $user = wp_get_current_user();
            $has_role = array_intersect($user->roles, $calendar['allowed_roles']);
            if (empty($has_role)) {
                return '<p class="ffc-error">' . __('You do not have permission to book this calendar.', 'ffc') . '</p>';
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

        ?>
        <div class="ffc-calendar-wrapper" data-calendar-id="<?php echo esc_attr($calendar['id']); ?>">

            <?php if (!empty($calendar['description'])): ?>
                <div class="ffc-calendar-description">
                    <p><?php echo esc_html($calendar['description']); ?></p>
                </div>
            <?php endif; ?>

            <!-- Date Picker -->
            <div class="ffc-calendar-datepicker-wrapper">
                <h3><?php _e('Select a Date', 'ffc'); ?></h3>
                <div id="ffc-datepicker-<?php echo $calendar['id']; ?>" class="ffc-datepicker"></div>
                <input type="hidden" id="ffc-selected-date" name="selected_date" value="">
            </div>

            <!-- Time Slots -->
            <div class="ffc-timeslots-wrapper" style="display: none;">
                <h3><?php _e('Available Times', 'ffc'); ?></h3>
                <div class="ffc-timeslots-loading">
                    <div class="ffc-spinner"></div>
                    <p><?php _e('Loading available slots...', 'ffc'); ?></p>
                </div>
                <div id="ffc-timeslots-container" class="ffc-timeslots-grid"></div>
            </div>

            <!-- Booking Form -->
            <div class="ffc-booking-form-wrapper" style="display: none;">
                <h3><?php _e('Your Information', 'ffc'); ?></h3>

                <form id="ffc-booking-form" class="ffc-booking-form">
                    <?php wp_nonce_field('ffc_calendar_nonce', 'nonce'); ?>
                    <input type="hidden" name="action" value="ffc_book_appointment">
                    <input type="hidden" name="calendar_id" value="<?php echo esc_attr($calendar['id']); ?>">
                    <input type="hidden" name="date" id="ffc-form-date" value="">
                    <input type="hidden" name="time" id="ffc-form-time" value="">

                    <div class="ffc-form-row">
                        <label for="ffc-booking-name">
                            <?php _e('Name', 'ffc'); ?> <span class="required">*</span>
                        </label>
                        <input
                            type="text"
                            id="ffc-booking-name"
                            name="name"
                            value="<?php echo $is_logged_in ? esc_attr($user->display_name) : ''; ?>"
                            required
                            <?php echo $is_logged_in ? 'readonly' : ''; ?>
                        >
                    </div>

                    <div class="ffc-form-row">
                        <label for="ffc-booking-email">
                            <?php _e('Email', 'ffc'); ?> <span class="required">*</span>
                        </label>
                        <input
                            type="email"
                            id="ffc-booking-email"
                            name="email"
                            value="<?php echo $is_logged_in ? esc_attr($user->user_email) : ''; ?>"
                            required
                            <?php echo $is_logged_in ? 'readonly' : ''; ?>
                        >
                    </div>

                    <div class="ffc-form-row">
                        <label for="ffc-booking-cpf-rf">
                            <?php _e('CPF / RF', 'ffc'); ?> <span class="required">*</span>
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
                            <?php _e('Notes (optional)', 'ffc'); ?>
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
                            <label><?php esc_html_e('Do not fill this field if you are human:', 'ffc'); ?></label>
                            <input type="text" name="ffc_honeypot_trap" value="" tabindex="-1" autocomplete="off">
                        </div>

                        <!-- Math Captcha -->
                        <div class="ffc-captcha-row">
                            <label for="ffc_captcha_ans">
                                <?php echo $captcha['label']; ?>
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
                            printf(
                                __('I agree to the collection and processing of my personal data in accordance with the <a href="%s" target="_blank">Privacy Policy</a> (LGPD).', 'ffc'),
                                get_privacy_policy_url()
                            );
                            ?>
                            <span class="required">*</span>
                        </label>
                        <input type="hidden" name="consent_text" value="<?php echo esc_attr(__('User consented to data collection for appointment booking.', 'ffc')); ?>">
                    </div>

                    <!-- Submit Button -->
                    <div class="ffc-form-row ffc-submit-row">
                        <button type="submit" class="ffc-btn ffc-btn-primary">
                            <?php _e('Book Appointment', 'ffc'); ?>
                        </button>
                        <button type="button" class="ffc-btn ffc-btn-secondary ffc-btn-back">
                            <?php _e('← Back to Date Selection', 'ffc'); ?>
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
                    <h3><?php _e('Appointment Confirmed!', 'ffc'); ?></h3>
                    <div class="ffc-appointment-details"></div>

                    <?php if ($calendar['requires_approval']): ?>
                        <p class="ffc-approval-notice">
                            <?php _e('Your appointment is pending approval. You will receive an email confirmation once it is approved.', 'ffc'); ?>
                        </p>
                    <?php else: ?>
                        <p class="ffc-confirmation-notice">
                            <?php _e('A confirmation email has been sent to your email address.', 'ffc'); ?>
                        </p>
                    <?php endif; ?>

                    <button type="button" class="ffc-btn ffc-btn-primary ffc-btn-new-booking">
                        <?php _e('Book Another Appointment', 'ffc'); ?>
                    </button>
                </div>
            </div>
        </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            var calendarId = <?php echo $calendar['id']; ?>;
            var workingDays = <?php echo json_encode(!empty($calendar['working_hours']) && is_array($calendar['working_hours']) ? array_column($calendar['working_hours'], 'day') : []); ?>;
            var minDate = <?php echo isset($calendar['advance_booking_min']) ? intval($calendar['advance_booking_min']) : 0; ?>; // hours
            var maxDate = <?php echo isset($calendar['advance_booking_max']) ? intval($calendar['advance_booking_max']) : 30; ?>; // days

            // Initialize datepicker
            $('#ffc-datepicker-' + calendarId).datepicker({
                dateFormat: 'yy-mm-dd',
                minDate: minDate > 0 ? '+' + Math.ceil(minDate / 24) + 'd' : 0,
                maxDate: '+' + maxDate + 'd',
                firstDay: 0,
                beforeShowDay: function(date) {
                    var day = date.getDay();
                    var isWorkingDay = workingDays.indexOf(day) !== -1;
                    return [isWorkingDay, isWorkingDay ? 'ffc-available-day' : 'ffc-unavailable-day'];
                },
                onSelect: function(dateText) {
                    $('#ffc-selected-date').val(dateText);
                    ffcCalendarFrontend.loadTimeSlots(calendarId, dateText);
                }
            });
        });
        </script>
        <?php
    }
}

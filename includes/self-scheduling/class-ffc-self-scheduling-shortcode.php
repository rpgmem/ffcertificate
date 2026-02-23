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
     *
     * @var \FreeFormCertificate\Repositories\CalendarRepository
     */
    private $calendar_repository;

    /**
     * Constructor
     */
    public function __construct() {
        $this->calendar_repository = new \FreeFormCertificate\Repositories\CalendarRepository();

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

        $s = \FreeFormCertificate\Core\Utils::asset_suffix();

        // Enqueue FFC common styles (includes CSS variables, honeypot, captcha, etc.)
        wp_enqueue_style(
            'ffc-common',
            FFC_PLUGIN_URL . "assets/css/ffc-common{$s}.css",
            array(),
            FFC_VERSION
        );

        // Enqueue FFC frontend styles
        wp_enqueue_style(
            'ffc-frontend',
            FFC_PLUGIN_URL . "assets/css/ffc-frontend{$s}.css",
            array('ffc-common'),
            FFC_VERSION
        );

        // Enqueue shared calendar styles (same as ffc-audience)
        wp_enqueue_style(
            'ffc-audience',
            FFC_PLUGIN_URL . "assets/css/ffc-audience{$s}.css",
            array('ffc-common'),
            FFC_VERSION
        );

        // Enqueue calendar frontend styles (timeslots, form, confirmation)
        wp_enqueue_style(
            'ffc-calendar-frontend',
            FFC_PLUGIN_URL . "assets/css/ffc-calendar-frontend{$s}.css",
            array('ffc-audience'),
            FFC_VERSION
        );

        // Enqueue FFC frontend helpers (for CPF/RF mask)
        wp_enqueue_script(
            'ffc-frontend-helpers',
            FFC_PLUGIN_URL . "assets/js/ffc-frontend-helpers{$s}.js",
            array('jquery'),
            FFC_VERSION,
            true
        );

        // Enqueue shared calendar core component
        wp_enqueue_script(
            'ffc-calendar-core',
            FFC_PLUGIN_URL . "assets/js/ffc-calendar-core{$s}.js",
            array('jquery'),
            FFC_VERSION,
            true
        );

        // PDF Libraries for auto-download receipt on booking
        wp_enqueue_script(
            'html2canvas',
            FFC_PLUGIN_URL . 'libs/js/html2canvas.min.js',
            array(),
            FFC_HTML2CANVAS_VERSION,
            true
        );

        wp_enqueue_script(
            'jspdf',
            FFC_PLUGIN_URL . 'libs/js/jspdf.umd.min.js',
            array(),
            FFC_JSPDF_VERSION,
            true
        );

        wp_enqueue_script(
            'ffc-pdf-generator',
            FFC_PLUGIN_URL . "assets/js/ffc-pdf-generator{$s}.js",
            array('jquery', 'html2canvas', 'jspdf'),
            FFC_VERSION,
            true
        );

        // Enqueue calendar frontend scripts
        wp_enqueue_script(
            'ffc-calendar-frontend',
            FFC_PLUGIN_URL . "assets/js/ffc-calendar-frontend{$s}.js",
            array('jquery', 'ffc-calendar-core', 'ffc-frontend-helpers', 'ffc-pdf-generator'),
            FFC_VERSION,
            true
        );

        // Dynamic fragments: refresh captcha + nonces on cached pages
        wp_enqueue_script( 'ffc-dynamic-fragments' );

        // Localize script
        wp_localize_script('ffc-calendar-frontend', 'ffcCalendar', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ffc_self_scheduling_nonce'),
            'strings' => array(
                'selectDate' => __('Please select a date', 'ffcertificate'),
                'selectTime' => __('Please select a time', 'ffcertificate'),
                'fillRequired' => __('Please fill all required fields', 'ffcertificate'),
                'consentRequired' => __('You must agree to the terms', 'ffcertificate'),
                'loading' => __('Loading...', 'ffcertificate'),
                'availableTimes' => __('Available Times', 'ffcertificate'),
                'yourInformation' => __('Your Information', 'ffcertificate'),
                'noSlots' => __('No available slots for this date', 'ffcertificate'),
                'success' => __('Appointment booked successfully!', 'ffcertificate'),
                'error' => __('An error occurred. Please try again.', 'ffcertificate'),
                // Calendar strings
                'months' => array(
                    __('January', 'ffcertificate'),
                    __('February', 'ffcertificate'),
                    __('March', 'ffcertificate'),
                    __('April', 'ffcertificate'),
                    __('May', 'ffcertificate'),
                    __('June', 'ffcertificate'),
                    __('July', 'ffcertificate'),
                    __('August', 'ffcertificate'),
                    __('September', 'ffcertificate'),
                    __('October', 'ffcertificate'),
                    __('November', 'ffcertificate'),
                    __('December', 'ffcertificate'),
                ),
                'weekdays' => array(
                    __('Sun', 'ffcertificate'),
                    __('Mon', 'ffcertificate'),
                    __('Tue', 'ffcertificate'),
                    __('Wed', 'ffcertificate'),
                    __('Thu', 'ffcertificate'),
                    __('Fri', 'ffcertificate'),
                    __('Sat', 'ffcertificate'),
                ),
                'today' => __('Today', 'ffcertificate'),
                'holiday' => __('Holiday', 'ffcertificate'),
                'closed' => __('Closed', 'ffcertificate'),
                'available' => __('Available', 'ffcertificate'),
                'booked' => __('Booked', 'ffcertificate'),
                'booking' => __('booking', 'ffcertificate'),
                'bookings' => __('bookings', 'ffcertificate'),
                // Confirmation screen
                'date' => __('Date', 'ffcertificate'),
                'time' => __('Time', 'ffcertificate'),
                'name' => __('Name', 'ffcertificate'),
                'email' => __('Email', 'ffcertificate'),
                'status' => __('Status', 'ffcertificate'),
                'confirmed' => __('Confirmed', 'ffcertificate'),
                'pendingApproval' => __('Pending Approval', 'ffcertificate'),
                'confirmationCode' => __('Confirmation Code', 'ffcertificate'),
                'confirmationCodeHelp' => __('Save this code to manage your appointment.', 'ffcertificate'),
                'downloadReceipt' => __('Download Receipt', 'ffcertificate'),
                'generatingReceipt' => __('Generating receipt in the background, please wait...', 'ffcertificate'),
                'validationCode' => __('Validation Code', 'ffcertificate'),
                'submit' => __('Book Appointment', 'ffcertificate'),
                'timeout' => __('Connection timeout. Please try again.', 'ffcertificate'),
                'networkError' => __('Network error. Please check your connection and try again.', 'ffcertificate'),
                // PDF overlay
                'generatingPdf' => __('Generating PDF...', 'ffcertificate'),
                'pleaseWait' => __('Please wait, this may take a few seconds...', 'ffcertificate'),
            )
        ));
    }

    /**
     * Render calendar shortcode
     *
     * @param array<string, mixed> $atts Shortcode attributes
     * @return string HTML output
     */
    public function render_calendar(array $atts): string {
        $atts = shortcode_atts(array(
            'id' => 0
        ), $atts, 'ffc_self_scheduling');

        $calendar_id = absint($atts['id']);

        if (!$calendar_id) {
            return '<p class="ffc-error">' . __('Calendar ID is required.', 'ffcertificate') . '</p>';
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
        }

        if (!$calendar) {
            return '<p class="ffc-error">' . __('Calendar not found.', 'ffcertificate') . '</p>';
        }

        if ($calendar['status'] !== 'active') {
            return '<p class="ffc-error">' . __('This calendar is not accepting bookings.', 'ffcertificate') . '</p>';
        }

        // Ensure working_hours is an array
        if (empty($calendar['working_hours']) || !is_array($calendar['working_hours'])) {
            return '<p class="ffc-error">' . __('Calendar has no working hours configured. Please contact the administrator.', 'ffcertificate') . '</p>';
        }

        $is_logged_in = is_user_logged_in();
        $has_bypass = \FreeFormCertificate\Repositories\CalendarRepository::userHasSchedulingBypass();
        $visibility = $calendar['visibility'] ?? 'public';
        $scheduling_visibility = $calendar['scheduling_visibility'] ?? 'public';

        // Visibility check: Private calendar + not logged in (and no bypass)
        if ($visibility === 'private' && !$is_logged_in && !$has_bypass) {
            return $this->render_private_visibility_message($calendar);
        }

        // Business hours restriction: viewing
        $restrict_viewing = !empty($calendar['restrict_viewing_to_hours']);
        $restrict_booking = !empty($calendar['restrict_booking_to_hours']);

        if ($restrict_viewing && !$has_bypass) {
            $outside_hours = $this->is_outside_business_hours($calendar);
            if ($outside_hours) {
                return $this->render_business_hours_message($calendar, 'viewing');
            }
        }

        // Render calendar interface with scheduling restriction flag
        $can_book = true;
        $scheduling_message = '';

        if ($scheduling_visibility === 'private' && !$is_logged_in && !$has_bypass) {
            $can_book = false;
            $scheduling_message = get_option(
                'ffc_ss_scheduling_message',
                __('To book on this calendar you need to be logged in. <a href="%login_url%">Log in</a> to continue.', 'ffcertificate')
            );
            $scheduling_message = str_replace('%login_url%', wp_login_url(get_permalink()), $scheduling_message);
        }

        // Business hours restriction: booking only
        if ($can_book && $restrict_booking && !$has_bypass) {
            $outside_hours = $this->is_outside_business_hours($calendar);
            if ($outside_hours) {
                $can_book = false;
                $scheduling_message = $this->get_business_hours_message($calendar, 'booking');
            }
        }

        ob_start();
        // Admin bypass badge
        if ($has_bypass && ($visibility === 'private' || $restrict_viewing || $restrict_booking)) {
            echo '<div class="ffc-admin-bypass-notice">';
            if ($visibility === 'private') {
                echo '<span class="ffc-badge ffc-badge-private">' . esc_html__('Private', 'ffcertificate') . '</span> ';
            }
            if ($restrict_viewing || $restrict_booking) {
                echo '<span class="ffc-badge ffc-badge-private">' . esc_html__('Business Hours Restricted', 'ffcertificate') . '</span> ';
            }
            echo esc_html__('You are viewing this calendar as an administrator (bypass active).', 'ffcertificate');
            echo '</div>';
        }
        $this->render_calendar_interface($calendar, $can_book, $scheduling_message);
        return ob_get_clean();
    }

    /**
     * Render message for private visibility restriction
     *
     * @since 4.7.0
     * @param array<string, mixed> $calendar Calendar data
     * @return string HTML output
     */
    private function render_private_visibility_message(array $calendar): string {
        $display_mode = get_option('ffc_ss_private_display_mode', 'show_message');

        if ($display_mode === 'hide') {
            return '';
        }

        $message = get_option(
            'ffc_ss_visibility_message',
            __('To view this calendar you need to be logged in. <a href="%login_url%">Log in</a> to continue.', 'ffcertificate')
        );
        $message = str_replace('%login_url%', wp_login_url(get_permalink()), $message);

        $output = '<div class="ffc-visibility-restricted">';

        if ($display_mode === 'show_title_message') {
            $output .= '<h3 class="ffc-calendar-title">' . esc_html($calendar['title']) . '</h3>';
        }

        $output .= '<div class="ffc-restricted-message">' . wp_kses_post($message) . '</div>';
        $output .= '</div>';

        return $output;
    }

    /**
     * Check if the current time is outside the calendar's working hours
     *
     * @since 4.7.0
     * @param array<string, mixed> $calendar Calendar data with working_hours
     * @return bool True if currently outside business hours
     */
    private function is_outside_business_hours(array $calendar): bool {
        $working_hours = $calendar['working_hours'] ?? array();
        if (empty($working_hours)) {
            return false;
        }

        $now = current_time('mysql');
        $current_date = gmdate('Y-m-d', strtotime($now));
        $current_time = gmdate('H:i', strtotime($now));

        // Check if today is a working day
        if (!\FreeFormCertificate\Scheduling\WorkingHoursService::is_working_day($current_date, $working_hours)) {
            return true;
        }

        // Check if current time is within working hours
        return !\FreeFormCertificate\Scheduling\WorkingHoursService::is_within_working_hours($current_date, $current_time, $working_hours);
    }

    /**
     * Get today's working hours range formatted for display
     *
     * @since 4.7.0
     * @param array<string, mixed> $calendar Calendar data with working_hours
     * @return string Formatted hours range (e.g. "09:00 - 17:00") or empty if closed
     */
    private function get_today_hours_display(array $calendar): string {
        $working_hours = $calendar['working_hours'] ?? array();
        if (empty($working_hours)) {
            return '';
        }

        $now = current_time('mysql');
        $current_date = gmdate('Y-m-d', strtotime($now));
        $ranges = \FreeFormCertificate\Scheduling\WorkingHoursService::get_day_ranges($current_date, $working_hours);

        if (empty($ranges)) {
            return __('Closed today', 'ffcertificate');
        }

        $formatted = array();
        foreach ($ranges as $range) {
            $formatted[] = $range['start'] . ' - ' . $range['end'];
        }

        return implode(', ', $formatted);
    }

    /**
     * Get the business hours restriction message
     *
     * @since 4.7.0
     * @param array<string, mixed> $calendar Calendar data
     * @param string $type 'viewing' or 'booking'
     * @return string Message HTML
     */
    private function get_business_hours_message(array $calendar, string $type): string {
        $option_key = $type === 'viewing'
            ? 'ffc_ss_business_hours_viewing_message'
            : 'ffc_ss_business_hours_booking_message';

        $default = $type === 'viewing'
            /* translators: %hours% is replaced with today's working hours range */
            ? __('This calendar is available for viewing only during business hours (%hours%).', 'ffcertificate')
            /* translators: %hours% is replaced with today's working hours range */
            : __('Booking is available only during business hours (%hours%).', 'ffcertificate');

        $message = get_option($option_key, $default);
        $hours_display = $this->get_today_hours_display($calendar);
        $message = str_replace('%hours%', $hours_display, $message);

        return $message;
    }

    /**
     * Render message for business hours viewing restriction
     *
     * @since 4.7.0
     * @param array<string, mixed> $calendar Calendar data
     * @param string $type 'viewing' or 'booking'
     * @return string HTML output
     */
    private function render_business_hours_message(array $calendar, string $type): string {
        $message = $this->get_business_hours_message($calendar, $type);

        $output = '<div class="ffc-visibility-restricted">';
        $output .= '<h3 class="ffc-calendar-title">' . esc_html($calendar['title']) . '</h3>';
        $output .= '<div class="ffc-restricted-message">' . wp_kses_post($message) . '</div>';
        $output .= '</div>';

        return $output;
    }

    /**
     * Render calendar booking interface
     *
     * @param array<string, mixed> $calendar
     * @param bool $can_book Whether the user can book (false when scheduling is restricted)
     * @param string $scheduling_message Message to show when booking is restricted
     * @return void
     */
    private function render_calendar_interface(array $calendar, bool $can_book = true, string $scheduling_message = ''): void {
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

            <!-- Booking Modal (time slots + form) -->
            <div class="ffc-modal" id="ffc-self-scheduling-modal" role="dialog" aria-modal="true" aria-labelledby="ffc-modal-title" style="display: none;">
                <div class="ffc-modal-backdrop"></div>
                <div class="ffc-modal-content ffc-modal-lg">
                    <div class="ffc-modal-header">
                        <h3 class="ffc-modal-title" id="ffc-modal-title"><?php esc_html_e('Available Times', 'ffcertificate'); ?></h3>
                        <button type="button" class="ffc-modal-close" aria-label="<?php esc_attr_e('Close', 'ffcertificate'); ?>">&times;</button>
                    </div>
                    <div class="ffc-modal-body">

                    <?php if (!$can_book && $scheduling_message): ?>
                        <!-- Scheduling restricted message (no time slots shown) -->
                        <div class="ffc-scheduling-restricted">
                            <div class="ffc-restricted-message"><?php echo wp_kses_post($scheduling_message); ?></div>
                        </div>
                    <?php else: ?>

                        <!-- Step 1: Time Slots -->
                        <div class="ffc-timeslots-wrapper">
                            <div class="ffc-timeslots-loading" role="status" aria-live="polite">
                                <div class="ffc-spinner" aria-hidden="true"></div>
                                <p><?php esc_html_e('Loading available slots...', 'ffcertificate'); ?></p>
                            </div>
                            <div id="ffc-timeslots-container" class="ffc-timeslots-grid" role="listbox" aria-label="<?php esc_attr_e('Available time slots', 'ffcertificate'); ?>"></div>
                        </div>

                        <!-- Step 2: Booking Form (hidden until time slot selected) -->
                        <div class="ffc-booking-form-wrapper" style="display: none;">
                            <form id="ffc-self-scheduling-form" class="ffc-booking-form" autocomplete="off">
                                <?php wp_nonce_field('ffc_self_scheduling_nonce', 'nonce'); ?>
                                <input type="hidden" name="action" value="ffc_book_appointment">
                                <input type="hidden" name="calendar_id" value="<?php echo esc_attr($calendar['id']); ?>">
                                <input type="hidden" name="date" id="ffc-form-date" value="">
                                <input type="hidden" name="time" id="ffc-form-time" value="">

                                <div class="ffc-form-row">
                                    <label for="ffc-booking-name">
                                        <?php esc_html_e('Name', 'ffcertificate'); ?> <span class="required">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        id="ffc-booking-name"
                                        name="name"
                                        value=""
                                        required
                                        aria-required="true"
                                    >
                                </div>

                                <div class="ffc-form-row">
                                    <label for="ffc-booking-email">
                                        <?php esc_html_e('Email', 'ffcertificate'); ?> <span class="required">*</span>
                                    </label>
                                    <input
                                        type="email"
                                        id="ffc-booking-email"
                                        name="email"
                                        value=""
                                        required
                                        aria-required="true"
                                    >
                                </div>

                                <div class="ffc-form-row">
                                    <label for="ffc-booking-cpf-rf">
                                        <?php esc_html_e('CPF / RF', 'ffcertificate'); ?> <span class="required">*</span>
                                    </label>
                                    <input
                                        type="tel"
                                        id="ffc-booking-cpf-rf"
                                        name="cpf_rf"
                                        maxlength="14"
                                        required
                                        aria-required="true"
                                    >
                                </div>

                                <div class="ffc-form-row">
                                    <label for="ffc-booking-notes">
                                        <?php esc_html_e('Notes (optional)', 'ffcertificate'); ?>
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
                                        <label><?php esc_html_e('Do not fill this field if you are human:', 'ffcertificate'); ?></label>
                                        <input type="text" name="ffc_honeypot_trap" value="" tabindex="-1" autocomplete="off">
                                    </div>

                                    <!-- Math Captcha -->
                                    <div class="ffc-captcha-row">
                                        <label for="ffc_captcha_ans">
                                            <span class="ffc-captcha-label-text"><?php echo esc_html( $captcha['label'] ); ?></span> <span class="required">*</span>
                                        </label>
                                        <input type="number" name="ffc_captcha_ans" id="ffc_captcha_ans" class="ffc-input" required aria-required="true">
                                        <input type="hidden" name="ffc_captcha_hash" id="ffc_captcha_hash" value="<?php echo esc_attr($captcha['hash']); ?>">
                                    </div>
                                </div>

                                <!-- LGPD Consent -->
                                <div class="ffc-form-row ffc-consent-row">
                                    <label class="ffc-consent-label">
                                        <input type="checkbox" id="ffc-booking-consent" name="consent" value="1" required aria-required="true">
                                        <?php
                                        echo wp_kses_post( sprintf(
                                            /* translators: %s: value */
                                            __('I agree to the collection and processing of my personal data in accordance with the <a href="%s" target="_blank">Privacy Policy</a> (LGPD).', 'ffcertificate'),
                                            esc_url( get_privacy_policy_url() )
                                        ) );
                                        ?>
                                        <span class="required">*</span>
                                    </label>
                                    <input type="hidden" name="consent_text" value="<?php echo esc_attr(__('User consented to data collection for appointment booking.', 'ffcertificate')); ?>">
                                </div>

                                <!-- Submit Button -->
                                <div class="ffc-form-row ffc-submit-row">
                                    <button type="submit" class="ffc-btn ffc-btn-primary">
                                        <?php esc_html_e('Book Appointment', 'ffcertificate'); ?>
                                    </button>
                                    <button type="button" class="ffc-btn ffc-btn-secondary ffc-btn-back">
                                        <?php esc_html_e('← Back', 'ffcertificate'); ?>
                                    </button>
                                </div>

                                <!-- Messages -->
                                <div class="ffc-form-messages" role="alert" aria-live="assertive"></div>
                            </form>
                        </div>

                    <?php endif; // $can_book ?>

                    </div>
                </div>
            </div>

            <!-- Confirmation Message (shown after successful booking, outside modal) -->
            <div class="ffc-confirmation-wrapper" role="status" aria-live="polite" style="display: none;">
                <div class="ffc-confirmation-success">
                    <div class="ffc-success-icon" aria-hidden="true">✓</div>
                    <h3><?php esc_html_e('Appointment Confirmed!', 'ffcertificate'); ?></h3>
                    <div class="ffc-appointment-details"></div>

                    <?php if ($calendar['requires_approval']): ?>
                        <p class="ffc-approval-notice">
                            <?php esc_html_e('Your appointment is pending approval. You will receive an email confirmation once it is approved.', 'ffcertificate'); ?>
                        </p>
                    <?php endif; ?>
                    <p class="ffc-confirmation-notice" style="display: none;">
                        <?php esc_html_e('A confirmation email has been sent to your email address.', 'ffcertificate'); ?>
                    </p>

                    <button type="button" class="ffc-btn ffc-btn-primary ffc-btn-new-booking">
                        <?php esc_html_e('Book Another Appointment', 'ffcertificate'); ?>
                    </button>
                </div>
            </div>
        </div>

        <?php
        // Build calendar config as data attribute (avoids wp_localize_script timing issues
        // and WordPress content filter mangling of inline JS)
        $working_days_js = array();
        if (!empty($calendar['working_hours']) && is_array($calendar['working_hours'])) {
            foreach ($calendar['working_hours'] as $wh) {
                if (isset($wh['day'])) {
                    $working_days_js[] = (int) $wh['day'];
                }
            }
        }

        $calendar_config = array(
            'calendarId'    => (int) $calendar['id'],
            'calendarTitle' => $calendar['title'] ?? '',
            'workingDays'   => $working_days_js,
            'minDateHours'  => isset($calendar['advance_booking_min']) ? (int) $calendar['advance_booking_min'] : 0,
            'maxDateDays'   => isset($calendar['advance_booking_max']) ? (int) $calendar['advance_booking_max'] : 30,
            'canBook'       => $can_book,
        );
        ?>
        <script type="application/json" id="ffc-calendar-config-<?php echo (int) $calendar['id']; ?>"><?php echo wp_json_encode($calendar_config); ?></script>
        <?php
    }
}

<?php
declare(strict_types=1);

/**
 * Appointment PDF Generator
 *
 * Generates PDF appointment confirmations/receipts
 *
 * @since 4.1.1
 * @version 4.1.1
 */

namespace FreeFormCertificate\Calendars;

if (!defined('ABSPATH')) exit;

class AppointmentPdfGenerator {

    /**
     * Generate appointment PDF
     *
     * @param int $appointment_id Appointment ID
     * @return array|WP_Error PDF data array or error
     */
    public function generate_pdf(int $appointment_id) {
        // Load repositories
        $appointment_repo = new \FreeFormCertificate\Repositories\AppointmentRepository();
        $calendar_repo = new \FreeFormCertificate\Repositories\CalendarRepository();

        // Get appointment
        $appointment = $appointment_repo->findById($appointment_id);

        if (!$appointment) {
            return new \WP_Error('appointment_not_found', __('Appointment not found.', 'ffc'));
        }

        // Get calendar
        $calendar = $calendar_repo->findById($appointment['calendar_id']);

        if (!$calendar) {
            return new \WP_Error('calendar_not_found', __('Calendar not found.', 'ffc'));
        }

        // Decrypt sensitive data if needed
        $email = $appointment['email'];
        if (empty($email) && !empty($appointment['email_encrypted'])) {
            if (class_exists('\FreeFormCertificate\Core\Encryption')) {
                try {
                    $email = \FreeFormCertificate\Core\Encryption::decrypt($appointment['email_encrypted']);
                } catch (\Exception $e) {
                    $email = '';
                }
            }
        }

        $phone = $appointment['phone'] ?? '';
        if (empty($phone) && !empty($appointment['phone_encrypted'])) {
            if (class_exists('\FreeFormCertificate\Core\Encryption')) {
                try {
                    $phone = \FreeFormCertificate\Core\Encryption::decrypt($appointment['phone_encrypted']);
                } catch (\Exception $e) {
                    $phone = '';
                }
            }
        }

        // Get date format from settings
        $settings = get_option('ffc_settings', array());
        $date_format = $settings['date_format'] ?? 'F j, Y';

        // Format date and time
        $appointment_date = date_i18n($date_format, strtotime($appointment['appointment_date']));
        $start_time = date_i18n(get_option('time_format'), strtotime($appointment['start_time']));
        $end_time = !empty($appointment['end_time']) ? date_i18n(get_option('time_format'), strtotime($appointment['end_time'])) : '';

        // Status label
        $status_labels = array(
            'pending' => __('Pending Approval', 'ffc'),
            'confirmed' => __('Confirmed', 'ffc'),
            'cancelled' => __('Cancelled', 'ffc'),
            'completed' => __('Completed', 'ffc'),
            'no_show' => __('No Show', 'ffc'),
        );
        $status_label = $status_labels[$appointment['status']] ?? $appointment['status'];

        // Generate HTML
        $html = $this->generate_html(array(
            'appointment_id' => $appointment['id'],
            'calendar_title' => $calendar['title'],
            'calendar_description' => $calendar['description'] ?? '',
            'name' => $appointment['name'] ?? '',
            'email' => $email,
            'phone' => $phone,
            'appointment_date' => $appointment_date,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'status' => $appointment['status'],
            'status_label' => $status_label,
            'user_notes' => $appointment['user_notes'] ?? '',
            'created_at' => date_i18n($date_format . ' ' . get_option('time_format'), strtotime($appointment['created_at'])),
            'site_name' => get_bloginfo('name'),
            'site_url' => home_url(),
        ));

        // Generate filename
        $filename = $this->generate_filename($calendar['title'], $appointment['id']);

        return array(
            'html' => $html,
            'filename' => $filename,
            'appointment' => $appointment,
            'calendar' => $calendar,
        );
    }

    /**
     * Generate HTML for PDF
     *
     * @param array $data Appointment data
     * @return string HTML content
     */
    private function generate_html(array $data): string {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title><?php echo esc_html__('Appointment Confirmation', 'ffc'); ?></title>
            <style>
                body {
                    font-family: Arial, Helvetica, sans-serif;
                    font-size: 14px;
                    line-height: 1.6;
                    color: #333;
                    margin: 0;
                    padding: 40px;
                }
                .header {
                    text-align: center;
                    margin-bottom: 40px;
                    padding-bottom: 20px;
                    border-bottom: 3px solid #0073aa;
                }
                .header h1 {
                    margin: 0;
                    color: #0073aa;
                    font-size: 28px;
                }
                .header .site-name {
                    font-size: 16px;
                    color: #666;
                    margin-top: 5px;
                }
                .status-badge {
                    display: inline-block;
                    padding: 8px 16px;
                    border-radius: 4px;
                    font-weight: bold;
                    font-size: 14px;
                    margin: 20px 0;
                }
                .status-pending {
                    background: #f0f0f1;
                    color: #646970;
                }
                .status-confirmed {
                    background: #d5e8d4;
                    color: #2e7d32;
                }
                .status-cancelled {
                    background: #f8d7da;
                    color: #b32d2e;
                }
                .content {
                    margin: 30px 0;
                }
                .info-section {
                    margin-bottom: 30px;
                }
                .info-section h2 {
                    font-size: 18px;
                    color: #0073aa;
                    margin-bottom: 15px;
                    border-bottom: 2px solid #e0e0e0;
                    padding-bottom: 5px;
                }
                .info-row {
                    margin-bottom: 12px;
                    display: flex;
                }
                .info-label {
                    font-weight: bold;
                    width: 150px;
                    color: #555;
                }
                .info-value {
                    flex: 1;
                    color: #333;
                }
                .footer {
                    margin-top: 50px;
                    padding-top: 20px;
                    border-top: 2px solid #e0e0e0;
                    text-align: center;
                    font-size: 12px;
                    color: #666;
                }
                .qr-code {
                    text-align: center;
                    margin: 30px 0;
                }
            </style>
        </head>
        <body>
            <div class="header">
                <h1><?php echo esc_html__('Appointment Confirmation', 'ffc'); ?></h1>
                <div class="site-name"><?php echo esc_html($data['site_name']); ?></div>
            </div>

            <div class="content">
                <!-- Status -->
                <div style="text-align: center;">
                    <span class="status-badge status-<?php echo esc_attr($data['status']); ?>">
                        <?php echo esc_html($data['status_label']); ?>
                    </span>
                </div>

                <!-- Calendar Info -->
                <div class="info-section">
                    <h2><?php echo esc_html__('Event Details', 'ffc'); ?></h2>
                    <div class="info-row">
                        <span class="info-label"><?php echo esc_html__('Event:', 'ffc'); ?></span>
                        <span class="info-value"><?php echo esc_html($data['calendar_title']); ?></span>
                    </div>
                    <?php if (!empty($data['calendar_description'])): ?>
                    <div class="info-row">
                        <span class="info-label"><?php echo esc_html__('Description:', 'ffc'); ?></span>
                        <span class="info-value"><?php echo esc_html($data['calendar_description']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Appointment Info -->
                <div class="info-section">
                    <h2><?php echo esc_html__('Appointment Information', 'ffc'); ?></h2>
                    <div class="info-row">
                        <span class="info-label"><?php echo esc_html__('Appointment ID:', 'ffc'); ?></span>
                        <span class="info-value">#<?php echo esc_html($data['appointment_id']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><?php echo esc_html__('Date:', 'ffc'); ?></span>
                        <span class="info-value"><?php echo esc_html($data['appointment_date']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><?php echo esc_html__('Time:', 'ffc'); ?></span>
                        <span class="info-value"><?php echo esc_html($data['start_time']); ?><?php echo !empty($data['end_time']) ? ' - ' . esc_html($data['end_time']) : ''; ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><?php echo esc_html__('Booked on:', 'ffc'); ?></span>
                        <span class="info-value"><?php echo esc_html($data['created_at']); ?></span>
                    </div>
                </div>

                <!-- Personal Info -->
                <div class="info-section">
                    <h2><?php echo esc_html__('Personal Information', 'ffc'); ?></h2>
                    <div class="info-row">
                        <span class="info-label"><?php echo esc_html__('Name:', 'ffc'); ?></span>
                        <span class="info-value"><?php echo esc_html($data['name']); ?></span>
                    </div>
                    <?php if (!empty($data['email'])): ?>
                    <div class="info-row">
                        <span class="info-label"><?php echo esc_html__('Email:', 'ffc'); ?></span>
                        <span class="info-value"><?php echo esc_html($data['email']); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($data['phone'])): ?>
                    <div class="info-row">
                        <span class="info-label"><?php echo esc_html__('Phone:', 'ffc'); ?></span>
                        <span class="info-value"><?php echo esc_html($data['phone']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Notes -->
                <?php if (!empty($data['user_notes'])): ?>
                <div class="info-section">
                    <h2><?php echo esc_html__('Notes', 'ffc'); ?></h2>
                    <div class="info-value"><?php echo nl2br(esc_html($data['user_notes'])); ?></div>
                </div>
                <?php endif; ?>
            </div>

            <div class="footer">
                <p><?php echo esc_html(sprintf(__('Generated on %s', 'ffc'), date_i18n(get_option('date_format') . ' ' . get_option('time_format')))); ?></p>
                <p><?php echo esc_html($data['site_name']); ?> - <?php echo esc_html($data['site_url']); ?></p>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * Generate filename for PDF
     *
     * @param string $calendar_title Calendar title
     * @param int $appointment_id Appointment ID
     * @return string Filename
     */
    private function generate_filename(string $calendar_title, int $appointment_id): string {
        // Sanitize calendar title for filename
        $safe_title = sanitize_title($calendar_title);
        $safe_title = substr($safe_title, 0, 30); // Limit length

        return sprintf(
            'appointment-%s-%d-%s.pdf',
            $safe_title,
            $appointment_id,
            date('Y-m-d')
        );
    }
}

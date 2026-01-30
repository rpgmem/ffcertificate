<?php
declare(strict_types=1);

/**
 * Appointment Receipt Handler
 *
 * Handles displaying and printing appointment receipts/confirmations
 *
 * @since 4.1.1
 * @version 4.1.1
 */

namespace FreeFormCertificate\Calendars;

if (!defined('ABSPATH')) exit;

class AppointmentReceiptHandler {

    /**
     * Constructor
     */
    public function __construct() {
        // Register query var for receipt token
        add_filter('query_vars', array($this, 'add_query_vars'));

        // Hook into template redirect to catch receipt requests
        add_action('template_redirect', array($this, 'handle_receipt_request'));
    }

    /**
     * Add custom query vars
     *
     * @param array $vars
     * @return array
     */
    public function add_query_vars(array $vars): array {
        $vars[] = 'ffc_appointment_receipt';
        $vars[] = 'token';
        return $vars;
    }

    /**
     * Handle receipt request
     *
     * @return void
     */
    public function handle_receipt_request(): void {
        if (!get_query_var('ffc_appointment_receipt')) {
            return;
        }

        $appointment_id = absint(get_query_var('ffc_appointment_receipt'));
        $token = get_query_var('token');

        if (!$appointment_id) {
            wp_die(__('Invalid appointment receipt request.', 'ffc'), 400);
        }

        // Load repositories
        $appointment_repo = new \FreeFormCertificate\Repositories\AppointmentRepository();
        $calendar_repo = new \FreeFormCertificate\Repositories\CalendarRepository();

        // Get appointment
        $appointment = $appointment_repo->findById($appointment_id);

        if (!$appointment) {
            wp_die(__('Appointment not found.', 'ffc'), 404);
        }

        // Verify access (either logged in user owns it, admin, or has valid token)
        $has_access = false;

        if (current_user_can('manage_options')) {
            $has_access = true;
        } elseif (is_user_logged_in() && $appointment['user_id'] == get_current_user_id()) {
            $has_access = true;
        } elseif (!empty($token) && !empty($appointment['confirmation_token']) && $token === $appointment['confirmation_token']) {
            $has_access = true;
        }

        if (!$has_access) {
            wp_die(__('You do not have permission to view this appointment receipt.', 'ffc'), 403);
        }

        // Get calendar (may be null if deleted)
        $calendar = null;
        if (!empty($appointment['calendar_id'])) {
            $calendar = $calendar_repo->findById((int)$appointment['calendar_id']);
        }

        // If calendar was deleted, create a placeholder
        if (!$calendar) {
            $calendar = array(
                'id' => 0,
                'title' => __('(Calendar Deleted)', 'ffc'),
                'description' => '',
            );
        }

        // Generate and display receipt
        $this->display_receipt($appointment, $calendar);
        exit;
    }

    /**
     * Enqueue PDF generation scripts
     *
     * @return void
     */
    private function enqueue_pdf_scripts(): void {
        // Enqueue html2canvas
        wp_enqueue_script(
            'html2canvas',
            'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js',
            array(),
            '1.4.1',
            true
        );

        // Enqueue jsPDF
        wp_enqueue_script(
            'jspdf',
            'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js',
            array(),
            '2.5.1',
            true
        );

        // Enqueue PDF generator (reuse from certificates)
        wp_enqueue_script(
            'ffc-pdf-generator',
            FFC_PLUGIN_URL . 'assets/js/ffc-pdf-generator.js',
            array('jquery', 'html2canvas', 'jspdf'),
            FFC_VERSION,
            true
        );
    }

    /**
     * Display receipt HTML
     *
     * @param array $appointment
     * @param array $calendar
     * @return void
     */
    private function display_receipt(array $appointment, array $calendar): void {
        // Enqueue PDF scripts
        $this->enqueue_pdf_scripts();

        // Decrypt sensitive data with safety checks
        $email = $appointment['email'] ?? '';
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

        // Format dates with validation
        $date_format = get_option('date_format');
        $time_format = get_option('time_format');

        // Validate and format appointment date
        $appointment_date = __('N/A', 'ffc');
        if (!empty($appointment['appointment_date'])) {
            $timestamp = strtotime($appointment['appointment_date']);
            if ($timestamp !== false) {
                $appointment_date = date_i18n($date_format, $timestamp);
            }
        }

        // Validate and format start time
        $start_time = __('N/A', 'ffc');
        if (!empty($appointment['start_time'])) {
            $timestamp = strtotime($appointment['start_time']);
            if ($timestamp !== false) {
                $start_time = date_i18n($time_format, $timestamp);
            }
        }

        // Validate and format end time
        $end_time = '';
        if (!empty($appointment['end_time'])) {
            $timestamp = strtotime($appointment['end_time']);
            if ($timestamp !== false) {
                $end_time = date_i18n($time_format, $timestamp);
            }
        }

        // Validate and format created at
        $created_at = __('N/A', 'ffc');
        if (!empty($appointment['created_at'])) {
            $timestamp = strtotime($appointment['created_at']);
            if ($timestamp !== false) {
                $created_at = date_i18n($date_format . ' ' . $time_format, $timestamp);
            }
        }

        // Status label with safety check
        $status_labels = array(
            'pending' => __('Pending Approval', 'ffc'),
            'confirmed' => __('Confirmed', 'ffc'),
            'cancelled' => __('Cancelled', 'ffc'),
            'completed' => __('Completed', 'ffc'),
            'no_show' => __('No Show', 'ffc'),
        );
        $appointment_status = $appointment['status'] ?? 'pending';
        $status_label = $status_labels[$appointment_status] ?? $appointment_status;

        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo esc_html__('Appointment Receipt', 'ffc'); ?> #<?php echo esc_html($appointment['id'] ?? '0'); ?></title>
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                    font-size: 14px;
                    line-height: 1.6;
                    color: #333;
                    background: #f5f5f5;
                    padding: 20px;
                }
                .receipt-container {
                    max-width: 800px;
                    margin: 0 auto;
                    background: white;
                    padding: 40px;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                }
                .header {
                    text-align: center;
                    margin-bottom: 40px;
                    padding-bottom: 20px;
                    border-bottom: 3px solid #0073aa;
                }
                .header h1 {
                    margin: 0 0 10px 0;
                    color: #0073aa;
                    font-size: 32px;
                }
                .header .site-name {
                    font-size: 18px;
                    color: #666;
                }
                .status-badge {
                    display: inline-block;
                    padding: 10px 20px;
                    border-radius: 5px;
                    font-weight: bold;
                    font-size: 16px;
                    margin: 20px 0;
                    text-transform: uppercase;
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
                .status-completed {
                    background: #cfe2ff;
                    color: #004085;
                }
                .info-section {
                    margin-bottom: 30px;
                }
                .info-section h2 {
                    font-size: 20px;
                    color: #0073aa;
                    margin-bottom: 15px;
                    border-bottom: 2px solid #e0e0e0;
                    padding-bottom: 8px;
                }
                .info-row {
                    margin-bottom: 12px;
                    padding: 8px 0;
                    border-bottom: 1px solid #f0f0f0;
                }
                .info-label {
                    font-weight: bold;
                    color: #555;
                    display: inline-block;
                    min-width: 150px;
                }
                .info-value {
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
                .action-buttons {
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    z-index: 1000;
                    display: flex;
                    gap: 10px;
                }
                .action-button {
                    background: #0073aa;
                    color: white;
                    border: none;
                    padding: 12px 24px;
                    border-radius: 5px;
                    cursor: pointer;
                    font-size: 16px;
                    box-shadow: 0 2px 5px rgba(0,0,0,0.2);
                    text-decoration: none;
                    display: inline-block;
                }
                .action-button:hover {
                    background: #005a87;
                }
                .action-button.secondary {
                    background: #50575e;
                }
                .action-button.secondary:hover {
                    background: #3c434a;
                }
                @media print {
                    body {
                        background: white;
                        padding: 0;
                    }
                    .receipt-container {
                        box-shadow: none;
                        padding: 20px;
                    }
                    .action-buttons {
                        display: none;
                    }
                }
            </style>
        </head>
        <body>
            <div class="action-buttons">
                <button class="action-button" id="ffc-download-pdf-btn">📥 <?php echo esc_html__('Download PDF', 'ffc'); ?></button>
                <button class="action-button secondary" onclick="window.print()">🖨️ <?php echo esc_html__('Print', 'ffc'); ?></button>
            </div>

            <div class="receipt-container" id="ffc-receipt-content">
                <div class="header">
                    <h1><?php echo esc_html__('Appointment Receipt', 'ffc'); ?></h1>
                    <div class="site-name"><?php bloginfo('name'); ?></div>
                </div>

                <div style="text-align: center;">
                    <span class="status-badge status-<?php echo esc_attr($appointment_status); ?>">
                        <?php echo esc_html($status_label); ?>
                    </span>
                </div>

                <div class="info-section">
                    <h2><?php echo esc_html__('Event Details', 'ffc'); ?></h2>
                    <div class="info-row">
                        <span class="info-label"><?php echo esc_html__('Event:', 'ffc'); ?></span>
                        <span class="info-value"><?php echo esc_html($calendar['title'] ?? __('N/A', 'ffc')); ?></span>
                    </div>
                    <?php if (!empty($calendar['description'])): ?>
                    <div class="info-row">
                        <span class="info-label"><?php echo esc_html__('Description:', 'ffc'); ?></span>
                        <span class="info-value"><?php echo esc_html($calendar['description']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="info-section">
                    <h2><?php echo esc_html__('Appointment Information', 'ffc'); ?></h2>
                    <div class="info-row">
                        <span class="info-label"><?php echo esc_html__('Appointment ID:', 'ffc'); ?></span>
                        <span class="info-value">#<?php echo esc_html($appointment['id'] ?? '0'); ?></span>
                    </div>
                    <?php if (!empty($appointment['validation_code'])): ?>
                    <div class="info-row">
                        <span class="info-label"><?php echo esc_html__('Validation Code:', 'ffc'); ?></span>
                        <span class="info-value" style="font-weight: bold; font-size: 1.1em; letter-spacing: 1px;"><?php echo esc_html(\FreeFormCertificate\Core\Utils::format_auth_code($appointment['validation_code'])); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="info-row">
                        <span class="info-label"><?php echo esc_html__('Date:', 'ffc'); ?></span>
                        <span class="info-value"><?php echo esc_html($appointment_date); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><?php echo esc_html__('Time:', 'ffc'); ?></span>
                        <span class="info-value"><?php echo esc_html($start_time); ?><?php echo !empty($end_time) ? ' - ' . esc_html($end_time) : ''; ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label"><?php echo esc_html__('Booked on:', 'ffc'); ?></span>
                        <span class="info-value"><?php echo esc_html($created_at); ?></span>
                    </div>
                </div>

                <div class="info-section">
                    <h2><?php echo esc_html__('Personal Information', 'ffc'); ?></h2>
                    <div class="info-row">
                        <span class="info-label"><?php echo esc_html__('Name:', 'ffc'); ?></span>
                        <span class="info-value"><?php echo esc_html($appointment['name'] ?? ''); ?></span>
                    </div>
                    <?php if (!empty($email)): ?>
                    <div class="info-row">
                        <span class="info-label"><?php echo esc_html__('Email:', 'ffc'); ?></span>
                        <span class="info-value"><?php echo esc_html($email); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($phone)): ?>
                    <div class="info-row">
                        <span class="info-label"><?php echo esc_html__('Phone:', 'ffc'); ?></span>
                        <span class="info-value"><?php echo esc_html($phone); ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if (!empty($appointment['user_notes'])): ?>
                <div class="info-section">
                    <h2><?php echo esc_html__('Notes', 'ffc'); ?></h2>
                    <div class="info-value"><?php echo nl2br(esc_html($appointment['user_notes'])); ?></div>
                </div>
                <?php endif; ?>

                <div class="footer">
                    <p><?php echo esc_html(sprintf(__('Generated on %s', 'ffc'), date_i18n(get_option('date_format') . ' ' . get_option('time_format')))); ?></p>
                    <p><?php bloginfo('name'); ?> - <?php bloginfo('url'); ?></p>
                </div>
            </div>

            <?php wp_print_scripts('jquery'); ?>
            <?php wp_print_scripts('html2canvas'); ?>
            <?php wp_print_scripts('jspdf'); ?>
            <?php wp_print_scripts('ffc-pdf-generator'); ?>

            <script>
            jQuery(document).ready(function($) {
                $('#ffc-download-pdf-btn').on('click', function() {
                    var $receiptContent = $('#ffc-receipt-content');

                    // Clone the receipt content
                    var htmlContent = $receiptContent.html();

                    // Generate filename
                    var appointmentId = '<?php echo esc_js($appointment['id'] ?? '0'); ?>';
                    var validationCode = '<?php echo esc_js(!empty($appointment['validation_code']) ? \FreeFormCertificate\Core\Utils::format_auth_code($appointment['validation_code']) : ''); ?>';
                    var filename = validationCode ?
                        'Appointment_Receipt_' + validationCode + '.pdf' :
                        'Appointment_Receipt_' + appointmentId + '.pdf';

                    // Prepare PDF data
                    var pdfData = {
                        template: htmlContent,
                        bg_image: null
                    };

                    // Call PDF generator
                    if (typeof window.ffcGeneratePDF === 'function') {
                        window.ffcGeneratePDF(pdfData, filename);
                    } else {
                        console.error('FFC PDF Generator not loaded');
                        alert('<?php echo esc_js(__('Error: PDF generator not loaded. Please refresh the page.', 'ffc')); ?>');
                    }
                });
            });
            </script>
        </body>
        </html>
        <?php
    }

    /**
     * Generate receipt URL for an appointment
     *
     * @param int $appointment_id
     * @param string $token Optional confirmation token for guest access
     * @return string
     */
    public static function get_receipt_url(int $appointment_id, string $token = ''): string {
        $url = home_url();
        $url = add_query_arg('ffc_appointment_receipt', $appointment_id, $url);

        if (!empty($token)) {
            $url = add_query_arg('token', $token, $url);
        }

        return $url;
    }
}

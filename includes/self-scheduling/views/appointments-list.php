<?php
/**
 * Appointments List View
 *
 * Displays all appointments with filters and export options.
 *
 * @since 4.1.0
 * @version 5.0.0 - Fixed URLs to use absolute paths and removed action=view to prevent 500 errors
 */

if (!defined('ABSPATH')) exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables scoped to this file

// Include WP List Table class
if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Appointments List Table
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Internal class, not part of public API.
if (!class_exists('FFC_Appointments_List_Table')) :

class FFC_Appointments_List_Table extends WP_List_Table {

    /** @var \FreeFormCertificate\Repositories\AppointmentRepository */
    private $appointment_repository;

    /** @var \FreeFormCertificate\Repositories\CalendarRepository */
    private $calendar_repository;

    public function __construct() {
        parent::__construct(array(
            'singular' => 'appointment',
            'plural'   => 'appointments',
            'ajax'     => false
        ));

        $this->appointment_repository = new \FreeFormCertificate\Repositories\AppointmentRepository();
        $this->calendar_repository = new \FreeFormCertificate\Repositories\CalendarRepository();
    }

    /**
     * Get columns
     *
     * @return array<string, string>
     */
    public function get_columns(): array {
        return array(
            'cb'              => '<input type="checkbox" />',
            'id'              => __('ID', 'ffcertificate'),
            'calendar'        => __('Calendar', 'ffcertificate'),
            'name'            => __('Name', 'ffcertificate'),
            'email'           => __('Email', 'ffcertificate'),
            'appointment_date'=> __('Date', 'ffcertificate'),
            'time'            => __('Time', 'ffcertificate'),
            'status'          => __('Status', 'ffcertificate'),
            'created_at'      => __('Created', 'ffcertificate')
        );
    }

    /**
     * Get sortable columns
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public function get_sortable_columns(): array {
        return array(
            'id'              => array('id', true),
            'calendar'        => array('calendar_id', false),
            'appointment_date'=> array('appointment_date', true),
            'status'          => array('status', false),
            'created_at'      => array('created_at', true)
        );
    }

    /**
     * Column default
     *
     * @param array<string, mixed> $item        Row data.
     * @param string               $column_name Column slug.
     * @return string
     */
    public function column_default($item, $column_name) {
        return esc_html($item[$column_name] ?? '-');
    }

    /**
     * Checkbox column
     *
     * @param array<string, mixed> $item Row data.
     */
    public function column_cb($item): string {
        return sprintf('<input type="checkbox" name="appointment[]" value="%d" />', $item['id']);
    }

    /**
     * ID column
     *
     * @param array<string, mixed> $item Row data.
     */
    public function column_id($item): string {
        $actions = array();
        $ffc_page_slug = 'ffc-appointments';

        if ($item['status'] === 'pending') {
            $confirm_url = wp_nonce_url(
                add_query_arg(
                    array('page' => $ffc_page_slug, 'ffc_action' => 'confirm', 'appointment' => $item['id']),
                    admin_url('admin.php')
                ),
                'ffc_confirm_appointment_' . $item['id']
            );
            $actions['confirm'] = sprintf(
                '<a href="%s">%s</a>',
                esc_url($confirm_url),
                __('Confirm', 'ffcertificate')
            );
        }

        if (in_array($item['status'], ['pending', 'confirmed'])) {
            $cancel_url = wp_nonce_url(
                add_query_arg(
                    array('page' => $ffc_page_slug, 'ffc_action' => 'cancel', 'appointment' => $item['id']),
                    admin_url('admin.php')
                ),
                'ffc_cancel_appointment_' . $item['id']
            );
            $actions['cancel'] = sprintf(
                '<a href="#" onclick="var r=prompt(\'%s\'); if(r && r.length >= 5){window.location=\'%s&reason=\'+encodeURIComponent(r);} return false;" style="color: #b32d2e;">%s</a>',
                esc_js(__('Please provide a reason for cancellation (minimum 5 characters):', 'ffcertificate')),
                esc_url($cancel_url),
                __('Cancel', 'ffcertificate')
            );
        }

        // View link: uses just appointment=X (no action parameter to avoid admin_action_view dispatch)
        $view_url = add_query_arg(
            array('page' => $ffc_page_slug, 'appointment' => $item['id']),
            admin_url('admin.php')
        );
        $actions['view'] = sprintf(
            '<a href="%s">%s</a>',
            esc_url($view_url),
            __('View', 'ffcertificate')
        );

        // Add receipt link (magic link to /valid/ page) - not for cancelled appointments
        $item_status = $item['status'] ?? 'pending';
        if ( $item_status !== 'cancelled' ) {
            $confirmation_token = $item['confirmation_token'] ?? '';
            if ( ! empty( $confirmation_token ) && class_exists( '\\FreeFormCertificate\\Generators\\MagicLinkHelper' ) ) {
                $receipt_url = \FreeFormCertificate\Generators\MagicLinkHelper::generate_magic_link( $confirmation_token );
            } else {
                $receipt_url = \FreeFormCertificate\SelfScheduling\AppointmentReceiptHandler::get_receipt_url(
                    (int)$item['id'],
                    $confirmation_token
                );
            }
            $actions['receipt'] = sprintf(
                '<a href="%s" target="_blank">%s</a>',
                esc_url($receipt_url),
                __('View Receipt', 'ffcertificate')
            );
        }

        return sprintf('#%d %s', $item['id'], $this->row_actions($actions));
    }

    /**
     * Calendar column
     *
     * @param array<string, mixed> $item Row data.
     */
    public function column_calendar($item): string {
        $calendar = $this->calendar_repository->findById((int)$item['calendar_id']);
        if ($calendar) {
            $edit_url = admin_url('post.php?post=' . $calendar['post_id'] . '&action=edit');
            return sprintf('<a href="%s">%s</a>', esc_url($edit_url), esc_html($calendar['title']));
        }
        return __('(Deleted)', 'ffcertificate');
    }

    /**
     * Name column
     *
     * @param array<string, mixed> $item Row data.
     */
    public function column_name($item): string {
        if (!empty($item['user_id'])) {
            $user = get_user_by('id', $item['user_id']);
            if ($user) {
                return esc_html($user->display_name);
            }
        }
        return esc_html($item['name'] ?? __('(Guest)', 'ffcertificate'));
    }

    /**
     * Email column (with decryption support)
     *
     * @param array<string, mixed> $item Row data.
     */
    public function column_email($item): string {
        $email = \FreeFormCertificate\Core\Encryption::decrypt_field($item, 'email');
        return $email ? esc_html($email) : '-';
    }

    /**
     * Time column
     *
     * @param array<string, mixed> $item Row data.
     */
    public function column_time($item): string {
        $start = gmdate('H:i', strtotime($item['start_time']));
        $end = gmdate('H:i', strtotime($item['end_time']));
        return esc_html($start . ' - ' . $end);
    }

    /**
     * Status column
     *
     * @param array<string, mixed> $item Row data.
     */
    public function column_status($item): string {
        $status_labels = array(
            'pending'   => '<span class="ffc-status ffc-status-pending">' . __('Pending', 'ffcertificate') . '</span>',
            'confirmed' => '<span class="ffc-status ffc-status-confirmed">' . __('Confirmed', 'ffcertificate') . '</span>',
            'cancelled' => '<span class="ffc-status ffc-status-cancelled">' . __('Cancelled', 'ffcertificate') . '</span>',
            'completed' => '<span class="ffc-status ffc-status-completed">' . __('Completed', 'ffcertificate') . '</span>',
            'no_show'   => '<span class="ffc-status ffc-status-noshow">' . __('No Show', 'ffcertificate') . '</span>',
        );

        return $status_labels[$item['status']] ?? esc_html($item['status']);
    }

    /**
     * Prepare items
     */
    public function prepare_items(): void {
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;

        // Get filter parameters
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Standard WP_List_Table filter parameters.
        $calendar_id = isset($_GET['calendar_id']) ? absint(wp_unslash($_GET['calendar_id'])) : 0;
        $status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        // Build conditions
        $conditions = array();
        if ($calendar_id) {
            $conditions['calendar_id'] = $calendar_id;
        }
        if ($status) {
            $conditions['status'] = $status;
        }

        // Get items
        $items = $this->appointment_repository->findAll($conditions, 'created_at', 'DESC', $per_page, $offset);
        $total_items = $this->appointment_repository->count($conditions);

        $this->items = $items;

        $this->set_pagination_args(array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil($total_items / $per_page)
        ));

        $this->_column_headers = array(
            $this->get_columns(),
            array(), // Hidden columns
            $this->get_sortable_columns()
        );
    }

    /**
     * Display filters
     */
    protected function extra_tablenav($which): void {
        if ($which !== 'top') {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display filter parameters for dropdown selection.
        $calendar_id = isset($_GET['calendar_id']) ? absint(wp_unslash($_GET['calendar_id'])) : 0;
        $status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        // Get all calendars for filter
        $calendars = $this->calendar_repository->getActiveCalendars();

        ?>
        <div class="alignleft actions">
            <select name="calendar_id">
                <option value=""><?php esc_html_e('All Calendars', 'ffcertificate'); ?></option>
                <?php foreach ($calendars as $calendar): ?>
                    <option value="<?php echo esc_attr($calendar['id']); ?>" <?php selected($calendar_id, $calendar['id']); ?>>
                        <?php echo esc_html($calendar['title']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="status">
                <option value=""><?php esc_html_e('All Statuses', 'ffcertificate'); ?></option>
                <option value="pending" <?php selected($status, 'pending'); ?>><?php esc_html_e('Pending', 'ffcertificate'); ?></option>
                <option value="confirmed" <?php selected($status, 'confirmed'); ?>><?php esc_html_e('Confirmed', 'ffcertificate'); ?></option>
                <option value="cancelled" <?php selected($status, 'cancelled'); ?>><?php esc_html_e('Cancelled', 'ffcertificate'); ?></option>
                <option value="completed" <?php selected($status, 'completed'); ?>><?php esc_html_e('Completed', 'ffcertificate'); ?></option>
                <option value="no_show" <?php selected($status, 'no_show'); ?>><?php esc_html_e('No Show', 'ffcertificate'); ?></option>
            </select>

            <?php submit_button(__('Filter', 'ffcertificate'), '', 'filter_action', false); ?>
        </div>
        <?php
    }
}

endif; // class_exists guard

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nonce verified per-action below via check_admin_referer.

// Appointments base URL (used for redirects and back links)
$ffcertificate_appointments_url = add_query_arg(array('page' => 'ffc-appointments'), admin_url('admin.php'));

// Determine if viewing a specific appointment:
//   - appointment=X alone → view
//   - appointment=X + ffc_action=confirm|cancel → mutation
$ffc_self_scheduling_appointment_id = isset($_GET['appointment']) ? absint(wp_unslash($_GET['appointment'])) : 0;
$ffcertificate_action = isset($_GET['ffc_action']) ? sanitize_text_field(wp_unslash($_GET['ffc_action'])) : '';

// phpcs:enable WordPress.Security.NonceVerification.Recommended

if ($ffc_self_scheduling_appointment_id > 0) {

    // Verify user has admin permissions
    if (!\FreeFormCertificate\Core\Utils::current_user_can_manage()) {
        wp_die(esc_html__('You do not have permission to perform this action.', 'ffcertificate'));
    }

    $ffcertificate_repo = new \FreeFormCertificate\Repositories\AppointmentRepository();

    // Handle mutations (confirm/cancel) — these redirect and exit
    if ($ffcertificate_action === 'confirm') {
        check_admin_referer('ffc_confirm_appointment_' . $ffc_self_scheduling_appointment_id);
        $ffcertificate_result = $ffcertificate_repo->confirm($ffc_self_scheduling_appointment_id, get_current_user_id());

        if ($ffcertificate_result) {
            // Send approval notification email with receipt link
            $ffcertificate_appointment = $ffcertificate_repo->findById($ffc_self_scheduling_appointment_id);
            if ($ffcertificate_appointment && !empty($ffcertificate_appointment['calendar_id'])) {
                $ffcertificate_cal_repo = new \FreeFormCertificate\Repositories\CalendarRepository();
                $ffcertificate_calendar = $ffcertificate_cal_repo->findById((int) $ffcertificate_appointment['calendar_id']);
                if ($ffcertificate_calendar) {
                    do_action('ffcertificate_self_scheduling_appointment_confirmed_email', $ffcertificate_appointment, $ffcertificate_calendar);
                }
            }

            set_transient('ffc_admin_notice_' . get_current_user_id(), array(
                'type' => 'success',
                'message' => __('Appointment confirmed successfully.', 'ffcertificate')
            ), 30);
        } else {
            set_transient('ffc_admin_notice_' . get_current_user_id(), array(
                'type' => 'error',
                'message' => __('Failed to confirm appointment.', 'ffcertificate')
            ), 30);
        }

        wp_safe_redirect($ffcertificate_appointments_url);
        exit;

    } elseif ($ffcertificate_action === 'cancel') {
        check_admin_referer('ffc_cancel_appointment_' . $ffc_self_scheduling_appointment_id);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $ffcertificate_cancel_reason = isset($_GET['reason']) ? sanitize_textarea_field(wp_unslash($_GET['reason'])) : __('Cancelled by admin', 'ffcertificate');
        $ffcertificate_result = $ffcertificate_repo->cancel($ffc_self_scheduling_appointment_id, get_current_user_id(), $ffcertificate_cancel_reason);

        if ($ffcertificate_result) {
            set_transient('ffc_admin_notice_' . get_current_user_id(), array(
                'type' => 'success',
                'message' => __('Appointment cancelled successfully.', 'ffcertificate')
            ), 30);
        } else {
            set_transient('ffc_admin_notice_' . get_current_user_id(), array(
                'type' => 'error',
                'message' => __('Failed to cancel appointment.', 'ffcertificate')
            ), 30);
        }

        wp_safe_redirect($ffcertificate_appointments_url);
        exit;

    } else {
        // Default: View appointment detail
        try {
            $ffcertificate_appointment = $ffcertificate_repo->findById($ffc_self_scheduling_appointment_id);
            if (!$ffcertificate_appointment) {
                echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__('Appointment not found.', 'ffcertificate') . '</p></div></div>';
                return;
            }

            // Get calendar info
            $ffcertificate_cal_repo = new \FreeFormCertificate\Repositories\CalendarRepository();
            $ffcertificate_calendar = !empty($ffcertificate_appointment['calendar_id'])
                ? $ffcertificate_cal_repo->findById((int) $ffcertificate_appointment['calendar_id'])
                : null;
            $ffcertificate_calendar_title = $ffcertificate_calendar ? $ffcertificate_calendar['title'] : __('(Deleted)', 'ffcertificate');

            // Decrypt sensitive fields
            $ffcertificate_decrypted = $ffcertificate_appointment;
            if (class_exists('\\FreeFormCertificate\\Core\\Encryption')) {
                try {
                    $ffcertificate_decrypted = \FreeFormCertificate\Core\Encryption::decrypt_appointment($ffcertificate_appointment);
                } catch (\Throwable $decrypt_err) {
                    // Decryption failed — continue with raw data
                }
            }

            // Resolve display values
            $ffcertificate_email = $ffcertificate_decrypted['email'] ?? '';
            $ffcertificate_phone = $ffcertificate_decrypted['phone'] ?? '';
            $ffcertificate_cpf   = $ffcertificate_decrypted['cpf'] ?? '';
            $ffcertificate_rf    = $ffcertificate_decrypted['rf'] ?? '';

            $ffcertificate_name = '';
            if (!empty($ffcertificate_appointment['user_id'])) {
                $ffcertificate_user = get_user_by('id', $ffcertificate_appointment['user_id']);
                if ($ffcertificate_user) {
                    $ffcertificate_name = $ffcertificate_user->display_name;
                }
            }
            if (empty($ffcertificate_name)) {
                $ffcertificate_name = $ffcertificate_appointment['name'] ?? __('(Guest)', 'ffcertificate');
            }

            // Decode custom data
            $ffcertificate_custom_data = array();
            if (!empty($ffcertificate_decrypted['custom_data'])) {
                $ffcertificate_custom_data = is_array($ffcertificate_decrypted['custom_data'])
                    ? $ffcertificate_decrypted['custom_data']
                    : (json_decode($ffcertificate_decrypted['custom_data'], true) ?: array());
            } elseif (!empty($ffcertificate_appointment['custom_data'])) {
                $ffcertificate_custom_data = json_decode($ffcertificate_appointment['custom_data'], true) ?: array();
            }

            // Status labels
            $ffcertificate_status_labels = array(
                'pending'   => __('Pending', 'ffcertificate'),
                'confirmed' => __('Confirmed', 'ffcertificate'),
                'cancelled' => __('Cancelled', 'ffcertificate'),
                'completed' => __('Completed', 'ffcertificate'),
                'no_show'   => __('No Show', 'ffcertificate'),
            );
            $ffcertificate_status_text = $ffcertificate_status_labels[$ffcertificate_appointment['status']] ?? $ffcertificate_appointment['status'];

            // Render detail view
            ?>
            <div class="wrap">
                <h1 class="wp-heading-inline">
                    <?php
                    printf(
                        /* translators: %d: appointment ID */
                        esc_html__('Appointment #%d', 'ffcertificate'),
                        $ffc_self_scheduling_appointment_id
                    );
                    ?>
                </h1>
                <a href="<?php echo esc_url($ffcertificate_appointments_url); ?>" class="page-title-action"><?php esc_html_e('Back to List', 'ffcertificate'); ?></a>
                <hr class="wp-header-end">

                <div id="poststuff">
                    <div id="post-body" class="metabox-holder columns-2">
                        <div id="post-body-content">
                            <div class="postbox">
                                <div class="postbox-header"><h2 class="hndle"><?php esc_html_e('Appointment Details', 'ffcertificate'); ?></h2></div>
                                <div class="inside">
                                    <table class="form-table">
                                        <tr><th><?php esc_html_e('Status', 'ffcertificate'); ?></th><td><span class="ffc-status ffc-status-<?php echo esc_attr($ffcertificate_appointment['status']); ?>"><?php echo esc_html($ffcertificate_status_text); ?></span></td></tr>
                                        <tr><th><?php esc_html_e('Calendar', 'ffcertificate'); ?></th><td><?php echo esc_html($ffcertificate_calendar_title); ?></td></tr>
                                        <tr><th><?php esc_html_e('Date', 'ffcertificate'); ?></th><td><?php echo esc_html($ffcertificate_appointment['appointment_date'] ?? '-'); ?></td></tr>
                                        <tr><th><?php esc_html_e('Time', 'ffcertificate'); ?></th><td><?php echo esc_html(($ffcertificate_appointment['start_time'] ?? '') . ' - ' . ($ffcertificate_appointment['end_time'] ?? '')); ?></td></tr>
                                        <tr><th><?php esc_html_e('Name', 'ffcertificate'); ?></th><td><?php echo esc_html($ffcertificate_name); ?></td></tr>
                                        <tr><th><?php esc_html_e('E-mail', 'ffcertificate'); ?></th><td><?php echo esc_html($ffcertificate_email ?: '-'); ?></td></tr>
                                        <tr><th><?php esc_html_e('Phone', 'ffcertificate'); ?></th><td><?php echo esc_html($ffcertificate_phone ?: '-'); ?></td></tr>
                                        <?php if (!empty($ffcertificate_cpf)): ?>
                                        <tr><th><?php esc_html_e('CPF', 'ffcertificate'); ?></th><td><?php echo esc_html(\FreeFormCertificate\Core\Utils::format_document($ffcertificate_cpf)); ?></td></tr>
                                        <?php endif; ?>
                                        <?php if (!empty($ffcertificate_rf)): ?>
                                        <tr><th><?php esc_html_e('RF', 'ffcertificate'); ?></th><td><?php echo esc_html(\FreeFormCertificate\Core\Utils::format_document($ffcertificate_rf)); ?></td></tr>
                                        <?php endif; ?>
                                        <?php if (!empty($ffcertificate_appointment['validation_code'])): ?>
                                        <tr><th><?php esc_html_e('Validation Code', 'ffcertificate'); ?></th><td><code><?php echo esc_html(\FreeFormCertificate\Core\Utils::format_auth_code($ffcertificate_appointment['validation_code'])); ?></code></td></tr>
                                        <?php endif; ?>
                                        <?php if (!empty($ffcertificate_appointment['user_notes'])): ?>
                                        <tr><th><?php esc_html_e('User Notes', 'ffcertificate'); ?></th><td><?php echo esc_html($ffcertificate_appointment['user_notes']); ?></td></tr>
                                        <?php endif; ?>
                                        <?php if (!empty($ffcertificate_appointment['admin_notes'])): ?>
                                        <tr><th><?php esc_html_e('Admin Notes', 'ffcertificate'); ?></th><td><?php echo esc_html($ffcertificate_appointment['admin_notes']); ?></td></tr>
                                        <?php endif; ?>
                                        <tr><th><?php esc_html_e('Created', 'ffcertificate'); ?></th><td><?php echo esc_html($ffcertificate_appointment['created_at'] ?? '-'); ?></td></tr>
                                        <?php if (!empty($ffcertificate_appointment['confirmation_token'])): ?>
                                        <tr><th><?php esc_html_e('Confirmation Token', 'ffcertificate'); ?></th><td><code><?php echo esc_html($ffcertificate_appointment['confirmation_token']); ?></code></td></tr>
                                        <?php endif; ?>
                                    </table>

                                    <?php if (!empty($ffcertificate_custom_data)): ?>
                                    <h3><?php esc_html_e('Custom Fields', 'ffcertificate'); ?></h3>
                                    <table class="form-table">
                                        <?php foreach ($ffcertificate_custom_data as $ffcertificate_field_key => $ffcertificate_field_val): ?>
                                        <tr>
                                            <th><?php echo esc_html(ucwords(str_replace(array('_', '-'), ' ', $ffcertificate_field_key))); ?></th>
                                            <td><?php echo esc_html(is_array($ffcertificate_field_val) ? implode(', ', $ffcertificate_field_val) : (string) $ffcertificate_field_val); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </table>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php
        } catch (\Throwable $e) {
            echo '<div class="wrap">';
            echo '<h1>' . esc_html__('Appointment Details', 'ffcertificate') . '</h1>';
            echo '<div class="notice notice-error"><p><strong>' . esc_html__('Error loading appointment:', 'ffcertificate') . '</strong> ' . esc_html($e->getMessage()) . '</p></div>';
            echo '<p><a href="' . esc_url($ffcertificate_appointments_url) . '" class="button">' . esc_html__('Back to List', 'ffcertificate') . '</a></p>';
            echo '</div>';
            if (class_exists('\\FreeFormCertificate\\Core\\Utils')) {
                \FreeFormCertificate\Core\Utils::debug_log('Appointment view error', array(
                    'id' => $ffc_self_scheduling_appointment_id,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ));
            }
        }

        return; // Stop — don't render the list table
    }
}

// Display admin notices from transients
$ffcertificate_admin_notice = get_transient('ffc_admin_notice_' . get_current_user_id());
if ($ffcertificate_admin_notice && is_array($ffcertificate_admin_notice)) {
    $ffcertificate_notice_type = $ffcertificate_admin_notice['type'] === 'error' ? 'notice-error' : 'notice-success';
    echo '<div class="notice ' . esc_attr($ffcertificate_notice_type) . ' is-dismissible"><p>' . esc_html($ffcertificate_admin_notice['message']) . '</p></div>';
    // Delete transient after displaying
    delete_transient('ffc_admin_notice_' . get_current_user_id());
}

// Create and display table
$ffcertificate_table = new FFC_Appointments_List_Table();
$ffcertificate_table->prepare_items();

?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e('Appointments', 'ffcertificate'); ?></h1>
    <a href="#" class="page-title-action"><?php esc_html_e('Export CSV', 'ffcertificate'); ?></a>
    <hr class="wp-header-end">

    <form method="get">
        <input type="hidden" name="page" value="ffc-appointments" />
        <?php $ffcertificate_table->display(); ?>
    </form>
</div>

<!-- Styles in ffc-calendar-admin.css -->

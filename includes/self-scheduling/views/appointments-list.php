<?php
/**
 * Appointments List View
 *
 * Displays all appointments with filters and export options.
 *
 * @since 4.1.0
 * @version 4.1.0
 */

if (!defined('ABSPATH')) exit;

// Include WP List Table class
if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Appointments List Table
 */
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Internal class, not part of public API.
class FFC_Appointments_List_Table extends WP_List_Table {

    private $appointment_repository;
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
     */
    public function get_columns(): array {
        return array(
            'cb'              => '<input type="checkbox" />',
            'id'              => __('ID', 'wp-ffcertificate'),
            'calendar'        => __('Calendar', 'wp-ffcertificate'),
            'name'            => __('Name', 'wp-ffcertificate'),
            'email'           => __('Email', 'wp-ffcertificate'),
            'appointment_date'=> __('Date', 'wp-ffcertificate'),
            'time'            => __('Time', 'wp-ffcertificate'),
            'status'          => __('Status', 'wp-ffcertificate'),
            'created_at'      => __('Created', 'wp-ffcertificate')
        );
    }

    /**
     * Get sortable columns
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
     */
    public function column_default($item, $column_name) {
        return esc_html($item[$column_name] ?? '-');
    }

    /**
     * Checkbox column
     */
    public function column_cb($item): string {
        return sprintf('<input type="checkbox" name="appointment[]" value="%d" />', $item['id']);
    }

    /**
     * ID column
     */
    public function column_id($item): string {
        $actions = array();

        if ($item['status'] === 'pending') {
            $actions['confirm'] = sprintf(
                '<a href="?post_type=ffc_self_scheduling&page=%s&action=confirm&appointment=%d&_wpnonce=%s">%s</a>',
                esc_attr( ( isset( $_REQUEST['page'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['page'] ) ) : '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $item['id'],
                wp_create_nonce('ffc_confirm_appointment_' . $item['id']),
                __('Confirm', 'wp-ffcertificate')
            );
        }

        if (in_array($item['status'], ['pending', 'confirmed'])) {
            $actions['cancel'] = sprintf(
                '<a href="?post_type=ffc_self_scheduling&page=%s&action=cancel&appointment=%d&_wpnonce=%s" style="color: #b32d2e;">%s</a>',
                esc_attr( ( isset( $_REQUEST['page'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['page'] ) ) : '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $item['id'],
                wp_create_nonce('ffc_cancel_appointment_' . $item['id']),
                __('Cancel', 'wp-ffcertificate')
            );
        }

        $actions['view'] = sprintf(
            '<a href="?post_type=ffc_self_scheduling&page=%s&action=view&appointment=%d">%s</a>',
            esc_attr( ( isset( $_REQUEST['page'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['page'] ) ) : '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $item['id'],
            __('View', 'wp-ffcertificate')
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
                __('View Receipt', 'wp-ffcertificate')
            );
        }

        return sprintf('#%d %s', $item['id'], $this->row_actions($actions));
    }

    /**
     * Calendar column
     */
    public function column_calendar($item): string {
        $calendar = $this->calendar_repository->findById((int)$item['calendar_id']);
        if ($calendar) {
            $edit_url = admin_url('post.php?post=' . $calendar['post_id'] . '&action=edit');
            return sprintf('<a href="%s">%s</a>', esc_url($edit_url), esc_html($calendar['title']));
        }
        return __('(Deleted)', 'wp-ffcertificate');
    }

    /**
     * Name column
     */
    public function column_name($item): string {
        if (!empty($item['user_id'])) {
            $user = get_user_by('id', $item['user_id']);
            if ($user) {
                return esc_html($user->display_name);
            }
        }
        return esc_html($item['name'] ?? __('(Guest)', 'wp-ffcertificate'));
    }

    /**
     * Email column (with decryption support)
     */
    public function column_email($item): string {
        $email = $item['email'];

        // Try to decrypt if encrypted
        if (empty($email) && !empty($item['email_encrypted'])) {
            if (class_exists('\FreeFormCertificate\Core\Encryption')) {
                $email = \FreeFormCertificate\Core\Encryption::decrypt($item['email_encrypted']);
            }
        }

        return $email ? esc_html($email) : '-';
    }

    /**
     * Time column
     */
    public function column_time($item): string {
        $start = gmdate('H:i', strtotime($item['start_time']));
        $end = gmdate('H:i', strtotime($item['end_time']));
        return esc_html($start . ' - ' . $end);
    }

    /**
     * Status column
     */
    public function column_status($item): string {
        $status_labels = array(
            'pending'   => '<span class="ffc-status ffc-status-pending">' . __('Pending', 'wp-ffcertificate') . '</span>',
            'confirmed' => '<span class="ffc-status ffc-status-confirmed">' . __('Confirmed', 'wp-ffcertificate') . '</span>',
            'cancelled' => '<span class="ffc-status ffc-status-cancelled">' . __('Cancelled', 'wp-ffcertificate') . '</span>',
            'completed' => '<span class="ffc-status ffc-status-completed">' . __('Completed', 'wp-ffcertificate') . '</span>',
            'no_show'   => '<span class="ffc-status ffc-status-noshow">' . __('No Show', 'wp-ffcertificate') . '</span>',
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
            'total_pages' => ceil($total_items / $per_page)
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
                <option value=""><?php esc_html_e('All Calendars', 'wp-ffcertificate'); ?></option>
                <?php foreach ($calendars as $calendar): ?>
                    <option value="<?php echo esc_attr($calendar['id']); ?>" <?php selected($calendar_id, $calendar['id']); ?>>
                        <?php echo esc_html($calendar['title']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="status">
                <option value=""><?php esc_html_e('All Statuses', 'wp-ffcertificate'); ?></option>
                <option value="pending" <?php selected($status, 'pending'); ?>><?php esc_html_e('Pending', 'wp-ffcertificate'); ?></option>
                <option value="confirmed" <?php selected($status, 'confirmed'); ?>><?php esc_html_e('Confirmed', 'wp-ffcertificate'); ?></option>
                <option value="cancelled" <?php selected($status, 'cancelled'); ?>><?php esc_html_e('Cancelled', 'wp-ffcertificate'); ?></option>
                <option value="completed" <?php selected($status, 'completed'); ?>><?php esc_html_e('Completed', 'wp-ffcertificate'); ?></option>
                <option value="no_show" <?php selected($status, 'no_show'); ?>><?php esc_html_e('No Show', 'wp-ffcertificate'); ?></option>
            </select>

            <?php submit_button(__('Filter', 'wp-ffcertificate'), '', 'filter_action', false); ?>
        </div>
        <?php
    }
}

// Process actions
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nonce verified in switch cases below via check_admin_referer.
if (isset($_GET['action']) && isset($_GET['appointment'])) {
    $ffc_self_scheduling_appointment_id = absint(wp_unslash($_GET['appointment']));
    $wp_ffcertificate_action = sanitize_text_field(wp_unslash($_GET['action']));
    // phpcs:enable WordPress.Security.NonceVerification.Recommended

    // Verify user has admin permissions
    if (!\FreeFormCertificate\Core\Utils::current_user_can_manage()) {
        wp_die(esc_html__('You do not have permission to perform this action.', 'wp-ffcertificate'));
    }

    $wp_ffcertificate_repo = new \FreeFormCertificate\Repositories\AppointmentRepository();

    switch ($wp_ffcertificate_action) {
        case 'confirm':
            check_admin_referer('ffc_confirm_appointment_' . $ffc_self_scheduling_appointment_id);
            $wp_ffcertificate_result = $wp_ffcertificate_repo->confirm($ffc_self_scheduling_appointment_id, get_current_user_id());

            if ($wp_ffcertificate_result) {
                // Store success message in transient
                set_transient('ffc_admin_notice_' . get_current_user_id(), array(
                    'type' => 'success',
                    'message' => __('Appointment confirmed successfully.', 'wp-ffcertificate')
                ), 30);
            } else {
                // Store error message in transient
                set_transient('ffc_admin_notice_' . get_current_user_id(), array(
                    'type' => 'error',
                    'message' => __('Failed to confirm appointment.', 'wp-ffcertificate')
                ), 30);
            }

            $wp_ffcertificate_redirect = add_query_arg(
                array(
                    'post_type' => 'ffc_self_scheduling',
                    'page' => 'ffc-appointments'
                ),
                admin_url('edit.php')
            );
            wp_safe_redirect($wp_ffcertificate_redirect);
            exit;

        case 'cancel':
            check_admin_referer('ffc_cancel_appointment_' . $ffc_self_scheduling_appointment_id);
            $wp_ffcertificate_result = $wp_ffcertificate_repo->cancel($ffc_self_scheduling_appointment_id, get_current_user_id(), __('Cancelled by admin', 'wp-ffcertificate'));

            if ($wp_ffcertificate_result) {
                // Store success message in transient
                set_transient('ffc_admin_notice_' . get_current_user_id(), array(
                    'type' => 'success',
                    'message' => __('Appointment cancelled successfully.', 'wp-ffcertificate')
                ), 30);
            } else {
                // Store error message in transient
                set_transient('ffc_admin_notice_' . get_current_user_id(), array(
                    'type' => 'error',
                    'message' => __('Failed to cancel appointment.', 'wp-ffcertificate')
                ), 30);
            }

            $wp_ffcertificate_redirect = add_query_arg(
                array(
                    'post_type' => 'ffc_self_scheduling',
                    'page' => 'ffc-appointments'
                ),
                admin_url('edit.php')
            );
            wp_safe_redirect($wp_ffcertificate_redirect);
            exit;
    }
}

// Display admin notices from transients
$wp_ffcertificate_admin_notice = get_transient('ffc_admin_notice_' . get_current_user_id());
if ($wp_ffcertificate_admin_notice && is_array($wp_ffcertificate_admin_notice)) {
    $wp_ffcertificate_notice_type = $wp_ffcertificate_admin_notice['type'] === 'error' ? 'notice-error' : 'notice-success';
    echo '<div class="notice ' . esc_attr($wp_ffcertificate_notice_type) . ' is-dismissible"><p>' . esc_html($wp_ffcertificate_admin_notice['message']) . '</p></div>';
    // Delete transient after displaying
    delete_transient('ffc_admin_notice_' . get_current_user_id());
}

// Create and display table
$wp_ffcertificate_table = new FFC_Appointments_List_Table();
$wp_ffcertificate_table->prepare_items();

?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e('Appointments', 'wp-ffcertificate'); ?></h1>
    <a href="#" class="page-title-action"><?php esc_html_e('Export CSV', 'wp-ffcertificate'); ?></a>
    <hr class="wp-header-end">

    <form method="get">
        <input type="hidden" name="page" value="<?php echo esc_attr( ( isset( $_REQUEST['page'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['page'] ) ) : '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>" />
        <?php $wp_ffcertificate_table->display(); ?>
    </form>
</div>

<style>
.ffc-status {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}
.ffc-status-pending {
    background: #f0f0f1;
    color: #646970;
}
.ffc-status-confirmed {
    background: #d5e8d4;
    color: #2e7d32;
}
.ffc-status-cancelled {
    background: #f8d7da;
    color: #b32d2e;
}
.ffc-status-completed {
    background: #cfe2ff;
    color: #004085;
}
.ffc-status-noshow {
    background: #fff3cd;
    color: #856404;
}
</style>

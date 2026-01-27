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
            'id'              => __('ID', 'ffc'),
            'calendar'        => __('Calendar', 'ffc'),
            'name'            => __('Name', 'ffc'),
            'email'           => __('Email', 'ffc'),
            'appointment_date'=> __('Date', 'ffc'),
            'time'            => __('Time', 'ffc'),
            'status'          => __('Status', 'ffc'),
            'created_at'      => __('Created', 'ffc')
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
                '<a href="?post_type=ffc_calendar&page=%s&action=confirm&appointment=%d&_wpnonce=%s">%s</a>',
                esc_attr($_REQUEST['page']),
                $item['id'],
                wp_create_nonce('ffc_confirm_appointment_' . $item['id']),
                __('Confirm', 'ffc')
            );
        }

        if (in_array($item['status'], ['pending', 'confirmed'])) {
            $actions['cancel'] = sprintf(
                '<a href="?post_type=ffc_calendar&page=%s&action=cancel&appointment=%d&_wpnonce=%s" style="color: #b32d2e;">%s</a>',
                esc_attr($_REQUEST['page']),
                $item['id'],
                wp_create_nonce('ffc_cancel_appointment_' . $item['id']),
                __('Cancel', 'ffc')
            );
        }

        $actions['view'] = sprintf(
            '<a href="?post_type=ffc_calendar&page=%s&action=view&appointment=%d">%s</a>',
            esc_attr($_REQUEST['page']),
            $item['id'],
            __('View', 'ffc')
        );

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
        return __('(Deleted)', 'ffc');
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
        return esc_html($item['name'] ?? __('(Guest)', 'ffc'));
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
        $start = date('H:i', strtotime($item['start_time']));
        $end = date('H:i', strtotime($item['end_time']));
        return esc_html($start . ' - ' . $end);
    }

    /**
     * Status column
     */
    public function column_status($item): string {
        $status_labels = array(
            'pending'   => '<span class="ffc-status ffc-status-pending">' . __('Pending', 'ffc') . '</span>',
            'confirmed' => '<span class="ffc-status ffc-status-confirmed">' . __('Confirmed', 'ffc') . '</span>',
            'cancelled' => '<span class="ffc-status ffc-status-cancelled">' . __('Cancelled', 'ffc') . '</span>',
            'completed' => '<span class="ffc-status ffc-status-completed">' . __('Completed', 'ffc') . '</span>',
            'no_show'   => '<span class="ffc-status ffc-status-noshow">' . __('No Show', 'ffc') . '</span>',
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
        $calendar_id = isset($_GET['calendar_id']) ? absint($_GET['calendar_id']) : 0;
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';

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

        $calendar_id = isset($_GET['calendar_id']) ? absint($_GET['calendar_id']) : 0;
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';

        // Get all calendars for filter
        $calendars = $this->calendar_repository->getActiveCalendars();

        ?>
        <div class="alignleft actions">
            <select name="calendar_id">
                <option value=""><?php _e('All Calendars', 'ffc'); ?></option>
                <?php foreach ($calendars as $calendar): ?>
                    <option value="<?php echo esc_attr($calendar['id']); ?>" <?php selected($calendar_id, $calendar['id']); ?>>
                        <?php echo esc_html($calendar['title']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="status">
                <option value=""><?php _e('All Statuses', 'ffc'); ?></option>
                <option value="pending" <?php selected($status, 'pending'); ?>><?php _e('Pending', 'ffc'); ?></option>
                <option value="confirmed" <?php selected($status, 'confirmed'); ?>><?php _e('Confirmed', 'ffc'); ?></option>
                <option value="cancelled" <?php selected($status, 'cancelled'); ?>><?php _e('Cancelled', 'ffc'); ?></option>
                <option value="completed" <?php selected($status, 'completed'); ?>><?php _e('Completed', 'ffc'); ?></option>
                <option value="no_show" <?php selected($status, 'no_show'); ?>><?php _e('No Show', 'ffc'); ?></option>
            </select>

            <?php submit_button(__('Filter', 'ffc'), '', 'filter_action', false); ?>
        </div>
        <?php
    }
}

// Process actions
if (isset($_GET['action']) && isset($_GET['appointment'])) {
    $appointment_id = absint($_GET['appointment']);
    $action = sanitize_text_field($_GET['action']);

    // Verify user has admin permissions
    if (!\FreeFormCertificate\Core\Utils::current_user_can_manage()) {
        wp_die(esc_html__('You do not have permission to perform this action.', 'ffc'));
    }

    $appointment_repo = new \FreeFormCertificate\Repositories\AppointmentRepository();

    switch ($action) {
        case 'confirm':
            check_admin_referer('ffc_confirm_appointment_' . $appointment_id);
            $appointment_repo->confirm($appointment_id, get_current_user_id());
            $redirect_url = add_query_arg(
                array(
                    'post_type' => 'ffc_calendar',
                    'page' => 'ffc-appointments',
                    'message' => 'confirmed'
                ),
                admin_url('edit.php')
            );
            wp_safe_redirect($redirect_url);
            exit;

        case 'cancel':
            check_admin_referer('ffc_cancel_appointment_' . $appointment_id);
            $appointment_repo->cancel($appointment_id, get_current_user_id(), __('Cancelled by admin', 'ffc'));
            $redirect_url = add_query_arg(
                array(
                    'post_type' => 'ffc_calendar',
                    'page' => 'ffc-appointments',
                    'message' => 'cancelled'
                ),
                admin_url('edit.php')
            );
            wp_safe_redirect($redirect_url);
            exit;
    }
}

// Display messages
if (isset($_GET['message'])) {
    $message = sanitize_text_field($_GET['message']);
    $messages = array(
        'confirmed' => __('Appointment confirmed successfully.', 'ffc'),
        'cancelled' => __('Appointment cancelled successfully.', 'ffc'),
    );

    if (isset($messages[$message])) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($messages[$message]) . '</p></div>';
    }
}

// Create and display table
$table = new FFC_Appointments_List_Table();
$table->prepare_items();

?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?php _e('Appointments', 'ffc'); ?></h1>
    <a href="#" class="page-title-action"><?php _e('Export CSV', 'ffc'); ?></a>
    <hr class="wp-header-end">

    <form method="get">
        <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>" />
        <?php $table->display(); ?>
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

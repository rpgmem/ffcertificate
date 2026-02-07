<?php
declare(strict_types=1);

/**
 * Audience Admin Bookings
 *
 * Handles the bookings listing page for the audience scheduling system.
 *
 * @since 4.6.0
 * @package FreeFormCertificate\Audience
 */

namespace FreeFormCertificate\Audience;

if (!defined('ABSPATH')) {
    exit;
}

class AudienceAdminBookings {

    /**
     * Menu slug prefix
     *
     * @var string
     */
    private string $menu_slug;

    /**
     * Constructor
     *
     * @param string $menu_slug Menu slug prefix.
     */
    public function __construct(string $menu_slug) {
        $this->menu_slug = $menu_slug;
    }

    /**
     * Render bookings page
     *
     * @return void
     */
    public function render_page(): void {
        // Get filter parameters
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display filter parameters on admin page
        $schedule_id = isset($_GET['schedule_id']) ? absint($_GET['schedule_id']) : 0;
        $environment_id = isset($_GET['environment_id']) ? absint($_GET['environment_id']) : 0;
        $status_filter = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
        $date_from = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '';
        $date_to = isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        // Build query args
        $args = array(
            'orderby' => 'booking_date',
            'order' => 'DESC',
        );

        if ($schedule_id > 0) {
            $args['schedule_id'] = $schedule_id;
        }
        if ($environment_id > 0) {
            $args['environment_id'] = $environment_id;
        }
        if (!empty($status_filter)) {
            $args['status'] = $status_filter;
        }
        if (!empty($date_from)) {
            $args['start_date'] = $date_from;
        }
        if (!empty($date_to)) {
            $args['end_date'] = $date_to;
        }

        // Get bookings
        $bookings = AudienceBookingRepository::get_all($args);

        // Get schedules for filter
        $schedules = AudienceScheduleRepository::get_all();

        // Get environments for filter
        $environments = array();
        if ($schedule_id > 0) {
            $environments = AudienceEnvironmentRepository::get_by_schedule($schedule_id);
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Bookings', 'wp-ffcertificate'); ?></h1>

            <?php settings_errors('ffc_audience'); ?>

            <!-- Filters -->
            <div class="tablenav top">
                <form method="get" action="">
                    <input type="hidden" name="page" value="<?php echo esc_attr($this->menu_slug); ?>-bookings">

                    <select name="schedule_id" id="filter-schedule">
                        <option value=""><?php esc_html_e('All Schedules', 'wp-ffcertificate'); ?></option>
                        <?php foreach ($schedules as $schedule) : ?>
                            <option value="<?php echo esc_attr($schedule->id); ?>" <?php selected($schedule_id, $schedule->id); ?>>
                                <?php echo esc_html($schedule->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="environment_id" id="filter-environment">
                        <option value=""><?php esc_html_e('All Environments', 'wp-ffcertificate'); ?></option>
                        <?php foreach ($environments as $env) : ?>
                            <option value="<?php echo esc_attr($env->id); ?>" <?php selected($environment_id, $env->id); ?>>
                                <?php echo esc_html($env->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="status">
                        <option value=""><?php esc_html_e('All Status', 'wp-ffcertificate'); ?></option>
                        <option value="active" <?php selected($status_filter, 'active'); ?>><?php esc_html_e('Active', 'wp-ffcertificate'); ?></option>
                        <option value="cancelled" <?php selected($status_filter, 'cancelled'); ?>><?php esc_html_e('Cancelled', 'wp-ffcertificate'); ?></option>
                    </select>

                    <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>" placeholder="<?php esc_attr_e('From', 'wp-ffcertificate'); ?>">
                    <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>" placeholder="<?php esc_attr_e('To', 'wp-ffcertificate'); ?>">

                    <input type="submit" class="button" value="<?php esc_attr_e('Filter', 'wp-ffcertificate'); ?>">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . $this->menu_slug . '-bookings')); ?>" class="button"><?php esc_html_e('Clear', 'wp-ffcertificate'); ?></a>
                </form>
            </div>

            <!-- Bookings Table -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col" class="manage-column"><?php esc_html_e('ID', 'wp-ffcertificate'); ?></th>
                        <th scope="col" class="manage-column"><?php esc_html_e('Date', 'wp-ffcertificate'); ?></th>
                        <th scope="col" class="manage-column"><?php esc_html_e('Time', 'wp-ffcertificate'); ?></th>
                        <th scope="col" class="manage-column"><?php esc_html_e('Environment', 'wp-ffcertificate'); ?></th>
                        <th scope="col" class="manage-column"><?php esc_html_e('Description', 'wp-ffcertificate'); ?></th>
                        <th scope="col" class="manage-column"><?php esc_html_e('Type', 'wp-ffcertificate'); ?></th>
                        <th scope="col" class="manage-column"><?php esc_html_e('Status', 'wp-ffcertificate'); ?></th>
                        <th scope="col" class="manage-column"><?php esc_html_e('Created By', 'wp-ffcertificate'); ?></th>
                        <th scope="col" class="manage-column"><?php esc_html_e('Actions', 'wp-ffcertificate'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($bookings)) : ?>
                        <tr>
                            <td colspan="9"><?php esc_html_e('No bookings found.', 'wp-ffcertificate'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($bookings as $booking) : ?>
                            <?php
                            $creator = get_userdata($booking->created_by);
                            $creator_name = $creator ? $creator->display_name : __('Unknown', 'wp-ffcertificate');
                            $status_class = $booking->status === 'active' ? 'status-active' : 'status-cancelled';
                            ?>
                            <tr>
                                <td><?php echo esc_html($booking->id); ?></td>
                                <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($booking->booking_date))); ?></td>
                                <td><?php echo esc_html(date_i18n('H:i', strtotime($booking->start_time)) . ' - ' . date_i18n('H:i', strtotime($booking->end_time))); ?></td>
                                <td><?php echo esc_html($booking->environment_name); ?></td>
                                <td><?php echo esc_html(wp_trim_words($booking->description, 10)); ?></td>
                                <td>
                                    <?php
                                    $type_labels = array(
                                        'audience' => __('Audience', 'wp-ffcertificate'),
                                        'custom' => __('Custom Users', 'wp-ffcertificate'),
                                    );
                                    echo esc_html($type_labels[$booking->booking_type] ?? $booking->booking_type);
                                    ?>
                                </td>
                                <td>
                                    <span class="<?php echo esc_attr($status_class); ?>">
                                        <?php echo $booking->status === 'active' ? esc_html__('Active', 'wp-ffcertificate') : esc_html__('Cancelled', 'wp-ffcertificate'); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($creator_name); ?></td>
                                <td>
                                    <a href="#" class="ffc-view-booking" data-booking-id="<?php echo esc_attr($booking->id); ?>">
                                        <?php esc_html_e('View', 'wp-ffcertificate'); ?>
                                    </a>
                                    <?php if ($booking->status === 'active') : ?>
                                        |
                                        <a href="#" class="ffc-cancel-booking" data-booking-id="<?php echo esc_attr($booking->id); ?>" style="color: #a00;">
                                            <?php esc_html_e('Cancel', 'wp-ffcertificate'); ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <p class="description" style="margin-top: 15px;">
                <?php /* translators: %d: number of bookings */ printf(esc_html__('Total: %d bookings', 'wp-ffcertificate'), count($bookings)); ?>
            </p>
        </div>

        <!-- Styles in ffc-audience-admin.css -->
        <!-- Scripts in ffc-audience-admin.js -->
        <?php
    }
}

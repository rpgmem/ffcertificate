<?php
declare(strict_types=1);

/**
 * Audience Admin Dashboard
 *
 * Renders the Scheduling Dashboard page with statistics for both
 * self-scheduling and audience scheduling systems.
 *
 * @since 4.6.0
 * @package FreeFormCertificate\Audience
 */

namespace FreeFormCertificate\Audience;

if (!defined('ABSPATH')) {
    exit;
}

class AudienceAdminDashboard {

    use \FreeFormCertificate\Core\DatabaseHelperTrait;

    /**
     * Menu slug prefix
     *
     * @var string
     */
    private string $menu_slug;

    /**
     * Constructor
     *
     * @param string $menu_slug Menu slug prefix for building admin URLs.
     */
    public function __construct(string $menu_slug) {
        $this->menu_slug = $menu_slug;
    }

    /**
     * Render the scheduling dashboard page
     *
     * @return void
     */
    public function render_dashboard_page(): void {
        // Audience statistics
        $audience_stats = array(
            'schedules' => AudienceScheduleRepository::count(array('status' => 'active')),
            'environments' => AudienceEnvironmentRepository::count(array('status' => 'active')),
            'audiences' => AudienceRepository::count(array('status' => 'active')),
            'upcoming_bookings' => AudienceBookingRepository::count(array(
                'status' => 'active',
                'start_date' => current_time('Y-m-d'),
            )),
        );

        // Self-scheduling statistics
        $self_stats = $this->get_self_scheduling_stats();

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Scheduling Dashboard', 'ffcertificate'); ?></h1>

            <div class="ffc-scheduling-dashboard">

                <!-- Self-Scheduling Section -->
                <h2><?php esc_html_e('Self-Scheduling (Personal)', 'ffcertificate'); ?></h2>
                <div class="ffc-stats-grid">
                    <div class="ffc-stat-card">
                        <span class="ffc-stat-label"><?php esc_html_e('Active Calendars', 'ffcertificate'); ?></span>
                        <div class="ffc-stat-number">
                            <span class="ffc-stat-icon dashicons dashicons-calendar"></span>
                            <span class="ffc-stat-value"><?php echo esc_html((string) $self_stats['calendars']); ?></span>
                        </div>
                        <a href="<?php echo esc_url(admin_url('edit.php?post_type=ffc_self_scheduling')); ?>" class="ffc-stat-link">
                            <?php esc_html_e('Manage', 'ffcertificate'); ?> &rarr;
                        </a>
                    </div>

                    <div class="ffc-stat-card">
                        <span class="ffc-stat-label"><?php esc_html_e('Upcoming Appointments', 'ffcertificate'); ?></span>
                        <div class="ffc-stat-number">
                            <span class="ffc-stat-icon dashicons dashicons-clock"></span>
                            <span class="ffc-stat-value"><?php echo esc_html((string) $self_stats['upcoming_appointments']); ?></span>
                        </div>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=ffc-appointments')); ?>" class="ffc-stat-link">
                            <?php esc_html_e('View All', 'ffcertificate'); ?> &rarr;
                        </a>
                    </div>
                </div>

                <!-- Audience Section -->
                <h2><?php esc_html_e('Audience Scheduling', 'ffcertificate'); ?></h2>
                <div class="ffc-stats-grid">
                    <div class="ffc-stat-card">
                        <span class="ffc-stat-label"><?php esc_html_e('Active Calendars', 'ffcertificate'); ?></span>
                        <div class="ffc-stat-number">
                            <span class="ffc-stat-icon dashicons dashicons-calendar-alt"></span>
                            <span class="ffc-stat-value"><?php echo esc_html((string) $audience_stats['schedules']); ?></span>
                        </div>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . $this->menu_slug . '-calendars')); ?>" class="ffc-stat-link">
                            <?php esc_html_e('Manage', 'ffcertificate'); ?> &rarr;
                        </a>
                    </div>

                    <div class="ffc-stat-card">
                        <span class="ffc-stat-label">
                            <?php
                            /* translators: %s: environment label (plural) */
                            printf(esc_html__('Active %s', 'ffcertificate'), esc_html(AudienceScheduleRepository::get_environment_label()));
                            ?>
                        </span>
                        <div class="ffc-stat-number">
                            <span class="ffc-stat-icon dashicons dashicons-building"></span>
                            <span class="ffc-stat-value"><?php echo esc_html((string) $audience_stats['environments']); ?></span>
                        </div>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . $this->menu_slug . '-environments')); ?>" class="ffc-stat-link">
                            <?php esc_html_e('Manage', 'ffcertificate'); ?> &rarr;
                        </a>
                    </div>

                    <div class="ffc-stat-card">
                        <span class="ffc-stat-label"><?php esc_html_e('Active Audiences', 'ffcertificate'); ?></span>
                        <div class="ffc-stat-number">
                            <span class="ffc-stat-icon dashicons dashicons-groups"></span>
                            <span class="ffc-stat-value"><?php echo esc_html((string) $audience_stats['audiences']); ?></span>
                        </div>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . $this->menu_slug . '-audiences')); ?>" class="ffc-stat-link">
                            <?php esc_html_e('Manage', 'ffcertificate'); ?> &rarr;
                        </a>
                    </div>

                    <div class="ffc-stat-card">
                        <span class="ffc-stat-label"><?php esc_html_e('Upcoming Bookings', 'ffcertificate'); ?></span>
                        <div class="ffc-stat-number">
                            <span class="ffc-stat-icon dashicons dashicons-clock"></span>
                            <span class="ffc-stat-value"><?php echo esc_html((string) $audience_stats['upcoming_bookings']); ?></span>
                        </div>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . $this->menu_slug . '-bookings')); ?>" class="ffc-stat-link">
                            <?php esc_html_e('View All', 'ffcertificate'); ?> &rarr;
                        </a>
                    </div>
                </div>

                <div class="ffc-quick-actions">
                    <h2><?php esc_html_e('Quick Actions', 'ffcertificate'); ?></h2>
                    <div class="ffc-action-buttons">
                        <a href="<?php echo esc_url(admin_url('post-new.php?post_type=ffc_self_scheduling')); ?>" class="button button-primary">
                            <?php esc_html_e('New Personal Calendar', 'ffcertificate'); ?>
                        </a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . $this->menu_slug . '-calendars&action=new')); ?>" class="button button-primary">
                            <?php esc_html_e('New Audience Calendar', 'ffcertificate'); ?>
                        </a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . $this->menu_slug . '-environments&action=new')); ?>" class="button">
                            <?php
                            /* translators: %s: environment label (singular) */
                            printf(esc_html__('Add %s', 'ffcertificate'), esc_html(AudienceScheduleRepository::get_environment_label(null, true)));
                            ?>
                        </a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . $this->menu_slug . '-audiences&action=new')); ?>" class="button">
                            <?php esc_html_e('Create Audience', 'ffcertificate'); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Styles loaded via ffc-audience-admin.css -->
        <?php
    }

    /**
     * Get self-scheduling statistics for dashboard
     *
     * @return array{calendars: int, upcoming_appointments: int}
     */
    private function get_self_scheduling_stats(): array {
        global $wpdb;

        $calendars = 0;
        $upcoming = 0;

        // Count published self-scheduling calendars
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $calendars = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'ffc_self_scheduling' AND post_status = 'publish'"
        );

        // Count upcoming appointments (today or future, not cancelled)
        $appointments_table = $wpdb->prefix . 'ffc_self_scheduling_appointments';
        if (self::table_exists($appointments_table)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $upcoming = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM %i WHERE appointment_date >= %s AND status IN ('pending', 'confirmed')",
                    $appointments_table,
                    current_time('Y-m-d')
                )
            );
        }

        return array(
            'calendars' => $calendars,
            'upcoming_appointments' => $upcoming,
        );
    }
}

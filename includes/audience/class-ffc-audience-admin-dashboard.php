<?php
/**
 * Audience Admin Dashboard
 *
 * Renders the Scheduling Dashboard page with statistics for both
 * self-scheduling and audience scheduling systems.
 *
 * @package FreeFormCertificate\Audience
 * @since 4.6.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Admin\AdminUI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Audience Admin Dashboard.
 */
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
	public function __construct( string $menu_slug ) {
		$this->menu_slug = $menu_slug;
	}

	/**
	 * Render the scheduling dashboard page
	 *
	 * @return void
	 */
	public function render_dashboard_page(): void {
		// Audience statistics.
		$audience_stats = array(
			'schedules'         => AudienceScheduleRepository::count( array( 'status' => 'active' ) ),
			'environments'      => AudienceEnvironmentRepository::count( array( 'status' => 'active' ) ),
			'audiences'         => AudienceReader::count( array( 'status' => 'active' ) ),
			'upcoming_bookings' => AudienceBookingReader::count(
				array(
					'status'     => 'active',
					'start_date' => current_time( 'Y-m-d' ),
				)
			),
		);

		// Self-scheduling statistics.
		$self_stats = $this->get_self_scheduling_stats();

		?>
		<div class="wrap ffc-admin-page ffc-page-scheduling-dashboard">
			<h1><?php esc_html_e( 'Scheduling Dashboard', 'ffcertificate' ); ?></h1>

			<?php
			// The shared stat cards (`AdminUI::get_stat_card()`, #1631), the
			// component the Certificates Dashboard already draws; each section
			// heading names its subject with an icon.
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- get_stat_card() escapes every value.
			?>
			<h2 class="ffc-icon-user"><?php esc_html_e( 'Personal Calendars', 'ffcertificate' ); ?></h2>
			<div class="ffc-stats">
				<?php
				echo AdminUI::get_stat_card(
					array(
						'label' => __( 'Active Calendars', 'ffcertificate' ),
						'value' => (int) $self_stats['calendars'],
						'icon'  => 'calendar',
						'url'   => admin_url( 'edit.php?post_type=ffc_self_scheduling' ),
						'link'  => __( 'Manage', 'ffcertificate' ),
					)
				);
				echo AdminUI::get_stat_card(
					array(
						'label' => __( 'Upcoming Appointments', 'ffcertificate' ),
						'value' => (int) $self_stats['upcoming_appointments'],
						'icon'  => 'clock',
						'url'   => admin_url( 'admin.php?page=ffc-appointments' ),
						'link'  => __( 'View All', 'ffcertificate' ),
					)
				);
				?>
			</div>

			<h2 class="ffc-icon-users"><?php esc_html_e( 'Audience Scheduling', 'ffcertificate' ); ?></h2>
			<div class="ffc-stats">
				<?php
				echo AdminUI::get_stat_card(
					array(
						'label' => __( 'Active Calendars', 'ffcertificate' ),
						'value' => (int) $audience_stats['schedules'],
						'icon'  => 'calendar',
						'url'   => admin_url( 'admin.php?page=' . $this->menu_slug . '-calendars' ),
						'link'  => __( 'Manage', 'ffcertificate' ),
					)
				);
				echo AdminUI::get_stat_card(
					array(
						/* translators: %s: environment label (plural) */
						'label' => sprintf( __( 'Active %s', 'ffcertificate' ), AudienceScheduleRepository::get_environment_label() ),
						'value' => (int) $audience_stats['environments'],
						'icon'  => 'building',
						'url'   => admin_url( 'admin.php?page=' . $this->menu_slug . '-environments' ),
						'link'  => __( 'Manage', 'ffcertificate' ),
					)
				);
				echo AdminUI::get_stat_card(
					array(
						'label' => __( 'Active Audiences', 'ffcertificate' ),
						'value' => (int) $audience_stats['audiences'],
						'icon'  => 'users',
						'url'   => admin_url( 'admin.php?page=' . $this->menu_slug . '-audiences' ),
						'link'  => __( 'Manage', 'ffcertificate' ),
					)
				);
				echo AdminUI::get_stat_card(
					array(
						'label' => __( 'Upcoming Bookings', 'ffcertificate' ),
						'value' => (int) $audience_stats['upcoming_bookings'],
						'icon'  => 'clock',
						'url'   => admin_url( 'admin.php?page=' . $this->menu_slug . '-bookings' ),
						'link'  => __( 'View All', 'ffcertificate' ),
					)
				);
				// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			</div>

			<h2 class="ffc-icon-zap"><?php esc_html_e( 'Quick Actions', 'ffcertificate' ); ?></h2>
			<div class="ffc-scheduling-quick-actions">
				<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=ffc_self_scheduling' ) ); ?>" class="button button-primary ffc-icon-plus">
					<?php esc_html_e( 'New Personal Calendar', 'ffcertificate' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->menu_slug . '-calendars&action=new' ) ); ?>" class="button button-primary ffc-icon-plus">
					<?php esc_html_e( 'New Audience Calendar', 'ffcertificate' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->menu_slug . '-environments&action=new' ) ); ?>" class="button ffc-icon-plus">
					<?php
					/* translators: %s: environment label (singular) */
					printf( esc_html__( 'Add %s', 'ffcertificate' ), esc_html( AudienceScheduleRepository::get_environment_label( null, true ) ) );
					?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->menu_slug . '-audiences&action=new' ) ); ?>" class="button ffc-icon-plus">
					<?php esc_html_e( 'Create Audience', 'ffcertificate' ); ?>
				</a>
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
		$upcoming  = 0;

		// Count published self-scheduling calendars.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Counts published ffc_self_scheduling posts; WP_Query would build and hydrate every post to return one number.
		$calendars = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'ffc_self_scheduling' AND post_status = 'publish'"
		);

		// Count upcoming appointments (today or future, not cancelled).
		$appointments_table = $wpdb->prefix . 'ffc_self_scheduling_appointments';
		if ( self::table_exists( $appointments_table ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dashboard counter over the plugin's own appointments table, which exists to show the current figure.
			$upcoming = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE appointment_date >= %s AND status IN ('pending', 'confirmed')",
					$appointments_table,
					current_time( 'Y-m-d' )
				)
			);
		}

		return array(
			'calendars'             => $calendars,
			'upcoming_appointments' => $upcoming,
		);
	}
}

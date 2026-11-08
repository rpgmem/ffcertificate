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
use FreeFormCertificate\Core\AdminDashboard;

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

		$env_plural   = AudienceScheduleRepository::get_environment_label();
		$env_singular = AudienceScheduleRepository::get_environment_label( null, true );
		$calendars    = admin_url( 'edit.php?post_type=ffc_self_scheduling' );
		$audience_cal = admin_url( 'admin.php?page=' . $this->menu_slug . '-calendars' );

		// The module dashboard (#2486): the counts first, one card per section
		// with the buttons that open it, and the ways to add something on the
		// side. The same layout as the Recruitment and Date Messages ones.
		echo '<div class="wrap ffc-admin-page ffc-page-scheduling-dashboard ffc-boxed">';
		echo '<h1>' . esc_html__( 'Scheduling Dashboard', 'ffcertificate' ) . '</h1>';

		echo '<div class="ffc-stats">';
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- get_stat_card() escapes every value.
		echo AdminUI::get_stat_card(
			array(
				'label' => __( 'Upcoming Appointments', 'ffcertificate' ),
				'value' => (int) $self_stats['upcoming_appointments'],
				'icon'  => 'clock',
			)
		);
		echo AdminUI::get_stat_card(
			array(
				'label' => __( 'Upcoming Bookings', 'ffcertificate' ),
				'value' => (int) $audience_stats['upcoming_bookings'],
				'icon'  => 'clock',
			)
		);
		echo AdminUI::get_stat_card(
			array(
				'label' => __( 'Active Personal Calendars', 'ffcertificate' ),
				'value' => (int) $self_stats['calendars'],
				'icon'  => 'user',
			)
		);
		echo AdminUI::get_stat_card(
			array(
				'label' => __( 'Active Audience Calendars', 'ffcertificate' ),
				'value' => (int) $audience_stats['schedules'],
				'icon'  => 'users',
			)
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';

		echo '<div class="ffc-dashboard"><div class="ffc-dashboard__sections">';

		AdminDashboard::section_card(
			'ffc-icon-user',
			__( 'Personal Calendars', 'ffcertificate' ),
			AdminDashboard::figures(
				array(
					__( 'Active Calendars', 'ffcertificate' )      => (int) $self_stats['calendars'],
					__( 'Upcoming Appointments', 'ffcertificate' ) => (int) $self_stats['upcoming_appointments'],
				)
			),
			array(
				array(
					'label' => __( 'Open calendars', 'ffcertificate' ),
					'icon'  => 'calendar',
					'href'  => $calendars,
				),
				array(
					'label' => __( 'Open appointments', 'ffcertificate' ),
					'icon'  => 'clock',
					'href'  => admin_url( 'admin.php?page=ffc-appointments' ),
				),
			)
		);

		AdminDashboard::section_card(
			'ffc-icon-users',
			__( 'Audience Calendars', 'ffcertificate' ),
			AdminDashboard::figures(
				array(
					__( 'Active Calendars', 'ffcertificate' )  => (int) $audience_stats['schedules'],
					__( 'Upcoming Bookings', 'ffcertificate' ) => (int) $audience_stats['upcoming_bookings'],
				)
			),
			array(
				array(
					'label' => __( 'Open calendars', 'ffcertificate' ),
					'icon'  => 'calendar',
					'href'  => $audience_cal,
				),
				array(
					'label' => __( 'Open bookings', 'ffcertificate' ),
					'icon'  => 'clock',
					'href'  => admin_url( 'admin.php?page=' . $this->menu_slug . '-bookings' ),
				),
			)
		);

		AdminDashboard::section_card(
			'ffc-icon-building',
			$env_plural,
			AdminDashboard::figures(
				array(
					/* translators: %s: environment label (plural) */
					sprintf( __( 'Active %s', 'ffcertificate' ), $env_plural ) => (int) $audience_stats['environments'],
				)
			),
			array(
				array(
					/* translators: %s: environment label (plural) */
					'label' => sprintf( __( 'Open %s', 'ffcertificate' ), $env_plural ),
					'icon'  => 'building',
					'href'  => AudienceAdminEnvironment::view_url( $this->menu_slug ),
				),
			)
		);

		AdminDashboard::section_card(
			'ffc-icon-users',
			__( 'Audiences', 'ffcertificate' ),
			AdminDashboard::figures(
				array(
					__( 'Active Audiences', 'ffcertificate' ) => (int) $audience_stats['audiences'],
				)
			),
			array(
				array(
					'label' => __( 'Open audiences', 'ffcertificate' ),
					'icon'  => 'users',
					'href'  => admin_url( 'admin.php?page=' . $this->menu_slug . '-audiences' ),
				),
			)
		);

		echo '</div>'; // .ffc-dashboard__sections

		// Four ways to add something (#1904): every one is `create`, so none
		// of them claims to be the dashboard's own work.
		echo '<aside class="ffc-dashboard__side"><div class="card"><h2 class="ffc-icon-zap">' . esc_html__( 'Quick Actions', 'ffcertificate' ) . '</h2>';
		echo '<div class="ffc-scheduling-quick-actions">';
		$quick_actions = array(
			array( __( 'New Personal Calendar', 'ffcertificate' ), admin_url( 'post-new.php?post_type=ffc_self_scheduling' ) ),
			array( __( 'New Audience Calendar', 'ffcertificate' ), $audience_cal . '&action=new' ),
			array(
				/* translators: %s: environment label (singular) */
				sprintf( __( 'Add %s', 'ffcertificate' ), $env_singular ),
				AudienceAdminEnvironment::view_url( $this->menu_slug, '&action=new' ),
			),
			array( __( 'Create Audience', 'ffcertificate' ), admin_url( 'admin.php?page=' . $this->menu_slug . '-audiences&action=new' ) ),
		);
		foreach ( $quick_actions as $quick_action ) {
			AdminUI::render_button(
				array(
					'label'    => $quick_action[0],
					'category' => 'create',
					'icon'     => 'plus',
					'href'     => $quick_action[1],
				)
			);
		}
		echo '</div></div></aside></div>'; // .ffc-dashboard
		echo '</div>'; // .wrap
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

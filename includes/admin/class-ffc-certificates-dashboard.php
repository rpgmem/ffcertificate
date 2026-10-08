<?php
/**
 * Certificates Dashboard
 *
 * Admin page registered as the first item under the Certificate menu
 * (edit.php?post_type=ffc_form). Renders a monthly calendar of forms keyed
 * by GeoFence start date with a fallback to the post publication date.
 *
 * @package FreeFormCertificate\Admin
 * @since 6.4.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Certificates Dashboard admin page.
 */
class CertificatesDashboard {

	public const MENU_SLUG  = 'ffc-certificates-dashboard';
	public const PARENT     = 'edit.php?post_type=ffc_form';
	public const CAPABILITY = 'ffc_view_certificates';

	/**
	 * Register WordPress hooks.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		// Priority 99 so all CPT-injected items (All / Add New) plus our sibling
		// Submissions submenu are already registered before we reorder. Activity
		// Log left this menu in #804 and now lives as a Settings tab.
		add_action( 'admin_menu', array( $this, 'reorder_menu' ), 99 );
	}

	/**
	 * Register the dashboard submenu.
	 */
	public function register_menu(): void {
		add_submenu_page(
			self::PARENT,
			__( 'Certificates Dashboard', 'ffcertificate' ),
			__( 'Dashboard', 'ffcertificate' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Move the dashboard to the top of the Certificate submenu.
	 *
	 * WordPress orders submenu items by registration position, but CPT items
	 * (All Certificates, Add New) get auto-injected at positions 5/10, so we
	 * rebuild the array to guarantee Dashboard sits first.
	 */
	public function reorder_menu(): void {
		global $submenu;

		if ( ! isset( $submenu[ self::PARENT ] ) ) {
			return;
		}

		$dashboard_item = null;
		$rest           = array();
		foreach ( $submenu[ self::PARENT ] as $item ) {
			if ( isset( $item[2] ) && self::MENU_SLUG === $item[2] ) {
				$dashboard_item = $item;
			} else {
				$rest[] = $item;
			}
		}

		if ( null === $dashboard_item ) {
			return;
		}

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Intentionally reordering WP's own $submenu array.
		$submenu[ self::PARENT ] = array_merge( array( $dashboard_item ), $rest );
	}

	/**
	 * Render the dashboard page.
	 *
	 * Sprint 2 scaffold: emits the page title and a placeholder container.
	 * The calendar UI and side list arrive in Sprint 4 once the REST endpoint
	 * (Sprint 3) is in place.
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ffcertificate' ) );
		}

		?>
		<div class="wrap ffc-admin-page ffc-page-certificates-dashboard">
			<h1><?php esc_html_e( 'Certificates Dashboard', 'ffcertificate' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Forms organised by GeoFence start date. Forms without a GeoFence start fall back to their publication date.', 'ffcertificate' ); ?>
			</p>

			<?php $this->render_summary(); ?>

			<div class="ffc-certificates-dashboard">
				<div class="ffc-certificates-dashboard-calendar">
					<div id="ffc-certificates-calendar"></div>
				</div>
				<aside class="ffc-certificates-dashboard-side" aria-live="polite">
					<h2 class="ffc-certificates-side-title ffc-icon-calendar">
						<?php esc_html_e( 'Forms on the selected day', 'ffcertificate' ); ?>
					</h2>
					<div class="ffc-certificates-side-empty">
						<?php
						$ffc_side_empty = AdminUI::get_empty_state(
							array(
								'icon'  => 'calendar',
								'title' => __( 'Pick a day in the calendar to see the forms scheduled for it.', 'ffcertificate' ),
							)
						);
						echo $ffc_side_empty; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_empty_state() escapes every value.
						?>
					</div>
					<ul id="ffc-certificates-day-list" class="ffc-certificates-day-list" hidden></ul>
				</aside>
			</div>
		</div>
		<?php
	}

	/**
	 * The summary row above the calendar (#1614).
	 *
	 * The month count is filled in by the dashboard script from the calendar
	 * payload it already fetches, so it follows the month on screen; the
	 * other three describe now and are counted here, once per page load.
	 */
	private function render_summary(): void {
		$summary = $this->summary();
		echo '<div class="ffc-stats ffc-certificates-stats">';
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- get_stat_card() escapes every value.
		echo AdminUI::get_stat_card(
			array(
				'label' => __( 'Forms in the month shown', 'ffcertificate' ),
				'icon'  => 'file',
				'id'    => 'ffc-cert-stat-month',
			)
		);
		echo AdminUI::get_stat_card(
			array(
				'label' => __( 'Submissions today', 'ffcertificate' ),
				'value' => $summary['today'],
				'icon'  => 'inbox',
			)
		);
		echo AdminUI::get_stat_card(
			array(
				'label' => __( 'Submissions in the last 7 days', 'ffcertificate' ),
				'value' => $summary['week'],
				'icon'  => 'chart',
			)
		);
		echo AdminUI::get_stat_card(
			array(
				'label' => __( 'GeoFence windows open now', 'ffcertificate' ),
				'value' => $summary['open'],
				'icon'  => 'clock',
			)
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
	}

	/**
	 * Submissions today and over the last 7 days, and the forms whose
	 * date/time window is open now.
	 *
	 * "Today" starts at midnight in the site timezone; the last 7 days are
	 * today and the six before it. A window is open when the form's
	 * date/time restriction is on and the same check the public form runs
	 * (`Geofence::validate_datetime()`) lets a visitor in now.
	 *
	 * @return array{today: int, week: int, open: int}
	 */
	public function summary(): array {
		$today = new \DateTimeImmutable( 'today', wp_timezone() );
		$repo  = new \FreeFormCertificate\Repositories\SubmissionRepository();

		$open = 0;
		$ids  = get_posts(
			array(
				'post_type'      => 'ffc_form',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		foreach ( $ids as $id ) {
			$config = get_post_meta( (int) $id, '_ffc_geofence_config', true );
			if ( ! is_array( $config ) || '1' !== (string) ( $config['datetime_enabled'] ?? '' ) ) {
				continue;
			}
			$check = \FreeFormCertificate\Security\Geofence::validate_datetime( $config );
			if ( ! empty( $check['valid'] ) ) {
				++$open;
			}
		}

		return array(
			'today' => $repo->countPublishedSince( $today->getTimestamp() ),
			'week'  => $repo->countPublishedSince( $today->modify( '-6 days' )->getTimestamp() ),
			'open'  => $open,
		);
	}

	/**
	 * Whether the supplied admin hook suffix corresponds to this dashboard.
	 *
	 * @param string $hook_suffix Hook suffix received in admin_enqueue_scripts.
	 * @return bool
	 */
	public static function is_dashboard_hook( string $hook_suffix ): bool {
		// WordPress builds the suffix as `<post_type>_page_<menu_slug>`.
		return 'ffc_form_page_' . self::MENU_SLUG === $hook_suffix;
	}
}

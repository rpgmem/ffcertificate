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
	public const CAPABILITY = 'edit_others_posts';

	/**
	 * Register WordPress hooks.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		// Priority 99 so all CPT-injected items (All / Add New) plus our siblings
		// (Submissions, Activity Log) are already registered before we reorder.
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
		<div class="wrap">
			<h1><?php esc_html_e( 'Certificates Dashboard', 'ffcertificate' ); ?></h1>
			<p><?php esc_html_e( 'Visual overview of all certificate forms by GeoFence start date (fallback: publication date).', 'ffcertificate' ); ?></p>
			<div id="ffc-certificates-dashboard" class="ffc-certificates-dashboard"></div>
		</div>
		<?php
	}
}

<?php
/**
 * Self-Scheduling Admin
 *
 * Manages admin interface for self-scheduling appointments.
 * Registers "Appointments" submenu under the unified Scheduling menu.
 *
 * @since 4.1.0
 * @version 4.6.0 - Migrated to unified Scheduling menu
 * @package FreeFormCertificate\SelfScheduling
 */

declare(strict_types=1);

namespace FreeFormCertificate\SelfScheduling;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin interface for self-scheduling appointments.
 *
 * @since 4.1.0
 */
class SelfSchedulingAdmin {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_submenu_pages' ), 25 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Add submenu pages to unified Scheduling menu
	 *
	 * @return void
	 */
	public function add_submenu_pages(): void {
		// Add Appointments submenu under unified Scheduling menu.
		add_submenu_page(
			'ffc-scheduling',
			__( 'Appointments', 'ffcertificate' ),
			__( 'Appointments', 'ffcertificate' ),
			'ffc_view_appointments',
			'ffc-appointments',
			array( $this, 'render_appointments_page' )
		);
	}

	/**
	 * Render Appointments page
	 *
	 * @return void
	 */
	public function render_appointments_page(): void {
		// 3-state: read-only viewers (ffc_view_appointments) may open the
		// appointments list; cancel/write actions remain manage-gated.
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_view_appointments' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ffcertificate' ) );
		}

		// Capture fatal errors that bypass try-catch (E_COMPILE_ERROR, E_PARSE, etc.).
		register_shutdown_function(
			static function (): void {
				$error = error_get_last();
				if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
					if ( class_exists( '\\FreeFormCertificate\\Core\\Utils', false ) ) {
						\FreeFormCertificate\Core\Debug::log_self_scheduling( 'Appointments page FATAL error (shutdown)', $error );
					}
				}
			}
		);

		try {
			require_once plugin_dir_path( __FILE__ ) . 'views/appointments-list.php';
		} catch ( \Throwable $e ) {
			echo '<div class="wrap"><div class="notice notice-error"><p><strong>'
				. esc_html__( 'Error:', 'ffcertificate' ) . '</strong> '
				. esc_html( $e->getMessage() )
				. ' <em>(' . esc_html( basename( $e->getFile() ) ) . ':' . esc_html( (string) $e->getLine() ) . ')</em>'
				. '</p></div></div>';
			\FreeFormCertificate\Core\Debug::log_self_scheduling(
				'Appointments page error',
				array(
					'error' => $e->getMessage(),
					'file'  => $e->getFile(),
					'line'  => $e->getLine(),
					'trace' => $e->getTraceAsString(),
				)
			);
		}
	}

	/**
	 * Enqueue admin assets
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_admin_assets( string $hook ): void {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// Match self-scheduling screens (CPT edit/list + appointments page).
		$is_self_scheduling = (
			'ffc_self_scheduling' === $screen->post_type ||
			strpos( $screen->id, 'ffc-appointments' ) !== false
		);

		if ( ! $is_self_scheduling ) {
			return;
		}

		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();

		// Only the status-badge styles are needed on this screen. The old
		// ffc-calendar-admin.js was an empty stub whose localize object
		// (ffcSelfSchedulingAdmin) and admin nonce were never read by any
		// script, and the jquery-ui deps/theme it pulled in had no widget to
		// drive — all removed in the frontend-audit Item 4 cleanup.
		wp_enqueue_style(
			'ffc-calendar-admin',
			plugins_url( "assets/css/ffc-calendar-admin{$s}.css", dirname( __DIR__, 1 ) ),
			array(),
			FFC_VERSION
		);

		// Shared batched-export driver (#772): the appointments list "Export CSV"
		// button drives the unified `ffc_export_*` dispatcher through
		// window.FFCBatchedExport, which needs FFC.request from ffc-core.
		wp_enqueue_script(
			'ffc-core',
			plugins_url( "assets/js/ffc-core{$s}.js", dirname( __DIR__, 1 ) ),
			array( 'jquery' ),
			FFC_VERSION,
			true
		);
		wp_enqueue_script(
			'ffc-batched-export',
			plugins_url( "assets/js/ffc-batched-export{$s}.js", dirname( __DIR__, 1 ) ),
			array( 'jquery', 'ffc-core' ),
			FFC_VERSION,
			true
		);
		// Shared progress-overlay modal styles (#786): the batched-export driver
		// renders window.FFCProgressOverlay, identical to the public download.
		wp_enqueue_style(
			'ffc-progress-overlay',
			plugins_url( "assets/css/ffc-progress-overlay{$s}.css", dirname( __DIR__, 1 ) ),
			array(),
			FFC_VERSION
		);

		// Row "Cancel" action handler + the batched CSV export button handler for
		// the appointments list (extracted from an inline onclick in
		// views/appointments-list.php).
		wp_enqueue_script(
			'ffc-self-scheduling-admin-appointments',
			plugins_url( "assets/js/ffc-self-scheduling-admin-appointments{$s}.js", dirname( __DIR__, 1 ) ),
			array( 'ffc-batched-export' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			'ffc-self-scheduling-admin-appointments',
			'ffcAppointmentsExport',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'exportNonce' => wp_create_nonce( 'ffc_appointments_export' ),
				'strings'     => array(
					'exportPreparing' => __( 'Preparing…', 'ffcertificate' ),
					/* translators: %1$d processed, %2$d total */
					'exportProgress'  => __( 'Exporting %1$d/%2$d…', 'ffcertificate' ),
					'exportDone'      => __( 'Done!', 'ffcertificate' ),
					'error'           => __( 'An error occurred.', 'ffcertificate' ),
				),
			)
		);
	}
}

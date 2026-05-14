<?php
/**
 * Activity Log AJAX endpoint — filter / search / pagination without
 * a full page reload.
 *
 * Returns server-rendered HTML for the table body + pagination block.
 * The JS-side handler simply swaps the matching containers, no row
 * reconstruction on the client (which would risk drifting from the
 * `AdminActivityLogPage::get_level_badge` / `get_action_label` helpers).
 *
 * Security:
 *   - nonce verified against the action name (FFC.request supplies it).
 *   - capability gated on `ffc_view_activity_log` — same cap as the
 *     submenu, so privilege boundaries don't shift.
 *
 * @package FreeFormCertificate\Admin
 * @since 6.5.8
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX endpoint for the Activity Log table refresh.
 */
class ActivityLogAjaxEndpoint {

	public const AJAX_ACTION = 'ffc_activity_log_fetch';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Handle the AJAX request.
	 */
	public static function handle(): void {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );

		if ( ! \FreeFormCertificate\Core\Utils::current_user_can_admin_or( 'ffc_view_activity_log' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to view activity logs.', 'ffcertificate' ) ),
				403
			);
		}

		// Activity log can be globally disabled — render a notice
		// rather than an empty table.
		$settings = get_option( 'ffc_settings', array() );
		if ( empty( $settings['enable_activity_log'] ) || 1 !== (int) $settings['enable_activity_log'] ) {
			wp_send_json_error(
				array( 'message' => __( 'Activity Log is currently disabled.', 'ffcertificate' ) ),
				400
			);
		}

		$per_page = 50;

		$params = array(
			'level'      => isset( $_POST['level'] ) ? wp_unslash( $_POST['level'] ) : '',
			'log_action' => isset( $_POST['log_action'] ) ? wp_unslash( $_POST['log_action'] ) : '',
			'search'     => isset( $_POST['search'] ) ? wp_unslash( $_POST['search'] ) : '',
			'paged'      => isset( $_POST['paged'] ) ? wp_unslash( $_POST['paged'] ) : 1,
		);

		$args         = AdminActivityLogPage::build_query_args( $params, $per_page );
		$current_page = (int) ( ( $args['offset'] / $per_page ) + 1 );

		$logs       = \FreeFormCertificate\Core\ActivityLog::get_activities( $args );
		$total_logs = \FreeFormCertificate\Core\ActivityLog::count_activities( $args );

		wp_send_json_success(
			array(
				'table_html'      => AdminActivityLogPage::render_rows_html( $logs ),
				'pagination_html' => AdminActivityLogPage::render_pagination_html( (int) $total_logs, $current_page, $per_page ),
				'total_logs'      => (int) $total_logs,
				'total_pages'     => (int) ceil( $total_logs / $per_page ),
				'current_page'    => $current_page,
				'is_empty'        => empty( $logs ),
			)
		);
	}
}

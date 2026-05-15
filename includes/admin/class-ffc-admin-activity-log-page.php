<?php
/**
 * AdminActivityLogPage
 * Displays activity logs with filtering and pagination
 *
 * @package FreeFormCertificate\Admin
 * @since 3.1.1
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace (Phase 2)
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page for the Activity Log screen.
 */
class AdminActivityLogPage {

	/**
	 * Register admin menu and export handler
	 */
	public function register_menu(): void {
		add_submenu_page(
			'edit.php?post_type=ffc_form',
			__( 'Activity Log', 'ffcertificate' ),
			__( 'Activity Log', 'ffcertificate' ),
			'ffc_view_activity_log',
			'ffc-activity-log',
			array( $this, 'render_page' )
		);

		add_action( 'admin_init', array( $this, 'handle_csv_export' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue the AJAX-filter script on the Activity Log page only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( 'ffc_form_page_ffc-activity-log' !== $hook ) {
			return;
		}
		$s = \FreeFormCertificate\Core\Utils::asset_suffix();
		wp_enqueue_script(
			'ffc-core',
			FFC_PLUGIN_URL . "assets/js/ffc-core{$s}.js",
			array( 'jquery' ),
			FFC_VERSION,
			true
		);
		wp_enqueue_script(
			'ffc-admin-activity-log',
			FFC_PLUGIN_URL . "assets/js/ffc-admin-activity-log{$s}.js",
			array( 'jquery', 'ffc-core', 'ffc-admin-js' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			'ffc-admin-activity-log',
			'ffcActivityLog',
			array(
				'nonce'   => wp_create_nonce( ActivityLogAjaxEndpoint::AJAX_ACTION ),
				'strings' => array(
					'noLogs'     => __( 'No activity logs found.', 'ffcertificate' ),
					'error'      => __( 'Failed to fetch logs.', 'ffcertificate' ),
					'preparing'  => __( 'Preparing CSV download…', 'ffcertificate' ),
					'colDate'    => __( 'Date/Time', 'ffcertificate' ),
					'colLevel'   => __( 'Level', 'ffcertificate' ),
					'colAction'  => __( 'Action', 'ffcertificate' ),
					'colUser'    => __( 'User', 'ffcertificate' ),
					'colIp'      => __( 'IP Address', 'ffcertificate' ),
					'colContext' => __( 'Context', 'ffcertificate' ),
				),
			)
		);
	}

	/**
	 * Handle CSV export of activity logs
	 *
	 * @since 5.2.0
	 */
	public function handle_csv_export(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked below
		if ( ! isset( $_GET['page'] ) || sanitize_text_field( wp_unslash( $_GET['page'] ) ) !== 'ffc-activity-log' ) {
			return;
		}
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['ffc_export_logs'] ) ) {
			return;
		}

		if ( ! \FreeFormCertificate\Core\Utils::current_user_can_admin_or( 'ffc_view_activity_log' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'ffcertificate' ) );
		}

		check_admin_referer( 'ffc_export_activity_log' );

		// Gather current filters.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Already verified above
		$args = array(
			'limit'   => 999999, // Export all matching rows.
			'offset'  => 0,
			'orderby' => 'created_at',
			'order'   => 'DESC',
		);

		$level = isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : '';
		if ( $level ) {
			$args['level'] = $level;
		}

		$action = isset( $_GET['log_action'] ) ? sanitize_text_field( wp_unslash( $_GET['log_action'] ) ) : '';
		if ( $action ) {
			$args['action'] = $action;
		}

		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		if ( $search ) {
			$args['search'] = $search;
		}
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

		$logs = \FreeFormCertificate\Core\ActivityLog::get_activities( $args );

		$headers = array(
			__( 'Date/Time', 'ffcertificate' ),
			__( 'Level', 'ffcertificate' ),
			__( 'Action', 'ffcertificate' ),
			__( 'User', 'ffcertificate' ),
			__( 'IP Address', 'ffcertificate' ),
			__( 'Context', 'ffcertificate' ),
		);

		$rows = array();
		foreach ( $logs as $log ) {
			$user_display = __( 'System / Anonymous', 'ffcertificate' );
			if ( ! empty( $log['user_id'] ) && (int) $log['user_id'] > 0 ) {
				$user         = get_userdata( (int) $log['user_id'] );
				$user_display = $user ? $user->display_name . ' (' . $user->user_login . ')' : sprintf( 'User #%d', $log['user_id'] );
			}

			$context = '';
			if ( ! empty( $log['context'] ) ) {
				$context = is_array( $log['context'] )
					? wp_json_encode( $log['context'], JSON_UNESCAPED_UNICODE )
					: (string) $log['context'];
			}

			$rows[] = array(
				$log['created_at'] ?? '',
				strtoupper( $log['level'] ?? '' ),
				self::get_action_label( $log['action'] ?? '' ),
				$user_display,
				$log['user_ip'] ?? '',
				$context,
			);
		}

		$filename      = 'ffc-activity-log-' . gmdate( 'Y-m-d' ) . '.csv';
		$safe_filename = str_replace( array( "\r", "\n", '"' ), '', $filename );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $safe_filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming CSV download to php://output.
		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			exit;
		}
		$writer = \FreeFormCertificate\Core\Csv::writer( $output );
		$writer->row( $headers );
		$writer->rows( $rows );
		$writer->close();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the php://output handle this method opened.
		fclose( $output );
		exit;
	}

	/**
	 * Render activity log page
	 */
	public function render_page(): void {
		// Check if Activity Log is enabled.
		$settings   = get_option( 'ffc_settings', array() );
		$is_enabled = isset( $settings['enable_activity_log'] ) && 1 === $settings['enable_activity_log'];

		if ( ! $is_enabled ) {
			$this->render_disabled_notice();
			return;
		}

		// Get filter parameters.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- These are standard admin page filter/pagination parameters.
		$current_page = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$per_page     = 50;
		$level        = isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : '';
		$action       = isset( $_GET['log_action'] ) ? sanitize_text_field( wp_unslash( $_GET['log_action'] ) ) : '';
		$search       = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Get logs.
		$args = array(
			'limit'   => $per_page,
			'offset'  => ( $current_page - 1 ) * $per_page,
			'orderby' => 'created_at',
			'order'   => 'DESC',
		);

		if ( $level ) {
			$args['level'] = $level;
		}

		if ( $action ) {
			$args['action'] = $action;
		}

		if ( $search ) {
			$args['search'] = $search;
		}

		$logs        = \FreeFormCertificate\Core\ActivityLog::get_activities( $args );
		$total_logs  = \FreeFormCertificate\Core\ActivityLog::count_activities( $args );
		$total_pages = ceil( $total_logs / $per_page );

		// Get unique actions for filter.
		$unique_actions = $this->get_unique_actions();

		// Render view.
		$view_file = FFC_PLUGIN_DIR . 'includes/admin/views/ffc-admin-activity-log.php';

		if ( file_exists( $view_file ) ) {
			include $view_file;
		} else {
			echo '<div class="wrap"><h1>' . esc_html__( 'Activity Log', 'ffcertificate' ) . '</h1>';
			echo '<div class="notice notice-error"><p>' . esc_html__( 'View file not found.', 'ffcertificate' ) . '</p></div></div>';
		}
	}

	/**
	 * Render disabled notice
	 */
	private function render_disabled_notice(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Activity Log', 'ffcertificate' ); ?></h1>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Activity Log is currently disabled.', 'ffcertificate' ); ?></strong>
				</p>
				<p>
					<?php esc_html_e( 'To enable activity logging, go to:', 'ffcertificate' ); ?>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=ffc_form&page=ffc-settings&tab=advanced' ) ); ?>">
						<?php esc_html_e( 'Settings > Advanced > Activity Log Settings', 'ffcertificate' ); ?>
					</a>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Get unique actions from database
	 *
	 * @return array<int, string>
	 */
	private function get_unique_actions(): array {
		global $wpdb;
		$table_name = $wpdb->prefix . 'ffc_activity_log';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$actions = $wpdb->get_col(
			$wpdb->prepare( 'SELECT DISTINCT action FROM %i ORDER BY action ASC', $table_name )
		);

		return $actions;
	}

	/**
	 * Get human-readable action name
	 *
	 * @param string $action Action.
	 */
	public static function get_action_label( string $action ): string {
		$labels = array(
			'submission_created'     => __( 'Submission Created', 'ffcertificate' ),
			'submission_updated'     => __( 'Submission Updated', 'ffcertificate' ),
			'submission_deleted'     => __( 'Submission Deleted', 'ffcertificate' ),
			'data_accessed'          => __( 'Data Accessed', 'ffcertificate' ),
			'access_denied'          => __( 'Access Denied', 'ffcertificate' ),
			'settings_changed'       => __( 'Settings Changed', 'ffcertificate' ),
			// Public Operator Access (#224) — early-open + ticket cleanup.
			'early_open_executed'    => __( 'Form Started Early', 'ffcertificate' ),
			'tickets_purged_expired' => __( 'Expired Form Tickets Cleared', 'ffcertificate' ),
			// Postpone close (6.5.12).
			'end_postponed'          => __( 'Form Close Postponed', 'ffcertificate' ),
		);

		return isset( $labels[ $action ] ) ? $labels[ $action ] : ucwords( str_replace( '_', ' ', $action ) );
	}

	/**
	 * Get level badge HTML
	 *
	 * @param string $level Level.
	 */
	public static function get_level_badge( string $level ): string {
		$classes = array(
			'info'    => 'ffc-badge-info',
			'warning' => 'ffc-badge-warning',
			'error'   => 'ffc-badge-error',
			'debug'   => 'ffc-badge-debug',
		);

		$class = isset( $classes[ $level ] ) ? $classes[ $level ] : 'ffc-badge-info';

		return '<span class="ffc-badge ' . esc_attr( $class ) . '">' . esc_html( strtoupper( $level ) ) . '</span>';
	}

	/**
	 * Build the args array passed to ActivityLog::get_activities /
	 * count_activities. Reads filter params off the request shape
	 * supplied by the page render OR the AJAX endpoint.
	 *
	 * @param array<string, mixed> $params Raw filter params
	 *                                     (level / log_action / search / paged).
	 * @param int                  $per_page Page size.
	 * @return array<string, mixed>
	 */
	public static function build_query_args( array $params, int $per_page = 50 ): array {
		$current_page = isset( $params['paged'] ) ? max( 1, absint( $params['paged'] ) ) : 1;

		$args = array(
			'limit'   => $per_page,
			'offset'  => ( $current_page - 1 ) * $per_page,
			'orderby' => 'created_at',
			'order'   => 'DESC',
		);

		$level = isset( $params['level'] ) ? sanitize_key( $params['level'] ) : '';
		if ( $level ) {
			$args['level'] = $level;
		}

		$action = isset( $params['log_action'] ) ? sanitize_text_field( $params['log_action'] ) : '';
		if ( $action ) {
			$args['action'] = $action;
		}

		$search = isset( $params['search'] ) ? sanitize_text_field( $params['search'] ) : '';
		if ( $search ) {
			$args['search'] = $search;
		}

		return $args;
	}

	/**
	 * Render the activity-log table body (all <tr> rows for the page).
	 *
	 * Extracted so the initial PHP render and the AJAX refresh share
	 * the same HTML — the helper handles level badges, user lookup,
	 * IP / context formatting consistently.
	 *
	 * @param array<int, array<string, mixed>> $logs Rows.
	 * @return string HTML — sequence of <tr>…</tr>, no enclosing <tbody>.
	 */
	public static function render_rows_html( array $logs ): string {
		if ( empty( $logs ) ) {
			return '';
		}
		ob_start();
		foreach ( $logs as $ffcertificate_log ) :
			?>
			<tr>
				<td>
					<strong><?php echo esc_html( date_i18n( 'Y-m-d', strtotime( (string) ( $ffcertificate_log['created_at'] ?? '' ) ) ) ); ?></strong><br>
					<span class="description"><?php echo esc_html( date_i18n( 'H:i:s', strtotime( (string) ( $ffcertificate_log['created_at'] ?? '' ) ) ) ); ?></span>
				</td>
				<td>
					<?php echo wp_kses_post( self::get_level_badge( (string) ( $ffcertificate_log['level'] ?? '' ) ) ); ?>
				</td>
				<td>
					<strong><?php echo esc_html( self::get_action_label( (string) ( $ffcertificate_log['action'] ?? '' ) ) ); ?></strong><br>
					<code class="description"><?php echo esc_html( (string) ( $ffcertificate_log['action'] ?? '' ) ); ?></code>
				</td>
				<td>
					<?php
					$ffcertificate_uid = (int) ( $ffcertificate_log['user_id'] ?? 0 );
					if ( $ffcertificate_uid > 0 ) {
						$ffcertificate_user = get_userdata( $ffcertificate_uid );
						if ( $ffcertificate_user ) {
							echo '<strong>' . esc_html( $ffcertificate_user->display_name ) . '</strong><br>';
							echo '<span class="description">' . esc_html( $ffcertificate_user->user_login ) . '</span>';
						} else {
							/* translators: %d: user ID */
							echo '<span class="description">' . esc_html( sprintf( __( 'User #%d (deleted)', 'ffcertificate' ), $ffcertificate_uid ) ) . '</span>';
						}
					} else {
						echo '<span class="description">' . esc_html__( 'System / Anonymous', 'ffcertificate' ) . '</span>';
					}
					?>
				</td>
				<td>
					<code><?php echo esc_html( (string) ( $ffcertificate_log['user_ip'] ?? '' ) ); ?></code>
				</td>
				<td>
					<?php
					if ( ! empty( $ffcertificate_log['context'] ) ) :
						// wp_json_encode returns string|false. The false case (circular
						// refs, malformed UTF-8) is treated as "no context" so the
						// admin still sees a valid <details> block.
						$ffcertificate_context_json = wp_json_encode( $ffcertificate_log['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
						if ( false === $ffcertificate_context_json ) {
							$ffcertificate_context_json = '';
						}
						?>
						<details>
							<summary class="ffc-log-summary">
								<?php esc_html_e( 'View Details', 'ffcertificate' ); ?> ▼
							</summary>
							<pre class="ffc-log-pre"><?php echo esc_html( $ffcertificate_context_json ); ?></pre>
						</details>
					<?php else : ?>
						<span class="description">—</span>
					<?php endif; ?>
				</td>
			</tr>
			<?php
		endforeach;
		return (string) ob_get_clean();
	}

	/**
	 * Render the pagination block for the activity-log table.
	 *
	 * @param int    $total_logs   Total log rows (across all pages).
	 * @param int    $current_page Current page (1-indexed).
	 * @param int    $per_page     Page size.
	 * @param string $base_url     Base admin URL — caller decides whether
	 *                              to add the filter query string here.
	 *                              The pagination links will append `paged`.
	 * @return string HTML — empty when there's only one page.
	 */
	public static function render_pagination_html( int $total_logs, int $current_page, int $per_page, string $base_url = '' ): string {
		$total_pages = (int) ceil( $total_logs / max( 1, $per_page ) );
		if ( $total_pages <= 1 ) {
			return '';
		}

		ob_start();
		?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<span class="displaying-num">
					<?php
					/* translators: %s: number of logs */
					printf( esc_html( _n( '%s log', '%s logs', $total_logs, 'ffcertificate' ) ), esc_html( number_format_i18n( $total_logs ) ) );
					?>
				</span>
				<?php
				$pagination_args = array(
					'base'      => '' !== $base_url ? add_query_arg( 'paged', '%#%', $base_url ) : add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
					'total'     => $total_pages,
					'current'   => $current_page,
				);
				echo wp_kses_post( (string) paginate_links( $pagination_args ) );
				?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}

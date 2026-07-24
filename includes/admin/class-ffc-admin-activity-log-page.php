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
	 * Constructor. Registers the batched CSV-export source with the shared
	 * registry (#772). Done here — not in register_menu(), which is hooked on
	 * `admin_menu` and never fires on admin-ajax — so the unified dispatcher can
	 * route `type=activity_log` start/batch/download requests to it. This class
	 * is instantiated by {@see Admin} on every admin request (admin-ajax
	 * included).
	 */
	public function __construct() {
		\FreeFormCertificate\Core\SourceRegistry::register(
			ActivityLogExportSource::TYPE,
			static function (): ActivityLogExportSource {
				return new ActivityLogExportSource();
			}
		);
	}

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
		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();
		wp_enqueue_script(
			'ffc-core',
			FFC_PLUGIN_URL . "assets/js/ffc-core{$s}.js",
			array( 'jquery' ),
			FFC_VERSION,
			true
		);
		// Shared batched-export driver (#772): the CSV export button drives the
		// unified `ffc_export_*` dispatcher through window.FFCBatchedExport.
		wp_enqueue_script(
			'ffc-batched-export',
			FFC_PLUGIN_URL . "assets/js/ffc-batched-export{$s}.js",
			array( 'jquery', 'ffc-core' ),
			FFC_VERSION,
			true
		);
		wp_enqueue_script(
			'ffc-admin-activity-log',
			FFC_PLUGIN_URL . "assets/js/ffc-admin-activity-log{$s}.js",
			array( 'jquery', 'ffc-core', 'ffc-admin-js', 'ffc-batched-export' ),
			FFC_VERSION,
			true
		);
		wp_localize_script(
			'ffc-admin-activity-log',
			'ffcActivityLog',
			array(
				'nonce'       => wp_create_nonce( ActivityLogAjaxEndpoint::AJAX_ACTION ),
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'exportNonce' => wp_create_nonce( 'ffc_activity_log_export' ),
				'strings'     => array(
					'noLogs'          => __( 'No activity logs found.', 'ffcertificate' ),
					'error'           => __( 'Failed to fetch logs.', 'ffcertificate' ),
					'preparing'       => __( 'Preparing CSV download…', 'ffcertificate' ),
					'colDate'         => __( 'Date/Time', 'ffcertificate' ),
					'colLevel'        => __( 'Level', 'ffcertificate' ),
					'colAction'       => __( 'Action', 'ffcertificate' ),
					'colUser'         => __( 'User', 'ffcertificate' ),
					'colIp'           => __( 'IP Address', 'ffcertificate' ),
					'colContext'      => __( 'Context', 'ffcertificate' ),
					'exportPreparing' => __( 'Preparing…', 'ffcertificate' ),
					/* translators: %1$d processed, %2$d total */
					'exportProgress'  => __( 'Exporting %1$d/%2$d…', 'ffcertificate' ),
					'exportDone'      => __( 'Done!', 'ffcertificate' ),
				),
			)
		);
	}

	/**
	 * Render activity log page
	 */
	public function render_page(): void {
		// Check if Activity Log is enabled.
		if ( ! \FreeFormCertificate\Settings\SettingsReader::activity_log_enabled() ) {
			$this->render_disabled_notice();
			return;
		}

		// Get filter parameters.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- These are standard admin page filter/pagination parameters.
		$current_page = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$per_page     = 50;
		$level        = isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : '';
		$action       = \FreeFormCertificate\Core\RequestInput::get_get_string( 'log_action' );
		$search       = \FreeFormCertificate\Core\RequestInput::get_get_string( 's' );
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

		$logs        = \FreeFormCertificate\Core\ActivityLogQuery::get_activities( $args );
		$total_logs  = \FreeFormCertificate\Core\ActivityLogQuery::count_activities( $args );
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
	 * Get unique actions from database (delegates to ActivityLogQuery
	 * so the read path stays centralized — see #331 follow-up).
	 *
	 * @return array<int, string>
	 */
	private function get_unique_actions(): array {
		return \FreeFormCertificate\Core\ActivityLogQuery::distinct_actions();
	}

	/**
	 * Get human-readable action name
	 *
	 * @param string $action Action.
	 */
	public static function get_action_label( string $action ): string {
		$labels = array(
			'submission_created'        => __( 'Submission Created', 'ffcertificate' ),
			'submission_updated'        => __( 'Submission Updated', 'ffcertificate' ),
			'submission_deleted'        => __( 'Submission Deleted', 'ffcertificate' ),
			'data_accessed'             => __( 'Data Accessed', 'ffcertificate' ),
			'access_denied'             => __( 'Access Denied', 'ffcertificate' ),
			'settings_changed'          => __( 'Settings Changed', 'ffcertificate' ),
			// Public Operator Access (#224) — early-open + ticket cleanup.
			'early_open_executed'       => __( 'Form Started Early', 'ffcertificate' ),
			'tickets_purged_expired'    => __( 'Expired Form Tickets Cleared', 'ffcertificate' ),
			// Postpone close (6.5.12).
			'end_postponed'             => __( 'Form Close Postponed', 'ffcertificate' ),
			// Schedule exception per submission (#366 Sprint 9).
			'schedule_override_created' => __( 'Schedule Override Created', 'ffcertificate' ),
			'operator_ip_bypass'        => __( 'Operator IP Bypass', 'ffcertificate' ),
			// Pre-flight telemetry (#356 follow-up).
			'preflight_blocked'         => __( 'Pre-flight Banner Shown', 'ffcertificate' ),
			// Delivery audit.
			'pdf_generated'             => __( 'PDF Generated', 'ffcertificate' ),
			'certificate_emailed'       => __( 'Certificate Emailed', 'ffcertificate' ),
			'csv_downloaded'            => __( 'CSV Downloaded', 'ffcertificate' ),
		);

		return isset( $labels[ $action ] ) ? $labels[ $action ] : ucwords( str_replace( '_', ' ', $action ) );
	}

	/**
	 * Pretty-print the context JSON for the two schedule-exception
	 * action types (#366). The base renderer dumps the raw JSON in a
	 * `<pre>` block which is correct but hostile to scan — for the
	 * exception flow we surface the four facts an admin actually
	 * triages on (before/after range, operator masked CPF,
	 * participant CPF hash prefix) above the raw dump.
	 *
	 * Returns null for any action that isn't one of ours so the
	 * caller falls back to the raw `<pre>` rendering.
	 *
	 * @param string               $action  Action tag.
	 * @param array<string, mixed> $context Decoded context array.
	 * @return string|null
	 */
	public static function render_schedule_exception_summary( string $action, array $context ): ?string {
		if ( 'schedule_override_created' === $action ) {
			$before = \FreeFormCertificate\Core\DateFormatter::format_schedule(
				(string) ( $context['schedule_start_before'] ?? '' ),
				(string) ( $context['schedule_end_before'] ?? '' )
			);
			$after  = \FreeFormCertificate\Core\DateFormatter::format_schedule(
				(string) ( $context['schedule_start_after'] ?? '' ),
				(string) ( $context['schedule_end_after'] ?? '' )
			);

			$rows = array(
				array( __( 'Before', 'ffcertificate' ), $before ),
				array( __( 'After', 'ffcertificate' ), $after ),
				array( __( 'Operator (masked)', 'ffcertificate' ), (string) ( $context['operator_cpf_masked'] ?? '' ) ),
				array( __( 'Participant CPF hash', 'ffcertificate' ), self::shorten_hash( (string) ( $context['participant_cpf_hash'] ?? '' ) ) ),
				array( __( 'Submission ID', 'ffcertificate' ), (string) ( $context['submission_id'] ?? '' ) ),
			);

			return self::render_summary_rows( $rows );
		}

		if ( 'operator_ip_bypass' === $action ) {
			$rows = array(
				array( __( 'Bypassed IP', 'ffcertificate' ), (string) ( $context['bypassed_ip'] ?? '' ) ),
				array( __( 'Operator (masked)', 'ffcertificate' ), (string) ( $context['operator_cpf_masked'] ?? '' ) ),
				array( __( 'Submission ID', 'ffcertificate' ), (string) ( $context['submission_id'] ?? '' ) ),
			);

			return self::render_summary_rows( $rows );
		}

		return null;
	}

	/**
	 * Human-readable label for a pre-flight `reason` code.
	 *
	 * The stored value (`cookies` / `gps_denied` / `gps_prompt`, see
	 * PreflightTelemetry::ALLOWED_REASONS) is a stable machine key the
	 * stats aggregator counts on — we never rewrite it. This maps it to a
	 * label for display only. `gps_prompt` in particular is the
	 * *pre-explainer* banner shown before the browser's GPS permission
	 * dialog, which the raw code does not convey.
	 *
	 * @param string $reason Stored reason code.
	 * @return string Translated label, or the raw code if unrecognized.
	 */
	public static function get_preflight_reason_label( string $reason ): string {
		$labels = array(
			'cookies'    => __( 'Cookie wall shown (browser blocked our probe cookie)', 'ffcertificate' ),
			'gps_denied' => __( 'GPS permission denied by the visitor', 'ffcertificate' ),
			'gps_prompt' => __( 'GPS permission pre-explainer shown (before the browser prompt)', 'ffcertificate' ),
		);
		return $labels[ $reason ] ?? $reason;
	}

	/**
	 * Pretty-print the `preflight_blocked` context. Surfaces the human
	 * reason label, form, and hashed visitor above the raw JSON dump so
	 * admins don't have to decode `"reason":"gps_prompt"` by hand.
	 *
	 * Returns null for any other action so the caller falls back to the
	 * raw `<pre>` rendering.
	 *
	 * @param string               $action  Action tag.
	 * @param array<string, mixed> $context Decoded context array.
	 * @return string|null
	 */
	public static function render_preflight_blocked_summary( string $action, array $context ): ?string {
		if ( 'preflight_blocked' !== $action ) {
			return null;
		}

		$reason = isset( $context['reason'] ) ? (string) $context['reason'] : '';

		$rows = array(
			array( __( 'Reason', 'ffcertificate' ), self::get_preflight_reason_label( $reason ) ),
			array( __( 'Form ID', 'ffcertificate' ), isset( $context['form_id'] ) ? (string) (int) $context['form_id'] : '' ),
			array( __( 'Visitor (IP hash)', 'ffcertificate' ), self::shorten_hash( (string) ( $context['ip_hash'] ?? '' ) ) ),
		);

		return self::render_summary_rows( $rows );
	}

	/**
	 * Truncate a sha256 hex digest to the first 12 chars so it stays
	 * visible without crowding the row. Full hash remains in the
	 * raw JSON dump below for forensic correlation.
	 *
	 * @param string $hash Full hash to shorten.
	 */
	private static function shorten_hash( string $hash ): string {
		if ( strlen( $hash ) <= 12 ) {
			return $hash;
		}
		return substr( $hash, 0, 12 ) . '…';
	}

	/**
	 * Render a list of (label, value) rows as a compact dl block.
	 *
	 * @param array<int, array{0: string, 1: string}> $rows Label / value pairs.
	 */
	private static function render_summary_rows( array $rows ): string {
		$out = '<dl class="ffc-log-summary-dl">';
		foreach ( $rows as $row ) {
			if ( '' === $row[1] ) {
				continue;
			}
			$out .= '<dt>' . esc_html( $row[0] ) . '</dt>';
			$out .= '<dd>' . esc_html( $row[1] ) . '</dd>';
		}
		$out .= '</dl>';
		return $out;
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
					<strong><?php echo esc_html( \FreeFormCertificate\Core\DateFormatter::format_date( (string) ( $ffcertificate_log['created_at'] ?? '' ) ) ); ?></strong><br>
					<span class="description"><?php echo esc_html( \FreeFormCertificate\Core\DateFormatter::format_time( (string) ( $ffcertificate_log['created_at'] ?? '' ) ) ); ?></span>
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
						// Schedule exception flow (#366 Sprint 9) gets a
						// friendly summary above the raw JSON dump. Other
						// action types render the raw block as before.
						$ffcertificate_context_arr = is_array( $ffcertificate_log['context'] ) ? $ffcertificate_log['context'] : array();
						$ffcertificate_action_tag  = (string) ( $ffcertificate_log['action'] ?? '' );
						$ffcertificate_summary     = self::render_schedule_exception_summary(
							$ffcertificate_action_tag,
							$ffcertificate_context_arr
						);
						if ( null === $ffcertificate_summary ) {
							$ffcertificate_summary = self::render_preflight_blocked_summary(
								$ffcertificate_action_tag,
								$ffcertificate_context_arr
							);
						}
						?>
						<?php if ( null !== $ffcertificate_summary ) : ?>
							<?php echo wp_kses_post( $ffcertificate_summary ); ?>
						<?php endif; ?>
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

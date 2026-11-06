<?php
/**
 * AdminActivityLogPage
 * Displays activity logs with filtering and pagination
 *
 * @package FreeFormCertificate\Admin
 * @since 3.2.0
 * @version 3.3.0 - Added strict types and type hints
 * @version 3.2.0 - Migrated to namespace
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\ArrayValue;
use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page for the Activity Log screen.
 */
class AdminActivityLogPage {

	/**
	 * Capability that reveals an entry's client address (#2109).
	 */
	public const PII_CAP = 'ffc_view_activity_log_pii';

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
	 * Enqueue the AJAX-filter + export scripts on the Settings → Activity Log
	 * tab only. Since #802 Phase B the Activity Log is a Settings tab, not a
	 * standalone submenu, so the gate is the Settings page hook + the active
	 * tab (mirroring the other tabs' enqueue guards). Registered from
	 * {@see \FreeFormCertificate\Settings\Tabs\TabActivityLog}.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( 'toplevel_page_ffc-settings' !== $hook ) {
			return;
		}
		$active_tab = RequestInput::get_get_key( 'tab' );
		if ( 'activity_log' !== $active_tab ) {
			return;
		}
		$s = \FreeFormCertificate\Core\AssetHelper::asset_suffix();
		\FreeFormCertificate\Core\AssetRegistry::enqueue_scripts( 'ffc-core' );
		// Shared batched-export driver (#772): the CSV export button drives the
		// unified `ffc_export_*` dispatcher through window.FFCBatchedExport.
		\FreeFormCertificate\Core\AssetRegistry::enqueue_scripts( 'ffc-batched-export' );
		// Shared progress-overlay modal styles (#786): the batched-export driver
		// renders window.FFCProgressOverlay, identical to the public download.
		\FreeFormCertificate\Core\AssetHelper::enqueue_common_style();

		\FreeFormCertificate\Core\AssetRegistry::enqueue_styles( 'ffc-progress-overlay' );
		// The client address is masked, with an audited Reveal (#2109).
		\FreeFormCertificate\Core\PiiReveal::enqueue_assets();
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

		// The same filters the in-place refresh reads (#2340).
		$per_page     = 50;
		$args         = self::build_query_args(
			array(
				'level'      => RequestInput::get_get_key( 'level' ),
				'log_action' => RequestInput::get_get_string( 'log_action' ),
				'search'     => RequestInput::get_get_string( 's' ),
				'user'       => RequestInput::get_get_string( 'log_user' ),
				'date_from'  => RequestInput::get_get_string( 'date_from' ),
				'date_to'    => RequestInput::get_get_string( 'date_to' ),
				'paged'      => RequestInput::get_get_int( 'paged', 1 ),
			),
			$per_page
		);
		$current_page = (int) ( ( $args['offset'] / $per_page ) + 1 );
		$level        = ArrayValue::string( $args, 'level' );
		$action       = ArrayValue::string( $args, 'action' );
		$search       = ArrayValue::string( $args, 'search' );
		$log_user     = ArrayValue::string( $args, 'user_id' );
		$date_from    = ArrayValue::string( $args, 'date_from' );
		$date_to      = ArrayValue::string( $args, 'date_to' );
		$has_filters  = self::has_filters( $args );

		$logs       = \FreeFormCertificate\Core\ActivityLogQuery::get_activities( $args );
		$total_logs = \FreeFormCertificate\Core\ActivityLogQuery::count_activities( $args );

		// The filter lists, each sorted by what the reader sees (#2340).
		$unique_actions = self::sorted_actions( $this->get_unique_actions() );
		$user_options   = self::user_options();
		$can_export     = \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_export_activity_log' );

		// Render view.
		$view_file = FFC_PLUGIN_DIR . 'includes/admin/views/ffc-admin-activity-log.php';

		if ( file_exists( $view_file ) ) {
			include $view_file;
		} else {
			echo '<div class="ffc-settings-wrap"><h2 class="ffc-icon-history">' . esc_html__( 'Activity Log', 'ffcertificate' ) . '</h2>';
			\FreeFormCertificate\Core\AdminNotice::render(
				'error',
				esc_html__( 'View file not found.', 'ffcertificate' )
			);
			echo '</div>';
		}
	}

	/**
	 * Render disabled notice
	 */
	private function render_disabled_notice(): void {
		?>
		<div class="ffc-settings-wrap">
		<div class="card">
			<h2 class="ffc-icon-history"><?php esc_html_e( 'Activity Log', 'ffcertificate' ); ?></h2>
			<?php
			ob_start();
			?>
			<p>
				<strong><?php esc_html_e( 'Activity Log is currently disabled.', 'ffcertificate' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( 'To enable activity logging, go to:', 'ffcertificate' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=ffc-settings&tab=advanced' ) ); ?>">
					<?php esc_html_e( 'Settings > Advanced > Activity Log Settings', 'ffcertificate' ); ?>
				</a>
			</p>
			<?php
			\FreeFormCertificate\Core\AdminNotice::callout(
				'warning',
				(string) ob_get_clean(),
				array(
					// Inline, or WordPress's common.js lifts the notice above
					// the settings tabs and the card is left empty.
					'additional_classes' => array( 'inline' ),
				)
			);
			?>
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
			'submission_created'                           => __( 'Submission Created', 'ffcertificate' ),
			'submission_updated'                           => __( 'Submission Updated', 'ffcertificate' ),
			'submission_deleted'                           => __( 'Submission Deleted', 'ffcertificate' ),
			'quiz_graded'                                  => __( 'Quiz Graded', 'ffcertificate' ),
			'quiz_regraded'                                => __( 'Grades Amended', 'ffcertificate' ),
			'submission_answer_corrected'                  => __( 'Answer Amended', 'ffcertificate' ),
			'data_accessed'                                => __( 'Data Accessed', 'ffcertificate' ),
			'access_denied'                                => __( 'Access Denied', 'ffcertificate' ),
			'settings_changed'                             => __( 'Settings Changed', 'ffcertificate' ),
			// Role editor (#2340).
			'role_capability_changed'                      => __( 'Role Capability Changed', 'ffcertificate' ),
			'role_default_restored'                        => __( 'Role Default Restored', 'ffcertificate' ),
			'user_ffc_roles_removed'                       => __( 'FFC Roles Removed with a Role Change', 'ffcertificate' ),
			'user_role_changed'                            => __( 'User FFC Role Changed', 'ffcertificate' ),
			'user_capabilities_changed'                    => __( 'User Capabilities Changed', 'ffcertificate' ),
			'user_audiences_changed'                       => __( 'User Audiences Changed', 'ffcertificate' ),
			// Public Operator Access (#224) — early-open + ticket cleanup.
			'early_open_executed'                          => __( 'Form Started Early', 'ffcertificate' ),
			'tickets_purged_expired'                       => __( 'Expired Form Tickets Cleared', 'ffcertificate' ),
			// Settings maintenance that changes data (#2338).
			'danger_zone_data_deleted'                     => __( 'Submissions Deleted (Danger Zone)', 'ffcertificate' ),
			'obsolete_shortcodes_removed'                  => __( 'Obsolete Shortcodes Removed', 'ffcertificate' ),
			'short_urls_cleaned'                           => __( 'Short URLs Cleaned Up', 'ffcertificate' ),
			'public_access_bulk_disabled'                  => __( 'Public Operator Access Disabled on Old Forms', 'ffcertificate' ),
			// Postpone close (6.5.12).
			'end_postponed'                                => __( 'Form Close Postponed', 'ffcertificate' ),
			// Schedule exception per submission (#366 Sprint 9).
			'schedule_override_created'                    => __( 'Schedule Override Created', 'ffcertificate' ),
			'operator_ip_bypass'                           => __( 'Operator IP Bypass', 'ffcertificate' ),
			// Pre-flight telemetry (#356 follow-up).
			'preflight_blocked'                            => __( 'Pre-flight Banner Shown', 'ffcertificate' ),
			// Delivery audit.
			'pdf_generated'                                => __( 'PDF Generated', 'ffcertificate' ),
			'certificate_emailed'                          => __( 'Certificate Emailed', 'ffcertificate' ),
			'csv_downloaded'                               => __( 'CSV Downloaded', 'ffcertificate' ),
			'pii_exported'                                 => __( 'Personal Data Exported', 'ffcertificate' ),
			'captcha_fallback_used'                        => __( 'Captcha: fallback used', 'ffcertificate' ),

			// Submission lifecycle.
			'submission_trashed'                           => __( 'Submission Trashed', 'ffcertificate' ),
			'submission_restored'                          => __( 'Submission Restored', 'ffcertificate' ),
			'submission_moved'                             => __( 'Submission Moved to Another Form', 'ffcertificate' ),
			'submission_pii_revealed'                      => __( 'Submission Personal Data Revealed', 'ffcertificate' ),
			'submission_pdf_revealed'                      => __( 'Certificate PDF Read by Staff', 'ffcertificate' ),
			'bulk_trash'                                   => __( 'Submissions Bulk Trashed', 'ffcertificate' ),
			'bulk_restore'                                 => __( 'Submissions Bulk Restored', 'ffcertificate' ),
			'bulk_delete'                                  => __( 'Submissions Bulk Deleted', 'ffcertificate' ),
			'data_cleanup'                                 => __( 'Old Submissions Cleaned Up', 'ffcertificate' ),
			'user_linked'                                  => __( 'Submission Linked to User', 'ffcertificate' ),
			'user_unlinked'                                => __( 'Submission Unlinked from User', 'ffcertificate' ),
			// Legacy: link/unlink logged under this generic key until #1024
			// corrected the swapped arguments. Kept so existing rows stay
			// readable; nothing writes it any more.
			'submission'                                   => __( 'Submission User Link Changed (legacy)', 'ffcertificate' ),

			// Users & privacy.
			'capabilities_granted'                         => __( 'Capabilities Granted', 'ffcertificate' ),
			'password_changed'                             => __( 'Password Changed', 'ffcertificate' ),
			'profile_updated'                              => __( 'Profile Updated', 'ffcertificate' ),
			'user_email_changed'                           => __( 'User Email Changed', 'ffcertificate' ),
			'user_profile_read_full'                       => __( 'Full Profile Viewed', 'ffcertificate' ),
			'user_profile_pii_revealed'                    => __( 'Profile Personal Data Revealed', 'ffcertificate' ),
			'user_data_anonymized'                         => __( 'User Data Anonymized', 'ffcertificate' ),
			'privacy_request_created'                      => __( 'Privacy Request Created', 'ffcertificate' ),
			'privacy_data_erased'                          => __( 'Privacy Data Erased', 'ffcertificate' ),

			// Scheduling.
			'appointment_created'                          => __( 'Appointment Created', 'ffcertificate' ),
			'appointment_cancelled'                        => __( 'Appointment Cancelled', 'ffcertificate' ),
			'appointment_pii_revealed'                     => __( 'Appointment Personal Data Revealed', 'ffcertificate' ),
			'appointment_booked_with_override'             => __( 'Appointment Booked Outside the Rules', 'ffcertificate' ),
			'appointment_booked_on_behalf'                 => __( 'Appointment Booked on Behalf of a Person', 'ffcertificate' ),
			'appointment_bulk_purged'                      => __( 'Calendar Appointments Purged', 'ffcertificate' ),
			'appointment_bulk_cancelled'                   => __( 'Calendar Future Appointments Cancelled', 'ffcertificate' ),
			'appointment_bulk_closed'                      => __( 'Old Appointments Closed', 'ffcertificate' ),
			'audience_account_email_revealed'              => __( 'Audience Account Email Revealed', 'ffcertificate' ),
			'audience_booking_override'                    => __( 'Audience Booking Made Outside the Rules', 'ffcertificate' ),

			// Reregistration.
			'reregistration_submitted'                     => __( 'Reregistration Submitted', 'ffcertificate' ),
			'reregistration_invitations_sent'              => __( 'Reregistration Invitations Sent', 'ffcertificate' ),
			'reregistration_reminders_sent'                => __( 'Reregistration Reminders Sent', 'ffcertificate' ),
			'reregistration_pii_revealed'                  => __( 'Reregistration Personal Data Revealed', 'ffcertificate' ),
			'reregistration_details_viewed'                => __( 'Reregistration Details Viewed', 'ffcertificate' ),
			'reregistration_record_verified'               => __( 'Reregistration Record Verified', 'ffcertificate' ),
			'reregistration_record_downloaded'             => __( 'Reregistration Record Downloaded', 'ffcertificate' ),
			'reregistration_submission_rejected'           => __( 'Reregistration Submission Rejected', 'ffcertificate' ),
			'reregistration_submission_returned'           => __( 'Reregistration Submission Returned to Draft', 'ffcertificate' ),
			'reregistration_submission_retracted'          => __( 'Reregistration Submission Retracted', 'ffcertificate' ),
			'date_messages_pii_revealed'                   => __( 'Date Messages Personal Data Revealed', 'ffcertificate' ),
			'activity_log_ip_revealed'                     => __( 'Activity Log Address Revealed', 'ffcertificate' ),
			'rate_limit_ip_revealed'                       => __( 'Rate Limit Address Revealed', 'ffcertificate' ),
			'custom_field_sensitive_demotion_refused'      => __( 'Sensitive Field Demotion Refused', 'ffcertificate' ),
			'custom_field_locked_change_refused'           => __( 'Answered Field Change Refused', 'ffcertificate' ),

			// Recruitment.
			'recruitment_notice_status_changed'            => __( 'Recruitment Notice Status Changed', 'ffcertificate' ),
			'recruitment_notice_promoted'                  => __( 'Recruitment Notice Promoted', 'ffcertificate' ),
			'recruitment_call_created'                     => __( 'Recruitment Call Created', 'ffcertificate' ),
			'recruitment_bulk_call_created'                => __( 'Recruitment Bulk Call Created', 'ffcertificate' ),
			'recruitment_call_cancelled'                   => __( 'Recruitment Call Cancelled', 'ffcertificate' ),
			'recruitment_candidate_promoted'               => __( 'Candidate Promoted', 'ffcertificate' ),
			'recruitment_candidate_deleted'                => __( 'Candidate Deleted', 'ffcertificate' ),
			'recruitment_candidate_account_linked'         => __( 'Candidate Linked to an Account', 'ffcertificate' ),
			'recruitment_candidate_account_unlinked'       => __( 'Candidate Unlinked from an Account', 'ffcertificate' ),
			'recruitment_notice_deleted'                   => __( 'Notice Deleted', 'ffcertificate' ),
			'recruitment_candidate_fields_edited'          => __( 'Candidate Fields Edited', 'ffcertificate' ),
			'recruitment_pii_revealed'                     => __( 'Candidate Personal Data Revealed', 'ffcertificate' ),
			'recruitment_classification_status_changed'    => __( 'Classification Status Changed', 'ffcertificate' ),
			'recruitment_classification_adjutancy_changed' => __( 'Classification Adjutancy Changed', 'ffcertificate' ),

			// Identity resolution (#1368).
			'identity_rf_repaired'                         => __( 'Stored RF Corrected', 'ffcertificate' ),
			'identity_records_relinked'                    => __( 'Records Moved to Another Account', 'ffcertificate' ),
			'identity_records_split'                       => __( 'Records Given Their Own Account', 'ffcertificate' ),
			'identity_accounts_merged'                     => __( 'Two Accounts Merged Into One', 'ffcertificate' ),
			'identity_orphans_adopted'                     => __( 'Orphaned Records Given an Account', 'ffcertificate' ),
			// Not resolutions, and the labels say so rather than implying the
			// number was fixed (#1532).
			'identity_accepted_unresolvable'               => __( 'Finding Accepted as Unresolvable', 'ffcertificate' ),
			'identity_acceptance_withdrawn'                => __( 'Acceptance Withdrawn', 'ffcertificate' ),
			'recruitment_classification_override_to_empty' => __( 'Classification Cleared', 'ffcertificate' ),
			'recruitment_classification_deleted'           => __( 'Classification Deleted', 'ffcertificate' ),
			'recruitment_adjutancy_deleted'                => __( 'Adjutancy Deleted', 'ffcertificate' ),
			'recruitment_csv_imported'                     => __( 'Recruitment CSV Imported', 'ffcertificate' ),
			'recruitment_csv_import_failed'                => __( 'Recruitment CSV Import Failed', 'ffcertificate' ),

			// Certificates issued through the operator API.
			'operator_certificate_issued'                  => __( 'Operator Certificate Issued', 'ffcertificate' ),

			// Bulk issue finished: one line per import (#1945).
			'bulk_issue_finished'                          => __( 'Bulk Issue Finished', 'ffcertificate' ),

			// System & security.
			'decrypt_failure'                              => __( 'Decryption Failure', 'ffcertificate' ),
			'decrypt_failure_suppressed'                   => __( 'Decryption Failures Suppressed', 'ffcertificate' ),
			'ip_geolocation_debug'                         => __( 'IP Geolocation Debug', 'ffcertificate' ),
			'dangerzone_setting_changed'                   => __( 'Danger Zone Setting Changed', 'ffcertificate' ),
			'rate_limit_blocked'                           => __( 'Rate Limit Block', 'ffcertificate' ),
			'captcha_failed'                               => __( 'Captcha Failed', 'ffcertificate' ),
			'attendance_recorded'                          => __( 'Attendance Recorded', 'ffcertificate' ),
			'attendance_removed'                           => __( 'Attendance Removed', 'ffcertificate' ),
			'checkin_window_opened'                        => __( 'Check-in Opened', 'ffcertificate' ),
			'checkin_window_closed'                        => __( 'Check-in Closed', 'ffcertificate' ),
			'scheduled_task_failed'                        => __( 'Scheduled Task Failed', 'ffcertificate' ),

			// Migrations.
			'migration_foreign_keys'                       => __( 'Foreign Keys Migration', 'ffcertificate' ),
			'auth_code_duplicates_removed'                 => __( 'Duplicate Authentication Codes Removed', 'ffcertificate' ),
			'legacy_columns_dropped'                       => __( 'Legacy Columns Dropped', 'ffcertificate' ),
			'cpf_rf_split_migration_batch'                 => __( 'CPF/RF Split Migration Batch', 'ffcertificate' ),
			'cpf_rf_split_unknown_length'                  => __( 'CPF/RF Split: Unknown Length', 'ffcertificate' ),
			'email_hash_rehash_migration_batch'            => __( 'Email Hash Rehash Batch', 'ffcertificate' ),
			'key_rotation_migration_batch'                 => __( 'Encryption Key Rotation Batch', 'ffcertificate' ),
			'activity_log_clear_plaintext_batch'           => __( 'Activity Log Plaintext Cleanup Batch', 'ffcertificate' ),
			'rewrite_html_image_refs_errors'               => __( 'Image Reference Rewrite Errors', 'ffcertificate' ),
		);

		// The fallback is deliberately kept for rows written by an older
		// version whose action key has since been removed, and for anything a
		// filter injects. It renders untranslated English, so a *current*
		// action reaching it is a bug — `ActivityLogActionLabelsTest` fails CI
		// when one does (#1024).
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
				array( __( 'Bypassed IP', 'ffcertificate' ), \FreeFormCertificate\Core\DocumentFormatter::mask_ip( (string) ( $context['bypassed_ip'] ?? '' ) ) ),
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

		// A form's row keeps `form_id`; any other names its type (#2040).
		$labels = array(
			'booking'      => __( 'Booking ID', 'ffcertificate' ),
			'csv_download' => __( 'CSV download page ID', 'ffcertificate' ),
			'receipt'      => __( 'Appointment ID', 'ffcertificate' ),
		);
		$type   = isset( $context['type'] ) ? (string) $context['type'] : 'form';
		$about  = isset( $labels[ $type ] )
			? array( $labels[ $type ], isset( $context['id'] ) ? (string) (int) $context['id'] : '' )
			: array( __( 'Form ID', 'ffcertificate' ), isset( $context['form_id'] ) ? (string) (int) $context['form_id'] : '' );

		$rows = array(
			array( __( 'Reason', 'ffcertificate' ), self::get_preflight_reason_label( $reason ) ),
			$about,
			array( __( 'Visitor (IP hash)', 'ffcertificate' ), self::shorten_hash( (string) ( $context['ip_hash'] ?? '' ) ) ),
		);

		return self::render_summary_rows( $rows );
	}

	/**
	 * Most values the generic summary shows; the rest stay in the details.
	 */
	public const SUMMARY_ITEMS = 6;

	/**
	 * Longest value the generic summary shows, in characters.
	 */
	public const SUMMARY_VALUE_CHARS = 80;

	/**
	 * Largest details block drawn on the screen, in bytes. The CSV export
	 * and the Reveal keep the whole value.
	 */
	public const DETAILS_MAX_BYTES = 8192;

	/**
	 * A readable summary of an entry's details, for an action with no
	 * summary of its own (#2340): its top-level values, each on its own
	 * line, in the order they were recorded. Nested values and the values
	 * past the first few are left to the details below.
	 *
	 * @param array<int|string, mixed> $context Details, already masked.
	 * @return string|null Escaped HTML, or null when nothing is shown.
	 */
	public static function render_context_summary( array $context ): ?string {
		$rows    = array();
		$skipped = 0;
		foreach ( $context as $key => $value ) {
			if ( is_bool( $value ) ) {
				$value = $value ? __( 'Yes', 'ffcertificate' ) : __( 'No', 'ffcertificate' );
			}
			if ( ! is_scalar( $value ) || '' === (string) $value || count( $rows ) >= self::SUMMARY_ITEMS ) {
				++$skipped;
				continue;
			}
			$text = (string) $value;
			if ( mb_strlen( $text ) > self::SUMMARY_VALUE_CHARS ) {
				$text = mb_substr( $text, 0, self::SUMMARY_VALUE_CHARS - 1 ) . '…';
			}
			$label  = is_string( $key ) ? ucfirst( str_replace( '_', ' ', $key ) ) : (string) $key;
			$rows[] = array( $label, $text );
		}
		if ( array() === $rows ) {
			return null;
		}
		$html = self::render_summary_rows( $rows );
		if ( $skipped > 0 ) {
			$html .= '<p class="description">' . esc_html(
				sprintf(
					/* translators: %s: number of further values */
					_n( '%s more value in the details.', '%s more values in the details.', $skipped, 'ffcertificate' ),
					number_format_i18n( $skipped )
				)
			) . '</p>';
		}
		return $html;
	}

	/**
	 * The details block, cut at a size the screen can draw (#2340).
	 *
	 * @param string $json Encoded details.
	 * @return string
	 */
	public static function bounded_json( string $json ): string {
		if ( strlen( $json ) <= self::DETAILS_MAX_BYTES ) {
			return $json;
		}
		return mb_strcut( $json, 0, self::DETAILS_MAX_BYTES ) . "\n… " . __( 'Cut here; the CSV export has the full details.', 'ffcertificate' );
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

		// The account that made the entry, or the system (#2340).
		$user = isset( $params['user'] ) ? sanitize_key( (string) $params['user'] ) : '';
		if ( \FreeFormCertificate\Core\ActivityLogQuery::USER_SYSTEM === $user ) {
			$args['user_id'] = $user;
		} elseif ( ctype_digit( $user ) && (int) $user > 0 ) {
			$args['user_id'] = $user;
		}

		// A date range in the site's timezone; the end date takes in the
		// whole of that day (#2340).
		foreach ( array( 'date_from', 'date_to' ) as $bound ) {
			$date = isset( $params[ $bound ] ) ? sanitize_text_field( (string) $params[ $bound ] ) : '';
			if ( '' !== $date && \FreeFormCertificate\Core\DateFormatter::is_valid_date( $date ) ) {
				$args[ $bound ] = $date;
			}
		}

		return $args;
	}

	/**
	 * Whether a query narrows the log, so an empty answer means "no
	 * matches" rather than "nothing recorded yet" (#2340).
	 *
	 * @param array<string, mixed> $args Query arguments from build_query_args().
	 * @return bool
	 */
	public static function has_filters( array $args ): bool {
		foreach ( array( 'level', 'action', 'search', 'user_id', 'date_from', 'date_to' ) as $key ) {
			if ( '' !== ArrayValue::string( $args, $key ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The action filter's values, sorted by the label they are shown with.
	 *
	 * @param array<int, string> $actions Stored action names.
	 * @return array<int, string>
	 */
	public static function sorted_actions( array $actions ): array {
		return array_values( \FreeFormCertificate\Core\LabelSorter::sort( $actions, static fn( string $action ): string => self::get_action_label( $action ) ) );
	}

	/**
	 * The user filter's options: the system first, then every account that
	 * appears in the log, sorted by its label (#2340).
	 *
	 * @return array<int|string, string> Value => label; an account id key is an int, as PHP stores it.
	 */
	public static function user_options(): array {
		$users = array();
		foreach ( \FreeFormCertificate\Core\ActivityLogQuery::distinct_users() as $user_id ) {
			$user = get_userdata( $user_id );
			/* translators: %d: user ID */
			$users[ (string) $user_id ] = $user ? sprintf( '%1$s (%2$s)', $user->display_name, $user->user_login ) : sprintf( __( 'User #%d (deleted)', 'ffcertificate' ), $user_id );
		}
		$users = \FreeFormCertificate\Core\LabelSorter::sort( $users, static fn( string $label ): string => $label );

		return array( \FreeFormCertificate\Core\ActivityLogQuery::USER_SYSTEM => __( 'System / Anonymous', 'ffcertificate' ) ) + $users;
	}

	/**
	 * The table's empty state: no entry matches the filters, or nothing has
	 * been recorded yet (#2340). An in-content callout, not a core notice,
	 * which common.js would lift out of the table container (#1843).
	 *
	 * @param bool $has_filters Whether the query was filtered.
	 * @return string Escaped HTML.
	 */
	public static function render_empty_html( bool $has_filters ): string {
		if ( ! $has_filters ) {
			return \FreeFormCertificate\Core\AdminNotice::callout_markup(
				'info',
				esc_html__( 'Nothing has been recorded yet. Entries appear here as soon as an audited action happens.', 'ffcertificate' )
			);
		}
		return \FreeFormCertificate\Core\AdminNotice::callout_markup(
			'info',
			esc_html__( 'No entries match these filters.', 'ffcertificate' ) . ' ' . \FreeFormCertificate\Core\Button::html(
				array(
					'label' => __( 'Clear Filters', 'ffcertificate' ),
					'icon'  => 'eraser',
					'href'  => admin_url( 'admin.php?page=ffc-settings&tab=activity_log' ),
					'class' => 'ffc-log-clear',
				)
			)
		);
	}

	/**
	 * How many entries the filters match, for the line above the table.
	 *
	 * @param int $total Entries matched.
	 * @return string Plain text.
	 */
	public static function count_text( int $total ): string {
		/* translators: %s: number of entries */
		return sprintf( _n( '%s entry', '%s entries', $total, 'ffcertificate' ), number_format_i18n( $total ) );
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
		// The address is masked for everyone; a holder of the reveal cap gets
		// a Reveal beside it, audited like every other one (#2109).
		$ffcertificate_reveal_nonce = \FreeFormCertificate\Core\PiiAccessPolicy::can_reveal_any( self::PII_CAP )
			? wp_create_nonce( 'ffc_reveal_pii_nonce' )
			: '';
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
					<?php
					$ffcertificate_log_id = is_numeric( $ffcertificate_log['id'] ?? null ) ? (int) $ffcertificate_log['id'] : 0;
					$ffcertificate_ip     = (string) ( $ffcertificate_log['user_ip'] ?? '' );
					if ( '' !== $ffcertificate_ip ) {
						echo '<code class="ffc-pii-value" data-field="ip">' . esc_html( \FreeFormCertificate\Core\DocumentFormatter::mask_ip( $ffcertificate_ip ) ) . '</code>';
						if ( '' !== $ffcertificate_reveal_nonce ) {
							$ffcertificate_reveal_button = \FreeFormCertificate\Core\PiiReveal::field_button(
								array(
									'field'         => 'ip',
									'type'          => \FreeFormCertificate\Admin\AdminAjax::REVEAL_ACTIVITY_LOG,
									'submission-id' => $ffcertificate_log_id,
									'nonce'         => $ffcertificate_reveal_nonce,
								)
							);
							echo ' ' . $ffcertificate_reveal_button; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- field_button() escapes every attribute.
						}
					}
					?>
				</td>
				<td>
					<?php
					if ( ! empty( $ffcertificate_log['context'] ) ) :
						// wp_json_encode returns string|false. The false case (circular
						// refs, malformed UTF-8) is treated as "no context" so the
						// admin still sees a valid <details> block.
						$ffcertificate_context_json = wp_json_encode( self::mask_personal_data( $ffcertificate_log['context'] ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
						if ( false === $ffcertificate_context_json ) {
							$ffcertificate_context_json = '';
						}
						// Something was masked: the clear details sit behind the
						// same audited Reveal as the address (#2340).
						$ffcertificate_context_clear  = (string) wp_json_encode( $ffcertificate_log['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
						$ffcertificate_context_masked = $ffcertificate_context_clear !== $ffcertificate_context_json;
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
						// Every other action: its top-level values, masked, in
						// a few readable lines (#2340).
						if ( null === $ffcertificate_summary ) {
							$ffcertificate_masked_arr = self::mask_personal_data( $ffcertificate_context_arr );
							$ffcertificate_summary    = self::render_context_summary( is_array( $ffcertificate_masked_arr ) ? $ffcertificate_masked_arr : array() );
						}
						$ffcertificate_context_json = self::bounded_json( $ffcertificate_context_json );
						?>
						<?php if ( null !== $ffcertificate_summary ) : ?>
							<?php echo wp_kses_post( $ffcertificate_summary ); ?>
						<?php endif; ?>
						<details>
							<summary class="ffc-log-summary">
								<?php esc_html_e( 'View Details', 'ffcertificate' ); ?> ▼
							</summary>
							<pre class="ffc-log-pre ffc-pii-value" data-field="context"><?php echo esc_html( $ffcertificate_context_json ); ?></pre>
							<?php
							if ( $ffcertificate_context_masked && '' !== $ffcertificate_reveal_nonce ) {
								$ffcertificate_context_button = \FreeFormCertificate\Core\PiiReveal::field_button(
									array(
										'field'         => 'context',
										'type'          => \FreeFormCertificate\Admin\AdminAjax::REVEAL_ACTIVITY_LOG,
										'submission-id' => $ffcertificate_log_id,
										'nonce'         => $ffcertificate_reveal_nonce,
									)
								);
								echo $ffcertificate_context_button; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- field_button() escapes every attribute.
							}
							?>
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
	 * Every address in an entry's details masked, whatever key holds it
	 * (#2109). Details carry the address under several keys (`ip`,
	 * `triggered_by_ip`, `bypassed_ip`, an older geofence `identifier`), so
	 * the test is the value: a string that is an IP address is masked.
	 *
	 * @param mixed $context Decoded details, or a scalar inside them.
	 * @return mixed
	 */
	public static function mask_addresses( $context ) {
		return self::mask_personal_data( $context );
	}

	/**
	 * Every piece of personal data in an entry's details masked (#2109,
	 * #2340): an address wherever it sits, an e-mail address wherever it
	 * sits, and a value under a key the plugin knows as personal (`cpf`,
	 * `rf`, `email`, `phone`…, {@see DocumentFormatter::kind_for_key()}).
	 * The clear details stay behind the entry's audited Reveal.
	 *
	 * @param mixed  $context Decoded details, or a value inside them.
	 * @param string $key     Key the value sits under; '' at the top.
	 * @return mixed
	 */
	public static function mask_personal_data( $context, string $key = '' ) {
		if ( is_array( $context ) ) {
			$out = array();
			foreach ( $context as $k => $v ) {
				$out[ $k ] = self::mask_personal_data( $v, is_string( $k ) ? $k : $key );
			}
			return $out;
		}
		if ( ! is_string( $context ) || '' === $context ) {
			return $context;
		}
		if ( null !== \FreeFormCertificate\Core\IpAddress::canonical( $context ) ) {
			return \FreeFormCertificate\Core\DocumentFormatter::mask_ip( $context );
		}
		if ( false !== filter_var( $context, FILTER_VALIDATE_EMAIL ) ) {
			return \FreeFormCertificate\Core\DocumentFormatter::mask_email( $context );
		}
		$kind = '' !== $key ? \FreeFormCertificate\Core\DocumentFormatter::kind_for_key( strtolower( $key ) ) : null;
		if ( null !== $kind ) {
			return \FreeFormCertificate\Core\DocumentFormatter::mask( $kind, $context );
		}
		return $context;
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

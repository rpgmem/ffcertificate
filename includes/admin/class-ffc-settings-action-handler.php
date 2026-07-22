<?php
/**
 * Settings Action Handler
 *
 * Handles the settings action / maintenance request handlers that were
 * previously inlined on {@see \FreeFormCertificate\Admin\Settings}. Extracted
 * (#591 phase-3, Sprint F3) so Settings stays a thin page/tab coordinator while
 * this collaborator owns the admin_init / admin_post / wp_ajax request handling.
 *
 * Responsibilities:
 * - Delegate the settings form submission to the Save Handler.
 * - Clear the QR Code cache.
 * - Run data migrations from the settings page.
 * - Drive the maintenance tools (obsolete shortcode cleanup, short-URL cleanup,
 *   public access disabler, submission link audit).
 * - Serve the AJAX date-format preview.
 * - Handle cache warm / clear actions.
 *
 * Behavior is preserved verbatim from Settings: same nonce checks, capability
 * checks, redirects, option writes and phpcs:ignore annotations.
 *
 * @package FreeFormCertificate\Admin
 * @since   6.7.x
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings action / maintenance request handlers.
 *
 * @since 6.7.x
 */
class SettingsActionHandler {

	use \FreeFormCertificate\Core\EmailHelperTrait;

	/**
	 * Save handler.
	 *
	 * @var \FreeFormCertificate\Admin\SettingsSaveHandler
	 */
	private $save_handler;

	/**
	 * Constructor.
	 *
	 * @param \FreeFormCertificate\Admin\SettingsSaveHandler $save_handler Save handler.
	 */
	public function __construct( \FreeFormCertificate\Admin\SettingsSaveHandler $save_handler ) {
		$this->save_handler = $save_handler;
	}

	/**
	 * Handle settings form submission
	 */
	public function handle_settings_submission(): void {
		$this->save_handler->handle_all_submissions();
	}

	/**
	 * Handle QR Code cache clearing
	 */
	public function handle_clear_qr_cache(): void {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Nonce verified below via wp_verify_nonce.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- isset() existence checks only.
		if ( ! isset( $_GET['ffc_clear_qr_cache'] ) || ! isset( $_GET['_wpnonce'] ) ) {
			return;
		}
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! wp_verify_nonce( \FreeFormCertificate\Core\RequestInput::get_get_string( '_wpnonce' ), 'ffc_clear_qr_cache' ) ) {
			return;
		}

		$cleared = ( new \FreeFormCertificate\Repositories\SubmissionRepository() )->clearQrCodeCache();

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type' => 'ffc_form',
					'page'      => 'ffc-settings',
					'tab'       => 'cache',
					'msg'       => 'qr_cache_cleared',
					'cleared'   => $cleared,
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Handle migration execution from settings page
	 */
	public function handle_migration_execution(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below after extracting migration key.
		if ( ! isset( $_GET['ffc_run_migration'] ) ) {
			return;
		}

		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_settings_dangerzone' ) ) {
			wp_die( esc_html__( 'You do not have permission to run migrations.', 'ffcertificate' ) );
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified immediately below.
		$migration_key = sanitize_key( wp_unslash( $_GET['ffc_run_migration'] ) );

		// Verify nonce.
		if ( ! wp_verify_nonce( \FreeFormCertificate\Core\RequestInput::get_get_string( '_wpnonce' ), 'ffc_migration_' . $migration_key ) ) {
			wp_die( esc_html__( 'Security check failed.', 'ffcertificate' ) );
		}

		// Autoloader handles class loading.
		$migration_manager = new \FreeFormCertificate\Migrations\MigrationManager();

		// Run migration.
		$result = $migration_manager->run_migration( $migration_key );

		// Prepare redirect URL.
		$redirect_url = add_query_arg(
			array(
				'post_type' => 'ffc_form',
				'page'      => 'ffc-settings',
				'tab'       => 'migrations',
			),
			admin_url( 'edit.php' )
		);

		// Add result message.
		if ( is_wp_error( $result ) ) {
			$redirect_url = add_query_arg( 'migration_error', rawurlencode( $result->get_error_message() ), $redirect_url );
		} else {
			$message = sprintf(
				/* translators: %d: number of records processed */
				__( 'Migration executed: %d records processed.', 'ffcertificate' ),
				isset( $result['processed'] ) ? $result['processed'] : 0
			);
			$redirect_url = add_query_arg( 'migration_success', rawurlencode( $message ), $redirect_url );
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Handle obsolete shortcode cleanup actions (preview / apply / save_days).
	 *
	 * Wired into `admin_init`. Reacts to `ffc_obsolete_cleanup=<mode>` coming
	 * either from GET (preview/apply links) or POST (save_days form submission).
	 * Each mode has its own nonce key (`ffc_obsolete_cleanup_<mode>`) and all
	 * modes require `manage_options`.
	 *
	 * Flow:
	 *  - `save_days`  → persist the grace window in `ffc_settings`.
	 *  - `preview`    → run `ObsoleteShortcodeCleaner::run()` in dry-run,
	 *                   store the report + a "preview OK" flag in transients
	 *                   so the UI can unlock the apply button.
	 *  - `apply`      → refuse unless a recent preview exists, then run the
	 *                   destructive pass and store the report.
	 *
	 * @since 5.1.0
	 */
	public function handle_obsolete_shortcode_cleanup(): void {
		// Accept the trigger from GET (preview/apply links) OR POST (save_days form).
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below.
		if ( ! isset( $_REQUEST['ffc_obsolete_cleanup'] ) ) {
			return;
		}

		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_settings_dangerzone' ) ) {
			wp_die( esc_html__( 'You do not have permission to run this action.', 'ffcertificate' ) );
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified immediately below.
		$mode          = sanitize_key( wp_unslash( $_REQUEST['ffc_obsolete_cleanup'] ) );
		$allowed_modes = array( 'preview', 'apply', 'save_days' );
		if ( ! in_array( $mode, $allowed_modes, true ) ) {
			wp_die( esc_html__( 'Invalid action.', 'ffcertificate' ) );
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified here.
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'ffc_obsolete_cleanup_' . $mode ) ) {
			wp_die( esc_html__( 'Security check failed.', 'ffcertificate' ) );
		}

		$user_id        = get_current_user_id();
		$report_key     = 'ffc_obsolete_cleanup_report_' . $user_id;
		$preview_ok_key = 'ffc_obsolete_cleanup_preview_ok_' . $user_id;

		$redirect_url = add_query_arg(
			array(
				'post_type' => 'ffc_form',
				'page'      => 'ffc-settings',
				'tab'       => 'migrations',
			),
			admin_url( 'edit.php' )
		);

		$settings         = get_option( 'ffc_settings', array() );
		$current_days_raw = is_array( $settings ) && isset( $settings['obsolete_shortcode_days'] )
			? (int) $settings['obsolete_shortcode_days']
			: 90;
		$current_days     = $current_days_raw > 0 ? $current_days_raw : 90;

		switch ( $mode ) {
			case 'save_days':
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
				$posted_days = isset( $_POST['obsolete_shortcode_days'] )
					? absint( wp_unslash( $_POST['obsolete_shortcode_days'] ) )
					: 0;
				if ( $posted_days < 1 ) {
					$posted_days = 1;
				} elseif ( $posted_days > 3650 ) {
					$posted_days = 3650;
				}

				if ( ! is_array( $settings ) ) {
					$settings = array();
				}
				$settings['obsolete_shortcode_days'] = $posted_days;
				update_option( 'ffc_settings', $settings );

				$redirect_url = add_query_arg(
					'obsolete_cleanup_msg',
					rawurlencode(
						sprintf(
						/* translators: %d: grace window in days */
							__( 'Grace window updated to %d days.', 'ffcertificate' ),
							$posted_days
						)
					),
					$redirect_url
				);
				break;

			case 'preview':
				$tool = \FreeFormCertificate\Maintenance\MaintenanceToolRegistry::create_default()->get( 'obsolete_shortcode' );
				if ( ! $tool instanceof \FreeFormCertificate\Maintenance\MaintenanceToolInterface ) {
					$redirect_url = add_query_arg( 'obsolete_cleanup_error', rawurlencode( __( 'Maintenance tool not available.', 'ffcertificate' ) ), $redirect_url );
					break;
				}
				try {
					$report = $tool->run(
						array(
							'days'    => $current_days,
							'dry_run' => true,
						)
					);
					set_transient( $report_key, $report, 5 * MINUTE_IN_SECONDS );
					set_transient( $preview_ok_key, 1, 5 * MINUTE_IN_SECONDS );
					$redirect_url = add_query_arg( 'obsolete_cleanup_msg', rawurlencode( __( 'Preview generated.', 'ffcertificate' ) ), $redirect_url );
				} catch ( \Throwable $e ) {
					$redirect_url = add_query_arg( 'obsolete_cleanup_error', rawurlencode( $e->getMessage() ), $redirect_url );
				}
				break;

			case 'apply':
				if ( ! get_transient( $preview_ok_key ) ) {
					$redirect_url = add_query_arg(
						'obsolete_cleanup_error',
						rawurlencode( __( 'Please run a preview first before removing shortcodes.', 'ffcertificate' ) ),
						$redirect_url
					);
					break;
				}
				$tool = \FreeFormCertificate\Maintenance\MaintenanceToolRegistry::create_default()->get( 'obsolete_shortcode' );
				if ( ! $tool instanceof \FreeFormCertificate\Maintenance\MaintenanceToolInterface ) {
					$redirect_url = add_query_arg( 'obsolete_cleanup_error', rawurlencode( __( 'Maintenance tool not available.', 'ffcertificate' ) ), $redirect_url );
					break;
				}
				try {
					$report = $tool->run(
						array(
							'days'    => $current_days,
							'dry_run' => false,
						)
					);
					set_transient( $report_key, $report, 5 * MINUTE_IN_SECONDS );
					delete_transient( $preview_ok_key );
					$redirect_url = add_query_arg(
						'obsolete_cleanup_msg',
						rawurlencode(
							sprintf(
							/* translators: 1: shortcodes removed, 2: posts affected */
								__( 'Cleanup complete. Removed %1$d shortcode(s) from %2$d post(s).', 'ffcertificate' ),
								(int) $report['shortcodes_removed'],
								(int) $report['posts_affected']
							)
						),
						$redirect_url
					);
				} catch ( \Throwable $e ) {
					$redirect_url = add_query_arg( 'obsolete_cleanup_error', rawurlencode( $e->getMessage() ), $redirect_url );
				}
				break;
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Handle the Short URL Cleanup maintenance action (Settings → Data Migrations).
	 *
	 * Two modes, each with its own nonce key (`ffc_url_cleanup_<mode>`), all
	 * requiring `ffc_manage_settings`:
	 *  - `preview` (POST): persist the chosen criteria + grace window into
	 *    `ffc_settings`, then run the {@see UrlShortenerCleaner} in dry-run and
	 *    store the report + a "preview OK" flag so the apply button unlocks.
	 *  - `apply`   (GET) : refuse unless a recent preview exists, then run the
	 *    destructive pass using the persisted options.
	 *
	 * @since 6.7.x
	 */
	public function handle_url_shortener_cleanup(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below.
		if ( ! isset( $_REQUEST['ffc_url_cleanup'] ) ) {
			return;
		}

		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_settings_dangerzone' ) ) {
			wp_die( esc_html__( 'You do not have permission to run this action.', 'ffcertificate' ) );
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified immediately below.
		$mode = sanitize_key( wp_unslash( $_REQUEST['ffc_url_cleanup'] ) );
		if ( ! in_array( $mode, array( 'preview', 'apply' ), true ) ) {
			wp_die( esc_html__( 'Invalid action.', 'ffcertificate' ) );
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified here.
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'ffc_url_cleanup_' . $mode ) ) {
			wp_die( esc_html__( 'Security check failed.', 'ffcertificate' ) );
		}

		$user_id        = get_current_user_id();
		$report_key     = 'ffc_url_cleanup_report_' . $user_id;
		$preview_ok_key = 'ffc_url_cleanup_preview_ok_' . $user_id;

		$redirect_url = add_query_arg(
			array(
				'post_type' => 'ffc_form',
				'page'      => 'ffc-settings',
				'tab'       => 'migrations',
			),
			admin_url( 'edit.php' )
		);

		$settings = get_option( 'ffc_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$tool = \FreeFormCertificate\Maintenance\MaintenanceToolRegistry::create_default()->get( 'url_shortener_cleanup' );
		if ( ! $tool instanceof \FreeFormCertificate\Maintenance\MaintenanceToolInterface ) {
			$redirect_url = add_query_arg( 'url_cleanup_error', rawurlencode( __( 'Maintenance tool not available.', 'ffcertificate' ) ), $redirect_url );
			wp_safe_redirect( $redirect_url );
			exit;
		}

		if ( 'preview' === $mode ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
			$posted_days = isset( $_POST['url_cleanup_days'] ) ? absint( wp_unslash( $_POST['url_cleanup_days'] ) ) : 90;
			$days        = min( 3650, max( 1, $posted_days ) );
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
			$orphaned = empty( $_POST['url_cleanup_orphaned'] ) ? 0 : 1;
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
			$never = empty( $_POST['url_cleanup_never_clicked'] ) ? 0 : 1;
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
			$trashed = empty( $_POST['url_cleanup_trashed'] ) ? 0 : 1;

			$settings['url_cleanup_days']          = $days;
			$settings['url_cleanup_orphaned']      = $orphaned;
			$settings['url_cleanup_never_clicked'] = $never;
			$settings['url_cleanup_trashed']       = $trashed;
			update_option( 'ffc_settings', $settings );

			try {
				$report = $tool->run(
					array(
						'criteria' => array(
							'orphaned'      => (bool) $orphaned,
							'never_clicked' => (bool) $never,
							'trashed'       => (bool) $trashed,
						),
						'days'     => $days,
						'dry_run'  => true,
					)
				);
				set_transient( $report_key, $report, 5 * MINUTE_IN_SECONDS );
				set_transient( $preview_ok_key, 1, 5 * MINUTE_IN_SECONDS );
				$redirect_url = add_query_arg( 'url_cleanup_msg', rawurlencode( __( 'Preview generated.', 'ffcertificate' ) ), $redirect_url );
			} catch ( \Throwable $e ) {
				$redirect_url = add_query_arg( 'url_cleanup_error', rawurlencode( $e->getMessage() ), $redirect_url );
			}
		} elseif ( ! get_transient( $preview_ok_key ) ) {
			$redirect_url = add_query_arg( 'url_cleanup_error', rawurlencode( __( 'Please run a preview first before deleting short URLs.', 'ffcertificate' ) ), $redirect_url );
		} else {
			$days     = isset( $settings['url_cleanup_days'] ) ? (int) $settings['url_cleanup_days'] : 90;
			$criteria = array(
				'orphaned'      => ! empty( $settings['url_cleanup_orphaned'] ),
				'never_clicked' => ! empty( $settings['url_cleanup_never_clicked'] ),
				'trashed'       => ! empty( $settings['url_cleanup_trashed'] ),
			);
			try {
				$report = $tool->run(
					array(
						'criteria' => $criteria,
						'days'     => $days,
						'dry_run'  => false,
					)
				);
				set_transient( $report_key, $report, 5 * MINUTE_IN_SECONDS );
				delete_transient( $preview_ok_key );
				$redirect_url = add_query_arg(
					'url_cleanup_msg',
					rawurlencode(
						sprintf(
						/* translators: %d: short URLs deleted */
							__( 'Cleanup complete. Deleted %d short URL(s).', 'ffcertificate' ),
							(int) $report['deleted']
						)
					),
					$redirect_url
				);
			} catch ( \Throwable $e ) {
				$redirect_url = add_query_arg( 'url_cleanup_error', rawurlencode( $e->getMessage() ), $redirect_url );
			}
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Handle the "Disable Public Operator Access on old forms" maintenance
	 * action (Settings → Data Migrations).
	 *
	 * Two modes, each with its own nonce key (`ffc_pubaccess_<mode>`), all
	 * requiring `ffc_manage_settings`:
	 *  - `preview` (POST): persist the grace window into `ffc_settings`, then
	 *    run the {@see PublicOperatorAccessDisabler} in dry-run and store the
	 *    report + a "preview OK" flag so the apply button unlocks.
	 *  - `apply`   (GET) : refuse unless a recent preview exists, then run the
	 *    destructive pass using the persisted grace window.
	 *
	 * @since 6.7.x
	 */
	public function handle_public_access_disabler(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below.
		if ( ! isset( $_REQUEST['ffc_pubaccess'] ) ) {
			return;
		}

		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_settings_dangerzone' ) ) {
			wp_die( esc_html__( 'You do not have permission to run this action.', 'ffcertificate' ) );
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified immediately below.
		$mode = sanitize_key( wp_unslash( $_REQUEST['ffc_pubaccess'] ) );
		if ( ! in_array( $mode, array( 'preview', 'apply' ), true ) ) {
			wp_die( esc_html__( 'Invalid action.', 'ffcertificate' ) );
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified here.
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'ffc_pubaccess_' . $mode ) ) {
			wp_die( esc_html__( 'Security check failed.', 'ffcertificate' ) );
		}

		$user_id        = get_current_user_id();
		$report_key     = 'ffc_pubaccess_report_' . $user_id;
		$preview_ok_key = 'ffc_pubaccess_preview_ok_' . $user_id;

		$redirect_url = add_query_arg(
			array(
				'post_type' => 'ffc_form',
				'page'      => 'ffc-settings',
				'tab'       => 'migrations',
			),
			admin_url( 'edit.php' )
		);

		$settings = get_option( 'ffc_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$tool = \FreeFormCertificate\Maintenance\MaintenanceToolRegistry::create_default()->get( 'public_access_disabler' );
		if ( ! $tool instanceof \FreeFormCertificate\Maintenance\MaintenanceToolInterface ) {
			$redirect_url = add_query_arg( 'pubaccess_error', rawurlencode( __( 'Maintenance tool not available.', 'ffcertificate' ) ), $redirect_url );
			wp_safe_redirect( $redirect_url );
			exit;
		}

		if ( 'preview' === $mode ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
			$posted_days                            = isset( $_POST['public_access_disable_days'] ) ? absint( wp_unslash( $_POST['public_access_disable_days'] ) ) : 90;
			$days                                   = min( 3650, max( 1, $posted_days ) );
			$settings['public_access_disable_days'] = $days;
			update_option( 'ffc_settings', $settings );

			try {
				$report = $tool->run(
					array(
						'days'    => $days,
						'dry_run' => true,
					)
				);
				set_transient( $report_key, $report, 5 * MINUTE_IN_SECONDS );
				set_transient( $preview_ok_key, 1, 5 * MINUTE_IN_SECONDS );
				$redirect_url = add_query_arg( 'pubaccess_msg', rawurlencode( __( 'Preview generated.', 'ffcertificate' ) ), $redirect_url );
			} catch ( \Throwable $e ) {
				$redirect_url = add_query_arg( 'pubaccess_error', rawurlencode( $e->getMessage() ), $redirect_url );
			}
		} elseif ( ! get_transient( $preview_ok_key ) ) {
			$redirect_url = add_query_arg( 'pubaccess_error', rawurlencode( __( 'Please run a preview first before disabling access.', 'ffcertificate' ) ), $redirect_url );
		} else {
			$days = isset( $settings['public_access_disable_days'] ) ? (int) $settings['public_access_disable_days'] : 90;
			try {
				$report = $tool->run(
					array(
						'days'    => $days,
						'dry_run' => false,
					)
				);
				set_transient( $report_key, $report, 5 * MINUTE_IN_SECONDS );
				delete_transient( $preview_ok_key );
				$redirect_url = add_query_arg(
					'pubaccess_msg',
					rawurlencode(
						sprintf(
						/* translators: %d: forms disabled */
							__( 'Done. Disabled Public Operator Access on %d form(s).', 'ffcertificate' ),
							(int) $report['disabled']
						)
					),
					$redirect_url
				);
			} catch ( \Throwable $e ) {
				$redirect_url = add_query_arg( 'pubaccess_error', rawurlencode( $e->getMessage() ), $redirect_url );
			}
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Handle the Submission ↔ user link audit (Settings → Data Migrations).
	 *
	 * Report-only: a single `scan` mode (nonce `ffc_submission_audit_scan`,
	 * `ffc_manage_settings`) runs the read-only {@see SubmissionLinkAuditor}
	 * and stores the report in a transient. Nothing is mutated.
	 *
	 * @since 6.7.x
	 */
	public function handle_submission_link_audit(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below.
		if ( ! isset( $_REQUEST['ffc_submission_audit'] ) ) {
			return;
		}

		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_settings_dangerzone' ) ) {
			wp_die( esc_html__( 'You do not have permission to run this action.', 'ffcertificate' ) );
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified immediately below.
		$mode = sanitize_key( wp_unslash( $_REQUEST['ffc_submission_audit'] ) );
		if ( 'scan' !== $mode ) {
			wp_die( esc_html__( 'Invalid action.', 'ffcertificate' ) );
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified here.
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'ffc_submission_audit_scan' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'ffcertificate' ) );
		}

		$report_key   = 'ffc_submission_audit_report_' . get_current_user_id();
		$redirect_url = add_query_arg(
			array(
				'post_type' => 'ffc_form',
				'page'      => 'ffc-settings',
				'tab'       => 'migrations',
			),
			admin_url( 'edit.php' )
		);

		$tool = \FreeFormCertificate\Maintenance\MaintenanceToolRegistry::create_default()->get( 'submission_link_audit' );
		if ( ! $tool instanceof \FreeFormCertificate\Maintenance\MaintenanceToolInterface ) {
			$redirect_url = add_query_arg( 'submission_audit_error', rawurlencode( __( 'Maintenance tool not available.', 'ffcertificate' ) ), $redirect_url );
			wp_safe_redirect( $redirect_url );
			exit;
		}

		try {
			$report = $tool->run( array() );
			set_transient( $report_key, $report, 5 * MINUTE_IN_SECONDS );
			$redirect_url = add_query_arg( 'submission_audit_msg', rawurlencode( __( 'Audit complete.', 'ffcertificate' ) ), $redirect_url );
		} catch ( \Throwable $e ) {
			$redirect_url = add_query_arg( 'submission_audit_error', rawurlencode( $e->getMessage() ), $redirect_url );
		}

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * AJAX handler for date format preview
	 *
	 * @since 2.10.0
	 */
	public function ajax_preview_date_format(): void {
		check_ajax_referer( 'ffc_preview_date', 'nonce' );

		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ffcertificate' ) ) );
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer.
		$format = \FreeFormCertificate\Core\RequestInput::get_post_string( 'format', 'F j, Y' );
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above via check_ajax_referer.
		$custom_format = \FreeFormCertificate\Core\RequestInput::get_post_string( 'custom_format' );

		// Sample date for preview.
		$sample_date = '2026-01-04 15:30:45';

		// Use custom format if selected.
		if ( 'custom' === $format && ! empty( $custom_format ) ) {
			$format = $custom_format;
		}

		try {
			$formatted = date_i18n( $format, strtotime( $sample_date ) );
			wp_send_json_success( array( 'formatted' => $formatted ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => __( 'Invalid date format', 'ffcertificate' ) ) );
		}
	}

	/**
	 * Handle cache actions.
	 */
	public function handle_cache_actions(): void {
		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_settings' ) ) {
			return;
		}

		// Warm Cache.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below via check_admin_referer.
		if ( isset( $_GET['action'] ) && sanitize_key( wp_unslash( $_GET['action'] ) ) === 'warm_cache' ) {
			check_admin_referer( 'ffc_warm_cache' );

			// Autoloader handles class loading.
			$warmed = \FreeFormCertificate\Submissions\FormCache::warm_all_forms();

			wp_safe_redirect(
				add_query_arg(
					array(
						'post_type' => 'ffc_form',
						'page'      => 'ffc-settings',
						'tab'       => 'cache',
						'msg'       => 'cache_warmed',
						'count'     => $warmed,
					),
					admin_url( 'edit.php' )
				)
			);
			exit;
		}

		// Clear Cache.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below via check_admin_referer.
		if ( isset( $_GET['action'] ) && sanitize_key( wp_unslash( $_GET['action'] ) ) === 'clear_cache' ) {
			check_admin_referer( 'ffc_clear_cache' );

			// Autoloader handles class loading.
			\FreeFormCertificate\Submissions\FormCache::clear_all_cache();
			\FreeFormCertificate\Submissions\FormCache::purge_external_caches_for_all_forms( 'manual_clear_all' );

			wp_safe_redirect(
				add_query_arg(
					array(
						'post_type' => 'ffc_form',
						'page'      => 'ffc-settings',
						'tab'       => 'cache',
						'msg'       => 'cache_cleared',
					),
					admin_url( 'edit.php' )
				)
			);
			exit;
		}
	}

	/**
	 * Handle the "Send a test email" action (Settings → SMTP → Email Model).
	 *
	 * Sends one test message through the shared email pipeline so an operator
	 * can confirm SMTP delivery and the Email Model chrome are working. The
	 * recipient is ALWAYS the current user's own account email — never taken
	 * from the request — so there is no way to make it mail an arbitrary
	 * address. Requires the SMTP sub-cap `ffc_manage_settings_smtp` (or admin)
	 * plus a valid nonce (#739 §4.4) — it exercises the SMTP transport, so it
	 * belongs to the same sub-cap that gates saving that transport.
	 *
	 * Redirects back to the SMTP tab with `ffc_test_email=<flag>` where flag is
	 * `sent` / `disabled` (global kill-switch on) / `no_address` (account has no
	 * email) / `failed` (transport returned false), rendered as a notice by the
	 * SMTP tab view.
	 *
	 * @since 6.15.x
	 */
	public function handle_send_test_email(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Presence check only; nonce verified below via check_admin_referer.
		if ( ! isset( $_POST['ffc_send_test_email'] ) ) {
			return;
		}

		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_settings_smtp' ) ) {
			wp_die( esc_html__( 'You do not have permission to send a test email.', 'ffcertificate' ) );
		}

		check_admin_referer( 'ffc_send_test_email' );

		$to = (string) wp_get_current_user()->user_email;

		if ( '' === $to || ! is_email( $to ) ) {
			$flag = 'no_address';
		} elseif ( \FreeFormCertificate\Settings\SettingsReader::emails_disabled() ) {
			$flag = 'disabled';
		} else {
			$body = self::ffc_email_document(
				'<p>' . esc_html__( 'This is a test email from Free Form Certificate. If you received it, your email delivery and Email Model settings are working.', 'ffcertificate' ) . '</p>',
				array( 'recipient' => $to )
			);
			$sent = self::ffc_send_mail( $to, __( 'Free Form Certificate — test email', 'ffcertificate' ), $body );
			$flag = $sent ? 'sent' : 'failed';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'      => 'ffc_form',
					'page'           => 'ffc-settings',
					'tab'            => 'smtp',
					'ffc_test_email' => $flag,
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}
}

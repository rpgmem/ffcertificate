<?php
declare(strict_types=1);

/**
 * ActivityLogSubscriber
 *
 * Listens to plugin hooks and logs activities via ActivityLog.
 * This decouples ActivityLog from business logic classes (SubmissionHandler,
 * AppointmentHandler) — the plugin "eats its own dog food" by consuming
 * its own hooks internally.
 *
 * @since 4.6.5
 * @version 4.6.9 - Added daily log cleanup hook
 */

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ActivityLogSubscriber {

	/**
	 * Register all hook listeners.
	 */
	public function __construct() {
		// Submission hooks
		add_action( 'ffc_after_submission_save', [ $this, 'on_submission_created' ], 10, 4 );
		add_action( 'ffc_after_submission_update', [ $this, 'on_submission_updated' ], 10, 2 );
		add_action( 'ffc_submission_trashed', [ $this, 'on_submission_trashed' ], 10, 1 );
		add_action( 'ffc_submission_restored', [ $this, 'on_submission_restored' ], 10, 1 );
		add_action( 'ffc_after_submission_delete', [ $this, 'on_submission_deleted' ], 10, 1 );

		// Appointment hooks
		add_action( 'ffc_after_appointment_create', [ $this, 'on_appointment_created' ], 10, 3 );
		add_action( 'ffc_appointment_cancelled', [ $this, 'on_appointment_cancelled' ], 10, 4 );

		// Settings hooks
		add_action( 'ffc_settings_saved', [ $this, 'on_settings_saved' ], 10, 1 );

		// Daily cron: automatic log cleanup (v4.6.9)
		add_action( 'ffc_daily_cleanup_hook', [ $this, 'on_daily_cleanup' ] );
	}

	/**
	 * Log submission created.
	 *
	 * @param int    $submission_id Submission ID.
	 * @param int    $form_id       Form ID.
	 * @param array  $submission_data Submission data.
	 * @param string $user_email    User email.
	 */
	public function on_submission_created( int $submission_id, int $form_id, array $submission_data, string $user_email ): void {
		if ( ! class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			return;
		}

		ActivityLog::log_submission_created( $submission_id, [
			'form_id' => $form_id,
			'has_cpf' => ! empty( $submission_data['cpf_rf'] ),
		] );
	}

	/**
	 * Log submission updated.
	 *
	 * @param int   $id          Submission ID.
	 * @param array $update_data Updated data.
	 */
	public function on_submission_updated( int $id, array $update_data ): void {
		if ( ! class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			return;
		}

		ActivityLog::log_submission_updated( $id, get_current_user_id() );
	}

	/**
	 * Log submission trashed.
	 *
	 * @param int $id Submission ID.
	 */
	public function on_submission_trashed( int $id ): void {
		if ( ! class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			return;
		}

		ActivityLog::log_submission_trashed( $id );
	}

	/**
	 * Log submission restored.
	 *
	 * @param int $id Submission ID.
	 */
	public function on_submission_restored( int $id ): void {
		if ( ! class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			return;
		}

		ActivityLog::log_submission_restored( $id );
	}

	/**
	 * Log submission deleted.
	 *
	 * @param int $id Submission ID.
	 */
	public function on_submission_deleted( int $id ): void {
		if ( ! class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			return;
		}

		ActivityLog::log_submission_deleted( $id );
	}

	/**
	 * Log appointment created.
	 *
	 * @param int   $appointment_id Appointment ID.
	 * @param array $data           Appointment data.
	 * @param array $calendar       Calendar configuration.
	 */
	public function on_appointment_created( int $appointment_id, array $data, array $calendar ): void {
		if ( ! class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			return;
		}

		ActivityLog::log(
			'appointment_created',
			ActivityLog::LEVEL_INFO,
			[
				'appointment_id' => $appointment_id,
				'calendar_id'    => $data['calendar_id'] ?? 0,
				'date'           => $data['appointment_date'] ?? '',
				'time'           => $data['start_time'] ?? '',
				'status'         => $data['status'] ?? '',
				'user_id'        => $data['user_id'] ?? null,
				'ip'             => $data['user_ip'] ?? '',
			],
			$appointment_id
		);
	}

	/**
	 * Log appointment cancelled.
	 *
	 * @param int      $appointment_id Appointment ID.
	 * @param array    $appointment    Original appointment data.
	 * @param string   $reason         Cancellation reason.
	 * @param int|null $cancelled_by   User ID who cancelled.
	 */
	public function on_appointment_cancelled( int $appointment_id, array $appointment, string $reason, ?int $cancelled_by ): void {
		if ( ! class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			return;
		}

		ActivityLog::log(
			'appointment_cancelled',
			ActivityLog::LEVEL_WARNING,
			[
				'appointment_id' => $appointment_id,
				'calendar_id'    => $appointment['calendar_id'] ?? 0,
				'cancelled_by'   => $cancelled_by,
				'reason'         => $reason,
			],
			$appointment_id
		);
	}

	/**
	 * Invalidate caches when settings are saved.
	 *
	 * Ensures settings-dependent components pick up new values
	 * without stale cached data.
	 *
	 * @param array $settings Saved settings.
	 */
	public function on_settings_saved( array $settings ): void {
		// Clear WordPress options cache for ffc_settings
		wp_cache_delete( 'ffc_settings', 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		// Clear any plugin-specific transients
		delete_transient( 'ffc_settings_cache' );
		delete_transient( 'ffc_geolocation_cache' );

		// Clear ActivityLog column cache (in case table structure changed)
		if ( class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			ActivityLog::clear_column_cache();
		}

		// Clear stats transients so new settings take effect
		delete_transient( 'ffc_activity_stats_7' );
		delete_transient( 'ffc_activity_stats_30' );
		delete_transient( 'ffc_activity_stats_90' );
	}

	/**
	 * Run automatic activity log cleanup on daily cron.
	 *
	 * @since 4.6.9
	 */
	public function on_daily_cleanup(): void {
		if ( ! class_exists( '\FreeFormCertificate\Core\ActivityLog' ) ) {
			return;
		}

		ActivityLog::run_cleanup();
	}
}

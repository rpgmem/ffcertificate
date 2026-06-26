<?php
/**
 * Migration Actions AJAX endpoint — JSON-based batch runner.
 *
 * Replaces the HTML-parsing loop in ffc-admin-migrations.js (which
 * fetched the full Settings page on every iteration) with a clean
 * ~200-byte JSON response per batch. The legacy admin_init handler in
 * `Settings::handle_migration_execution` stays in place as the no-JS
 * fallback.
 *
 * Security:
 *   - nonce verified against the action name (FFC.request supplies it).
 *   - capability gated via Capabilities::current_user_can_admin_or, matching
 *     the legacy handler so privilege boundaries don't shift.
 *
 * @package FreeFormCertificate\Admin
 * @since 6.5.7
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX endpoint for running migration batches with progress polling.
 */
class MigrationActionsAjaxEndpoint {

	public const AJAX_ACTION = 'ffc_migration_run_batch';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Handle the AJAX request.
	 *
	 * Runs ONE batch of the requested migration and returns the fresh
	 * status snapshot. The JS-side loops until `is_complete=true`.
	 */
	public static function handle(): void {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );

		if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_manage_settings' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to run migrations.', 'ffcertificate' ) ),
				403
			);
		}

		$migration_key = isset( $_POST['migration_key'] )
			? sanitize_key( wp_unslash( $_POST['migration_key'] ) )
			: '';

		if ( '' === $migration_key ) {
			wp_send_json_error(
				array( 'message' => __( 'Missing migration key.', 'ffcertificate' ) ),
				400
			);
		}

		try {
			$manager = new \FreeFormCertificate\Migrations\MigrationManager();
		} catch ( \Throwable $e ) {
			wp_send_json_error(
				array( 'message' => $e->getMessage() ),
				500
			);
		}

		if ( ! $manager->is_migration_available( $migration_key ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Unknown migration.', 'ffcertificate' ) ),
				404
			);
		}

		$result = $manager->run_migration( $migration_key );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array( 'message' => $result->get_error_message() ),
				500
			);
		}

		// Pull the freshly-updated status so the client can repaint the
		// bar with the real percent / counters rather than estimating.
		$status = $manager->get_migration_status( $migration_key );
		if ( is_wp_error( $status ) ) {
			wp_send_json_error(
				array( 'message' => $status->get_error_message() ),
				500
			);
		}

		wp_send_json_success(
			array(
				'migration_key' => $migration_key,
				'processed'     => isset( $result['processed'] ) ? (int) $result['processed'] : 0,
				'total'         => (int) ( $status['total'] ?? 0 ),
				'migrated'      => (int) ( $status['migrated'] ?? 0 ),
				'pending'       => (int) ( $status['pending'] ?? 0 ),
				'percent'       => (float) ( $status['percent'] ?? 0 ),
				'is_complete'   => (bool) ( $status['is_complete'] ?? false ),
			)
		);
	}
}

<?php
/**
 * Submissions Bulk Actions AJAX endpoint.
 *
 * Lets the admin trash / restore / delete submissions inline from the
 * Submissions list without the per-action page reload of the legacy
 * admin_init handler in `Admin::handle_submission_actions()`. The
 * legacy handler stays in place as the no-JS fallback.
 *
 * Used both by the WP-list-table bulk form (multiple IDs) and by the
 * per-row Trash / Restore / Delete buttons (single ID — the JS sends
 * an array of one to the same endpoint).
 *
 * Security:
 *   - nonce verified against the action name (FFC.request supplies it).
 *   - capability gated on `manage_options`, matching the submenu cap
 *     used to register the Submissions page.
 *   - the `action` key lives in a hardcoded allowlist; the IDs are
 *     coerced through absint and filtered to drop non-positive values.
 *
 * Out of scope: `move_to_form` already has its own dedicated modal
 * flow with conflict detection.
 *
 * @package FreeFormCertificate\Admin
 * @since 6.5.9
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AJAX endpoint for inline submission bulk actions.
 */
class SubmissionsBulkActionsAjaxEndpoint {

	public const AJAX_ACTION = 'ffc_submissions_bulk_action';

	/**
	 * Allowed action names — the JS-side payload's `action` field must
	 * match one of these. Each maps to a bulk method on SubmissionHandler.
	 *
	 * @return array<string, string>
	 */
	public static function action_map(): array {
		return array(
			'trash'   => 'bulk_trash_submissions',
			'restore' => 'bulk_restore_submissions',
			'delete'  => 'bulk_delete_submissions',
		);
	}

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

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to modify submissions.', 'ffcertificate' ) ),
				403
			);
		}

		$action = isset( $_POST['action_name'] ) ? sanitize_key( wp_unslash( $_POST['action_name'] ) ) : '';
		$map    = self::action_map();
		if ( ! isset( $map[ $action ] ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Unknown action.', 'ffcertificate' ) ),
				400
			);
		}

		$raw_ids = isset( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : array();
		if ( ! is_array( $raw_ids ) ) {
			$raw_ids = array();
		}
		$ids = array_values(
			array_filter(
				array_map( 'absint', $raw_ids ),
				static function ( $id ) {
					return $id > 0;
				}
			)
		);
		if ( empty( $ids ) ) {
			wp_send_json_error(
				array( 'message' => __( 'No submissions selected.', 'ffcertificate' ) ),
				400
			);
		}

		$method  = $map[ $action ];
		$handler = new \FreeFormCertificate\Submissions\SubmissionHandler();
		$result  = $handler->$method( $ids );

		if ( false === $result ) {
			wp_send_json_error(
				array( 'message' => __( 'The bulk operation failed.', 'ffcertificate' ) ),
				500
			);
		}

		$count   = is_int( $result ) ? $result : count( $ids );
		$message = self::format_message( $action, $count );

		wp_send_json_success(
			array(
				'action'       => $action,
				'affected_ids' => $ids,
				'count'        => $count,
				'message'      => $message,
			)
		);
	}

	/**
	 * Build a localised toast message for the bulk result.
	 *
	 * @param string $action One of trash / restore / delete.
	 * @param int    $count  Number of submissions affected.
	 */
	private static function format_message( string $action, int $count ): string {
		switch ( $action ) {
			case 'trash':
				/* translators: %d: number of submissions trashed. */
				return sprintf( _n( '%d submission moved to trash.', '%d submissions moved to trash.', $count, 'ffcertificate' ), $count );
			case 'restore':
				/* translators: %d: number of submissions restored. */
				return sprintf( _n( '%d submission restored.', '%d submissions restored.', $count, 'ffcertificate' ), $count );
			case 'delete':
				/* translators: %d: number of submissions permanently deleted. */
				return sprintf( _n( '%d submission permanently deleted.', '%d submissions permanently deleted.', $count, 'ffcertificate' ), $count );
			default:
				return '';
		}
	}
}

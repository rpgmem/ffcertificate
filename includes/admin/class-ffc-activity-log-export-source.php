<?php
/**
 * ActivityLogExportSource
 *
 * Batched {@see \FreeFormCertificate\Core\BatchedExportSourceInterface} for the
 * Activity Log export (`ffc-activity-log` admin page). Migrated from the former
 * synchronous export (`AdminActivityLogPage::handle_csv_export`, which
 * materialised every matching row with `limit = 999999`) onto the shared
 * timeout-safe engine (issue #772): the export now runs as an AJAX start →
 * batch → download job via the unified dispatcher, keyset-paged by `id` so a
 * large audit trail can't exhaust memory or the execution-time budget.
 *
 * `context` PII is decrypted per row inside the query layer (never persisted to
 * disk beyond the transient temp file the engine already guards). Export order
 * is id-DESC (a stable keyset), which matches the page's default
 * `created_at DESC` for this sequentially-written table. Filters preserved:
 * level / action / search.
 *
 * Gated by the dedicated `ffc_export_activity_log` capability + a job nonce.
 *
 * @package FreeFormCertificate\Admin
 * @since   6.17.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Admin;

use FreeFormCertificate\Core\ActivityLogQuery;
use FreeFormCertificate\Core\BatchedExportSourceInterface;
use FreeFormCertificate\Core\Capabilities;
use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activity-log export as a batched source.
 */
class ActivityLogExportSource implements BatchedExportSourceInterface {

	/**
	 * Stable source type routed by the dispatcher / registry.
	 */
	public const TYPE = 'activity_log';

	/**
	 * Capability gating every phase.
	 */
	private const CAP = 'ffc_export_activity_log';

	/**
	 * Nonce action shared by start / batch / download.
	 */
	private const NONCE = 'ffc_activity_log_export';

	/**
	 * Per-request cache of user id => display string.
	 *
	 * @var array<int, string>
	 */
	private array $user_names = array();

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function type(): string {
		return self::TYPE;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function authorize_start(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! Capabilities::current_user_can_admin_or( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to export the activity log.', 'ffcertificate' ) ), 403 );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return void
	 */
	public function authorize_batch( array $job ): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! Capabilities::current_user_can_admin_or( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to export the activity log.', 'ffcertificate' ) ), 403 );
		}
		if ( (int) get_current_user_id() !== (int) ( $job['user_id'] ?? -1 ) ) {
			wp_send_json_error( array( 'message' => __( 'Session mismatch.', 'ffcertificate' ) ), 403 );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return void
	 */
	public function authorize_download( array $job ): void {
		if ( ! wp_verify_nonce( RequestInput::get_get_string( 'nonce' ), self::NONCE ) ) {
			wp_die( esc_html__( 'Security check failed.', 'ffcertificate' ) );
		}
		if ( ! Capabilities::current_user_can_admin_or( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to export the activity log.', 'ffcertificate' ) );
		}
		if ( (int) get_current_user_id() !== (int) ( $job['user_id'] ?? -1 ) ) {
			wp_die( esc_html__( 'Session mismatch.', 'ffcertificate' ) );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function job_owner_fields(): array {
		return array( 'user_id' => get_current_user_id() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, mixed>
	 */
	public function sanitize_filters(): array {
		// The nonce is verified in authorize_start(); RequestInput wraps + sanitizes.
		return array(
			'level'  => sanitize_key( RequestInput::get_post_string( 'level' ) ),
			'action' => RequestInput::get_post_string( 'log_action' ),
			'search' => RequestInput::get_post_string( 's' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return int
	 */
	public function count( array $filters ): int {
		return ActivityLogQuery::count_activities( $filters );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array<string, mixed>
	 */
	public function build_context( array $filters ): array {
		unset( $filters );
		return array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @param array<string, mixed> $context Frozen context.
	 * @return array<int, string>
	 */
	public function header( array $filters, array $context ): array {
		unset( $filters, $context );
		return array(
			__( 'Date/Time', 'ffcertificate' ),
			__( 'Level', 'ffcertificate' ),
			__( 'Action', 'ffcertificate' ),
			__( 'User', 'ffcertificate' ),
			__( 'IP Address', 'ffcertificate' ),
			__( 'Context', 'ffcertificate' ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @param array<string, mixed> $context Frozen context.
	 * @return string
	 */
	public function filename( array $filters, array $context ): string {
		unset( $filters, $context );
		return \FreeFormCertificate\Core\FilenameHelper::get_export_filename( 'ffc-activity-log' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @param array<string, mixed> $context Frozen context.
	 * @param int                  $cursor  Exclusive upper-bound id.
	 * @param int                  $size    Page size.
	 * @return array<int, array<string, mixed>>
	 */
	public function fetch_page( array $filters, array $context, int $cursor, int $size ): array {
		unset( $context );
		return ActivityLogQuery::find_by_cursor( $filters, $cursor, $size );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $row Row.
	 * @return int
	 */
	public function cursor_of( array $row ): int {
		return (int) ( $row['id'] ?? 0 );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $row     Raw row (context already decrypted).
	 * @param array<string, mixed> $context Frozen context.
	 * @return array<int, string>
	 */
	public function format_row( array $row, array $context ): array {
		unset( $context );

		$log_context = '';
		if ( ! empty( $row['context'] ) ) {
			$log_context = is_array( $row['context'] )
				? (string) wp_json_encode( $row['context'], JSON_UNESCAPED_UNICODE )
				: (string) $row['context'];
		}

		return array(
			(string) ( $row['created_at'] ?? '' ),
			strtoupper( (string) ( $row['level'] ?? '' ) ),
			AdminActivityLogPage::get_action_label( (string) ( $row['action'] ?? '' ) ),
			$this->user_display( (int) ( $row['user_id'] ?? 0 ) ),
			(string) ( $row['user_ip'] ?? '' ),
			$log_context,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $job_id Job id.
	 * @param array<string, mixed> $job    Job state.
	 * @return array<string, mixed>
	 */
	public function extra_start_response( string $job_id, array $job ): array {
		unset( $job_id, $job );
		return array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $job_id Job id.
	 * @param array<string, mixed> $job    Final job state.
	 * @return void
	 */
	public function on_complete( string $job_id, array $job ): void {
		unset( $job_id, $job );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $job Job state.
	 * @return void
	 */
	public function on_before_download( array $job ): void {
		unset( $job );
	}

	/**
	 * Resolve a user id to a "Display Name (login)" string, or the
	 * system/anonymous placeholder, cached per request.
	 *
	 * @param int $user_id WordPress user id.
	 * @return string
	 */
	private function user_display( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return __( 'System / Anonymous', 'ffcertificate' );
		}
		if ( isset( $this->user_names[ $user_id ] ) ) {
			return $this->user_names[ $user_id ];
		}
		$user = get_userdata( $user_id );
		$name = $user
			? $user->display_name . ' (' . $user->user_login . ')'
			: sprintf( 'User #%d', $user_id );

		$this->user_names[ $user_id ] = $name;
		return $name;
	}
}

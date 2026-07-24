<?php
/**
 * AudienceBookingExportSource
 *
 * Batched {@see \FreeFormCertificate\Core\BatchedExportSourceInterface} for the
 * Audience Bookings export (`ffc-scheduling-bookings` admin page). Migrated from
 * the former synchronous export (`AudienceBookingCsvExporter`, which paged the
 * reader straight to `php://output` via `CsvStreamer`) onto the shared
 * timeout-safe engine (issue #772): the export now runs as an AJAX start →
 * batch → download job via the unified dispatcher, keyset-paged by `id` so a
 * large bookings table can't exhaust the execution-time budget.
 *
 * The bookings tables store no direct PII — only foreign keys to WP users
 * (`created_by` / `cancelled_by` / participants) and to audiences — so this
 * source resolves those ids to display names / counts and needs no
 * encryption/masking layer. Export order is `id`-DESC (a stable keyset);
 * filters preserved: schedule / environment / status / date window.
 *
 * Gated by the shared `ffc_export_audiences` capability + a job nonce.
 *
 * @package FreeFormCertificate\Audience
 * @since   6.17.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\BatchedExportSourceInterface;
use FreeFormCertificate\Core\Capabilities;
use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Audience-bookings export as a batched source.
 */
class AudienceBookingExportSource implements BatchedExportSourceInterface {

	/**
	 * Stable source type routed by the dispatcher / registry.
	 */
	public const TYPE = 'audience_bookings';

	/**
	 * Capability gating every phase (shared with the audiences dataset export).
	 */
	private const CAP = 'ffc_export_audiences';

	/**
	 * Nonce action shared by start / batch / download.
	 */
	private const NONCE = 'ffc_audience_bookings_export';

	/**
	 * Per-request cache of user id => display name.
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
			wp_send_json_error( array( 'message' => __( 'You do not have permission to export bookings.', 'ffcertificate' ) ), 403 );
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
			wp_send_json_error( array( 'message' => __( 'You do not have permission to export bookings.', 'ffcertificate' ) ), 403 );
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
			wp_die( esc_html__( 'You do not have permission to export bookings.', 'ffcertificate' ) );
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
		$filters = array();

		$schedule_id    = RequestInput::get_post_int( 'schedule_id' );
		$environment_id = RequestInput::get_post_int( 'environment_id' );
		$status         = RequestInput::get_post_string( 'status' );
		$date_from      = RequestInput::get_post_string( 'date_from' );
		$date_to        = RequestInput::get_post_string( 'date_to' );

		if ( $schedule_id > 0 ) {
			$filters['schedule_id'] = $schedule_id;
		}
		if ( $environment_id > 0 ) {
			$filters['environment_id'] = $environment_id;
		}
		if ( '' !== $status ) {
			$filters['status'] = $status;
		}
		if ( '' !== $date_from ) {
			$filters['start_date'] = $date_from;
		}
		if ( '' !== $date_to ) {
			$filters['end_date'] = $date_to;
		}

		return $filters;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return int
	 */
	public function count( array $filters ): int {
		return AudienceBookingReader::count_for_export( $filters );
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
			__( 'ID', 'ffcertificate' ),
			__( 'Environment', 'ffcertificate' ),
			__( 'Schedule ID', 'ffcertificate' ),
			__( 'Booking Date', 'ffcertificate' ),
			__( 'Start Time', 'ffcertificate' ),
			__( 'End Time', 'ffcertificate' ),
			__( 'All Day', 'ffcertificate' ),
			__( 'Type', 'ffcertificate' ),
			__( 'Description', 'ffcertificate' ),
			__( 'Status', 'ffcertificate' ),
			__( 'Audiences', 'ffcertificate' ),
			__( 'Participants', 'ffcertificate' ),
			__( 'Created By', 'ffcertificate' ),
			__( 'Created At', 'ffcertificate' ),
			__( 'Cancelled By', 'ffcertificate' ),
			__( 'Cancelled At', 'ffcertificate' ),
			__( 'Cancellation Reason', 'ffcertificate' ),
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
		return \FreeFormCertificate\Core\FilenameHelper::get_export_filename( 'audience-bookings' );
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
		$rows = AudienceBookingReader::find_by_cursor( $filters, $cursor, $size );
		return array_map( static fn( $row ): array => (array) $row, $rows );
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
	 * @param array<string, mixed> $row     Raw booking row (cast from stdClass).
	 * @param array<string, mixed> $context Frozen context.
	 * @return array<int, string>
	 */
	public function format_row( array $row, array $context ): array {
		unset( $context );

		$booking_id = (int) ( $row['id'] ?? 0 );

		$audiences = array_map(
			static function ( $audience ): string {
				$audience = (array) $audience;
				return (string) ( $audience['name'] ?? '' );
			},
			AudienceBookingReader::get_booking_audiences( $booking_id )
		);

		$participant_count = count( AudienceBookingReader::get_booking_users( $booking_id ) );

		$type_labels   = array(
			'audience'   => __( 'Audience', 'ffcertificate' ),
			'individual' => __( 'Individual', 'ffcertificate' ),
		);
		$status_labels = array(
			'active'    => __( 'Active', 'ffcertificate' ),
			'cancelled' => __( 'Cancelled', 'ffcertificate' ),
		);

		$booking_type = (string) ( $row['booking_type'] ?? '' );
		$status       = (string) ( $row['status'] ?? '' );
		$all_day      = empty( $row['is_all_day'] ) ? __( 'No', 'ffcertificate' ) : __( 'Yes', 'ffcertificate' );

		return array(
			(string) $booking_id,
			(string) ( $row['environment_name'] ?? '' ),
			(string) ( $row['schedule_id'] ?? '' ),
			(string) ( $row['booking_date'] ?? '' ),
			(string) ( $row['start_time'] ?? '' ),
			(string) ( $row['end_time'] ?? '' ),
			$all_day,
			$type_labels[ $booking_type ] ?? $booking_type,
			(string) ( $row['description'] ?? '' ),
			$status_labels[ $status ] ?? $status,
			implode( ', ', $audiences ),
			(string) $participant_count,
			$this->user_name( (int) ( $row['created_by'] ?? 0 ) ),
			(string) ( $row['created_at'] ?? '' ),
			$this->user_name( (int) ( $row['cancelled_by'] ?? 0 ) ),
			(string) ( $row['cancelled_at'] ?? '' ),
			(string) ( $row['cancellation_reason'] ?? '' ),
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
	 * Resolve a user id to a display name, or the id placeholder, cached per
	 * request. Empty string for the zero / unset id (e.g. an active booking's
	 * blank `cancelled_by`).
	 *
	 * @param int $user_id WordPress user id.
	 * @return string
	 */
	private function user_name( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}
		if ( isset( $this->user_names[ $user_id ] ) ) {
			return $this->user_names[ $user_id ];
		}
		$user = get_userdata( $user_id );
		$name = $user ? $user->display_name : 'ID: ' . $user_id;

		$this->user_names[ $user_id ] = $name;
		return $name;
	}
}

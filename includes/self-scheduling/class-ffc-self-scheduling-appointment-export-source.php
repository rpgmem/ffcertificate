<?php
/**
 * AppointmentExportSource
 *
 * Batched {@see \FreeFormCertificate\Core\BatchedExportSourceInterface} for the
 * appointment export (`ffc-appointments` admin page). Migrated from the former
 * synchronous export (`AppointmentCsvExporter`, an `admin_post` handler that
 * paged the result set straight to `php://output` via `CsvStreamer`) onto the
 * shared timeout-safe engine (issue #772): the export now runs as an AJAX
 * start → batch → download job via the unified dispatcher, keyset-paged by `id`.
 *
 * Like the certificate-submissions source, appointments carry per-row
 * `custom_data` JSON, so the header is the fixed columns plus the union of every
 * matching row's dynamic keys — discovered up front in `build_context()` by a
 * lightweight keyset scan of just the JSON columns. PII (email / phone / user
 * IP) is decrypted per row in memory, never persisted beyond the engine's guarded
 * temp file. Export order is `id`-DESC (a stable keyset); filters preserved:
 * calendar(s) / status(es) / date window.
 *
 * Gated by the dedicated `ffc_export_appointments` capability + a job nonce.
 *
 * @package FreeFormCertificate\SelfScheduling
 * @since   6.17.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\SelfScheduling;

use FreeFormCertificate\Core\BatchedExportSourceInterface;
use FreeFormCertificate\Core\Capabilities;
use FreeFormCertificate\Core\RequestInput;
use FreeFormCertificate\Repositories\AppointmentRepository;
use FreeFormCertificate\Repositories\CalendarRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Appointment export as a batched source.
 */
class AppointmentExportSource implements BatchedExportSourceInterface {

	use \FreeFormCertificate\Core\CsvExportTrait;

	/**
	 * Stable source type routed by the dispatcher / registry.
	 */
	public const TYPE = 'appointments';

	/**
	 * Capability gating every phase.
	 */
	private const CAP = 'ffc_export_appointments';

	/**
	 * Nonce action shared by start / batch / download.
	 */
	private const NONCE = 'ffc_appointments_export';

	/**
	 * Records per query during dynamic-key discovery (lighter query).
	 */
	private const KEYS_BATCH_SIZE = 500;

	/**
	 * Appointment repository.
	 *
	 * @var AppointmentRepository
	 */
	private AppointmentRepository $appointment_repository;

	/**
	 * Calendar repository.
	 *
	 * @var CalendarRepository
	 */
	private CalendarRepository $calendar_repository;

	/**
	 * Per-request cache of calendar id => title.
	 *
	 * @var array<int, string>
	 */
	private array $calendar_title_cache = array();

	/**
	 * Constructor.
	 *
	 * @param AppointmentRepository $appointment_repository Appointment repository.
	 * @param CalendarRepository    $calendar_repository    Calendar repository.
	 */
	public function __construct( AppointmentRepository $appointment_repository, CalendarRepository $calendar_repository ) {
		$this->appointment_repository = $appointment_repository;
		$this->calendar_repository    = $calendar_repository;
	}

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
			wp_send_json_error( array( 'message' => __( 'You do not have permission to export appointments.', 'ffcertificate' ) ), 403 );
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
			wp_send_json_error( array( 'message' => __( 'You do not have permission to export appointments.', 'ffcertificate' ) ), 403 );
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
			wp_die( esc_html__( 'You do not have permission to export appointments.', 'ffcertificate' ) );
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
		$calendar_id = RequestInput::get_post_int( 'calendar_id' );
		$status      = RequestInput::get_post_string( 'status' );
		$start_date  = RequestInput::get_post_string( 'start_date' );
		$end_date    = RequestInput::get_post_string( 'end_date' );

		return array(
			'calendar_ids' => $calendar_id > 0 ? array( $calendar_id ) : null,
			'statuses'     => '' !== $status ? array( sanitize_key( $status ) ) : array(),
			'start_date'   => '' !== $start_date ? $start_date : null,
			'end_date'     => '' !== $end_date ? $end_date : null,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return int
	 */
	public function count( array $filters ): int {
		return $this->appointment_repository->countForExport(
			$filters['calendar_ids'],
			(array) $filters['statuses'],
			$filters['start_date'],
			$filters['end_date']
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array<string, mixed>
	 */
	public function build_context( array $filters ): array {
		return array(
			'dynamic_keys' => $this->scan_dynamic_keys( $filters ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @param array<string, mixed> $context Frozen context.
	 * @return array<int, string>
	 */
	public function header( array $filters, array $context ): array {
		unset( $filters );
		return array_values(
			array_merge(
				$this->get_fixed_headers(),
				$this->build_dynamic_headers( $context['dynamic_keys'] )
			)
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
		unset( $context );
		$calendar_ids = $filters['calendar_ids'];
		if ( $calendar_ids && count( $calendar_ids ) === 1 ) {
			$calendar       = $this->calendar_repository->findById( (int) $calendar_ids[0] );
			$calendar_title = $calendar ? (string) $calendar['title'] : 'calendar-' . $calendar_ids[0];
		} elseif ( $calendar_ids && count( $calendar_ids ) > 1 ) {
			$calendar_title = count( $calendar_ids ) . '-calendars';
		} else {
			$calendar_title = 'all-calendars';
		}

		return \FreeFormCertificate\Core\FilenameHelper::sanitize_filename( $calendar_title ) . '-appointments-' . gmdate( 'Y-m-d' ) . '.csv';
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
		return $this->appointment_repository->getExportBatch(
			$filters['calendar_ids'],
			(array) $filters['statuses'],
			$filters['start_date'],
			$filters['end_date'],
			$cursor,
			$size
		);
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
	 * @param array<string, mixed> $row     Raw row (custom_data still JSON/encrypted).
	 * @param array<string, mixed> $context Frozen context.
	 * @return array<int, mixed>
	 */
	public function format_row( array $row, array $context ): array {
		return $this->format_csv_row( $row, $context['dynamic_keys'] );
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

	// ──────────────────────────────────────────────────────────────.
	// Domain helpers (moved verbatim from the former AppointmentCsvExporter).
	// ──────────────────────────────────────────────────────────────.

	/**
	 * Scan all matching records to discover the union of dynamic JSON keys,
	 * keyset-paged by id so the whole result set never sits in memory at once.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array<int, string>
	 */
	private function scan_dynamic_keys( array $filters ): array {
		$all_keys = array();
		$cursor   = PHP_INT_MAX;

		while ( true ) {
			$batch = $this->appointment_repository->getExportKeysBatch(
				$filters['calendar_ids'],
				(array) $filters['statuses'],
				$filters['start_date'],
				$filters['end_date'],
				$cursor,
				self::KEYS_BATCH_SIZE
			);
			if ( empty( $batch ) ) {
				break;
			}
			$all_keys = array_merge( $all_keys, $this->extract_dynamic_keys( $batch, 'custom_data', 'custom_data_encrypted' ) );
			$last_row = end( $batch );
			$cursor   = (int) $last_row['id'];
			unset( $batch );
		}

		return array_values( array_unique( $all_keys ) );
	}

	/**
	 * Fixed CSV column headers.
	 *
	 * @return array<int, string>
	 */
	private function get_fixed_headers(): array {
		return array(
			__( 'ID', 'ffcertificate' ),
			__( 'Calendar', 'ffcertificate' ),
			__( 'Calendar ID', 'ffcertificate' ),
			__( 'User ID', 'ffcertificate' ),
			__( 'Name', 'ffcertificate' ),
			__( 'Email', 'ffcertificate' ),
			__( 'Phone', 'ffcertificate' ),
			__( 'Appointment Date', 'ffcertificate' ),
			__( 'Start Time', 'ffcertificate' ),
			__( 'End Time', 'ffcertificate' ),
			__( 'Status', 'ffcertificate' ),
			__( 'User Notes', 'ffcertificate' ),
			__( 'Admin Notes', 'ffcertificate' ),
			__( 'Consent Given', 'ffcertificate' ),
			__( 'Consent Date', 'ffcertificate' ),
			__( 'Consent IP', 'ffcertificate' ),
			__( 'Consent Text', 'ffcertificate' ),
			__( 'Created At', 'ffcertificate' ),
			__( 'Updated At', 'ffcertificate' ),
			__( 'Approved At', 'ffcertificate' ),
			__( 'Approved By', 'ffcertificate' ),
			__( 'Cancelled At', 'ffcertificate' ),
			__( 'Cancelled By', 'ffcertificate' ),
			__( 'Cancellation Reason', 'ffcertificate' ),
			__( 'Reminder Sent At', 'ffcertificate' ),
			__( 'User IP', 'ffcertificate' ),
			__( 'User Agent', 'ffcertificate' ),
		);
	}

	/**
	 * Format a single appointment row into a CSV line (fixed + dynamic columns).
	 *
	 * @param array<string, mixed> $row          Row.
	 * @param array<int, string>   $dynamic_keys Dynamic keys.
	 * @return array<int, mixed>
	 */
	private function format_csv_row( array $row, array $dynamic_keys ): array {
		// Calendar title (cached).
		$calendar_title = '';
		if ( ! empty( $row['calendar_id'] ) ) {
			$calendar_title = $this->get_calendar_title_cached( (int) $row['calendar_id'] );
		}

		// Decrypt sensitive fields (encrypted → plain fallback).
		$email   = \FreeFormCertificate\Core\Encryption::decrypt_field( $row, 'email' );
		$phone   = \FreeFormCertificate\Core\Encryption::decrypt_field( $row, 'phone' );
		$user_ip = \FreeFormCertificate\Core\Encryption::decrypt_field( $row, 'user_ip' );

		// Consent given (Yes/No).
		$consent_given = '';
		if ( isset( $row['consent_given'] ) ) {
			$consent_given = $row['consent_given'] ? __( 'Yes', 'ffcertificate' ) : __( 'No', 'ffcertificate' );
		}

		// Usernames for approval/cancellation.
		$approved_by = '';
		if ( ! empty( $row['approved_by'] ) ) {
			$user        = get_userdata( (int) $row['approved_by'] );
			$approved_by = $user ? $user->display_name : 'ID: ' . $row['approved_by'];
		}
		$cancelled_by = '';
		if ( ! empty( $row['cancelled_by'] ) ) {
			$user         = get_userdata( (int) $row['cancelled_by'] );
			$cancelled_by = $user ? $user->display_name : 'ID: ' . $row['cancelled_by'];
		}

		// Status label.
		$status_labels = array(
			'pending'   => __( 'Pending', 'ffcertificate' ),
			'confirmed' => __( 'Confirmed', 'ffcertificate' ),
			'cancelled' => __( 'Cancelled', 'ffcertificate' ),
			'completed' => __( 'Completed', 'ffcertificate' ),
			'no_show'   => __( 'No Show', 'ffcertificate' ),
		);
		$status        = $status_labels[ $row['status'] ] ?? $row['status'];

		$line = array(
			$row['id'],
			$calendar_title,
			$row['calendar_id'] ?? '',
			$row['user_id'] ?? '',
			$row['name'] ?? '',
			$email,
			$phone,
			$row['appointment_date'] ?? '',
			$row['start_time'] ?? '',
			$row['end_time'] ?? '',
			$status,
			$row['user_notes'] ?? '',
			$row['admin_notes'] ?? '',
			$consent_given,
			// Category A instants since 6.6.0 (#249 sub-escopo d) — formatted for human eyes.
			! empty( $row['consent_date'] ) ? \FreeFormCertificate\Core\DateFormatter::format_datetime( (int) $row['consent_date'] ) : '',
			$user_ip,
			$row['consent_text'] ?? '',
			$row['created_at'] ?? '',
			$row['updated_at'] ?? '',
			! empty( $row['approved_at'] ) ? \FreeFormCertificate\Core\DateFormatter::format_datetime( (int) $row['approved_at'] ) : '',
			$approved_by,
			! empty( $row['cancelled_at'] ) ? \FreeFormCertificate\Core\DateFormatter::format_datetime( (int) $row['cancelled_at'] ) : '',
			$cancelled_by,
			$row['cancellation_reason'] ?? '',
			! empty( $row['reminder_sent_at'] ) ? \FreeFormCertificate\Core\DateFormatter::format_datetime( (int) $row['reminder_sent_at'] ) : '',
			$user_ip,
			$row['user_agent'] ?? '',
		);

		// Dynamic columns (custom_data fields).
		$custom_data = $this->decode_json_field( $row, 'custom_data', 'custom_data_encrypted' );
		foreach ( $dynamic_keys as $key ) {
			$value = $custom_data[ $key ] ?? '';
			if ( is_array( $value ) ) {
				$value = implode( ', ', $value );
			}
			$line[] = $value;
		}

		return $line;
	}

	/**
	 * Resolve a calendar id to its title, cached per request. Deleted calendars
	 * render as "(Deleted)".
	 *
	 * @param int $calendar_id Calendar id.
	 * @return string
	 */
	private function get_calendar_title_cached( int $calendar_id ): string {
		if ( ! isset( $this->calendar_title_cache[ $calendar_id ] ) ) {
			$calendar                                    = $this->calendar_repository->findById( $calendar_id );
			$this->calendar_title_cache[ $calendar_id ] = $calendar['title'] ?? __( '(Deleted)', 'ffcertificate' );
		}
		return $this->calendar_title_cache[ $calendar_id ];
	}
}

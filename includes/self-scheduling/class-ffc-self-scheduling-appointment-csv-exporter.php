<?php
/**
 * Appointment CSV Exporter
 *
 * Handles CSV export functionality for calendar appointments.
 * Exports appointment data with dynamic columns and filtering.
 *
 * @package FreeFormCertificate\SelfScheduling
 * @since 4.1.0
 * @version 4.1.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\SelfScheduling;

use FreeFormCertificate\Repositories\AppointmentRepository;
use FreeFormCertificate\Repositories\CalendarRepository;
use FreeFormCertificate\Core\CsvStreamer;
use FreeFormCertificate\Core\HttpCsvDownload;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exporter for appointment csv data.
 */
class AppointmentCsvExporter {

	use \FreeFormCertificate\Core\CsvExportTrait;

	/**
	 * Rows fetched per page while streaming the export (issue #757).
	 */
	private const BATCH_SIZE = 500;

	/**
	 * Appointment repository.
	 *
	 * @var AppointmentRepository
	 */
	protected $appointment_repository;

	/**
	 * Calendar repository.
	 *
	 * @var CalendarRepository
	 */
	protected $calendar_repository;

	/**
	 * CSV streaming orchestrator (injectable so tests can capture the output
	 * instead of writing to php://output and calling exit).
	 *
	 * @var CsvStreamer
	 */
	private CsvStreamer $streamer;

	/**
	 * Constructor
	 *
	 * @param CsvStreamer|null $streamer CSV streamer; defaults to the live HTTP download.
	 */
	public function __construct( ?CsvStreamer $streamer = null ) {
		$this->appointment_repository = new AppointmentRepository();
		$this->calendar_repository    = new CalendarRepository();
		$this->streamer               = $streamer ?? new CsvStreamer( new HttpCsvDownload() );

		// Register export action.
		add_action( 'admin_post_ffc_export_appointments_csv', array( $this, 'handle_export_request' ) );
	}

	/**
	 * Get fixed column headers
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
	 * Get all unique custom data keys from appointments.
	 * Delegates to CsvExportTrait::extract_dynamic_keys().
	 *
	 * @param array<int, array<string, mixed>> $rows Rows.
	 * @return array<int, string>
	 */
	private function get_dynamic_columns( array $rows ): array {
		return $this->extract_dynamic_keys( $rows, 'custom_data', 'custom_data_encrypted' );
	}

	/**
	 * Get custom data from a row, handling encryption.
	 * Delegates to CsvExportTrait::decode_json_field().
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private function get_custom_data( array $row ): array {
		return $this->decode_json_field( $row, 'custom_data', 'custom_data_encrypted' );
	}

	/**
	 * Generate translatable headers for dynamic columns.
	 * Delegates to CsvExportTrait::build_dynamic_headers().
	 *
	 * @param array<int, string> $dynamic_keys Dynamic keys.
	 * @return array<string, string>
	 */
	private function get_dynamic_headers( array $dynamic_keys ): array {
		return $this->build_dynamic_headers( $dynamic_keys );
	}

	/**
	 * Format a single CSV row
	 *
	 * @param array<string, mixed> $row Row.
	 * @param array<int, string>   $dynamic_keys Dynamic keys.
	 * @return array<int, string>
	 */
	private function format_csv_row( array $row, array $dynamic_keys ): array {
		// Get calendar title.
		$calendar_title = '';
		if ( ! empty( $row['calendar_id'] ) ) {
			$calendar       = $this->calendar_repository->findById( (int) $row['calendar_id'] );
			$calendar_title = $calendar['title'] ?? __( '(Deleted)', 'ffcertificate' );
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

		// Get usernames for approval/cancellation.
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

		// Fixed Columns.
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

		// Dynamic Columns (custom_data fields).
		$custom_data = $this->get_custom_data( $row );

		foreach ( $dynamic_keys as $key ) {
			$value = $custom_data[ $key ] ?? '';
			// Flatten arrays/objects to string.
			if ( is_array( $value ) ) {
				$value = implode( ', ', $value );
			}
			$line[] = $value;
		}

		return $line;
	}

	/**
	 * Export appointments to CSV file
	 *
	 * @param array<int, int>|null $calendar_ids Calendar ID(s) to filter, null for all.
	 * @param array<int, string>   $statuses Status filter.
	 * @param string|null          $start_date Start date filter (Y-m-d).
	 * @param string|null          $end_date End date filter (Y-m-d).
	 * @return void
	 */
	public function export_csv( $calendar_ids = null, array $statuses = array(), ?string $start_date = null, ?string $end_date = null ): void {
		\FreeFormCertificate\Core\Debug::log_self_scheduling(
			'Appointment CSV export started',
			array(
				'calendar_ids' => $calendar_ids,
				'statuses'     => $statuses,
				'start_date'   => $start_date,
				'end_date'     => $end_date,
			)
		);

		[ $where_sql, $where_values ] = $this->build_export_where( $calendar_ids, $statuses, $start_date, $end_date );

		// Peek the first page; an empty first page means nothing to export.
		$first_page = $this->fetch_export_page( '*', $where_sql, $where_values, self::BATCH_SIZE, 0 );
		if ( empty( $first_page ) ) {
			wp_die( esc_html__( 'No appointments available for export.', 'ffcertificate' ) );
		}

		// The header needs the union of every row's custom_data keys, so pass
		// over the whole result set first — paged, selecting only the JSON
		// columns — instead of holding all rows in memory at once (issue #757).
		$dynamic_keys = $this->collect_dynamic_keys( $where_sql, $where_values );

		// Generate filename.
		if ( $calendar_ids && count( $calendar_ids ) === 1 ) {
			$calendar       = $this->calendar_repository->findById( $calendar_ids[0] );
			$calendar_title = $calendar ? $calendar['title'] : 'calendar-' . $calendar_ids[0];
		} elseif ( $calendar_ids && count( $calendar_ids ) > 1 ) {
			$calendar_title = count( $calendar_ids ) . '-calendars';
		} else {
			$calendar_title = 'all-calendars';
		}

		$filename = \FreeFormCertificate\Core\FilenameHelper::sanitize_filename( $calendar_title ) . '-appointments-' . gmdate( 'Y-m-d' ) . '.csv';

		// array_values() reindexes to int keys so the merged header matches
		// CsvStreamer::stream()'s array<int, string> contract (get_dynamic_headers
		// is typed with string keys). Keys are irrelevant to the CSV output.
		$header_row = array_values(
			array_merge(
				$this->get_fixed_headers(),
				$this->get_dynamic_headers( $dynamic_keys )
			)
		);

		$this->streamer->stream(
			$filename,
			$header_row,
			$this->export_rows( $first_page, $where_sql, $where_values, $dynamic_keys )
		);
	}

	/**
	 * Yield each formatted CSV row, streaming the result set in pages. The first
	 * page fetched for the empty-check in {@see self::export_csv()} is reused, so
	 * peak memory stays bounded by one batch regardless of the appointment count.
	 *
	 * @param array<int, array<string, mixed>> $first_page   The already-fetched first page.
	 * @param string                           $where_sql    WHERE clause.
	 * @param array<int, mixed>                $where_values Bind values.
	 * @param array<int, string>               $dynamic_keys Dynamic column keys.
	 * @return \Generator<int, array<int, string>>
	 */
	private function export_rows( array $first_page, string $where_sql, array $where_values, array $dynamic_keys ): \Generator {
		$page   = $first_page;
		$offset = 0;
		do {
			foreach ( $page as $row ) {
				yield $this->format_csv_row( $row, $dynamic_keys );
			}
			$fetched = count( $page );
			$offset += self::BATCH_SIZE;
			$page    = self::BATCH_SIZE === $fetched
				? $this->fetch_export_page( '*', $where_sql, $where_values, self::BATCH_SIZE, $offset )
				: array();
		} while ( ! empty( $page ) );
	}

	/**
	 * Build the shared WHERE clause + bind values for the export query.
	 *
	 * @param array<int, int>|null $calendar_ids Calendar id(s), or null for all.
	 * @param array<int, string>   $statuses     Status filter.
	 * @param string|null          $start_date   Start date (Y-m-d).
	 * @param string|null          $end_date     End date (Y-m-d).
	 * @return array{0: string, 1: array<int, mixed>} [ where_sql, bind_values ].
	 */
	private function build_export_where( $calendar_ids, array $statuses, ?string $start_date, ?string $end_date ): array {
		$where_clauses = array();
		$where_values  = array();

		// Calendar filter.
		if ( null !== $calendar_ids && ! empty( $calendar_ids ) ) {
			$placeholders    = implode( ',', array_fill( 0, count( $calendar_ids ), '%d' ) );
			$where_clauses[] = "calendar_id IN ($placeholders)";
			$where_values    = array_merge( $where_values, $calendar_ids );
		}

		// Status filter.
		if ( ! empty( $statuses ) ) {
			$placeholders    = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
			$where_clauses[] = "status IN ($placeholders)";
			$where_values    = array_merge( $where_values, $statuses );
		}

		// Date range filter.
		if ( $start_date && $end_date ) {
			$where_clauses[] = 'appointment_date BETWEEN %s AND %s';
			$where_values[]  = $start_date;
			$where_values[]  = $end_date;
		} elseif ( $start_date ) {
			$where_clauses[] = 'appointment_date >= %s';
			$where_values[]  = $start_date;
		} elseif ( $end_date ) {
			$where_clauses[] = 'appointment_date <= %s';
			$where_values[]  = $end_date;
		}

		$where_sql = ! empty( $where_clauses ) ? 'WHERE ' . implode( ' AND ', $where_clauses ) : '';

		return array( $where_sql, $where_values );
	}

	/**
	 * Fetch one ordered page of the export result set.
	 *
	 * The ORDER BY carries an `id` tiebreaker so LIMIT/OFFSET paging stays stable
	 * across pages even when many rows share an appointment_date + start_time.
	 *
	 * @param string            $select       Column list ('*' or a fixed literal — never request data).
	 * @param string            $where_sql    WHERE clause from {@see self::build_export_where()}.
	 * @param array<int, mixed> $where_values Bind values for the WHERE placeholders.
	 * @param int               $limit        Page size.
	 * @param int               $offset       Page offset.
	 * @return array<int, array<string, mixed>>
	 */
	private function fetch_export_page( string $select, string $where_sql, array $where_values, int $limit, int $offset ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'ffc_self_scheduling_appointments';

		// $select is a controlled literal supplied by this class, never request data.
		$sql  = "SELECT {$select} FROM %i {$where_sql} ORDER BY appointment_date DESC, start_time DESC, id DESC LIMIT %d OFFSET %d";
		$args = array_merge( array( $table ), $where_values, array( $limit, $offset ) );

		$sql = $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is a query template with placeholders bound above.

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $select is a fixed literal; $where_sql is built from validated placeholders; $sql is pre-prepared.
		return $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Union of every row's custom_data keys across the whole result set, read in
	 * pages (selecting only the JSON columns) so the header can be built without
	 * holding the full result set in memory (issue #757).
	 *
	 * @param string            $where_sql    WHERE clause.
	 * @param array<int, mixed> $where_values Bind values.
	 * @return array<int, string>
	 */
	private function collect_dynamic_keys( string $where_sql, array $where_values ): array {
		$keys   = array();
		$offset = 0;
		do {
			$page    = $this->fetch_export_page( 'custom_data, custom_data_encrypted', $where_sql, $where_values, self::BATCH_SIZE, $offset );
			$keys    = array_merge( $keys, $this->get_dynamic_columns( $page ) );
			$fetched = count( $page );
			$offset += self::BATCH_SIZE;
		} while ( self::BATCH_SIZE === $fetched );

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Handle export request from admin
	 *
	 * @return void
	 */
	public function handle_export_request(): void {
		try {
			// Security check.
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- isset() is an existence check; value sanitized on next line.
			if ( ! wp_verify_nonce( \FreeFormCertificate\Core\RequestInput::get_post_string( 'ffc_export_appointments_csv_action' ), 'ffc_export_appointments_csv_nonce' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'ffcertificate' ) );
			}

			// Bulk export is its own capability tier (GAP G), split out of
			// `ffc_manage_appointments` — a manager can configure the calendar
			// without holding the right to extract the full attendee dataset.
			if ( ! \FreeFormCertificate\Core\Capabilities::current_user_can_admin_or( 'ffc_export_appointments' ) ) {
				wp_die( esc_html__( 'You do not have permission to export appointments.', 'ffcertificate' ) );
			}

			// Get filters.
			$calendar_ids = null;
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint() applied to each element; is_array() is a type check only.
			if ( ! empty( $_POST['calendar_ids'] ) && is_array( $_POST['calendar_ids'] ) ) {
				$calendar_ids = array_map( 'absint', wp_unslash( $_POST['calendar_ids'] ) );
			} elseif ( ! empty( $_POST['calendar_id'] ) ) {
				$calendar_ids = array( absint( wp_unslash( $_POST['calendar_id'] ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint() is the sanitizer.
			}

			$statuses = array();
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key() applied after unslash; is_array() is a type check only.
			if ( ! empty( $_POST['statuses'] ) && is_array( $_POST['statuses'] ) ) {
				$statuses = array_map( 'sanitize_key', wp_unslash( $_POST['statuses'] ) );
			}

			$start_date = \FreeFormCertificate\Core\RequestInput::get_post_string( 'start_date' );
			$end_date   = \FreeFormCertificate\Core\RequestInput::get_post_string( 'end_date' );
			if ( '' === $start_date ) {
				$start_date = null;
			}
			if ( '' === $end_date ) {
				$end_date = null;
			}

			$this->export_csv( $calendar_ids, $statuses, $start_date, $end_date );

		} catch ( \Exception $e ) {
			\FreeFormCertificate\Core\Debug::log_self_scheduling(
				'Appointment CSV export exception',
				array(
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				)
			);
			wp_die( esc_html__( 'Error generating CSV: ', 'ffcertificate' ) . esc_html( $e->getMessage() ) );
		}
	}
}

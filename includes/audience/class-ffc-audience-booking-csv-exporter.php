<?php
/**
 * Audience Booking CSV Exporter
 *
 * Streams the Audience Bookings list (`ffc-scheduling-bookings` admin page) to a
 * CSV download. Registered as an `admin_post` handler and driven by the "Export
 * CSV" button on the bookings page. Gated by `ffc_export_audiences` (the same
 * export cap that governs the audiences dataset) so the list can be delegated
 * read-only without granting bulk extraction.
 *
 * Memory-safe: rows are fetched in fixed-size pages via
 * `AudienceBookingReader::get_all()` (LIMIT/OFFSET) and streamed straight to
 * `php://output`, so peak memory is bounded by the batch size rather than the
 * total booking count.
 *
 * The bookings tables store no direct PII — only foreign keys to WP users
 * (`created_by`, `cancelled_by`, participants) and to audiences — so this
 * exporter resolves those ids to display names / counts and needs no
 * encryption/masking layer.
 *
 * @package FreeFormCertificate\Audience
 * @since   6.16.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Audience;

use FreeFormCertificate\Core\Capabilities;
use FreeFormCertificate\Core\Csv;
use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exporter for audience-booking CSV data.
 */
class AudienceBookingCsvExporter {

	/**
	 * Rows fetched per page while streaming.
	 */
	private const BATCH_SIZE = 500;

	/**
	 * Per-request cache of user id => display name.
	 *
	 * @var array<int, string>
	 */
	private array $user_names = array();

	/**
	 * Constructor. Registers the export action.
	 */
	public function __construct() {
		add_action( 'admin_post_ffc_export_audience_bookings_csv', array( $this, 'handle_export_request' ) );
	}

	/**
	 * Handle the export request from the bookings page.
	 *
	 * @return void
	 */
	public function handle_export_request(): void {
		if ( ! wp_verify_nonce( RequestInput::get_post_string( 'ffc_export_audience_bookings_action' ), 'ffc_export_audience_bookings_nonce' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'ffcertificate' ) );
		}

		if ( ! Capabilities::current_user_can_admin_or( 'ffc_export_audiences' ) ) {
			wp_die( esc_html__( 'You do not have permission to export bookings.', 'ffcertificate' ) );
		}

		$args = array(
			'orderby' => 'booking_date',
			'order'   => 'DESC',
		);

		$schedule_id    = RequestInput::get_post_int( 'schedule_id' );
		$environment_id = RequestInput::get_post_int( 'environment_id' );
		$status         = RequestInput::get_post_string( 'status' );
		$date_from      = RequestInput::get_post_string( 'date_from' );
		$date_to        = RequestInput::get_post_string( 'date_to' );

		if ( $schedule_id > 0 ) {
			$args['schedule_id'] = $schedule_id;
		}
		if ( $environment_id > 0 ) {
			$args['environment_id'] = $environment_id;
		}
		if ( '' !== $status ) {
			$args['status'] = $status;
		}
		if ( '' !== $date_from ) {
			$args['start_date'] = $date_from;
		}
		if ( '' !== $date_to ) {
			$args['end_date'] = $date_to;
		}

		$this->export_csv( $args );
	}

	/**
	 * Fixed CSV column headers.
	 *
	 * @return array<int, string>
	 */
	private function get_headers(): array {
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
	 * Format one booking row (associative array cast from the reader's stdClass)
	 * into a CSV line.
	 *
	 * @param array<string, mixed> $row Booking row.
	 * @return array<int, string>
	 */
	private function format_row( array $row ): array {
		$booking_id = (int) ( $row['id'] ?? 0 );

		$audiences = array_map(
			static function ( $audience ): string {
				$audience = (array) $audience;
				return (string) ( $audience['name'] ?? '' );
			},
			AudienceBookingReader::get_booking_audiences( $booking_id )
		);

		$participant_count = count( AudienceBookingReader::get_booking_users( $booking_id ) );

		$yes_no = static function ( $flag ): string {
			return $flag ? __( 'Yes', 'ffcertificate' ) : __( 'No', 'ffcertificate' );
		};

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

		return array(
			(string) $booking_id,
			(string) ( $row['environment_name'] ?? '' ),
			(string) ( $row['schedule_id'] ?? '' ),
			(string) ( $row['booking_date'] ?? '' ),
			(string) ( $row['start_time'] ?? '' ),
			(string) ( $row['end_time'] ?? '' ),
			$yes_no( $row['is_all_day'] ?? 0 ),
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
	 * Resolve a user id to a display name (cached per request).
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

	/**
	 * Stream the bookings to a CSV download, fetched in pages.
	 *
	 * @param array<string, mixed> $args Reader filter args (without limit/offset).
	 * @return void
	 */
	private function export_csv( array $args ): void {
		$filename      = 'audience-bookings-' . gmdate( 'Y-m-d' ) . '.csv';
		$safe_filename = str_replace( array( "\r", "\n", '"' ), '', $filename );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $safe_filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming CSV download to php://output.
		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			exit;
		}

		$writer = Csv::writer( $output );
		$writer->row( $this->get_headers() );

		$offset = 0;
		do {
			$batch = AudienceBookingReader::get_all(
				array_merge(
					$args,
					array(
						'limit'  => self::BATCH_SIZE,
						'offset' => $offset,
					)
				)
			);
			foreach ( $batch as $row ) {
				$writer->row( $this->format_row( (array) $row ) );
			}
			$offset += self::BATCH_SIZE;
			$fetched = count( $batch );
		} while ( self::BATCH_SIZE === $fetched );

		$writer->close();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the php://output handle this method opened.
		fclose( $output );
		exit;
	}
}

<?php
/**
 * URL Shortener CSV Exporter
 *
 * Streams the Short URLs list (`ffc-short-urls` admin page) to a CSV download.
 * Registered as an `admin_post` handler and driven by the "Export CSV" button
 * on the admin page. Gated by the dedicated `ffc_export_url_shortener` cap
 * (split out of `ffc_manage_url_shortener`) so the list can be delegated
 * without granting bulk extraction.
 *
 * Memory-safe: rows are fetched in fixed-size pages via the repository's
 * `findPaginated()` and streamed straight to `php://output`, so peak memory is
 * bounded by the batch size rather than the total short-URL count.
 *
 * @package FreeFormCertificate\UrlShortener
 * @since   6.16.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\UrlShortener;

use FreeFormCertificate\Core\Capabilities;
use FreeFormCertificate\Core\CsvStreamer;
use FreeFormCertificate\Core\HttpCsvDownload;
use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exporter for short-URL CSV data.
 */
class UrlShortenerCsvExporter {

	/**
	 * Rows fetched per page while streaming.
	 */
	private const BATCH_SIZE = 500;

	/**
	 * URL shortener service (source of the repository).
	 *
	 * @var UrlShortenerService
	 */
	private UrlShortenerService $service;

	/**
	 * CSV streaming orchestrator (injectable so tests can capture the output
	 * instead of writing to php://output and calling exit).
	 *
	 * @var CsvStreamer
	 */
	private CsvStreamer $streamer;

	/**
	 * Per-request cache of user id => display name (avoids repeated lookups
	 * when the same creator owns many links).
	 *
	 * @var array<int, string>
	 */
	private array $user_names = array();

	/**
	 * Constructor.
	 *
	 * @param UrlShortenerService $service  Service.
	 * @param CsvStreamer|null    $streamer CSV streamer; defaults to the live HTTP download.
	 */
	public function __construct( UrlShortenerService $service, ?CsvStreamer $streamer = null ) {
		$this->service  = $service;
		$this->streamer = $streamer ?? new CsvStreamer( new HttpCsvDownload() );
		add_action( 'admin_post_ffc_export_short_urls_csv', array( $this, 'handle_export_request' ) );
	}

	/**
	 * Handle the export request from the admin page.
	 *
	 * @return void
	 */
	public function handle_export_request(): void {
		if ( ! wp_verify_nonce( RequestInput::get_post_string( 'ffc_export_short_urls_action' ), 'ffc_export_short_urls_nonce' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'ffcertificate' ) );
		}

		if ( ! Capabilities::current_user_can_admin_or( 'ffc_export_url_shortener' ) ) {
			wp_die( esc_html__( 'You do not have permission to export short URLs.', 'ffcertificate' ) );
		}

		$search  = RequestInput::get_post_string( 's' );
		$status  = RequestInput::get_post_string( 'status' );
		$orderby = RequestInput::get_post_string( 'orderby' );
		$order   = RequestInput::get_post_string( 'order' );

		$this->export_csv(
			$search,
			'' !== $status ? $status : 'all',
			'' !== $orderby ? $orderby : 'created_at',
			'' !== $order ? strtoupper( $order ) : 'DESC'
		);
	}

	/**
	 * Fixed CSV column headers.
	 *
	 * @return array<int, string>
	 */
	private function get_headers(): array {
		return array(
			__( 'ID', 'ffcertificate' ),
			__( 'Short Code', 'ffcertificate' ),
			__( 'Title', 'ffcertificate' ),
			__( 'Target URL', 'ffcertificate' ),
			__( 'Clicks', 'ffcertificate' ),
			__( 'Status', 'ffcertificate' ),
			__( 'Post ID', 'ffcertificate' ),
			__( 'Created By', 'ffcertificate' ),
			__( 'Created At', 'ffcertificate' ),
			__( 'Updated At', 'ffcertificate' ),
		);
	}

	/**
	 * Format one repository row into a CSV line.
	 *
	 * @param array<string, mixed> $row Short-URL row.
	 * @return array<int, string>
	 */
	private function format_row( array $row ): array {
		return array(
			(string) ( $row['id'] ?? '' ),
			(string) ( $row['short_code'] ?? '' ),
			(string) ( $row['title'] ?? '' ),
			(string) ( $row['target_url'] ?? '' ),
			(string) ( $row['click_count'] ?? '0' ),
			(string) ( $row['status'] ?? '' ),
			(string) ( $row['post_id'] ?? '' ),
			$this->creator_name( (int) ( $row['created_by'] ?? 0 ) ),
			(string) ( $row['created_at'] ?? '' ),
			(string) ( $row['updated_at'] ?? '' ),
		);
	}

	/**
	 * Resolve a creator user id to a display name (cached per request).
	 *
	 * @param int $user_id WordPress user id.
	 * @return string
	 */
	private function creator_name( int $user_id ): string {
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
	 * Stream the short-URL list to a CSV download.
	 *
	 * The heavy lifting (headers, output stream, termination) lives in the
	 * injected {@see CsvStreamer}; here we only supply the filename, the header
	 * row and a generator that pages the rows.
	 *
	 * @param string $search  Search term (title / target / code).
	 * @param string $status  Status filter ('all' excludes trashed).
	 * @param string $orderby Sort column.
	 * @param string $order   Sort direction (ASC|DESC).
	 * @return void
	 */
	private function export_csv( string $search, string $status, string $orderby, string $order ): void {
		$this->streamer->stream(
			'short-urls-' . gmdate( 'Y-m-d' ) . '.csv',
			$this->get_headers(),
			$this->stream_rows( $search, $status, $orderby, $order )
		);
	}

	/**
	 * Yield each short-URL row as a formatted CSV line, paging the repository so
	 * peak memory stays bounded by one batch regardless of the row count.
	 *
	 * @param string $search  Search term.
	 * @param string $status  Status filter.
	 * @param string $orderby Sort column.
	 * @param string $order   Sort direction.
	 * @return \Generator<int, array<int, string>>
	 */
	private function stream_rows( string $search, string $status, string $orderby, string $order ): \Generator {
		$repository = $this->service->get_repository();

		$page = 1;
		do {
			$result = $repository->findPaginated(
				array(
					'per_page' => self::BATCH_SIZE,
					'page'     => $page,
					'orderby'  => $orderby,
					'order'    => $order,
					'search'   => $search,
					'status'   => $status,
				)
			);
			$items  = $result['items'];
			foreach ( $items as $row ) {
				yield $this->format_row( $row );
			}
			$fetched = count( $items );
			++$page;
		} while ( self::BATCH_SIZE === $fetched );
	}
}

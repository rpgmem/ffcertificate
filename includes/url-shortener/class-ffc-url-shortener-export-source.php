<?php
/**
 * UrlShortenerExportSource
 *
 * Batched {@see \FreeFormCertificate\Core\BatchedExportSourceInterface} for the
 * Short URLs list export (`ffc-short-urls` admin page). Migrated from the former
 * synchronous `UrlShortenerCsvExporter` onto the shared timeout-safe engine
 * (issue #772): the export now runs as an AJAX start → batch → download job via
 * the unified dispatcher, so a large short-URL table can't exhaust the
 * execution-time budget.
 *
 * The batched engine uses a single-column `id` keyset (`WHERE id < cursor ORDER
 * BY id DESC`), so the export streams newest-first by id and no longer honours
 * the on-screen `orderby`/`order` (a view concern); the `search` + `status`
 * filters are preserved. For sequentially-created short URLs, id-DESC matches
 * the default `created_at DESC` in practice.
 *
 * Gated by the dedicated `ffc_export_url_shortener` capability + a job nonce.
 *
 * @package FreeFormCertificate\UrlShortener
 * @since   6.17.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\UrlShortener;

use FreeFormCertificate\Core\BatchedExportSourceInterface;
use FreeFormCertificate\Core\Capabilities;
use FreeFormCertificate\Core\RequestInput;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Short-URL list export as a batched source.
 */
class UrlShortenerExportSource implements BatchedExportSourceInterface {

	/**
	 * Stable source type routed by the dispatcher / registry.
	 */
	public const TYPE = 'url_shortener';

	/**
	 * Capability gating every phase.
	 */
	private const CAP = 'ffc_export_url_shortener';

	/**
	 * Nonce action shared by start / batch / download.
	 */
	private const NONCE = 'ffc_url_shortener_export';

	/**
	 * Repository.
	 *
	 * @var UrlShortenerRepository
	 */
	private UrlShortenerRepository $repository;

	/**
	 * Per-request cache of user id => display name.
	 *
	 * @var array<int, string>
	 */
	private array $user_names = array();

	/**
	 * Constructor.
	 *
	 * @param UrlShortenerRepository $repository Repository.
	 */
	public function __construct( UrlShortenerRepository $repository ) {
		$this->repository = $repository;
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
			wp_send_json_error( array( 'message' => __( 'You do not have permission to export short URLs.', 'ffcertificate' ) ), 403 );
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
			wp_send_json_error( array( 'message' => __( 'You do not have permission to export short URLs.', 'ffcertificate' ) ), 403 );
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
			wp_die( esc_html__( 'You do not have permission to export short URLs.', 'ffcertificate' ) );
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
		$status = RequestInput::get_post_string( 'status' );
		return array(
			'search' => RequestInput::get_post_string( 's' ),
			'status' => '' !== $status ? $status : 'all',
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return int
	 */
	public function count( array $filters ): int {
		return $this->repository->countForExport( $filters );
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
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @param array<string, mixed> $context Frozen context.
	 * @return string
	 */
	public function filename( array $filters, array $context ): string {
		unset( $filters, $context );
		return 'short-urls-' . gmdate( 'Y-m-d' ) . '.csv';
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
		return $this->repository->findByCursor( $filters, $cursor, $size );
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
	 * @param array<string, mixed> $row     Raw row.
	 * @param array<string, mixed> $context Frozen context.
	 * @return array<int, string>
	 */
	public function format_row( array $row, array $context ): array {
		unset( $context );
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
}

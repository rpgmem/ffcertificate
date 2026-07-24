<?php
/**
 * BatchedExportDispatcher
 *
 * The single AJAX entry point for every batched CSV export. It replaces the two
 * near-identical per-exporter endpoint trios (`ffc_csv_export_*` admin +
 * `ffc_public_csv_*` front-end) with one unified trio routed by a `type`
 * parameter:
 *
 *   wp_ajax(_nopriv)_ffc_export_start    → engine::handle_start
 *   wp_ajax(_nopriv)_ffc_export_batch    → engine::handle_batch
 *   wp_ajax(_nopriv)_ffc_export_download → engine::handle_download
 *
 * Each request carries `type`; the dispatcher looks the source up in the
 * {@see SourceRegistry} and hands it to the shared {@see BatchedCsvExport}
 * engine. Authorization is entirely the source's job (the engine calls its
 * per-phase `authorize_*`), so registering the `nopriv` variant for every phase
 * is safe — a capability-gated admin source rejects an anonymous caller, and an
 * anonymous public source runs its own rate-limit + nonce + IP-hash fence. All
 * jobs share one transient/file namespace (`ffc_export_` / `ffc-export-`); the
 * job's stored `type` is what a resumed batch/download routes on.
 *
 * @package FreeFormCertificate\Core
 * @since   6.17.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes the unified `ffc_export_*` AJAX endpoints to registered sources.
 */
class BatchedExportDispatcher {

	/**
	 * Shared job engine (one transient/file namespace for every source).
	 *
	 * @var BatchedCsvExport
	 */
	private BatchedCsvExport $engine;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->engine = new BatchedCsvExport( 'ffc_export_', 'ffc-export-' );
	}

	/**
	 * Register the three unified endpoints (priv + nopriv).
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_ajax_ffc_export_start', array( $this, 'start' ) );
		add_action( 'wp_ajax_nopriv_ffc_export_start', array( $this, 'start' ) );
		add_action( 'wp_ajax_ffc_export_batch', array( $this, 'batch' ) );
		add_action( 'wp_ajax_nopriv_ffc_export_batch', array( $this, 'batch' ) );
		add_action( 'wp_ajax_ffc_export_download', array( $this, 'download' ) );
		add_action( 'wp_ajax_nopriv_ffc_export_download', array( $this, 'download' ) );
	}

	/**
	 * Start endpoint.
	 *
	 * @return void
	 */
	public function start(): void {
		$this->engine->handle_start( $this->resolve_source_or_json_error() );
	}

	/**
	 * Batch endpoint.
	 *
	 * @return void
	 */
	public function batch(): void {
		$this->engine->handle_batch( $this->resolve_source_or_json_error() );
	}

	/**
	 * Download endpoint.
	 *
	 * @return void
	 */
	public function download(): void {
		$type   = RequestInput::get_get_string( 'type' );
		$source = SourceRegistry::get( $type );
		if ( null === $source ) {
			wp_die( esc_html__( 'Unknown export type.', 'ffcertificate' ) );
		}
		$this->engine->handle_download( $source );
	}

	/**
	 * Resolve the source for a start/batch request (from POST), terminating the
	 * request with a JSON error when the type is unknown.
	 *
	 * @return BatchedExportSourceInterface
	 */
	private function resolve_source_or_json_error(): BatchedExportSourceInterface {
		$type   = RequestInput::get_post_string( 'type' );
		$source = SourceRegistry::get( $type );
		if ( null === $source ) {
			// wp_send_json_error() halts the request (typed `never`), so the
			// return below is only reached with a non-null source.
			wp_send_json_error( array( 'message' => __( 'Unknown export type.', 'ffcertificate' ) ) );
		}
		return $source;
	}
}

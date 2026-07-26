<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\BatchedExportDispatcher;
use FreeFormCertificate\Core\SourceRegistry;
use FreeFormCertificate\Core\BatchedExportSourceInterface;

/**
 * Tests for the unified BatchedExportDispatcher: the six registered endpoints,
 * the unknown-`type` rejection on each phase, and that a known `type` is
 * resolved from the registry and handed to the engine (proved by the source's
 * first per-phase hook running). (Issue #772.)
 *
 * @covers \FreeFormCertificate\Core\BatchedExportDispatcher
 */
class BatchedExportDispatcherTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\Core\BatchedExportDispatcher' );
		SourceRegistry::reset();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => is_string( $v ) ? trim( $v ) : $v );
		Functions\when( 'wp_send_json_error' )->alias(
			static function () {
				throw new \RuntimeException( 'json_error' );
			}
		);
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);
	}

	protected function tearDown(): void {
		SourceRegistry::reset();
		unset( $_POST['type'], $_GET['type'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A source whose per-phase hooks throw a routing marker, proving the
	 * dispatcher resolved it and handed it to the engine.
	 */
	private function routing_source( string $type ): BatchedExportSourceInterface {
		return new class( $type ) implements BatchedExportSourceInterface {
			private string $t;
			public function __construct( string $t ) {
				$this->t = $t;
			}
			public function type(): string {
				return $this->t; }
			public function authorize_start(): void {
				throw new \RuntimeException( 'routed_start' );
			}
			public function authorize_batch( array $job ): void {
				throw new \RuntimeException( 'routed_batch' );
			}
			public function authorize_download( array $job ): void {
				throw new \RuntimeException( 'routed_download' );
			}
			public function job_owner_fields(): array {
				return array(); }
			public function sanitize_filters(): array {
				return array(); }
			public function count( array $filters ): int {
				return 0; }
			public function build_context( array $filters ): array {
				return array(); }
			public function header( array $filters, array $context ): array {
				return array(); }
			public function filename( array $filters, array $context ): string {
				return 'x.csv'; }
			public function fetch_page( array $filters, array $context, int $cursor, int $size ): array {
				return array(); }
			public function cursor_of( array $row ): int {
				return 0; }
			public function format_row( array $row, array $context ): array {
				return array(); }
			public function extra_start_response( string $job_id, array $job ): array {
				return array(); }
			public function on_complete( string $job_id, array $job ): void {}
			public function on_before_download( array $job ): void {}
		};
	}

	// ==================================================================
	// register()
	// ==================================================================

	public function test_register_adds_six_endpoints(): void {
		$hooks = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook ) use ( &$hooks ) {
				$hooks[] = $hook;
				return true;
			}
		);

		( new BatchedExportDispatcher() )->register();

		$this->assertSame(
			array(
				'wp_ajax_ffc_export_start',
				'wp_ajax_nopriv_ffc_export_start',
				'wp_ajax_ffc_export_batch',
				'wp_ajax_nopriv_ffc_export_batch',
				'wp_ajax_ffc_export_download',
				'wp_ajax_nopriv_ffc_export_download',
			),
			$hooks
		);
	}

	// ==================================================================
	// Unknown type rejection
	// ==================================================================

	public function test_start_rejects_unknown_type(): void {
		$_POST['type'] = 'nope';
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'json_error' );
		( new BatchedExportDispatcher() )->start();
	}

	public function test_batch_rejects_unknown_type(): void {
		$_POST['type'] = 'nope';
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'json_error' );
		( new BatchedExportDispatcher() )->batch();
	}

	public function test_download_rejects_unknown_type_with_wp_die(): void {
		$_GET['type'] = 'nope';
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die' );
		( new BatchedExportDispatcher() )->download();
	}

	// ==================================================================
	// Known type routes to the engine (→ source per-phase hook)
	// ==================================================================

	public function test_start_routes_known_type_to_engine(): void {
		SourceRegistry::register( 'fake', fn() => $this->routing_source( 'fake' ) );
		$_POST['type'] = 'fake';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'routed_start' );
		( new BatchedExportDispatcher() )->start();
	}

	public function test_download_routes_known_type_to_engine(): void {
		// handle_download resolves the source, then loads the job; with no job
		// transient it wp_die()s "not found" — but reaching wp_die (not the
		// unknown-type wp_die) still proves the known type was routed. To prove
		// the source is used, give it a job so authorize_download runs.
		SourceRegistry::register( 'fake', fn() => $this->routing_source( 'fake' ) );
		$_GET['type'] = 'fake';

		$store = array( 'ffc_export_job-x' => array( 'file' => '/tmp/x', 'type' => 'fake' ) );
		Functions\when( 'get_transient' )->alias(
			static function ( $k ) use ( &$store ) {
				return $store[ $k ] ?? false;
			}
		);
		$_GET['job_id'] = 'job-x';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'routed_download' );
		( new BatchedExportDispatcher() )->download();

		unset( $_GET['job_id'] );
	}
}

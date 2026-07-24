<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\BatchedCsvExport;
use FreeFormCertificate\Core\BatchedExportSourceInterface;

/**
 * Tests for the batched CSV export engine: the start → batch → download job
 * lifecycle, driven by a fake in-memory {@see BatchedExportSourceInterface}.
 * The engine's file I/O runs against real temp files (nothing native is
 * stubbed); only WP functions are stubbed via Brain Monkey. (Issue #772.)
 *
 * @covers \FreeFormCertificate\Core\BatchedCsvExport
 */
class BatchedCsvExportTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, mixed> In-memory transient store. */
	private array $store = array();

	/** @var string Temp base dir for the run. */
	private string $tmp_base = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\Core\BatchedCsvExport' );

		$this->store    = array();
		$this->tmp_base = sys_get_temp_dir() . '/ffc-engine-' . uniqid();
		@mkdir( $this->tmp_base, 0777, true );

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $v ) => is_string( $v ) ? trim( $v ) : $v );
		Functions\when( 'wp_raise_memory_limit' )->justReturn( true );
		Functions\when( 'trailingslashit' )->alias( static fn( $p ) => rtrim( (string) $p, '/' ) . '/' );
		Functions\when( 'wp_mkdir_p' )->alias( static fn( $d ) => is_dir( $d ) || @mkdir( $d, 0777, true ) );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'job-1' );
		Functions\when( 'wp_upload_dir' )->justReturn( array( 'basedir' => $this->tmp_base ) );

		$store = &$this->store;
		Functions\when( 'set_transient' )->alias(
			static function ( $k, $v ) use ( &$store ) {
				$store[ $k ] = $v;
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			static function ( $k ) use ( &$store ) {
				return $store[ $k ] ?? false;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			static function ( $k ) use ( &$store ) {
				unset( $store[ $k ] );
				return true;
			}
		);
		Functions\when( 'wp_send_json_success' )->alias(
			static function ( $d ) {
				throw new JsonHalt( 'success', $d );
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $d = null ) {
				throw new JsonHalt( 'error', $d );
			}
		);
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new \RuntimeException( 'wp_die' );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		foreach ( glob( $this->tmp_base . '/ffc-tmp/*' ) ?: array() as $f ) {
			@unlink( $f );
		}
		@unlink( $this->tmp_base . '/ffc-tmp/.htaccess' );
		@rmdir( $this->tmp_base . '/ffc-tmp' );
		@rmdir( $this->tmp_base );
		parent::tearDown();
	}

	/**
	 * A configurable in-memory source.
	 *
	 * @param array<int, array<string, mixed>> $rows      Rows (id DESC).
	 * @param int                              $count     Reported total.
	 * @param callable|null                    $completed Called by on_complete().
	 */
	private function make_source( array $rows, int $count, ?callable $completed = null ): BatchedExportSourceInterface {
		return new class( $rows, $count, $completed ) implements BatchedExportSourceInterface {
			/** @var array<int, array<string, mixed>> */
			private array $rows;
			private int $count;
			/** @var callable|null */
			private $completed;

			public function __construct( array $rows, int $count, ?callable $completed ) {
				$this->rows      = $rows;
				$this->count     = $count;
				$this->completed = $completed;
			}
			public function type(): string {
				return 'fake';
			}
			public function authorize_start(): void {}
			public function authorize_batch( array $job ): void {}
			public function authorize_download( array $job ): void {}
			public function job_owner_fields(): array {
				return array( 'user_id' => 1 );
			}
			public function sanitize_filters(): array {
				return array( 'x' => 1 );
			}
			public function count( array $filters ): int {
				return $this->count;
			}
			public function build_context( array $filters ): array {
				return array( 'k' => 'v' );
			}
			public function header( array $filters, array $context ): array {
				return array( 'A', 'B' );
			}
			public function filename( array $filters, array $context ): string {
				return 'fake.csv';
			}
			public function fetch_page( array $filters, array $context, int $cursor, int $size ): array {
				$out = array();
				foreach ( $this->rows as $r ) {
					if ( (int) $r['id'] < $cursor ) {
						$out[] = $r;
					}
				}
				return array_slice( $out, 0, $size );
			}
			public function cursor_of( array $row ): int {
				return (int) $row['id'];
			}
			public function format_row( array $row, array $context ): array {
				return array( $row['id'], $row['v'] );
			}
			public function extra_start_response( string $job_id, array $job ): array {
				return array();
			}
			public function on_complete( string $job_id, array $job ): void {
				if ( $this->completed ) {
					( $this->completed )( $job_id, $job );
				}
			}
		};
	}

	private function engine(): BatchedCsvExport {
		return new BatchedCsvExport( 'ffct_', 'ffc-x-' );
	}

	// ==================================================================
	// handle_start()
	// ==================================================================

	public function test_start_writes_header_and_returns_job(): void {
		$source   = $this->make_source( array(), 3 );
		$captured = null;
		try {
			$this->engine()->handle_start( $source );
			$this->fail( 'expected halt' );
		} catch ( JsonHalt $e ) {
			$captured = $e;
		}

		$this->assertSame( 'success', $captured->kind );
		$this->assertSame( 'job-1', $captured->data['job_id'] );
		$this->assertSame( 3, $captured->data['total'] );

		$this->assertArrayHasKey( 'ffct_job-1', $this->store );
		$file = $this->store['ffct_job-1']['file'];
		$this->assertFileExists( $file );
		$this->assertStringContainsString( 'A;B', (string) file_get_contents( $file ) );
	}

	public function test_start_errors_when_no_records(): void {
		$this->expectException( JsonHalt::class );
		try {
			$this->engine()->handle_start( $this->make_source( array(), 0 ) );
		} catch ( JsonHalt $e ) {
			$this->assertSame( 'error', $e->kind );
			throw $e;
		}
	}

	// ==================================================================
	// handle_batch()
	// ==================================================================

	public function test_batch_appends_rows_advances_cursor_not_done(): void {
		$file = $this->tmp_base . '/head.csv';
		file_put_contents( $file, "\xEF\xBB\xBFA;B\n" );

		$this->store['ffct_job-1'] = array(
			'type'      => 'fake',
			'filters'   => array(),
			'context'   => array(),
			'cursor'    => PHP_INT_MAX,
			'processed' => 0,
			'total'     => 2,
			'file'      => $file,
			'filename'  => 'fake.csv',
			'user_id'   => 1,
		);
		$_POST['job_id'] = 'job-1';

		$source = $this->make_source(
			array(
				array( 'id' => 2, 'v' => 'xx' ),
				array( 'id' => 1, 'v' => 'yy' ),
			),
			2
		);

		$captured = null;
		try {
			$this->engine()->handle_batch( $source );
		} catch ( JsonHalt $e ) {
			$captured = $e;
		}

		$this->assertSame( 'success', $captured->kind );
		$this->assertFalse( $captured->data['done'] );
		$this->assertSame( 2, $captured->data['processed'] );
		$this->assertSame( 1, $this->store['ffct_job-1']['cursor'], 'cursor advanced to last id' );

		$csv = (string) file_get_contents( $file );
		$this->assertStringContainsString( 'xx', $csv );
		$this->assertStringContainsString( 'yy', $csv );

		unset( $_POST['job_id'] );
	}

	public function test_batch_completes_and_fires_on_complete_when_empty(): void {
		$file = $this->tmp_base . '/head2.csv';
		file_put_contents( $file, "\xEF\xBB\xBFA;B\n" );

		$this->store['ffct_job-1'] = array(
			'type'      => 'fake',
			'filters'   => array(),
			'context'   => array(),
			'cursor'    => 0,
			'processed' => 5,
			'total'     => 5,
			'file'      => $file,
			'filename'  => 'fake.csv',
			'user_id'   => 1,
		);
		$_POST['job_id'] = 'job-1';

		$fired  = false;
		$source = $this->make_source(
			array(),
			5,
			static function () use ( &$fired ) {
				$fired = true;
			}
		);

		$captured = null;
		try {
			$this->engine()->handle_batch( $source );
		} catch ( JsonHalt $e ) {
			$captured = $e;
		}

		$this->assertTrue( $fired, 'on_complete fired' );
		$this->assertSame( 'success', $captured->kind );
		$this->assertTrue( $captured->data['done'] );

		unset( $_POST['job_id'] );
	}

	public function test_batch_errors_when_job_missing(): void {
		$_POST['job_id'] = 'nope';

		$this->expectException( JsonHalt::class );
		try {
			$this->engine()->handle_batch( $this->make_source( array(), 0 ) );
		} catch ( JsonHalt $e ) {
			$this->assertSame( 'error', $e->kind );
			unset( $_POST['job_id'] );
			throw $e;
		}
	}

	// ==================================================================
	// handle_download()
	// ==================================================================

	public function test_download_dies_when_job_missing(): void {
		$_GET['job_id'] = 'nope';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die' );
		try {
			$this->engine()->handle_download( $this->make_source( array(), 0 ) );
		} finally {
			unset( $_GET['job_id'] );
		}
	}

	public function test_download_dies_when_file_missing(): void {
		$this->store['ffct_job-1'] = array(
			'file'     => '/no/such/ffc-file.csv',
			'filename' => 'x.csv',
			'user_id'  => 1,
		);
		$_GET['job_id'] = 'job-1';

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'wp_die' );
		try {
			$this->engine()->handle_download( $this->make_source( array(), 0 ) );
		} finally {
			unset( $_GET['job_id'] );
		}
	}
}

/**
 * Marker exception carrying the wp_send_json_* payload so tests can inspect it.
 */
class JsonHalt extends \RuntimeException {

	/** @var string */
	public $kind;

	/** @var mixed */
	public $data;

	/**
	 * @param string $kind 'success' | 'error'.
	 * @param mixed  $data Payload.
	 */
	public function __construct( string $kind, $data ) {
		parent::__construct( 'json_' . $kind );
		$this->kind = $kind;
		$this->data = $data;
	}
}

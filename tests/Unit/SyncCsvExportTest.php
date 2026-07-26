<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\SyncCsvExport;
use FreeFormCertificate\Core\CsvStreamer;
use FreeFormCertificate\Core\CsvDownloadInterface;
use FreeFormCertificate\Core\SyncSourceInterface;

/**
 * Tests for the SyncCsvExport driver: it authorizes the source first, then
 * streams its filename + header + rows through the injected CsvStreamer. Driven
 * by a fake in-memory source + a buffered download double (nothing native is
 * stubbed). (Issue #772.)
 *
 * @covers \FreeFormCertificate\Core\SyncCsvExport
 */
class SyncCsvExportTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\\FreeFormCertificate\Core\SyncCsvExport' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** A buffered CsvDownloadInterface capturing the streamed bytes. */
	private function buffered_download(): CsvDownloadInterface {
		return new class() implements CsvDownloadInterface {
			public bool $finished = false;
			public string $output = '';
			public string $filename = '';
			/** @var resource|null */
			private $stream = null;
			public function send_headers( string $filename ): void {
				$this->filename = $filename;
			}
			public function open_stream() {
				if ( ! is_resource( $this->stream ) ) {
					$this->stream = fopen( 'php://memory', 'w+' );
				}
				return $this->stream;
			}
			public function finish(): void {
				$this->finished = true;
				if ( is_resource( $this->stream ) ) {
					rewind( $this->stream );
					$this->output = (string) stream_get_contents( $this->stream );
				}
			}
		};
	}

	/**
	 * A fake source; `$authorized` flips when authorize() runs so the test can
	 * prove the driver calls it before streaming.
	 */
	private function fake_source( bool &$authorized ): SyncSourceInterface {
		return new class( $authorized ) implements SyncSourceInterface {
			/** @var bool */
			private $flag;
			public function __construct( bool &$authorized ) {
				$this->flag = &$authorized;
			}
			public function authorize(): void {
				$this->flag = true;
			}
			public function filename(): string {
				return 'fake.csv';
			}
			public function header(): array {
				return array( 'A', 'B' );
			}
			public function rows(): iterable {
				yield array( '1', 'x' );
				yield array( '2', 'y' );
			}
		};
	}

	public function test_handle_authorizes_then_streams(): void {
		$authorized = false;
		$download   = $this->buffered_download();

		( new SyncCsvExport( new CsvStreamer( $download ) ) )->handle( $this->fake_source( $authorized ) );

		$this->assertTrue( $authorized, 'authorize() ran before streaming' );
		$this->assertTrue( $download->finished );
		$this->assertSame( 'fake.csv', $download->filename );
		$this->assertStringContainsString( 'A;B', $download->output, 'header row' );
		$this->assertStringContainsString( '1;x', $download->output, 'first data row' );
		$this->assertStringContainsString( '2;y', $download->output, 'second data row' );
	}
}

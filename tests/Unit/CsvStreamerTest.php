<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\CsvStreamer;
use FreeFormCertificate\Core\CsvDownloadInterface;

/**
 * Tests for CsvStreamer — the pure orchestration layer of the CSV export path.
 *
 * A buffered CsvDownloadInterface double captures the bytes (into php://memory)
 * instead of writing to php://output and calling exit, so the streaming path is
 * fully unit-testable — the whole point of the injectable output boundary.
 *
 * @covers \FreeFormCertificate\Core\CsvStreamer
 */
class CsvStreamerTest extends TestCase {

	/**
	 * A CsvDownloadInterface that writes to php://memory and records the result.
	 */
	private function buffered_download(): CsvDownloadInterface {
		return new class() implements CsvDownloadInterface {
			public string $filename = '';
			public bool $finished   = false;
			public string $output   = '';
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

	public function test_stream_writes_header_and_rows_then_finishes(): void {
		$download = $this->buffered_download();

		( new CsvStreamer( $download ) )->stream(
			'report.csv',
			array( 'ID', 'Name' ),
			array( array( '1', 'Ana' ), array( '2', 'Bruno' ) )
		);

		$this->assertSame( 'report.csv', $download->filename, 'filename passed to send_headers' );
		$this->assertTrue( $download->finished, 'finish() was called' );
		// CsvWriter defaults: UTF-8 BOM once + ';' delimiter.
		$this->assertStringContainsString( 'ID;Name', $download->output );
		$this->assertStringContainsString( '1;Ana', $download->output );
		$this->assertStringContainsString( '2;Bruno', $download->output );
	}

	public function test_stream_consumes_a_generator_lazily(): void {
		$download = $this->buffered_download();

		$yielded = 0;
		$rows    = ( function () use ( &$yielded ) {
			foreach ( array( array( 'a' ), array( 'b' ) ) as $row ) {
				++$yielded;
				yield $row;
			}
		} )();

		( new CsvStreamer( $download ) )->stream( 'g.csv', array( 'H' ), $rows );

		$this->assertSame( 2, $yielded, 'the generator was fully consumed' );
		$this->assertStringContainsString( 'H', $download->output );
		$this->assertStringContainsString( 'a', $download->output );
		$this->assertStringContainsString( 'b', $download->output );
	}

	public function test_stream_with_no_rows_still_writes_the_header(): void {
		$download = $this->buffered_download();

		( new CsvStreamer( $download ) )->stream( 'empty.csv', array( 'Only', 'Header' ), array() );

		$this->assertTrue( $download->finished );
		$this->assertStringContainsString( 'Only;Header', $download->output );
	}
}

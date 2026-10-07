<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Generators\QrLogo;

/**
 * QrLogo (#1563): a Media Library image becomes an embeddable data URI, and
 * anything that is not a small raster image becomes nothing.
 *
 * @covers \FreeFormCertificate\Generators\QrLogo
 */
class QrLogoTest extends TestCase {

	/** @var string */
	private string $file = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		QrLogo::reset();
		$this->file = (string) tempnam( sys_get_temp_dir(), 'ffc_logo_' );
		file_put_contents( $this->file, 'PNGBYTES' );
	}

	protected function tearDown(): void {
		@unlink( $this->file );
		QrLogo::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function attachment( string $mime, string $path ): void {
		Functions\when( 'get_post_mime_type' )->justReturn( $mime );
		Functions\when( 'get_attached_file' )->justReturn( $path );
	}

	public function test_a_raster_image_becomes_a_data_uri(): void {
		$this->attachment( 'image/png', $this->file );

		$this->assertSame( 'data:image/png;base64,' . base64_encode( 'PNGBYTES' ), QrLogo::data_uri( 7 ) );
	}

	public function test_no_id_means_no_logo(): void {
		Functions\expect( 'get_post_mime_type' )->never();

		$this->assertSame( '', QrLogo::data_uri( 0 ) );
	}

	public function test_an_svg_is_refused(): void {
		// An SVG logo would be markup inside the QR markup.
		$this->attachment( 'image/svg+xml', $this->file );

		$this->assertSame( '', QrLogo::data_uri( 7 ) );
	}

	public function test_a_missing_file_is_refused(): void {
		$this->attachment( 'image/png', $this->file . '-gone' );

		$this->assertSame( '', QrLogo::data_uri( 7 ) );
	}

	public function test_a_file_over_the_cap_is_refused(): void {
		file_put_contents( $this->file, str_repeat( 'x', QrLogo::MAX_BYTES + 1 ) );
		$this->attachment( 'image/jpeg', $this->file );

		$this->assertSame( '', QrLogo::data_uri( 7 ) );
	}

	public function test_an_empty_file_is_refused(): void {
		file_put_contents( $this->file, '' );
		$this->attachment( 'image/png', $this->file );

		$this->assertSame( '', QrLogo::data_uri( 7 ) );
	}

	public function test_the_file_is_read_once_per_request(): void {
		Functions\expect( 'get_post_mime_type' )->once()->andReturn( 'image/webp' );
		Functions\expect( 'get_attached_file' )->once()->andReturn( $this->file );

		$first = QrLogo::data_uri( 9 );
		$this->assertSame( $first, QrLogo::data_uri( 9 ) );
		$this->assertStringStartsWith( 'data:image/webp;base64,', $first );
	}
}

<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use FreeFormCertificate\Generators\QrCapacity;
use FreeFormCertificate\Generators\QrSvgRenderer;
use PHPUnit\Framework\TestCase;

/**
 * The QR codes the plugin draws read back as exactly the text encoded
 * (#2648).
 *
 * Every other QR test measures the grid: how many modules, how many pixels.
 * None proves that a reader recovers the text, and a signed payload is
 * fragile in exactly that way, since one wrong byte reads as a forged
 * signature (#2639). So this test encodes with the plugin's own path
 * (phpqrcode, through `QrSvgRenderer::matrix()` for the SVG and `QRcode::png()`
 * for the PNG) and decodes with an independent reader, chillerlan/php-qrcode,
 * a dev-only dependency that never ships.
 *
 * @covers \FreeFormCertificate\Generators\QrSvgRenderer::matrix
 * @covers \FreeFormCertificate\Generators\QrCapacity::free_level
 */
final class QrRoundTripTest extends TestCase {

	/** Pixels per module in the images the reader decodes. */
	private const SCALE = 4;

	/**
	 * Every text the plugin encodes, by kind.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function texts(): array {
		$vector = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/signed-qr-vector.json' ), true );
		$link   = 'https://www.exemplo.sp.gov.br/valid/#token=' . str_repeat( 'a', 32 );

		return array(
			'link only'                    => array( $link ),
			'signed, compact'              => array( $link . '&s=' . $vector['compact']['s'] ),
			'signed, full'                 => array( $link . '&s=' . $vector['full']['s'] ),
			// The QR generator encodes free text and vCards as they are typed.
			'accents and escaped separator' => array( 'José | Maria\\ Ação – Formação em Libras' ),
			'short URL'                    => array( 'https://exemplo.test/s/Ab3dE' ),
		);
	}

	/**
	 * A module matrix drawn as the PNG a camera would see: black modules on
	 * white, with a four-module quiet zone.
	 *
	 * @param array<int, array<int, bool>> $matrix Modules, dark = true.
	 */
	private static function png( array $matrix ): string {
		$quiet = 4;
		$side  = ( count( $matrix ) + 2 * $quiet ) * self::SCALE;
		$image = imagecreatetruecolor( $side, $side );
		$white = (int) imagecolorallocate( $image, 255, 255, 255 );
		$black = (int) imagecolorallocate( $image, 0, 0, 0 );
		imagefill( $image, 0, 0, $white );
		foreach ( $matrix as $y => $row ) {
			foreach ( $row as $x => $dark ) {
				if ( $dark ) {
					$left = ( $x + $quiet ) * self::SCALE;
					$top  = ( $y + $quiet ) * self::SCALE;
					imagefilledrectangle( $image, $left, $top, $left + self::SCALE - 1, $top + self::SCALE - 1, $black );
				}
			}
		}
		ob_start();
		imagepng( $image );
		return (string) ob_get_clean();
	}

	/**
	 * The text an independent reader recovers from an image.
	 *
	 * @param string $png PNG bytes.
	 */
	private static function read( string $png ): string {
		$reader = new QRCode( new QROptions( array( 'readerUseImagickIfAvailable' => false ) ) );
		return (string) $reader->readFromBlob( $png );
	}

	/**
	 * @dataProvider texts
	 *
	 * @param string $text What the plugin encodes.
	 */
	public function test_the_svg_path_reads_back_exactly_at_every_level( string $text ): void {
		foreach ( array( 'L', 'M', 'Q', 'H' ) as $level ) {
			$matrix = QrSvgRenderer::matrix( $text, $level );
			$this->assertNotSame( array(), $matrix, 'Precondition: the text was encoded at ' . $level . '.' );

			$this->assertSame( $text, self::read( self::png( $matrix ) ), 'Level ' . $level );
		}
	}

	/**
	 * The PNG the certificate and the short URL draw, at the quiet zone the
	 * default models print (2 modules) and at none.
	 *
	 * @dataProvider texts
	 *
	 * @param string $text What the plugin encodes.
	 */
	public function test_the_png_path_reads_back_exactly( string $text ): void {
		foreach ( array( 2, 0 ) as $margin ) {
			$file = (string) tempnam( sys_get_temp_dir(), 'ffc-qr-' );
			\QRcode::png( $text, $file, \QR_ECLEVEL_M, self::SCALE, $margin );
			$png = (string) file_get_contents( $file );
			unlink( $file );

			// A PNG drawn with no quiet zone is read on a page with room
			// around it, as the certificate prints it: pad it the same way.
			if ( 0 === $margin ) {
				$png = self::padded( $png );
			}

			$this->assertSame( $text, self::read( $png ), 'Margin ' . $margin );
		}
	}

	/**
	 * A code drawn at the level raised for free (#2648) reads back too.
	 *
	 * @dataProvider texts
	 *
	 * @param string $text What the plugin encodes.
	 */
	public function test_a_code_at_the_free_level_reads_back_exactly( string $text ): void {
		$level = QrCapacity::free_level( $text, 'M' );

		$this->assertSame( $text, self::read( self::png( QrSvgRenderer::matrix( $text, $level ) ) ), 'Level ' . $level );
	}

	public function test_a_code_does_not_read_as_another_text(): void {
		list( $first ) = self::texts()['signed, full'];
		list( $other ) = self::texts()['signed, compact'];

		$this->assertNotSame( $other, self::read( self::png( QrSvgRenderer::matrix( $first, 'M' ) ) ), 'The reader returns what was encoded, not any valid text.' );
	}

	/**
	 * A PNG placed on a white page, with four modules of room on each side.
	 *
	 * @param string $png PNG bytes.
	 */
	private static function padded( string $png ): string {
		$source = imagecreatefromstring( $png );
		if ( false === $source ) {
			throw new \RuntimeException( 'phpqrcode wrote no PNG.' );
		}
		$room  = 4 * self::SCALE;
		$width = imagesx( $source ) + 2 * $room;
		$page  = imagecreatetruecolor( $width, imagesy( $source ) + 2 * $room );
		imagefill( $page, 0, 0, (int) imagecolorallocate( $page, 255, 255, 255 ) );
		imagecopy( $page, $source, $room, $room, 0, 0, imagesx( $source ), imagesy( $source ) );
		ob_start();
		imagepng( $page );
		return (string) ob_get_clean();
	}
}

<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Generators\QrDesign;
use FreeFormCertificate\Generators\QrSvgRenderer;

/**
 * The styled QR renderer (#1563): the matrix it reads, and what each design
 * option draws.
 *
 * Whether the drawn codes SCAN is not something a unit test can show; it was
 * measured when the renderer was written, by rasterising every combination of
 * shapes and a gradient in Chromium and decoding them with ZXing (160 of 160).
 * What these tests pin is the geometry that measurement relied on.
 *
 * @covers \FreeFormCertificate\Generators\QrSvgRenderer
 */
class QrSvgRendererTest extends TestCase {

	private const URL = 'https://example.com/valid?code=ABCD-1234';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( array() );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Count the dark modules in a matrix.
	 *
	 * @param array<int, array<int, bool>> $matrix Matrix.
	 */
	private function dark( array $matrix ): int {
		$count = 0;
		foreach ( $matrix as $row ) {
			$count += count( array_filter( $row ) );
		}
		return $count;
	}

	public function test_matrix_reads_the_binarised_bits_not_the_raw_frame(): void {
		$matrix = QrSvgRenderer::matrix( self::URL, 'M' );
		$n      = count( $matrix );

		$this->assertGreaterThanOrEqual( 21, $n );
		$this->assertSame( 0, ( $n - 17 ) % 4, 'A QR side is 17 + 4 * version.' );

		// The raw frame flags function patterns in its high bits, so testing
		// a byte for truth paints every module dark -- the short URL SVG
		// download's defect. A real code is roughly half dark.
		$dark = $this->dark( $matrix );
		$this->assertLessThan( $n * $n, $dark );
		$this->assertGreaterThan( (int) ( $n * $n * 0.3 ), $dark );
		$this->assertLessThan( (int) ( $n * $n * 0.7 ), $dark );
	}

	public function test_matrix_carries_the_finder_pattern(): void {
		$matrix = QrSvgRenderer::matrix( self::URL, 'M' );

		// Top-left finder: a dark 7x7 ring, a light ring, a dark 3x3 centre.
		$this->assertTrue( $matrix[0][0] );
		$this->assertTrue( $matrix[0][6] );
		$this->assertFalse( $matrix[1][1] );
		$this->assertTrue( $matrix[3][3] );
	}

	public function test_higher_error_correction_needs_a_larger_matrix(): void {
		$text = str_repeat( 'abcdefghij', 8 );

		$this->assertGreaterThan(
			count( QrSvgRenderer::matrix( $text, 'L' ) ),
			count( QrSvgRenderer::matrix( $text, 'H' ) )
		);
	}

	public function test_an_unknown_error_level_falls_back_to_medium(): void {
		$this->assertSame( QrSvgRenderer::matrix( self::URL, 'M' ), QrSvgRenderer::matrix( self::URL, 'Z' ) );
	}

	public function test_empty_text_renders_nothing(): void {
		$this->assertSame( array(), QrSvgRenderer::matrix( '', 'M' ) );
		$this->assertSame( '', QrSvgRenderer::render( '', QrDesign::plain() ) );
	}

	public function test_text_beyond_capacity_renders_nothing_rather_than_failing(): void {
		$this->assertSame( '', QrSvgRenderer::render( str_repeat( 'x', 8000 ), QrDesign::plain(), 'H' ) );
	}

	public function test_plain_render_draws_one_square_per_dark_module_outside_the_finders(): void {
		$matrix = QrSvgRenderer::matrix( self::URL, 'M' );
		$svg    = QrSvgRenderer::render_matrix( $matrix, QrDesign::plain(), 2 );

		// The three finders (33 dark modules each) are drawn as shapes of their
		// own, so the module group holds every other dark module.
		$this->assertSame( $this->dark( $matrix ) - 3 * 33, substr_count( $svg, '<rect x=' ) - 6 );
		$this->assertStringContainsString( 'fill="#000000"', $svg );
		$this->assertStringContainsString( '<rect width="', $svg );
		$this->assertStringContainsString( 'fill="#ffffff"', $svg );
	}

	public function test_viewbox_includes_the_quiet_zone_and_size_sets_the_dimensions(): void {
		$matrix = QrSvgRenderer::matrix( self::URL, 'M' );
		$box    = ( count( $matrix ) + 2 * 4 ) * 10;

		$svg = QrSvgRenderer::render_matrix( $matrix, QrDesign::plain(), 4, 300 );
		$this->assertStringContainsString( 'viewBox="0 0 ' . $box . ' ' . $box . '"', $svg );
		$this->assertStringContainsString( 'width="300" height="300"', $svg );

		$this->assertStringNotContainsString( 'width="300"', QrSvgRenderer::render_matrix( $matrix, QrDesign::plain(), 4, 0 ) );
	}

	public function test_margin_is_clamped(): void {
		$matrix = QrSvgRenderer::matrix( self::URL, 'M' );
		$box    = ( count( $matrix ) + 20 ) * 10;

		$this->assertStringContainsString( 'viewBox="0 0 ' . $box . ' ', QrSvgRenderer::render_matrix( $matrix, QrDesign::plain(), 99 ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function module_shapes(): array {
		return array(
			'dots'    => array( 'dots', '<circle' ),
			'diamond' => array( 'diamond', '<path d="M' ),
			'rounded' => array( 'rounded', 'rx="3"' ),
			'fluid'   => array( 'fluid', '<circle' ),
		);
	}

	/**
	 * @dataProvider module_shapes
	 */
	public function test_each_module_shape_draws_its_own_primitive( string $dots, string $needle ): void {
		$svg   = QrSvgRenderer::render( self::URL, new QrDesign( array( 'dots' => $dots ) ) );
		$group = substr( $svg, strpos( $svg, '<g fill=' ) );

		$this->assertStringContainsString( $needle, $group );
	}

	public function test_fluid_bridges_horizontal_and_vertical_neighbours(): void {
		$matrix = array_fill( 0, 21, array_fill( 0, 21, false ) );
		// Two modules side by side and one below, away from the finders.
		$matrix[10][10] = true;
		$matrix[10][11] = true;
		$matrix[11][10] = true;

		$svg = QrSvgRenderer::render_matrix( $matrix, new QrDesign( array( 'dots' => 'fluid' ) ), 0 );

		$this->assertSame( 3, substr_count( $svg, '<circle' ) );
		// (10,10) bridges right and down; (10,11) and (11,10) have no dark
		// neighbour further on.
		$this->assertStringContainsString( '<rect x="105" y="100" width="10" height="10"/>', $svg );
		$this->assertStringContainsString( '<rect x="100" y="105" width="10" height="10"/>', $svg );
	}

	public function test_eyes_follow_their_shapes_and_colours(): void {
		$design = new QrDesign(
			array(
				'eye_frame'       => 'circle',
				'eye_ball'        => 'circle',
				'eye_frame_color' => '#8a2be2',
				'eye_ball_color'  => '#0b6b3a',
			)
		);
		$svg    = QrSvgRenderer::render( self::URL, $design );

		$this->assertSame( 3, substr_count( $svg, 'stroke="#8a2be2"' ) );
		$this->assertSame( 3, substr_count( $svg, 'rx="30" fill="none"' ) );
		$this->assertSame( 3, substr_count( $svg, 'fill="#0b6b3a"' ) );
	}

	public function test_leaf_frame_and_diamond_ball(): void {
		$svg = QrSvgRenderer::render( self::URL, new QrDesign( array( 'eye_frame' => 'leaf', 'eye_ball' => 'diamond' ) ) );

		$this->assertSame( 3, substr_count( $svg, 'Q' ) / 2 );
		$this->assertSame( 6, substr_count( $svg, '<path d="M' ) );
	}

	public function test_rounded_eye_frame_and_ball(): void {
		$svg = QrSvgRenderer::render( self::URL, new QrDesign( array( 'eye_frame' => 'rounded', 'eye_ball' => 'rounded' ) ) );

		$this->assertSame( 3, substr_count( $svg, 'rx="18" fill="none"' ) );
		$this->assertSame( 3, substr_count( $svg, 'rx="8" fill="#000000"' ) );
	}

	public function test_a_gradient_spans_the_whole_code_not_each_module(): void {
		$svg = QrSvgRenderer::render( self::URL, new QrDesign( array( 'gradient' => true, 'color' => '#111111', 'color_end' => '#2271b1' ) ) );

		$this->assertStringContainsString( 'gradientUnits="userSpaceOnUse"', $svg );
		$this->assertStringContainsString( 'stop-color="#2271b1"', $svg );
		$this->assertStringContainsString( '<g fill="url(#ffc-qr-gradient)">', $svg );
	}

	public function test_no_gradient_markup_without_a_gradient(): void {
		$this->assertStringNotContainsString( '<defs>', QrSvgRenderer::render( self::URL, QrDesign::plain() ) );
	}

	public function test_coordinates_never_use_a_locale_decimal_comma(): void {
		$previous = setlocale( LC_NUMERIC, '0' );
		setlocale( LC_NUMERIC, 'pt_BR.UTF-8', 'de_DE.UTF-8' );

		$svg = QrSvgRenderer::render( self::URL, new QrDesign( array( 'dots' => 'dots' ) ) );

		setlocale( LC_NUMERIC, (string) $previous );
		$this->assertDoesNotMatchRegularExpression( '/="\d+,\d+"/', $svg );
		$this->assertStringContainsString( 'r="4.2"', $svg );
	}

	public function test_a_logo_clears_the_centre_and_raises_the_error_correction(): void {
		$logo   = new QrDesign( array( 'logo' => 'data:image/png;base64,AAAA' ) );
		$plain  = QrSvgRenderer::render( self::URL, QrDesign::plain(), 'L' );
		$framed = QrSvgRenderer::render( self::URL, $logo, 'L' );

		$this->assertStringContainsString( '<image href="data:image/png;base64,AAAA"', $framed );
		// H needs a larger symbol than L for the same payload.
		preg_match( '/viewBox="0 0 (\d+)/', $plain, $p );
		preg_match( '/viewBox="0 0 (\d+)/', $framed, $f );
		$this->assertGreaterThan( (int) $p[1], (int) $f[1] );
	}

	public function test_no_module_is_drawn_under_the_logo(): void {
		$matrix = array_fill( 0, 25, array_fill( 0, 25, true ) );
		$svg    = QrSvgRenderer::render_matrix( $matrix, new QrDesign( array( 'logo' => 'data:image/png;base64,AAAA' ) ), 0 );

		// 25 * 0.22 = 5 modules (odd), starting at 10, plus a one-module ring:
		// rows and columns 9..15 stay empty, so (12,12) and (9,9) are not drawn
		// while (8,8) is.
		$this->assertStringNotContainsString( '<rect x="120" y="120" width="10" height="10"/>', $svg );
		$this->assertStringNotContainsString( '<rect x="90" y="90" width="10" height="10"/>', $svg );
		$this->assertStringContainsString( '<rect x="80" y="80" width="10" height="10"/>', $svg );
		$this->assertStringContainsString( '<rect x="100" y="100" width="50" height="50" rx="10"', $svg );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function frames(): array {
		return array(
			'banner' => array( 'banner' ),
			'badge'  => array( 'badge' ),
			'bubble' => array( 'bubble' ),
		);
	}

	/**
	 * @dataProvider frames
	 */
	public function test_a_frame_makes_the_document_taller_and_reports_its_height( string $frame ): void {
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		$design = new QrDesign( array( 'frame' => $frame, 'frame_text' => 'Scan & <verify>' ) );

		$drawn = QrSvgRenderer::render_sized( self::URL, $design, 'M', 2, 300 );

		$this->assertSame( 300, $drawn['width'] );
		$this->assertGreaterThan( 300, $drawn['height'] );
		$this->assertStringContainsString( 'width="300" height="' . $drawn['height'] . '"', $drawn['svg'] );
		// The caption is escaped text, never markup.
		$this->assertStringContainsString( '>Scan &amp; &lt;verify&gt;</text>', $drawn['svg'] );
	}

	public function test_banner_and_bubble_captions_read_on_their_frame(): void {
		Functions\when( 'wp_strip_all_tags' )->returnArg();

		$dark  = QrSvgRenderer::render( self::URL, new QrDesign( array( 'frame' => 'banner', 'frame_text' => 'Scan', 'frame_color' => '#1d2327' ) ) );
		$light = QrSvgRenderer::render( self::URL, new QrDesign( array( 'frame' => 'bubble', 'frame_text' => 'Scan', 'frame_color' => '#ffd700' ) ) );

		$this->assertMatchesRegularExpression( '/fill="#ffffff" font-family/', $dark );
		$this->assertMatchesRegularExpression( '/fill="#000000" font-family/', $light );
	}

	public function test_a_frame_without_caption_draws_no_text(): void {
		$svg = QrSvgRenderer::render( self::URL, new QrDesign( array( 'frame' => 'badge' ) ) );

		$this->assertStringNotContainsString( '<text', $svg );
		$this->assertStringContainsString( 'stroke="#1d2327"', $svg );
	}

	public function test_a_long_caption_shrinks_to_fit(): void {
		Functions\when( 'wp_strip_all_tags' )->returnArg();

		$short = QrSvgRenderer::render( self::URL, new QrDesign( array( 'frame' => 'banner', 'frame_text' => 'Scan' ) ) );
		$long  = QrSvgRenderer::render( self::URL, new QrDesign( array( 'frame' => 'banner', 'frame_text' => str_repeat( 'W', 24 ) ) ) );

		preg_match( '/font-size="([\d.]+)"/', $short, $s );
		preg_match( '/font-size="([\d.]+)"/', $long, $l );
		$this->assertLessThan( (float) $s[1], (float) $l[1] );
	}
}

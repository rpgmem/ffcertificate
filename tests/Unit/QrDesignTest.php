<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Generators\QrDesign;

/**
 * QrDesign (#1563): normalisation, the settings read, where it applies, and
 * the scan checks.
 *
 * @covers \FreeFormCertificate\Generators\QrDesign
 */
class QrDesignTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( array() );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_plain_is_black_squares_on_white_with_no_gradient(): void {
		$this->assertSame(
			array(
				'dots'            => 'square',
				'eye_frame'       => 'square',
				'eye_ball'        => 'square',
				'color'           => '#000000',
				'gradient'        => false,
				'color_end'       => '',
				'background'      => '#ffffff',
				'eye_frame_color' => '#000000',
				'eye_ball_color'  => '#000000',
				'logo'            => '',
				'frame'           => 'none',
				'frame_text'      => '',
				'frame_color'     => '#1d2327',
			),
			QrDesign::plain()->to_array()
		);
	}

	public function test_unknown_shapes_and_malformed_colours_fall_back(): void {
		$design = new QrDesign(
			array(
				'dots'       => 'star',
				'eye_frame'  => array( 'circle' ),
				'eye_ball'   => '<script>',
				'color'      => 'red',
				'background' => '#fff',
				'gradient'   => true,
				'color_end'  => 'url(#x)',
			)
		);

		$this->assertSame( 'square', $design->dots );
		$this->assertSame( 'square', $design->eye_frame );
		$this->assertSame( 'square', $design->eye_ball );
		$this->assertSame( '#000000', $design->color );
		$this->assertSame( '#ffffff', $design->background );
		// A broken gradient end falls back to the module colour: still a
		// gradient, just a flat one, rather than a value the SVG would carry.
		$this->assertSame( '#000000', $design->color_end );
	}

	public function test_colours_are_lowercased_and_eyes_default_to_the_module_colour(): void {
		$design = new QrDesign( array( 'color' => ' #1D2327 ' ) );

		$this->assertSame( '#1d2327', $design->color );
		$this->assertSame( '#1d2327', $design->eye_frame_color );
		$this->assertSame( '#1d2327', $design->eye_ball_color );
	}

	public function test_gradient_end_is_ignored_while_the_gradient_is_off(): void {
		$this->assertSame( '', ( new QrDesign( array( 'color_end' => '#2271b1' ) ) )->color_end );
		$this->assertSame( '#2271b1', ( new QrDesign( array( 'gradient' => '1', 'color_end' => '#2271b1' ) ) )->color_end );
	}

	public function test_from_settings_reads_the_qr_design_keys(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'qr_design_dots'            => 'dots',
				'qr_design_eye_frame'       => 'leaf',
				'qr_design_eye_ball'        => 'diamond',
				'qr_design_color'           => '#112233',
				'qr_design_gradient'        => 1,
				'qr_design_color_end'       => '#445566',
				'qr_design_background'      => '#fafafa',
				'qr_design_eye_frame_color' => '#778899',
				'qr_design_eye_ball_color'  => '#aabbcc',
			)
		);

		$this->assertSame(
			array(
				'dots'            => 'dots',
				'eye_frame'       => 'leaf',
				'eye_ball'        => 'diamond',
				'color'           => '#112233',
				'gradient'        => true,
				'color_end'       => '#445566',
				'background'      => '#fafafa',
				'eye_frame_color' => '#778899',
				'eye_ball_color'  => '#aabbcc',
				'logo'            => '',
				'frame'           => 'none',
				'frame_text'      => '',
				'frame_color'     => '#1d2327',
			),
			QrDesign::from_settings()->to_array()
		);
	}

	public function test_applies_to_reads_one_switch_per_surface(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'qr_design_on_certificate' => true,
				'qr_design_on_short_urls'  => 0,
				'qr_design_dots'           => 'dots',
			)
		);

		$this->assertTrue( QrDesign::applies_to( 'certificate' ) );
		$this->assertFalse( QrDesign::applies_to( 'short_urls' ) );
		$this->assertFalse( QrDesign::applies_to( 'magic_link' ) );

		$this->assertSame( 'dots', QrDesign::for_surface( 'certificate' )->dots );
		$this->assertSame( 'square', QrDesign::for_surface( 'short_urls' )->dots );
	}

	public function test_nothing_applies_on_a_fresh_install(): void {
		foreach ( QrDesign::SURFACES as $surface ) {
			$this->assertFalse( QrDesign::applies_to( $surface ), $surface );
		}
	}

	public function test_scan_checks_pass_for_the_plain_design(): void {
		$this->assertSame(
			array(
				'inverted'     => false,
				'low_contrast' => false,
				'min_ratio'    => 21.0,
				'caption_contrast' => true,
			),
			QrDesign::plain()->scan_checks()
		);
	}

	public function test_scan_checks_flag_an_inverted_code(): void {
		$checks = ( new QrDesign( array( 'color' => '#ffffff', 'background' => '#000000' ) ) )->scan_checks();

		$this->assertTrue( $checks['inverted'] );
		$this->assertFalse( $checks['low_contrast'] );
	}

	public function test_scan_checks_take_the_faintest_foreground(): void {
		// The modules are fine; one pale eye is enough to lose the finder.
		$checks = ( new QrDesign( array( 'eye_ball_color' => '#cccccc' ) ) )->scan_checks();

		$this->assertTrue( $checks['low_contrast'] );
		$this->assertFalse( $checks['inverted'] );
		$this->assertLessThan( QrDesign::MIN_CONTRAST, $checks['min_ratio'] );
	}

	public function test_scan_checks_include_the_gradient_end(): void {
		$checks = ( new QrDesign( array( 'gradient' => true, 'color_end' => '#eeeeee' ) ) )->scan_checks();

		$this->assertTrue( $checks['low_contrast'] );
	}

	public function test_hex_accepts_only_full_lowercase_hex(): void {
		$this->assertSame( '#abcdef', QrDesign::hex( '#ABCDEF', '#000000' ) );
		$this->assertSame( '#000000', QrDesign::hex( '#abc', '#000000' ) );
		$this->assertSame( '#000000', QrDesign::hex( 123, '#000000' ) );
	}

	public function test_only_a_raster_data_uri_is_kept_as_logo(): void {
		$png = 'data:image/png;base64,iVBORw0KGgo=';

		$this->assertSame( $png, ( new QrDesign( array( 'logo' => $png ) ) )->logo );
		$this->assertSame( '', ( new QrDesign( array( 'logo' => 'data:image/svg+xml;base64,PHN2Zz4=' ) ) )->logo );
		$this->assertSame( '', ( new QrDesign( array( 'logo' => 'https://evil.example/x.png' ) ) )->logo );
		$this->assertSame( '', ( new QrDesign( array( 'logo' => 'data:image/png;base64,"/><script>' ) ) )->logo );
	}

	public function test_a_logo_forces_error_correction_to_h(): void {
		$this->assertSame( 'M', QrDesign::plain()->error_level( 'M' ) );
		$this->assertSame( 'H', ( new QrDesign( array( 'logo' => 'data:image/png;base64,AAAA' ) ) )->error_level( 'L' ) );
	}

	public function test_frame_and_caption_are_normalised(): void {
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => strip_tags( $s ) );

		$design = new QrDesign(
			array(
				'frame'       => 'banner',
				'frame_text'  => "  <b>Scan</b>\n to   verify this certificate now ",
				'frame_color' => '#AA0000',
			)
		);

		$this->assertSame( 'banner', $design->frame );
		$this->assertSame( 'Scan to verify this cert', $design->frame_text );
		$this->assertSame( QrDesign::FRAME_TEXT_MAX, mb_strlen( $design->frame_text ) );
		$this->assertSame( '#aa0000', $design->frame_color );
		$this->assertSame( 'none', ( new QrDesign( array( 'frame' => 'polaroid' ) ) )->frame );
	}

	public function test_a_badge_caption_that_fades_into_the_background_is_flagged(): void {
		Functions\when( 'wp_strip_all_tags' )->returnArg();

		$pale  = new QrDesign( array( 'frame' => 'badge', 'frame_text' => 'Scan', 'frame_color' => '#dddddd' ) );
		$dark  = new QrDesign( array( 'frame' => 'badge', 'frame_text' => 'Scan', 'frame_color' => '#1d2327' ) );
		$other = new QrDesign( array( 'frame' => 'banner', 'frame_text' => 'Scan', 'frame_color' => '#dddddd' ) );

		$this->assertFalse( $pale->scan_checks()['caption_contrast'] );
		$this->assertTrue( $dark->scan_checks()['caption_contrast'] );
		// The banner picks a readable caption colour itself.
		$this->assertTrue( $other->scan_checks()['caption_contrast'] );
	}
}

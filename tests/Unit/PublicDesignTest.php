<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Core\PublicDesign;
use PHPUnit\Framework\TestCase;

/**
 * The public design: what is stored, the stylesheet it prints, where that
 * stylesheet is appended, and the contrast it reports.
 *
 * @covers \FreeFormCertificate\Core\PublicDesign
 */
class PublicDesignTest extends TestCase {

	/**
	 * What get_option( 'ffc_settings' ) returns.
	 *
	 * @var array<string, mixed>
	 */
	private array $stored = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_option' )->alias( fn() => $this->stored );
		Functions\when( 'is_admin' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_off_by_default_prints_nothing(): void {
		$design = PublicDesign::from_settings();
		$this->assertFalse( $design->enabled );
		$this->assertSame( '', $design->css() );
	}

	public function test_the_defaults_match_the_stylesheet_and_the_declared_settings(): void {
		$common = (string) file_get_contents( __DIR__ . '/../../assets/css/ffc-common.css' );
		$light  = substr( $common, 0, (int) strpos( $common, ':root.ffc-dark-mode' ) );
		foreach (
			array(
				'accent' => '--ffc-primary',
				'border' => '--ffc-border',
				'muted'  => '--ffc-text-muted',
				'error'  => '--ffc-danger',
			) as $field => $token
		) {
			$this->assertMatchesRegularExpression( '/' . preg_quote( $token, '/' ) . ':\s*' . preg_quote( PublicDesign::DEFAULTS[ $field ], '/' ) . ';/i', $light, "{$field} defaults to the light {$token}." );
		}
		$this->assertMatchesRegularExpression( '/--ffc-radius-sm:\s*' . PublicDesign::DEFAULTS['radius'] . 'px;/', $light );
		$this->assertMatchesRegularExpression( '/--ffc-form-width:\s*' . PublicDesign::DEFAULTS['width'] . 'px;/', $light );
	}

	public function test_invalid_values_take_the_defaults(): void {
		$design = new PublicDesign(
			array(
				'enabled' => '1',
				'accent'  => 'red',
				'border'  => '#12',
				'muted'   => '#ABCDEF',
				'radius'  => '99',
				'width'   => '10',
				'font'    => 'comic',
			)
		);
		$this->assertSame( PublicDesign::DEFAULTS['accent'], $design->accent );
		$this->assertSame( PublicDesign::DEFAULTS['border'], $design->border );
		$this->assertSame( '#abcdef', $design->muted, 'A valid colour is kept, lower-cased.' );
		$this->assertSame( PublicDesign::RADIUS_MAX, $design->radius );
		$this->assertSame( PublicDesign::WIDTH_MIN, $design->width );
		$this->assertSame( 'theme', $design->font );
	}

	public function test_a_cleared_number_keeps_its_default(): void {
		$design = new PublicDesign(
			array(
				'enabled' => '1',
				'radius'  => '',
				'width'   => '',
			)
		);
		$this->assertSame( PublicDesign::DEFAULTS['radius'], $design->radius, 'A cleared field is not square corners.' );
		$this->assertSame( PublicDesign::DEFAULTS['width'], $design->width );
		$this->assertSame( 0, ( new PublicDesign( array( 'enabled' => '1', 'radius' => '0' ) ) )->radius, 'A typed 0 is a choice.' );
	}

	public function test_switching_off_resets_every_value(): void {
		$design = new PublicDesign(
			array(
				'enabled' => '',
				'accent'  => '#7a1f2b',
				'radius'  => '0',
				'font'    => 'plugin',
			)
		);
		$this->assertSame( PublicDesign::DEFAULTS['accent'], $design->accent );
		$this->assertSame( PublicDesign::DEFAULTS['radius'], $design->radius );
		$this->assertSame( 'theme', $design->font );
		$this->assertSame( 0, $design->to_settings()['public_design'] );
	}

	public function test_the_colours_apply_to_the_light_theme_only(): void {
		$css = ( new PublicDesign(
			array(
				'enabled' => '1',
				'accent'  => '#7a1f2b',
				'radius'  => '0',
				'width'   => '800',
				'font'    => 'plugin',
			)
		) )->css();

		$this->assertStringContainsString( '.ffc-shortcode{--ffc-form-width:800px;--ffc-radius-sm:0px;--ffc-radius-md:0px;font-family:var(--ffc-font-family);}', $css, 'Shape and font in both themes.' );
		$this->assertStringContainsString( ':root:not(.ffc-dark-mode) .ffc-shortcode{--ffc-primary:#7a1f2b;', $css, 'Colours scoped away from the dark theme.' );
		$this->assertStringContainsString( '--ffc-text-on-primary:#ffffff;', $css, 'White reads best on a dark accent.' );
		$this->assertStringContainsString( '--ffc-focus-ring:rgba(122, 31, 43, 0.75);', $css );
		$this->assertStringNotContainsString( 'ffc-dark-mode .ffc-shortcode{--ffc-primary', str_replace( ':root:not(.ffc-dark-mode)', '', $css ), 'Nothing reaches the dark palette.' );
	}

	public function test_a_light_accent_gets_dark_text_and_a_lighter_hover(): void {
		$css = ( new PublicDesign(
			array(
				'enabled' => '1',
				'accent'  => '#ffd400',
			)
		) )->css();
		$this->assertStringContainsString( '--ffc-text-on-primary:#000000;', $css );
		$this->assertMatchesRegularExpression( '/--ffc-primary-hover:#ffdf40;/', $css, 'The hover moves away from the text colour, so the button text stays readable.' );
	}

	public function test_the_theme_font_adds_no_font_rule(): void {
		$css = ( new PublicDesign( array( 'enabled' => '1' ) ) )->css();
		$this->assertStringNotContainsString( 'font-family', $css );
	}

	public function test_the_defaults_raise_no_contrast_warning(): void {
		$this->assertSame( array(), ( new PublicDesign( array( 'enabled' => '1' ) ) )->weak_contrasts() );
	}

	public function test_weak_pairs_are_named_with_their_floor(): void {
		$weak = ( new PublicDesign(
			array(
				'enabled' => '1',
				'accent'  => '#ffd400',
				'border'  => '#eeeeee',
			)
		) )->weak_contrasts();
		$this->assertSame( array( 'links on white', 'field borders on white' ), array_column( $weak, 'pair' ) );
		$this->assertSame( array( 4.5, 3.0 ), array_column( $weak, 'min' ) );
	}

	public function test_the_stylesheet_follows_ffc_common_on_public_pages_only(): void {
		$this->stored = array(
			'public_design'        => 1,
			'public_design_accent' => '#7a1f2b',
		);
		$tag = "<link rel='stylesheet' id='ffc-common-css' href='x.css' />\n";

		$out = PublicDesign::append_to_common_style( $tag, 'ffc-common' );
		$this->assertStringStartsWith( $tag, $out );
		$this->assertStringContainsString( '<style id="ffc-public-design">', $out );

		$this->assertSame( $tag, PublicDesign::append_to_common_style( $tag, 'ffc-frontend-css' ), 'Only after the palette sheet.' );

		Functions\when( 'is_admin' )->justReturn( true );
		$this->assertSame( $tag, PublicDesign::append_to_common_style( $tag, 'ffc-common' ), 'Never in the admin.' );
	}

	public function test_off_appends_nothing(): void {
		$tag = "<link rel='stylesheet' id='ffc-common-css' href='x.css' />\n";
		$this->assertSame( $tag, PublicDesign::append_to_common_style( $tag, 'ffc-common' ) );
	}

	public function test_init_hooks_the_printed_tag(): void {
		PublicDesign::init();
		$this->assertSame( 10, has_filter( 'style_loader_tag', array( PublicDesign::class, 'append_to_common_style' ) ) );
	}
}

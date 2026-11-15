<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\VisualLayout;
use FreeFormCertificate\Generators\PdfHtmlRenderer;

/**
 * The visual template's layout model and its server renderer (#2584).
 *
 * The fixture is a hand-written layout using every element type; its HTML is
 * frozen beside it (regenerate with FFC_UPDATE_VISUAL_FIXTURE=1 and review
 * the diff). The HTML policy is the identity here: the renderer emits only
 * what the policy keeps, measured against a real WordPress when the fixture
 * was written, where `HtmlPolicy::kses_layout()` returned it unchanged.
 *
 * @covers \FreeFormCertificate\Core\VisualLayout
 */
class VisualLayoutTest extends TestCase {

	private const FIXTURES = __DIR__ . '/../fixtures/visual-layout/';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->alias( static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES ) );
		Functions\when( 'esc_attr' )->alias( static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES ) );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ) );
		Functions\when( 'sanitize_hex_color' )->alias( static fn( $c ) => preg_match( '/^#([0-9a-f]{3}){1,2}$/i', (string) $c ) ? $c : null );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v, $flags = 0 ) => json_encode( $v, $flags ) );
		Functions\when( 'wp_get_attachment_url' )->alias( static fn( $id ) => 41 === $id ? 'https://example.test/wp-content/uploads/seal.png' : false );
		Functions\when( 'wp_get_attachment_metadata' )->alias(
			static fn( $id ) => 41 === $id ? array(
				'width'  => 400,
				'height' => 200,
			) : false
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function fixture_layout(): array {
		$layout = VisualLayout::validate( (string) file_get_contents( self::FIXTURES . 'certificate.json' ) );
		$this->assertIsArray( $layout );
		return $layout;
	}

	public function test_a_hand_written_layout_renders_to_known_html(): void {
		$html = VisualLayout::render( $this->fixture_layout() );
		if ( getenv( 'FFC_UPDATE_VISUAL_FIXTURE' ) ) {
			file_put_contents( self::FIXTURES . 'certificate.html', $html . "\n" );
		}
		$this->assertSame( rtrim( (string) file_get_contents( self::FIXTURES . 'certificate.html' ), "\n" ), $html );
	}

	public function test_the_renderer_places_each_element_where_the_layout_says(): void {
		$html = VisualLayout::render( $this->fixture_layout() );
		$this->assertStringContainsString( 'style="position:relative;width:1123px;height:794px;overflow:hidden"', $html );
		$this->assertStringContainsString( 'left:100px;top:300px;width:923px;height:50px;z-index:3', $html, 'the list order is the stacking order' );
		$this->assertStringContainsString( 'left:20px;top:20px;width:1075px;height:746px', $html, 'a frame draws its border inside its box' );
		$this->assertStringContainsString( 'transform:rotate(-2deg)', $html );
		$this->assertStringContainsString( 'class="ffc-visual-el ffc-visual-field ffc-fit"', $html );
		$this->assertStringContainsString( 'class="ffc-visual-el ffc-visual-field ffc-fit-box"', $html );
		// Cover, 400 × 200 into 120 × 120: scale 0.6, drawn 240 × 120, centred.
		$this->assertStringContainsString( 'style="position:absolute;left:-60px;top:0px;width:240px;height:120px;max-width:none"', $html );
		$this->assertStringContainsString( '<img src="https://example.test/wp-content/uploads/seal.png" alt="" style="max-width:200px;max-height:120px">', $html, 'contain is a maximum size, never object-fit' );
		$this->assertStringNotContainsString( 'object-fit', $html );
		$this->assertStringContainsString( '<img src="{{signer:signature}}"', $html );
		$this->assertStringContainsString( '{{qr_code:size=90:margin=0}}', $html, 'the code fits the shorter side' );
	}

	public function test_a_rendered_layout_goes_through_the_certificate_renderer(): void {
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_date' )->alias( static fn( $format, $ts = null ) => gmdate( $format, (int) $ts ) );
		Functions\when( 'get_bloginfo' )->justReturn( '' );
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'get_home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'home_url' )->justReturn( 'https://example.test/' );
		Functions\when( 'add_query_arg' )->returnArg( 3 );
		$layout = VisualLayout::validate( '{"version":1,"elements":[{"type":"field","token":"{{name}}","x":10,"y":20,"w":300,"h":40},{"type":"text","text":"Olá, {{name}}","x":10,"y":80,"w":300,"h":40}]}' );
		$this->assertIsArray( $layout );

		$html = ( new PdfHtmlRenderer() )->generate_html( array( 'name' => 'Ana <b>Lima</b>' ), 'Form', array( 'pdf_layout' => VisualLayout::render( $layout ) ) );
		$this->assertStringContainsString( '>Ana &lt;b&gt;Lima&lt;/b&gt;</div>', $html, 'a field prints its value, escaped' );
		$this->assertStringContainsString( '>Olá, Ana &lt;b&gt;Lima&lt;/b&gt;</div>', $html, 'a token inside literal text resolves too' );
	}

	public function test_a_portrait_page_has_its_own_size(): void {
		$layout = VisualLayout::validate( '{"version":1,"page":{"orientation":"portrait"},"elements":[]}' );
		$this->assertIsArray( $layout );
		$this->assertStringContainsString( 'width:794px;height:1123px', VisualLayout::render( $layout ) );
		$this->assertSame( 'landscape', VisualLayout::validate( '{"version":1,"page":{"orientation":"diagonal"},"elements":[]}' )['page']['orientation'] );
	}

	/**
	 * @dataProvider refused_layouts
	 */
	public function test_what_cannot_be_a_layout_is_refused( string $json, string $code ): void {
		$result = VisualLayout::validate( $json );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function refused_layouts(): array {
		return array(
			'not JSON'          => array( '{"version":1,', 'ffc_layout_not_json' ),
			'a scalar'          => array( '"layout"', 'ffc_layout_not_json' ),
			'no version'        => array( '{"elements":[]}', 'ffc_layout_version' ),
			'a later version'   => array( '{"version":2,"elements":[]}', 'ffc_layout_version' ),
			'a version string'  => array( '{"version":"1","elements":[]}', 'ffc_layout_version' ),
			'no element list'   => array( '{"version":1}', 'ffc_layout_elements' ),
			'elements as a map' => array( '{"version":1,"elements":{"a":{}}}', 'ffc_layout_elements' ),
			'an element string' => array( '{"version":1,"elements":["text"]}', 'ffc_layout_element' ),
			'an unknown type'   => array( '{"version":1,"elements":[{"type":"script"}]}', 'ffc_layout_type' ),
			'too deep'          => array( str_repeat( '[', 40 ) . str_repeat( ']', 40 ), 'ffc_layout_not_json' ),
		);
	}

	public function test_too_many_elements_are_refused(): void {
		$elements = array_fill( 0, VisualLayout::MAX_ELEMENTS + 1, array( 'type' => 'qr' ) );
		$result   = VisualLayout::validate( (string) json_encode( array( 'version' => 1, 'elements' => $elements ) ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_layout_too_many', $result->get_error_code() );
	}

	public function test_hostile_values_are_neutralised(): void {
		$layout = VisualLayout::validate(
			(string) json_encode(
				array(
					'version'  => 1,
					'elements' => array(
						array(
							'type'        => 'text',
							'id'          => 'A"B',
							'text'        => "</div><script>alert(1)</script>\x07",
							'font_family' => 'Arial;}</style><x onload=y',
							'color'       => 'red;background:url(x)',
							'align'       => 'center;position:fixed',
							'x'           => 'NaN',
							'y'           => 1e9,
							'w'           => -5,
							'font_size'   => 9999,
							'font_weight' => 'heavy',
							'onclick'     => 'x()',
						),
						array(
							'type'  => 'field',
							'token' => '{{name}}"><script>',
						),
						array(
							'type'  => 'image',
							'token' => 'javascript:alert(1)',
						),
						array(
							'type' => 'html',
							'html' => '<p>ok</p>',
						),
					),
				)
			)
		);
		$this->assertIsArray( $layout );
		$text = $layout['elements'][0];
		$this->assertSame( 'ab', $text['id'] );
		$this->assertSame( '</div><script>alert(1)</script>', $text['text'], 'control characters go; the text stays text' );
		$this->assertSame( 'Arialstylex onloady', $text['font_family'] );
		$this->assertSame( '#000000', $text['color'] );
		$this->assertSame( 'left', $text['align'] );
		$this->assertSame( 0.0, $text['x'] );
		$this->assertSame( 1588.0, $text['y'], 'clamped to twice the page height' );
		$this->assertSame( 1.0, $text['w'] );
		$this->assertSame( 300.0, $text['font_size'] );
		$this->assertSame( 400, $text['font_weight'] );
		$this->assertArrayNotHasKey( 'onclick', $text, 'an unknown key is dropped' );
		$this->assertSame( '{{name}}', $layout['elements'][1]['token'], 'a token carrying markup falls back' );
		$this->assertSame( '', $layout['elements'][2]['token'], 'only the signature token is an image' );

		$html = VisualLayout::render( $layout );
		$this->assertStringContainsString( '&lt;/div&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
	}

	public function test_an_image_shipped_with_the_plugin_is_drawn_from_the_plugin(): void {
		$file   = 'assets/img/certificate-defaults/default_assinagture_1.png';
		$layout = VisualLayout::validate(
			(string) json_encode(
				array(
					'version'  => 1,
					'elements' => array(
						array( 'type' => 'image', 'asset' => $file, 'x' => 10, 'y' => 10, 'w' => 150, 'h' => 60 ),
						array( 'type' => 'image', 'asset' => 'assets/img/../../ffcertificate.php' ),
						array( 'type' => 'image', 'asset' => 'assets/img/certificate-defaults/missing.png' ),
						array( 'type' => 'image', 'asset' => 'includes/core/class-ffc-visual-layout.php' ),
						array( 'type' => 'image', 'asset' => $file, 'attachment_id' => 41 ),
					),
				)
			)
		);
		$this->assertIsArray( $layout );
		$this->assertSame( $file, $layout['elements'][0]['asset'] );
		$this->assertSame( '', $layout['elements'][1]['asset'], 'a path that climbs out is refused' );
		$this->assertSame( '', $layout['elements'][2]['asset'], 'a file that is not there is refused' );
		$this->assertSame( '', $layout['elements'][3]['asset'], 'only an image under assets/img' );

		$html = VisualLayout::render( $layout );
		$this->assertStringContainsString( '<img src="' . FFC_PLUGIN_URL . $file . '"', $html );
		$this->assertStringContainsString( 'seal.png', $html, 'a Media Library image wins over the asset' );
	}

	public function test_a_frame_takes_a_linear_gradient(): void {
		$frame  = static fn( $gradient ) => array(
			'type'     => 'frame',
			'fill'     => '#0b2545',
			'gradient' => $gradient,
		);
		$layout = VisualLayout::validate(
			(string) json_encode(
				array(
					'version'  => 1,
					'elements' => array(
						$frame(
							array(
								'angle' => 450,
								'stops' => array(
									array( 'color' => '#0B2545', 'at' => -5 ),
									array( 'color' => 'red;background:url(x)', 'at' => 20 ),
									array( 'color' => '#13315c', 'at' => 45 ),
									array( 'color' => '#2f80ed', 'at' => 140 ),
									array( 'color' => '#000000', 'at' => 100 ),
									array( 'color' => '#111111', 'at' => 100 ),
									array( 'color' => '#222222', 'at' => 100 ),
								),
							)
						),
						$frame( array( 'stops' => array( array( 'color' => '#000000' ) ) ) ),
						$frame( 'linear-gradient(red, blue)' ),
						$frame( null ),
					),
				)
			)
		);
		$this->assertIsArray( $layout );
		$gradient = $layout['elements'][0]['gradient'];
		$this->assertSame( 360.0, $gradient['angle'] );
		$this->assertCount( 5, $gradient['stops'], 'five colours at most; a colour that is not one is dropped' );
		$this->assertSame( array( 'color' => '#0b2545', 'at' => 0.0 ), $gradient['stops'][0] );
		$this->assertSame( array( 'color' => '#2f80ed', 'at' => 100.0 ), $gradient['stops'][2] );
		$this->assertSame( array(), $layout['elements'][1]['gradient'], 'one colour is no gradient' );
		$this->assertSame( array(), $layout['elements'][2]['gradient'], 'only the structured form' );
		$this->assertSame( array(), $layout['elements'][3]['gradient'] );

		$html = VisualLayout::render( $layout );
		$this->assertStringContainsString( 'background-color:#0b2545;background-image:linear-gradient(360deg,#0b2545 0%,#13315c 45%,#2f80ed 100%,#000000 100%,#111111 100%)', $html );
		$this->assertSame( 1, substr_count( $html, 'linear-gradient' ), 'a frame without one draws its fill alone' );
		$this->assertStringNotContainsString( 'url(', $html );
	}

	public function test_ids_are_kept_unique(): void {
		$layout = VisualLayout::validate( '{"version":1,"elements":[{"type":"qr","id":"a"},{"type":"qr","id":"a"},{"type":"qr"},{"type":"qr","id":"el-3"}]}' );
		$this->assertSame( array( 'a', 'el-2', 'el-3', 'el-4' ), array_column( $layout['elements'], 'id' ) );
	}

	public function test_a_validated_layout_round_trips_through_its_json(): void {
		$layout = $this->fixture_layout();
		$this->assertSame( $layout, VisualLayout::validate( VisualLayout::to_json( $layout ) ) );
		$this->assertSame( VisualLayout::blank(), VisualLayout::validate( VisualLayout::to_json( VisualLayout::blank() ) ) );
	}

	public function test_the_stored_layout_of_a_template(): void {
		$meta = array(
			7 => array(
				VisualLayout::META_EDITOR => VisualLayout::EDITOR_VISUAL,
				VisualLayout::META_LAYOUT => '{"version":1,"elements":[]}',
			),
			8 => array( VisualLayout::META_LAYOUT => 'broken' ),
		);
		Functions\when( 'get_post_meta' )->alias( static fn( $id, $key ) => $meta[ $id ][ $key ] ?? '' );
		$this->assertTrue( VisualLayout::is_visual( 7 ) );
		$this->assertFalse( VisualLayout::is_visual( 8 ) );
		$this->assertSame( VisualLayout::blank(), VisualLayout::stored( 7 ) );
		$this->assertNull( VisualLayout::stored( 8 ) );
	}
}

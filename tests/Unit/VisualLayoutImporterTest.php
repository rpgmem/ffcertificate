<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\VisualLayoutImporter;

/**
 * Reading an HTML template into a visual layout (#2587): positioned boxes
 * become typed elements, the rest HTML blocks, and the report names each.
 *
 * @covers \FreeFormCertificate\Core\VisualLayoutImporter
 */
class VisualLayoutImporterTest extends TestCase {

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
		Functions\when( 'attachment_url_to_postid' )->alias( static fn( $url ) => 'https://example.test/uploads/seal.png' === $url ? 41 : 0 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The report lines of one status.
	 *
	 * @param array<string, mixed> $result Importer result.
	 * @return list<string>
	 */
	private function lines( array $result, string $status ): array {
		return array_values(
			array_map(
				static fn( array $e ): string => $e['label'],
				array_filter( $result['report'], static fn( array $e ): bool => $status === $e['status'] )
			)
		);
	}

	public function test_positioned_boxes_become_typed_elements(): void {
		$html = '<div style="width: 1123px; height: 794px; position: relative; font-family: Arimo, Arial; color: #1d2a4a; background: #fdfbf5">'
			. '<div style="position: absolute; top: 26px; left: 26px; right: 26px; bottom: 26px; border: 14px solid #1d2a4a; box-sizing: border-box"></div>'
			. '<p class="ffc-fit" style="position: absolute; left: 100px; top: 300px; width: 900px; height: 60px; font-size: 36pt; text-align: center; letter-spacing: 0.1em">{{name}}</p>'
			. '<div style="position: absolute; left: 100px; top: 400px; width: 900px; height: 40px"><p style="font-weight: bold; color: rgb(138, 109, 29)">Completed on<br>25/12/2025</p></div>'
			. '<div style="position: absolute; left: 40px; top: 660px; width: 90px; height: 90px">{{qr_code:size=90:margin=2}}</div>'
			. '<div style="position: absolute; left: 200px; top: 560px; width: 200px; height: 80px"><img src="{{signer:signature}}"></div>'
			. '<img style="position: absolute; left: 900px; top: 40px; width: 120px; height: 120px" src="https://example.test/uploads/seal.png">'
			. '</div>';

		$result   = VisualLayoutImporter::from_html( $html );
		$elements = $result['layout']['elements'];

		$this->assertSame( 'landscape', $result['layout']['page']['orientation'] );
		$this->assertSame( '#fdfbf5', $result['bg_color'], 'the page colour moves to the template' );
		$this->assertSame( array( 'frame', 'field', 'text', 'qr', 'image', 'image' ), array_column( $elements, 'type' ) );

		// The frame takes its insets as a size, and its border.
		$this->assertSame( array( 26.0, 26.0, 1071.0, 742.0 ), array( $elements[0]['x'], $elements[0]['y'], $elements[0]['w'], $elements[0]['h'] ) );
		$this->assertSame( 14.0, (float) $elements[0]['border_width'] );
		$this->assertSame( '#1d2a4a', $elements[0]['border_color'] );

		// The field: one tag, pt to px, em letter spacing, the page's font, fit.
		$this->assertSame( '{{name}}', $elements[1]['token'] );
		$this->assertSame( 48.0, (float) $elements[1]['font_size'] );
		$this->assertSame( 4.8, (float) $elements[1]['letter_spacing'] );
		$this->assertSame( 'Arimo, Arial', $elements[1]['font_family'] );
		$this->assertSame( '#1d2a4a', $elements[1]['color'] );
		$this->assertSame( 'center', $elements[1]['align'] );
		$this->assertSame( 'line', $elements[1]['fit'] );

		// The text, through a wrapper: its line break, weight and rgb colour.
		$this->assertSame( "Completed on\n25/12/2025", $elements[2]['text'] );
		$this->assertSame( 700, $elements[2]['font_weight'] );
		$this->assertSame( '#8a6d1d', $elements[2]['color'] );

		$this->assertSame( 2, $elements[3]['margin'] );
		$this->assertSame( '{{signer:signature}}', $elements[4]['token'] );
		$this->assertSame( 41, $elements[5]['attachment_id'] );

		$this->assertCount( 7, $this->lines( $result, VisualLayoutImporter::CONVERTED ), 'six elements and the page colour' );
		$this->assertSame( array(), $this->lines( $result, VisualLayoutImporter::KEPT ) );
	}

	public function test_flowing_content_is_one_block_the_size_of_the_page(): void {
		$html = '<div style="width: 794px; height: 1123px; font-family: Georgia; color: #333">'
			. '<h1>Certificate</h1><p>This certifies that {{name}} completed {{form_title}}.</p>'
			. '</div>';

		$result   = VisualLayoutImporter::from_html( $html );
		$elements = $result['layout']['elements'];

		$this->assertSame( 'portrait', $result['layout']['page']['orientation'] );
		$this->assertCount( 1, $elements );
		$this->assertSame( 'html', $elements[0]['type'] );
		$this->assertSame( array( 0.0, 0.0, 794.0, 1123.0 ), array( (float) $elements[0]['x'], (float) $elements[0]['y'], (float) $elements[0]['w'], (float) $elements[0]['h'] ) );
		$this->assertStringContainsString( 'font-family:Georgia;color:#333;', $elements[0]['html'], 'the page\'s text style travels with the block' );
		$this->assertStringContainsString( '{{name}}', $elements[0]['html'] );
		// The report names each piece by its first words.
		$this->assertSame( array( '“Certificate”', '“This certifies that {{name}} completed {{form_t…”' ), $this->lines( $result, VisualLayoutImporter::KEPT ) );
	}

	public function test_mixed_content_keeps_what_is_not_a_single_shape_as_html(): void {
		$html = '<div style="width: 1123px; height: 794px">'
			. '<div style="position: absolute; top: 95px; left: 100px; width: 923px; text-align: center"><p>Certificate</p><p>of completion</p></div>'
			. '<div style="position: absolute; top: 560px; left: 200px; width: 723px; height: 120px"><table><tr><td>A</td><td>B</td></tr></table></div>'
			. '<img style="position: absolute; left: 10px; top: 10px; width: 50px; height: 50px" src="/wp-content/plugins/ffcertificate/logo.png">'
			. '</div>';

		$result   = VisualLayoutImporter::from_html( $html );
		$elements = $result['layout']['elements'];

		$this->assertSame( array( 'html', 'html', 'html' ), array_column( $elements, 'type' ) );
		// No fixed height: the block runs to the bottom of the page.
		$this->assertSame( 794.0 - 95.0, (float) $elements[0]['h'] );
		$this->assertStringContainsString( 'text-align:center', $elements[0]['html'] );
		$this->assertStringNotContainsString( 'position:absolute', $elements[0]['html'], 'the placing declarations do not travel into the block' );
		$this->assertStringContainsString( '<table>', $elements[1]['html'] );
		// An image outside the Media Library cannot be an image element.
		$this->assertStringContainsString( 'logo.png', $elements[2]['html'] );
		$this->assertCount( 3, $this->lines( $result, VisualLayoutImporter::KEPT ) );
	}

	public function test_rules_outside_the_vocabulary_are_dropped_and_reported(): void {
		$html = '<div style="width: 1123px; height: 794px">'
			. '<div style="position: absolute; left: 10px; top: 10px; width: 500px; height: 60px; font-size: 20px; text-shadow: 1px 1px #000; margin: 4px">Named</div>'
			. '<div style="position: absolute; left: 10px; top: 100px; width: 200px; height: 200px; background-color: #eee; box-shadow: 0 0 4px #000; border-radius: 12px"></div>'
			. '<div style="position: absolute; left: 10px; top: 400px; width: 200px; height: 200px"></div>'
			. '</div>';

		$result   = VisualLayoutImporter::from_html( $html );
		$elements = $result['layout']['elements'];

		$this->assertSame( array( 'text', 'frame' ), array_column( $elements, 'type' ), 'a box that draws nothing is left out' );
		$this->assertContains( 'An empty box with no border or fill', $this->lines( $result, VisualLayoutImporter::DROPPED ) );
		$this->assertSame( '#eeeeee', $elements[1]['fill'] );
		$this->assertSame( 12.0, (float) $elements[1]['radius'] );
		$this->assertSame(
			array(
				'The rule text-shadow: 1px 1px #000, which a visual element does not draw',
				'The rule margin: 4px, which a visual element does not draw',
				'The rule box-shadow: 0 0 4px #000, which a visual element does not draw',
				'An empty box with no border or fill',
			),
			$this->lines( $result, VisualLayoutImporter::DROPPED )
		);
	}

	public function test_a_template_with_no_measured_page_is_one_block(): void {
		$result = VisualLayoutImporter::from_html( '<p>Hello {{name}}</p><p>Second</p>' );

		$this->assertSame( array( 'html' ), array_column( $result['layout']['elements'], 'type' ) );
		$this->assertStringContainsString( 'Second', $result['layout']['elements'][0]['html'] );
		$this->assertSame( array( 'The whole template, which has no page with a width and height in pixels' ), $this->lines( $result, VisualLayoutImporter::KEPT ) );
		$this->assertSame( '', $result['bg_color'] );
	}

	public function test_stacking_follows_z_index_then_source_order_and_rotation_is_kept(): void {
		$html = '<div style="width: 1123px; height: 794px">'
			. '<div style="position: absolute; left: 0; top: 0; width: 10px; height: 10px; background: #111; z-index: 5"></div>'
			. '<div style="position: absolute; left: 0; top: 0; width: 20px; height: 20px; background: #222; transform: rotate(-12deg)"></div>'
			. '</div>';

		$elements = VisualLayoutImporter::from_html( $html )['layout']['elements'];

		$this->assertSame( array( '#222222', '#111111' ), array_column( $elements, 'fill' ) );
		$this->assertSame( -12.0, (float) $elements[0]['rotation'] );
	}

	/**
	 * Each shipped default model reads into a layout that still carries
	 * every tag of its HTML, whatever became typed and whatever stayed HTML.
	 */
	public function test_each_default_model_keeps_its_tags(): void {
		for ( $n = 1; $n <= 6; $n++ ) {
			$html   = (string) file_get_contents( FFC_PLUGIN_DIR . 'templates/certificate-defaults/default_certificate_' . $n . '.html' );
			$result = VisualLayoutImporter::from_html( $html );
			$this->assertNotEmpty( $result['layout']['elements'], "model {$n}" );
			$rendered = \FreeFormCertificate\Core\VisualLayout::render( $result['layout'] );
			preg_match_all( '/\{\{[a-z0-9_:=]+\}\}/', $html, $wanted );
			foreach ( array_unique( $wanted[0] ) as $tag ) {
				$this->assertStringContainsString( $tag, $rendered, "model {$n} keeps {$tag}" );
			}
		}
	}
}

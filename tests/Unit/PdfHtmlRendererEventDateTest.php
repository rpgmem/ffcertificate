<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\EventDate;
use FreeFormCertificate\Generators\PdfHtmlRenderer;

/**
 * The certificate renderer prints the event date the generator passes (#2579).
 *
 * @covers \FreeFormCertificate\Generators\PdfHtmlRenderer
 */
class PdfHtmlRendererEventDateTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_bloginfo' )->justReturn( 'Site' );
		Functions\when( 'get_home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'untrailingslashit' )->alias( static fn( $v ) => rtrim( (string) $v, '/' ) );
		Functions\when( 'esc_html' )->alias( static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES ) );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( '_x' )->returnArg();
		Functions\when( 'wp_date' )->alias(
			static fn( $format, $ts = null, $tz = null ) => ( new \DateTimeImmutable( '@' . ( $ts ?? time() ) ) )->setTimezone( $tz instanceof \DateTimeZone ? $tz : new \DateTimeZone( 'UTC' ) )->format( $format )
		);
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function render( string $layout, array $data ): string {
		return ( new PdfHtmlRenderer() )->generate_html( $data, 'Form', array( 'pdf_layout' => $layout ) );
	}

	public function test_the_date_tokens_print_the_event_date_beside_the_hours(): void {
		$html = $this->render(
			'<p>no dia {{schedule:date}}, das {{schedule}}; {{schedule:dates:format=j/n}}</p>',
			array(
				'schedule'             => '08:00 às 10:30',
				EventDate::DATA_START => '2025-12-19',
				EventDate::DATA_END   => '2025-12-20',
			)
		);
		$this->assertStringContainsString( 'no dia 19/12/2025, das 08:00 às 10:30; 19/12 to 20/12', $html );
	}

	public function test_without_an_event_date_the_tokens_print_nothing(): void {
		$this->assertStringContainsString( '<p>no dia .</p>', $this->render( '<p>no dia {{schedule:date}}.</p>', array() ) );
	}

	public function test_an_image_a_token_left_without_an_address_is_not_printed(): void {
		// A signer position with no signer prints an empty signature (#2624).
		$html = $this->render(
			'<td><img src="{{signer1:signature}}" style="width:150px"><img src=" " alt=""><IMG alt="x" SRC=\'\'><img src="/logo.png" alt=""><strong>{{signer1:name}}</strong></td>',
			array(
				'signer1:signature' => '',
				'signer1:name'      => '',
			)
		);
		$this->assertSame( 1, substr_count( strtolower( $html ), '<img' ), 'only the image with an address is left' );
		$this->assertStringContainsString( '<img src="https://example.test/logo.png" alt="">', $html );
		$this->assertStringContainsString( '<td><img src="https://example.test/logo.png" alt=""><strong></strong></td>', $html );

		$kept = $this->render( '<img src="{{signer1:signature}}">', array( 'signer1:signature' => 'https://example.test/sig.png' ) );
		$this->assertStringContainsString( '<img src="https://example.test/sig.png">', $kept );
	}
}

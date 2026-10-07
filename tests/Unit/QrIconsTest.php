<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Generators\QrIcons;
use FreeFormCertificate\Generators\QrPayload;

/**
 * The QR screens' own icon set (#1570): every content type has one, each is
 * a self-contained drawing in the text colour, and nothing is fetched.
 *
 * @covers \FreeFormCertificate\Generators\QrIcons
 */
class QrIconsTest extends TestCase {

	public function test_every_content_type_has_an_icon(): void {
		foreach ( QrPayload::TYPES as $type ) {
			$this->assertTrue( QrIcons::has( $type ), $type );
		}
	}

	public function test_every_design_section_and_action_has_an_icon(): void {
		foreach ( array( 'pattern', 'eyes', 'colors', 'logo', 'frame', 'advanced', 'download', 'print' ) as $name ) {
			$this->assertTrue( QrIcons::has( $name ), $name );
		}
	}

	public function test_an_icon_is_inline_decorative_and_in_the_text_colour(): void {
		foreach ( QrIcons::names() as $name ) {
			$svg = QrIcons::svg( $name, 24 );
			$this->assertNotFalse( simplexml_load_string( $svg ), $name . ' is well-formed XML' );
			$this->assertStringContainsString( 'aria-hidden="true"', $svg, $name );
			$this->assertStringContainsString( 'stroke="currentColor"', $svg, $name );
			$this->assertDoesNotMatchRegularExpression( '/#[0-9a-f]{3,6}\b|href|url\(/i', $svg, $name . ' carries no colour of its own and fetches nothing' );
		}
	}

	public function test_size_and_unknown_names(): void {
		$this->assertStringContainsString( 'width="18" height="18"', QrIcons::svg( 'url', 18 ) );
		$this->assertStringContainsString( 'width="1" height="1"', QrIcons::svg( 'url', -5 ) );
		$this->assertSame( '', QrIcons::svg( 'nope' ) );
		$this->assertFalse( QrIcons::has( 'nope' ) );
	}
}

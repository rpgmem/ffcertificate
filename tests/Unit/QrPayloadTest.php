<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Generators\QrPayload;

/**
 * QrPayload (#1563): the text each content type encodes, and the validation
 * that refuses what a phone could not act on.
 *
 * The formats were also checked end to end: every type below was rendered,
 * rasterised and decoded with ZXing back to the exact payload, UTF-8 text and
 * Wi-Fi escapes included.
 *
 * @covers \FreeFormCertificate\Generators\QrPayload
 */
class QrPayloadTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_http_validate_url' )->alias( static fn( $u ) => filter_var( $u, FILTER_VALIDATE_URL ) ? $u : false );
		Functions\when( 'sanitize_email' )->alias( static fn( $e ) => trim( $e ) );
		Functions\when( 'is_email' )->alias( static fn( $e ) => false !== filter_var( $e, FILTER_VALIDATE_EMAIL ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function error( string $type, array $fields ): string {
		$result = QrPayload::build( $type, $fields );
		$this->assertInstanceOf( \WP_Error::class, $result );
		return $result->get_error_message();
	}

	public function test_url_gains_a_scheme_and_refuses_garbage(): void {
		$this->assertSame( 'https://example.com/a?b=1', QrPayload::build( 'url', array( 'url' => 'example.com/a?b=1' ) ) );
		$this->assertSame( 'http://example.com', QrPayload::build( 'url', array( 'url' => ' http://example.com ' ) ) );
		$this->error( 'url', array( 'url' => '' ) );
		$this->error( 'url', array( 'url' => 'not a url' ) );
	}

	public function test_text_is_kept_as_typed(): void {
		$this->assertSame( "Olá\nmundo", QrPayload::build( 'text', array( 'text' => "Olá\nmundo" ) ) );
		$this->error( 'text', array( 'text' => '   ' ) );
	}

	public function test_wifi_escapes_its_special_characters(): void {
		$this->assertSame(
			'WIFI:T:WPA;S:Escola\;Rede\\:1;P:p@ss\\,\\"x\\\\y;H:true;;',
			QrPayload::build(
				'wifi',
				array(
					'ssid'     => 'Escola;Rede:1',
					'password' => 'p@ss,"x\\y',
					'security' => 'WPA',
					'hidden'   => '1',
				)
			)
		);
	}

	public function test_an_open_network_carries_no_password(): void {
		$this->assertSame( 'WIFI:T:nopass;S:Guest;;', QrPayload::build( 'wifi', array( 'ssid' => 'Guest', 'password' => 'ignored', 'security' => 'nopass' ) ) );
	}

	public function test_wifi_needs_a_name_and_a_password_unless_open(): void {
		$this->error( 'wifi', array( 'password' => 'x' ) );
		$this->error( 'wifi', array( 'ssid' => 'Net', 'security' => 'WPA' ) );
		// An unknown security type falls back to WPA rather than failing.
		$this->assertStringStartsWith( 'WIFI:T:WPA;', (string) QrPayload::build( 'wifi', array( 'ssid' => 'Net', 'password' => 'x', 'security' => 'WPA9' ) ) );
	}

	public function test_email_is_a_canonical_mailto_with_encoded_fields(): void {
		$this->assertSame(
			'mailto:fulano@example.com?subject=Ol%C3%A1%20%26%20tal&body=a%20b%0Ac',
			QrPayload::build( 'email', array( 'email' => 'Fulano@Example.com', 'subject' => 'Olá & tal', 'body' => "a b\nc" ) )
		);
		$this->assertSame( 'mailto:a@b.co', QrPayload::build( 'email', array( 'email' => 'a@b.co' ) ) );
		$this->error( 'email', array( 'email' => 'nobody@' ) );
	}

	public function test_phone_sms_and_whatsapp_normalise_the_number(): void {
		$this->assertSame( 'tel:+5511987654321', QrPayload::build( 'phone', array( 'phone' => '+55 (11) 98765-4321' ) ) );
		$this->assertSame( 'SMSTO:11987654321:Oi: tudo bem?', QrPayload::build( 'sms', array( 'phone' => '11 98765 4321', 'message' => 'Oi: tudo bem?' ) ) );
		$this->assertSame( 'https://wa.me/5511987654321?text=Quero%20o%20certificado', QrPayload::build( 'whatsapp', array( 'phone' => '+55 11 98765-4321', 'message' => 'Quero o certificado' ) ) );
		$this->assertSame( 'https://wa.me/5511987654321', QrPayload::build( 'whatsapp', array( 'phone' => '5511987654321' ) ) );
	}

	public function test_a_number_too_short_or_too_long_is_refused(): void {
		$this->error( 'phone', array( 'phone' => '12' ) );
		$this->error( 'sms', array( 'phone' => str_repeat( '9', 16 ) ) );
		$this->error( 'whatsapp', array( 'phone' => 'abc' ) );
	}

	public function test_an_unknown_type_and_non_scalar_fields_are_refused(): void {
		$this->assertSame( 'ffc_qr_type', QrPayload::build( 'vcard', array() )->get_error_code() );
		$this->error( 'text', array( 'text' => array( 'x' ) ) );
	}

	public function test_usage_reports_fill_version_and_density(): void {
		$this->assertSame(
			array(
				'bytes'    => 100,
				'capacity' => 2331,
				'percent'  => 5,
				'version'  => 5,
				'dense'    => false,
			),
			QrPayload::usage( str_repeat( 'x', 100 ), 'M', 37 )
		);

		$dense = QrPayload::usage( str_repeat( 'x', 1300 ), 'H', 17 + 4 * 30 );
		$this->assertSame( 30, $dense['version'] );
		$this->assertTrue( $dense['dense'] );
		$this->assertSame( 100, $dense['percent'] );
		$this->assertSame( 0, QrPayload::usage( 'x', 'Z', 0 )['version'] );
		$this->assertSame( 2331, QrPayload::usage( 'x', 'Z', 0 )['capacity'] );
	}
}

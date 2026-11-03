<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Core\Captcha\CaptchaContext;
use PHPUnit\Framework\TestCase;

/**
 * The names a captcha challenge can be bound to (#2339).
 *
 * @covers \FreeFormCertificate\Core\Captcha\CaptchaContext
 */
class CaptchaContextTest extends TestCase {

	public function test_a_form_context_names_the_form(): void {
		$this->assertSame( 'form:42', CaptchaContext::form( 42 ) );
		$this->assertSame( 'form:0', CaptchaContext::form( -3 ) );
	}

	/**
	 * @dataProvider known_names
	 */
	public function test_a_known_name_is_kept( string $name ): void {
		$this->assertSame( $name, CaptchaContext::sanitize( $name ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function known_names(): array {
		return array(
			'verification page' => array( CaptchaContext::VERIFY ),
			'public CSV'        => array( CaptchaContext::PUBLIC_CSV ),
			'booking'           => array( CaptchaContext::BOOKING ),
			'a form'            => array( 'form:7' ),
		);
	}

	/**
	 * Anything else falls to '', which no verifier asks for, so a forged
	 * name gets a challenge that passes nowhere rather than an unbound one.
	 *
	 * @dataProvider unknown_names
	 *
	 * @param mixed $raw Value from the request.
	 */
	public function test_anything_else_falls_to_the_empty_context( $raw ): void {
		$this->assertSame( '', CaptchaContext::sanitize( $raw ) );
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function unknown_names(): array {
		return array(
			'unknown action'      => array( 'admin' ),
			'form zero'           => array( 'form:0' ),
			'separator injection' => array( 'form:7|context=verify' ),
			'equals injection'    => array( 'verify=1' ),
			'not a string'        => array( array( 'verify' ) ),
			'empty'               => array( '' ),
		);
	}
}

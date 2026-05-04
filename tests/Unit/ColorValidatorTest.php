<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\ColorValidator;

/**
 * Tests for the shared hex-color normalizer.
 *
 * @covers \FreeFormCertificate\Core\ColorValidator
 */
class ColorValidatorTest extends TestCase {

	public function test_accepts_three_digit_hex(): void {
		$this->assertSame( '#abc', ColorValidator::normalize( '#ABC', '#000' ) );
	}

	public function test_accepts_six_digit_hex(): void {
		$this->assertSame( '#abcdef', ColorValidator::normalize( '#ABCDEF', '#000' ) );
	}

	public function test_accepts_eight_digit_hex_with_alpha(): void {
		$this->assertSame( '#abcdef12', ColorValidator::normalize( '#ABCDEF12', '#000' ) );
	}

	public function test_lowercases_input(): void {
		$this->assertSame( '#aabbcc', ColorValidator::normalize( '#AaBbCc', '#000' ) );
	}

	public function test_trims_whitespace(): void {
		$this->assertSame( '#abc', ColorValidator::normalize( "  #abc  \n", '#000' ) );
	}

	public function test_rejects_missing_hash(): void {
		$this->assertSame( '#fff', ColorValidator::normalize( 'abcdef', '#fff' ) );
	}

	public function test_rejects_invalid_chars(): void {
		$this->assertSame( '#fff', ColorValidator::normalize( '#xyz', '#fff' ) );
	}

	public function test_rejects_wrong_length(): void {
		$this->assertSame( '#fff', ColorValidator::normalize( '#abcd', '#fff' ) );
		$this->assertSame( '#fff', ColorValidator::normalize( '#abcdefg', '#fff' ) );
	}

	public function test_empty_string_returns_default(): void {
		$this->assertSame( '#default', ColorValidator::normalize( '', '#default' ) );
	}

	public function test_non_string_returns_default(): void {
		$this->assertSame( '#default', ColorValidator::normalize( null, '#default' ) );
		$this->assertSame( '#default', ColorValidator::normalize( array( '#abc' ), '#default' ) );
		$this->assertSame( '#default', ColorValidator::normalize( 12345, '#default' ) );
	}
}

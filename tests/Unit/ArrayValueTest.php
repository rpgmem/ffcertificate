<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Core\ArrayValue;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the scalar reads over an untyped array (#1060).
 *
 * The idiom this class replaces is `(string) ( $data['key'] ?? '' )`, and the
 * cases that matter are the ones a cast gets wrong rather than the ones it
 * gets right: an array becoming the word `Array`, an arbitrary string
 * becoming the integer 0. Those are the assertions below.
 *
 * @covers \FreeFormCertificate\Core\ArrayValue
 */
class ArrayValueTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		class_exists( '\FreeFormCertificate\Core\ArrayValue' );
	}

	// ==================================================================
	// string()
	// ==================================================================

	public function test_string_reads_a_scalar(): void {
		$this->assertSame( 'abc', ArrayValue::string( array( 'k' => 'abc' ), 'k' ) );
		$this->assertSame( '7', ArrayValue::string( array( 'k' => 7 ), 'k' ) );
		$this->assertSame( '1.5', ArrayValue::string( array( 'k' => 1.5 ), 'k' ) );
	}

	public function test_string_refuses_an_array_instead_of_returning_the_word_array(): void {
		// `(string) array( 'a' )` is the literal 'Array' plus a PHP notice.
		// Bound into a query that is a filter matching nothing; written to a
		// column it is a row nobody can explain.
		$this->assertSame( '', ArrayValue::string( array( 'k' => array( 'a' ) ), 'k' ) );
	}

	public function test_string_refuses_an_object_instead_of_a_fatal(): void {
		// `(string)` on an object without __toString is Error, not a notice.
		$this->assertSame( '', ArrayValue::string( array( 'k' => new \stdClass() ), 'k' ) );
	}

	public function test_string_returns_the_default_for_a_missing_key(): void {
		$this->assertSame( 'fallback', ArrayValue::string( array(), 'k', 'fallback' ) );
	}

	public function test_string_treats_null_as_absent(): void {
		$this->assertSame( 'fallback', ArrayValue::string( array( 'k' => null ), 'k', 'fallback' ) );
	}

	// ==================================================================
	// int()
	// ==================================================================

	public function test_int_reads_a_numeric_value_in_either_spelling(): void {
		$this->assertSame( 7, ArrayValue::int( array( 'k' => 7 ), 'k' ) );
		$this->assertSame( 7, ArrayValue::int( array( 'k' => '7' ), 'k' ) );
		$this->assertSame( 7, ArrayValue::int( array( 'k' => '7.9' ), 'k' ) );
	}

	public function test_int_refuses_a_non_numeric_string_instead_of_returning_zero(): void {
		// `(int) 'not-an-id'` is 0, which is indistinguishable from a real
		// zero — and has written junction rows pointing at record 0.
		$this->assertSame( -1, ArrayValue::int( array( 'k' => 'not-an-id' ), 'k', -1 ) );
	}

	public function test_int_refuses_an_array(): void {
		$this->assertSame( 0, ArrayValue::int( array( 'k' => array( 1 ) ), 'k' ) );
	}

	// ==================================================================
	// bool()
	// ==================================================================

	public function test_bool_reads_the_spellings_forms_and_json_actually_use(): void {
		$this->assertTrue( ArrayValue::bool( array( 'k' => '1' ), 'k' ) );
		$this->assertTrue( ArrayValue::bool( array( 'k' => 1 ), 'k' ) );
		$this->assertTrue( ArrayValue::bool( array( 'k' => true ), 'k' ) );
		$this->assertTrue( ArrayValue::bool( array( 'k' => 'on' ), 'k' ) );

		$this->assertFalse( ArrayValue::bool( array( 'k' => '0' ), 'k' ) );
		$this->assertFalse( ArrayValue::bool( array( 'k' => 0 ), 'k' ) );
		$this->assertFalse( ArrayValue::bool( array( 'k' => '' ), 'k' ) );
		$this->assertFalse( ArrayValue::bool( array( 'k' => false ), 'k' ) );
	}

	public function test_bool_reads_the_string_false_as_false(): void {
		// JSON round-trips through forms as a string more often than not, and
		// `(bool) 'false'` is true — the trap this exists to close.
		$this->assertFalse( ArrayValue::bool( array( 'k' => 'false' ), 'k' ) );
		$this->assertFalse( ArrayValue::bool( array( 'k' => 'FALSE' ), 'k' ) );
	}

	public function test_bool_returns_the_default_for_a_non_scalar(): void {
		$this->assertTrue( ArrayValue::bool( array( 'k' => array() ), 'k', true ) );
	}

	// ==================================================================
	// array()
	// ==================================================================

	public function test_array_reads_a_nested_array_and_refuses_anything_else(): void {
		$this->assertSame( array( 'a' => 1 ), ArrayValue::array( array( 'k' => array( 'a' => 1 ) ), 'k' ) );
		$this->assertSame( array(), ArrayValue::array( array( 'k' => 'string' ), 'k' ) );
		$this->assertSame( array(), ArrayValue::array( array(), 'k' ) );
	}

	// ==================================================================
	// Integer keys
	// ==================================================================

	public function test_an_integer_key_works_too(): void {
		// Decoded JSON lists are keyed by int, so restricting the key to
		// string would leave exactly the callers that need this the most.
		$this->assertSame( 'first', ArrayValue::string( array( 0 => 'first' ), 0 ) );
	}
}

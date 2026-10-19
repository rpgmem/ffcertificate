<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Audience\AudienceSelfJoinLimit;
use PHPUnit\Framework\TestCase;

/**
 * The self-join group limit reads its option and clamps it (#1916).
 *
 * @covers \FreeFormCertificate\Audience\AudienceSelfJoinLimit
 */
class AudienceSelfJoinLimitTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_an_unset_option_reads_as_the_default(): void {
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => $default );

		$this->assertSame( 2, AudienceSelfJoinLimit::get() );
	}

	/**
	 * @return iterable<string, array{0: mixed, 1: int}>
	 */
	public static function stored_values(): iterable {
		yield 'in range, as stored by the form' => array( 7, 7 );
		yield 'in range, as a string' => array( '7', 7 );
		yield 'the floor' => array( 1, 1 );
		yield 'the ceiling' => array( 20, 20 );
		yield 'zero' => array( 0, 1 );
		yield 'negative' => array( '-4', 1 );
		yield 'above the ceiling' => array( '500', 20 );
		yield 'a fraction' => array( '3.9', 3 );
		yield 'empty' => array( '', 2 );
		yield 'not a number' => array( 'two', 2 );
		yield 'false' => array( false, 2 );
		yield 'an array' => array( array( 5 ), 2 );
	}

	/**
	 * @dataProvider stored_values
	 *
	 * @param mixed $stored   Raw stored value.
	 * @param int   $expected What the reader answers.
	 */
	public function test_a_stored_value_is_clamped_on_read( $stored, int $expected ): void {
		Functions\when( 'get_option' )->alias(
			static fn( $key, $default = false ) => AudienceSelfJoinLimit::OPTION === $key ? $stored : $default
		);

		$this->assertSame( $expected, AudienceSelfJoinLimit::get() );
	}
}

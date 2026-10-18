<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Audience\AudienceSelfJoinScope;
use PHPUnit\Framework\TestCase;

/**
 * The four self-join choices of an audience with children (#1901) and the
 * two flags each one is stored as.
 *
 * @covers \FreeFormCertificate\Audience\AudienceSelfJoinScope
 */
class AudienceSelfJoinScopeTest extends TestCase {

	/**
	 * @return iterable<string, array{0: string, 1: array{0: int, 1: int}}>
	 */
	public static function mapping(): iterable {
		yield 'off' => array( 'off', array( 0, 0 ) );
		yield 'this audience only' => array( 'self', array( 1, 0 ) );
		yield 'audience and descendants' => array( 'all', array( 1, 1 ) );
		yield 'descendants only' => array( 'descendants', array( 0, 1 ) );
	}

	/**
	 * @dataProvider mapping
	 *
	 * @param string                $scope The choice.
	 * @param array{0: int, 1: int} $flags Own flag and descendants' flag.
	 */
	public function test_each_choice_writes_two_flags_and_reads_back_from_them( string $scope, array $flags ): void {
		$this->assertSame( $flags, AudienceSelfJoinScope::flags( $scope ) );
		$this->assertSame( $scope, AudienceSelfJoinScope::from_flags( 1 === $flags[0], (string) $flags[1] ) );
	}

	public function test_the_choices_are_listed_in_the_control_s_order(): void {
		$this->assertSame( array( 'off', 'self', 'all', 'descendants' ), AudienceSelfJoinScope::values() );
	}

	public function test_an_unknown_choice_writes_nothing(): void {
		$this->assertNull( AudienceSelfJoinScope::flags( 'mixed' ) );
		$this->assertNull( AudienceSelfJoinScope::flags( '' ) );
	}

	public function test_mixed_or_absent_descendants_read_as_no_choice(): void {
		foreach ( array( true, false ) as $own ) {
			$this->assertNull( AudienceSelfJoinScope::from_flags( $own, 'mixed' ) );
			$this->assertNull( AudienceSelfJoinScope::from_flags( $own, null ) );
		}
	}
}

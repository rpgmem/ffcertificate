<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UserDashboard\ViewPolicy;

/**
 * Tests for the user-profile read visibility enum.
 *
 * @coversNothing Enum case declarations are not executable statements, so
 * pcov attributes no line coverage here; the assertions still guard the
 * backing values + case set against accidental edits.
 */
class ViewPolicyTest extends TestCase {

	public function test_backing_values(): void {
		$this->assertSame( 'full', ViewPolicy::FULL->value );
		$this->assertSame( 'masked', ViewPolicy::MASKED->value );
		$this->assertSame( 'hashed_only', ViewPolicy::HASHED_ONLY->value );
	}

	public function test_from_resolves_each_case(): void {
		$this->assertSame( ViewPolicy::FULL, ViewPolicy::from( 'full' ) );
		$this->assertSame( ViewPolicy::MASKED, ViewPolicy::from( 'masked' ) );
		$this->assertSame( ViewPolicy::HASHED_ONLY, ViewPolicy::from( 'hashed_only' ) );
	}

	public function test_try_from_returns_null_for_unknown(): void {
		// Route the input through a string-typed helper so the value is not a
		// compile-time literal PHPStan can fold into a guaranteed-null result.
		$this->assertNull( ViewPolicy::tryFrom( self::asString( 'nope' ) ) );
	}

	private static function asString( string $s ): string {
		return $s;
	}

	public function test_cases_are_exhaustive(): void {
		$values = array_map( static fn ( ViewPolicy $c ): string => $c->value, ViewPolicy::cases() );
		$this->assertSame( array( 'full', 'masked', 'hashed_only' ), $values );
	}
}

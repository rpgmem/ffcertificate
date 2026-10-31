<?php
/**
 * Call counter for a WordPress function a test's setUp() already stubbed.
 *
 * @package FreeFormCertificate\Tests
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

use Brain\Monkey\Functions;

/**
 * Records every call to one stubbed function, so a test can assert how many
 * times it ran (#1699).
 *
 * `Functions\expect( 'x' )->never()` does not take effect when `setUp()`
 * already stubbed `x` with `Functions\when()`: the earlier stub keeps
 * answering, the expectation is never consulted, and a call that should
 * fail the test passes. A second `when()` DOES replace the first, so this
 * installs one that counts and answers with the value the test chooses —
 * normally the same value setUp() returned, so a mutation that reaches the
 * call sees the same world as before.
 */
final class CallLog {

	/**
	 * Arguments of each call, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $calls = array();

	/**
	 * Replace the stub for a function with one that records its calls.
	 *
	 * @param string $function Function name.
	 * @param mixed  $returns  Value every call returns.
	 * @return self
	 */
	public static function record( string $function, mixed $returns = null ): self {
		$log = new self();
		Functions\when( $function )->alias(
			static function ( ...$args ) use ( $log, $returns ) {
				$log->calls[] = $args;
				return $returns;
			}
		);
		return $log;
	}

	/**
	 * How many times the function ran.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->calls );
	}

	/**
	 * The arguments of every call, in order.
	 *
	 * @return list<array<int, mixed>>
	 */
	public function calls(): array {
		return $this->calls;
	}
}

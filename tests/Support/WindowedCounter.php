<?php
/**
 * Keys of a fixed-window rate counter, for tests (#2339).
 *
 * `RateCounter::windowed()` puts the window number in the key, and the
 * number moves when the clock crosses a boundary. A test that seeds a counter
 * before the act, or reads one after it, takes one reading of a clock the
 * code reads again -- so it seeds the current window and the next one, and
 * reads the current window and the previous one. A run that crosses the
 * boundary then still finds what it planted.
 *
 * @package FreeFormCertificate\Tests\Support
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Support;

/**
 * The window keys a counter can land under during one test.
 */
final class WindowedCounter {

	/**
	 * Seed a counter under this window and the next.
	 *
	 * @param array<string, mixed> $store   Transient store, by reference.
	 * @param string               $base    Key without its window number.
	 * @param int                  $seconds Window length.
	 * @param int                  $value   Count to plant.
	 * @return void
	 */
	public static function seed( array &$store, string $base, int $seconds, int $value ): void {
		$now = (int) floor( time() / $seconds );
		foreach ( array( $now, $now + 1 ) as $window ) {
			$store[ $base . '_' . $window ] = $value;
		}
	}

	/**
	 * Read a counter from this window or the previous one.
	 *
	 * @param array<string, mixed> $store   Transient store.
	 * @param string               $base    Key without its window number.
	 * @param int                  $seconds Window length.
	 * @return mixed The stored value, or null when neither window holds one.
	 */
	public static function read( array $store, string $base, int $seconds ) {
		$now = (int) floor( time() / $seconds );
		foreach ( array( $now, $now - 1 ) as $window ) {
			if ( array_key_exists( $base . '_' . $window, $store ) ) {
				return $store[ $base . '_' . $window ];
			}
		}
		return null;
	}

	/**
	 * Whether a key is this counter under some window.
	 *
	 * @param string $key  Key to test.
	 * @param string $base Key without its window number.
	 * @return bool
	 */
	public static function matches( string $key, string $base ): bool {
		return 1 === preg_match( '/^' . preg_quote( $base, '/' ) . '_\d+$/', $key );
	}
}

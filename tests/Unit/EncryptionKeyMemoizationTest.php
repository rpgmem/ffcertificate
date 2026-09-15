<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Encryption;

/**
 * The two keys derived from the WordPress constants are computed at most ONCE
 * per process (#1230).
 *
 * Each costs a `hash_pbkdf2( 'sha256', …, 10000, … )` -- measured at 11.56 ms
 * in this environment -- and `encrypt()` / `decrypt_internal()` ask for BOTH per
 * call. Without memoization, one decrypt cost 23.1 ms of pure CPU.
 *
 * HOW THIS TEST PROVES IT, AND WHY IT CANNOT PROVE IT ANOTHER WAY
 *
 * There is no counter to inspect: `hash_pbkdf2` is an internal PHP function and
 * Patchwork does not instrument it. That leaves time -- and time is a fragile
 * assertion unless the difference is enormous. Here it is: 11.56 ms against a
 * property read, a ratio in the thousands. The floor demanded below is
 * deliberately loose (20x), far from the noise of a loaded CI runner, and still
 * impossible to pass without the memoization.
 *
 * The memo is cleared by REFLECTION, not by a production reset method. A public
 * `reset_keys()` would exist only for the test, and would be exactly the
 * indirection that narrows nothing CLAUDE.md warns about -- worse, it would be a
 * door to invalidate at runtime a value that, by construction, never changes.
 *
 * WITHOUT THAT THE TEST WOULD BE ORDER-DEPENDENT, which is the defect class
 * CLAUDE.md itself records: if any earlier test in the process has already
 * touched `Encryption`, the memo is already warm and both measurements come out
 * fast -- green without proving anything.
 *
 * @covers \FreeFormCertificate\Core\Encryption
 */
class EncryptionKeyMemoizationTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Preload for pcov's coverage attribution (see CLAUDE.md).
		class_exists( '\FreeFormCertificate\Core\Encryption' );

		Functions\when( 'get_option' )->justReturn( array() );

		self::clear_memo();
	}

	protected function tearDown(): void {
		// Leave the process as it was found: the memo is static and outlives the
		// method, so a later test would inherit this one's state.
		self::clear_memo();

		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Clears both memoized properties without touching the production API.
	 */
	private static function clear_memo(): void {
		foreach ( array( 'wp_derived_enc_key', 'wp_derived_mac_key' ) as $name ) {
			$property = new \ReflectionProperty( Encryption::class, $name );
			$property->setAccessible( true );
			$property->setValue( null, null );
		}
	}

	/**
	 * Reads one of the derived keys, timing the call.
	 *
	 * @param string $method Name of the private method.
	 * @return array{0: string, 1: float} The value and the duration in seconds.
	 */
	private static function time_call( string $method ): array {
		$reflection = new \ReflectionMethod( Encryption::class, $method );
		$reflection->setAccessible( true );

		$started = microtime( true );
		$value   = (string) $reflection->invoke( null );

		return array( $value, microtime( true ) - $started );
	}

	/**
	 * @dataProvider derived_key_methods
	 *
	 * @param string $method The deriving method under test.
	 */
	public function test_derived_key_is_computed_once_and_reused( string $method ): void {
		list( $first, $cold ) = self::time_call( $method );
		list( $second, $warm ) = self::time_call( $method );

		// The value does not change -- the property that makes memoization correct.
		$this->assertSame( $first, $second, 'The memoized key diverged from the freshly derived one.' );
		$this->assertSame( 32, strlen( $first ), 'The derivation must return 32 raw bytes.' );

		// And the second read does not redo the work.
		$this->assertGreaterThan(
			20 * $warm,
			$cold,
			sprintf(
				'The second call to %s() cost %.4f ms against the first call\'s %.4f ms: the PBKDF2 is being redone.',
				$method,
				$warm * 1000,
				$cold * 1000
			)
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function derived_key_methods(): array {
		return array(
			'cipher key' => array( 'wp_derived_encryption_key' ),
			'HMAC key'   => array( 'wp_derived_hmac_key' ),
		);
	}

	/**
	 * The two keys are DIFFERENT from each other -- each has its own PBKDF2 salt,
	 * and that is what makes them cryptographically independent. A memoization
	 * that shared the property by mistake would pass everything above and break
	 * here.
	 */
	public function test_the_two_memoized_keys_stay_independent(): void {
		list( $enc ) = self::time_call( 'wp_derived_encryption_key' );
		list( $mac ) = self::time_call( 'wp_derived_hmac_key' );

		$this->assertNotSame( $enc, $mac, 'The cipher and HMAC keys collided: the salts are not separated.' );
	}

	/**
	 * The memoization must not change what a ciphertext means: a value encrypted
	 * before the memo warmed up stays readable afterwards, and vice versa.
	 *
	 * It is what separates "do not recompute" from "compute something else".
	 */
	public function test_ciphertext_survives_a_cold_and_a_warm_memo(): void {
		$plain = 'cpf-12345678901';

		// Encrypt with the memo cold.
		self::clear_memo();
		$encrypted = Encryption::encrypt( $plain );
		$this->assertNotNull( $encrypted, 'Encryption failed with the memo cold.' );

		// Decrypt with the memo warm (the encryption above just filled it).
		$this->assertSame( $plain, Encryption::decrypt( (string) $encrypted ) );

		// And decrypt the SAME ciphertext with the memo cold again.
		self::clear_memo();
		$this->assertSame(
			$plain,
			Encryption::decrypt( (string) $encrypted ),
			'A ciphertext written before the memo stopped being readable after clearing it.'
		);
	}
}

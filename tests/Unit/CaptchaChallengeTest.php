<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Captcha\ChallengeSigner;
use FreeFormCertificate\Core\Captcha\ChallengeStore;

/**
 * Tests for the captcha challenge primitives introduced in 6.23.0:
 * keyed signing and single-redemption bookkeeping.
 *
 * @covers \FreeFormCertificate\Core\Captcha\ChallengeSigner
 * @covers \FreeFormCertificate\Core\Captcha\ChallengeStore
 */
class CaptchaChallengeTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * In-memory stand-in for the transient store.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	/**
	 * TTL passed to the last set_transient() call.
	 *
	 * @var int|null
	 */
	private ?int $last_ttl = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// pcov does not attribute coverage to a class first autoloaded during
		// a test method, so preload both under test.
		class_exists( '\FreeFormCertificate\Core\Captcha\ChallengeSigner' );
		class_exists( '\FreeFormCertificate\Core\Captcha\ChallengeStore' );

		$this->transients = array();

		Functions\when( 'wp_salt' )->alias(
			static function ( string $scheme = 'auth' ): string {
				return 'test-salt-' . $scheme;
			}
		);

		Functions\when( 'get_transient' )->alias(
			function ( string $key ) {
				return $this->transients[ $key ] ?? false;
			}
		);

		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value, int $ttl = 0 ): bool {
				$this->transients[ $key ] = $value;
				$this->last_ttl           = $ttl;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// ChallengeSigner
	// ==================================================================

	public function test_sign_is_deterministic_for_the_same_payload(): void {
		$this->assertSame( ChallengeSigner::sign( 'math|7|123' ), ChallengeSigner::sign( 'math|7|123' ) );
	}

	public function test_sign_returns_a_sha256_hex_digest(): void {
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', ChallengeSigner::sign( 'math|7|123' ) );
	}

	public function test_sign_differs_for_different_payloads(): void {
		$this->assertNotSame( ChallengeSigner::sign( 'math|7|123' ), ChallengeSigner::sign( 'math|8|123' ) );
	}

	public function test_signature_is_keyed_not_a_plain_hash(): void {
		// The whole defect being fixed was a digest an attacker could
		// recompute. Any unkeyed digest of the payload must not match.
		$payload = 'math|7|123';

		$this->assertNotSame( hash( 'sha256', $payload ), ChallengeSigner::sign( $payload ) );
		$this->assertNotSame( hash( 'sha256', '7ffc_math_salt' ), ChallengeSigner::sign( $payload ) );
	}

	public function test_matches_accepts_an_authentic_signature(): void {
		$this->assertTrue( ChallengeSigner::matches( 'math|7|123', ChallengeSigner::sign( 'math|7|123' ) ) );
	}

	public function test_matches_rejects_a_signature_for_another_payload(): void {
		$this->assertFalse( ChallengeSigner::matches( 'math|8|123', ChallengeSigner::sign( 'math|7|123' ) ) );
	}

	public function test_matches_rejects_an_empty_signature(): void {
		$this->assertFalse( ChallengeSigner::matches( 'math|7|123', '' ) );
	}

	// ==================================================================
	// ChallengeStore
	// ==================================================================

	public function test_redeem_admits_the_first_presentation(): void {
		$this->assertTrue( ChallengeStore::redeem( 'proof-a', 600 ) );
	}

	public function test_redeem_refuses_the_second_presentation(): void {
		ChallengeStore::redeem( 'proof-a', 600 );

		$this->assertFalse( ChallengeStore::redeem( 'proof-a', 600 ) );
	}

	public function test_redeem_tracks_proofs_independently(): void {
		$this->assertTrue( ChallengeStore::redeem( 'proof-a', 600 ) );
		$this->assertTrue( ChallengeStore::redeem( 'proof-b', 600 ) );
		$this->assertFalse( ChallengeStore::redeem( 'proof-a', 600 ) );
	}

	public function test_redeem_refuses_an_empty_proof(): void {
		$this->assertFalse( ChallengeStore::redeem( '', 600 ) );
	}

	public function test_redeem_writes_a_key_the_uninstall_sweep_matches(): void {
		// uninstall.php removes `_transient_ffc_%`; a key without the `ffc_`
		// prefix would leak rows that nothing cleans up.
		ChallengeStore::redeem( 'proof-a', 600 );

		$keys = array_keys( $this->transients );
		$this->assertCount( 1, $keys );
		$this->assertStringStartsWith( 'ffc_', $keys[0] );
	}

	public function test_redeem_key_does_not_embed_the_proof(): void {
		ChallengeStore::redeem( 'proof-a', 600 );

		$keys = array_keys( $this->transients );
		$this->assertStringNotContainsString( 'proof-a', $keys[0] );
	}

	public function test_redeem_floors_a_short_ttl(): void {
		// A challenge redeemed in its final second must still be remembered
		// long enough to block an immediate replay.
		ChallengeStore::redeem( 'proof-late', 1 );

		$this->assertSame( 60, $this->last_ttl );
	}

	public function test_redeem_keeps_a_longer_ttl(): void {
		ChallengeStore::redeem( 'proof-long', 600 );

		$this->assertSame( 600, $this->last_ttl );
	}
}

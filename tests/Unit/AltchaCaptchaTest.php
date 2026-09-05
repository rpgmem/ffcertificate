<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Captcha\AltchaCaptcha;

/**
 * Tests for the ALTCHA proof-of-work strategy.
 *
 * These solve the challenge the way the widget does — count up until the hash
 * reproduces — so they exercise the real wire format end to end without a
 * browser. A test that hand-built the payload from the class's own internals
 * would pass even if the format drifted away from what the widget posts.
 *
 * @covers \FreeFormCertificate\Core\Captcha\AltchaCaptcha
 * @covers \FreeFormCertificate\Core\Captcha\ChallengeSigner
 * @covers \FreeFormCertificate\Core\Captcha\ChallengeStore
 */
class AltchaCaptchaTest extends TestCase {

	/** @var array<string, mixed> Transient store standing in for WordPress. */
	private array $transients = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// pcov attributes coverage only to classes loaded before the test
		// method runs.
		class_exists( '\FreeFormCertificate\Core\Captcha\AltchaCaptcha' );
		class_exists( '\FreeFormCertificate\Core\Captcha\ChallengeSigner' );
		class_exists( '\FreeFormCertificate\Core\Captcha\ChallengeStore' );

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_salt' )->justReturn( 'unit-test-salt' );

		$this->transients = array();
		$store            = &$this->transients;

		Functions\when( 'get_transient' )->alias(
			function ( $key ) use ( &$store ) {
				return $store[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) use ( &$store ) {
				$store[ $key ] = $value;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Solve a challenge exactly as the widget does.
	 *
	 * The counter is hashed as a decimal string, which is the widget's
	 * `counterMode: 'string'` for this challenge format. Hashing it as an
	 * integer would agree for small numbers and diverge silently later.
	 *
	 * @param array<string, mixed> $challenge Challenge as issued.
	 * @return string Base64 payload the widget would post.
	 */
	private function solve( array $challenge ): string {
		$salt = (string) $challenge['salt'];

		for ( $n = 0; $n <= AltchaCaptcha::COMPLEXITY; $n++ ) {
			if ( hash( 'sha256', $salt . (string) $n ) === $challenge['challenge'] ) {
				return $this->encode(
					array(
						'algorithm' => $challenge['algorithm'],
						'challenge' => $challenge['challenge'],
						'number'    => $n,
						'salt'      => $salt,
						'signature' => $challenge['signature'],
						'took'      => 12,
					)
				);
			}
		}

		$this->fail( 'No solution below the published maxnumber — the challenge is unsolvable as issued.' );
	}

	/**
	 * @param array<string, mixed> $payload Solution payload.
	 */
	private function encode( array $payload ): string {
		return base64_encode( (string) json_encode( $payload ) );
	}

	// ==================================================================
	// create_challenge()
	// ==================================================================

	public function test_challenge_carries_the_v1_wire_format(): void {
		$challenge = AltchaCaptcha::create_challenge();

		$this->assertSame( 'SHA-256', $challenge['algorithm'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $challenge['challenge'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{24}\?expires=\d+$/', $challenge['salt'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $challenge['signature'] );
	}

	public function test_two_challenges_never_share_a_salt(): void {
		// The salt is what makes one visitor's work useless to another. Two
		// identical salts would mean one solution answering both challenges.
		$a = AltchaCaptcha::create_challenge();
		$b = AltchaCaptcha::create_challenge();

		$this->assertNotSame( $a['salt'], $b['salt'] );
		$this->assertNotSame( $a['challenge'], $b['challenge'] );
	}

	public function test_challenge_is_solvable_and_the_solution_verifies(): void {
		$provider  = new AltchaCaptcha();
		$challenge = AltchaCaptcha::create_challenge();

		$result = $provider->verify( array( AltchaCaptcha::FIELD => $this->solve( $challenge ) ) );

		$this->assertTrue( $result );
	}

	// ==================================================================
	// verify() — single use
	// ==================================================================

	public function test_a_solution_verifies_once_and_is_then_spent(): void {
		$provider = new AltchaCaptcha();
		$solution = $this->solve( AltchaCaptcha::create_challenge() );

		$this->assertTrue( $provider->verify( array( AltchaCaptcha::FIELD => $solution ) ) );
		$this->assertIsString(
			$provider->verify( array( AltchaCaptcha::FIELD => $solution ) ),
			'Replaying a solved challenge must be refused — this is the #1054 property.'
		);
	}

	// ==================================================================
	// peek() — the #1061 contract
	// ==================================================================

	public function test_peek_accepts_without_spending(): void {
		$provider = new AltchaCaptcha();
		$solution = $this->solve( AltchaCaptcha::create_challenge() );

		$this->assertTrue( $provider->peek( array( AltchaCaptcha::FIELD => $solution ) ) );
		$this->assertTrue(
			$provider->verify( array( AltchaCaptcha::FIELD => $solution ) ),
			'A peeked solution must still be spendable, or the two-request CSV flow breaks again.'
		);
	}

	public function test_peek_refuses_an_already_spent_solution(): void {
		$provider = new AltchaCaptcha();
		$solution = $this->solve( AltchaCaptcha::create_challenge() );

		$provider->verify( array( AltchaCaptcha::FIELD => $solution ) );

		$this->assertIsString(
			$provider->peek( array( AltchaCaptcha::FIELD => $solution ) ),
			'Reporting a spent proof as valid only moves the contradiction one request downstream.'
		);
	}

	// ==================================================================
	// verify() — rejection paths
	// ==================================================================

	public function test_a_forged_signature_is_refused(): void {
		$provider  = new AltchaCaptcha();
		$challenge = AltchaCaptcha::create_challenge();
		$solved    = json_decode( (string) base64_decode( $this->solve( $challenge ), true ), true );

		$solved['signature'] = str_repeat( 'a', 64 );

		$this->assertIsString( $provider->verify( array( AltchaCaptcha::FIELD => $this->encode( $solved ) ) ) );
	}

	public function test_a_wrong_number_is_refused(): void {
		$provider  = new AltchaCaptcha();
		$challenge = AltchaCaptcha::create_challenge();
		$solved    = json_decode( (string) base64_decode( $this->solve( $challenge ), true ), true );

		++$solved['number'];

		$this->assertIsString( $provider->verify( array( AltchaCaptcha::FIELD => $this->encode( $solved ) ) ) );
	}

	public function test_an_expired_challenge_is_refused(): void {
		// The salt carries the expiry and is bound to the challenge hash, so
		// an expired one can only be produced by issuing it that way.
		$provider = new AltchaCaptcha();
		$expires  = time() - 1;
		$salt     = bin2hex( random_bytes( 12 ) ) . '?expires=' . $expires;
		$number   = 7;
		$hash     = hash( 'sha256', $salt . $number );

		$payload = array(
			'algorithm' => 'SHA-256',
			'challenge' => $hash,
			'number'    => $number,
			'salt'      => $salt,
			'signature' => \FreeFormCertificate\Core\Captcha\ChallengeSigner::sign( $hash ),
		);

		$this->assertIsString( $provider->verify( array( AltchaCaptcha::FIELD => $this->encode( $payload ) ) ) );
	}

	public function test_a_salt_without_an_expiry_is_refused(): void {
		// An unsigned salt cannot extend its own life, but it can omit the
		// expiry entirely — which must not read as "never expires".
		$provider = new AltchaCaptcha();
		$salt     = bin2hex( random_bytes( 12 ) );
		$number   = 3;
		$hash     = hash( 'sha256', $salt . $number );

		$payload = array(
			'algorithm' => 'SHA-256',
			'challenge' => $hash,
			'number'    => $number,
			'salt'      => $salt,
			'signature' => \FreeFormCertificate\Core\Captcha\ChallengeSigner::sign( $hash ),
		);

		$this->assertIsString( $provider->verify( array( AltchaCaptcha::FIELD => $this->encode( $payload ) ) ) );
	}

	/**
	 * @dataProvider malformedPayloads
	 * @param mixed $raw Request value.
	 */
	public function test_malformed_payloads_are_refused_without_fataling( $raw ): void {
		// Every byte here is attacker-controlled. Under strict_types an array
		// reaching hash() is a TypeError, not a rejection — the #1058 class.
		$provider = new AltchaCaptcha();

		$this->assertIsString( $provider->verify( array( AltchaCaptcha::FIELD => $raw ) ) );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function malformedPayloads(): array {
		return array(
			'missing'            => array( null ),
			'empty'              => array( '' ),
			'not base64'         => array( '!!!not base64!!!' ),
			'not json'           => array( base64_encode( 'plain text' ) ),
			'json scalar'        => array( base64_encode( '"a string"' ) ),
			'missing keys'       => array( base64_encode( '{"algorithm":"SHA-256"}' ) ),
			'array where string' => array( base64_encode( '{"algorithm":"SHA-256","challenge":"x","salt":["a"],"signature":"y","number":1}' ) ),
			'number not numeric' => array( base64_encode( '{"algorithm":"SHA-256","challenge":"x","salt":"y","signature":"z","number":"12a"}' ) ),
			'wrong algorithm'    => array( base64_encode( '{"algorithm":"MD5","challenge":"x","salt":"y","signature":"z","number":1}' ) ),
		);
	}

	// ==================================================================
	// challenge_payload()
	// ==================================================================

	public function test_challenge_payload_names_the_provider_and_carries_nothing_else(): void {
		// The widget fetches its own challenge, so there is nothing for the
		// cached-page refresh to patch — only a name so the client can tell
		// "nothing to do" from "provider I do not know".
		$this->assertSame( array( 'provider' => 'altcha' ), ( new AltchaCaptcha() )->challenge_payload() );
	}
}

<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Captcha\AltchaCaptcha;
use FreeFormCertificate\Core\Captcha\CaptchaSettings;
use FreeFormCertificate\Core\Captcha\ChallengeSigner;
use FreeFormCertificate\Tests\Support\AltchaSolver;

/**
 * Tests for the ALTCHA proof-of-work strategy.
 *
 * These solve the challenge the way the widget does — count up until the
 * derived key starts with the published prefix — so they exercise the real
 * wire format end to end without a
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
		// The work factor and the lifetime are settings now, read through
		// CaptchaSettings; with no stored option they fall to the declared
		// defaults, which is the configuration these tests mean to exercise.
		Functions\when( 'get_option' )->justReturn( array() );

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
	 * @param array<string, mixed> $challenge Challenge as issued.
	 * @return string Base64 payload the widget would post.
	 */
	private function solve( array $challenge ): string {
		return AltchaSolver::payload( $challenge, $this->counter_of( $challenge ) );
	}

	/**
	 * The counter that solves a challenge, bounded by the configured work factor.
	 *
	 * @param array<string, mixed> $challenge Challenge as issued.
	 * @return int
	 */
	private function counter_of( array $challenge ): int {
		$counter = AltchaSolver::counter_of( $challenge, CaptchaSettings::complexity() );
		$this->assertNotNull( $counter, 'No counter up to the work factor solves the challenge — it is unsolvable as issued.' );

		return (int) $counter;
	}

	/**
	 * A payload decoded back to an array, for tampering.
	 *
	 * @param string $payload Base64 payload.
	 * @return array<string, mixed>
	 */
	private function decoded( string $payload ): array {
		return json_decode( (string) base64_decode( $payload, true ), true );
	}

	/**
	 * Sign parameters the way the class does, for challenges it would not issue.
	 *
	 * @param array<string, mixed> $parameters Challenge parameters.
	 * @return string
	 */
	private function sign( array $parameters ): string {
		$method = new \ReflectionMethod( AltchaCaptcha::class, 'signed_payload' );
		$method->setAccessible( true );

		return ChallengeSigner::sign( (string) $method->invoke( null, $parameters ) );
	}

	// ==================================================================
	// create_challenge()
	// ==================================================================

	public function test_challenge_carries_the_v2_wire_format(): void {
		$challenge  = AltchaCaptcha::create_challenge();
		$parameters = $challenge['parameters'];

		$this->assertSame( array( 'parameters', 'signature' ), array_keys( $challenge ) );
		$this->assertSame( 'SHA-256', $parameters['algorithm'] );
		$this->assertSame( 1, $parameters['cost'] );
		$this->assertSame( 32, $parameters['keyLength'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $parameters['keyPrefix'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $parameters['nonce'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $parameters['salt'] );
		$this->assertGreaterThan( time(), $parameters['expiresAt'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $challenge['signature'] );
		$this->assertArrayNotHasKey( 'challenge', $challenge, 'A top-level `challenge` key is the v1 shape, which ALTCHA 3.4.0 can no longer read.' );
	}

	public function test_two_challenges_never_share_a_nonce_or_salt(): void {
		// The nonce and salt are what make one visitor's work useless to
		// another. Two identical pairs would mean one solution answering both.
		$a = AltchaCaptcha::create_challenge()['parameters'];
		$b = AltchaCaptcha::create_challenge()['parameters'];

		$this->assertNotSame( $a['nonce'], $b['nonce'] );
		$this->assertNotSame( $a['salt'], $b['salt'] );
	}

	public function test_challenge_is_solvable_and_the_solution_verifies(): void {
		$provider  = new AltchaCaptcha();
		$challenge = AltchaCaptcha::create_challenge();

		$this->assertTrue( $provider->verify( array( AltchaCaptcha::FIELD => $this->solve( $challenge ) ) ) );
	}

	public function test_the_key_is_derived_from_salt_nonce_and_a_big_endian_counter(): void {
		// Pinned against a value computed outside the class, so a change to
		// the byte order or the concatenation fails here and not in a browser.
		$parameters = array(
			'salt'  => str_repeat( '00', 16 ),
			'nonce' => str_repeat( 'ff', 16 ),
		);

		$this->assertSame(
			hash( 'sha256', str_repeat( "\x00", 16 ) . str_repeat( "\xff", 16 ) . "\x00\x00\x01\x02" ),
			AltchaSolver::key( $parameters, 258 )
		);
	}

	// ==================================================================
	// verify() — every parameter is signed
	// ==================================================================

	/**
	 * Every issued parameter is covered by the signature.
	 *
	 * v1 carried the expiry inside the salt, and the salt/number boundary
	 * could be moved without changing the hashed string (CVE-2025-68113,
	 * #1357). v2 has no such boundary: each field is signed on its own. This
	 * changes each one and expects a refusal, so a field the signature
	 * stopped covering fails here.
	 *
	 * @dataProvider tamperedParameters
	 * @param string     $key   Parameter to change.
	 * @param int|string $value Value to put there.
	 */
	public function test_a_tampered_parameter_is_refused( string $key, $value ): void {
		$provider  = new AltchaCaptcha();
		$challenge = AltchaCaptcha::create_challenge();
		$counter   = $this->counter_of( $challenge );

		$challenge['parameters'][ $key ] = $value;

		$this->assertIsString( $provider->verify( array( AltchaCaptcha::FIELD => AltchaSolver::payload( $challenge, $counter ) ) ) );
	}

	/**
	 * @return array<string, array{0: string, 1: int|string}>
	 */
	public static function tamperedParameters(): array {
		return array(
			'expiry pushed out'  => array( 'expiresAt', 4102444800 ),
			'easier key prefix'  => array( 'keyPrefix', str_repeat( '0', 32 ) ),
			'another nonce'      => array( 'nonce', str_repeat( 'a', 32 ) ),
			'another salt'       => array( 'salt', str_repeat( 'b', 32 ) ),
			'another algorithm'  => array( 'algorithm', 'SHA-512' ),
			'more rounds'        => array( 'cost', 2 ),
			'shorter key'        => array( 'keyLength', 16 ),
		);
	}

	public function test_an_added_parameter_does_not_change_what_is_verified(): void {
		// Unknown fields are dropped before signing, so a client cannot widen
		// what the signature covers -- and an honest client that echoes an
		// extra field it was never sent is still accepted.
		$provider  = new AltchaCaptcha();
		$challenge = AltchaCaptcha::create_challenge();
		$counter   = $this->counter_of( $challenge );

		$challenge['parameters']['data'] = array( 'note' => 'x' );

		$this->assertTrue( $provider->verify( array( AltchaCaptcha::FIELD => AltchaSolver::payload( $challenge, $counter ) ) ) );
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
		$provider = new AltchaCaptcha();
		$solved   = $this->decoded( $this->solve( AltchaCaptcha::create_challenge() ) );

		$solved['challenge']['signature'] = str_repeat( 'a', 64 );

		$this->assertIsString( $provider->verify( array( AltchaCaptcha::FIELD => AltchaSolver::encode( $solved ) ) ) );
	}

	public function test_a_wrong_counter_is_refused(): void {
		// The next counter, with the key honestly derived for it: the key
		// matches the counter but not the prefix, which is the check that
		// carries the work.
		$provider  = new AltchaCaptcha();
		$challenge = AltchaCaptcha::create_challenge();
		$counter   = $this->counter_of( $challenge ) + 1;

		$this->assertIsString( $provider->verify( array( AltchaCaptcha::FIELD => AltchaSolver::payload( $challenge, $counter ) ) ) );
	}

	public function test_a_derived_key_that_does_not_match_its_counter_is_refused(): void {
		// The right prefix copied into the key with a counter that does not
		// produce it: claiming the work without doing it.
		$provider  = new AltchaCaptcha();
		$challenge = AltchaCaptcha::create_challenge();
		$solved    = $this->decoded( $this->solve( $challenge ) );

		$solved['solution']['counter']    = $solved['solution']['counter'] + 1;
		$solved['solution']['derivedKey'] = $challenge['parameters']['keyPrefix'] . str_repeat( '0', 32 );

		$this->assertIsString( $provider->verify( array( AltchaCaptcha::FIELD => AltchaSolver::encode( $solved ) ) ) );
	}

	public function test_an_expired_challenge_is_refused_as_expired(): void {
		// Issued by hand with an expiry in the past and a valid signature, so
		// the only thing wrong with it is its age.
		$provider   = new AltchaCaptcha();
		$parameters = array(
			'algorithm' => 'SHA-256',
			'cost'      => 1,
			'expiresAt' => time() - 1,
			'keyLength' => 32,
			'keyPrefix' => '',
			'nonce'     => bin2hex( random_bytes( 16 ) ),
			'salt'      => bin2hex( random_bytes( 16 ) ),
		);
		$parameters['keyPrefix'] = substr( AltchaSolver::key( $parameters, 7 ), 0, 32 );
		$challenge               = array(
			'parameters' => $parameters,
			'signature'  => $this->sign( $parameters ),
		);

		$this->assertSame(
			'Error: The verification expired. Please try again.',
			$provider->verify( array( AltchaCaptcha::FIELD => AltchaSolver::payload( $challenge, 7 ) ) )
		);
	}

	public function test_a_v1_payload_is_refused(): void {
		// The format this class issued before #1928. Refused rather than
		// translated: a visitor holding one re-solves a fresh challenge.
		$provider = new AltchaCaptcha();
		$salt     = bin2hex( random_bytes( 12 ) ) . '?expires=' . ( time() + 600 );

		$payload = AltchaSolver::encode(
			array(
				'algorithm' => 'SHA-256',
				'challenge' => hash( 'sha256', $salt . '7' ),
				'number'    => 7,
				'salt'      => $salt,
				'signature' => str_repeat( 'a', 64 ),
			)
		);

		$this->assertSame(
			'Error: Please complete the verification.',
			$provider->verify( array( AltchaCaptcha::FIELD => $payload ) )
		);
	}

	/**
	 * @dataProvider malformedPayloads
	 * @param mixed $raw Request value.
	 */
	public function test_malformed_payloads_are_refused_without_fataling( $raw ): void {
		// Every byte here is attacker-controlled. Under strict_types an array
		// reaching hex2bin() is a TypeError, not a rejection — the #1058 class.
		$provider = new AltchaCaptcha();

		$this->assertIsString( $provider->verify( array( AltchaCaptcha::FIELD => $raw ) ) );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function malformedPayloads(): array {
		$params = array(
			'algorithm' => 'SHA-256',
			'cost'      => 1,
			'expiresAt' => 4102444800,
			'keyLength' => 32,
			'keyPrefix' => str_repeat( 'a', 32 ),
			'nonce'     => str_repeat( 'b', 32 ),
			'salt'      => str_repeat( 'c', 32 ),
		);
		$wrap   = static function ( array $parameters, array $solution, $signature = 'sig' ): string {
			return base64_encode(
				(string) json_encode(
					array(
						'challenge' => array(
							'parameters' => $parameters,
							'signature'  => $signature,
						),
						'solution'  => $solution,
					)
				)
			);
		};
		$good   = array(
			'counter'    => 1,
			'derivedKey' => str_repeat( 'd', 64 ),
		);

		return array(
			'missing'                  => array( null ),
			'empty'                    => array( '' ),
			'not base64'               => array( '!!!not base64!!!' ),
			'not json'                 => array( base64_encode( 'plain text' ) ),
			'json scalar'              => array( base64_encode( '"a string"' ) ),
			'no solution'              => array( base64_encode( '{"challenge":{"parameters":{},"signature":"x"}}' ) ),
			'parameters not an object' => array( base64_encode( '{"challenge":{"parameters":"x","signature":"x"},"solution":{"counter":1,"derivedKey":"' . str_repeat( 'd', 64 ) . '"}}' ) ),
			'signature not a string'   => array( $wrap( $params, $good, array( 'x' ) ) ),
			'salt is an array'         => array( $wrap( array_merge( $params, array( 'salt' => array( 'a' ) ) ), $good ) ),
			'nonce not hex'            => array( $wrap( array_merge( $params, array( 'nonce' => str_repeat( 'z', 32 ) ) ), $good ) ),
			'expiry as a string'       => array( $wrap( array_merge( $params, array( 'expiresAt' => '4102444800' ) ), $good ) ),
			'counter as a string'      => array( $wrap( $params, array_merge( $good, array( 'counter' => '1' ) ) ) ),
			'counter negative'         => array( $wrap( $params, array_merge( $good, array( 'counter' => -1 ) ) ) ),
			'counter above uint32'     => array( $wrap( $params, array_merge( $good, array( 'counter' => 4294967296 ) ) ) ),
			'derived key uppercase'    => array( $wrap( $params, array_merge( $good, array( 'derivedKey' => str_repeat( 'D', 64 ) ) ) ) ),
			'derived key short'        => array( $wrap( $params, array_merge( $good, array( 'derivedKey' => 'dd' ) ) ) ),
			'wrong algorithm'          => array( $wrap( array_merge( $params, array( 'algorithm' => 'MD5' ) ), $good ) ),
		);
	}

	// ==================================================================
	// render_fields() — asset wiring
	// ==================================================================

	public function test_rendering_the_widget_enqueues_its_script(): void {
		/*
		 * The regression this pins: the enqueue used to live in a page-type
		 * branch in `Frontend::enqueue_scripts()`, gated on the certificate
		 * form or the verification page. The public CSV download is neither,
		 * so it rendered the widget's container with nothing to fill it — an
		 * empty box and no console error. The self-scheduling booking form
		 * would have been the same, since a different class enqueues it.
		 *
		 * Tying the enqueue to the render is what makes the surface list
		 * irrelevant, and this asserts that binding rather than the list.
		 */
		$enqueued = array();

		Functions\when( 'wp_enqueue_script' )->alias(
			function ( string $handle ) use ( &$enqueued ): void {
				$enqueued[] = $handle;
			}
		);
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( string $text ): void { echo $text; } );
		Functions\when( 'get_locale' )->justReturn( 'pt_BR' );
		Functions\when( 'admin_url' )->alias( static fn( string $p = '' ): string => 'https://example.test/wp-admin/' . $p );
		Functions\when( 'add_query_arg' )->alias( static fn( array $a, string $u ): string => $u . '?' . http_build_query( $a ) );
		Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );

		$html = ( new AltchaCaptcha() )->render_fields();

		$this->assertContains( 'ffc-captcha', $enqueued );
		$this->assertStringContainsString( '<altcha-widget', $html );
	}

	public function test_the_rendered_language_matches_the_one_the_glue_registers(): void {
		// The widget selects its strings by the `language` attribute. If the
		// attribute and the registered i18n key disagree, it falls back to
		// English — silently, with nothing in the console.
		Functions\when( 'wp_enqueue_script' )->justReturn( true );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( string $text ): void { echo $text; } );
		Functions\when( 'get_locale' )->justReturn( 'pt_BR' );
		Functions\when( 'admin_url' )->alias( static fn( string $p = '' ): string => 'https://example.test/wp-admin/' . $p );
		Functions\when( 'add_query_arg' )->alias( static fn( array $a, string $u ): string => $u . '?' . http_build_query( $a ) );
		Functions\when( 'wp_json_encode' )->alias( static fn( $d ) => json_encode( $d ) );

		$localized = array();
		Functions\when( 'wp_register_script' )->justReturn( true );
		Functions\when( 'wp_localize_script' )->alias(
			function ( string $handle, string $object, array $data ) use ( &$localized ): void {
				$localized = $data;
			}
		);

		AltchaCaptcha::register_assets();
		$html = ( new AltchaCaptcha() )->render_fields();

		$this->assertSame( 'pt-br', $localized['language'] );
		$this->assertStringContainsString( 'language="pt-br"', $html );
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

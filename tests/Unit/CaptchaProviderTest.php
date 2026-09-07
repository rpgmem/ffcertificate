<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Captcha\CaptchaProvider;
use FreeFormCertificate\Core\Captcha\CaptchaProviderInterface;
use FreeFormCertificate\Core\Captcha\MathCaptcha;
use FreeFormCertificate\Core\SecurityService;

/**
 * Tests for the captcha strategy contract and its resolver (#1053 PR2).
 *
 * @covers \FreeFormCertificate\Core\Captcha\CaptchaProvider
 * @covers \FreeFormCertificate\Core\Captcha\MathCaptcha
 * @covers \FreeFormCertificate\Core\SecurityService
 */
class CaptchaProviderTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * In-memory stand-in for the transient store.
	 *
	 * @var array<string, mixed>
	 */
	private array $transients = array();

	/**
	 * Value `ffc_settings` resolves to.
	 *
	 * @var array<string, mixed>
	 */
	private array $settings = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// pcov does not attribute coverage to a class first autoloaded during
		// a test method.
		class_exists( '\FreeFormCertificate\Core\Captcha\CaptchaProvider' );
		class_exists( '\FreeFormCertificate\Core\Captcha\MathCaptcha' );
		class_exists( '\FreeFormCertificate\Core\SecurityService' );

		CaptchaProvider::reset();
		MathCaptcha::reset_instances();

		$this->settings   = array();
		$this->transients = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( string $text ): void { echo $text; } );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'wp_rand' )->alias(
			static function ( int $min = 0, int $max = 0 ): int {
				return random_int( $min, $max );
			}
		);
		Functions\when( 'wp_salt' )->alias(
			static function ( string $scheme = 'auth' ): string {
				return 'test-salt-' . $scheme;
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( string $key, $default = false ) {
				return 'ffc_settings' === $key ? $this->settings : $default;
			}
		);
		Functions\when( 'get_transient' )->alias(
			function ( string $key ) {
				return $this->transients[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ): bool {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		CaptchaProvider::reset();
		MathCaptcha::reset_instances();
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// CaptchaProvider
	// ==================================================================

	public function test_resolve_returns_the_math_strategy_by_default(): void {
		$provider = CaptchaProvider::resolve();

		$this->assertInstanceOf( CaptchaProviderInterface::class, $provider );
		$this->assertSame( MathCaptcha::ID, $provider->id() );
	}

	public function test_resolve_honours_a_configured_provider(): void {
		$this->settings = array( CaptchaProvider::SETTING_KEY => MathCaptcha::ID );

		$this->assertSame( MathCaptcha::ID, CaptchaProvider::resolve()->id() );
	}

	public function test_resolve_falls_back_to_math_on_an_unknown_value(): void {
		// A typo in an option must not take the public forms down, and math is
		// the strategy with the fewest runtime requirements.
		$this->settings = array( CaptchaProvider::SETTING_KEY => 'not-a-provider' );

		$this->assertSame( MathCaptcha::ID, CaptchaProvider::resolve()->id() );
	}

	public function test_resolve_memoises_the_instance(): void {
		$this->assertSame( CaptchaProvider::resolve(), CaptchaProvider::resolve() );
	}

	public function test_reset_clears_the_memoised_instance(): void {
		$first = CaptchaProvider::resolve();
		CaptchaProvider::reset();

		$this->assertNotSame( $first, CaptchaProvider::resolve() );
	}

	public function test_supports_recognises_only_registered_ids(): void {
		$this->assertTrue( CaptchaProvider::supports( MathCaptcha::ID ) );
		$this->assertFalse( CaptchaProvider::supports( 'not-a-provider' ) );
		$this->assertFalse( CaptchaProvider::supports( '' ) );
	}

	public function test_available_lists_math(): void {
		$this->assertContains( MathCaptcha::ID, CaptchaProvider::available() );
	}

	// ==================================================================
	// MathCaptcha
	// ==================================================================

	public function test_math_render_fields_emits_the_challenge_inputs(): void {
		$html = ( new MathCaptcha() )->render_fields();

		$this->assertStringContainsString( 'ffc-captcha-row', $html );
		$this->assertStringContainsString( 'name="ffc_captcha_ans"', $html );
		$this->assertStringContainsString( 'name="ffc_captcha_hash"', $html );
		$this->assertMatchesRegularExpression( '/value="\d+\.[0-9a-f]{16}\.[0-9a-f]{64}"/', $html );
	}

	public function test_math_render_fields_gives_each_instance_distinct_ids(): void {
		// The plugin supports several forms on one page (DynamicFragments has a
		// branch for it). Fixed ids duplicated across them and broke the
		// `<label for>` association a screen reader needs (#1056).
		$provider = new MathCaptcha();

		$first  = $provider->render_fields();
		$second = $provider->render_fields();

		preg_match_all( '/id="(ffc_captcha_(?:ans|hash)_\d+)"/', $first . $second, $m );

		$this->assertCount( 4, $m[1], 'expected two ids per render' );
		$this->assertSame( $m[1], array_unique( $m[1] ), 'ids must not repeat across renders' );
	}

	public function test_math_render_fields_points_the_label_at_its_own_input(): void {
		$provider = new MathCaptcha();
		$provider->render_fields();
		$html = $provider->render_fields();

		preg_match( '/<label for="([^"]+)"/', $html, $label );
		preg_match( '/name="ffc_captcha_ans" id="([^"]+)"/', $html, $input );

		$this->assertNotEmpty( $label[1] );
		$this->assertSame( $label[1], $input[1], 'label must reference the input in the same render' );
	}

	public function test_math_render_fields_keeps_the_name_attributes_stable(): void {
		// The names are the contract with the server; only the ids vary.
		$html = ( new MathCaptcha() )->render_fields();

		$this->assertStringContainsString( 'name="ffc_captcha_ans"', $html );
		$this->assertStringContainsString( 'name="ffc_captcha_hash"', $html );
	}

	public function test_math_render_fields_omits_the_honeypot(): void {
		// The honeypot is provider-independent and belongs to the caller.
		$this->assertStringNotContainsString( 'ffc_honeypot_trap', ( new MathCaptcha() )->render_fields() );
	}

	public function test_math_verify_accepts_a_matching_answer(): void {
		$challenge = SecurityService::generate_simple_captcha();

		$result = ( new MathCaptcha() )->verify(
			array(
				'ffc_captcha_ans'  => (string) $challenge['answer'],
				'ffc_captcha_hash' => $challenge['hash'],
			)
		);

		$this->assertTrue( $result );
	}

	public function test_math_verify_rejects_a_wrong_answer(): void {
		$challenge = SecurityService::generate_simple_captcha();

		$result = ( new MathCaptcha() )->verify(
			array(
				'ffc_captcha_ans'  => (string) ( $challenge['answer'] + 1 ),
				'ffc_captcha_hash' => $challenge['hash'],
			)
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'incorrect', $result );
	}

	public function test_math_verify_rejects_a_missing_challenge(): void {
		$result = ( new MathCaptcha() )->verify( array() );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'security question', $result );
	}

	public function test_math_verify_rejects_a_replayed_challenge(): void {
		$challenge = SecurityService::generate_simple_captcha();
		$request   = array(
			'ffc_captcha_ans'  => (string) $challenge['answer'],
			'ffc_captcha_hash' => $challenge['hash'],
		);
		$provider  = new MathCaptcha();

		$this->assertTrue( $provider->verify( $request ) );
		$this->assertIsString( $provider->verify( $request ) );
	}

	// ------------------------------------------------------------------
	// peek() — the two-request flow (public CSV download: info, then export).
	// ------------------------------------------------------------------

	public function test_math_peek_accepts_a_matching_answer(): void {
		$challenge = SecurityService::generate_simple_captcha();

		$result = ( new MathCaptcha() )->peek(
			array(
				'ffc_captcha_ans'  => (string) $challenge['answer'],
				'ffc_captcha_hash' => $challenge['hash'],
			)
		);

		$this->assertTrue( $result );
	}

	public function test_math_peek_rejects_a_wrong_answer(): void {
		$challenge = SecurityService::generate_simple_captcha();

		$result = ( new MathCaptcha() )->peek(
			array(
				'ffc_captcha_ans'  => (string) ( $challenge['answer'] + 1 ),
				'ffc_captcha_hash' => $challenge['hash'],
			)
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'incorrect', $result );
	}

	public function test_math_peek_leaves_the_challenge_spendable(): void {
		// The regression this exists for: the CSV info screen checked the
		// answer and burned the token, so the download that followed rejected
		// the very answer the visitor had just been told was correct.
		$challenge = SecurityService::generate_simple_captcha();
		$request   = array(
			'ffc_captcha_ans'  => (string) $challenge['answer'],
			'ffc_captcha_hash' => $challenge['hash'],
		);
		$provider  = new MathCaptcha();

		$this->assertTrue( $provider->peek( $request ) );
		$this->assertTrue( $provider->peek( $request ), 'peeking twice must still not spend it' );
		$this->assertTrue( $provider->verify( $request ), 'the action that follows must still be able to spend it' );
	}

	public function test_math_peek_rejects_an_already_spent_challenge(): void {
		// Reporting a spent token as valid would only move the contradiction
		// downstream — the visitor would pass this screen and fail the next.
		$challenge = SecurityService::generate_simple_captcha();
		$request   = array(
			'ffc_captcha_ans'  => (string) $challenge['answer'],
			'ffc_captcha_hash' => $challenge['hash'],
		);
		$provider  = new MathCaptcha();

		$this->assertTrue( $provider->verify( $request ) );
		$this->assertIsString( $provider->peek( $request ) );
	}

	public function test_math_peek_rejects_a_missing_challenge(): void {
		$result = ( new MathCaptcha() )->peek( array() );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'security question', $result );
	}

	public function test_math_challenge_payload_keeps_the_legacy_keys(): void {
		// The frontend has read new_label / new_hash since long before this
		// contract existed; `provider` is additive.
		$payload = ( new MathCaptcha() )->challenge_payload();

		$this->assertSame( MathCaptcha::ID, $payload['provider'] );
		$this->assertArrayHasKey( 'new_label', $payload );
		$this->assertMatchesRegularExpression( '/^\d+\.[0-9a-f]{16}\.[0-9a-f]{64}$/', $payload['new_hash'] );
	}

	// ==================================================================
	// SecurityService composition
	// ==================================================================

	public function test_render_security_fields_composes_honeypot_and_challenge(): void {
		$html = SecurityService::render_security_fields();

		$this->assertStringContainsString( 'ffc-security-container', $html );
		$this->assertStringContainsString( 'ffc_honeypot_trap', $html );
		$this->assertStringContainsString( 'ffc-captcha-row', $html );
	}

	public function test_validate_security_fields_still_gates_the_honeypot(): void {
		$result = SecurityService::validate_security_fields( array( 'ffc_honeypot_trap' => 'bot' ) );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'Honeypot', $result );
	}

	public function test_validate_security_fields_delegates_the_captcha_half(): void {
		$challenge = SecurityService::generate_simple_captcha();

		$this->assertTrue(
			SecurityService::validate_security_fields(
				array(
					'ffc_honeypot_trap' => '',
					'ffc_captcha_ans'   => (string) $challenge['answer'],
					'ffc_captcha_hash'  => $challenge['hash'],
				)
			)
		);
	}

	public function test_peek_security_fields_still_gates_the_honeypot(): void {
		$result = SecurityService::peek_security_fields( array( 'ffc_honeypot_trap' => 'bot' ) );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'Honeypot', $result );
	}

	public function test_peek_security_fields_does_not_spend_the_challenge(): void {
		$challenge = SecurityService::generate_simple_captcha();
		$fields    = array(
			'ffc_honeypot_trap' => '',
			'ffc_captcha_ans'   => (string) $challenge['answer'],
			'ffc_captcha_hash'  => $challenge['hash'],
		);

		$this->assertTrue( SecurityService::peek_security_fields( $fields ) );
		$this->assertTrue( SecurityService::validate_security_fields( $fields ) );
	}

	public function test_validate_security_fields_still_spends_the_challenge(): void {
		$challenge = SecurityService::generate_simple_captcha();
		$fields    = array(
			'ffc_honeypot_trap' => '',
			'ffc_captcha_ans'   => (string) $challenge['answer'],
			'ffc_captcha_hash'  => $challenge['hash'],
		);

		$this->assertTrue( SecurityService::validate_security_fields( $fields ) );
		$this->assertIsString( SecurityService::validate_security_fields( $fields ) );
	}

	public function test_with_fresh_challenge_carries_the_provider_id(): void {
		$payload = SecurityService::with_fresh_challenge( array( 'message' => 'rate limited' ) );

		$this->assertSame( 'rate limited', $payload['message'] );
		$this->assertTrue( $payload['refresh_captcha'] );
		$this->assertSame( MathCaptcha::ID, $payload['provider'] );
	}
}

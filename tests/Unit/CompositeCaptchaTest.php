<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Captcha\AltchaCaptcha;
use FreeFormCertificate\Core\Captcha\CaptchaProvider;
use FreeFormCertificate\Core\Captcha\CompositeCaptcha;
use FreeFormCertificate\Core\Captcha\MathCaptcha;
use FreeFormCertificate\Core\SecurityService;

/**
 * Tests for the composite strategy — ALTCHA with the math challenge as the
 * no-JavaScript fallback (#1053 PR3).
 *
 * @covers \FreeFormCertificate\Core\Captcha\CompositeCaptcha
 * @covers \FreeFormCertificate\Core\Captcha\CaptchaProvider
 */
class CompositeCaptchaTest extends TestCase {

	/** @var array<string, mixed> */
	private array $transients = array();

	/** @var array<string, mixed> */
	private array $settings = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		class_exists( '\FreeFormCertificate\Core\Captcha\CompositeCaptcha' );
		class_exists( '\FreeFormCertificate\Core\Captcha\CaptchaProvider' );

		CaptchaProvider::reset();
		MathCaptcha::reset_instances();

		$this->settings   = array();
		$this->transients = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( string $text ): void { echo $text; } );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'get_locale' )->justReturn( 'pt_BR' );
		Functions\when( 'admin_url' )->alias( static fn( string $p = '' ): string => 'https://example.test/wp-admin/' . $p );
		Functions\when( 'add_query_arg' )->alias(
			static fn( array $args, string $url ): string => $url . '?' . http_build_query( $args )
		);
		Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) );
		Functions\when( 'wp_rand' )->alias( static fn( int $min = 0, int $max = 0 ): int => random_int( $min, $max ) );
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'get_option' )->alias(
			fn( string $key, $default = false ) => 'ffc_settings' === $key ? $this->settings : $default
		);
		Functions\when( 'get_transient' )->alias( fn( string $key ) => $this->transients[ $key ] ?? false );
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

	/**
	 * Solve an ALTCHA challenge the way the widget does.
	 */
	private function solved_altcha(): string {
		$challenge = AltchaCaptcha::create_challenge();
		$salt      = (string) $challenge['salt'];

		for ( $n = 0; $n <= (int) $challenge['maxnumber']; $n++ ) {
			if ( hash( 'sha256', $salt . (string) $n ) === $challenge['challenge'] ) {
				return base64_encode(
					(string) json_encode(
						array(
							'algorithm' => $challenge['algorithm'],
							'challenge' => $challenge['challenge'],
							'number'    => $n,
							'salt'      => $salt,
							'signature' => $challenge['signature'],
						)
					)
				);
			}
		}

		$this->fail( 'Unsolvable challenge.' );
	}

	// ==================================================================
	// Resolution
	// ==================================================================

	public function test_every_mode_resolves_to_its_own_strategy(): void {
		foreach (
			array(
				MathCaptcha::ID      => MathCaptcha::class,
				AltchaCaptcha::ID    => AltchaCaptcha::class,
				CompositeCaptcha::ID => CompositeCaptcha::class,
			) as $id => $class
		) {
			CaptchaProvider::reset();
			$this->settings = array( CaptchaProvider::SETTING_KEY => $id );

			$this->assertInstanceOf( $class, CaptchaProvider::resolve() );
		}
	}

	public function test_only_the_two_widget_modes_load_the_bundle(): void {
		// A site on the math challenge must not ship 111 KB it cannot use.
		$expected = array(
			MathCaptcha::ID      => false,
			AltchaCaptcha::ID    => true,
			CompositeCaptcha::ID => true,
		);

		foreach ( $expected as $id => $needed ) {
			CaptchaProvider::reset();
			$this->settings = array( CaptchaProvider::SETTING_KEY => $id );

			$this->assertSame( $needed, CaptchaProvider::needs_altcha_widget(), "mode {$id}" );
		}
	}

	// ==================================================================
	// Routing
	// ==================================================================

	public function test_a_posted_altcha_solution_is_verified_by_the_altcha_half(): void {
		$composite = new CompositeCaptcha();

		$this->assertTrue( $composite->verify( array( AltchaCaptcha::FIELD => $this->solved_altcha() ) ) );
	}

	public function test_a_math_answer_is_verified_when_no_altcha_solution_is_posted(): void {
		$composite = new CompositeCaptcha();
		$challenge = SecurityService::generate_simple_captcha();

		$result = $composite->verify(
			array(
				'ffc_captcha_ans'  => (string) $challenge['answer'],
				'ffc_captcha_hash' => (string) $challenge['hash'],
			)
		);

		$this->assertTrue( $result );
	}

	public function test_an_empty_altcha_field_falls_through_to_the_math_half(): void {
		// A no-JavaScript visitor posts the widget's name as an empty string
		// on some browsers; that must not route them to the ALTCHA half and
		// answer with "please complete the verification" for a widget they
		// never saw.
		$composite = new CompositeCaptcha();
		$challenge = SecurityService::generate_simple_captcha();

		$result = $composite->verify(
			array(
				AltchaCaptcha::FIELD => '',
				'ffc_captcha_ans'    => (string) $challenge['answer'],
				'ffc_captcha_hash'   => (string) $challenge['hash'],
			)
		);

		$this->assertTrue( $result );
	}

	public function test_peek_routes_the_same_way_and_does_not_spend(): void {
		$composite = new CompositeCaptcha();
		$solution  = $this->solved_altcha();

		$this->assertTrue( $composite->peek( array( AltchaCaptcha::FIELD => $solution ) ) );
		$this->assertTrue( $composite->verify( array( AltchaCaptcha::FIELD => $solution ) ) );
	}

	// ==================================================================
	// Rendering
	// ==================================================================

	public function test_render_puts_the_widget_first_and_the_math_half_in_noscript(): void {
		// A browser with JavaScript never parses the contents of <noscript>,
		// so it neither shows the arithmetic nor submits its fields.
		$html = ( new CompositeCaptcha() )->render_fields();

		$this->assertStringContainsString( '<altcha-widget', $html );
		$this->assertStringContainsString( '<noscript>', $html );
		$this->assertLessThan(
			strpos( $html, '<noscript>' ),
			strpos( $html, '<altcha-widget' ),
			'The widget must precede the fallback, or a sighted visitor meets the arithmetic first.'
		);
		$this->assertStringContainsString(
			'ffc_captcha_ans',
			substr( $html, (int) strpos( $html, '<noscript>' ) ),
			'The math inputs belong inside <noscript>, not beside the widget.'
		);
	}

	public function test_challenge_payload_is_the_altcha_half(): void {
		// The refresh path only reaches pages that run JavaScript, and those
		// are using the widget.
		$this->assertSame(
			array( 'provider' => AltchaCaptcha::ID ),
			( new CompositeCaptcha() )->challenge_payload()
		);
	}
}

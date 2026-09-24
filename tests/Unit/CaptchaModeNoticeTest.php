<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\CaptchaModeNotice;

/**
 * Gating tests for the #1053 ALTCHA-mode nudge.
 *
 * The notice shipped in 6.23.0 with no test at all, which is how its three
 * gates went unpinned: the class here is the *domain* half of
 * AbstractDismissibleNotice, and the shared plumbing (capability gate, hook
 * registration, the AJAX endpoint's 403) is already exercised through
 * EncryptionKeyHealthNoticeTest, so what this file asserts is only what this
 * subclass decides — when the advice is worth giving, and that dismissing it
 * is one-shot.
 *
 * Runs in separate processes because the alias mock of the Captcha\
 * CaptchaProvider static must not leak into the captcha suites.
 *
 * @covers \FreeFormCertificate\Admin\CaptchaModeNotice
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CaptchaModeNoticeTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( '' );
		// wp_admin_notice() is WP 6.4 core — reproduce enough of its markup for
		// the render assertions (class list + message).
		Functions\when( 'wp_admin_notice' )->alias(
			static function ( $message, $args ) {
				$classes = 'notice notice-' . ( $args['type'] ?? 'info' )
					. ( ! empty( $args['dismissible'] ) ? ' is-dismissible' : '' )
					. ' ' . implode( ' ', $args['additional_classes'] ?? array() );
				echo '<div class="' . $classes . '">' . $message . '</div>';
			}
		);

		Mockery::mock( 'alias:FreeFormCertificate\Core\AssetHelper' )
			->shouldReceive( 'asset_suffix' )->andReturn( '.min' )->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Alias-mock the resolver so the notice sees the given strategy id.
	 *
	 * @param string $id Provider id the resolved strategy reports.
	 */
	private function mock_provider( string $id ): void {
		$strategy = Mockery::mock( 'FreeFormCertificate\Core\Captcha\CaptchaProviderInterface' );
		$strategy->shouldReceive( 'id' )->andReturn( $id );

		Mockery::mock( 'alias:FreeFormCertificate\Core\Captcha\CaptchaProvider' )
			->shouldReceive( 'resolve' )->andReturn( $strategy );
	}

	private function rendered_output(): string {
		ob_start();
		CaptchaModeNotice::maybe_render();
		return (string) ob_get_clean();
	}

	public function test_does_not_render_without_https(): void {
		// The widget refuses to run outside a secure context, so on plain HTTP
		// this would be advice the administrator cannot take.
		$this->mock_provider( 'math' );
		Functions\when( 'is_ssl' )->justReturn( false );

		$this->assertSame( '', $this->rendered_output() );
	}

	public function test_does_not_render_when_already_on_altcha_only(): void {
		$this->mock_provider( 'altcha' );
		Functions\when( 'is_ssl' )->justReturn( true );

		$this->assertSame( '', $this->rendered_output() );
	}

	public function test_renders_over_https_on_the_math_challenge(): void {
		$this->mock_provider( 'math' );
		Functions\when( 'is_ssl' )->justReturn( true );

		$output = $this->rendered_output();

		$this->assertStringContainsString( 'ffc-captcha-mode-notice', $output );
		$this->assertStringContainsString( 'ffc-js-dismiss-notice', $output );
		$this->assertStringContainsString( 'is-dismissible', $output );
		$this->assertStringContainsString( 'page=ffc-settings&tab=captcha', $output );
	}

	public function test_renders_over_https_on_the_composite_mode(): void {
		// Composite mode accepts either proof, so its effective strength equals
		// the math challenge's — the nudge is worth making there too. This is
		// the case a naive `provider is math` gate would miss.
		$this->mock_provider( 'both' );
		Functions\when( 'is_ssl' )->justReturn( true );

		$this->assertStringContainsString( 'ffc-captcha-mode-notice', $this->rendered_output() );
	}

	public function test_dismissal_is_one_shot_across_a_provider_change(): void {
		// The signature is a plain flag rather than a state fingerprint, so a
		// site that dismissed the nudge on math does not get it again on
		// composite. Stored '1' → stay hidden whatever the provider says.
		$this->mock_provider( 'both' );
		Functions\when( 'is_ssl' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				return CaptchaModeNotice::OPTION_DISMISSED === $key ? '1' : $default;
			}
		);

		$this->assertSame( '', $this->rendered_output() );
	}

	public function test_ajax_dismiss_stores_the_one_shot_flag(): void {
		$this->mock_provider( 'math' );
		Functions\when( 'check_ajax_referer' )->justReturn( true );

		$captured = array();
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$captured ) {
				$captured[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_send_json_success' )->alias(
			static function () {
				throw new \RuntimeException( 'json_success' );
			}
		);

		try {
			CaptchaModeNotice::ajax_dismiss();
			$this->fail( 'expected halt' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( '1', $captured[ CaptchaModeNotice::OPTION_DISMISSED ] ?? null );
		}
	}

	/**
	 * The `class_exists( CaptchaProvider )` gate, asserted structurally.
	 *
	 * It is the one branch no behavioural test can reach: the class is
	 * autoloadable, so `class_exists()` loads it and answers true — there is no
	 * arrangement under which the guard returns false in this process. What it
	 * defends is a runtime where the captcha module failed to load, which is
	 * why it is there and why the assertion below is over the source rather
	 * than over a render. Presence, not behaviour, and the docblock says so.
	 */
	public function test_should_show_refuses_when_the_provider_class_is_absent(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/includes/admin/class-ffc-captcha-mode-notice.php'
		);

		// Matched as source text rather than by regex: the guard is one line,
		// and a pattern over escaped backslashes is harder to read than the line
		// it looks for.
		$this->assertStringContainsString(
			"if ( ! class_exists( '\\FreeFormCertificate\\Core\\Captcha\\CaptchaProvider' ) ) {\n\t\t\treturn false;",
			$source,
			'CaptchaModeNotice::should_show() must bail when the captcha module is not loaded.'
		);
	}
}

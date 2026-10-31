<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * The set-password screen's error notice (#1842): the shared component,
 * warning tone, still an alert.
 *
 * @coversNothing
 */
class SetPasswordTemplateTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $text ) {
			echo $text; // phpcs:ignore
		} );
		Functions\when( 'esc_attr_e' )->alias( static function ( $text ) {
			echo $text; // phpcs:ignore
		} );
		Functions\when( 'esc_html' )->alias( static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'esc_attr' )->alias( static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ) );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/admin-post.php' );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param string $error The ffc_password_error code.
	 */
	private function render( string $error ): string {
		$ffc_error   = $error;
		$ffc_min_len = 12;

		ob_start();
		include dirname( __DIR__, 2 ) . '/templates/public/set-password.php';
		return (string) ob_get_clean();
	}

	public function test_a_known_error_renders_a_warning_alert_notice(): void {
		$html = $this->render( 'mismatch' );

		$this->assertStringContainsString(
			'<div class="ffc-notice ffc-notice-warning" role="alert" data-ffc-notice="set-password-error"><span class="ffc-notice-icon ffc-icon-warning" aria-hidden="true"></span>'
			. '<div class="ffc-notice-body"><p class="ffc-notice-text">The two passwords did not match. Try again.</p></div></div>',
			$html
		);
		$this->assertStringNotContainsString( 'ffc-dashboard-notice', $html );
	}

	public function test_the_form_carries_neither_the_key_nor_the_login(): void {
		// The pair travels in an HttpOnly cookie (#2246); a hidden field would
		// put it back in the page, where any script can read it.
		$html = $this->render( '' );

		$this->assertStringNotContainsString( 'name="ffc_key"', $html );
		$this->assertStringNotContainsString( 'name="ffc_login"', $html );
		$this->assertStringContainsString( 'name="ffc_pass1"', $html );
	}

	public function test_no_error_renders_no_notice(): void {
		$this->assertStringNotContainsString( 'ffc-notice', $this->render( '' ) );
		$this->assertStringNotContainsString( 'ffc-notice', $this->render( 'unknown' ) );
	}
}

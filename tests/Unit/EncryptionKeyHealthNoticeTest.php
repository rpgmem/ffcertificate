<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\EncryptionKeyHealthNotice;

/**
 * Gating + dismiss tests for the #839 S7a encryption key-health notice.
 *
 * Runs in separate processes so the alias mock of the Core\Encryption static
 * (whose key_health() drives the notice) can't leak into other tests.
 *
 * @covers \FreeFormCertificate\Admin\EncryptionKeyHealthNotice
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class EncryptionKeyHealthNoticeTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $t ) { echo $t; } );
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
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

		if ( ! defined( 'FFC_PLUGIN_URL' ) ) {
			define( 'FFC_PLUGIN_URL', 'https://example.test/wp-content/plugins/ffcertificate/' );
		}
		if ( ! defined( 'FFC_VERSION' ) ) {
			define( 'FFC_VERSION', '0.0.0-test' );
		}

		Mockery::mock( 'alias:FreeFormCertificate\Core\AssetHelper' )
			->shouldReceive( 'asset_suffix' )->andReturn( '.min' )->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param string               $health key_health() return.
	 * @param array<string, mixed> $report key_health_report() return.
	 */
	private function mock_encryption( string $health, array $report ): void {
		Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' )
			->shouldReceive( 'key_health' )->andReturn( $health )
			->shouldReceive( 'key_health_report' )->andReturn( $report );
	}

	private function rendered_output(): string {
		ob_start();
		EncryptionKeyHealthNotice::maybe_render();
		return (string) ob_get_clean();
	}

	public function test_does_not_render_when_keys_ok(): void {
		$this->mock_encryption( 'ok', array( 'status' => 'ok', 'fingerprint' => 'abc123abc123' ) );
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->assertSame( '', $this->rendered_output() );
	}

	public function test_does_not_render_when_user_cannot_manage(): void {
		$this->mock_encryption( 'weak', array( 'status' => 'weak', 'fingerprint' => 'abc123abc123' ) );
		Functions\when( 'current_user_can' )->justReturn( false );
		Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
			->shouldReceive( 'current_user_can_admin_or' )->andReturn( false );

		$this->assertSame( '', $this->rendered_output() );
	}

	public function test_does_not_render_when_dismissed_for_current_signature(): void {
		$this->mock_encryption( 'weak', array( 'status' => 'weak', 'fingerprint' => 'abc123abc123' ) );
		Functions\when( 'current_user_can' )->justReturn( true );
		// Stored signature equals the current one → stay hidden.
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				return EncryptionKeyHealthNotice::OPTION_DISMISSED === $key ? 'weak:abc123abc123' : $default;
			}
		);

		$this->assertSame( '', $this->rendered_output() );
	}

	public function test_renders_when_unsafe_and_not_dismissed(): void {
		$this->mock_encryption( 'weak', array( 'status' => 'weak', 'fingerprint' => 'abc123abc123' ) );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( '' );

		$output = $this->rendered_output();

		$this->assertStringContainsString( 'ffc-encryption-key-health-notice', $output );
		$this->assertStringContainsString( 'ffc-js-dismiss-notice', $output );
		$this->assertStringContainsString( 'is-dismissible', $output );
	}

	public function test_renders_again_when_signature_changed_after_dismissal(): void {
		// Dismissed an earlier state; the key has since changed (new fingerprint)
		// and worsened — the notice must reappear.
		$this->mock_encryption( 'empty', array( 'status' => 'empty', 'fingerprint' => 'newfingerpr1' ) );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				return EncryptionKeyHealthNotice::OPTION_DISMISSED === $key ? 'weak:oldfingerpr0' : $default;
			}
		);

		$this->assertStringContainsString( 'ffc-encryption-key-health-notice', $this->rendered_output() );
	}

	public function test_ajax_dismiss_stores_current_signature(): void {
		$this->mock_encryption( 'weak', array( 'status' => 'weak', 'fingerprint' => 'abc123abc123' ) );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );

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
			EncryptionKeyHealthNotice::ajax_dismiss();
			$this->fail( 'expected halt' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'weak:abc123abc123', $captured[ EncryptionKeyHealthNotice::OPTION_DISMISSED ] ?? null );
		}
	}

	// ------------------------------------------------------------------
	// Shared AbstractDismissibleNotice plumbing (#849), exercised through
	// this concrete subclass.
	// ------------------------------------------------------------------

	public function test_init_registers_admin_notice_and_ajax_hooks(): void {
		$hooks = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook ) use ( &$hooks ) {
				$hooks[] = $hook;
				return true;
			}
		);

		EncryptionKeyHealthNotice::init();

		$this->assertContains( 'admin_notices', $hooks );
		$this->assertContains( 'wp_ajax_' . EncryptionKeyHealthNotice::AJAX_ACTION, $hooks );
	}

	public function test_ajax_dismiss_forbidden_when_cannot_manage(): void {
		$this->mock_encryption( 'weak', array( 'status' => 'weak', 'fingerprint' => 'abc123abc123' ) );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );
		Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
			->shouldReceive( 'current_user_can_admin_or' )->andReturn( false );

		$code = null;
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $payload, $status ) use ( &$code ) {
				$code = $status;
				throw new \RuntimeException( 'json_error' );
			}
		);

		try {
			EncryptionKeyHealthNotice::ajax_dismiss();
			$this->fail( 'expected halt' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 403, $code );
		}
	}
}

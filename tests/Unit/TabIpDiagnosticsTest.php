<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\Tabs\TabIpDiagnostics;

/**
 * #901 phase 2 — the IP Diagnostics tab. Focused on the acceptance criterion
 * "render condicional do guia": the Cloudflare setup guide appears only when
 * the environment verdict is a direct connection.
 *
 * @covers \FreeFormCertificate\Settings\Tabs\TabIpDiagnostics
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class TabIpDiagnosticsTest extends TestCase {

	/** @var array<string, mixed> */
	private array $server_backup = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		class_exists( '\FreeFormCertificate\Settings\Tabs\TabIpDiagnostics' );
		class_exists( '\FreeFormCertificate\Core\Capabilities' );
		$this->server_backup = $_SERVER;
		foreach ( array( 'HTTP_X_FORWARDED_FOR', 'HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR' ) as $k ) {
			unset( $_SERVER[ $k ] );
		}
		$this->stub_wp();
	}

	protected function tearDown(): void {
		$_SERVER = $this->server_backup;
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Stub every WP function the tab render touches (no POST → no save path). */
	private function stub_wp(): void {
		foreach ( array( '__', 'esc_html__' ) as $fn ) {
			Functions\when( $fn )->returnArg();
		}
		foreach ( array( 'esc_html', 'esc_attr', 'esc_textarea', 'esc_url', 'wp_kses_post', 'wp_unslash', 'sanitize_text_field' ) as $fn ) {
			Functions\when( $fn )->returnArg();
		}
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'human_time_diff' )->justReturn( '1 hour' );
		// Passthrough filters → legacy mode, bundled CF ranges, empty proxies.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				return $value;
			}
		);
	}

	private function render_to_string(): string {
		ob_start();
		( new TabIpDiagnostics() )->render();
		return (string) ob_get_clean();
	}

	public function test_guide_shown_on_direct_connection(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7'; // public, non-CDN → direct.
		$html = $this->render_to_string();
		$this->assertStringContainsString( 'put Cloudflare in front', $html );
		$this->assertStringContainsString( 'developers.cloudflare.com', $html );
	}

	public function test_guide_hidden_behind_cloudflare(): void {
		$_SERVER['REMOTE_ADDR'] = '104.16.0.1'; // ∈ bundled CF range → cloudflare.
		$html = $this->render_to_string();
		$this->assertStringNotContainsString( 'put Cloudflare in front', $html );
	}

	public function test_renders_the_config_form_controls(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$html = $this->render_to_string();
		$this->assertStringContainsString( 'name="ffc_ip_strategy"', $html );
		$this->assertStringContainsString( 'name="ffc_ip_trusted_proxy_mode"', $html );
		$this->assertStringContainsString( 'name="ffc_ip_custom_proxies"', $html );
	}
}

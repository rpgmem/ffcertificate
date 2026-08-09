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
		// Stub WP first: loading SettingsTab checks function_exists('wp_kses_post')
		// and would otherwise `require` a real wp-includes/formatting.php (absent
		// in the test env). The stubs must exist before the class_exists preload.
		$this->stub_wp();
		class_exists( '\FreeFormCertificate\Settings\Tabs\TabIpDiagnostics' );
		class_exists( '\FreeFormCertificate\Core\Capabilities' );
		class_exists( '\FreeFormCertificate\Admin\AdminUI' );
		$this->server_backup = $_SERVER;
		foreach ( array( 'HTTP_X_FORWARDED_FOR', 'HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR' ) as $k ) {
			unset( $_SERVER[ $k ] );
		}
	}

	protected function tearDown(): void {
		$_SERVER = $this->server_backup;
		$_POST   = array();
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
		Functions\when( 'wp_admin_notice' )->justReturn( null );
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'sanitize_key' )->alias(
			static fn( $key ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) )
		);
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
		// Key on the guide's wrapper + doc link, not the "put Cloudflare in
		// front" phrase — that also appears in the always-on recommendation
		// notice, so it no longer uniquely marks the guide.
		$this->assertStringContainsString( 'ffc-cf-guide', $html );
		$this->assertStringContainsString( 'developers.cloudflare.com', $html );
	}

	public function test_guide_hidden_behind_cloudflare(): void {
		$_SERVER['REMOTE_ADDR'] = '104.16.0.1'; // ∈ bundled CF range → cloudflare.
		$html = $this->render_to_string();
		$this->assertStringNotContainsString( 'ffc-cf-guide', $html );
		$this->assertStringNotContainsString( 'developers.cloudflare.com', $html );
	}

	public function test_renders_the_config_form_controls(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$html = $this->render_to_string();
		$this->assertStringContainsString( 'name="ffc_ip_strategy"', $html );
		$this->assertStringContainsString( 'name="ffc_ip_trusted_proxy_mode"', $html );
		$this->assertStringContainsString( 'name="ffc_ip_custom_proxies"', $html );
		// shadow_logging is a .ffc-toggle autosave switch (kept in-form).
		$this->assertStringContainsString( 'data-ffc-autosave-key="shadow_logging"', $html );
		$this->assertStringContainsString( 'name="ffc_ip_shadow_logging"', $html );
	}

	public function test_save_persists_sanitized_settings(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		$captured = null;
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$captured ) {
				$captured = $value;
				return true;
			}
		);

		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$_POST                  = array(
			'ffc_save_ip_diagnostics'   => '1',
			'ffc_ip_strategy'           => 'secure',
			'ffc_ip_trusted_proxy_mode' => 'custom',
			'ffc_ip_custom_proxies'     => "192.0.2.0/24\nnot-a-cidr\n10.0.0.0/8",
			'ffc_ip_shadow_logging'     => '1',
		);

		$this->render_to_string();

		$this->assertIsArray( $captured );
		$this->assertSame( 'secure', $captured['strategy'] );
		$this->assertSame( 'custom', $captured['trusted_proxy_mode'] );
		// The invalid entry is dropped; valid CIDRs survive.
		$this->assertSame( "192.0.2.0/24\n10.0.0.0/8", $captured['custom_proxies'] );
		$this->assertSame( 1, $captured['shadow_logging'] );
	}

	public function test_recommendation_shown_when_strategy_legacy(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$html                   = $this->render_to_string();
		$this->assertStringContainsString( 'Recommended', $html );
		$this->assertStringContainsString( 'The current default (Legacy)', $html );
	}

	public function test_recommendation_hidden_when_strategy_secure(): void {
		Functions\when( 'get_option' )->justReturn( array( 'strategy' => 'secure' ) );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$html                   = $this->render_to_string();
		$this->assertStringNotContainsString( 'Recommended', $html );
	}
}

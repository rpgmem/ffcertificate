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
 * It preloads `Admin\AdminUI`, which
 * tests/fixtures/recruitment-admin-page-renderer-stubs.php shadows with a stub
 * for the rest of any process that has required it (#1432); that fixture is
 * only ever required inside RecruitmentAdminPageRendererTest's own processes,
 * so this class runs in the shared one (#2091).
 *
 * @covers \FreeFormCertificate\Settings\Tabs\TabIpDiagnostics
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

	public function test_guide_hidden_when_cloudflare_via_proxy(): void {
		// Private LB peer + CF edge as first public XFF hop → cloudflare_via_proxy.
		$_SERVER['REMOTE_ADDR']          = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.77, 104.16.0.1';
		$html                            = $this->render_to_string();
		// The misleading "put Cloudflare in front" guide must NOT appear...
		$this->assertStringNotContainsString( 'ffc-cf-guide', $html );
		$this->assertStringNotContainsString( 'developers.cloudflare.com', $html );
		// ...and the behind-host-proxy context note must.
		$this->assertStringContainsString( 'reverse proxy', $html );
	}

	public function test_custom_cidr_field_warns_against_cloudflare_ranges(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$html                   = $this->render_to_string();
		$this->assertStringContainsString( 'Do not paste Cloudflare ranges here', $html );
	}

	public function test_headers_table_has_situation_column_and_cf_ray(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$html                   = $this->render_to_string();
		$this->assertStringContainsString( 'Situation', $html );
		$this->assertStringContainsString( 'CF-Ray', $html );
	}

	public function test_unified_strategy_comparison_table_present(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$html                   = $this->render_to_string();
		$this->assertStringContainsString( 'Scenario', $html );
		$this->assertStringContainsString( 'Legacy →', $html );
		$this->assertStringContainsString( 'Secure →', $html );
	}

	public function test_renders_the_config_form_controls(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$html = $this->render_to_string();
		$this->assertStringContainsString( 'name="ffc_ip_strategy"', $html );
		$this->assertStringContainsString( 'name="ffc_ip_trusted_proxy_mode"', $html );
		$this->assertStringContainsString( 'name="ffc_ip_custom_proxies"', $html );
		// shadow_logging is a .ffc-control-toggle autosave switch (kept in-form).
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

	public function test_the_active_strategy_is_a_toggle_that_is_on_for_secure(): void {
		Functions\when( 'get_option' )->justReturn( array( 'strategy' => 'secure' ) );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$html                   = $this->render_to_string();

		$this->assertStringContainsString( 'Active strategy', $html );
		$this->assertStringNotContainsString( 'Effective strategy', $html );
		$this->assertStringContainsString( '<input type="checkbox" id="ffc_ip_strategy" name="ffc_ip_strategy" value="secure" checked>', $html );
	}

	public function test_the_active_strategy_toggle_is_off_for_legacy(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$html                   = $this->render_to_string();

		$this->assertStringContainsString( '<input type="checkbox" id="ffc_ip_strategy" name="ffc_ip_strategy" value="secure">', $html );
	}

	public function test_a_strategy_toggle_left_off_saves_legacy(): void {
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
			'ffc_ip_trusted_proxy_mode' => 'auto',
		);

		$this->render_to_string();

		$this->assertSame( 'legacy', $captured['strategy'] );
	}

	public function test_the_custom_cidr_row_shows_only_in_the_custom_mode(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$html                   = $this->render_to_string();
		$this->assertStringContainsString( '<fieldset id="ffc_ip_trusted_proxy_mode">', $html );
		$this->assertStringContainsString( '<tr class="ffc-collapsed-target ffc-collapsed" data-ffc-master="ffc_ip_trusted_proxy_mode" data-ffc-master-value="custom">', $html );

		Functions\when( 'get_option' )->justReturn( array( 'trusted_proxy_mode' => 'custom' ) );
		$html = $this->render_to_string();
		$this->assertStringContainsString( '<tr class="ffc-collapsed-target" data-ffc-master="ffc_ip_trusted_proxy_mode" data-ffc-master-value="custom">', $html );
	}

	/**
	 * Stub the POST guards and record what the refresh path does.
	 *
	 * @param bool         $can     Whether the user holds the capability.
	 * @param bool         $nonce   Whether the nonce verifies.
	 * @param array|null   $before  The cache option before the action.
	 * @param array|null   $after   The cache option after the action.
	 * @return \stdClass   `fired` (int), `notices` (list of [message, type]), `saved` (bool).
	 */
	private function stub_refresh( bool $can, bool $nonce, ?array $before, ?array $after ): \stdClass {
		$log          = new \stdClass();
		$log->fired   = 0;
		$log->notices = array();
		$log->saved   = false;
		$cache        = $before;

		Functions\when( 'current_user_can' )->justReturn( $can );
		Functions\when( 'check_admin_referer' )->alias(
			static function () use ( $nonce ) {
				if ( ! $nonce ) {
					throw new \RuntimeException( 'nonce' );
				}
				return true;
			}
		);
		Functions\when( 'do_action' )->alias(
			static function ( $hook ) use ( $log, &$cache, $after ) {
				if ( 'ffc_cloudflare_cidr_refresh_now' === $hook ) {
					++$log->fired;
					$cache = $after;
				}
			}
		);
		Functions\when( 'get_option' )->alias(
			static function ( $key, $fallback = false ) use ( &$cache ) {
				return 'ffc_cloudflare_cidr_cache' === $key ? $cache : array();
			}
		);
		Functions\when( 'update_option' )->alias(
			static function () use ( $log ) {
				$log->saved = true;
				return true;
			}
		);
		Functions\when( '_n' )->alias(
			static fn( $single, $plural, $n ) => 1 === $n ? $single : $plural
		);
		Functions\when( 'wp_admin_notice' )->alias(
			static function ( $message, $args ) use ( $log ) {
				$log->notices[] = array( $message, $args['type'] ?? '' );
			}
		);
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		return $log;
	}

	public function test_a_refresh_only_post_fires_the_action_and_reports_the_count(): void {
		$log   = $this->stub_refresh(
			true,
			true,
			null,
			array( 'count' => 22, 'updated' => 1700000000, 'ranges' => array() )
		);
		$_POST = array( 'ffc_ip_refresh_cloudflare' => '1' );

		$this->render_to_string();

		$this->assertSame( 1, $log->fired );
		$this->assertFalse( $log->saved, 'A refresh must not save the form.' );
		$this->assertCount( 1, $log->notices );
		$this->assertStringContainsString( 'Cloudflare IP ranges refreshed: 22 ranges cached.', $log->notices[0][0] );
		$this->assertSame( 'success', $log->notices[0][1] );
	}

	public function test_a_failed_refresh_says_the_bundled_list_stays_in_use(): void {
		$log   = $this->stub_refresh( true, true, null, null );
		$_POST = array( 'ffc_ip_refresh_cloudflare' => '1' );

		$this->render_to_string();

		$this->assertSame( 1, $log->fired );
		$this->assertCount( 1, $log->notices );
		$this->assertStringContainsString( 'The bundled list stays in use.', $log->notices[0][0] );
		$this->assertSame( 'error', $log->notices[0][1] );
	}

	public function test_a_failed_refresh_keeps_the_list_fetched_earlier(): void {
		$cache = array( 'count' => 22, 'updated' => 1700000000, 'ranges' => array() );
		$log   = $this->stub_refresh( true, true, $cache, $cache );
		$_POST = array( 'ffc_ip_refresh_cloudflare' => '1' );

		$this->render_to_string();

		$this->assertSame( 1, $log->fired );
		$this->assertStringContainsString( 'The list fetched earlier stays in use.', $log->notices[0][0] );
		$this->assertSame( 'error', $log->notices[0][1] );
	}

	public function test_a_refresh_without_the_capability_fires_nothing(): void {
		$log   = $this->stub_refresh( false, true, null, array( 'count' => 1, 'updated' => 1 ) );
		$_POST = array( 'ffc_ip_refresh_cloudflare' => '1' );

		$this->render_to_string();

		$this->assertSame( 0, $log->fired );
		$this->assertSame( array(), $log->notices );
	}

	public function test_a_refresh_without_a_valid_nonce_fires_nothing(): void {
		$log   = $this->stub_refresh( true, false, null, array( 'count' => 1, 'updated' => 1 ) );
		$_POST = array( 'ffc_ip_refresh_cloudflare' => '1' );

		try {
			$this->render_to_string();
			$this->fail( 'check_admin_referer() must stop the request.' );
		} catch ( \RuntimeException $e ) {
			ob_end_clean();
			$this->assertSame( 'nonce', $e->getMessage() );
		}

		$this->assertSame( 0, $log->fired );
	}

	public function test_save_saves_and_does_not_refresh(): void {
		$log   = $this->stub_refresh( true, true, null, array( 'count' => 1, 'updated' => 1 ) );
		$_POST = array(
			'ffc_save_ip_diagnostics'   => '1',
			'ffc_ip_trusted_proxy_mode' => 'auto',
		);

		$this->render_to_string();

		$this->assertTrue( $log->saved );
		$this->assertSame( 0, $log->fired );
		$this->assertStringContainsString( 'IP resolution settings saved.', $log->notices[0][0] );
	}

	public function test_both_buttons_render_from_the_button_family_with_their_names(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$html                   = $this->render_to_string();

		$this->assertStringContainsString(
			'<button type="submit" class="ffc-btn ffc-btn-primary ffc-icon-save" name="ffc_save_ip_diagnostics" value="1">Save IP settings</button>',
			$html
		);
		$this->assertStringContainsString(
			'<button type="submit" class="ffc-btn ffc-btn-neutral ffc-icon-sync" name="ffc_ip_refresh_cloudflare" value="1">Refresh Cloudflare ranges now</button>',
			$html
		);
	}
}

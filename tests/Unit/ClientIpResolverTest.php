<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\ClientIpResolver;

/**
 * #899 phase 1 — unit tests for the consolidated client-IP resolver.
 *
 * Two strategies are pinned here: the `legacy` header walk (behavioural parity
 * with the pre-#899 `RequestInput::get_user_ip()`, exercised via the default
 * mode) and the `secure` trusted-proxy decision tree (Cloudflare header,
 * right→left XFF walk, direct-connection fallback, spoof rejection).
 *
 * Runs in isolated processes so a prior suite test that left wp_unslash under
 * Brain Monkey management can't surface as "not defined nor mocked" here.
 *
 * @covers \FreeFormCertificate\Core\ClientIpResolver
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ClientIpResolverTest extends TestCase {

	/** @var array<string, mixed> */
	private array $server_backup = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// pcov attribution: preload the class first touched during the test.
		class_exists( '\FreeFormCertificate\Core\ClientIpResolver' );
		$this->server_backup = $_SERVER;
		foreach ( array( 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR' ) as $k ) {
			unset( $_SERVER[ $k ] );
		}
	}

	protected function tearDown(): void {
		$_SERVER = $this->server_backup;
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Request-envelope readers used by the resolver. */
	private function stub_request_funcs(): void {
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
	}

	/**
	 * Passthrough apply_filters: returns the supplied default/value ($value is
	 * the 2nd positional arg) so every hook keeps its coded default.
	 */
	private function stub_filters_passthrough(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				return $value;
			}
		);
	}

	/** Force the effective strategy to `secure`; other hooks pass through. */
	private function stub_filters_secure_mode(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				return 'ffc_ip_resolver_mode' === $hook ? ClientIpResolver::MODE_SECURE : $value;
			}
		);
	}

	// ---- mode() -------------------------------------------------------------

	public function test_mode_defaults_to_legacy(): void {
		$this->stub_filters_passthrough();
		$this->assertSame( ClientIpResolver::MODE_LEGACY, ClientIpResolver::mode() );
	}

	public function test_mode_rejects_unknown_filter_value(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				return 'ffc_ip_resolver_mode' === $hook ? 'bogus' : $value;
			}
		);
		$this->assertSame( ClientIpResolver::MODE_LEGACY, ClientIpResolver::mode() );
	}

	// ---- legacy strategy (parity with pre-#899 behaviour) -------------------

	public function test_legacy_returns_remote_addr_when_only_header(): void {
		$this->stub_request_funcs();
		$this->stub_filters_passthrough();
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$this->assertSame( '198.51.100.7', ClientIpResolver::resolve() );
	}

	public function test_legacy_prefers_forwarded_for_over_remote_addr(): void {
		$this->stub_request_funcs();
		$this->stub_filters_passthrough();
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.9';
		$_SERVER['REMOTE_ADDR']          = '198.51.100.7';
		$this->assertSame( '203.0.113.9', ClientIpResolver::resolve() );
	}

	public function test_legacy_skips_private_and_reserved(): void {
		$this->stub_request_funcs();
		$this->stub_filters_passthrough();
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.5';
		$_SERVER['REMOTE_ADDR']          = '127.0.0.1';
		$this->assertSame( ClientIpResolver::UNKNOWN, ClientIpResolver::resolve() );
	}

	public function test_legacy_returns_first_public_from_comma_list(): void {
		$this->stub_request_funcs();
		$this->stub_filters_passthrough();
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.5, 203.0.113.42, 8.8.8.8';
		$this->assertSame( '203.0.113.42', ClientIpResolver::resolve() );
	}

	public function test_legacy_falls_back_to_zero(): void {
		$this->stub_request_funcs();
		$this->stub_filters_passthrough();
		$this->assertSame( ClientIpResolver::UNKNOWN, ClientIpResolver::resolve() );
	}

	// ---- secure strategy ----------------------------------------------------

	public function test_secure_direct_connection_returns_remote_addr(): void {
		$this->stub_request_funcs();
		$this->stub_filters_secure_mode();
		// Not a Cloudflare / trusted-proxy peer → forwarded headers ignored.
		$_SERVER['REMOTE_ADDR']          = '198.51.100.7';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8';
		$this->assertSame( '198.51.100.7', ClientIpResolver::resolve() );
	}

	public function test_secure_ignores_spoofed_forwarded_when_direct(): void {
		$this->stub_request_funcs();
		$this->stub_filters_secure_mode();
		// The classic spoof: client injects XFF but connects directly.
		$_SERVER['REMOTE_ADDR']          = '203.0.113.50';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.1.1.1';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '2.2.2.2';
		$this->assertSame( '203.0.113.50', ClientIpResolver::resolve() );
	}

	public function test_secure_trusts_cf_header_behind_cloudflare(): void {
		$this->stub_request_funcs();
		$this->stub_filters_secure_mode();
		// 104.16.0.1 ∈ 104.16.0.0/13 (bundled Cloudflare range).
		$_SERVER['REMOTE_ADDR']           = '104.16.0.1';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.77';
		$_SERVER['HTTP_X_FORWARDED_FOR']  = '9.9.9.9';
		$this->assertSame( '203.0.113.77', ClientIpResolver::resolve() );
	}

	public function test_secure_cf_header_ignored_when_peer_not_cloudflare(): void {
		$this->stub_request_funcs();
		$this->stub_filters_secure_mode();
		// Peer is NOT a CF edge → the CF header is attacker-controlled, ignore it.
		$_SERVER['REMOTE_ADDR']           = '198.51.100.9';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '1.2.3.4';
		$this->assertSame( '198.51.100.9', ClientIpResolver::resolve() );
	}

	public function test_secure_walks_xff_right_to_left_for_trusted_proxy(): void {
		$this->stub_request_funcs();
		// Trust a custom proxy 192.0.2.0/24; CF ranges pass through unchanged.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				if ( 'ffc_ip_resolver_mode' === $hook ) {
					return ClientIpResolver::MODE_SECURE;
				}
				if ( 'ffc_trusted_proxies' === $hook ) {
					return array( '192.0.2.0/24' );
				}
				return $value;
			}
		);
		$_SERVER['REMOTE_ADDR'] = '192.0.2.10'; // trusted proxy peer.
		// client, then two trusted proxy hops appended on the right.
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.5, 192.0.2.7, 192.0.2.8';
		$this->assertSame( '203.0.113.5', ClientIpResolver::resolve() );
	}

	public function test_secure_trusts_literal_proxy_entry_without_cidr(): void {
		$this->stub_request_funcs();
		// Trusted proxy given as a bare IP (no `/mask`) → literal-match branch.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				if ( 'ffc_ip_resolver_mode' === $hook ) {
					return ClientIpResolver::MODE_SECURE;
				}
				if ( 'ffc_trusted_proxies' === $hook ) {
					return array( '192.0.2.10' );
				}
				return $value;
			}
		);
		$_SERVER['REMOTE_ADDR']          = '192.0.2.10';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.5, 192.0.2.10';
		$this->assertSame( '203.0.113.5', ClientIpResolver::resolve() );
	}

	public function test_secure_returns_unknown_when_remote_addr_invalid(): void {
		$this->stub_request_funcs();
		$this->stub_filters_secure_mode();
		$_SERVER['REMOTE_ADDR'] = 'not-an-ip';
		$this->assertSame( ClientIpResolver::UNKNOWN, ClientIpResolver::resolve() );
	}

	public function test_secure_ipv6_cloudflare_peer_uses_cf_header(): void {
		$this->stub_request_funcs();
		$this->stub_filters_secure_mode();
		// 2400:cb00::1 ∈ 2400:cb00::/32 (bundled Cloudflare IPv6 range).
		$_SERVER['REMOTE_ADDR']           = '2400:cb00::1';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.88';
		$this->assertSame( '203.0.113.88', ClientIpResolver::resolve() );
	}

	// ---- secure strategy: Cloudflare via host proxy (#920) ------------------

	public function test_secure_trusts_cf_header_behind_private_lb_with_cf_boundary(): void {
		$this->stub_request_funcs();
		$this->stub_filters_secure_mode();
		// Topology: client → Cloudflare edge → private host LB → PHP.
		$_SERVER['REMOTE_ADDR']           = '10.0.0.5';                 // private LB peer.
		$_SERVER['HTTP_X_FORWARDED_FOR']  = '203.0.113.77, 104.16.0.1'; // client, CF edge.
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.77';
		$this->assertSame( '203.0.113.77', ClientIpResolver::resolve() );
	}

	public function test_secure_via_proxy_when_lb_appends_its_own_private_hop(): void {
		$this->stub_request_funcs();
		$this->stub_filters_secure_mode();
		// LB appends itself → the CF edge is no longer the rightmost hop, but it
		// is still the first PUBLIC hop scanning right→left.
		$_SERVER['REMOTE_ADDR']           = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR']  = '203.0.113.77, 104.16.0.1, 10.0.0.5';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.77';
		$this->assertSame( '203.0.113.77', ClientIpResolver::resolve() );
	}

	public function test_secure_no_via_proxy_trust_when_boundary_hop_not_cloudflare(): void {
		$this->stub_request_funcs();
		$this->stub_filters_secure_mode();
		// Private peer but the first public XFF hop is NOT a CF edge → no proof
		// CF is upstream → the CF header is not trusted; peer is returned.
		$_SERVER['REMOTE_ADDR']           = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR']  = '203.0.113.77, 8.8.8.8';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.77';
		$this->assertSame( '10.0.0.5', ClientIpResolver::resolve() );
	}

	public function test_secure_via_proxy_needs_cf_connecting_ip_present(): void {
		$this->stub_request_funcs();
		$this->stub_filters_secure_mode();
		// CF boundary proven, but no CF-Connecting-IP to trust → fall to peer.
		$_SERVER['REMOTE_ADDR']          = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.77, 104.16.0.1';
		$this->assertSame( '10.0.0.5', ClientIpResolver::resolve() );
	}

	public function test_secure_via_proxy_disabled_when_cf_ranges_cleared_direct_mode(): void {
		$this->stub_request_funcs();
		// `direct` proxy-mode clears the CF range set upstream → the boundary hop
		// matches no CF range → step 3 never fires and the peer is returned.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				if ( 'ffc_ip_resolver_mode' === $hook ) {
					return ClientIpResolver::MODE_SECURE;
				}
				if ( 'ffc_cloudflare_ip_ranges' === $hook ) {
					return array();
				}
				return $value;
			}
		);
		$_SERVER['REMOTE_ADDR']           = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR']  = '203.0.113.77, 104.16.0.1';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.77';
		$this->assertSame( '10.0.0.5', ClientIpResolver::resolve() );
	}

	// ---- remote_addr() — raw TCP peer, headers ignored (#927) ---------------

	public function test_remote_addr_returns_validated_peer(): void {
		$this->stub_request_funcs();
		$_SERVER['REMOTE_ADDR']          = '198.51.100.7';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.5'; // must be ignored.
		$this->assertSame( '198.51.100.7', ClientIpResolver::remote_addr() );
	}

	public function test_remote_addr_returns_unknown_when_absent(): void {
		$this->stub_request_funcs();
		$this->assertSame( ClientIpResolver::UNKNOWN, ClientIpResolver::remote_addr() );
	}

	public function test_remote_addr_returns_unknown_when_invalid(): void {
		$this->stub_request_funcs();
		$_SERVER['REMOTE_ADDR'] = 'not-an-ip';
		$this->assertSame( ClientIpResolver::UNKNOWN, ClientIpResolver::remote_addr() );
	}

	// ---- shadow logging (dormant by default) --------------------------------

	public function test_shadow_logging_off_by_default_stays_silent(): void {
		$this->stub_request_funcs();
		$this->stub_filters_passthrough(); // ffc_ip_shadow_logging → false.
		Functions\expect( 'error_log' )->never();
		// Divergent inputs: legacy picks the forged XFF, secure picks the peer.
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8';
		$_SERVER['REMOTE_ADDR']          = '198.51.100.7';
		$this->assertSame( '8.8.8.8', ClientIpResolver::resolve() );
	}

	public function test_shadow_logging_records_divergence_when_enabled(): void {
		$this->stub_request_funcs();
		// Enable shadow logging; keep every other hook at its coded default.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				return 'ffc_ip_shadow_logging' === $hook ? true : $value;
			}
		);
		// Debug::is_enabled() reads ffc_settings; enable the frontend area.
		Functions\when( 'get_option' )->justReturn( array( 'debug_frontend' => 1 ) );
		class_exists( '\FreeFormCertificate\Core\Debug' );

		$captured = '';
		Functions\when( 'error_log' )->alias(
			static function ( $message ) use ( &$captured ) {
				$captured = (string) $message;
				return true;
			}
		);

		// legacy → 8.8.8.8 (forged XFF); secure → 198.51.100.7 (direct peer).
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8';
		$_SERVER['REMOTE_ADDR']          = '198.51.100.7';

		$this->assertSame( '8.8.8.8', ClientIpResolver::resolve(), 'legacy stays effective' );
		$this->assertStringContainsString( 'divergence', $captured );
		// Raw IPs must never appear in the log — only 16-char sha256 prefixes.
		$this->assertStringNotContainsString( '8.8.8.8', $captured );
		$this->assertStringNotContainsString( '198.51.100.7', $captured );
	}

	// ---- classify() — environment verdict (#901 phase 2) --------------------

	public function test_classify_direct_when_peer_is_public_non_cdn(): void {
		$this->stub_request_funcs();
		$this->stub_filters_passthrough();
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$this->assertSame( ClientIpResolver::VERDICT_DIRECT, ClientIpResolver::classify() );
	}

	public function test_classify_cloudflare_when_peer_in_cf_range(): void {
		$this->stub_request_funcs();
		$this->stub_filters_passthrough();
		$_SERVER['REMOTE_ADDR'] = '104.16.0.1'; // ∈ 104.16.0.0/13 (bundled CF).
		$this->assertSame( ClientIpResolver::VERDICT_CLOUDFLARE, ClientIpResolver::classify() );
	}

	public function test_classify_trusted_proxy_when_peer_in_configured_range(): void {
		$this->stub_request_funcs();
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				return 'ffc_trusted_proxies' === $hook ? array( '192.0.2.0/24' ) : $value;
			}
		);
		$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
		$this->assertSame( ClientIpResolver::VERDICT_TRUSTED_PROXY, ClientIpResolver::classify() );
	}

	public function test_classify_direct_when_remote_addr_invalid(): void {
		$this->stub_request_funcs();
		$this->stub_filters_passthrough();
		$_SERVER['REMOTE_ADDR'] = 'not-an-ip';
		$this->assertSame( ClientIpResolver::VERDICT_DIRECT, ClientIpResolver::classify() );
	}

	public function test_classify_accepts_explicit_remote_argument(): void {
		$this->stub_filters_passthrough();
		$this->assertSame( ClientIpResolver::VERDICT_CLOUDFLARE, ClientIpResolver::classify( '2400:cb00::1' ) );
	}

	public function test_classify_cloudflare_via_proxy_when_private_peer_and_cf_boundary(): void {
		$this->stub_request_funcs();
		$this->stub_filters_passthrough();
		// Private LB peer + CF edge as first public XFF hop → the CF-behind-proxy
		// topology that must NOT surface the "put Cloudflare in front" guide.
		$_SERVER['REMOTE_ADDR']          = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.77, 104.16.0.1';
		$this->assertSame( ClientIpResolver::VERDICT_CLOUDFLARE_VIA_PROXY, ClientIpResolver::classify() );
	}

	public function test_classify_direct_when_private_peer_but_no_cf_boundary(): void {
		$this->stub_request_funcs();
		$this->stub_filters_passthrough();
		$_SERVER['REMOTE_ADDR']          = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.77, 8.8.8.8';
		$this->assertSame( ClientIpResolver::VERDICT_DIRECT, ClientIpResolver::classify() );
	}

	public function test_classify_direct_when_private_peer_without_forwarded_for(): void {
		$this->stub_request_funcs();
		$this->stub_filters_passthrough();
		$_SERVER['REMOTE_ADDR'] = '10.0.0.5';
		$this->assertSame( ClientIpResolver::VERDICT_DIRECT, ClientIpResolver::classify() );
	}
}

<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Integrations\GithubUpdater;

/**
 * Tests for the self-hosted GitHub Releases updater: transient injection
 * (response vs no_update), the details modal, ETag conditional fetch, and the
 * SHA-256 verification gate.
 *
 * @covers \FreeFormCertificate\Integrations\GithubUpdater
 */
class GithubUpdaterTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const PLUGIN_FILE = 'ffcertificate/ffcertificate.php';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// pcov attribution: preload the freshly-autoloaded class.
		class_exists( '\FreeFormCertificate\Integrations\GithubUpdater' );

		// WP_Error is referenced unqualified inside the Integrations namespace.
		if ( ! class_exists( 'FreeFormCertificate\Integrations\WP_Error' ) ) {
			class_alias( 'WP_Error', 'FreeFormCertificate\Integrations\WP_Error' );
		}

		Functions\when( '__' )->returnArg();
		// Debug::log_admin() (reached on error paths) resolves settings via
		// get_option; stub it so the breadcrumb no-ops regardless of whether the
		// Debug class is autoloaded alongside other test files.
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'wpautop' )->returnArg();
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) {
				return $value;
			}
		);
		// Note: add_filter/add_action are intentionally NOT stubbed here — only
		// init() calls them, and its test sets explicit Functions\expect()s (a
		// when() stub would swallow the calls and make the count assertion fail).
		Functions\when( 'get_site_transient' )->justReturn( false );
		Functions\when( 'set_site_transient' )->justReturn( true );
		Functions\when( 'delete_site_transient' )->justReturn( true );
		Functions\when( 'wp_remote_get' )->justReturn( new \WP_Error( 'stub', 'stubbed' ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );
		Functions\when( 'wp_delete_file' )->justReturn( true );

		$this->reset_booted();
	}

	protected function tearDown(): void {
		unset( $_GET['force-check'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Reset the private one-shot `$booted` guard between tests. */
	private function reset_booted(): void {
		$ref  = new \ReflectionClass( GithubUpdater::class );
		$prop = $ref->getProperty( 'booted' );
		$prop->setAccessible( true );
		$prop->setValue( null, false );
	}

	/**
	 * A parsed-release payload as get_latest_release() returns it.
	 *
	 * @param string $version Release version.
	 * @param string $sha256  Expected asset digest (hex, no prefix).
	 * @return array<string, string>
	 */
	private function release( string $version = '6.17.0', string $sha256 = '' ): array {
		return array(
			'version'      => $version,
			'package'      => "https://github.com/rpgmem/ffcertificate/releases/download/v{$version}/ffcertificate-{$version}.zip",
			'sha256'       => $sha256,
			'url'          => "https://github.com/rpgmem/ffcertificate/releases/tag/v{$version}",
			'changelog'    => "### Changed\n- stuff",
			'published_at' => '2026-07-26T23:39:21Z',
		);
	}

	/** Seed a fresh cache so get_latest_release() returns $release without a fetch. */
	private function seed_cache( array $release, string $etag = '"abc"' ): void {
		Functions\when( 'get_site_transient' )->justReturn(
			array(
				'payload'    => $release,
				'etag'       => $etag,
				'fetched_at' => time(),
			)
		);
	}

	/** A WP-style update_plugins transient with a known installed version. */
	private function transient( string $installed ): \stdClass {
		$t            = new \stdClass();
		$t->checked   = array( self::PLUGIN_FILE => $installed );
		$t->response  = array();
		$t->no_update = array();
		return $t;
	}

	// ------------------------------------------------------------------
	// check_for_update()
	// ------------------------------------------------------------------

	public function test_injects_response_when_update_available(): void {
		$this->seed_cache( $this->release( '6.17.0' ) );

		$out = GithubUpdater::check_for_update( $this->transient( '6.16.0' ) );

		$this->assertArrayHasKey( self::PLUGIN_FILE, $out->response );
		$this->assertArrayNotHasKey( self::PLUGIN_FILE, $out->no_update );
		$item = $out->response[ self::PLUGIN_FILE ];
		$this->assertSame( '6.17.0', $item->new_version );
		// Package is the built asset, never the source zipball.
		$this->assertStringContainsString( 'ffcertificate-6.17.0.zip', $item->package );
		$this->assertStringNotContainsString( 'zipball', $item->package );
	}

	public function test_sets_no_update_when_current(): void {
		$this->seed_cache( $this->release( '6.17.0' ) );

		$out = GithubUpdater::check_for_update( $this->transient( '6.17.0' ) );

		$this->assertArrayHasKey( self::PLUGIN_FILE, $out->no_update );
		$this->assertArrayNotHasKey( self::PLUGIN_FILE, $out->response );
	}

	public function test_no_op_when_checked_empty(): void {
		$this->seed_cache( $this->release( '6.17.0' ) );

		$t          = new \stdClass();
		$t->checked = array();
		$out        = GithubUpdater::check_for_update( $t );

		$this->assertObjectNotHasProperty( 'response', $out );
	}

	public function test_force_check_busts_the_release_cache(): void {
		$_GET['force-check'] = '1';
		$this->seed_cache( $this->release( '6.17.0' ) );

		$deleted = array();
		Functions\when( 'delete_site_transient' )->alias(
			static function ( $key ) use ( &$deleted ) {
				$deleted[] = $key;
				return true;
			}
		);

		$out = GithubUpdater::check_for_update( $this->transient( '6.16.0' ) );

		// The forced check drops our cached payload so the scan re-fetches GitHub
		// (and clears any persistent object-cache copy).
		$this->assertContains( 'ffc_github_update', $deleted );
		$this->assertArrayHasKey( self::PLUGIN_FILE, $out->response );
	}

	public function test_normal_check_does_not_bust_the_release_cache(): void {
		$this->seed_cache( $this->release( '6.17.0' ) );

		$deleted = array();
		Functions\when( 'delete_site_transient' )->alias(
			static function ( $key ) use ( &$deleted ) {
				$deleted[] = $key;
				return true;
			}
		);

		$out = GithubUpdater::check_for_update( $this->transient( '6.16.0' ) );

		// Without force-check, the 12h cache is respected — no delete.
		$this->assertSame( array(), $deleted );
		$this->assertArrayHasKey( self::PLUGIN_FILE, $out->response );
	}

	public function test_no_op_when_release_unavailable(): void {
		// Empty cache + wp_remote_get failing → no release.
		Functions\when( 'get_site_transient' )->justReturn( false );

		$out = GithubUpdater::check_for_update( $this->transient( '6.16.0' ) );

		$this->assertSame( array(), $out->response );
		$this->assertSame( array(), $out->no_update );
	}

	public function test_four_segment_cachebust_triggers_update(): void {
		$this->seed_cache( $this->release( '6.6.2.1' ) );

		$out = GithubUpdater::check_for_update( $this->transient( '6.6.2' ) );

		$this->assertArrayHasKey( self::PLUGIN_FILE, $out->response );
		$this->assertSame( '6.6.2.1', $out->response[ self::PLUGIN_FILE ]->new_version );
	}

	// ------------------------------------------------------------------
	// ETag conditional fetch
	// ------------------------------------------------------------------

	public function test_fetch_sends_if_none_match_and_304_reuses_payload(): void {
		// Stale cache with an ETag → forces a conditional fetch.
		Functions\when( 'get_site_transient' )->justReturn(
			array(
				'payload'    => $this->release( '6.17.0' ),
				'etag'       => '"deadbeef"',
				'fetched_at' => time() - 999999,
			)
		);

		$sent_headers = array();
		Functions\when( 'wp_remote_get' )->alias(
			static function ( $url, $args ) use ( &$sent_headers ) {
				$sent_headers = $args['headers'] ?? array();
				return array( 'stub' => true );
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 304 );

		$out = GithubUpdater::check_for_update( $this->transient( '6.16.0' ) );

		$this->assertSame( '"deadbeef"', $sent_headers['If-None-Match'] ?? null );
		// 304 → the cached payload is reused, so the update is still injected.
		$this->assertArrayHasKey( self::PLUGIN_FILE, $out->response );
	}

	// ------------------------------------------------------------------
	// Full fetch → parse_release()
	// ------------------------------------------------------------------

	/**
	 * A releases/latest JSON body (heredoc avoids the WPCS json_encode sniff).
	 *
	 * @param string $version   Tag version (without leading v).
	 * @param bool   $has_asset Whether to attach the built ffcertificate zip.
	 * @return string
	 */
	private function release_json( string $version, bool $has_asset = true ): string {
		$asset = $has_asset
			? '{"name":"ffcertificate-' . $version . '.zip","browser_download_url":"https://github.com/rpgmem/ffcertificate/releases/download/v' . $version . '/ffcertificate-' . $version . '.zip","digest":"sha256:deadbeef"},'
			: '';
		return '{'
			. '"tag_name":"v' . $version . '",'
			. '"html_url":"https://github.com/rpgmem/ffcertificate/releases/tag/v' . $version . '",'
			. '"body":"### Added\n- stuff",'
			. '"published_at":"2026-08-01T00:00:00Z",'
			. '"assets":[' . $asset . '{"name":"Source code (zip)","browser_download_url":"https://github.com/rpgmem/ffcertificate/archive/refs/tags/v' . $version . '.zip"}]'
			. '}';
	}

	public function test_full_fetch_parses_release_and_injects(): void {
		Functions\when( 'get_site_transient' )->justReturn( false );
		$body = $this->release_json( '6.18.0' );
		Functions\when( 'wp_remote_get' )->justReturn( array( 'body' => $body ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );
		Functions\when( 'wp_remote_retrieve_header' )->justReturn( '"new-etag"' );

		$out = GithubUpdater::check_for_update( $this->transient( '6.17.0' ) );

		$this->assertArrayHasKey( self::PLUGIN_FILE, $out->response );
		$item = $out->response[ self::PLUGIN_FILE ];
		$this->assertSame( '6.18.0', $item->new_version );
		// The built asset is selected, never the source archive.
		$this->assertStringContainsString( 'releases/download/v6.18.0/ffcertificate-6.18.0.zip', $item->package );
		$this->assertStringNotContainsString( 'archive/refs', $item->package );
	}

	public function test_fetch_skips_when_no_built_asset(): void {
		Functions\when( 'get_site_transient' )->justReturn( false );
		$body = $this->release_json( '6.18.0', false ); // only the source archive
		Functions\when( 'wp_remote_get' )->justReturn( array( 'body' => $body ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( $body );

		$out = GithubUpdater::check_for_update( $this->transient( '6.17.0' ) );

		$this->assertSame( array(), $out->response );
	}

	public function test_fetch_non_200_yields_no_release(): void {
		Functions\when( 'get_site_transient' )->justReturn( false );
		Functions\when( 'wp_remote_get' )->justReturn( array( 'body' => '' ) );
		Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 403 );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );

		$out = GithubUpdater::check_for_update( $this->transient( '6.17.0' ) );

		$this->assertSame( array(), $out->response );
	}

	// ------------------------------------------------------------------
	// plugin_info()
	// ------------------------------------------------------------------

	public function test_plugin_info_returns_object_for_our_slug(): void {
		$this->seed_cache( $this->release( '6.17.0' ) );

		$args       = new \stdClass();
		$args->slug = 'ffcertificate';
		$info       = GithubUpdater::plugin_info( false, 'plugin_information', $args );

		$this->assertIsObject( $info );
		$this->assertSame( '6.17.0', $info->version );
		$this->assertStringContainsString( 'ffcertificate-6.17.0.zip', $info->download_link );
		$this->assertArrayHasKey( 'changelog', $info->sections );
	}

	public function test_plugin_info_passthrough_for_other_slug(): void {
		$args       = new \stdClass();
		$args->slug = 'some-other-plugin';

		$this->assertFalse( GithubUpdater::plugin_info( false, 'plugin_information', $args ) );
		$this->assertFalse( GithubUpdater::plugin_info( false, 'query_plugins', $args ) );
	}

	// ------------------------------------------------------------------
	// verify_download() — SHA-256 gate
	// ------------------------------------------------------------------

	public function test_verify_download_returns_path_on_match(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'ffc' );
		file_put_contents( $tmp, 'plugin-zip-bytes' );
		$hash = hash_file( 'sha256', $tmp );

		$release = $this->release( '6.17.0', $hash );
		$this->seed_cache( $release );
		Functions\when( 'download_url' )->justReturn( $tmp );

		$result = GithubUpdater::verify_download( false, $release['package'] );

		$this->assertSame( $tmp, $result );
		unlink( $tmp );
	}

	public function test_verify_download_aborts_on_hash_mismatch(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'ffc' );
		file_put_contents( $tmp, 'tampered-bytes' );

		$release = $this->release( '6.17.0', str_repeat( 'a', 64 ) ); // wrong hash
		$this->seed_cache( $release );
		Functions\when( 'download_url' )->justReturn( $tmp );

		$result = GithubUpdater::verify_download( false, $release['package'] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ffc_updater_checksum', $result->get_error_code() );
		if ( file_exists( $tmp ) ) {
			unlink( $tmp );
		}
	}

	public function test_verify_download_passthrough_for_other_package(): void {
		$this->seed_cache( $this->release( '6.17.0', str_repeat( 'a', 64 ) ) );

		$result = GithubUpdater::verify_download( false, 'https://example.com/other.zip' );

		$this->assertFalse( $result );
	}

	// ------------------------------------------------------------------
	// init()
	// ------------------------------------------------------------------

	public function test_init_registers_hooks_once(): void {
		Functions\expect( 'add_filter' )->times( 3 );
		Functions\expect( 'add_action' )->once();

		GithubUpdater::init();
		// Second call is a no-op thanks to the $booted guard (no extra hooks).
		GithubUpdater::init();

		$this->assertTrue( true );
	}
}

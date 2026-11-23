<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Audience\AudienceTerminalPage;
use PHPUnit\Framework\TestCase;

/**
 * The check-in terminal's own page (#2744): its address, when it answers,
 * and the document it prints.
 *
 * @covers \FreeFormCertificate\Audience\AudienceTerminalPage
 */
class AudienceTerminalPageTest extends TestCase {

	/** @var array<string, mixed> The `ffc_settings` option. */
	private array $settings = array();

	/** @var string The query variable's value. */
	private string $query_var = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->settings  = array( 'module_audiences_enabled' => true );
		$this->query_var = '';
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr_e' )->alias( static function ( $text ) {
			echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test stub.
		} );
		Functions\when( 'esc_html_e' )->alias( static function ( $text ) {
			echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test stub.
		} );
		Functions\when( 'get_option' )->alias( fn ( $key, $default = false ) => 'ffc_settings' === $key ? $this->settings : $default );
		Functions\when( 'get_query_var' )->alias( fn () => $this->query_var );
		Functions\when( 'get_site_icon_url' )->justReturn( '' );
		Functions\when( 'wp_parse_url' )->alias( static fn ( $url, $component = -1 ) => parse_url( $url, $component ) );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_the_page_registers_its_path_and_its_query_variable_ahead_of_the_canonical_redirect(): void {
		Functions\expect( 'add_filter' )->once()->with( 'query_vars', array( AudienceTerminalPage::class, 'query_vars' ) );
		Functions\expect( 'add_action' )->once()->with( 'parse_request', array( AudienceTerminalPage::class, 'maybe_route' ), 1 );
		Functions\expect( 'add_action' )->once()->with( 'template_redirect', array( AudienceTerminalPage::class, 'maybe_render' ), 1 );

		AudienceTerminalPage::init();

		$this->assertSame( array( 'p', AudienceTerminalPage::QUERY_VAR ), AudienceTerminalPage::query_vars( array( 'p' ) ) );
		$this->assertSame( 'not-an-array', AudienceTerminalPage::query_vars( 'not-an-array' ) );
	}

	public function test_the_address_is_the_query_form_without_pretty_permalinks(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com/' );
		Functions\when( 'add_query_arg' )->alias( static fn ( $key, $value, $url ) => $url . '?' . $key . '=' . $value );

		$this->assertSame( 'https://example.com/?ffc_terminal=1', AudienceTerminalPage::url() );
		$this->assertSame( 'https://example.com/?ffc_terminal=manifest', AudienceTerminalPage::manifest_url() );
	}

	public function test_the_address_is_its_own_path_with_pretty_permalinks(): void {
		$this->pretty();

		$this->assertSame( 'https://example.com/site/terminal/', AudienceTerminalPage::url() );
		$this->assertSame( 'https://example.com/site/terminal/manifest.webmanifest', AudienceTerminalPage::manifest_url() );
	}

	public function test_only_the_terminal_path_and_its_manifest_are_claimed(): void {
		$this->assertSame( 'page', AudienceTerminalPage::route_from_path( '/terminal/', '/' ) );
		$this->assertSame( 'page', AudienceTerminalPage::route_from_path( '/terminal', '/' ) );
		$this->assertSame( 'manifest', AudienceTerminalPage::route_from_path( '/terminal/manifest.webmanifest', '/' ) );
		$this->assertSame( 'page', AudienceTerminalPage::route_from_path( '/site/terminal/', '/site/' ), 'a site in a sub-directory' );
		$this->assertSame( '', AudienceTerminalPage::route_from_path( '/terminal/', '/site/' ), 'outside the home path' );
		$this->assertSame( '', AudienceTerminalPage::route_from_path( '/docs/terminal/', '/' ), 'a page that merely ends in terminal' );
		$this->assertSame( '', AudienceTerminalPage::route_from_path( '/terminal/anything', '/' ) );
		$this->assertSame( '', AudienceTerminalPage::route_from_path( '/terminals/', '/' ) );
		$this->assertSame( '', AudienceTerminalPage::route_from_path( '/', '/' ) );
	}

	public function test_every_response_is_kept_out_of_search_engines_and_caches(): void {
		foreach ( array( 'text/html; charset=utf-8', 'application/manifest+json' ) as $type ) {
			$headers = AudienceTerminalPage::headers( $type );
			$this->assertSame( $type, $headers['Content-Type'] );
			$this->assertSame( 'noindex, nofollow, noarchive', $headers['X-Robots-Tag'], $type );
			$this->assertStringContainsString( 'no-store', $headers['Cache-Control'], $type );
			$this->assertSame( 'no-referrer', $headers['Referrer-Policy'] );
		}
	}

	public function test_the_manifest_installs_a_full_screen_app_scoped_to_the_terminal(): void {
		$this->pretty();
		Functions\when( 'get_bloginfo' )->alias( static fn ( $show = '' ) => 'language' === $show ? 'pt-BR' : 'Example' );
		Functions\when( 'get_site_icon_url' )->alias( static fn ( $size ) => 'https://example.com/icon-' . $size . '.png' );

		$manifest = AudienceTerminalPage::manifest();

		$this->assertSame( '/site/terminal/', $manifest['start_url'] );
		$this->assertSame( '/site/terminal/', $manifest['scope'], 'never the site or the app' );
		$this->assertSame( 'fullscreen', $manifest['display'] );
		$this->assertSame( 'landscape', $manifest['orientation'] );
		$this->assertSame( 'pt-BR', $manifest['lang'] );
		$this->assertSame( 'Check-in terminal — Example', $manifest['name'] );
		$this->assertSame( array( '192x192', '512x512' ), array_column( $manifest['icons'], 'sizes' ) );
	}

	public function test_without_pretty_permalinks_the_manifest_starts_on_the_query_form(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com/' );
		Functions\when( 'add_query_arg' )->alias( static fn ( $key, $value, $url ) => $url . '?' . $key . '=' . $value );
		Functions\when( 'get_bloginfo' )->justReturn( '' );

		$manifest = AudienceTerminalPage::manifest();

		$this->assertSame( '/?ffc_terminal=1', $manifest['start_url'] );
		$this->assertSame( '/', $manifest['scope'] );
		$this->assertSame( 'Check-in terminal', $manifest['name'] );
		$this->assertSame( array(), $manifest['icons'], 'no site icon, no icon entry' );
	}

	/**
	 * Pretty permalinks, with the site in a sub-directory.
	 */
	private function pretty(): void {
		Functions\when( 'get_option' )->alias( fn ( $key, $default = false ) => 'permalink_structure' === $key ? '/%postname%/' : ( 'ffc_settings' === $key ? $this->settings : $default ) );
		Functions\when( 'home_url' )->alias( static fn ( $path = '' ) => 'https://example.com/site' . ( '' === $path ? '/' : $path ) );
	}

	public function test_the_page_answers_only_when_asked_and_while_the_module_is_on(): void {
		Functions\expect( 'nocache_headers' )->never();

		AudienceTerminalPage::maybe_render();

		$this->query_var                            = '1';
		$this->settings['module_audiences_enabled'] = false;
		AudienceTerminalPage::maybe_render();

		$this->addToAssertionCount( 1 );
	}

	public function test_the_document_prints_the_page_and_its_registered_assets(): void {
		$printed   = array();
		$localized = array();
		$enqueued  = array();
		Functions\when( 'get_language_attributes' )->justReturn( 'lang="en-US"' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Example' );
		Functions\when( 'wp_style_is' )->justReturn( false );
		Functions\when( 'wp_enqueue_style' )->alias( static function ( $handle, $src = '', $deps = array() ) use ( &$enqueued ) {
			$enqueued[ 'style:' . $handle ] = array( $src, $deps );
		} );
		Functions\when( 'wp_enqueue_script' )->alias( static function ( $handle, $src = '', $deps = array() ) use ( &$enqueued ) {
			$enqueued[ $handle ] = array( $src, $deps );
		} );
		Functions\when( 'wp_localize_script' )->alias( static function ( $handle, $name, $data ) use ( &$localized ) {
			$localized[ $handle ] = array( $name, $data );
			return true;
		} );
		Functions\when( 'wp_print_styles' )->alias( static function ( $handles ) use ( &$printed ) {
			$printed[] = 'style:' . implode( ',', $handles );
		} );
		Functions\when( 'wp_print_scripts' )->alias( static function ( $handles ) use ( &$printed ) {
			$printed[] = 'script:' . implode( ',', $handles );
		} );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'home_url' )->justReturn( 'https://example.com/' );
		Functions\when( 'add_query_arg' )->alias( static fn ( $key, $value, $url ) => $url . '?' . $key . '=' . $value );
		Functions\when( 'rest_url' )->alias( static fn ( $path ) => 'https://example.com/wp-json/' . $path );

		$html = AudienceTerminalPage::document();

		$this->assertStringStartsWith( '<!DOCTYPE html><html lang="en-US" class="ffc-dark-mode"><head>', $html, 'a terminal is always dark, whatever the site\'s setting (#2780)' );
		$this->assertStringContainsString( '<body class="ffc-shortcode ffc-terminal-page">', $html );
		$this->assertStringContainsString( 'data-ffc-terminal', $html );
		$this->assertStringContainsString( '<meta name="robots" content="noindex,nofollow,noarchive">', $html );
		$this->assertStringContainsString( '<link rel="manifest" href="https://example.com/?ffc_terminal=manifest">', $html );
		foreach ( array( 'pairing', 'locked', 'ready', 'between', 'result', 'unlock', 'menu' ) as $view ) {
			$this->assertStringContainsString( 'data-ffc-terminal-view="' . $view . '"', $html, $view );
		}
		$this->assertStringContainsString( 'id="ffc-terminal-code"', $html );
		$this->assertStringContainsString( 'id="ffc-terminal-reading"', $html );
		$this->assertMatchesRegularExpression( '/role="status"[^>]*data-ffc-terminal-part="result-ok"/', $html, 'a recorded result is announced politely' );
		$this->assertMatchesRegularExpression( '/role="alert"[^>]*data-ffc-terminal-part="result-refused"/', $html, 'a refusal is announced at once' );
		$this->assertSame( array( 'style:ffc-terminal-kiosk', 'script:ffc-terminal-kiosk' ), $printed, 'the dark-mode toggle is never printed, so the setting cannot turn the terminal light' );
		$this->assertSame( array( 'ffc-common' ), $enqueued['style:ffc-terminal-kiosk'][1] ?? null );
		$this->assertSame( array( 'ffc-presence-verifier' ), $enqueued['ffc-terminal-kiosk'][1] ?? null, 'the kiosk checks codes offline with the verifier' );
		$this->assertStringContainsString( 'assets/js/ffc-presence-verifier', $enqueued['ffc-presence-verifier'][0] ?? '' );
		$this->assertSame( 'ffcTerminal', $localized['ffc-terminal-kiosk'][0] );
		$config = $localized['ffc-terminal-kiosk'][1];
		$this->assertSame( 'https://example.com/wp-json/ffc/v1/terminal/', $config['rest'] );
		$this->assertSame( 'https://example.com/wp-json/ffc/v1/presence/', $config['presence'] );
		$this->assertArrayNotHasKey( 'nonce', $config, 'a terminal has no account to carry a nonce for' );
		$this->assertFalse( $config['camera'], 'the camera is off until an administrator allows it' );
		$this->assertSame( array( 'ready', 'reading', 'insecure', 'unsupported', 'denied', 'failed' ), array_keys( $config['strings']['camera'] ) );
		$this->assertStringContainsString( 'data-ffc-terminal-part="camera-start"', $html );
		$this->assertMatchesRegularExpression( '/<video[^>]*playsinline[^>]*muted/', $html, 'the viewfinder plays inline and silent' );
		$this->assertSame( array( 'storage', 'pairing', 'paired', 'failed', 'events', 'until', 'expired', 'operator', 'ended', 'queued', 'alreadyQueued', 'queue', 'sent', 'nowChecking', 'nextEvent', 'openUntil', 'notOpen', 'opensAt', 'noEvents', 'terminalTitle', 'notPaired', 'checkinUntil', 'present', 'presentOnly', 'opensIn', 'readyTitle', 'readyLead', 'cameraTitle', 'cameraLead', 'recordedTitle', 'alreadyTitle', 'queuedTitle', 'refusedTitle', 'recordedDetail', 'openBadge', 'openDetail', 'help', 'nextPerson', 'unlockWait', 'connIdle', 'connQueue', 'connOffline', 'connCamera', 'titles', 'wakeUnsupported', 'wakeRefused', 'check', 'camera', 'offline' ), array_keys( $config['strings'] ) );
	}

	public function test_the_camera_follows_its_option(): void {
		$this->settings = array( 'terminal_camera' => 1 );
		$this->assertTrue( AudienceTerminalPage::camera_allowed() );
		$this->settings = array( 'terminal_camera' => '0' );
		$this->assertFalse( AudienceTerminalPage::camera_allowed() );
		$this->settings = array();
		$this->assertFalse( AudienceTerminalPage::camera_allowed(), 'off until an administrator turns it on' );
	}

	public function test_the_dark_theme_is_on_the_root_whatever_the_setting(): void {
		$this->settings['dark_mode'] = 'off';
		Functions\when( 'get_language_attributes' )->justReturn( 'lang="en-US"' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Example' );
		Functions\when( 'wp_style_is' )->justReturn( false );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'wp_print_styles' )->justReturn( null );
		Functions\when( 'wp_print_scripts' )->justReturn( null );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'home_url' )->justReturn( 'https://example.com/' );
		Functions\when( 'add_query_arg' )->returnArg( 3 );
		Functions\when( 'rest_url' )->returnArg();

		$this->assertStringStartsWith( '<!DOCTYPE html><html lang="en-US" class="ffc-dark-mode">', AudienceTerminalPage::document() );
	}
}

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
		$this->assertSame( 'https://example.com/?ffc_terminal=install', AudienceTerminalPage::install_url(), 'the install page (#2813)' );
	}

	public function test_the_address_is_its_own_path_with_pretty_permalinks(): void {
		$this->pretty();

		$this->assertSame( 'https://example.com/site/terminal/', AudienceTerminalPage::url() );
		$this->assertSame( 'https://example.com/site/terminal/manifest.webmanifest', AudienceTerminalPage::manifest_url() );
		$this->assertSame( 'https://example.com/site/terminal/install/', AudienceTerminalPage::install_url() );
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
		$this->assertSame( 'worker', AudienceTerminalPage::route_from_path( '/terminal/sw.js', '/' ), 'the shell worker (#2783)' );
		$this->assertSame( 'worker', AudienceTerminalPage::route_from_path( '/site/terminal/sw.js', '/site/' ) );
		$this->assertSame( '', AudienceTerminalPage::route_from_path( '/sw.js', '/' ), 'never a worker at the site root' );
		$this->assertSame( '', AudienceTerminalPage::route_from_path( '/app/sw.js', '/' ) );
		$this->assertSame( 'install', AudienceTerminalPage::route_from_path( '/terminal/install/', '/' ), 'the install page (#2813)' );
		$this->assertSame( 'install', AudienceTerminalPage::route_from_path( '/site/terminal/install', '/site/' ) );
		$this->assertSame( '', AudienceTerminalPage::route_from_path( '/terminal/install/more', '/' ) );
	}

	public function test_the_worker_lives_under_the_terminal_and_only_with_pretty_permalinks(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com/' );
		$this->assertSame( '', AudienceTerminalPage::worker_url() );

		$this->pretty();
		$this->assertSame( 'https://example.com/site/terminal/sw.js', AudienceTerminalPage::worker_url() );
	}

	public function test_the_worker_script_carries_its_versioned_cache_and_the_plugin_file(): void {
		Functions\when( 'wp_json_encode' )->alias( static fn ( $value ) => json_encode( $value ) );

		$script = AudienceTerminalPage::worker();
		$this->assertStringStartsWith( 'self.ffcTerminalCache = "ffc-terminal-' . FFC_VERSION, $script );
		$this->assertStringContainsString( 'FFCTerminalShell', $script, 'the plugin\'s own worker file follows' );

		$this->assertSame( 'ffc-terminal-' . FFC_VERSION, AudienceTerminalPage::cache_name( false ), 'a release names the cache by its version' );
		$this->assertMatchesRegularExpression( '/^ffc-terminal-' . preg_quote( FFC_VERSION, '/' ) . '\\.\\d{9,}$/', AudienceTerminalPage::cache_name( true ), 'a testes site adds the newest file time of the shell' );
		$this->assertSame( 'text/javascript; charset=utf-8', AudienceTerminalPage::headers( 'text/javascript; charset=utf-8' )['Content-Type'] );
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
		$this->assertSame(
			array(
				array(
					'platform' => 'webapp',
					'url'      => 'https://example.com/site/terminal/manifest.webmanifest',
				),
			),
			$manifest['related_applications'],
			'names itself, so the install page can tell it is installed (#2813)'
		);
		$this->assertArrayNotHasKey( 'prefer_related_applications', $manifest );
	}

	public function test_without_pretty_permalinks_the_manifest_starts_on_the_query_form(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com/' );
		Functions\when( 'add_query_arg' )->alias( static fn ( $key, $value, $url ) => $url . '?' . $key . '=' . $value );
		Functions\when( 'get_bloginfo' )->justReturn( '' );

		$manifest = AudienceTerminalPage::manifest();

		$this->assertSame( '/?ffc_terminal=1', $manifest['start_url'] );
		$this->assertSame( '/', $manifest['scope'] );
		$this->assertSame( 'Check-in terminal', $manifest['name'] );
		$this->assertSame(
			array( FFC_PLUGIN_URL . 'assets/img/terminal/terminal-icon-192.png', FFC_PLUGIN_URL . 'assets/img/terminal/terminal-icon-512.png' ),
			array_column( $manifest['icons'], 'src' ),
			'no site icon: the plugin\'s own, since a browser installs no app without an icon (#2818)'
		);
		$this->assertSame( array( '192x192', '512x512' ), array_column( $manifest['icons'], 'sizes' ) );
		foreach ( array( 192, 512 ) as $size ) {
			$this->assertFileExists( dirname( __DIR__, 2 ) . '/assets/img/terminal/terminal-icon-' . $size . '.png' );
			$this->assertSame( array( $size, $size ), array_slice( (array) getimagesize( dirname( __DIR__, 2 ) . '/assets/img/terminal/terminal-icon-' . $size . '.png' ), 0, 2 ) );
		}
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

	public function test_the_install_page_is_public_in_the_terminals_look_with_every_state_hidden(): void {
		$printed = array();
		$this->pretty();
		Functions\when( 'get_language_attributes' )->justReturn( 'lang="pt-BR"' );
		Functions\when( 'get_bloginfo' )->justReturn( 'Example' );
		Functions\when( 'get_site_icon_url' )->justReturn( '' );
		Functions\when( 'wp_style_is' )->justReturn( false );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'wp_print_styles' )->alias( static function ( $handles ) use ( &$printed ) {
			$printed[] = 'style:' . implode( ',', $handles );
		} );
		Functions\when( 'wp_print_scripts' )->alias( static function ( $handles ) use ( &$printed ) {
			$printed[] = 'script:' . implode( ',', $handles );
		} );
		Functions\when( 'esc_url' )->returnArg();

		$html = AudienceTerminalPage::install_document();

		$this->assertStringStartsWith( '<!DOCTYPE html><html lang="pt-BR" class="ffc-dark-mode"><head>', $html, 'the terminal\'s dark look' );
		$this->assertStringContainsString( '<link rel="manifest" href="https://example.com/site/terminal/manifest.webmanifest">', $html, 'the browser offers the install from here' );
		$this->assertStringContainsString( 'data-ffc-terminal-install-page data-copied=', $html );
		$this->assertStringContainsString( 'href="https://example.com/site/terminal/"', $html, 'the installed state links back to the terminal, the server printing the address' );
		foreach ( array( 'wait', 'prompt', 'ios-top', 'ios-bottom', 'ios-old', 'installed', 'menu', 'unsupported' ) as $state ) {
			$this->assertMatchesRegularExpression( '/data-state="' . $state . '" hidden>/', $html, 'every state starts hidden: ' . $state );
		}
		$this->assertStringContainsString( 'value="https://example.com/site/terminal/install/"', $html, 'the address to copy is this page' );
		$this->assertStringNotContainsString( 'ffcTerminal', $html, 'no pairing data on the install page' );
		$this->assertSame( array( 'style:ffc-terminal-kiosk', 'script:ffc-terminal-install' ), $printed );
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
		$this->assertStringContainsString( 'data-ffc-terminal-part="queue-list"', $html, 'the operator menu lists the terminal\'s events (#2780)' );
		$this->assertStringContainsString( 'data-ffc-terminal-part="queue-open-add"', $html );
		foreach ( array( 'pairing', 'setup', 'locked', 'ready', 'between', 'result', 'unlock', 'menu' ) as $view ) {
			$this->assertStringContainsString( 'data-ffc-terminal-view="' . $view . '"', $html, $view );
		}
		$this->assertStringContainsString( 'id="ffc-terminal-code"', $html );
		$this->assertStringContainsString( 'id="ffc-terminal-reading"', $html );
		$this->assertMatchesRegularExpression( '/role="status"[^>]*data-ffc-terminal-part="result-ok"/', $html, 'a recorded result is announced politely' );
		$this->assertMatchesRegularExpression( '/role="alert"[^>]*data-ffc-terminal-part="result-refused"/', $html, 'a refusal is announced at once' );
		$this->assertSame( array( 'style:ffc-terminal-kiosk', 'script:ffc-terminal-kiosk,ffc-terminal-install' ), $printed, 'the dark-mode toggle is never printed, so the setting cannot turn the terminal light' );
		$this->assertMatchesRegularExpression( '/<aside class="ffc-terminal-install-banner" data-ffc-terminal-install-banner[^>]*hidden>/', $html, 'the install banner starts hidden; the script shows it in a browser tab only (#2813)' );
		$this->assertStringContainsString( 'href="https://example.com/?ffc_terminal=install"', $html, 'it leads to the install page' );
		$this->assertSame( array( 'ffc-common' ), $enqueued['style:ffc-terminal-kiosk'][1] ?? null );
		$this->assertSame( array( 'ffc-presence-verifier' ), $enqueued['ffc-terminal-kiosk'][1] ?? null, 'the kiosk checks codes offline with the verifier' );
		$this->assertStringContainsString( 'assets/js/ffc-presence-verifier', $enqueued['ffc-presence-verifier'][0] ?? '' );
		$this->assertSame( 'ffcTerminal', $localized['ffc-terminal-kiosk'][0] );
		$config = $localized['ffc-terminal-kiosk'][1];
		$this->assertSame( 'https://example.com/wp-json/ffc/v1/terminal/', $config['rest'] );
		$this->assertSame( 'https://example.com/wp-json/ffc/v1/presence/', $config['presence'] );
		$this->assertArrayNotHasKey( 'nonce', $config, 'a terminal has no account to carry a nonce for' );
		$this->assertFalse( $config['camera'], 'the camera is off until an administrator allows it' );
		$this->assertSame( '', $config['worker'], 'the query form registers no worker, which would claim the whole site (#2783)' );
		$this->assertStringContainsString( 'data-ffc-terminal-part="offline"', $html );
		$this->assertSame( array( 'ready', 'reading', 'insecure', 'unsupported', 'denied', 'failed' ), array_keys( $config['strings']['camera'] ) );
		$this->assertStringContainsString( 'data-ffc-terminal-part="camera-start"', $html );
		$this->assertMatchesRegularExpression( '/<video[^>]*playsinline[^>]*muted/', $html, 'the viewfinder plays inline and silent' );
		$this->assertSame( array( 'offlinePairing', 'storage', 'pairing', 'paired', 'failed', 'events', 'until', 'expired', 'revoked', 'operator', 'clockOff', 'ended', 'queued', 'alreadyQueued', 'queue', 'sent', 'nowChecking', 'nextEvent', 'openUntil', 'notOpen', 'opensAt', 'noEvents', 'idleRefused', 'terminalTitle', 'notPaired', 'checkinUntil', 'present', 'presentOnly', 'opensIn', 'readyTitle', 'readyLead', 'cameraTitle', 'cameraLead', 'recordedTitle', 'alreadyTitle', 'queuedTitle', 'refusedTitle', 'recordedDetail', 'openBadge', 'openDetail', 'help', 'nextPerson', 'unlockWait', 'connIdle', 'connQueue', 'connOffline', 'connCamera', 'setup', 'queueEdit', 'titles', 'wakeUnsupported', 'wakeRefused', 'check', 'camera', 'offline' ), array_keys( $config['strings'] ) );
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

<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Audience\AudienceAppScreens;
use FreeFormCertificate\Shortcodes\AgendaAppScreen;
use FreeFormCertificate\Shortcodes\DashboardAppScreens;
use PHPUnit\Framework\TestCase;

/**
 * The FFC screens registered with the Site PWA app (#2750): only through
 * the app's action, only the ones this person may open, with the contract
 * version checked.
 *
 * @covers \FreeFormCertificate\Shortcodes\DashboardAppScreens
 * @covers \FreeFormCertificate\Shortcodes\AgendaAppScreen
 * @covers \FreeFormCertificate\Audience\AudienceAppScreens
 */
class AppScreensTest extends TestCase {

	/** @var array<string, mixed> The `ffc_settings` option. */
	private array $settings = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn ( $path = '' ) => 'https://example.com/wp-admin/' . $path );
		Functions\when( 'get_option' )->alias( fn ( $key, $default = false ) => 'ffc_settings' === $key ? $this->settings : $default );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_user_by' )->justReturn( (object) array( 'ID' => 7 ) );
		Functions\when( 'user_can' )->justReturn( false );
		$this->settings = array(
			'module_recruitment_enabled'    => false,
			'module_reregistration_enabled' => false,
			'module_audiences_enabled'      => false,
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A registry double that records what was added.
	 */
	private function registry(): object {
		return new class() {
			/** @var array<string, array<string, mixed>> */
			public array $added = array();

			/**
			 * @param array<string, mixed> $args Screen arguments.
			 */
			public function add( string $id, array $args ): bool {
				$this->added[ $id ] = $args;
				return true;
			}
		};
	}

	public function test_both_classes_hook_only_the_apps_action(): void {
		$hooks = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook, $callback, $priority = 10, $args = 1 ) use ( &$hooks ) {
				$hooks[] = array( $hook, $callback, $args );
				return true;
			}
		);

		DashboardAppScreens::init();
		AudienceAppScreens::init();

		$this->assertSame(
			array(
				array( 'site_pwa_register_screens', array( DashboardAppScreens::class, 'register' ), 2 ),
				array( 'site_pwa_register_screens', array( AudienceAppScreens::class, 'register' ), 2 ),
			),
			$hooks
		);
	}

	/**
	 * @dataProvider unknown_contracts
	 *
	 * @param mixed $screens Registry.
	 * @param mixed $api     Version.
	 */
	public function test_an_unknown_contract_registers_nothing( $screens, $api ): void {
		$this->settings['module_audiences_enabled'] = true;
		Functions\when( 'user_can' )->justReturn( true );

		DashboardAppScreens::register( $screens, $api );
		AudienceAppScreens::register( $screens, $api );

		$this->assertSame( array(), is_object( $screens ) && property_exists( $screens, 'added' ) ? $screens->added : array() );
	}

	/**
	 * @return array<string, array{0: mixed, 1: mixed}>
	 */
	public function unknown_contracts(): array {
		return array(
			'a newer version'  => array( $this->registry(), 2 ),
			'no version'       => array( $this->registry(), 0 ),
			'not a registry'   => array( new \stdClass(), 1 ),
			'not even objects' => array( 'screens', 1 ),
		);
	}

	public function test_a_person_gets_the_screens_of_the_tabs_they_see(): void {
		Functions\when( 'user_can' )->alias(
			static fn ( $user, $cap ) => in_array( $cap, array( 'ffc_view_own_certificates', 'ffc_view_own_appointments' ), true )
		);
		$registry = $this->registry();

		DashboardAppScreens::register( $registry, 1 );

		$this->assertSame( array( 'calendar', 'certificates', 'messages', 'profile' ), array_keys( $registry->added ) );
		$this->assertSame( 'nav', $registry->added['calendar']['place'] );
		$this->assertSame( 1, $registry->added['calendar']['position'] );
		$this->assertSame( 'nav', $registry->added['certificates']['place'] );
		$this->assertSame( 'more', $registry->added['profile']['place'] );
		$this->assertIsCallable( $registry->added['certificates']['render'] );
	}

	public function test_the_calendar_screen_reads_the_routes_of_the_tabs_the_person_sees(): void {
		$scripts   = array();
		$localized = array();
		Functions\when( 'wp_enqueue_style' )->justReturn( true );
		Functions\when( 'wp_enqueue_script' )->alias( static function ( $handle, $src = '' ) use ( &$scripts ) {
			$scripts[ $handle ] = $src;
		} );
		Functions\when( 'wp_localize_script' )->alias( static function ( $handle, $name, $data ) use ( &$localized ) {
			$localized[ $handle ] = array( $name, $data );
			return true;
		} );
		Functions\when( 'wp_style_is' )->justReturn( false );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $text ) {
			echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test stub.
		} );
		Functions\when( 'esc_attr_e' )->alias( static function ( $text ) {
			echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test stub.
		} );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'rest_url' )->alias( static fn( $path ) => 'https://example.com/wp-json/' . $path );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'current_time' )->justReturn( '2026-11-21' );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_posts' )->justReturn( array( 11, 12 ) );
		Functions\when( 'get_the_title' )->alias( static fn( $id ) => 'Calendar ' . $id );
		Functions\when( 'determine_locale' )->justReturn( 'pt_BR' );

		$html = AgendaAppScreen::render( array( 'audience' ) );

		$this->assertStringContainsString( 'data-ffc-app-agenda', $html );
		$this->assertStringContainsString( 'data-ffc-app-view="book"', $html );
		$this->assertStringContainsString( 'assets/js/ffc-app-agenda', $scripts['ffc-app-agenda'] );
		$config = $localized['ffc-app-agenda'][1];
		$this->assertSame( array( 'audience' => 'https://example.com/wp-json/ffc/v1/user/audience-bookings' ), $config['routes'], 'only the tabs the person sees' );
		$this->assertTrue( $config['presence'] );
		$this->assertSame( array(), $config['book'], 'no personal calendars without the appointments tab' );
		$this->assertSame( '2026-11-21', $config['today'] );
		$this->assertStringContainsString( '%d', $config['strings']['past'] );
	}

	public function test_a_signed_out_request_registers_nothing(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		$registry = $this->registry();

		DashboardAppScreens::register( $registry, 1 );

		$this->assertSame( array(), $registry->added );
	}

	public function test_the_calendar_screen_draws_only_the_calendar_tabs_this_person_has(): void {
		Functions\when( 'user_can' )->alias( static fn ( $user, $cap ) => 'ffc_view_own_appointments' === $cap );

		$this->assertSame( array( 'appointments', 'messages', 'profile' ), DashboardAppScreens::visible_tabs( 7 ) );
	}

	public function test_the_attendance_screens_wait_for_the_audience_module(): void {
		$registry = $this->registry();
		AudienceAppScreens::register( $registry, 1 );
		$this->assertSame( array(), $registry->added );

		$this->settings['module_audiences_enabled'] = true;
		AudienceAppScreens::register( $registry, 1 );

		$this->assertSame( array( 'presence', 'operator-pair', 'operator-terminals', 'operator-qr', 'operator-attendance' ), array_keys( $registry->added ) );
		$this->assertSame( 'ffc_view_own_audience_bookings', $registry->added['presence']['capability'] );
		$this->assertSame( 'nav', $registry->added['presence']['place'] );
		$caps = array(
			'operator-pair'       => 'ffc_manage_audiences_terminals',
			'operator-terminals'  => 'ffc_manage_audiences_terminals',
			'operator-qr'         => 'ffc_checkin_audiences',
			'operator-attendance' => 'ffc_checkin_audiences',
		);
		foreach ( $caps as $id => $cap ) {
			$this->assertSame( $cap, $registry->added[ $id ]['capability'], $id );
			$this->assertSame( 'more', $registry->added[ $id ]['place'], $id );
		}
		$this->assertSame( 'https://example.com/wp-admin/admin.php?page=ffc-scheduling-bookings', $registry->added['operator-attendance']['url'] );
		$this->assertArrayNotHasKey( 'render', $registry->added['operator-attendance'] );
		$this->assertSame( array( AudienceAppScreens::class, 'render_presence' ), $registry->added['presence']['render'] );
		$this->assertSame( array( AudienceAppScreens::class, 'render_operator_pair' ), $registry->added['operator-pair']['render'] );
		$this->assertSame( array( AudienceAppScreens::class, 'render_operator_terminals' ), $registry->added['operator-terminals']['render'] );
		$this->assertSame( array( AudienceAppScreens::class, 'render_operator_qr' ), $registry->added['operator-qr']['render'] );
	}

	public function test_the_presence_screen_draws_its_markup_and_hands_the_script_what_it_needs(): void {
		$styles    = array();
		$scripts   = array();
		$localized = array();
		Functions\when( 'wp_enqueue_style' )->alias( static function ( $handle, $src = '' ) use ( &$styles ) {
			$styles[ $handle ] = $src;
		} );
		Functions\when( 'wp_enqueue_script' )->alias( static function ( $handle, $src = '', $deps = array() ) use ( &$scripts ) {
			$scripts[ $handle ] = array( $src, $deps );
		} );
		Functions\when( 'wp_localize_script' )->alias( static function ( $handle, $name, $data ) use ( &$localized ) {
			$localized[ $handle ] = array( $name, $data );
			return true;
		} );
		Functions\when( 'wp_style_is' )->justReturn( false );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $text ) {
			echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test stub.
		} );
		Functions\when( 'esc_attr_e' )->alias( static function ( $text ) {
			echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test stub.
		} );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'rest_url' )->alias( static fn( $path ) => 'https://example.com/wp-json/' . $path );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );

		$html = AudienceAppScreens::render_presence();

		foreach ( array( 'unsupported', 'activate', 'code' ) as $view ) {
			$this->assertStringContainsString( 'data-ffc-presence-view="' . $view . '"', $html, $view );
		}
		$this->assertStringContainsString( 'data-ffc-presence-part="activate"', $html );
		$this->assertStringContainsString( 'id="ffc-presence-event"', $html );
		$this->assertArrayHasKey( 'ffc-presence-app', $styles );
		$this->assertStringContainsString( 'libs/js/qrcodegen-' . FFC_QRCODEGEN_VERSION . '.js', $scripts['ffc-qrcodegen'][0] );
		$this->assertSame( array( 'ffc-qrcodegen' ), $scripts['ffc-presence-app'][1] );
		$this->assertSame( 'ffcPresence', $localized['ffc-presence-app'][0] );
		$config = $localized['ffc-presence-app'][1];
		$this->assertSame( 'https://example.com/wp-json/ffc/v1/presence/', $config['rest'] );
		$this->assertSame( 7, $config['userId'] );
		$this->assertSame( array( 'storage', 'activating', 'activated', 'failed', 'next', 'expired', 'qrLabel', 'dismiss', 'fillForm' ), array_keys( $config['strings'] ) );
		$this->assertStringContainsString( '%d', $config['strings']['next'] );
	}

	/**
	 * Stub what an operator screen enqueues and render it.
	 *
	 * @param string $method Renderer.
	 * @return array{0: string, 1: array<string, array{0: string, 1: array<int, string>}>, 2: array<string, array{0: string, 1: array<string, mixed>}>, 3: array<string, string>}
	 */
	private function render_operator( string $method ): array {
		$styles    = array();
		$scripts   = array();
		$localized = array();
		Functions\when( 'wp_enqueue_style' )->alias( static function ( $handle, $src = '' ) use ( &$styles ) {
			$styles[ $handle ] = $src;
		} );
		Functions\when( 'wp_enqueue_script' )->alias( static function ( $handle, $src = '', $deps = array() ) use ( &$scripts ) {
			$scripts[ $handle ] = array( $src, $deps );
		} );
		Functions\when( 'wp_localize_script' )->alias( static function ( $handle, $name, $data ) use ( &$localized ) {
			$localized[ $handle ] = array( $name, $data );
			return true;
		} );
		Functions\when( 'wp_style_is' )->justReturn( false );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $text ) {
			echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test stub.
		} );
		Functions\when( 'esc_attr_e' )->alias( static function ( $text ) {
			echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test stub.
		} );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'rest_url' )->alias( static fn( $path ) => 'https://example.com/wp-json/' . $path );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );

		$html = AudienceAppScreens::$method();
		return array( $html, $scripts, $localized, $styles );
	}

	public function test_the_pair_screen_draws_the_event_list_the_label_and_the_code(): void {
		list( $html, $scripts, $localized, $styles ) = $this->render_operator( 'render_operator_pair' );

		$this->assertStringContainsString( 'data-ffc-terminal-operator="pair"', $html );
		foreach ( array( 'form', 'events', 'events-empty', 'label', 'generate', 'result', 'qr', 'code', 'countdown', 'again' ) as $part ) {
			$this->assertStringContainsString( 'data-ffc-op-part="' . $part . '"', $html, $part );
		}
		$this->assertStringNotContainsString( 'data-ffc-op-part="list"', $html );
		$this->assertArrayHasKey( 'ffc-terminal-operator', $styles );
		$this->assertSame( array( 'ffc-presence-app' ), $scripts['ffc-terminal-operator'][1] );
		$this->assertSame( array( 'ffc-qrcodegen' ), $scripts['ffc-presence-app'][1] );
		$this->assertSame( 'ffcTerminalOperator', $localized['ffc-terminal-operator'][0] );
		$config = $localized['ffc-terminal-operator'][1];
		$this->assertSame( 'https://example.com/wp-json/ffc/v1/terminal/', $config['rest'] );
		$this->assertSame( 'nonce', $config['nonce'] );
		$this->assertSame( 10, $config['maxBookings'] );
	}

	public function test_the_terminal_list_ends_a_terminal_behind_an_inline_confirmation(): void {
		list( $html ) = $this->render_operator( 'render_operator_terminals' );

		$this->assertStringContainsString( 'data-ffc-terminal-operator="terminals"', $html );
		$this->assertStringContainsString( '<template data-ffc-op-part="row">', $html );
		foreach ( array( 'end', 'keep', 'confirm-end' ) as $item ) {
			$this->assertStringContainsString( 'data-ffc-op-item="' . $item . '"', $html, $item );
		}
		$this->assertMatchesRegularExpression( '/data-ffc-op-item="confirm" hidden/', $html );
		$this->assertStringNotContainsString( 'confirm(', $html );
	}

	public function test_the_operator_qr_screen_draws_only_the_code(): void {
		list( $html ) = $this->render_operator( 'render_operator_qr' );

		$this->assertStringContainsString( 'data-ffc-terminal-operator="qr"', $html );
		$this->assertStringContainsString( 'data-ffc-op-part="qr"', $html );
		$this->assertStringNotContainsString( 'data-ffc-op-part="form"', $html );
	}
}

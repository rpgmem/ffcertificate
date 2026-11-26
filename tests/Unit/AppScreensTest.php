<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use FreeFormCertificate\Audience\AudienceAppScreens;
use FreeFormCertificate\Audience\AudiencePresenceDeviceCookie;
use FreeFormCertificate\Shortcodes\AgendaAppScreen;
use FreeFormCertificate\Shortcodes\CertificatesAppScreen;
use FreeFormCertificate\Shortcodes\DashboardAppScreens;
use FreeFormCertificate\Tests\Support\FakeCredentials;
use FreeFormCertificate\Tests\Support\FakeTerminalTables;
use PHPUnit\Framework\TestCase;

/**
 * The FFC screens registered with the Site PWA app (#2750): only through
 * the app's action, only the ones this person may open, with the contract
 * version checked.
 *
 * @covers \FreeFormCertificate\Shortcodes\DashboardAppScreens
 * @covers \FreeFormCertificate\Shortcodes\AgendaAppScreen
 * @covers \FreeFormCertificate\Shortcodes\CertificatesAppScreen
 * @covers \FreeFormCertificate\Audience\AudienceAppScreens
 * @covers \FreeFormCertificate\Audience\AudiencePresenceDeviceCookie
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

		$this->assertSame( 10, has_filter( 'site_pwa_profile_line', array( DashboardAppScreens::class, 'profile_line' ) ), 'the line under the name on More' );
		$this->assertSame( 10, has_filter( 'site_pwa_details_screen', array( DashboardAppScreens::class, 'details_screen' ) ), 'My details opens Profile (site-pwa#56)' );
		$this->assertSame( 10, has_filter( 'map_meta_cap', array( AudienceAppScreens::class, 'map_app_caps' ) ), 'the operator screens wait for presence (#2828)' );
		$this->assertSame( 10, has_filter( 'site_pwa_lock_required', array( AudienceAppScreens::class, 'lock_required' ) ), 'operators must lock the app (#2830)' );
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
		$this->assertSame( array( CertificatesAppScreen::class, 'render' ), DashboardAppScreens::definitions()['certificates']['render'], 'the app draws its own Certificates screen' );
		$this->assertSame( array( AgendaAppScreen::class, 'render' ), DashboardAppScreens::definitions()['calendar']['render'], 'the app draws its own Calendar screen' );
		$this->assertIsCallable( $registry->added['calendar']['render'] );
	}

	public function test_the_certificates_screen_reads_the_dashboard_route(): void {
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
		Functions\when( 'esc_attr_e' )->alias( static function ( $text ) {
			echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test stub.
		} );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'rest_url' )->alias( static fn( $path ) => 'https://example.com/wp-json/' . $path );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );

		$html = CertificatesAppScreen::render();

		$this->assertStringContainsString( 'data-ffc-app-certificates', $html );
		$this->assertStringContainsString( 'type="search"', $html );
		$this->assertStringContainsString( 'aria-label="Search by course or code"', $html );
		$this->assertArrayHasKey( 'ffc-app-certificates', $styles );
		$this->assertStringContainsString( 'assets/js/ffc-app-certificates', $scripts['ffc-app-certificates'][0] );
		$this->assertSame( 'ffcAppCertificates', $localized['ffc-app-certificates'][0] );
		$config = $localized['ffc-app-certificates'][1];
		$this->assertSame( 'https://example.com/wp-json/ffc/v1/user/certificates', $config['rest'] );
		$this->assertSame( CertificatesAppScreen::NEW_DAYS, $config['newDays'] );
		$this->assertStringContainsString( '%s', $config['strings']['issued'] );
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

	public function test_the_book_links_take_the_apps_address_when_site_pwa_gives_one(): void {
		Functions\when( 'get_posts' )->justReturn( array( 11, 12 ) );
		Functions\when( 'get_the_title' )->alias( static fn( $id ) => 'Calendar ' . $id );
		Functions\when( 'get_transient' )->justReturn( array( 'ffc_self_scheduling:11' => 101, 'ffc_self_scheduling:12' => 102 ) );
		Functions\when( 'get_post_status' )->justReturn( 'publish' );
		Functions\when( 'get_post_field' )->alias( static fn( $field, $page ) => '[ffc_self_scheduling id="' . ( $page - 90 ) . '"]' );
		Functions\when( 'get_permalink' )->alias( static fn( $page ) => 'https://example.com/page-' . $page . '/' );

		$this->assertSame(
			array(
				array( 'title' => 'Calendar 11', 'url' => 'https://example.com/page-101/' ),
				array( 'title' => 'Calendar 12', 'url' => 'https://example.com/page-102/' ),
			),
			AgendaAppScreen::bookable(),
			'without Site PWA the permalink stays'
		);

		Filters\expectApplied( 'site_pwa_app_url' )->twice()->andReturnUsing(
			static fn( $url ) => 'https://example.com/page-101/' === $url ? 'https://example.com/app/?p=101' : $url
		);

		$this->assertSame(
			array( 'https://example.com/app/?p=101', 'https://example.com/page-102/' ),
			array_column( AgendaAppScreen::bookable(), 'url' ),
			'the app address where Site PWA can draw the page, the permalink where it cannot (#2846)'
		);
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
		$this->assertSame( 'center', $registry->added['presence']['slot'], 'Presence is raised in the middle of the bar (site-pwa#23)' );
		$this->assertSame( 'qr', $registry->added['presence']['icon'] );
		$this->assertArrayNotHasKey( 'slot', $registry->added['operator-pair'], 'only Presence asks for the centre' );
		$caps = array(
			'operator-pair'       => 'ffc_app_pair_terminals',
			'operator-terminals'  => 'ffc_app_pair_terminals',
			'operator-qr'         => 'ffc_app_checkin_audiences',
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

		// More's Operator group (site-pwa#23): every operator screen, each with its line.
		foreach ( array_keys( $caps ) as $id ) {
			$this->assertSame( 'operator', $registry->added[ $id ]['section'], $id );
			$this->assertNotSame( '', $registry->added[ $id ]['subtitle'], $id );
		}
		$this->assertArrayNotHasKey( 'section', $registry->added['presence'], 'Presence is in the bar, not in a More group' );
		$this->assertSame( array( AudienceAppScreens::class, 'terminals_badge' ), $registry->added['operator-terminals']['badge'] );
		$this->assertArrayNotHasKey( 'badge', $registry->added['operator-pair'] );
	}

	public function test_the_operator_screens_show_only_on_the_phone_that_holds_the_active_presence(): void {
		Functions\when( 'wp_salt' )->justReturn( FakeCredentials::WP_SALT );
		Functions\when( 'wp_unslash' )->returnArg();
		$devices = array();
		$db      = new class( $devices ) extends \wpdb {
			/** @var array<string, array<string, mixed>> */
			public array $rows;
			/** @param array<string, array<string, mixed>> $rows Rows by key id. */
			public function __construct( array &$rows ) {
				$this->prefix = 'wp_';
				$this->rows   = &$rows;
			}
			/**
			 * @param string $query Query.
			 * @param mixed  ...$args Arguments.
			 * @return string
			 */
			public function prepare( $query, ...$args ) {
				return (string) $args[1];
			}
			/**
			 * @param string $query The key id, as prepared above.
			 * @param string $output Output shape.
			 * @param int    $y Row offset.
			 * @return array<string, mixed>|null
			 */
			public function get_row( $query = null, $output = OBJECT, $y = 0 ) {
				return $this->rows[ $query ] ?? null;
			}
		};
		$device = static fn( int $user, ?int $revoked ): array => array(
			'id'           => 1,
			'user_id'      => $user,
			'device_id'    => 'd',
			'key_id'       => 'k',
			'public_key'   => 'p',
			'is_change'    => 0,
			'activated_at' => 100,
			'revoked_at'   => $revoked,
		);
		$had    = array_key_exists( 'wpdb', $GLOBALS );
		$backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = $db;
		unset( $_COOKIE[ AudiencePresenceDeviceCookie::NAME ] );
		try {
			$this->assertSame( array( 'edit_posts' ), AudienceAppScreens::map_app_caps( array( 'edit_posts' ), 'edit_posts', 7 ), 'other capabilities pass through' );
			$this->assertSame( array( 'do_not_allow' ), AudienceAppScreens::map_app_caps( array(), 'ffc_app_pair_terminals', 7 ), 'a phone that never activated presence' );

			$devices['k-here']  = $device( 7, null );
			$devices['k-other'] = $device( 7, 500 );
			$_COOKIE[ AudiencePresenceDeviceCookie::NAME ] = AudiencePresenceDeviceCookie::value( 7, 'k-here' );
			$this->assertSame( array( 'ffc_manage_audiences_terminals' ), AudienceAppScreens::map_app_caps( array(), 'ffc_app_pair_terminals', 7 ), 'the phone holding the active key' );
			$this->assertSame( array( 'ffc_checkin_audiences' ), AudienceAppScreens::map_app_caps( array(), 'ffc_app_checkin_audiences', 7 ) );
			$this->assertSame( array( 'do_not_allow' ), AudienceAppScreens::map_app_caps( array(), 'ffc_app_pair_terminals', 8 ), 'the same cookie names another account' );

			$_COOKIE[ AudiencePresenceDeviceCookie::NAME ] = AudiencePresenceDeviceCookie::value( 7, 'k-other' );
			$this->assertSame( array( 'do_not_allow' ), AudienceAppScreens::map_app_caps( array(), 'ffc_app_pair_terminals', 7 ), 'a phone whose key was replaced' );

			$_COOKIE[ AudiencePresenceDeviceCookie::NAME ] = 'k-here.' . str_repeat( '0', 64 );
			$this->assertNull( AudiencePresenceDeviceCookie::active_key( 7 ), 'a forged signature' );
			$this->assertNull( AudiencePresenceDeviceCookie::active_key( 7, 'k-here' ), 'no signature' );
			$this->assertSame( 'k-here', AudiencePresenceDeviceCookie::active_key( 7, AudiencePresenceDeviceCookie::value( 7, 'k-here' ) ) );
			$this->assertNull( AudiencePresenceDeviceCookie::active_key( 0, AudiencePresenceDeviceCookie::value( 0, 'k-here' ) ), 'signed out' );
		} finally {
			unset( $_COOKIE[ AudiencePresenceDeviceCookie::NAME ] );
			if ( $had ) {
				$GLOBALS['wpdb'] = $backup;
			} else {
				unset( $GLOBALS['wpdb'] );
			}
		}
	}

	public function test_the_app_lock_is_mandatory_for_operators_only(): void {
		$operator = new \WP_User( 7 );
		$user_can = array();
		Functions\when( 'user_can' )->alias(
			static function ( $user, $cap ) use ( &$user_can ) {
				return in_array( $cap, $user_can[ $user->ID ] ?? array(), true );
			}
		);

		$this->assertFalse( AudienceAppScreens::lock_required( false, $operator ), 'the audience module is off' );
		$this->assertTrue( AudienceAppScreens::lock_required( true, $operator ), 'another plugin already required it' );

		$this->settings['module_audiences_enabled'] = true;
		$this->assertFalse( AudienceAppScreens::lock_required( false, $operator ), 'a participant is only invited' );

		$user_can[7] = array( 'ffc_checkin_audiences' );
		$this->assertTrue( AudienceAppScreens::lock_required( false, $operator ), 'a check-in operator' );

		$user_can[7] = array( 'ffc_manage_audiences_terminals' );
		$this->assertTrue( AudienceAppScreens::lock_required( false, $operator ), 'a terminal operator' );

		$this->assertFalse( AudienceAppScreens::lock_required( false, new \WP_User( 0 ) ), 'signed out' );
		$this->assertFalse( AudienceAppScreens::lock_required( false, 'not a user' ) );
	}

	public function test_my_terminals_counts_the_operators_active_terminals_and_those_not_heard_from(): void {
		$tables = new FakeTerminalTables();
		$row    = static fn( int $id, int $by, ?int $seen, ?int $revoked = null, int $expires = 2000 ): array => array(
			'id'             => $id,
			'token_hash'     => 't' . $id,
			'device_hash'    => 'd' . $id,
			'label'          => 'T' . $id,
			'bookings'       => '[]',
			'removed'        => '{}',
			'paired_by'      => $by,
			'paired_at'      => $id,
			'expires_at'     => $expires,
			'last_seen_at'   => $seen,
			'queued'         => 0,
			'readings'       => 0,
			'auto_end_hours' => null,
			'revoked_at'     => $revoked,
			'revoked_by'     => null,
		);
		$had    = array_key_exists( 'wpdb', $GLOBALS );
		$backup = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = $tables;
		try {
			$this->assertSame( '', AudienceAppScreens::terminals_badge( 1000, 7 ), 'no active terminal, no badge' );

			$tables->terminals = array(
				1 => $row( 1, 7, 990 ),          // heard from 10 s ago: online.
				2 => $row( 2, 7, 500 ),          // silent for 500 s: offline.
				3 => $row( 3, 7, null ),         // never heard from: offline.
				4 => $row( 4, 7, 999, 900 ),     // ended by hand: not counted.
				5 => $row( 5, 7, 999, null, 900 ), // expired: not counted.
				6 => $row( 6, 8, 999 ),          // another operator's.
			);
			$this->assertSame( '3 active · 2 offline', AudienceAppScreens::terminals_badge( 1000, 7 ) );
			$this->assertSame( '1 active · 0 offline', AudienceAppScreens::terminals_badge( 1000, 8 ) );
			$this->assertSame( '', AudienceAppScreens::terminals_badge( 1000, 0 ), 'signed out' );
		} finally {
			if ( $had ) {
				$GLOBALS['wpdb'] = $backup;
			} else {
				unset( $GLOBALS['wpdb'] );
			}
		}
	}

	public function test_the_profile_line_shows_the_masked_rf_and_the_department(): void {
		$this->assertSame( 'RF ***.**1-0 · Teaching', DashboardAppScreens::line( ' ***.**1-0 ', 'Teaching' ) );
		$this->assertSame( 'RF ***.**1-0', DashboardAppScreens::line( '***.**1-0', '' ) );
		$this->assertSame( 'Teaching', DashboardAppScreens::line( null, 'Teaching' ) );
		$this->assertSame( '', DashboardAppScreens::line( null, array() ) );

		$this->assertSame( 'set by another plugin', DashboardAppScreens::profile_line( 'set by another plugin', new \WP_User( 7 ) ), 'a line already set is kept' );
		$this->assertSame( '', DashboardAppScreens::profile_line( '', null ) );
		$this->assertSame( 42, DashboardAppScreens::profile_line( 42, new \WP_User( 7 ) ) );
	}

	public function test_my_details_names_the_profile_screen_unless_another_plugin_named_one(): void {
		$this->assertSame( 'profile', DashboardAppScreens::details_screen( '' ) );
		$this->assertArrayHasKey( DashboardAppScreens::PROFILE_SCREEN, DashboardAppScreens::definitions(), 'the screen it names is one this class registers' );
		$this->assertSame( 'other', DashboardAppScreens::details_screen( 'other' ), 'a screen another plugin named is kept' );
		$this->assertNull( DashboardAppScreens::details_screen( null ) );
	}

	public function test_reregistration_shows_no_badge_while_the_module_is_off_or_nobody_is_signed_in(): void {
		$this->assertSame( '', DashboardAppScreens::reregistration_badge( 7 ) );
		$this->settings['module_reregistration_enabled'] = true;
		$this->assertSame( '', DashboardAppScreens::reregistration_badge( 0 ) );
		$this->assertSame( array( DashboardAppScreens::class, 'reregistration_badge' ), DashboardAppScreens::definitions()['reregistration']['badge'] );
		$this->assertNotSame( '', DashboardAppScreens::definitions()['reregistration']['subtitle'] );
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

		Functions\when( 'home_url' )->justReturn( 'https://example.com/' );
		Functions\when( 'esc_url' )->returnArg();

		$html = AudienceAppScreens::render_presence();

		foreach ( array( 'unsupported', 'activate', 'code' ) as $view ) {
			$this->assertStringContainsString( 'data-ffc-presence-view="' . $view . '"', $html, $view );
		}
		$this->assertStringContainsString( 'data-ffc-presence-part="activate"', $html );
		foreach ( array( 'current', 'facts', 'changes', 'offline', 'later' ) as $part ) {
			$this->assertStringContainsString( 'data-ffc-presence-part="' . $part . '"', $html, $part );
		}
		$this->assertStringContainsString( 'id="ffc-presence-event"', $html );
		$this->assertArrayHasKey( 'ffc-presence-app', $styles );
		$this->assertStringContainsString( 'libs/js/qrcodegen-' . FFC_QRCODEGEN_VERSION . '.js', $scripts['ffc-qrcodegen'][0] );
		$this->assertSame( array( 'ffc-qrcodegen' ), $scripts['ffc-presence-app'][1] );
		$this->assertSame( 'ffcPresence', $localized['ffc-presence-app'][0] );
		$config = $localized['ffc-presence-app'][1];
		$this->assertSame( 'https://example.com/wp-json/ffc/v1/presence/', $config['rest'] );
		$this->assertSame( 7, $config['userId'] );
		$this->assertSame( array( 'storage', 'activating', 'activated', 'failed', 'next', 'expired', 'qrLabel', 'dismiss', 'fillForm', 'recorded', 'alsoRecorded', 'upcoming', 'current', 'otherPhone', 'titleFirst', 'titleElsewhere', 'activateHere', 'moveHere', 'changes', 'dayOne', 'days' ), array_keys( $config['strings'] ) );
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

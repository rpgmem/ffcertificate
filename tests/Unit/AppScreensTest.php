<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Audience\AudienceAppScreens;
use FreeFormCertificate\Shortcodes\DashboardAppScreens;
use PHPUnit\Framework\TestCase;

/**
 * The FFC screens registered with the Site PWA app (#2750): only through
 * the app's action, only the ones this person may open, with the contract
 * version checked.
 *
 * @covers \FreeFormCertificate\Shortcodes\DashboardAppScreens
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
		foreach ( array( 'operator-pair', 'operator-terminals', 'operator-qr', 'operator-attendance' ) as $id ) {
			$this->assertSame( 'ffc_checkin_audiences', $registry->added[ $id ]['capability'], $id );
			$this->assertSame( 'more', $registry->added[ $id ]['place'], $id );
		}
		$this->assertSame( 'https://example.com/wp-admin/admin.php?page=ffc-scheduling-bookings', $registry->added['operator-attendance']['url'] );
		$this->assertArrayNotHasKey( 'render', $registry->added['operator-attendance'] );
		$this->assertSame( array( AudienceAppScreens::class, 'render_coming_soon' ), $registry->added['presence']['render'] );
	}

	public function test_a_screen_not_built_yet_says_so(): void {
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_style_is' )->justReturn( false );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();

		$html = AudienceAppScreens::render_coming_soon();

		$this->assertStringContainsString( 'data-ffc-notice="app-coming-soon"', $html );
		$this->assertStringContainsString( 'This screen arrives in a coming update.', $html );
	}
}

<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Shortcodes\AppLook;
use PHPUnit\Framework\TestCase;

/**
 * The look this plugin offers the installable app through Site PWA's filters (#2873).
 *
 * @covers \FreeFormCertificate\Shortcodes\AppLook
 */
final class AppLookTest extends TestCase {

	/**
	 * What get_option( 'ffc_settings' ) returns.
	 *
	 * @var array<string, mixed>
	 */
	private array $stored = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_option' )->alias( fn() => $this->stored );
		Functions\when( 'is_admin' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_init_hooks_the_three_site_pwa_filters(): void {
		$hooked = array();
		Functions\when( 'add_filter' )->alias(
			static function ( $hook, $callback ) use ( &$hooked ) {
				$hooked[ $hook ] = $callback;
				return true;
			}
		);

		AppLook::init();

		$this->assertSame(
			array(
				'site_pwa_identity_sources'  => array( AppLook::class, 'identity_sources' ),
				'site_pwa_scheme_sources'    => array( AppLook::class, 'scheme_sources' ),
				'site_pwa_dark_root_classes' => array( AppLook::class, 'dark_root_classes' ),
			),
			$hooked
		);
	}

	public function test_both_sources_register_under_the_stored_id_and_keep_the_others(): void {
		$other    = array( 'label' => 'Other', 'read' => 'strlen' );
		$identity = AppLook::identity_sources( array( 'other' => $other ) );
		$scheme   = AppLook::scheme_sources( array( 'other' => $other ) );

		$this->assertSame( array( 'other', 'ffc' ), array_keys( $identity ) );
		$this->assertSame( array( 'other', 'ffc' ), array_keys( $scheme ) );
		$this->assertSame( 'Free Form Certificate: Public Design', $identity['ffc']['label'] );
		$this->assertSame( 'Free Form Certificate: Dark Mode', $scheme['ffc']['label'] );
		$this->assertTrue( is_callable( $identity['ffc']['read'] ) );
		$this->assertTrue( is_callable( $scheme['ffc']['read'] ) );
	}

	public function test_a_filter_value_that_is_not_an_array_passes_through(): void {
		$this->assertSame( 'broken', AppLook::identity_sources( 'broken' ) );
		$this->assertNull( AppLook::scheme_sources( null ) );
		$this->assertSame( false, AppLook::dark_root_classes( false ) );
	}

	public function test_identity_is_null_while_public_design_is_off(): void {
		$this->stored = array( 'public_design' => 0, 'public_design_accent' => '#123456' );

		$this->assertNull( AppLook::identity() );
	}

	public function test_identity_maps_the_card_corner_to_radius_and_the_control_corner_to_control_radius(): void {
		$this->stored = array(
			'public_design'             => 1,
			'public_design_accent'      => '#1a5276',
			'public_design_radius'      => 6,
			'public_design_card_radius' => 14,
		);

		$this->assertSame(
			array(
				'accent'         => '#1a5276',
				'radius'         => 14,
				'control_radius' => 6,
			),
			AppLook::identity()
		);
	}

	/**
	 * @return array<string, array{0: mixed, 1: string|null}>
	 */
	public static function dark_modes(): array {
		return array(
			'off'     => array( 'off', 'light' ),
			'on'      => array( 'on', 'dark' ),
			'auto'    => array( 'auto', 'auto' ),
			'unknown' => array( 'sepia', null ),
			'not set' => array( null, 'light' ),
		);
	}

	/**
	 * @dataProvider dark_modes
	 *
	 * @param mixed       $stored   The stored dark_mode.
	 * @param string|null $expected What the app reads.
	 */
	public function test_scheme_speaks_the_apps_words( $stored, ?string $expected ): void {
		$this->stored = null === $stored ? array() : array( 'dark_mode' => $stored );

		$this->assertSame( $expected, AppLook::scheme() );
	}

	public function test_the_dark_root_class_is_added_once(): void {
		$this->assertSame( array( 'theme-dark', 'ffc-dark-mode' ), AppLook::dark_root_classes( array( 'theme-dark' ) ) );
		$this->assertSame( array( 'ffc-dark-mode' ), AppLook::dark_root_classes( array( 'ffc-dark-mode' ) ) );
	}
}

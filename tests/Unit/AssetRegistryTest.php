<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\AssetRegistry;

/**
 * Tests for AssetRegistry: the shared scripts every screen enqueues by handle (#1924).
 *
 * The registrations moved here from Loader, and so did their tests.
 *
 * @covers \FreeFormCertificate\Core\AssetRegistry
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AssetRegistryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var list<array{handle: string, src: string, deps: array<int, string>, ver: mixed, in_footer: mixed}> */
	private array $registered = array();

	/** @var list<array{handle: string, object_name: string, l10n: mixed}> */
	private array $localized = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Alias mock: AssetHelper is final and static. The separate process
		// gives each test a fresh class table for it.
		$helper = Mockery::mock( 'alias:\FreeFormCertificate\Core\AssetHelper' );
		$helper->shouldReceive( 'asset_suffix' )->andReturn( '.min' );
		$helper->shouldReceive( 'core_script_config' )->andReturn( array( 'version' => '1.0.0' ) );

		$registered = &$this->registered;
		$localized  = &$this->localized;

		Functions\when( 'wp_register_script' )->alias(
			function ( $handle, $src, $deps = array(), $ver = false, $in_footer = false ) use ( &$registered ) {
				$registered[] = array(
					'handle'    => $handle,
					'src'       => $src,
					'deps'      => $deps,
					'ver'       => $ver,
					'in_footer' => $in_footer,
				);
			}
		);
		Functions\when( 'wp_localize_script' )->alias(
			function ( $handle, $object_name, $l10n ) use ( &$localized ) {
				$localized[] = array(
					'handle'      => $handle,
					'object_name' => $object_name,
					'l10n'        => $l10n,
				);
			}
		);
		Functions\when( 'admin_url' )->alias(
			static function ( $path = '' ) {
				return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
			}
		);
		// The captcha widget registers here too (#1053), and its localisation
		// reads the site locale to key the widget's i18n store.
		Functions\when( 'get_locale' )->justReturn( 'pt_BR' );
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * The one registration of a handle.
	 *
	 * @param string $handle Handle.
	 * @return array{handle: string, src: string, deps: array<int, string>, ver: mixed, in_footer: mixed}
	 */
	private function registration( string $handle ): array {
		$matches = array_values( array_filter( $this->registered, static fn ( array $r ): bool => $handle === $r['handle'] ) );
		$this->assertCount( 1, $matches, $handle . ' must be registered exactly once.' );

		return $matches[0];
	}

	/**
	 * Localizations attached to a handle.
	 *
	 * @param string $handle Handle.
	 * @return list<array{handle: string, object_name: string, l10n: mixed}>
	 */
	private function localizations( string $handle ): array {
		return array_values( array_filter( $this->localized, static fn ( array $l ): bool => $handle === $l['handle'] ) );
	}

	// ==================================================================
	// init()
	// ==================================================================

	public function test_init_hooks_both_registrations_at_their_priorities(): void {
		$hooks = array();
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback, $priority = 10 ) use ( &$hooks ) {
				$hooks[ $hook ] = array( $callback, $priority );
			}
		);

		AssetRegistry::init();

		$this->assertSame( array( array( AssetRegistry::class, 'register_frontend' ), 10 ), $hooks['wp_enqueue_scripts'] );
		$this->assertSame(
			array( array( AssetRegistry::class, 'register_admin' ), 1 ),
			$hooks['admin_enqueue_scripts'],
			'Priority 1, so the handles resolve before module enqueues run at the default 10.'
		);
	}

	// ==================================================================
	// register_frontend()
	// ==================================================================

	public function test_frontend_registers_ffc_core_with_its_configuration(): void {
		AssetRegistry::register_frontend();

		$core = $this->registration( 'ffc-core' );
		$this->assertStringContainsString( 'ffc-core.min.js', $core['src'] );
		$this->assertSame( array( 'jquery' ), $core['deps'] );
		$this->assertTrue( $core['in_footer'] );

		$config = $this->localizations( 'ffc-core' );
		$this->assertCount( 1, $config );
		$this->assertSame( 'ffcCoreConfig', $config[0]['object_name'] );
		$this->assertSame( array( 'version' => '1.0.0' ), $config[0]['l10n'] );
	}

	public function test_frontend_registers_the_field_behaviours_and_the_helpers_on_them(): void {
		AssetRegistry::register_frontend();

		$behaviours = $this->registration( 'ffc-field-behaviours' );
		$this->assertStringContainsString( 'ffc-field-behaviours.min.js', $behaviours['src'] );
		$this->assertContains( 'ffc-core', $behaviours['deps'] );

		$helpers = $this->registration( 'ffc-rate-limit' );
		$this->assertStringContainsString( 'ffc-frontend-helpers.min.js', $helpers['src'] );
		$this->assertContains( 'jquery', $helpers['deps'] );
		$this->assertContains( 'ffc-core', $helpers['deps'], 'The helpers build their notices with FFC.notice() (#1842).' );
		$this->assertContains( 'ffc-field-behaviours', $helpers['deps'], 'The CPF/RF mask and validators come from the field behaviours (#1920).' );
		$this->assertTrue( $helpers['in_footer'] );
	}

	public function test_frontend_registers_the_captcha_widget(): void {
		// Registered unconditionally here and enqueued by the provider at
		// render time, so the widget reaches every surface that renders it
		// rather than a list of page types that drifts.
		AssetRegistry::register_frontend();

		$this->registration( 'ffc-altcha' );
		$glue = $this->registration( 'ffc-captcha' );

		$this->assertContains( 'ffc-altcha', $glue['deps'], 'The glue writes to the store the bundle creates.' );
		$this->assertContains( 'ffc-core', $glue['deps'], 'The glue listens for the rejection event ffc-core dispatches.' );
	}

	public function test_frontend_registers_and_localizes_dynamic_fragments(): void {
		AssetRegistry::register_frontend();

		$dynamic = $this->registration( 'ffc-dynamic-fragments' );
		$this->assertStringContainsString( 'ffc-dynamic-fragments.min.js', $dynamic['src'] );
		$this->assertSame( array(), $dynamic['deps'], 'ffc-dynamic-fragments has no dependencies.' );
		$this->assertTrue( $dynamic['in_footer'] );

		$l10n = $this->localizations( 'ffc-dynamic-fragments' );
		$this->assertCount( 1, $l10n );
		$this->assertSame( 'ffcDynamic', $l10n[0]['object_name'] );
		$this->assertStringContainsString( 'admin-ajax.php', $l10n[0]['l10n']['ajaxUrl'] );
	}

	// ==================================================================
	// register_admin() — 6.6.9
	// ==================================================================

	public function test_admin_registers_ffc_core_with_its_configuration(): void {
		Functions\when( 'wp_script_is' )->justReturn( false );

		AssetRegistry::register_admin();

		$core = $this->registration( 'ffc-core' );
		$this->assertStringContainsString( 'ffc-core.min.js', $core['src'] );
		$this->assertSame( array( 'jquery' ), $core['deps'] );
		$this->assertTrue( $core['in_footer'] );
		$this->assertCount( 1, $this->localizations( 'ffc-core' ) );
	}

	public function test_admin_is_a_noop_when_ffc_core_is_already_registered(): void {
		// Something registered it first: registering again would print the
		// configuration a second time.
		Functions\when( 'wp_script_is' )->justReturn( true );

		AssetRegistry::register_admin();

		$this->assertSame( array(), $this->registered );
		$this->assertSame( array(), $this->localized );
	}
}

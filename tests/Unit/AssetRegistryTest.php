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

		// WordPress refuses a handle that is already registered and says so by
		// returning false; the double does the same, so a registration that
		// is attempted twice is recorded once, as the real registry holds it.
		Functions\when( 'wp_register_script' )->alias(
			function ( $handle, $src, $deps = array(), $ver = false, $in_footer = false ) use ( &$registered ) {
				foreach ( $registered as $existing ) {
					if ( $handle === $existing['handle'] ) {
						return false;
					}
				}
				$registered[] = array(
					'handle'    => $handle,
					'src'       => $src,
					'deps'      => $deps,
					'ver'       => $ver,
					'in_footer' => $in_footer,
				);
				return true;
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
		AssetRegistry::register_admin();

		$core = $this->registration( 'ffc-core' );
		$this->assertStringContainsString( 'ffc-core.min.js', $core['src'] );
		$this->assertSame( array( 'jquery' ), $core['deps'] );
		$this->assertTrue( $core['in_footer'] );
		$this->assertCount( 1, $this->localizations( 'ffc-core' ) );
	}

	public function test_ffc_core_is_not_localized_again_when_something_registered_it_first(): void {
		// Something registered it first: localizing again would print the
		// configuration a second time.
		$this->registered[] = array(
			'handle'    => 'ffc-core',
			'src'       => 'elsewhere',
			'deps'      => array(),
			'ver'       => false,
			'in_footer' => true,
		);

		AssetRegistry::register_admin();

		$this->assertSame( 'elsewhere', $this->registration( 'ffc-core' )['src'] );
		$this->assertSame( array(), $this->localizations( 'ffc-core' ) );
	}

	public function test_both_hooks_register_the_same_shared_scripts(): void {
		AssetRegistry::register_admin();
		$admin = array_column( $this->registered, 'handle' );

		$this->registered = array();
		$this->localized  = array();
		AssetRegistry::register_frontend();
		$front = array_column( $this->registered, 'handle' );

		$this->assertSame( array(), array_diff( $admin, $front ), 'A script one screen shares must resolve on the other side too.' );
		$this->assertContains( 'ffc-pdf-generator', $admin );
		$this->assertContains( 'ffc-admin-js', $admin );
	}

	// ==================================================================
	// The shared definitions (#1931)
	// ==================================================================

	/**
	 * Every shared script follows SCRIPT_DEBUG, including the ones whose
	 * sites named a literal path: `ffc-qr-raster` and `ffc-url-shortener-admin`
	 * served their readable source in production, and the reregistration
	 * screen served `ffc-pdf-generator.min.js` with SCRIPT_DEBUG on.
	 */
	public function test_every_shared_script_follows_the_suffix(): void {
		AssetRegistry::register_admin();

		foreach ( $this->registered as $script ) {
			if ( in_array( $script['handle'], array( 'html2canvas', 'jspdf' ), true ) ) {
				continue;
			}
			$this->assertStringEndsWith( '.min.js', $script['src'], $script['handle'] );
			$this->assertSame( FFC_VERSION, $script['ver'], $script['handle'] );
			$this->assertTrue( $script['in_footer'], $script['handle'] );
		}
	}

	public function test_the_vendored_bundles_carry_their_version_in_the_file_name(): void {
		AssetRegistry::register_admin();

		$canvas = $this->registration( 'html2canvas' );
		$this->assertStringEndsWith( 'libs/js/html2canvas-pro-' . FFC_HTML2CANVAS_PRO_VERSION . '.min.js', $canvas['src'] );
		$this->assertSame( FFC_HTML2CANVAS_PRO_VERSION, $canvas['ver'] );

		$pdf = $this->registration( 'jspdf' );
		$this->assertStringEndsWith( 'libs/js/jspdf-' . FFC_JSPDF_VERSION . '.umd.min.js', $pdf['src'] );
		$this->assertSame( FFC_JSPDF_VERSION, $pdf['ver'] );
	}

	/**
	 * The dependencies the call sites used to restate, asserted once here.
	 *
	 * @return array<string, array{0: string, 1: list<string>}>
	 */
	public static function dependencies(): array {
		return array(
			'autosave reaches FFC.request and the FFC.Admin namespace' => array( 'ffc-admin-autosave', array( 'jquery', 'ffc-core', 'ffc-admin-js' ) ),
			'the admin script carries the export button driver'       => array( 'ffc-admin-js', array( 'jquery', 'ffc-core', 'ffc-batched-export' ) ),
			'the field behaviours build on FFC'                       => array( 'ffc-field-behaviours', array( 'jquery', 'ffc-core' ) ),
			'the PDF generator needs both bundles and jQuery'         => array( 'ffc-pdf-generator', array( 'jquery', 'html2canvas', 'jspdf' ) ),
			'the calendar core needs nothing of FFC'                  => array( 'ffc-calendar-core', array( 'jquery' ) ),
			'the geofence modules extend the geofence core'           => array( 'ffc-geofence-gps', array( 'jquery', 'ffc-geofence-frontend' ) ),
			'the URL shortener script calls FFC.request and the QR raster' => array( 'ffc-url-shortener-admin', array( 'jquery', 'ffc-core', 'ffc-admin-js', 'ffc-qr-raster' ) ),
		);
	}

	/**
	 * @dataProvider dependencies
	 *
	 * @param string       $handle Handle.
	 * @param list<string> $deps   Expected dependencies.
	 */
	public function test_a_shared_script_declares_its_dependencies( string $handle, array $deps ): void {
		AssetRegistry::register_admin();

		$this->assertSame( $deps, $this->registration( $handle )['deps'] );
	}

	// ==================================================================
	// enqueue_scripts()
	// ==================================================================

	public function test_enqueue_registers_the_handle_and_its_shared_dependencies_first(): void {
		$order = array();
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle ) use ( &$order ) {
				$order[] = 'enqueue:' . $handle;
			}
		);

		AssetRegistry::enqueue_scripts( 'ffc-admin-autosave' );

		$handles = array_column( $this->registered, 'handle' );
		$this->assertSame( array( 'ffc-core', 'ffc-batched-export', 'ffc-admin-js', 'ffc-admin-autosave' ), $handles, 'Nothing it depends on may be unknown when it is enqueued, or WordPress drops it in silence.' );
		$this->assertSame( array( 'enqueue:ffc-admin-autosave' ), $order, 'Only the handle asked for is enqueued; its dependencies follow through WordPress.' );
		$this->assertCount( 1, $this->localizations( 'ffc-core' ) );
	}

	public function test_enqueue_registers_nothing_twice_across_calls(): void {
		Functions\when( 'wp_enqueue_script' )->justReturn( null );

		AssetRegistry::enqueue_scripts( 'ffc-core', 'ffc-field-behaviours' );
		AssetRegistry::enqueue_scripts( 'ffc-rate-limit', 'ffc-pdf-generator' );

		$handles = array_column( $this->registered, 'handle' );
		$this->assertSame( $handles, array_values( array_unique( $handles ) ) );
		$this->assertCount( 1, $this->localizations( 'ffc-core' ), 'ffcCoreConfig is attached once, by the registration that succeeded.' );
	}

	public function test_enqueue_passes_a_handle_it_does_not_know_through_unregistered(): void {
		$enqueued = array();
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle ) use ( &$enqueued ) {
				$enqueued[] = $handle;
			}
		);

		AssetRegistry::enqueue_scripts( 'wp-color-picker' );

		$this->assertSame( array(), $this->registered );
		$this->assertSame( array( 'wp-color-picker' ), $enqueued );
	}
}

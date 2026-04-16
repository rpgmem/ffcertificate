<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Loader;

/**
 * Tests for Loader: hook registration and frontend asset registration.
 *
 * Only the constructor wiring and register_frontend_assets() are tested here.
 * The init_plugin() method orchestrates dozens of concrete classes and is
 * intentionally excluded — it would require mocking the entire plugin graph.
 *
 * @covers \FreeFormCertificate\Loader
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class LoaderTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var Mockery\MockInterface */
    private $utils_mock;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Helper: stub the WP functions called by the constructor so it can
     * be instantiated without side-effect failures.
     */
    private function stub_constructor_functions(): void {
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'register_activation_hook' )->justReturn( true );
        Functions\when( 'register_deactivation_hook' )->justReturn( true );
    }

    // ==================================================================
    // Constructor — hook registration
    // ==================================================================

    public function test_constructor_registers_plugins_loaded_hook(): void {
        $actions_added = [];
        Functions\when( 'add_action' )->alias( function ( $hook, $callback, $priority = 10 ) use ( &$actions_added ) {
            $actions_added[] = [
                'hook'     => $hook,
                'callback' => $callback,
                'priority' => $priority,
            ];
        } );
        Functions\when( 'register_activation_hook' )->justReturn( true );
        Functions\when( 'register_deactivation_hook' )->justReturn( true );

        $loader = new Loader();

        $plugins_loaded = array_filter( $actions_added, function ( $entry ) {
            return $entry['hook'] === 'plugins_loaded';
        } );

        $this->assertNotEmpty( $plugins_loaded, 'Constructor should register a plugins_loaded action' );
        $match = reset( $plugins_loaded );
        $this->assertSame( 10, $match['priority'], 'plugins_loaded priority should be 10' );
        $this->assertIsArray( $match['callback'] );
        $this->assertSame( $loader, $match['callback'][0] );
        $this->assertSame( 'init_plugin', $match['callback'][1] );
    }

    public function test_constructor_registers_enqueue_scripts_hook(): void {
        $actions_added = [];
        Functions\when( 'add_action' )->alias( function ( $hook, $callback, $priority = 10 ) use ( &$actions_added ) {
            $actions_added[] = [
                'hook'     => $hook,
                'callback' => $callback,
            ];
        } );
        Functions\when( 'register_activation_hook' )->justReturn( true );
        Functions\when( 'register_deactivation_hook' )->justReturn( true );

        $loader = new Loader();

        $enqueue = array_filter( $actions_added, function ( $entry ) {
            return $entry['hook'] === 'wp_enqueue_scripts';
        } );

        $this->assertNotEmpty( $enqueue, 'Constructor should register a wp_enqueue_scripts action' );
        $match = reset( $enqueue );
        $this->assertIsArray( $match['callback'] );
        $this->assertSame( $loader, $match['callback'][0] );
        $this->assertSame( 'register_frontend_assets', $match['callback'][1] );
    }

    public function test_constructor_registers_activation_hooks(): void {
        Functions\when( 'add_action' )->justReturn( true );

        $activation_called   = false;
        $deactivation_called = false;

        Functions\when( 'register_activation_hook' )->alias( function ( $file, $callback ) use ( &$activation_called ) {
            $activation_called = true;
            $this->assertStringContainsString( 'ffcertificate.php', $file );
            $this->assertSame( '\\FreeFormCertificate\Activator', $callback[0] );
            $this->assertSame( 'activate', $callback[1] );
        } );

        Functions\when( 'register_deactivation_hook' )->alias( function ( $file, $callback ) use ( &$deactivation_called ) {
            $deactivation_called = true;
            $this->assertStringContainsString( 'ffcertificate.php', $file );
            $this->assertSame( '\\FreeFormCertificate\Deactivator', $callback[0] );
            $this->assertSame( 'deactivate', $callback[1] );
        } );

        new Loader();

        $this->assertTrue( $activation_called, 'Constructor should call register_activation_hook' );
        $this->assertTrue( $deactivation_called, 'Constructor should call register_deactivation_hook' );
    }

    // ==================================================================
    // register_frontend_assets()
    // ==================================================================

    /**
     * Helper: create a Loader instance with constructor stubs, then set up
     * mocks for the WP asset functions used by register_frontend_assets().
     *
     * @return array{loader: Loader, registered_scripts: array, localized_scripts: array}
     */
    private function build_loader_and_asset_spies(): array {
        $this->stub_constructor_functions();
        $loader = new Loader();

        // Mock Utils::asset_suffix() — alias mock works because
        // @runTestsInSeparateProcesses gives us a fresh process.
        $this->utils_mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\Utils' );
        $this->utils_mock->shouldReceive( 'asset_suffix' )->andReturn( '.min' );

        $registered_scripts = [];
        $localized_scripts  = [];

        Functions\when( 'wp_register_script' )->alias(
            function ( $handle, $src, $deps = [], $ver = false, $in_footer = false ) use ( &$registered_scripts ) {
                $registered_scripts[] = [
                    'handle'    => $handle,
                    'src'       => $src,
                    'deps'      => $deps,
                    'ver'       => $ver,
                    'in_footer' => $in_footer,
                ];
            }
        );

        Functions\when( 'wp_localize_script' )->alias(
            function ( $handle, $object_name, $l10n ) use ( &$localized_scripts ) {
                $localized_scripts[] = [
                    'handle'      => $handle,
                    'object_name' => $object_name,
                    'l10n'        => $l10n,
                ];
            }
        );

        Functions\when( 'admin_url' )->alias( function ( $path = '' ) {
            return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
        } );

        return [
            'loader'              => $loader,
            'registered_scripts'  => &$registered_scripts,
            'localized_scripts'   => &$localized_scripts,
        ];
    }

    public function test_register_frontend_assets_registers_rate_limit_script(): void {
        $spies = $this->build_loader_and_asset_spies();
        $spies['loader']->register_frontend_assets();

        $rate_limit = array_filter( $spies['registered_scripts'], function ( $entry ) {
            return $entry['handle'] === 'ffc-rate-limit';
        } );

        $this->assertNotEmpty( $rate_limit, 'Should register the ffc-rate-limit script' );
        $match = reset( $rate_limit );
        $this->assertStringContainsString( 'ffc-frontend-helpers.min.js', $match['src'] );
        $this->assertContains( 'jquery', $match['deps'] );
        $this->assertTrue( $match['in_footer'], 'ffc-rate-limit should be loaded in footer' );
    }

    public function test_register_frontend_assets_registers_dynamic_fragments_script(): void {
        $spies = $this->build_loader_and_asset_spies();
        $spies['loader']->register_frontend_assets();

        $dynamic = array_filter( $spies['registered_scripts'], function ( $entry ) {
            return $entry['handle'] === 'ffc-dynamic-fragments';
        } );

        $this->assertNotEmpty( $dynamic, 'Should register the ffc-dynamic-fragments script' );
        $match = reset( $dynamic );
        $this->assertStringContainsString( 'ffc-dynamic-fragments.min.js', $match['src'] );
        $this->assertSame( [], $match['deps'], 'ffc-dynamic-fragments should have no dependencies' );
        $this->assertTrue( $match['in_footer'], 'ffc-dynamic-fragments should be loaded in footer' );
    }

    public function test_register_frontend_assets_localizes_dynamic_fragments(): void {
        $spies = $this->build_loader_and_asset_spies();
        $spies['loader']->register_frontend_assets();

        $localized = array_filter( $spies['localized_scripts'], function ( $entry ) {
            return $entry['handle'] === 'ffc-dynamic-fragments';
        } );

        $this->assertNotEmpty( $localized, 'Should localize the ffc-dynamic-fragments script' );
        $match = reset( $localized );
        $this->assertSame( 'ffcDynamic', $match['object_name'] );
        $this->assertArrayHasKey( 'ajaxUrl', $match['l10n'] );
        $this->assertStringContainsString( 'admin-ajax.php', $match['l10n']['ajaxUrl'] );
    }
}

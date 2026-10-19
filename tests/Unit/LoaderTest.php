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
 * Tests for Loader: hook registration.
 *
 * Only the constructor wiring is tested here; the asset registration it wires
 * is AssetRegistryTest's.
 * The init_plugin() method orchestrates dozens of concrete classes and is
 * intentionally excluded — it would require mocking the entire plugin graph.
 *
 * @covers \FreeFormCertificate\Loader
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class LoaderTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
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

		new Loader();

		$enqueue = array_filter( $actions_added, function ( $entry ) {
			return $entry['hook'] === 'wp_enqueue_scripts';
		} );

		// The registration itself lives in Core\AssetRegistry since #1924; the
		// constructor is what wires it.
		$this->assertNotEmpty( $enqueue, 'Constructor should register a wp_enqueue_scripts action' );
		$match = reset( $enqueue );
		$this->assertSame( array( \FreeFormCertificate\Core\AssetRegistry::class, 'register_frontend' ), $match['callback'] );
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
	// Admin registration — 6.6.9, wired through Core\\AssetRegistry (#1924)
	// ==================================================================

	public function test_constructor_registers_admin_enqueue_scripts_hook(): void {
		$actions_added = [];
		Functions\when( 'add_action' )->alias( function ( $hook, $callback, $priority = 10 ) use ( &$actions_added ) {
			$actions_added[] = [ 'hook' => $hook, 'callback' => $callback, 'priority' => $priority ];
		} );
		Functions\when( 'register_activation_hook' )->justReturn( true );
		Functions\when( 'register_deactivation_hook' )->justReturn( true );

		new Loader();

		$admin_hooks = array_filter( $actions_added, function ( $entry ) {
			return $entry['hook'] === 'admin_enqueue_scripts'
				&& array( \FreeFormCertificate\Core\AssetRegistry::class, 'register_admin' ) === $entry['callback'];
		} );

		$this->assertNotEmpty( $admin_hooks, '6.6.9 fix: ffc-core must register on every admin request.' );
		$match = reset( $admin_hooks );
		$this->assertSame( 1, $match['priority'], 'Priority 1 so dep is resolvable before module enqueues run at default priority 10.' );
	}
}

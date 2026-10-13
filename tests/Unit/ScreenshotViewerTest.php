<?php

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use FreeFormCertificate\Integrations\ScreenshotViewer;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The screenshot viewer loads on this plugin's "View details" modal and
 * nowhere else, with its labels.
 *
 * @covers \FreeFormCertificate\Integrations\ScreenshotViewer
 */
final class ScreenshotViewerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, string> Handle => URL, for scripts and styles enqueued. */
	private array $enqueued = array();

	/** @var array<string, mixed> Localized object name => data. */
	private array $localized = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->enqueued  = array();
		$this->localized = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( static fn ( $key ) => strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) ) );
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle, $src ) {
				$this->enqueued[ 'js:' . $handle ] = $src;
			}
		);
		Functions\when( 'wp_enqueue_style' )->alias(
			function ( $handle, $src ) {
				$this->enqueued[ 'css:' . $handle ] = $src;
			}
		);
		Functions\when( 'wp_localize_script' )->alias(
			function ( $handle, $name, $data ) {
				$this->localized[ $name ] = $data;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		$_GET = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_it_loads_on_this_plugins_modal_with_its_labels(): void {
		$_GET = array(
			'tab'    => 'plugin-information',
			'plugin' => 'ffcertificate',
		);

		ScreenshotViewer::enqueue( 'plugin-install.php' );

		$this->assertSame( array( 'css:ffc-screenshots', 'js:ffc-screenshots' ), array_keys( $this->enqueued ) );
		$this->assertStringEndsWith( 'assets/js/ffc-screenshots.min.js', $this->enqueued['js:ffc-screenshots'] );
		$this->assertSame( '%1$s of %2$s', $this->localized['ffcScreenshots']['counter'] );
		$this->assertSame( array( 'dialog', 'close', 'previous', 'next', 'zoomIn', 'zoomOut', 'counter' ), array_keys( $this->localized['ffcScreenshots'] ) );
	}

	public function test_another_plugins_modal_does_not_get_it(): void {
		$_GET = array(
			'tab'    => 'plugin-information',
			'plugin' => 'akismet',
		);

		ScreenshotViewer::enqueue( 'plugin-install.php' );

		$this->assertSame( array(), $this->enqueued );
	}

	public function test_the_plugin_installer_itself_does_not_get_it(): void {
		$_GET = array( 'tab' => 'search' );

		ScreenshotViewer::enqueue( 'plugin-install.php' );

		$this->assertSame( array(), $this->enqueued );
	}

	public function test_another_screen_with_the_same_query_does_not_get_it(): void {
		$_GET = array(
			'tab'    => 'plugin-information',
			'plugin' => 'ffcertificate',
		);

		ScreenshotViewer::enqueue( 'plugins.php' );

		$this->assertSame( array(), $this->enqueued );
	}

	public function test_init_hooks_the_enqueue(): void {
		Functions\expect( 'add_action' )->once()->with( 'admin_enqueue_scripts', array( ScreenshotViewer::class, 'enqueue' ) );

		ScreenshotViewer::init();
	}
}

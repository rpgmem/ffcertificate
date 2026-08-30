<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Settings\Tabs\TabEmailModel;

/**
 * Tests for TabEmailModel: the shared email-chrome editor tab (split out of the
 * SMTP tab in #976).
 *
 * @covers \FreeFormCertificate\Settings\Tabs\TabEmailModel
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class TabEmailModelTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var TabEmailModel */
	private $tab;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		// SettingsTab's constructor defensively require()s wp-includes/formatting.php
		// when wp_kses_post is undefined; stubbing it keeps the base ctor a no-op.
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );
		$this->tab = new TabEmailModel();
	}

	protected function tearDown(): void {
		unset( $_GET['tab'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_tab_id_is_email_model(): void {
		$this->assertSame( 'email_model', $this->tab->get_id() );
	}

	public function test_tab_title(): void {
		$this->assertSame( 'Email Model', $this->tab->get_title() );
	}

	public function test_extends_settings_tab(): void {
		$this->assertInstanceOf( \FreeFormCertificate\Settings\SettingsTab::class, $this->tab );
	}

	public function test_enqueue_scripts_returns_early_for_wrong_hook(): void {
		Functions\expect( 'wp_enqueue_script' )->never();
		Functions\expect( 'wp_enqueue_media' )->never();
		$this->tab->enqueue_scripts( 'edit.php' );
	}

	public function test_enqueue_scripts_enqueues_model_assets_when_active(): void {
		$_GET['tab'] = 'email_model';
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( fn( $k ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $k ) ) );

		$utils_mock = Mockery::mock( 'alias:FreeFormCertificate\Core\AssetHelper' );
		$utils_mock->shouldReceive( 'asset_suffix' )->once()->andReturn( '.min' );

		Functions\expect( 'wp_enqueue_media' )->once();
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'get_bloginfo' )->justReturn( 'Site' );
		Functions\when( 'wp_localize_script' )->justReturn( null );

		$opts = Mockery::mock( 'alias:FreeFormCertificate\Core\EmailTemplateOptions' );
		$opts->shouldReceive( 'defaults' )->andReturn( array() );
		$opts->shouldReceive( 'font_stacks' )->andReturn( array() );
		$opts->shouldReceive( 'footer_tokens' )->andReturn( array() );

		Functions\expect( 'wp_enqueue_script' )
			->with( 'ffc-email-model', Mockery::type( 'string' ), array( 'jquery', 'wp-color-picker' ), Mockery::type( 'string' ), true )
			->once();

		$this->tab->enqueue_scripts( 'toplevel_page_ffc-settings' );
	}
}

<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use FreeFormCertificate\Settings\SettingsTab;
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
		SettingsTab::set_resolved_active_tab( null );
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


	public function test_enqueue_scripts_returns_early_for_wrong_hook(): void {
		Functions\expect( 'wp_enqueue_script' )->never();
		Functions\expect( 'wp_enqueue_media' )->never();
		$this->tab->enqueue_scripts( 'edit.php' );
	}

	/**
	 * @runInSeparateProcess
	 */
	public function test_enqueue_scripts_enqueues_model_assets_when_active(): void {
		SettingsTab::set_resolved_active_tab( 'email_model' );
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

	/**
	 * The test-send card leaves the read-only lock (#1860): it renders for a
	 * user holding only `ffc_test_settings_smtp`, and for nobody without it
	 * or the SMTP manage cap.
	 *
	 * @param array<int, string> $caps   Capabilities the user holds.
	 * @param bool               $prints Whether the card renders.
	 *
	 * @dataProvider unlocked_card_cases
	 * @runInSeparateProcess
	 */
	public function test_the_test_card_renders_for_the_caps_that_may_send( array $caps, bool $prints ): void {
		Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
			->shouldReceive( 'current_user_can_admin_or' )
			->andReturnUsing( static fn( string $cap ): bool => in_array( $cap, $caps, true ) );
		Functions\when( 'wp_get_current_user' )->justReturn( (object) array( 'user_email' => 'op@example.org' ) );
		Functions\when( 'esc_html_e' )->alias(
			static function ( string $text ): void {
				echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test stub.
			}
		);
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'esc_attr' )->returnArg();

		ob_start();
		$this->tab->render_unlocked();
		$html = (string) ob_get_clean();

		if ( $prints ) {
			$this->assertStringContainsString( 'name="ffc_send_test_email"', $html );
			$this->assertStringContainsString( 'op@example.org', $html );
			// The send is the card's primary action, enabled for an account with an address (#1874).
			$this->assertStringContainsString( '<button type="submit" class="ffc-btn ffc-btn-primary ffc-icon-send" id="ffc_send_test_email_submit" name="ffc_send_test_email_submit">Send Test Email</button>', $html );
		} else {
			$this->assertSame( '', trim( $html ) );
		}
	}

	/**
	 * @return array<string, array{0: array<int, string>, 1: bool}>
	 */
	public static function unlocked_card_cases(): array {
		return array(
			'test cap alone'  => array( array( 'ffc_test_settings_smtp' ), true ),
			'smtp manage cap' => array( array( 'ffc_manage_settings_smtp' ), true ),
			'settings view'   => array( array( 'ffc_view_settings' ), false ),
		);
	}
}

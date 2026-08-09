<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\AbstractDismissibleNotice;

/**
 * Direct unit coverage for the shared AbstractDismissibleNotice plumbing (#849).
 *
 * The three concrete notices (EncryptionKeyHealthNotice,
 * DeviceThresholdUpgradeNotice, HtmlRefsNotice) each carry a covers annotation
 * scoped to their own subclass, so pcov attributes none of the inherited base's
 * lines to them — the abstract reads as 0% despite being fully exercised through
 * those subclasses. This dedicated test drives the base directly through a minimal
 * in-test stub that leaves notice_type()/extra_class() at their base defaults
 * (every concrete notice overrides extra_class(), so its default has no other
 * caller), recording the plumbing coverage against the abstract itself (the
 * #563 pcov attribution fix + #912 precedent).
 *
 * @covers \FreeFormCertificate\Admin\AbstractDismissibleNotice
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AbstractDismissibleNoticeTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// pcov attribution: preload the class under test before any test method
		// autoloads it (via the stub subclass below).
		class_exists( '\FreeFormCertificate\Admin\AbstractDismissibleNotice' );

		StubDismissibleNotice::reset();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'get_option' )->justReturn( '' );
		// wp_admin_notice() is WP 6.4 core — reproduce enough of its markup for
		// the render assertions (class list + message).
		Functions\when( 'wp_admin_notice' )->alias(
			static function ( $message, $args ) {
				$classes = 'notice notice-' . ( $args['type'] ?? 'info' )
					. ( ! empty( $args['dismissible'] ) ? ' is-dismissible' : '' )
					. ' ' . implode( ' ', $args['additional_classes'] ?? array() );
				echo '<div class="' . $classes . '">' . $message . '</div>';
			}
		);

		if ( ! defined( 'FFC_PLUGIN_URL' ) ) {
			define( 'FFC_PLUGIN_URL', 'https://example.test/wp-content/plugins/ffcertificate/' );
		}
		if ( ! defined( 'FFC_VERSION' ) ) {
			define( 'FFC_VERSION', '0.0.0-test' );
		}

		Mockery::mock( 'alias:FreeFormCertificate\Core\AssetHelper' )
			->shouldReceive( 'asset_suffix' )->andReturn( '.min' )->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function rendered_output(): string {
		ob_start();
		StubDismissibleNotice::maybe_render();
		return (string) ob_get_clean();
	}

	// ---- init() ------------------------------------------------------------

	public function test_init_registers_admin_notice_and_ajax_hooks(): void {
		$hooks = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook ) use ( &$hooks ) {
				$hooks[] = $hook;
				return true;
			}
		);

		StubDismissibleNotice::init();

		$this->assertContains( 'admin_notices', $hooks );
		$this->assertContains( 'wp_ajax_ffc_stub_notice', $hooks );
	}

	// ---- maybe_render() gates ---------------------------------------------

	public function test_renders_wrapper_with_base_default_type_and_class(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$output = $this->rendered_output();

		// Base defaults: notice_type() -> 'info', extra_class() -> '' (filtered out).
		$this->assertStringContainsString( 'notice-info', $output );
		$this->assertStringContainsString( 'ffc-js-dismiss-notice', $output );
		$this->assertStringContainsString( 'is-dismissible', $output );
		$this->assertStringContainsString( 'stub notice body', $output );
	}

	public function test_does_not_render_when_user_cannot_manage(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
			->shouldReceive( 'current_user_can_admin_or' )->andReturn( false );

		$this->assertSame( '', $this->rendered_output() );
	}

	public function test_does_not_render_when_should_show_false(): void {
		StubDismissibleNotice::$show = false;
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->assertSame( '', $this->rendered_output() );
	}

	public function test_does_not_render_when_dismissed_signature_matches(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( '1' ); // equals dismiss_signature()

		$this->assertSame( '', $this->rendered_output() );
	}

	public function test_renders_when_stored_option_is_non_scalar(): void {
		// A non-scalar stored value coerces to '' — the current signature '1'
		// then differs, so the notice renders (covers the is_scalar() false arm).
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array( 'unexpected' ) );

		$this->assertStringContainsString( 'stub notice body', $this->rendered_output() );
	}

	// ---- user_can_manage() branches ---------------------------------------

	public function test_renders_via_ffc_capability_when_not_admin(): void {
		// Not a WP admin, but holds the FFC settings cap → gate passes.
		Functions\when( 'current_user_can' )->justReturn( false );
		Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
			->shouldReceive( 'current_user_can_admin_or' )->andReturn( true );

		$this->assertStringContainsString( 'stub notice body', $this->rendered_output() );
	}

	// ---- ajax_dismiss() ----------------------------------------------------

	public function test_ajax_dismiss_stores_signature_and_succeeds(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );

		$captured = array();
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$captured ) {
				$captured[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_send_json_success' )->alias(
			static function () {
				throw new \RuntimeException( 'json_success' );
			}
		);

		try {
			StubDismissibleNotice::ajax_dismiss();
			$this->fail( 'expected halt' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'json_success', $e->getMessage() );
			$this->assertSame( '1', $captured['ffc_stub_notice_dismissed'] ?? null );
		}
	}

	public function test_ajax_dismiss_forbidden_when_cannot_manage(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );
		Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
			->shouldReceive( 'current_user_can_admin_or' )->andReturn( false );

		$code = null;
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $payload, $status ) use ( &$code ) {
				$code = $status;
				throw new \RuntimeException( 'json_error' );
			}
		);

		try {
			StubDismissibleNotice::ajax_dismiss();
			$this->fail( 'expected halt' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 403, $code );
		}
	}
}

/**
 * Minimal concrete notice used only to drive the abstract base's shared
 * plumbing. Deliberately leaves notice_type() and extra_class() unoverridden
 * so the base defaults are exercised.
 */
// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- test fixture kept next to its only user.
class StubDismissibleNotice extends AbstractDismissibleNotice {

	/** @var bool */
	public static $show = true;

	public static function reset(): void {
		self::$show = true;
	}

	protected static function option_key(): string {
		return 'ffc_stub_notice_dismissed';
	}

	protected static function action(): string {
		return 'ffc_stub_notice';
	}

	protected static function should_show(): bool {
		return self::$show;
	}

	protected static function dismiss_signature(): string {
		return '1';
	}

	protected static function notice_message(): string {
		return '<p>stub notice body</p>';
	}
}

<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\HtmlRefsNotice;

/**
 * Gating + detection tests for the #865 Phase 0 legacy-html/ references notice.
 *
 * Separate processes so the AssetHelper alias mock + the $wpdb global can't
 * leak into other tests.
 *
 * @covers \FreeFormCertificate\Admin\HtmlRefsNotice
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class HtmlRefsNoticeTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce' );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		Functions\when( 'set_transient' )->justReturn( true );
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
		if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
			define( 'HOUR_IN_SECONDS', 3600 );
		}

		Mockery::mock( 'alias:FreeFormCertificate\Core\AssetHelper' )
			->shouldReceive( 'asset_suffix' )->andReturn( '.min' )->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Install a $wpdb whose existence probe returns $found.
	 *
	 * @param string|null $found get_var() return.
	 */
	private function mock_wpdb( ?string $found ): void {
		$wpdb           = Mockery::mock( 'wpdb' );
		$wpdb->postmeta = 'wp_postmeta';
		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $v ) => $v );
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $q ) => $q );
		$wpdb->shouldReceive( 'get_var' )->andReturn( $found );
		$GLOBALS['wpdb'] = $wpdb;
	}

	private function rendered_output(): string {
		ob_start();
		HtmlRefsNotice::maybe_render();
		return (string) ob_get_clean();
	}

	public function test_does_not_render_when_cached_absent(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( '0' ); // cached: no refs
		Functions\when( 'get_option' )->justReturn( '' );

		$this->assertSame( '', $this->rendered_output() );
	}

	public function test_renders_when_cached_present_and_not_dismissed(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( '1' ); // cached: refs present
		Functions\when( 'get_option' )->justReturn( '' );

		$output = $this->rendered_output();

		$this->assertStringContainsString( 'ffc-html-refs-notice', $output );
		$this->assertStringContainsString( 'ffc-js-dismiss-notice', $output );
		$this->assertStringContainsString( 'is-dismissible', $output );
		$this->assertStringContainsString( 'notice-warning', $output );
	}

	public function test_scans_db_when_uncached_and_renders_on_hit(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false ); // uncached → scan
		Functions\when( 'get_option' )->justReturn( '' );
		$this->mock_wpdb( '42' ); // a matching meta row exists

		$this->assertStringContainsString( 'ffc-html-refs-notice', $this->rendered_output() );
	}

	public function test_does_not_render_when_uncached_and_db_clean(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( '' );
		$this->mock_wpdb( null ); // no matching row

		$this->assertSame( '', $this->rendered_output() );
	}

	public function test_does_not_render_when_dismissed(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( '1' );
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				return HtmlRefsNotice::OPTION_DISMISSED === $key ? '1' : $default;
			}
		);

		$this->assertSame( '', $this->rendered_output() );
	}

	public function test_does_not_render_when_cannot_manage(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' )
			->shouldReceive( 'current_user_can_admin_or' )->andReturn( false );

		$this->assertSame( '', $this->rendered_output() );
	}

	public function test_ajax_dismiss_stores_one_shot_signature(): void {
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
			HtmlRefsNotice::ajax_dismiss();
			$this->fail( 'expected halt' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( '1', $captured[ HtmlRefsNotice::OPTION_DISMISSED ] ?? null );
		}
	}

	public function test_init_registers_admin_notice_and_ajax_hooks(): void {
		$hooks = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook ) use ( &$hooks ) {
				$hooks[] = $hook;
				return true;
			}
		);

		HtmlRefsNotice::init();

		$this->assertContains( 'admin_notices', $hooks );
		$this->assertContains( 'wp_ajax_' . HtmlRefsNotice::AJAX_ACTION, $hooks );
	}

	// ==================================================================
	// The text follows whether the repair exists (#1438)
	// ==================================================================

	/**
	 * With the drop-folder gone, the notice must not send anybody to the card.
	 *
	 * The card is hidden on every install since 6.23.0 -- the migration reads the
	 * folder it side-loads from -- so the old wording pointed at a control that
	 * is not on the screen. It is also the wrong tense: the files are not "going
	 * to be deleted", they are gone, and the only repair is a person re-uploading
	 * them.
	 */
	public function test_the_message_states_the_loss_and_offers_no_card_when_the_folder_is_gone(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( '1' );
		Functions\when( 'get_option' )->justReturn( '' );

		$output = $this->rendered_output();

		$this->assertStringContainsString( 'no longer exists', $output );
		$this->assertStringContainsString( 'Media Library', $output );
		$this->assertStringNotContainsString(
			'tab=migrations',
			$output,
			'The Migrations card is hidden while the folder is absent, so linking to it sends the reader nowhere.'
		);
		$this->assertStringNotContainsString(
			'will delete',
			$output,
			'The prospective wording belongs to the branch where the files are still on disk.'
		);
	}

	/**
	 * And with the folder present it still points at the card, because then the
	 * card is there and the images are still recoverable automatically.
	 *
	 * Driven through the probe's own injectable argument rather than by creating
	 * a directory in the plugin root: `FFC_PLUGIN_DIR` is a constant, so the
	 * branch is reachable in a test only by asking the probe what it would say.
	 */
	public function test_the_probe_is_what_selects_the_branch(): void {
		$root = sys_get_temp_dir() . '/ffc-notice-' . bin2hex( random_bytes( 6 ) );
		mkdir( $root . '/html', 0777, true );

		try {
			$this->assertTrue(
				\FreeFormCertificate\Core\LegacyHtmlRefs::drop_folder_exists( $root ),
				'The present branch is selected by this answer, so the notice and the card cannot disagree.'
			);
			$this->assertFalse(
				\FreeFormCertificate\Core\LegacyHtmlRefs::drop_folder_exists(),
				'And on this tree the answer is the one the rendered message above is asserted against.'
			);
		} finally {
			rmdir( $root . '/html' );
			rmdir( $root );
		}
	}
}

<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentPublicShortcode;

/**
 * Tests for RecruitmentPublicShortcode — pins the §8 status branching
 * (draft = error, preliminary = warning-only, active/closed = listing
 * with optional banner), the missing-attribute / unknown-notice / unknown
 * adjutancy error states, and the cache + rate-limit infrastructure
 * surfaces (without exercising every render permutation — full HTML
 * snapshots land in sprint 13).
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentPublicShortcode
 */
class RecruitmentPublicShortcodeTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$this->wpdb   = $wpdb;

		Functions\when( 'wp_enqueue_style' )->justReturn( true );
		// O bloco de cores dos selos é anexado ao handle da folha (#1193).
		Functions\when( 'wp_add_inline_style' )->justReturn( true );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'current_time' )->justReturn( '2026-05-01 10:00:00' );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'shortcode_atts' )->alias(
			static function ( $defaults, $atts ) {
				return array_merge( $defaults, is_array( $atts ) ? $atts : array() );
			}
		);

		$this->wpdb->shouldReceive( 'prepare' )
			->andReturnUsing( static fn ( $sql ) => $sql )
			->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function notice_stub( string $status, string $code = 'EDITAL-2026-01' ): object {
		return (object) array(
			'id'                    => '5',
			'code'                  => $code,
			'name'                  => 'Test',
			'status'                => $status,
			'opened_at'             => null,
			'closed_at'             => null,
			'was_reopened'          => '0',
			'public_columns_config' => '{"rank":true,"name":true,"status":true,"pcd_badge":false,"date_to_assume":true,"score":false,"cpf_masked":false,"rf_masked":false,"email_masked":false}',
			'created_at'            => '2026-05-01 10:00:00',
			'updated_at'            => '2026-05-01 10:00:00',
		);
	}

	public function test_render_returns_error_when_notice_attribute_missing(): void {
		$html = RecruitmentPublicShortcode::render( array() );
		$this->assertStringContainsString( 'Notice attribute is required.', $html );
	}

	public function test_render_uncached_returns_error_when_notice_unknown(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$html = RecruitmentPublicShortcode::render_uncached( 'EDITAL-FAKE', '', 1, 1 );

		$this->assertStringContainsString( 'Notice not found.', $html );
	}

	public function test_render_uncached_blocks_draft_notices(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->notice_stub( 'draft' ) );

		$html = RecruitmentPublicShortcode::render_uncached( 'EDITAL-2026-01', '', 1, 1 );

		$this->assertStringContainsString( 'still being prepared', $html );
	}

	public function test_render_uncached_renders_listing_with_warning_banner_for_preliminary(): void {
		// Preliminary now renders the preview list with a "subject to
		// change" banner at the top (was warning-only pre-6.1.0).
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->notice_stub( 'preliminary' ) );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );

		$html = RecruitmentPublicShortcode::render_uncached( 'EDITAL-2026-01', '', 1, 1 );

		$this->assertStringContainsString( 'Preliminary list', $html );
		$this->assertStringContainsString( 'EDITAL-2026-01', $html );
	}

	public function test_render_uncached_renders_empty_state_for_active_with_no_rows(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->notice_stub( 'definitive' ) );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );

		$html = RecruitmentPublicShortcode::render_uncached( 'EDITAL-2026-01', '', 1, 1 );

		$this->assertStringContainsString( 'No candidates classified yet.', $html );
		// Notice header still rendered (so the user sees they're at the
		// right edital).
		$this->assertStringContainsString( 'EDITAL-2026-01', $html );
	}

	public function test_render_uncached_includes_closed_banner(): void {
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( $this->notice_stub( 'closed' ) );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );

		$html = RecruitmentPublicShortcode::render_uncached( 'EDITAL-2026-01', '', 1, 1 );

		$this->assertStringContainsString( 'Notice closed.', $html );
	}

	public function test_render_uncached_rejects_unknown_adjutancy_slug(): void {
		$this->wpdb->shouldReceive( 'get_row' )
			->twice()
			->andReturn(
				$this->notice_stub( 'definitive' ),
				null // adjutancy lookup
			);

		$html = RecruitmentPublicShortcode::render_uncached( 'EDITAL-2026-01', 'inexistente', 1, 1 );

		$this->assertStringContainsString( 'Adjutancy not found for this notice.', $html );
	}

	public function test_render_uses_cached_html_when_available(): void {
		Functions\when( 'get_transient' )->justReturn( '<div>cached</div>' );

		// wpdb should never be hit when cache is warm.
		$this->wpdb->shouldNotReceive( 'get_row' );

		$html = RecruitmentPublicShortcode::render( array( 'notice' => 'EDITAL-2026-01' ) );

		$this->assertSame( '<div>cached</div>', $html );
	}

	public function test_render_skips_rate_limit_when_setting_is_zero(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'public_rate_limit_per_minute' => 0,
				'public_cache_seconds'         => 0,
			)
		);
		// Notice unknown so the call short-circuits after rate-limit
		// passes — proves the rate-limit didn't block.
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		$html = RecruitmentPublicShortcode::render( array( 'notice' => 'EDITAL-2026-01' ) );

		$this->assertStringContainsString( 'Notice not found.', $html );
	}

	public function test_render_returns_throttle_message_when_rate_limit_exceeded(): void {
		// Configure rate limit = 5; preload the bucket to 5 already so
		// the next request trips it.
		Functions\when( 'get_option' )->justReturn(
			array(
				'public_rate_limit_per_minute' => 5,
				'public_cache_seconds'         => 0,
			)
		);

		$server_backup           = $_SERVER;
		$_SERVER['REMOTE_ADDR']  = '203.0.113.7';
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) {
				if ( false !== strpos( $key, 'ffc_recruitment_public_rate_' ) ) {
					return 999; // Way past the limit.
				}
				return false;
			}
		);

		$html = RecruitmentPublicShortcode::render( array( 'notice' => 'EDITAL-2026-01' ) );

		$this->assertStringContainsString( 'Too many requests', $html );

		$_SERVER = $server_backup;
	}

	public function test_render_throttles_unidentifiable_caller_fail_closed(): void {
		// #927 D3: with no identifiable client IP, the resolver returns the
		// '0.0.0.0' sentinel — a real bucket — so an unidentifiable caller is
		// now throttled (fail-closed) instead of waved through (former fail-open).
		Functions\when( 'get_option' )->justReturn(
			array(
				'public_rate_limit_per_minute' => 5,
				'public_cache_seconds'         => 0,
			)
		);

		$server_backup = $_SERVER;
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'] );
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) {
				return false !== strpos( (string) $key, 'ffc_recruitment_public_rate_' ) ? 999 : false;
			}
		);

		$html = RecruitmentPublicShortcode::render( array( 'notice' => 'EDITAL-2026-01' ) );

		$this->assertStringContainsString( 'Too many requests', $html );

		$_SERVER = $server_backup;
	}
	// ==================================================================
	// Enfileiramento adiantado (#1193)
	// ==================================================================

	/**
	 * The hook itself — without it the method above is unreachable and the
	 * `<link>` goes back to the footer, with nothing red to say so.
	 */
	public function test_register_hooks_the_early_enqueue(): void {
		Functions\when( 'add_shortcode' )->justReturn( true );
		$hooks = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook, $cb ) use ( &$hooks ) {
				$hooks[ $hook ] = $cb;
				return true;
			}
		);

		RecruitmentPublicShortcode::register();

		$this->assertArrayHasKey( 'wp_enqueue_scripts', $hooks );
		$this->assertSame(
			array( RecruitmentPublicShortcode::class, 'maybe_enqueue_early' ),
			$hooks['wp_enqueue_scripts']
		);
	}

	/**
	 * The shortcode enqueues from inside `the_content`, which runs after
	 * `wp_head` — so the `<link>` comes out in the footer and there is a window
	 * in which the badges paint without their shape. This hook closes it for
	 * the case we can see.
	 */
	public function test_early_enqueue_runs_when_the_queried_post_carries_the_shortcode(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		$post               = new \WP_Post();
		$post->post_content = '[ffc_recruitment_queue notice="X"]';
		Functions\when( 'get_post' )->justReturn( $post );
		Functions\expect( 'has_shortcode' )
			->once()
			->with( $post->post_content, 'ffc_recruitment_queue' )
			->andReturn( true );
		// `when()` and not `expect()`: the setUp already stubs this function,
		// and a second Brain\Monkey expectation on the same name never sees the
		// calls — the first stub answers them all.
		$handles = array();
		Functions\when( 'wp_enqueue_style' )->alias(
			static function ( $handle ) use ( &$handles ) {
				$handles[] = $handle;
				return true;
			}
		);

		RecruitmentPublicShortcode::maybe_enqueue_early();

		$this->assertContains( 'ffc-recruitment-public', $handles );
		$this->assertContains( 'ffc-common', $handles, 'A paleta tem que ir junto: sem ela, todo `var(--ffc-*)` invalida a declaração.' );
	}

	/**
	 * A post without the shortcode must not pull the sheets onto every page of
	 * the site — the hook fires on every frontend request.
	 */
	public function test_early_enqueue_is_silent_when_the_post_has_no_shortcode(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		$post               = new \WP_Post();
		$post->post_content = 'nothing here';
		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'has_shortcode' )->justReturn( false );
		$handles = array();
		Functions\when( 'wp_enqueue_style' )->alias(
			static function ( $handle ) use ( &$handles ) {
				$handles[] = $handle;
				return true;
			}
		);

		RecruitmentPublicShortcode::maybe_enqueue_early();

		$this->assertSame( array(), $handles );
	}

	/**
	 * No queried post at all (a 404, an archive) — `get_post()` returns null and
	 * the read of `post_content` would be a fatal.
	 */
	public function test_early_enqueue_is_silent_without_a_queried_post(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'get_post' )->justReturn( null );
		$handles = array();
		Functions\when( 'wp_enqueue_style' )->alias(
			static function ( $handle ) use ( &$handles ) {
				$handles[] = $handle;
				return true;
			}
		);

		RecruitmentPublicShortcode::maybe_enqueue_early();

		$this->assertSame( array(), $handles );
	}

}

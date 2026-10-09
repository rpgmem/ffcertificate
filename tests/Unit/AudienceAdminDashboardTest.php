<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceAdminDashboard;

/**
 * @covers \FreeFormCertificate\Audience\AudienceAdminDashboard
 */
class AudienceAdminDashboardTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
		Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );
		Functions\when( 'current_time' )->justReturn( '2025-01-01 12:00:00' );
		// The shared stat cards format their number (#1631).
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => (string) $n );
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		// AudienceEnvironmentRepository::count() folds the CacheVersion counter
		// (a wp_options read) into its query-cache key.
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults = array() ) {
			return array_merge( $defaults, (array) $args );
		} );
		Functions\when( 'sanitize_sql_orderby' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();

		// wp_count_posts returns an object with publish, draft, etc.
		$counts = (object) array( 'publish' => 3, 'draft' => 1, 'trash' => 0 );
		Functions\when( 'wp_count_posts' )->justReturn( $counts );

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', '/tmp/' );
		}

		global $wpdb;
		$wpdb = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$wpdb->posts = 'wp_posts';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () { return func_get_arg(0); } )->byDefault();
		$wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
		$wpdb->shouldReceive( 'get_var' )->andReturn( '0' )->byDefault();
		$wpdb->shouldReceive( 'get_row' )->andReturn( null )->byDefault();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ==================================================================
	// Constructor
	// ==================================================================

	public function test_constructor_creates_instance(): void {
		$page = new AudienceAdminDashboard( 'ffc-scheduling' );
		$this->assertInstanceOf( AudienceAdminDashboard::class, $page );
	}

	// ==================================================================
	// render_dashboard_page()
	// ==================================================================

	public function test_render_dashboard_page_outputs_html(): void {
		$page = new AudienceAdminDashboard( 'ffc-scheduling' );
		ob_start();
		$page->render_dashboard_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'wrap', $output );
	}

	public function test_dashboard_draws_the_shared_stat_cards_under_icon_headings(): void {
		// #1631: the screen had its own card markup and bare h2s with a rule.
		$page = new AudienceAdminDashboard( 'ffc-scheduling' );
		ob_start();
		$page->render_dashboard_page();
		$output = (string) ob_get_clean();

		$this->assertSame( 6, substr_count( $output, '<div class="ffc-stat-card">' ) );
		$this->assertSame( 6, substr_count( $output, 'class="ffc-stat-card__link"' ) );
		foreach ( array( 'ffc-icon-user', 'ffc-icon-users', 'ffc-icon-zap' ) as $icon ) {
			$this->assertStringContainsString( '<h2 class="' . $icon . '">', $output );
		}
		$this->assertStringNotContainsString( 'ffc-audience-stat-card', $output );
	}
}

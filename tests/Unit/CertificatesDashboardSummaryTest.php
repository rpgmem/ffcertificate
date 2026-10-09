<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\CertificatesDashboard;

/**
 * The dashboard's summary row and side-panel empty state (#1614).
 *
 * @covers \FreeFormCertificate\Admin\CertificatesDashboard
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CertificatesDashboardSummaryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Geofence config by form id.
	 *
	 * @var array<int, mixed>
	 */
	private array $configs = array();

	/**
	 * `since` bounds the repository was asked about.
	 *
	 * @var array<int, int>
	 */
	private array $since = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', '/tmp/' );
		}
		foreach ( array( '__', 'esc_html__', 'esc_html', 'esc_attr', 'esc_url' ) as $fn ) {
			Functions\when( $fn )->returnArg();
		}
		Functions\when( 'esc_html_e' )->alias( static function ( $t ) { echo $t; } );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => (string) $n );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'America/Sao_Paulo' ) );
		Functions\when( 'get_posts' )->alias( fn() => array_keys( $this->configs ) );
		Functions\when( 'get_post_meta' )->alias( fn( $id ) => $this->configs[ $id ] ?? '' );

		$repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\SubmissionRepository' );
		$repo->shouldReceive( 'countPublishedSince' )->andReturnUsing(
			function ( int $since ): int {
				$this->since[] = $since;
				return 1 === count( $this->since ) ? 3 : 12;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_counts_today_and_the_last_seven_days_from_midnight_in_the_site_timezone(): void {
		$summary = ( new CertificatesDashboard() )->summary();

		$this->assertSame( 3, $summary['today'] );
		$this->assertSame( 12, $summary['week'] );
		$midnight = ( new \DateTimeImmutable( 'today', new \DateTimeZone( 'America/Sao_Paulo' ) ) )->getTimestamp();
		$this->assertSame( array( $midnight, $midnight - 6 * 86400 ), $this->since );
	}

	public function test_counts_only_forms_whose_date_window_is_on_and_open(): void {
		$this->configs = array(
			1 => array( 'datetime_enabled' => '1', 'open' => true ),
			2 => array( 'datetime_enabled' => '1', 'open' => false ),
			3 => array( 'datetime_enabled' => '0', 'open' => true ),
			4 => 'not an array',
		);
		$geo = Mockery::mock( 'alias:FreeFormCertificate\Security\Geofence' );
		$geo->shouldReceive( 'validate_datetime' )->andReturnUsing( static fn( array $c ) => array( 'valid' => $c['open'] ) );

		$this->assertSame( 1, ( new CertificatesDashboard() )->summary()['open'], 'off and closed windows do not count' );
	}

	public function test_render_page_prints_the_summary_row_and_the_shared_empty_state(): void {
		ob_start();
		( new CertificatesDashboard() )->render_page();
		$html = (string) ob_get_clean();

		$this->assertSame( 4, substr_count( $html, '<div class="ffc-stat-card">' ) );
		$this->assertStringContainsString( '<span class="ffc-stat-card__value" id="ffc-cert-stat-month">—</span>', $html, 'the script fills the month in' );
		$this->assertStringContainsString( '<span class="ffc-stat-card__value">3</span><span class="ffc-stat-card__label">Submissions today</span>', $html );
		$this->assertStringContainsString( '<span class="ffc-stat-card__value">12</span><span class="ffc-stat-card__label">Submissions in the last 7 days</span>', $html );
		$this->assertStringContainsString( '<div class="ffc-certificates-side-empty"><div class="ffc-empty-state">', preg_replace( '/>\s+</', '><', $html ) );
		$this->assertStringContainsString( 'class="ffc-certificates-dashboard"', $html );
		$this->assertStringContainsString( 'id="ffc-certificates-day-list"', $html );
	}
}

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
 * Runs the real SubmissionRepository over a `$wpdb` double and the real
 * Geofence date check over each form's stored config.
 *
 * @covers \FreeFormCertificate\Admin\CertificatesDashboard
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

	/**
	 * The `$wpdb` global before the test, restored in tearDown().
	 *
	 * @var mixed
	 */
	private $previous_wpdb;

	/**
	 * Whether `$wpdb` was set before the test.
	 */
	private bool $had_wpdb = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		foreach ( array( '__', 'esc_html__', 'esc_html', 'esc_attr', 'esc_url' ) as $fn ) {
			Functions\when( $fn )->returnArg();
		}
		Functions\when( 'esc_html_e' )->alias( static function ( $t ) { echo $t; } );
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => (string) $n );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'America/Sao_Paulo' ) );
		Functions\when( 'get_posts' )->alias( fn() => array_keys( $this->configs ) );
		Functions\when( 'get_post_meta' )->alias( fn( $id ) => $this->configs[ $id ] ?? '' );

		Functions\when( 'wp_date' )->alias( static fn( string $format, ?int $ts = null ) => gmdate( $format, $ts ?? time() ) );

		$this->had_wpdb      = array_key_exists( 'wpdb', $GLOBALS );
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;

		// The count query binds the table and the `since` bound; the double
		// records the bound and answers 3 for the first count, 12 after.
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( string $sql, ...$args ): string {
				if ( str_contains( $sql, 'submission_date >= %d' ) && 'wp_ffc_submissions' === $args[0] ) {
					$this->since[] = (int) $args[1];
					return 'COUNT_SINCE';
				}
				return $sql;
			}
		);
		$wpdb->shouldReceive( 'get_var' )->with( 'COUNT_SINCE' )->andReturnUsing(
			fn(): string => 1 === count( $this->since ) ? '3' : '12'
		);
		$GLOBALS['wpdb'] = $wpdb;
	}

	protected function tearDown(): void {
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_counts_today_and_the_last_seven_days_from_midnight_in_the_site_timezone(): void {
		// Midnight is read before and after the call and either is accepted:
		// a single read fails when the run crosses midnight (#1719).
		$midnight = static fn(): int => ( new \DateTimeImmutable( 'today', new \DateTimeZone( 'America/Sao_Paulo' ) ) )->getTimestamp();
		$before   = $midnight();
		$summary  = ( new CertificatesDashboard() )->summary();
		$after    = $midnight();

		$this->assertSame( 3, $summary['today'] );
		$this->assertSame( 12, $summary['week'] );
		$this->assertContains(
			$this->since,
			array( array( $before, $before - 6 * 86400 ), array( $after, $after - 6 * 86400 ) )
		);
	}

	public function test_counts_only_forms_whose_date_window_is_on_and_open(): void {
		// Windows far from today on both sides, so the check does not depend
		// on the day or the timezone the run happens in.
		$open          = array(
			'date_start' => '2000-01-01',
			'date_end'   => '2999-12-31',
		);
		$closed        = array(
			'date_start' => '2000-01-01',
			'date_end'   => '2000-01-02',
		);
		$this->configs = array(
			1 => array( 'datetime_enabled' => '1' ) + $open,
			2 => array( 'datetime_enabled' => '1' ) + $closed,
			3 => array( 'datetime_enabled' => '0' ) + $open,
			4 => 'not an array',
		);

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

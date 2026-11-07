<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentDashboard;

/**
 * The Recruitment dashboard's figures (#2486): what each count reads and
 * how the rows the database returns become the side column.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentDashboard
 */
class RecruitmentDashboardTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var mixed The `$wpdb` an earlier test left, restored afterwards. */
	private $wpdb_backup;

	/** @var list<string> Every statement the dashboard ran. */
	private array $queries = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->wpdb_backup = $GLOBALS['wpdb'] ?? null;
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => (string) $n );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->wpdb_backup;
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * A database that answers each statement by what it selects.
	 *
	 * @param list<array<string,mixed>> $upcoming Rows of the presentations query.
	 */
	private function database( array $upcoming ): void {
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn( $query, ...$args ) => vsprintf( str_replace( array( '%i', '%s' ), array( '%s', "'%s'" ), (string) $query ), $args )
		);
		$wpdb->shouldReceive( 'get_results' )->andReturnUsing(
			function ( $query ) use ( $upcoming ) {
				$this->queries[] = (string) $query;
				if ( false !== strpos( (string) $query, 'GROUP BY status' ) ) {
					return array(
						array( 'status' => 'draft', 'n' => '2' ),
						array( 'status' => 'definitive', 'n' => '3' ),
						array( 'status' => 'bogus', 'n' => '9' ),
					);
				}
				return $upcoming;
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturnUsing(
			function ( $query ) {
				$this->queries[] = (string) $query;
				return array( 'total' => '30', 'linked' => '12' );
			}
		);
		$wpdb->shouldReceive( 'get_var' )->andReturnUsing(
			function ( $query ) {
				$this->queries[] = (string) $query;
				if ( false !== strpos( (string) $query, 'DISTINCT' ) ) {
					return '2';
				}
				if ( false !== strpos( (string) $query, 'NOT EXISTS' ) ) {
					return '1';
				}
				if ( false !== strpos( (string) $query, "status = 'called'" ) ) {
					return '4';
				}
				return '7';
			}
		);
		$GLOBALS['wpdb'] = $wpdb;
	}

	public function test_stats_maps_every_count(): void {
		$this->database(
			array(
				array( 'code' => 'EDT-1', 'date' => '2026-11-08', 'people' => '2' ),
				array( 'code' => 'EDT-2', 'date' => '2026-11-09', 'people' => '1' ),
			)
		);

		$stats = RecruitmentDashboard::stats( '2026-11-07' );

		$this->assertSame(
			array( 'draft' => 2, 'preliminary' => 0, 'definitive' => 3, 'closed' => 0 ),
			$stats['notices'],
			'every status is listed, and an unknown one is not'
		);
		$this->assertSame( 30, $stats['candidates'] );
		$this->assertSame( 12, $stats['linked'] );
		$this->assertSame( 4, $stats['awaiting'] );
		$this->assertSame( 3, $stats['upcoming'], 'people summed across the days' );
		$this->assertSame( 2, $stats['overdue'] );
		$this->assertSame( 7, $stats['adjutancies'] );
		$this->assertSame( 1, $stats['unattached'] );
		$this->assertSame( 7, $stats['reasons'] );
		$this->assertSame(
			array(
				array( 'code' => 'EDT-1', 'date' => '2026-11-08', 'people' => 2 ),
				array( 'code' => 'EDT-2', 'date' => '2026-11-09', 'people' => 1 ),
			),
			$stats['upcoming_rows']
		);
	}

	public function test_presentations_count_seven_days_from_today_and_skip_cancelled_calls(): void {
		$this->database( array() );

		RecruitmentDashboard::stats( '2026-11-07' );

		$presentations = array_values( array_filter( $this->queries, static fn( $q ) => false !== strpos( $q, 'BETWEEN' ) ) );
		$this->assertCount( 1, $presentations );
		$this->assertStringContainsString( "BETWEEN '2026-11-07' AND '2026-11-13'", $presentations[0], 'today and the six days after it' );
		$this->assertStringContainsString( 'c.cancelled_at IS NULL', $presentations[0] );
		$this->assertStringContainsString( "cl.status IN ('called', 'accepted')", $presentations[0] );

		$overdue = array_values( array_filter( $this->queries, static fn( $q ) => false !== strpos( $q, 'DISTINCT' ) ) );
		$this->assertStringContainsString( "c.date_to_assume < '2026-11-07'", $overdue[0], 'a call is overdue from the day after its date' );
	}

	public function test_the_side_column_lists_at_most_five_rows_but_counts_them_all(): void {
		$rows = array();
		for ( $i = 1; $i <= 7; $i++ ) {
			$rows[] = array( 'code' => 'EDT-' . $i, 'date' => '2026-11-08', 'people' => '1' );
		}
		$this->database( $rows );

		$stats = RecruitmentDashboard::stats( '2026-11-07' );

		$this->assertCount( 5, $stats['upcoming_rows'] );
		$this->assertSame( 7, $stats['upcoming'] );
	}

	public function test_figures_escapes_and_formats(): void {
		$this->assertSame(
			'<ul class="ffc-dashboard__figures"><li><span>Draft</span><span>5</span></li><li><span>Date</span><span>12/11</span></li></ul>',
			RecruitmentDashboard::figures(
				array(
					'Draft' => 5,
					'Date'  => '12/11',
				)
			)
		);
	}
}

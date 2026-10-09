<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use FreeFormCertificate\DateMessages\DeliveryLog;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * The log is the deduplication (#1538).
 *
 * @covers \FreeFormCertificate\DateMessages\DeliveryLog
 */
class DateMessagesDeliveryLogTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		global $wpdb;
		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$this->wpdb   = $wpdb;
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @dataProvider affected
	 *
	 * @param int|false $affected Rows the INSERT IGNORE reported.
	 * @param bool      $claimed  Expected claim.
	 */
	public function test_a_claim_is_won_only_when_the_row_went_in( $affected, bool $claimed ): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturnUsing(
			static function ( $sql ) {
				return str_starts_with( $sql, 'INSERT IGNORE INTO %i' ) ? 'SQL' : 'WRONG';
			}
		);
		$this->wpdb->shouldReceive( 'query' )->once()->with( 'SQL' )->andReturn( $affected );

		$this->assertSame( $claimed, DeliveryLog::claim( 7, 3, 9, '2026-10-10' ) );
	}

	/**
	 * @return array<string, array{0: int|false, 1: bool}>
	 */
	public function affected(): array {
		return array(
			'inserted'       => array( 1, true ),
			'already logged' => array( 0, false ),
			'query failed'   => array( false, false ),
		);
	}

	public function test_bump_refuses_a_column_that_is_not_a_counter(): void {
		$this->wpdb->shouldReceive( 'prepare' )->never();
		$this->wpdb->shouldReceive( 'query' )->never();

		DeliveryLog::bump( 7, 'started_at', 5 );
		DeliveryLog::bump( 7, 'sent', 0 );
	}

	public function test_delivered_among_reads_one_query_for_the_batch(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_col' )->once()->with( 'SQL' )->andReturn( array( '4', 'x', '9' ) );

		$this->assertSame( array( 4 => true, 9 => true ), DeliveryLog::delivered_among( 3, '2026-10-10', array( 4, 5, 9 ) ) );
	}
	public function test_recent_runs_pages_newest_first_and_counts(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->with(
			'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d',
			'wp_ffc_date_message_runs',
			20,
			40
		)->andReturn( 'PAGE' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->with( 'PAGE', ARRAY_A )->andReturn(
			array(
				array(
					'id'   => '9',
					'sent' => '4',
				),
			)
		);
		$this->wpdb->shouldReceive( 'prepare' )->once()->with( 'SELECT COUNT(*) FROM %i', 'wp_ffc_date_message_runs' )->andReturn( 'COUNT' );
		$this->wpdb->shouldReceive( 'get_var' )->once()->with( 'COUNT' )->andReturn( '41' );

		$this->assertSame(
			array(
				array(
					'id'   => '9',
					'sent' => '4',
				),
			),
			DeliveryLog::recent_runs( 20, 40 )
		);
		$this->assertSame( 41, DeliveryLog::count_runs() );
	}

	public function test_recent_runs_reads_a_failed_query_as_none(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'PAGE' );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn( null );

		$this->assertSame( array(), DeliveryLog::recent_runs( 0, -5 ) );
	}

	// ------------------------------------------------------------------
	// One-year history (#1647)
	// ------------------------------------------------------------------

	public function test_purge_removes_old_runs_with_their_deliveries_first(): void {
		$order = array();
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, ...$args ) {
				$flat = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
				return $sql . ' | ' . implode( ',', $flat );
			}
		);
		$this->wpdb->shouldReceive( 'get_col' )->once()->with( 'SELECT id FROM %i WHERE started_at < %d ORDER BY id LIMIT %d | wp_ffc_date_message_runs,1000,200' )->andReturn( array( '4', '9' ) );
		$this->wpdb->shouldReceive( 'query' )->twice()->andReturnUsing(
			static function ( $sql ) use ( &$order ) {
				$order[] = $sql;
				return 2;
			}
		);

		$this->assertSame( 2, DeliveryLog::purge( 1000 ) );
		$this->assertSame(
			array(
				'DELETE FROM %i WHERE run_id IN (%d,%d) | wp_ffc_date_message_log,4,9',
				'DELETE FROM %i WHERE id IN (%d,%d) | wp_ffc_date_message_runs,4,9',
			),
			$order,
			'Deliveries go before their run, so an interrupted purge never orphans one.'
		);
	}

	public function test_purge_with_nothing_old_removes_nothing(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SELECT' );
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array() );
		$this->wpdb->shouldReceive( 'query' )->never();

		$this->assertSame( 0, DeliveryLog::purge( 1000 ) );
	}

	public function test_purge_continues_while_batches_are_full_and_stops_when_one_removes_nothing(): void {
		$full = array_map( 'strval', range( 1, 200 ) );
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_col' )->times( 2 )->andReturn( $full );
		// First batch removes 200 runs; the second removes none, which ends the
		// loop even though the selection keeps answering (#1378).
		$this->wpdb->shouldReceive( 'query' )->times( 4 )->andReturn( 200, 200, 0, 0 );

		$this->assertSame( 200, DeliveryLog::purge( 1000 ) );
	}

	public function test_purge_stops_after_its_batch_budget(): void {
		$full = array_map( 'strval', range( 1, 200 ) );
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_col' )->times( 25 )->andReturn( $full );
		$this->wpdb->shouldReceive( 'query' )->times( 50 )->andReturn( 200 );

		$this->assertSame( 5000, DeliveryLog::purge( 1000 ) );
	}

	public function test_purge_expired_uses_the_one_year_window(): void {
		$this->assertSame( 365, DeliveryLog::RETENTION_DAYS );
		$cutoffs = array();
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturnUsing(
			static function ( $sql, $table, $before ) use ( &$cutoffs ) {
				$cutoffs[] = $before;
				return 'SELECT';
			}
		);
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array() );

		$expected = time() - 365 * DAY_IN_SECONDS;
		DeliveryLog::purge_expired();

		$this->assertEqualsWithDelta( $expected, $cutoffs[0], 5 );
	}
}

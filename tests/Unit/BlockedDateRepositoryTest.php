<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Repositories\BlockedDateRepository;

/**
 * Tests for BlockedDateRepository: recurring pattern matching (weekly, monthly, yearly).
 *
 * Uses Reflection to access the private matchesRecurringPattern() method.
 */
class BlockedDateRepositoryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var BlockedDateRepository */
	private $repository;

	/** @var \Mockery\MockInterface&\wpdb */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Several methods this class reaches are deprecated (#2089) and emit
		// the notice; stub it so each test chooses the branch rather than
		// inheriting it from an earlier class.
		\Brain\Monkey\Functions\when( '_deprecated_function' )->justReturn( null );

		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_cache_flush' )->justReturn( true );
		// clear_cache() with no key asks the backend first (#2241); without
		// group flushing it falls back to wp_cache_flush(), stubbed above.
		Functions\when( 'wp_cache_supports' )->justReturn( false );
		Functions\when( 'current_time' )->justReturn( '2030-01-01 00:00:00' );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'wp_json_encode' )->alias( fn ( $v ) => json_encode( $v ) );

		// Provide a mock $wpdb global for AbstractRepository
		global $wpdb;
		$wpdb = Mockery::mock( 'wpdb' )->makePartial();
		$wpdb->prefix = 'wp_';
		$wpdb->last_error = '';
		$wpdb->insert_id = 0;

		$this->wpdb = $wpdb;

		$this->repository = new BlockedDateRepository();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Invoke the private matchesRecurringPattern method.
	 */
	private function matchesPattern( string $date, ?string $time, array $pattern ): bool {
		$ref = new \ReflectionMethod( BlockedDateRepository::class, 'matchesRecurringPattern' );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $this->repository, [ $date, $time, $pattern ] );
	}

	// ==================================================================
	// Empty / invalid patterns
	// ==================================================================

	public function test_empty_pattern_returns_false(): void {
		$this->assertFalse( $this->matchesPattern( '2030-01-15', null, array() ) );
	}

	public function test_missing_type_returns_false(): void {
		$this->assertFalse( $this->matchesPattern( '2030-01-15', null, array( 'days' => array( 1 ) ) ) );
	}

	public function test_unknown_type_returns_false(): void {
		$this->assertFalse( $this->matchesPattern( '2030-01-15', null, array( 'type' => 'unknown', 'days' => array( 1 ) ) ) );
	}

	// ==================================================================
	// Weekly pattern — day of week (0=Sun … 6=Sat)
	// ==================================================================

	public function test_weekly_matches_blocked_day(): void {
		// 2030-01-13 is a Sunday (0)
		$pattern = array( 'type' => 'weekly', 'days' => array( 0 ) );
		$this->assertTrue( $this->matchesPattern( '2030-01-13', null, $pattern ) );
	}

	public function test_weekly_does_not_match_unblocked_day(): void {
		// 2030-01-14 is Monday (1), blocking only Sunday (0)
		$pattern = array( 'type' => 'weekly', 'days' => array( 0 ) );
		$this->assertFalse( $this->matchesPattern( '2030-01-14', null, $pattern ) );
	}

	public function test_weekly_weekend_block(): void {
		$pattern = array( 'type' => 'weekly', 'days' => array( 0, 6 ) ); // Sun, Sat

		// 2030-01-12 Saturday (6) → blocked
		$this->assertTrue( $this->matchesPattern( '2030-01-12', null, $pattern ) );
		// 2030-01-13 Sunday (0) → blocked
		$this->assertTrue( $this->matchesPattern( '2030-01-13', null, $pattern ) );
		// 2030-01-14 Monday (1) → not blocked
		$this->assertFalse( $this->matchesPattern( '2030-01-14', null, $pattern ) );
	}

	public function test_weekly_empty_days_returns_false(): void {
		$pattern = array( 'type' => 'weekly', 'days' => array() );
		$this->assertFalse( $this->matchesPattern( '2030-01-14', null, $pattern ) );
	}

	public function test_weekly_missing_days_key_returns_false(): void {
		$pattern = array( 'type' => 'weekly' );
		$this->assertFalse( $this->matchesPattern( '2030-01-14', null, $pattern ) );
	}

	// ==================================================================
	// Monthly pattern — day of month (1-31)
	// ==================================================================

	public function test_monthly_matches_blocked_day_of_month(): void {
		// Block 1st and 15th of every month
		$pattern = array( 'type' => 'monthly', 'days' => array( 1, 15 ) );
		$this->assertTrue( $this->matchesPattern( '2030-03-01', null, $pattern ) );
		$this->assertTrue( $this->matchesPattern( '2030-07-15', null, $pattern ) );
	}

	public function test_monthly_does_not_match_unblocked_day(): void {
		$pattern = array( 'type' => 'monthly', 'days' => array( 1, 15 ) );
		$this->assertFalse( $this->matchesPattern( '2030-03-10', null, $pattern ) );
	}

	public function test_monthly_empty_days_returns_false(): void {
		$pattern = array( 'type' => 'monthly', 'days' => array() );
		$this->assertFalse( $this->matchesPattern( '2030-03-01', null, $pattern ) );
	}

	public function test_monthly_missing_days_key_returns_false(): void {
		$pattern = array( 'type' => 'monthly' );
		$this->assertFalse( $this->matchesPattern( '2030-03-01', null, $pattern ) );
	}

	// ==================================================================
	// Yearly pattern — specific month-day (mm-dd)
	// ==================================================================

	public function test_yearly_matches_holiday_date(): void {
		// Block Christmas and New Year
		$pattern = array( 'type' => 'yearly', 'dates' => array( '12-25', '01-01' ) );
		$this->assertTrue( $this->matchesPattern( '2030-12-25', null, $pattern ) );
		$this->assertTrue( $this->matchesPattern( '2030-01-01', null, $pattern ) );
	}

	public function test_yearly_does_not_match_other_date(): void {
		$pattern = array( 'type' => 'yearly', 'dates' => array( '12-25', '01-01' ) );
		$this->assertFalse( $this->matchesPattern( '2030-06-15', null, $pattern ) );
	}

	public function test_yearly_empty_dates_returns_false(): void {
		$pattern = array( 'type' => 'yearly', 'dates' => array() );
		$this->assertFalse( $this->matchesPattern( '2030-12-25', null, $pattern ) );
	}

	public function test_yearly_missing_dates_key_returns_false(): void {
		$pattern = array( 'type' => 'yearly' );
		$this->assertFalse( $this->matchesPattern( '2030-12-25', null, $pattern ) );
	}

	public function test_yearly_ignores_year_variation(): void {
		// Same mm-dd in different years
		$pattern = array( 'type' => 'yearly', 'dates' => array( '09-07' ) );
		$this->assertTrue( $this->matchesPattern( '2030-09-07', null, $pattern ) );
		$this->assertTrue( $this->matchesPattern( '2031-09-07', null, $pattern ) );
	}

	// ==================================================================
	// Time parameter (pattern matching ignores time — time check is external)
	// ==================================================================

	public function test_weekly_match_ignores_time_parameter(): void {
		// 2030-01-12 Saturday (6)
		$pattern = array( 'type' => 'weekly', 'days' => array( 6 ) );
		$this->assertTrue( $this->matchesPattern( '2030-01-12', '09:00', $pattern ) );
		$this->assertTrue( $this->matchesPattern( '2030-01-12', null, $pattern ) );
	}

	// ==================================================================
	// getGlobalBlocks
	// ==================================================================

	public function test_get_global_blocks_returns_rows(): void {
		$rows = array( array( 'id' => 1, 'calendar_id' => null, 'block_type' => 'full_day' ) );
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

		$this->assertSame( $rows, $this->repository->getGlobalBlocks() );
	}

	public function test_get_global_blocks_returns_empty_on_null(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( null );

		$this->assertSame( array(), $this->repository->getGlobalBlocks() );
	}

	// ==================================================================
	// findByCalendar (delegates to findAll)
	// ==================================================================

	public function test_find_by_calendar_returns_rows(): void {
		$rows = array( array( 'id' => 5, 'calendar_id' => 3 ) );
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

		$this->assertSame( $rows, $this->repository->findByCalendar( 3 ) );
	}

	// ==================================================================
	// getBlockedDatesInRange / getUpcomingBlocks
	// ==================================================================

	public function test_get_blocked_dates_in_range_returns_rows(): void {
		$rows = array( array( 'id' => 1 ), array( 'id' => 2 ) );
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

		$this->assertSame( $rows, $this->repository->getBlockedDatesInRange( 1, '2030-01-01', '2030-02-01' ) );
	}

	public function test_get_blocked_dates_in_range_returns_empty_on_null(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( null );

		$this->assertSame( array(), $this->repository->getBlockedDatesInRange( 1, '2030-01-01', '2030-02-01' ) );
	}

	public function test_get_upcoming_blocks_delegates_to_range(): void {
		$rows = array( array( 'id' => 9 ) );
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( $rows );

		$this->assertSame( $rows, $this->repository->getUpcomingBlocks( 4, 14 ) );
	}

	// ==================================================================
	// isDateBlocked — block type branches
	// ==================================================================

	public function test_is_date_blocked_full_day(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array( array( 'block_type' => 'full_day' ) )
		);

		$this->assertTrue( $this->repository->isDateBlocked( 1, '2030-05-20' ) );
	}

	public function test_is_date_blocked_time_range_inside_window(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array( array( 'block_type' => 'time_range', 'start_time' => '09:00', 'end_time' => '12:00' ) )
		);

		$this->assertTrue( $this->repository->isDateBlocked( 1, '2030-05-20', '10:00' ) );
	}

	public function test_is_date_blocked_time_range_outside_window(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array( array( 'block_type' => 'time_range', 'start_time' => '09:00', 'end_time' => '12:00' ) )
		);

		$this->assertFalse( $this->repository->isDateBlocked( 1, '2030-05-20', '13:00' ) );
	}

	public function test_is_date_blocked_recurring_match(): void {
		// 2030-01-12 is a Saturday (6).
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn(
			array(
				array(
					'block_type'        => 'recurring',
					'recurring_pattern' => json_encode( array( 'type' => 'weekly', 'days' => array( 6 ) ) ),
				),
			)
		);

		$this->assertTrue( $this->repository->isDateBlocked( 1, '2030-01-12' ) );
	}

	public function test_is_date_blocked_returns_false_when_no_blocks(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );

		$this->assertFalse( $this->repository->isDateBlocked( 1, '2030-05-20' ) );
	}

	public function test_is_date_blocked_handles_null_results(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( null );

		$this->assertFalse( $this->repository->isDateBlocked( 1, '2030-05-20' ) );
	}

	// ==================================================================
	// #2244: one day for a NULL end date, overlap, duplicates, delete
	// ==================================================================

	public function test_a_block_without_an_end_date_is_read_for_its_own_day_only(): void {
		$sql  = '';
		$args = array();
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturnUsing(
			static function ( $query, ...$values ) use ( &$sql, &$args ) {
				$sql  = $query;
				$args = $values;
				return 'SQL';
			}
		);
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( array() );

		$this->repository->getBlocksForDate( 3, '2030-05-20' );

		$this->assertStringContainsString( 'end_date >= %s OR (end_date IS NULL AND (start_date = %s OR block_type = %s))', $sql );
		$this->assertSame( array( 'wp_ffc_self_scheduling_blocked_dates', 3, '2030-05-20', '2030-05-20', '2030-05-20', 'recurring' ), $args, 'Only a recurring block runs on without an end.' );
	}

	/** @return array<string, array{string, ?string, bool}> */
	public function slots_against_a_morning_block(): array {
		return array(
			'starts inside'           => array( '10:00:00', '10:30:00', true ),
			'starts before, overlaps' => array( '08:30:00', '09:30:00', true ),
			'ends when it starts'     => array( '08:00:00', '09:00:00', false ),
			'starts when it ends'     => array( '12:00:00', '12:30:00', false ),
			'spans it'                => array( '08:00:00', '13:00:00', true ),
			'start only, before'      => array( '08:30:00', null, false ),
			'start only, inside'      => array( '09:00:00', null, true ),
		);
	}

	/**
	 * @dataProvider slots_against_a_morning_block
	 */
	public function test_a_time_range_closes_every_slot_it_overlaps( string $start, ?string $end, bool $closed ): void {
		$blocks = array( array( 'block_type' => 'time_range', 'start_time' => '09:00:00', 'end_time' => '12:00:00' ) );

		$this->assertSame( $closed, BlockedDateRepository::blocksMatch( $blocks, '2030-05-20', $start, $end ) );
	}

	public function test_is_covered_finds_a_full_day_over_the_range_and_the_same_time_range(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$this->wpdb->shouldReceive( 'get_results' )->andReturn(
			array(
				array( 'block_type' => 'full_day', 'start_date' => '2030-05-20', 'end_date' => '2030-05-22' ),
				array( 'block_type' => 'full_day', 'start_date' => '2030-06-01', 'end_date' => null ),
				array( 'block_type' => 'time_range', 'start_date' => '2030-07-01', 'end_date' => null, 'start_time' => '09:00:00', 'end_time' => '12:00:00' ),
			)
		);

		$this->assertTrue( $this->repository->isCovered( 3, 'full_day', '2030-05-21', '2030-05-22' ) );
		$this->assertTrue( $this->repository->isCovered( 3, 'time_range', '2030-05-20', '2030-05-20', '10:00:00', '11:00:00' ), 'A whole-day block covers a time range on it.' );
		$this->assertTrue( $this->repository->isCovered( 3, 'full_day', '2030-06-01', '2030-06-01' ), 'No end date is the start date.' );
		$this->assertTrue( $this->repository->isCovered( 3, 'time_range', '2030-07-01', '2030-07-01', '09:30:00', '11:00:00' ) );
		$this->assertFalse( $this->repository->isCovered( 3, 'full_day', '2030-05-21', '2030-05-23' ), 'One day past the block.' );
		$this->assertFalse( $this->repository->isCovered( 3, 'full_day', '2030-06-02', '2030-06-02' ) );
		$this->assertFalse( $this->repository->isCovered( 3, 'time_range', '2030-07-01', '2030-07-01', '08:00:00', '10:00:00' ) );
		$this->assertFalse( $this->repository->isCovered( 3, 'full_day', '2030-07-01', '2030-07-01' ), 'A time range does not cover the whole day.' );
	}

	public function test_delete_for_calendar_deletes_only_that_calendars_row(): void {
		$this->wpdb->shouldReceive( 'delete' )->once()
			->with( 'wp_ffc_self_scheduling_blocked_dates', array( 'id' => 9, 'calendar_id' => 3 ), array( '%d', '%d' ) )
			->andReturn( 1, 0 );

		$this->assertTrue( $this->repository->deleteForCalendar( 3, 9 ) );
	}

	public function test_delete_for_calendar_reports_a_row_it_did_not_find(): void {
		$this->wpdb->shouldReceive( 'delete' )->once()->andReturn( 0 );

		$this->assertFalse( $this->repository->deleteForCalendar( 3, 9 ) );
	}

	// ==================================================================
	// create* helpers (delegate to AbstractRepository::insert)
	// ==================================================================

	public function test_create_full_day_block_returns_insert_id(): void {
		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( 1 );
		$this->wpdb->insert_id = 55;

		$this->assertSame( 55, $this->repository->createFullDayBlock( null, '2030-05-20', '2030-05-21', 'maint' ) );
	}

	public function test_create_time_range_block_returns_insert_id(): void {
		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( 1 );
		$this->wpdb->insert_id = 77;

		$this->assertSame( 77, $this->repository->createTimeRangeBlock( 2, '2030-05-20', '09:00', '12:00' ) );
	}

	public function test_create_recurring_block_returns_insert_id(): void {
		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( 1 );
		$this->wpdb->insert_id = 88;

		$result = $this->repository->createRecurringBlock( null, '2030-05-20', array( 'type' => 'weekly', 'days' => array( 0, 6 ) ) );
		$this->assertSame( 88, $result );
	}

	public function test_create_full_day_block_returns_false_on_failure(): void {
		$this->wpdb->shouldReceive( 'insert' )->once()->andReturn( false );

		$this->assertFalse( $this->repository->createFullDayBlock( 1, '2030-05-20' ) );
	}

	// ==================================================================
	// deleteExpiredBlocks
	// ==================================================================

	public function test_delete_expired_blocks_returns_row_count(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'DELETE SQL' );
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( 4 );

		$this->assertSame( 4, $this->repository->deleteExpiredBlocks( 30 ) );
	}

	public function test_delete_expired_blocks_returns_false_when_prepare_fails(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( null );

		$this->assertFalse( $this->repository->deleteExpiredBlocks() );
	}

	public function test_delete_expired_blocks_returns_false_on_query_failure(): void {
		$this->wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'DELETE SQL' );
		$this->wpdb->shouldReceive( 'query' )->once()->andReturn( false );

		$this->assertFalse( $this->repository->deleteExpiredBlocks() );
	}
}

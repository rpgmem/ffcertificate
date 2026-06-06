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

        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );
        Functions\when( 'wp_cache_delete' )->justReturn( true );
        Functions\when( 'wp_cache_flush' )->justReturn( true );
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

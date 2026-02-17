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

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );
        Functions\when( 'wp_cache_delete' )->justReturn( true );

        // Provide a mock $wpdb global for AbstractRepository
        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';

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
}

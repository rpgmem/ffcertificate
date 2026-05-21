<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\PreflightStatsService;

/**
 * 6.6.4 follow-up (#361 Sprint 3) — PreflightStatsService aggregates
 * ActivityLog `preflight_blocked` rows into per-form + per-reason
 * counts. Pins:
 *   - Empty result when no rows
 *   - Filter by form_id (other forms' rows are ignored)
 *   - All three reasons counted (cookies / gps_denied / gps_prompt)
 *   - Unknown reasons silently dropped (don't crash on bad data)
 *
 * @runClassInSeparateProcess
 * @preserveGlobalState disabled
 */
class PreflightStatsServiceTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( '__' )->returnArg();
        if ( ! defined( 'DAY_IN_SECONDS' ) ) {
            define( 'DAY_IN_SECONDS', 86400 );
        }
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Helper: alias-mock ActivityLog so we can seed the rows
     * get_form_stats() will pull.
     */
    private function stub_activity_log_rows( array $rows ): void {
        $mock = Mockery::mock( 'alias:\FreeFormCertificate\Core\ActivityLog' );
        $mock->shouldReceive( 'get_activities' )->andReturn( $rows );
    }

    public function test_returns_zero_counts_when_no_rows(): void {
        $this->stub_activity_log_rows( array() );

        $stats = PreflightStatsService::get_form_stats( 42 );

        $this->assertSame(
            array( 'cookies' => 0, 'gps_denied' => 0, 'gps_prompt' => 0, 'total' => 0 ),
            $stats
        );
    }

    public function test_returns_zero_counts_for_invalid_form_id(): void {
        // No alias mock needed — short-circuit before query.
        $stats = PreflightStatsService::get_form_stats( 0 );
        $this->assertSame( 0, $stats['total'] );
    }

    public function test_counts_one_per_reason_for_matching_form(): void {
        $this->stub_activity_log_rows( array(
            array( 'context' => '{"form_id":42,"reason":"cookies"}' ),
            array( 'context' => '{"form_id":42,"reason":"cookies"}' ),
            array( 'context' => '{"form_id":42,"reason":"gps_denied"}' ),
            array( 'context' => '{"form_id":42,"reason":"gps_prompt"}' ),
            array( 'context' => '{"form_id":42,"reason":"gps_prompt"}' ),
            array( 'context' => '{"form_id":42,"reason":"gps_prompt"}' ),
        ) );

        $stats = PreflightStatsService::get_form_stats( 42 );

        $this->assertSame( 2, $stats['cookies'] );
        $this->assertSame( 1, $stats['gps_denied'] );
        $this->assertSame( 3, $stats['gps_prompt'] );
        $this->assertSame( 6, $stats['total'] );
    }

    public function test_ignores_rows_for_other_forms(): void {
        $this->stub_activity_log_rows( array(
            array( 'context' => '{"form_id":42,"reason":"cookies"}' ),
            array( 'context' => '{"form_id":99,"reason":"cookies"}' ), // wrong form
            array( 'context' => '{"form_id":99,"reason":"gps_denied"}' ), // wrong form
        ) );

        $stats = PreflightStatsService::get_form_stats( 42 );

        $this->assertSame( 1, $stats['cookies'] );
        $this->assertSame( 0, $stats['gps_denied'] );
        $this->assertSame( 1, $stats['total'] );
    }

    public function test_silently_drops_unknown_reason_and_malformed_context(): void {
        $this->stub_activity_log_rows( array(
            array( 'context' => '{"form_id":42,"reason":"cookies"}' ),
            array( 'context' => '{"form_id":42,"reason":"made_up"}' ),     // unknown reason
            array( 'context' => 'not even json' ),                          // malformed
            array( 'context' => array( 'form_id' => 42, 'reason' => 'gps_denied' ) ), // array shape
            array( /* no context key */ ),
        ) );

        $stats = PreflightStatsService::get_form_stats( 42 );

        $this->assertSame( 1, $stats['cookies'] );
        $this->assertSame( 1, $stats['gps_denied'] );
        $this->assertSame( 0, $stats['gps_prompt'] );
        $this->assertSame( 2, $stats['total'] );
    }
}

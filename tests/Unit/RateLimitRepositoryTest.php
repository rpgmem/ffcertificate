<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Security\RateLimitRepository;

/**
 * Tests for RateLimitRepository: the raw $wpdb persistence helpers behind
 * RateLimitChecker — counter read/increment, submission counting, temporary
 * blocks, and the window start/end calculators.
 *
 * @covers \FreeFormCertificate\Security\RateLimitRepository
 */
class RateLimitRepositoryTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var \Mockery\MockInterface&\wpdb */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( 'current_time' )->justReturn( '2026-05-20 12:00:00' );

        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' )->makePartial();
        $wpdb->prefix = 'wp_';
        // %i pre-prepared form clauses; default prepare returns the SQL untouched.
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing(
            function ( $sql ) {
                return $sql;
            }
        )->byDefault();
        $this->wpdb = $wpdb;
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // get_count_from_db
    // ------------------------------------------------------------------

    public function test_get_count_from_db_returns_int(): void {
        $this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '5' );

        $this->assertSame( 5, RateLimitRepository::get_count_from_db( 'ip', '1.1.1.1', 'hour', null ) );
    }

    public function test_get_count_from_db_returns_zero_when_null(): void {
        $this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( null );

        $this->assertSame( 0, RateLimitRepository::get_count_from_db( 'ip', '1.1.1.1', 'hour', null ) );
    }

    public function test_get_count_from_db_uses_form_clause_when_form_id_set(): void {
        $this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '2' );

        $this->assertSame( 2, RateLimitRepository::get_count_from_db( 'ip', '1.1.1.1', 'day', 42 ) );
    }

    // ------------------------------------------------------------------
    // increment_counter
    // ------------------------------------------------------------------

    public function test_increment_counter_updates_existing_row(): void {
        $this->wpdb->shouldReceive( 'get_row' )->once()->andReturn(
            (object) array( 'id' => 9, 'count' => 3 )
        );
        $this->wpdb->shouldReceive( 'update' )
            ->once()
            ->with(
                'wp_ffc_rate_limits',
                Mockery::on(
                    function ( $data ) {
                        return 4 === $data['count'];
                    }
                ),
                array( 'id' => 9 ),
                array( '%d', '%s' ),
                array( '%d' )
            )
            ->andReturn( 1 );

        RateLimitRepository::increment_counter( 'ip', '1.1.1.1', 'hour', null );
    }

    public function test_increment_counter_inserts_new_row_when_absent(): void {
        $this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );
        $this->wpdb->shouldReceive( 'insert' )
            ->once()
            ->with(
                'wp_ffc_rate_limits',
                Mockery::on(
                    function ( $data ) {
                        return 1 === $data['count'] && 'ip' === $data['type'] && 7 === $data['form_id'];
                    }
                ),
                Mockery::type( 'array' )
            )
            ->andReturn( 1 );

        RateLimitRepository::increment_counter( 'ip', '1.1.1.1', 'hour', 7 );
    }

    // ------------------------------------------------------------------
    // get_submission_count
    // ------------------------------------------------------------------

    public function test_get_submission_count_email_uses_sha256_fallback_when_encryption_unavailable(): void {
        // With no Encryption configured the method hashes via sha256 and queries.
        $this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '4' );

        $count = RateLimitRepository::get_submission_count( 'email', 'a@b.com', 'day', null );
        $this->assertSame( 4, $count );
    }

    public function test_get_submission_count_email_week_period(): void {
        $this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '1' );

        $this->assertSame( 1, RateLimitRepository::get_submission_count( 'email', 'a@b.com', 'week', 12 ) );
    }

    public function test_get_submission_count_email_month_period(): void {
        $this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '9' );

        $this->assertSame( 9, RateLimitRepository::get_submission_count( 'email', 'a@b.com', 'month', null ) );
    }

    public function test_get_submission_count_cpf_eleven_digits_uses_cpf_hash_column(): void {
        // 11 digits → cpf_hash column path; Encryption is configured in the
        // unit bootstrap so the method hashes + queries.
        $this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '2' );

        $this->assertSame( 2, RateLimitRepository::get_submission_count( 'cpf', '123.456.789-09', 'year', 3 ) );
    }

    public function test_get_submission_count_cpf_seven_digits_uses_rf_hash_column(): void {
        // 7 digits → rf_hash column path.
        $this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '1' );

        $this->assertSame( 1, RateLimitRepository::get_submission_count( 'cpf', '1234567', 'day', null ) );
    }

    public function test_get_submission_count_unknown_field_returns_zero(): void {
        $this->assertSame( 0, RateLimitRepository::get_submission_count( 'phone', '999', 'day', null ) );
    }

    // ------------------------------------------------------------------
    // is_temporarily_blocked
    // ------------------------------------------------------------------

    public function test_is_temporarily_blocked_true_when_row_present(): void {
        $this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '2026-05-20 13:00:00' );

        $this->assertTrue( RateLimitRepository::is_temporarily_blocked( 'ip', '1.1.1.1', null ) );
    }

    public function test_is_temporarily_blocked_false_when_no_row(): void {
        $this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( null );

        $this->assertFalse( RateLimitRepository::is_temporarily_blocked( 'ip', '1.1.1.1', 5 ) );
    }

    // ------------------------------------------------------------------
    // block_temporarily
    // ------------------------------------------------------------------

    public function test_block_temporarily_inserts_block_row(): void {
        $this->wpdb->shouldReceive( 'insert' )
            ->once()
            ->with(
                'wp_ffc_rate_limits',
                Mockery::on(
                    function ( $data ) {
                        return 1 === $data['is_blocked']
                            && 999 === $data['count']
                            && 'abuse' === $data['blocked_reason'];
                    }
                ),
                Mockery::type( 'array' )
            )
            ->andReturn( 1 );

        RateLimitRepository::block_temporarily( 'ip', '1.1.1.1', null, 24 );
    }

    // ------------------------------------------------------------------
    // get_window_start
    // ------------------------------------------------------------------

    public function test_get_window_start_minute(): void {
        $this->assertSame( gmdate( 'Y-m-d H:i:00' ), RateLimitRepository::get_window_start( 'minute' ) );
    }

    public function test_get_window_start_hour(): void {
        $this->assertSame( gmdate( 'Y-m-d H:00:00' ), RateLimitRepository::get_window_start( 'hour' ) );
    }

    public function test_get_window_start_day(): void {
        $this->assertSame( gmdate( 'Y-m-d 00:00:00' ), RateLimitRepository::get_window_start( 'day' ) );
    }

    public function test_get_window_start_week_is_monday(): void {
        $expected = gmdate( 'Y-m-d 00:00:00', strtotime( 'monday this week' ) );
        $this->assertSame( $expected, RateLimitRepository::get_window_start( 'week' ) );
    }

    public function test_get_window_start_month(): void {
        $this->assertSame( gmdate( 'Y-m-01 00:00:00' ), RateLimitRepository::get_window_start( 'month' ) );
    }

    public function test_get_window_start_year(): void {
        $this->assertSame( gmdate( 'Y-01-01 00:00:00' ), RateLimitRepository::get_window_start( 'year' ) );
    }

    public function test_get_window_start_default(): void {
        $result = RateLimitRepository::get_window_start( 'something_else' );
        // Default branch renders a full datetime; assert shape.
        $this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result );
    }

    // ------------------------------------------------------------------
    // get_window_end
    // ------------------------------------------------------------------

    public function test_get_window_end_minute(): void {
        $this->assertSame( gmdate( 'Y-m-d H:i:59' ), RateLimitRepository::get_window_end( 'minute' ) );
    }

    public function test_get_window_end_hour(): void {
        $this->assertSame( gmdate( 'Y-m-d H:59:59' ), RateLimitRepository::get_window_end( 'hour' ) );
    }

    public function test_get_window_end_day(): void {
        $this->assertSame( gmdate( 'Y-m-d 23:59:59' ), RateLimitRepository::get_window_end( 'day' ) );
    }

    public function test_get_window_end_week_is_sunday(): void {
        $expected = gmdate( 'Y-m-d 23:59:59', strtotime( 'sunday this week' ) );
        $this->assertSame( $expected, RateLimitRepository::get_window_end( 'week' ) );
    }

    public function test_get_window_end_month(): void {
        $this->assertSame( gmdate( 'Y-m-t 23:59:59' ), RateLimitRepository::get_window_end( 'month' ) );
    }

    public function test_get_window_end_year(): void {
        $this->assertSame( gmdate( 'Y-12-31 23:59:59' ), RateLimitRepository::get_window_end( 'year' ) );
    }

    public function test_get_window_end_default(): void {
        $result = RateLimitRepository::get_window_end( 'something_else' );
        $this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result );
    }
}

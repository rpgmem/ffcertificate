<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\AuthCodeService;

/**
 * Tests for AuthCodeService: random string generation, auth code formatting,
 * and globally unique code generation across multiple database tables.
 *
 * @covers \FreeFormCertificate\Core\AuthCodeService
 */
class AuthCodeServiceTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Stub wp_rand to delegate to PHP's random_int
        Functions\when( 'wp_rand' )->alias( function ( int $min, int $max ): int {
            return random_int( $min, $max );
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // generate_random_string
    // ==================================================================

    public function test_generate_random_string_default_length_is_12(): void {
        $result = AuthCodeService::generate_random_string();

        $this->assertSame( 12, strlen( $result ) );
    }

    public function test_generate_random_string_default_chars_are_uppercase_alphanumeric(): void {
        $result = AuthCodeService::generate_random_string();

        $this->assertMatchesRegularExpression( '/^[A-Z0-9]{12}$/', $result );
    }

    public function test_generate_random_string_custom_length(): void {
        $result = AuthCodeService::generate_random_string( 20 );

        $this->assertSame( 20, strlen( $result ) );
    }

    public function test_generate_random_string_length_one(): void {
        $result = AuthCodeService::generate_random_string( 1 );

        $this->assertSame( 1, strlen( $result ) );
    }

    public function test_generate_random_string_length_zero_returns_empty(): void {
        $result = AuthCodeService::generate_random_string( 0 );

        $this->assertSame( '', $result );
    }

    public function test_generate_random_string_custom_chars(): void {
        $result = AuthCodeService::generate_random_string( 10, 'abc' );

        $this->assertSame( 10, strlen( $result ) );
        $this->assertMatchesRegularExpression( '/^[abc]{10}$/', $result );
    }

    public function test_generate_random_string_single_char_set(): void {
        $result = AuthCodeService::generate_random_string( 5, 'X' );

        $this->assertSame( 'XXXXX', $result );
    }

    public function test_generate_random_string_digits_only_chars(): void {
        $result = AuthCodeService::generate_random_string( 8, '0123456789' );

        $this->assertMatchesRegularExpression( '/^[0-9]{8}$/', $result );
    }

    public function test_generate_random_string_empty_chars_throws_on_nonzero_length(): void {
        // When chars is empty, strlen($chars) = 0, so the loop body attempts
        // wp_rand(0, -1) which causes a ValueError from random_int.
        // This is an edge case callers should avoid.
        $this->expectException( \ValueError::class );

        AuthCodeService::generate_random_string( 3, '' );
    }

    public function test_generate_random_string_empty_chars_with_zero_length_returns_empty(): void {
        // When both chars and length are empty/zero, the loop never runs
        $result = AuthCodeService::generate_random_string( 0, '' );

        $this->assertSame( '', $result );
    }

    // ==================================================================
    // generate_auth_code
    // ==================================================================

    public function test_generate_auth_code_matches_format(): void {
        $result = AuthCodeService::generate_auth_code();

        $this->assertMatchesRegularExpression(
            '/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/',
            $result,
            'Auth code must match XXXX-XXXX-XXXX format'
        );
    }

    public function test_generate_auth_code_is_all_uppercase(): void {
        $result = AuthCodeService::generate_auth_code();

        $this->assertSame( $result, strtoupper( $result ) );
    }

    public function test_generate_auth_code_total_length_is_14(): void {
        $result = AuthCodeService::generate_auth_code();

        // 4 + 1 (dash) + 4 + 1 (dash) + 4 = 14
        $this->assertSame( 14, strlen( $result ) );
    }

    public function test_generate_auth_code_contains_exactly_two_dashes(): void {
        $result = AuthCodeService::generate_auth_code();

        $this->assertSame( 2, substr_count( $result, '-' ) );
    }

    public function test_generate_auth_code_produces_different_codes(): void {
        $codes = [];
        for ( $i = 0; $i < 10; $i++ ) {
            $codes[] = AuthCodeService::generate_auth_code();
        }

        // With 36^12 possible codes, 10 random codes should all be unique
        $this->assertCount( 10, array_unique( $codes ), 'Expected 10 unique codes from 10 generations' );
    }

    // ==================================================================
    // generate_globally_unique_auth_code — first attempt succeeds
    // ==================================================================

    public function test_globally_unique_auth_code_returns_clean_code_on_first_attempt(): void {
        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';

        // All three tables return null (no collision)
        $wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
        $wpdb->shouldReceive( 'get_var' )
            ->times( 3 )
            ->andReturn( null, null, null );

        $result = AuthCodeService::generate_globally_unique_auth_code();

        // clean_auth_code strips dashes and prefixes, returning 12-char uppercase alphanumeric
        $this->assertSame( 12, strlen( $result ) );
        $this->assertMatchesRegularExpression( '/^[A-Z0-9]{12}$/', $result );
    }

    // ==================================================================
    // generate_globally_unique_auth_code — collision on first table
    // ==================================================================

    public function test_globally_unique_auth_code_retries_on_first_table_collision(): void {
        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );

        // First attempt: collision on ffc_submissions (returns an id)
        // Second attempt: all three tables clear
        $wpdb->shouldReceive( 'get_var' )
            ->times( 4 ) // 1 (collision) + 3 (success)
            ->andReturn(
                '1',         // 1st attempt: ffc_submissions has collision -> continue
                null,        // 2nd attempt: ffc_submissions clear
                null,        // 2nd attempt: ffc_reregistration_submissions clear
                null         // 2nd attempt: ffc_self_scheduling_appointments clear
            );

        $result = AuthCodeService::generate_globally_unique_auth_code();

        $this->assertSame( 12, strlen( $result ) );
        $this->assertMatchesRegularExpression( '/^[A-Z0-9]{12}$/', $result );
    }

    // ==================================================================
    // generate_globally_unique_auth_code — collision on second table
    // ==================================================================

    public function test_globally_unique_auth_code_retries_on_second_table_collision(): void {
        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );

        // First attempt: ffc_submissions clear, ffc_reregistration_submissions collision
        // Second attempt: all clear
        $wpdb->shouldReceive( 'get_var' )
            ->times( 5 ) // 2 (1st attempt) + 3 (2nd attempt)
            ->andReturn(
                null,        // 1st attempt: ffc_submissions clear
                '1',         // 1st attempt: ffc_reregistration_submissions collision -> continue
                null,        // 2nd attempt: ffc_submissions clear
                null,        // 2nd attempt: ffc_reregistration_submissions clear
                null         // 2nd attempt: ffc_self_scheduling_appointments clear
            );

        $result = AuthCodeService::generate_globally_unique_auth_code();

        $this->assertSame( 12, strlen( $result ) );
        $this->assertMatchesRegularExpression( '/^[A-Z0-9]{12}$/', $result );
    }

    // ==================================================================
    // generate_globally_unique_auth_code — collision on third table
    // ==================================================================

    public function test_globally_unique_auth_code_retries_on_third_table_collision(): void {
        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );

        // First attempt: first two tables clear, third table collision
        // Second attempt: all clear
        $wpdb->shouldReceive( 'get_var' )
            ->times( 6 ) // 3 (1st attempt) + 3 (2nd attempt)
            ->andReturn(
                null,        // 1st attempt: ffc_submissions clear
                null,        // 1st attempt: ffc_reregistration_submissions clear
                '1',         // 1st attempt: ffc_self_scheduling_appointments collision -> continue
                null,        // 2nd attempt: ffc_submissions clear
                null,        // 2nd attempt: ffc_reregistration_submissions clear
                null         // 2nd attempt: ffc_self_scheduling_appointments clear
            );

        $result = AuthCodeService::generate_globally_unique_auth_code();

        $this->assertSame( 12, strlen( $result ) );
        $this->assertMatchesRegularExpression( '/^[A-Z0-9]{12}$/', $result );
    }

    // ==================================================================
    // generate_globally_unique_auth_code — all attempts collide (fallback)
    // ==================================================================

    public function test_globally_unique_auth_code_falls_back_after_max_attempts(): void {
        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );

        // All 10 attempts collide on ffc_submissions
        $wpdb->shouldReceive( 'get_var' )
            ->times( 10 )
            ->andReturn( '1' ); // always collides on first table check

        $result = AuthCodeService::generate_globally_unique_auth_code();

        // Fallback still returns a valid clean auth code
        $this->assertSame( 12, strlen( $result ) );
        $this->assertMatchesRegularExpression( '/^[A-Z0-9]{12}$/', $result );
    }

    // ==================================================================
    // generate_globally_unique_auth_code — verify all 3 tables checked
    // ==================================================================

    public function test_globally_unique_auth_code_checks_all_three_tables(): void {
        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';

        $prepared_queries = [];

        $wpdb->shouldReceive( 'prepare' )
            ->times( 3 )
            ->andReturnUsing( function ( $query, $table, $code ) use ( &$prepared_queries ) {
                $prepared_queries[] = $table;
                return "SELECT id FROM {$table} WHERE auth_code = '{$code}' LIMIT 1";
            } );

        $wpdb->shouldReceive( 'get_var' )
            ->times( 3 )
            ->andReturn( null, null, null );

        AuthCodeService::generate_globally_unique_auth_code();

        $this->assertContains( 'wp_ffc_submissions', $prepared_queries );
        $this->assertContains( 'wp_ffc_reregistration_submissions', $prepared_queries );
        $this->assertContains( 'wp_ffc_self_scheduling_appointments', $prepared_queries );
    }

    // ==================================================================
    // generate_globally_unique_auth_code — result has no dashes
    // ==================================================================

    public function test_globally_unique_auth_code_returns_code_without_dashes(): void {
        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';

        $wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
        $wpdb->shouldReceive( 'get_var' )
            ->times( 3 )
            ->andReturn( null, null, null );

        $result = AuthCodeService::generate_globally_unique_auth_code();

        $this->assertStringNotContainsString( '-', $result );
    }
}

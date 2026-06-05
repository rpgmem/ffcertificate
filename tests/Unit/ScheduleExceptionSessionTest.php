<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\ScheduleExceptionSession;

/**
 * Tests for ScheduleExceptionSession — the operator → participant
 * bridge built in Sprint 3 of #366.
 *
 * @covers \FreeFormCertificate\Frontend\ScheduleExceptionSession
 */
class ScheduleExceptionSessionTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /**
     * Captured arguments of every `setcookie()` call made during the
     * current test. Tests inspect this to verify cookie attributes
     * without poking at PHP's hidden header buffer.
     *
     * @var array<int, array{name: string, value: string, options: array<string, mixed>}>
     */
    private array $cookie_calls = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( 'wp_salt' )->justReturn( 'test-nonce-salt' );
        Functions\when( 'is_ssl' )->justReturn( false );
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );

        $this->cookie_calls = array();
        Functions\when( 'setcookie' )->alias(
            function ( string $name, string $value = '', $options = array() ): bool {
                $this->cookie_calls[] = array(
                    'name'    => $name,
                    'value'   => $value,
                    'options' => is_array( $options ) ? $options : array( 'expires' => $options ),
                );
                return true;
            }
        );

        $_COOKIE = array();
    }

    protected function tearDown(): void {
        $_COOKIE = array();
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // sign_token() / verify_token() — pure crypto round-trip
    // ==================================================================

    public function test_sign_and_verify_round_trip_recovers_payload(): void {
        $payload = array(
            'v'                 => 1,
            'form_id'           => 42,
            'start'             => '08:00',
            'end'               => '17:30',
            'operator_cpf_hash' => str_repeat( 'a', 64 ),
            'exp'               => time() + 600,
            'jti'               => 'deadbeefcafebabe',
        );

        $token   = ScheduleExceptionSession::sign_token( $payload );
        $decoded = ScheduleExceptionSession::verify_token( $token );

        $this->assertSame( $payload, $decoded );
    }

    public function test_verify_rejects_tampered_payload(): void {
        $payload = array(
            'v'                 => 1,
            'form_id'           => 42,
            'start'             => '08:00',
            'end'               => '17:30',
            'operator_cpf_hash' => str_repeat( 'a', 64 ),
            'exp'               => time() + 600,
            'jti'               => 'deadbeefcafebabe',
        );

        $token = ScheduleExceptionSession::sign_token( $payload );
        // Flip a character in the body half — attacker rewrites the
        // override end time, leaves the original signature alone.
        $tampered = 'X' . substr( $token, 1 );

        $this->assertNull( ScheduleExceptionSession::verify_token( $tampered ) );
    }

    public function test_verify_rejects_tampered_signature(): void {
        $payload = array(
            'v'                 => 1,
            'form_id'           => 42,
            'start'             => '08:00',
            'end'               => '17:30',
            'operator_cpf_hash' => str_repeat( 'a', 64 ),
            'exp'               => time() + 600,
            'jti'               => 'cafe',
        );

        $token  = ScheduleExceptionSession::sign_token( $payload );
        $parts  = explode( '.', $token, 2 );
        // Replace signature with garbage of the same length.
        $broken = $parts[0] . '.' . str_repeat( 'A', strlen( $parts[1] ) );

        $this->assertNull( ScheduleExceptionSession::verify_token( $broken ) );
    }

    public function test_verify_rejects_expired_token(): void {
        $payload = array(
            'v'                 => 1,
            'form_id'           => 42,
            'start'             => null,
            'end'               => null,
            'operator_cpf_hash' => str_repeat( 'b', 64 ),
            'exp'               => time() - 1, // expired by 1s
            'jti'               => 'a',
        );

        $token = ScheduleExceptionSession::sign_token( $payload );

        $this->assertNull(
            ScheduleExceptionSession::verify_token( $token ),
            'expired tokens must fail verification regardless of signature validity'
        );
    }

    public function test_verify_rejects_wrong_version(): void {
        $payload = array(
            'v'                 => 99,
            'form_id'           => 42,
            'start'             => null,
            'end'               => null,
            'operator_cpf_hash' => str_repeat( 'c', 64 ),
            'exp'               => time() + 600,
            'jti'               => 'a',
        );

        $token = ScheduleExceptionSession::sign_token( $payload );

        $this->assertNull(
            ScheduleExceptionSession::verify_token( $token ),
            'unknown token-format versions must be rejected to avoid silent mis-parsing after a future schema change'
        );
    }

    public function test_verify_rejects_malformed_token(): void {
        $this->assertNull( ScheduleExceptionSession::verify_token( '' ) );
        $this->assertNull( ScheduleExceptionSession::verify_token( 'no-dot-separator' ) );
        $this->assertNull( ScheduleExceptionSession::verify_token( '!!!.zzz' ) );
    }

    // ==================================================================
    // create() — cookie attributes
    // ==================================================================

    public function test_create_sets_cookie_with_httponly_samesite_and_ttl(): void {
        $token = ScheduleExceptionSession::create( 42, '08:00', '17:30', str_repeat( 'a', 64 ) );

        $this->assertCount( 1, $this->cookie_calls );
        $call = $this->cookie_calls[0];

        $this->assertSame( 'ffc_exception_42', $call['name'] );
        $this->assertSame( $token, $call['value'], 'cookie carries the same token returned to the caller' );
        $this->assertTrue( $call['options']['httponly'] );
        $this->assertSame( 'Lax', $call['options']['samesite'] );
        $this->assertSame( '/', $call['options']['path'] );
        $this->assertFalse( $call['options']['secure'] ); // is_ssl() stubbed false
        $this->assertGreaterThanOrEqual( time() + ScheduleExceptionSession::TTL_SECONDS - 5, $call['options']['expires'] );
        $this->assertLessThanOrEqual( time() + ScheduleExceptionSession::TTL_SECONDS + 5, $call['options']['expires'] );
    }

    public function test_create_returns_a_token_that_verifies_against_itself(): void {
        $token = ScheduleExceptionSession::create( 42, '08:00', '17:30', str_repeat( 'a', 64 ) );

        $payload = ScheduleExceptionSession::verify_token( $token );

        $this->assertNotNull( $payload );
        $this->assertSame( 42, $payload['form_id'] );
        $this->assertSame( '08:00', $payload['start'] );
        $this->assertSame( '17:30', $payload['end'] );
        $this->assertSame( str_repeat( 'a', 64 ), $payload['operator_cpf_hash'] );
    }

    public function test_create_sets_secure_attribute_when_ssl_is_active(): void {
        Functions\when( 'is_ssl' )->justReturn( true );

        ScheduleExceptionSession::create( 7, null, null, str_repeat( 'd', 64 ) );

        $this->assertTrue( $this->cookie_calls[0]['options']['secure'] );
    }

    // ==================================================================
    // read_from_cookie() — full flow
    // ==================================================================

    public function test_read_from_cookie_returns_payload_for_matching_form(): void {
        $token              = ScheduleExceptionSession::create( 42, '08:00', '17:30', str_repeat( 'a', 64 ) );
        $_COOKIE['ffc_exception_42'] = $token;

        $payload = ScheduleExceptionSession::read_from_cookie( 42 );

        $this->assertNotNull( $payload );
        $this->assertSame( '08:00', $payload['start'] );
        $this->assertSame( '17:30', $payload['end'] );
    }

    public function test_read_from_cookie_returns_null_when_no_cookie(): void {
        $this->assertNull( ScheduleExceptionSession::read_from_cookie( 42 ) );
    }

    public function test_read_from_cookie_rejects_cookie_scoped_to_other_form(): void {
        $token                       = ScheduleExceptionSession::create( 99, '08:00', '17:30', str_repeat( 'a', 64 ) );
        // Operator-issued cookie for form 99, but the render path is for form 42.
        $_COOKIE['ffc_exception_42'] = $token;

        $this->assertNull(
            ScheduleExceptionSession::read_from_cookie( 42 ),
            'a signature-valid token whose embedded form_id mismatches the read context must be rejected'
        );
    }

    // ==================================================================
    // clear()
    // ==================================================================

    public function test_clear_emits_expiring_cookie_and_unsets_php_superglobal(): void {
        $_COOKIE['ffc_exception_42'] = 'whatever';

        ScheduleExceptionSession::clear( 42 );

        $this->assertCount( 1, $this->cookie_calls );
        $call = $this->cookie_calls[0];

        $this->assertSame( 'ffc_exception_42', $call['name'] );
        $this->assertSame( '', $call['value'] );
        $this->assertLessThan( time(), $call['options']['expires'], 'expiry must be in the past so the browser deletes the cookie' );
        $this->assertArrayNotHasKey( 'ffc_exception_42', $_COOKIE );
    }

    public function test_cookie_name_includes_form_id_for_scoping(): void {
        $this->assertSame( 'ffc_exception_1', ScheduleExceptionSession::cookie_name( 1 ) );
        $this->assertSame( 'ffc_exception_999', ScheduleExceptionSession::cookie_name( 999 ) );
    }

    // ==================================================================
    // Consumed-jti ledger — atomic single-use (#Item11)
    // ==================================================================

    /**
     * Wire a $wpdb whose INSERT IGNORE reports `$rows_affected` rows, so
     * try_consume_jti() can be driven deterministically.
     *
     * @param int $rows_affected Value the mocked query sets on the wpdb.
     * @return \Mockery\MockInterface
     */
    private function mock_wpdb_insert( int $rows_affected ) {
        global $wpdb;
        $wpdb                = \Mockery::mock( 'wpdb' );
        $wpdb->options       = 'wp_options';
        $wpdb->rows_affected = 0;
        $wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
        $wpdb->shouldReceive( 'query' )->andReturnUsing(
            function () use ( $wpdb, $rows_affected ) {
                $wpdb->rows_affected = $rows_affected;
                return $rows_affected;
            }
        );
        return $wpdb;
    }

    public function test_try_consume_jti_returns_true_when_insert_wins(): void {
        $this->mock_wpdb_insert( 1 );
        $this->assertTrue( ScheduleExceptionSession::try_consume_jti( 'abc123', time() + 1800 ) );
    }

    public function test_try_consume_jti_returns_false_on_replay(): void {
        // INSERT IGNORE affects 0 rows when the jti was already claimed.
        $this->mock_wpdb_insert( 0 );
        $this->assertFalse( ScheduleExceptionSession::try_consume_jti( 'abc123', time() + 1800 ) );
    }

    public function test_try_consume_jti_rejects_empty_jti_without_touching_db(): void {
        global $wpdb;
        $wpdb = \Mockery::mock( 'wpdb' );
        $wpdb->shouldNotReceive( 'query' );
        $this->assertFalse( ScheduleExceptionSession::try_consume_jti( '', time() + 1800 ) );
    }

    public function test_is_jti_consumed_true_when_marker_present(): void {
        Functions\when( 'get_option' )->alias(
            static fn( $key, $default = false ) => 'ffc_sched_exc_used_spent' === $key ? '123' : $default
        );
        $this->assertTrue( ScheduleExceptionSession::is_jti_consumed( 'spent' ) );
    }

    public function test_is_jti_consumed_false_when_marker_absent(): void {
        Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => $default );
        $this->assertFalse( ScheduleExceptionSession::is_jti_consumed( 'fresh' ) );
    }

    public function test_is_jti_consumed_false_for_empty_jti(): void {
        // No get_option needed — short-circuits on the empty guard.
        $this->assertFalse( ScheduleExceptionSession::is_jti_consumed( '' ) );
    }

    public function test_cleanup_expired_consumed_deletes_only_stale_markers(): void {
        global $wpdb;
        $wpdb          = \Mockery::mock( 'wpdb' );
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $v ) => $v );
        $wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
        $wpdb->shouldReceive( 'get_col' )->andReturn(
            array( 'ffc_sched_exc_used_old1', 'ffc_sched_exc_used_old2' )
        );

        $deleted = array();
        Functions\when( 'delete_option' )->alias(
            function ( $name ) use ( &$deleted ) {
                $deleted[] = $name;
                return true;
            }
        );

        $count = ScheduleExceptionSession::cleanup_expired_consumed();

        $this->assertSame( 2, $count );
        $this->assertSame(
            array( 'ffc_sched_exc_used_old1', 'ffc_sched_exc_used_old2' ),
            $deleted
        );
    }

    public function test_cleanup_expired_consumed_no_op_when_nothing_stale(): void {
        global $wpdb;
        $wpdb          = \Mockery::mock( 'wpdb' );
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $v ) => $v );
        $wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
        $wpdb->shouldReceive( 'get_col' )->andReturn( array() );
        Functions\when( 'delete_option' )->justReturn( true );

        $this->assertSame( 0, ScheduleExceptionSession::cleanup_expired_consumed() );
    }
}

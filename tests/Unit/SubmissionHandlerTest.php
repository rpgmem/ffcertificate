<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Submissions\SubmissionHandler;
use FreeFormCertificate\Core\Encryption;

/**
 * Tests for SubmissionHandler: submission creation, encryption flow,
 * ticket_hash population, token handling, bulk operations.
 *
 * Note: WP crypto constants are defined in bootstrap.php, so
 * Encryption::is_configured() returns true — the encryption path
 * is always active.
 */
class SubmissionHandlerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private SubmissionHandler $handler;
    private $mockRepo;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Mock global $wpdb
        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';
        // Allow auth-code uniqueness check & orphan-linking calls
        $wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
        $wpdb->shouldReceive( 'get_var' )->andReturn( null );
        $wpdb->shouldReceive( 'query' )->andReturn( 0 );
        $wpdb->shouldReceive( 'get_results' )->andReturn( array() );

        // Common WP stubs
        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );
        Functions\when( 'wp_cache_delete' )->justReturn( true );
        Functions\when( 'current_time' )->justReturn( '2026-02-17 12:00:00' );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( '__' )->returnArg();
        Functions\when( 'do_action' )->justReturn( null );
        Functions\when( 'get_option' )->justReturn( 0 );
        Functions\when( 'absint' )->alias( function( $val ) { return abs( intval( $val ) ); } );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'sanitize_key' )->alias( function( $key ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) ); } );
        Functions\when( 'wp_rand' )->alias( function( $min = 0, $max = null ) { return rand( $min, $max ?? getrandmax() ); } );
        Functions\when( 'is_wp_error' )->alias( function( $thing ) { return $thing instanceof \WP_Error; } );
        Functions\when( 'apply_filters' )->alias( function() { $args = func_get_args(); return $args[1] ?? null; } );
        Functions\when( 'get_user_by' )->justReturn( false );
        Functions\when( 'get_userdata' )->justReturn( null );
        Functions\when( 'wp_generate_password' )->justReturn( 'TestPass123!' );
        Functions\when( 'sanitize_user' )->returnArg();
        Functions\when( 'remove_accents' )->returnArg();
        Functions\when( 'username_exists' )->justReturn( false );
        Functions\when( 'wp_create_user' )->justReturn( 100 );
        Functions\when( 'wp_update_user' )->justReturn( 100 );
        Functions\when( 'update_user_meta' )->justReturn( true );
        Functions\when( 'wp_new_user_notification' )->justReturn( null );

        $this->handler = new SubmissionHandler();

        // Replace the private repository with a mock via reflection
        $this->mockRepo = Mockery::mock( 'FreeFormCertificate\Repositories\SubmissionRepository' );
        $ref = new \ReflectionClass( $this->handler );
        $prop = $ref->getProperty( 'repository' );
        $prop->setAccessible( true );
        $prop->setValue( $this->handler, $this->mockRepo );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // get_repository()
    // ------------------------------------------------------------------

    public function test_get_repository_returns_repository_instance(): void {
        $repo = $this->handler->get_repository();
        $this->assertSame( $this->mockRepo, $repo );
    }

    // ------------------------------------------------------------------
    // process_submission() - basic flow
    // ------------------------------------------------------------------

    public function test_process_submission_returns_id_on_success(): void {
        $this->mockRepo->shouldReceive( 'insert' )
            ->once()
            ->andReturn( 42 );

        $data = array( 'name' => 'Test User', 'email' => 'test@example.com' );
        $result = $this->handler->process_submission(
            1,
            'Test Form',
            $data,
            'test@example.com',
            array(),
            array()
        );

        $this->assertSame( 42, $result );
    }

    public function test_process_submission_returns_wp_error_on_insert_failure(): void {
        $this->mockRepo->shouldReceive( 'insert' )
            ->once()
            ->andReturn( false );

        $data = array( 'name' => 'Test User' );
        $result = $this->handler->process_submission(
            1,
            'Test Form',
            $data,
            'test@example.com',
            array(),
            array()
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    public function test_process_submission_insert_data_includes_core_fields(): void {
        $captured = null;
        $this->mockRepo->shouldReceive( 'insert' )
            ->once()
            ->withArgs( function( $data ) use ( &$captured ) {
                $captured = $data;
                return true;
            } )
            ->andReturn( 10 );

        $data = array( 'name' => 'Alice', 'email' => 'alice@test.com', 'cpf_rf' => '12345678901' );
        $this->handler->process_submission( 5, 'My Form', $data, 'alice@test.com', array(), array() );

        $this->assertSame( 5, $captured['form_id'] );
        $this->assertSame( 'publish', $captured['status'] );
        $this->assertNotEmpty( $captured['auth_code'] );
        $this->assertNotEmpty( $captured['magic_token'] );
        $this->assertSame( '2026-02-17 12:00:00', $captured['submission_date'] );
    }

    public function test_process_submission_encrypts_email_when_configured(): void {
        $captured = null;
        $this->mockRepo->shouldReceive( 'insert' )
            ->once()
            ->withArgs( function( $data ) use ( &$captured ) {
                $captured = $data;
                return true;
            } )
            ->andReturn( 10 );

        $data = array( 'name' => 'Carol', 'email' => 'carol@test.com' );
        $this->handler->process_submission( 1, 'Form', $data, 'carol@test.com', array(), array() );

        // With encryption on (constants defined in bootstrap), plaintext is null,
        // encrypted/hash fields are populated.
        $this->assertNull( $captured['email'] );
        $this->assertNotNull( $captured['email_encrypted'] );
        $this->assertNotNull( $captured['email_hash'] );
        $this->assertSame( 64, strlen( $captured['email_hash'] ) );
    }

    public function test_process_submission_populates_ticket_hash_when_ticket_present(): void {
        $captured = null;
        $this->mockRepo->shouldReceive( 'insert' )
            ->once()
            ->withArgs( function( $data ) use ( &$captured ) {
                $captured = $data;
                return true;
            } )
            ->andReturn( 10 );

        $data = array( 'name' => 'Dave', 'email' => 'dave@test.com', 'ticket' => 'ABC123' );
        $this->handler->process_submission( 1, 'Form', $data, 'dave@test.com', array(), array() );

        $this->assertNotNull( $captured['ticket_hash'] );
        $this->assertSame( 64, strlen( $captured['ticket_hash'] ) );
    }

    public function test_process_submission_ticket_hash_null_without_ticket(): void {
        $captured = null;
        $this->mockRepo->shouldReceive( 'insert' )
            ->once()
            ->withArgs( function( $data ) use ( &$captured ) {
                $captured = $data;
                return true;
            } )
            ->andReturn( 10 );

        $data = array( 'name' => 'Eve', 'email' => 'eve@test.com' );
        $this->handler->process_submission( 1, 'Form', $data, 'eve@test.com', array(), array() );

        $this->assertNull( $captured['ticket_hash'] );
    }

    public function test_process_submission_consent_fields_set_when_given(): void {
        $captured = null;
        $this->mockRepo->shouldReceive( 'insert' )
            ->once()
            ->withArgs( function( $data ) use ( &$captured ) {
                $captured = $data;
                return true;
            } )
            ->andReturn( 10 );

        $data = array( 'name' => 'Consent User', 'ffc_lgpd_consent' => '1' );
        $this->handler->process_submission( 1, 'Form', $data, 'c@test.com', array(), array() );

        $this->assertSame( 1, $captured['consent_given'] );
        $this->assertSame( '2026-02-17 12:00:00', $captured['consent_date'] );
    }

    public function test_process_submission_data_field_encrypted(): void {
        $captured = null;
        $this->mockRepo->shouldReceive( 'insert' )
            ->once()
            ->withArgs( function( $data ) use ( &$captured ) {
                $captured = $data;
                return true;
            } )
            ->andReturn( 10 );

        $data = array( 'name' => 'Encrypted User' );
        $this->handler->process_submission( 1, 'Form', $data, 'e@test.com', array(), array() );

        // With encryption on, the plaintext data column is null and data_encrypted is set
        $this->assertNull( $captured['data'] );
        $this->assertNotNull( $captured['data_encrypted'] );
    }

    // ------------------------------------------------------------------
    // get_submission()
    // ------------------------------------------------------------------

    public function test_get_submission_returns_decrypted_data(): void {
        $this->mockRepo->shouldReceive( 'findById' )
            ->with( 5 )
            ->once()
            ->andReturn( array(
                'id'               => 5,
                'email'            => 'plain@test.com',
                'email_encrypted'  => null,
                'cpf_rf'           => '12345678901',
                'cpf_rf_encrypted' => null,
                'user_ip'          => '127.0.0.1',
                'user_ip_encrypted'=> null,
                'data'             => '{"name":"Test"}',
                'data_encrypted'   => null,
            ) );

        $result = $this->handler->get_submission( 5 );

        $this->assertIsArray( $result );
        $this->assertSame( 'plain@test.com', $result['email'] );
    }

    public function test_get_submission_returns_null_for_missing(): void {
        $this->mockRepo->shouldReceive( 'findById' )
            ->with( 999 )
            ->once()
            ->andReturn( null );

        $result = $this->handler->get_submission( 999 );
        $this->assertNull( $result );
    }

    // ------------------------------------------------------------------
    // get_submission_by_token()
    // ------------------------------------------------------------------

    public function test_get_submission_by_token_validates_length(): void {
        // Token too short — should return null without hitting repo
        $result = $this->handler->get_submission_by_token( 'short' );
        $this->assertNull( $result );
    }

    public function test_get_submission_by_token_finds_valid_token(): void {
        $token = str_repeat( 'a1', 16 ); // 32-char hex
        $this->mockRepo->shouldReceive( 'findByToken' )
            ->with( $token )
            ->once()
            ->andReturn( array(
                'id' => 7, 'email' => 'tok@test.com', 'email_encrypted' => null,
                'cpf_rf' => null, 'cpf_rf_encrypted' => null,
                'user_ip' => null, 'user_ip_encrypted' => null,
                'data' => '{}', 'data_encrypted' => null,
            ) );

        $result = $this->handler->get_submission_by_token( $token );
        $this->assertIsArray( $result );
        $this->assertSame( 7, $result['id'] );
    }

    // ------------------------------------------------------------------
    // trash / restore / delete
    // ------------------------------------------------------------------

    public function test_trash_submission_calls_update_status(): void {
        $this->mockRepo->shouldReceive( 'updateStatus' )
            ->with( 3, 'trash' )
            ->once()
            ->andReturn( 1 );

        $result = $this->handler->trash_submission( 3 );
        $this->assertTrue( $result );
    }

    public function test_restore_submission_calls_update_status(): void {
        $this->mockRepo->shouldReceive( 'updateStatus' )
            ->with( 3, 'publish' )
            ->once()
            ->andReturn( 1 );

        $result = $this->handler->restore_submission( 3 );
        $this->assertTrue( $result );
    }

    public function test_delete_submission_calls_repo_delete(): void {
        $this->mockRepo->shouldReceive( 'delete' )
            ->with( 8 )
            ->once()
            ->andReturn( 1 );

        $result = $this->handler->delete_submission( 8 );
        $this->assertTrue( $result );
    }

    // ------------------------------------------------------------------
    // Bulk operations
    // ------------------------------------------------------------------

    public function test_bulk_trash_disables_logging_during_operation(): void {
        $this->mockRepo->shouldReceive( 'bulkUpdateStatus' )
            ->with( array( 1, 2, 3 ), 'trash' )
            ->once()
            ->andReturn( 3 );

        $result = $this->handler->bulk_trash_submissions( array( 1, 2, 3 ) );
        $this->assertSame( 3, $result );
    }

    public function test_bulk_restore_submissions(): void {
        $this->mockRepo->shouldReceive( 'bulkUpdateStatus' )
            ->with( array( 4, 5 ), 'publish' )
            ->once()
            ->andReturn( 2 );

        $result = $this->handler->bulk_restore_submissions( array( 4, 5 ) );
        $this->assertSame( 2, $result );
    }

    public function test_bulk_delete_submissions(): void {
        $this->mockRepo->shouldReceive( 'bulkDelete' )
            ->with( array( 6, 7 ) )
            ->once()
            ->andReturn( 2 );

        $result = $this->handler->bulk_delete_submissions( array( 6, 7 ) );
        $this->assertSame( 2, $result );
    }

    // ------------------------------------------------------------------
    // ensure_magic_token()
    // ------------------------------------------------------------------

    public function test_ensure_magic_token_returns_existing_token(): void {
        $this->mockRepo->shouldReceive( 'findById' )
            ->with( 10 )
            ->once()
            ->andReturn( array( 'magic_token' => 'abcdef1234567890abcdef1234567890' ) );

        $token = $this->handler->ensure_magic_token( 10 );
        $this->assertSame( 'abcdef1234567890abcdef1234567890', $token );
    }

    public function test_ensure_magic_token_generates_new_when_missing(): void {
        $this->mockRepo->shouldReceive( 'findById' )
            ->with( 11 )
            ->once()
            ->andReturn( array( 'magic_token' => null ) );

        $this->mockRepo->shouldReceive( 'update' )
            ->once()
            ->andReturn( 1 );

        $token = $this->handler->ensure_magic_token( 11 );
        $this->assertSame( 32, strlen( $token ) );
        $this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $token );
    }
}

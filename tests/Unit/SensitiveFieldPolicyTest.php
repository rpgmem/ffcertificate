<?php
/**
 * Characterization tests for the "sensitive field" policy across write paths.
 *
 * These tests pin the contract that Phase 1 (SensitiveFieldRegistry) must
 * preserve: the set of fields each repository/handler treats as sensitive
 * (encrypted, optionally hashed for lookup) on write.
 *
 * The scope is intentionally narrow: each test asserts which columns are
 * populated with encrypted/hash values and which plaintext columns are
 * dropped. It does NOT re-test encryption primitives (see EncryptionTest).
 *
 * Sites covered:
 *   - FreeFormCertificate\Submissions\SubmissionHandler::process_submission
 *   - FreeFormCertificate\Repositories\AppointmentRepository::createAppointment
 *
 * Not covered here (evaluated separately): ReregistrationDataProcessor uses
 * per-field is_sensitive flags read from wp_ffc_custom_fields at runtime;
 * its contract is pinned in ReregistrationDataProcessorTest by field
 * definition rather than hard-coded list.
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Encryption;
use FreeFormCertificate\Repositories\AppointmentRepository;
use FreeFormCertificate\Submissions\SubmissionHandler;

/**
 * @coversNothing
 * @runClassInSeparateProcess
 * @preserveGlobalState disabled
 */
class SensitiveFieldPolicyTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var Mockery\MockInterface */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';
        $wpdb->insert_id = 0;
        $this->wpdb = $wpdb;

        $wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () {
            return func_get_args()[0];
        } )->byDefault();
        $wpdb->shouldReceive( 'get_var' )->andReturn( null )->byDefault();
        $wpdb->shouldReceive( 'get_results' )->andReturn( array() )->byDefault();
        $wpdb->shouldReceive( 'query' )->andReturn( 0 )->byDefault();

        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );
        Functions\when( 'wp_cache_delete' )->justReturn( true );
        Functions\when( 'wp_cache_flush' )->justReturn( true );
        Functions\when( 'current_time' )->justReturn( '2026-05-01 12:00:00' );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( '__' )->returnArg();
        Functions\when( 'do_action' )->justReturn( null );
        Functions\when( 'get_option' )->justReturn( 0 );
        Functions\when( 'absint' )->alias( function ( $val ) {
            return abs( intval( $val ) );
        } );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'sanitize_key' )->alias( function ( $k ) {
            return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $k ) );
        } );
        Functions\when( 'wp_rand' )->alias( function ( $min = 0, $max = null ) {
            return random_int( $min, $max ?? PHP_INT_MAX );
        } );
        Functions\when( 'is_wp_error' )->alias( function ( $t ) {
            return $t instanceof \WP_Error;
        } );
        Functions\when( 'apply_filters' )->alias( function () {
            $a = func_get_args();
            return $a[1] ?? null;
        } );
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

        Functions\when( 'FreeFormCertificate\Core\get_option' )->alias( function ( $k, $d = false ) {
            return \get_option( $k, $d );
        } );
        Functions\when( 'FreeFormCertificate\Core\get_current_user_id' )->alias( function () {
            return \get_current_user_id();
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // SubmissionHandler::process_submission
    // ==================================================================

    /**
     * The set of columns the submission write path must populate with
     * encrypted / hashed values is the "sensitive field contract" of
     * SubmissionHandler. Centralizing policy must not change this set.
     */
    public function test_submission_handler_encrypts_and_hashes_expected_columns(): void {
        $handler  = new SubmissionHandler();
        $mockRepo = Mockery::mock( 'FreeFormCertificate\\Repositories\\SubmissionRepository' );
        $ref      = new \ReflectionClass( $handler );
        $prop     = $ref->getProperty( 'repository' );
        $prop->setAccessible( true );
        $prop->setValue( $handler, $mockRepo );

        $captured = null;
        $mockRepo->shouldReceive( 'insert' )
            ->once()
            ->withArgs( function ( $data ) use ( &$captured ) {
                $captured = $data;
                return true;
            } )
            ->andReturn( 42 );

        $data = array(
            'name'     => 'Carol',
            'email'    => 'carol@test.com',
            'cpf_rf'   => '12345678901',
            'ticket'   => 'ABC-123',
            'extra1'   => 'payload',
        );
        $handler->process_submission( 1, 'Form', $data, 'carol@test.com', array(), array() );

        $this->assertIsArray( $captured );

        // Encrypted / hashed columns that must be populated on any sensitive
        // write. Adding or removing a key here is a behavior change that the
        // registry refactor must be deliberate about.
        $this->assertNotNull( $captured['email_encrypted'], 'email must be encrypted' );
        $this->assertNotNull( $captured['email_hash'], 'email must have a lookup hash' );
        $this->assertNotNull( $captured['cpf_encrypted'], 'cpf must be encrypted' );
        $this->assertNotNull( $captured['cpf_hash'], 'cpf must have a lookup hash' );
        $this->assertNotNull( $captured['ticket_hash'], 'ticket must have a lookup hash' );
        $this->assertNotNull( $captured['data_encrypted'], 'extra form data must be encrypted' );

        // Hash length must match Encryption::hash (SHA-256 hex).
        $this->assertSame( 64, strlen( $captured['email_hash'] ) );
        $this->assertSame( 64, strlen( $captured['cpf_hash'] ) );
        $this->assertSame( 64, strlen( $captured['ticket_hash'] ) );
    }

    /**
     * Seven-digit cpf_rf values route to rf_* columns instead of cpf_*. This
     * split is policy, not a detail — preserve it through the refactor.
     */
    public function test_submission_handler_seven_digit_identifier_routes_to_rf_columns(): void {
        $handler  = new SubmissionHandler();
        $mockRepo = Mockery::mock( 'FreeFormCertificate\\Repositories\\SubmissionRepository' );
        $ref      = new \ReflectionClass( $handler );
        $prop     = $ref->getProperty( 'repository' );
        $prop->setAccessible( true );
        $prop->setValue( $handler, $mockRepo );

        $captured = null;
        $mockRepo->shouldReceive( 'insert' )
            ->once()
            ->withArgs( function ( $data ) use ( &$captured ) {
                $captured = $data;
                return true;
            } )
            ->andReturn( 43 );

        $submission = array( 'email' => 'x@y.z', 'cpf_rf' => '1234567' );
        $handler->process_submission(
            1,
            'Form',
            $submission,
            'x@y.z',
            array(),
            array()
        );

        $this->assertNotNull( $captured['rf_encrypted'] );
        $this->assertNotNull( $captured['rf_hash'] );
        $this->assertNull( $captured['cpf_encrypted'] );
        $this->assertNull( $captured['cpf_hash'] );
    }

    /**
     * Verifies that the hash stored is the *exact* output of Encryption::hash
     * on the plaintext value — not a raw sha256, not a normalized variant.
     * This invariant is what PR #55 established and it must survive the
     * registry refactor.
     */
    public function test_submission_handler_hash_matches_encryption_hash_of_plaintext(): void {
        $handler  = new SubmissionHandler();
        $mockRepo = Mockery::mock( 'FreeFormCertificate\\Repositories\\SubmissionRepository' );
        $ref      = new \ReflectionClass( $handler );
        $prop     = $ref->getProperty( 'repository' );
        $prop->setAccessible( true );
        $prop->setValue( $handler, $mockRepo );

        $captured = null;
        $mockRepo->shouldReceive( 'insert' )
            ->once()
            ->withArgs( function ( $data ) use ( &$captured ) {
                $captured = $data;
                return true;
            } )
            ->andReturn( 44 );

        $email = 'John@Example.com';
        $cpf   = '12345678901';
        $submission = array( 'email' => $email, 'cpf_rf' => $cpf );
        $handler->process_submission( 1, 'F', $submission, $email, array(), array() );

        $this->assertSame( Encryption::hash( $email ), $captured['email_hash'] );
        $this->assertSame( Encryption::hash( $cpf ), $captured['cpf_hash'] );
    }

    // ==================================================================
    // AppointmentRepository::createAppointment
    // ==================================================================

    /**
     * Appointments encrypt a slightly different set of fields (adds phone
     * and custom_data; no ticket). Pinning the exact set so the refactor
     * can declare each one in the registry without losing coverage.
     */
    public function test_appointment_repository_encrypts_and_hashes_expected_columns(): void {
        $repo = new AppointmentRepository();

        $captured = null;
        $this->wpdb->shouldReceive( 'insert' )
            ->once()
            ->andReturnUsing( function ( $table, $data ) use ( &$captured ) {
                $captured = $data;
                return 1;
            } );
        $this->wpdb->insert_id = 1;

        $repo->createAppointment( array(
            'calendar_id'        => 5,
            'appointment_date'   => '2026-05-10',
            'start_time'         => '09:00:00',
            'status'             => 'pending',
            'confirmation_token' => 'tok-123',
            'validation_code'    => 'VAL1234567AB',
            'email'              => 'alice@example.com',
            'cpf_rf'             => '12345678901',
            'phone'              => '5511999990000',
            'custom_data'        => array( 'notes' => 'vip' ),
            'user_ip'            => '192.0.2.1',
        ) );

        $this->assertIsArray( $captured );

        // Encrypted columns.
        $this->assertNotNull( $captured['email_encrypted'] );
        $this->assertNotNull( $captured['cpf_encrypted'] );
        $this->assertNotNull( $captured['phone_encrypted'] );
        $this->assertNotNull( $captured['custom_data_encrypted'] );
        $this->assertNotNull( $captured['user_ip_encrypted'] );

        // Hash columns: email, cpf, rf only (phone/custom_data/user_ip are
        // encrypted-only — no lookup hash today).
        $this->assertNotNull( $captured['email_hash'] );
        $this->assertNotNull( $captured['cpf_hash'] );
        $this->assertArrayNotHasKey( 'phone_hash', $captured );
        $this->assertArrayNotHasKey( 'custom_data_hash', $captured );
        $this->assertArrayNotHasKey( 'user_ip_hash', $captured );

        // Plaintext columns must be stripped.
        $this->assertArrayNotHasKey( 'email', $captured );
        $this->assertArrayNotHasKey( 'cpf_rf', $captured );
        $this->assertArrayNotHasKey( 'phone', $captured );
        $this->assertArrayNotHasKey( 'custom_data', $captured );
        $this->assertArrayNotHasKey( 'user_ip', $captured );
    }

    /**
     * Appointment findByCpfRf / findByEmail build the lookup hash through
     * Encryption::hash. A row created by createAppointment must therefore
     * store a hash computed the same way. This was the exact regression
     * PR #55 fixed; lock it in.
     */
    public function test_appointment_repository_hashes_match_encryption_hash_of_plaintext(): void {
        $repo = new AppointmentRepository();

        $captured = null;
        $this->wpdb->shouldReceive( 'insert' )
            ->once()
            ->andReturnUsing( function ( $table, $data ) use ( &$captured ) {
                $captured = $data;
                return 1;
            } );
        $this->wpdb->insert_id = 1;

        $email = 'Bob@Example.com';
        $cpf   = '98765432100';
        $repo->createAppointment( array(
            'calendar_id'        => 5,
            'confirmation_token' => 't',
            'validation_code'    => 'VAL1234567AB',
            'email'              => $email,
            'cpf_rf'             => $cpf,
        ) );

        $this->assertSame( Encryption::hash( $email ), $captured['email_hash'] );
        $this->assertSame( Encryption::hash( $cpf ), $captured['cpf_hash'] );
    }
}

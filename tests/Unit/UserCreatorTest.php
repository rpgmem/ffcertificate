<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UserDashboard\UserCreator;

/**
 * Tests for UserCreator: user creation, orphan linking, username generation.
 */
class UserCreatorTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->posts  = 'wp_posts';
        $wpdb->last_error = '';

        Functions\when( 'current_time' )->justReturn( '2026-02-17 12:00:00' );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( '__' )->returnArg();
        Functions\when( 'absint' )->alias( function( $val ) { return abs( intval( $val ) ); } );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'sanitize_key' )->alias( function( $key ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) ); } );
        Functions\when( 'get_option' )->justReturn( 0 );
        Functions\when( 'do_action' )->justReturn( null );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'is_wp_error' )->alias( function( $thing ) { return $thing instanceof \WP_Error; } );

        // Namespaced stubs: prevent "is not defined" errors when Sprint 27 tests run first.
        // Core namespace (Debug calls get_option/get_current_user_id).
        Functions\when( 'FreeFormCertificate\Core\get_option' )->alias( function ( $key, $default = false ) {
            return \get_option( $key, $default );
        } );
        Functions\when( 'FreeFormCertificate\Core\get_current_user_id' )->alias( function () {
            return \get_current_user_id();
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // generate_username() — public, easy to test in isolation
    // ------------------------------------------------------------------

    public function test_generate_username_from_nome_completo(): void {
        Functions\when( 'sanitize_user' )->returnArg();
        Functions\when( 'remove_accents' )->returnArg();
        Functions\when( 'username_exists' )->justReturn( false );

        $username = UserCreator::generate_username(
            'alice@example.com',
            array( 'nome_completo' => 'Alice Silva' )
        );

        // Space is removed by preg_replace('/[^a-z0-9._-]/', '')
        $this->assertSame( 'alicesilva', $username );
    }

    public function test_generate_username_from_nome_field(): void {
        Functions\when( 'sanitize_user' )->returnArg();
        Functions\when( 'remove_accents' )->returnArg();
        Functions\when( 'username_exists' )->justReturn( false );

        $username = UserCreator::generate_username(
            'bob@example.com',
            array( 'nome' => 'Bob Santos' )
        );

        $this->assertSame( 'bobsantos', $username );
    }

    public function test_generate_username_from_name_field(): void {
        Functions\when( 'sanitize_user' )->returnArg();
        Functions\when( 'remove_accents' )->returnArg();
        Functions\when( 'username_exists' )->justReturn( false );

        $username = UserCreator::generate_username(
            'carol@example.com',
            array( 'name' => 'Carol Oliveira' )
        );

        $this->assertSame( 'carololiveira', $username );
    }

    public function test_generate_username_increments_on_collision(): void {
        Functions\when( 'sanitize_user' )->returnArg();
        Functions\when( 'remove_accents' )->returnArg();

        $call_count = 0;
        Functions\when( 'username_exists' )->alias( function( $name ) use ( &$call_count ) {
            $call_count++;
            return $call_count <= 2;
        } );

        $username = UserCreator::generate_username(
            'dave@example.com',
            array( 'nome_completo' => 'Dave Costa' )
        );

        // 'davecosta' taken (call 1), 'davecosta.2' taken (call 2), 'davecosta.3' OK (call 3)
        $this->assertSame( 'davecosta.3', $username );
    }

    public function test_generate_username_falls_back_to_random_when_name_too_short(): void {
        Functions\when( 'sanitize_user' )->returnArg();
        Functions\when( 'remove_accents' )->returnArg();
        Functions\when( 'username_exists' )->justReturn( false );
        Functions\when( 'wp_generate_password' )->justReturn( 'abcd1234' );

        $username = UserCreator::generate_username(
            'x@example.com',
            array( 'nome_completo' => 'ab' )
        );

        $this->assertStringStartsWith( 'ffc_', $username );
    }

    public function test_generate_username_falls_back_to_random_when_no_name(): void {
        Functions\when( 'sanitize_user' )->returnArg();
        Functions\when( 'remove_accents' )->returnArg();
        Functions\when( 'username_exists' )->justReturn( false );
        Functions\when( 'wp_generate_password' )->justReturn( 'xyz78901' );

        $username = UserCreator::generate_username( 'test@example.com', array() );

        $this->assertStringStartsWith( 'ffc_', $username );
        $this->assertSame( 'ffc_xyz78901', $username );
    }

    public function test_generate_username_strips_special_characters(): void {
        Functions\when( 'sanitize_user' )->returnArg();
        Functions\when( 'remove_accents' )->alias( function( $s ) { return 'joao silva'; } );
        Functions\when( 'username_exists' )->justReturn( false );

        $username = UserCreator::generate_username(
            'joao@example.com',
            array( 'nome_completo' => 'João Silva' )
        );

        $this->assertSame( 'joaosilva', $username );
    }

    // ------------------------------------------------------------------
    // get_or_create_user() — step 1: existing user_id found via CPF hash
    // ------------------------------------------------------------------

    public function test_get_or_create_user_returns_existing_user_when_cpf_matched(): void {
        global $wpdb;

        // Allow flexible calls to prepare and get_var
        $wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
        $wpdb->shouldReceive( 'get_var' )->andReturn( '42' );
        $wpdb->shouldReceive( 'query' )->andReturn( 0 );

        // grant_context_capabilities → get_userdata returns null (skip grant)
        Functions\when( 'get_userdata' )->justReturn( null );

        $result = UserCreator::get_or_create_user( 'hash123', 'test@example.com', array() );

        $this->assertSame( 42, $result );
    }

    public function test_get_or_create_user_links_to_existing_wp_user_by_email(): void {
        global $wpdb;

        $wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
        $wpdb->shouldReceive( 'get_var' )->andReturn( null );
        $wpdb->shouldReceive( 'query' )->andReturn( 0 );

        $mock_user = Mockery::mock( 'WP_User' );
        $mock_user->ID = 55;
        $mock_user->display_name = 'Existing User';
        $mock_user->user_login = 'existinguser';
        $mock_user->shouldReceive( 'add_role' )->with( 'ffc_user' )->once();

        Functions\when( 'get_user_by' )->justReturn( $mock_user );
        Functions\when( 'get_userdata' )->justReturn( null );
        Functions\when( 'wp_update_user' )->justReturn( 55 );
        Functions\when( 'update_user_meta' )->justReturn( true );

        $result = UserCreator::get_or_create_user( 'newhash', 'existing@example.com', array() );

        $this->assertSame( 55, $result );
    }

    public function test_get_or_create_user_creates_new_user_when_email_not_found(): void {
        global $wpdb;

        $wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
        $wpdb->shouldReceive( 'get_var' )->andReturn( null );
        $wpdb->shouldReceive( 'query' )->andReturn( 0 );
        $wpdb->shouldReceive( 'get_results' )->andReturn( array() );

        Functions\when( 'get_user_by' )->justReturn( false );
        Functions\when( 'wp_generate_password' )->justReturn( 'SecurePass123!' );
        Functions\when( 'sanitize_user' )->returnArg();
        Functions\when( 'remove_accents' )->returnArg();
        Functions\when( 'username_exists' )->justReturn( false );
        Functions\when( 'wp_create_user' )->justReturn( 100 );
        Functions\when( 'get_userdata' )->justReturn( null );
        Functions\when( 'wp_update_user' )->justReturn( 100 );
        Functions\when( 'update_user_meta' )->justReturn( true );
        Functions\when( 'wp_new_user_notification' )->justReturn( null );

        $result = UserCreator::get_or_create_user(
            'brandhash',
            'brand@new.com',
            array( 'nome_completo' => 'Brand New User' )
        );

        $this->assertSame( 100, $result );
    }

    public function test_get_or_create_user_returns_wp_error_on_create_failure(): void {
        global $wpdb;

        $wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
        $wpdb->shouldReceive( 'get_var' )->andReturn( null );

        Functions\when( 'get_user_by' )->justReturn( false );
        Functions\when( 'wp_generate_password' )->justReturn( 'Pass123!' );
        Functions\when( 'sanitize_user' )->returnArg();
        Functions\when( 'remove_accents' )->returnArg();
        Functions\when( 'username_exists' )->justReturn( false );

        $wp_error = new \WP_Error( 'create_failed', 'User creation failed' );
        Functions\when( 'wp_create_user' )->justReturn( $wp_error );

        $result = UserCreator::get_or_create_user(
            'failhash',
            'fail@example.com',
            array( 'nome_completo' => 'Fail User' )
        );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    // ------------------------------------------------------------------
    // get_or_create_user() — capability granting via context
    // ------------------------------------------------------------------

    public function test_get_or_create_user_grants_appointment_capabilities_for_appointment_context(): void {
        global $wpdb;

        $wpdb->shouldReceive( 'prepare' )->andReturn( 'QUERY' );
        $wpdb->shouldReceive( 'get_var' )->andReturn( '42' );
        $wpdb->shouldReceive( 'query' )->andReturn( 0 );

        $mock_user = Mockery::mock( 'WP_User' );
        $mock_user->shouldReceive( 'has_cap' )->andReturn( false );
        $mock_user->shouldReceive( 'add_cap' )->times( 3 ); // 3 appointment caps
        $mock_user->ID = 42;
        $mock_user->user_email = 'test@example.com';
        $mock_user->display_name = 'Test User';

        Functions\when( 'get_userdata' )->justReturn( $mock_user );

        $result = UserCreator::get_or_create_user( 'hash123', 'test@example.com', array(), 'appointment' );

        $this->assertSame( 42, $result );
    }
}

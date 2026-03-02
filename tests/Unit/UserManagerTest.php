<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UserDashboard\UserManager;

/**
 * Tests for UserManager: profile retrieval/update, CPF/RF masking,
 * email retrieval, name extraction, and delegation methods.
 *
 * @covers \FreeFormCertificate\UserDashboard\UserManager
 */
class UserManagerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var Mockery\MockInterface */
    private $wpdb;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        global $wpdb;
        $wpdb         = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';
        $this->wpdb   = $wpdb;

        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );
        Functions\when( 'wp_cache_delete' )->justReturn( true );
        Functions\when( '__' )->returnArg();
        Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults = array() ) {
            return array_merge( $defaults, $args );
        } );
        Functions\when( 'sanitize_text_field' )->alias( 'trim' );
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'absint' )->alias( function ( $val ) {
            return abs( intval( $val ) );
        } );
        Functions\when( 'current_time' )->justReturn( '2026-03-01 12:00:00' );
        Functions\when( 'is_email' )->alias( function ( $email ) {
            return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
        } );
        Functions\when( 'get_option' )->justReturn( array() );

        $this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () {
            return func_get_args()[0];
        } )->byDefault();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // Helper: make table_exists return true or false
    // ==================================================================

    /**
     * Configure the wpdb mock so that table_exists() returns $exists.
     *
     * table_exists uses: $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name))
     * When it returns the table name string, table_exists() === true.
     * When it returns null, table_exists() === false.
     */
    private function mock_table_exists( string $table_name, bool $exists ): void {
        $this->wpdb->shouldReceive( 'get_var' )
            ->with( 'SHOW TABLES LIKE %s' )
            ->andReturn( $exists ? $table_name : null )
            ->byDefault();
    }

    // ==================================================================
    // get_profile()
    // ==================================================================

    public function test_get_profile_returns_profile_from_custom_table(): void {
        $profile_row = array(
            'id'           => 1,
            'user_id'      => 42,
            'display_name' => 'Alice Smith',
            'phone'        => '+5511999999999',
            'department'   => 'Engineering',
            'organization' => 'ACME',
            'notes'        => 'VIP user',
            'preferences'  => '{"theme":"dark"}',
            'created_at'   => '2025-01-01 00:00:00',
            'updated_at'   => '2025-06-01 00:00:00',
        );

        // table_exists returns true
        $this->wpdb->shouldReceive( 'get_var' )
            ->with( 'SHOW TABLES LIKE %s' )
            ->once()
            ->andReturn( 'wp_ffc_user_profiles' );

        // get_row returns the profile
        $this->wpdb->shouldReceive( 'get_row' )
            ->once()
            ->andReturn( $profile_row );

        $result = UserManager::get_profile( 42 );

        $this->assertSame( $profile_row, $result );
    }

    public function test_get_profile_falls_back_to_userdata_when_table_exists_but_no_row(): void {
        // table_exists returns true
        $this->wpdb->shouldReceive( 'get_var' )
            ->with( 'SHOW TABLES LIKE %s' )
            ->once()
            ->andReturn( 'wp_ffc_user_profiles' );

        // get_row returns null (no profile in custom table)
        $this->wpdb->shouldReceive( 'get_row' )
            ->once()
            ->andReturn( null );

        $mock_user                  = new \stdClass();
        $mock_user->display_name    = 'Bob Jones';
        $mock_user->user_registered = '2024-12-15 10:30:00';

        Functions\expect( 'get_userdata' )
            ->once()
            ->with( 99 )
            ->andReturn( $mock_user );

        $result = UserManager::get_profile( 99 );

        $this->assertSame( 99, $result['user_id'] );
        $this->assertSame( 'Bob Jones', $result['display_name'] );
        $this->assertSame( '', $result['phone'] );
        $this->assertSame( '', $result['department'] );
        $this->assertSame( '', $result['organization'] );
        $this->assertSame( '', $result['notes'] );
        $this->assertNull( $result['preferences'] );
        $this->assertSame( '2024-12-15 10:30:00', $result['created_at'] );
        $this->assertSame( '2024-12-15 10:30:00', $result['updated_at'] );
    }

    public function test_get_profile_falls_back_to_userdata_when_table_not_exists(): void {
        // table_exists returns false
        $this->wpdb->shouldReceive( 'get_var' )
            ->with( 'SHOW TABLES LIKE %s' )
            ->once()
            ->andReturn( null );

        $mock_user                  = new \stdClass();
        $mock_user->display_name    = 'Carol';
        $mock_user->user_registered = '2025-03-10 08:00:00';

        Functions\expect( 'get_userdata' )
            ->once()
            ->with( 7 )
            ->andReturn( $mock_user );

        $result = UserManager::get_profile( 7 );

        $this->assertSame( 7, $result['user_id'] );
        $this->assertSame( 'Carol', $result['display_name'] );
    }

    public function test_get_profile_returns_empty_array_when_no_table_and_no_user(): void {
        // table_exists returns false
        $this->wpdb->shouldReceive( 'get_var' )
            ->with( 'SHOW TABLES LIKE %s' )
            ->once()
            ->andReturn( null );

        Functions\expect( 'get_userdata' )
            ->once()
            ->with( 999 )
            ->andReturn( false );

        $result = UserManager::get_profile( 999 );

        $this->assertSame( array(), $result );
    }

    // ==================================================================
    // update_profile()
    // ==================================================================

    public function test_update_profile_returns_false_when_table_not_exists(): void {
        // table_exists returns false
        $this->wpdb->shouldReceive( 'get_var' )
            ->with( 'SHOW TABLES LIKE %s' )
            ->once()
            ->andReturn( null );

        $result = UserManager::update_profile( 42, array( 'display_name' => 'New Name' ) );

        $this->assertFalse( $result );
    }

    public function test_update_profile_returns_false_when_no_allowed_fields(): void {
        // table_exists returns true
        $this->wpdb->shouldReceive( 'get_var' )
            ->with( 'SHOW TABLES LIKE %s' )
            ->once()
            ->andReturn( 'wp_ffc_user_profiles' );

        // No allowed fields in data
        $result = UserManager::update_profile( 42, array( 'unknown_field' => 'value' ) );

        $this->assertFalse( $result );
    }

    public function test_update_profile_updates_existing_row(): void {
        // table_exists returns true
        $this->wpdb->shouldReceive( 'get_var' )
            ->with( 'SHOW TABLES LIKE %s' )
            ->once()
            ->andReturn( 'wp_ffc_user_profiles' );

        // Profile exists (get_var for SELECT id check returns an id)
        $this->wpdb->shouldReceive( 'get_var' )
            ->with( "SELECT id FROM %i WHERE user_id = %d" )
            ->once()
            ->andReturn( '5' );

        // update call succeeds
        $this->wpdb->shouldReceive( 'update' )
            ->once()
            ->andReturn( 1 );

        Functions\expect( 'wp_update_user' )
            ->once()
            ->andReturn( 42 );

        $result = UserManager::update_profile( 42, array(
            'display_name' => 'Updated Name',
            'phone'        => '+5511888888888',
        ) );

        $this->assertTrue( $result );
    }

    public function test_update_profile_inserts_new_row_when_not_exists(): void {
        // table_exists returns true
        $this->wpdb->shouldReceive( 'get_var' )
            ->with( 'SHOW TABLES LIKE %s' )
            ->once()
            ->andReturn( 'wp_ffc_user_profiles' );

        // Profile does NOT exist
        $this->wpdb->shouldReceive( 'get_var' )
            ->with( "SELECT id FROM %i WHERE user_id = %d" )
            ->once()
            ->andReturn( null );

        // insert call succeeds
        $this->wpdb->shouldReceive( 'insert' )
            ->once()
            ->andReturn( 1 );

        $result = UserManager::update_profile( 42, array(
            'department'   => 'HR',
            'organization' => 'ACME Corp',
        ) );

        $this->assertTrue( $result );
    }

    public function test_update_profile_handles_preferences_json(): void {
        // table_exists returns true
        $this->wpdb->shouldReceive( 'get_var' )
            ->with( 'SHOW TABLES LIKE %s' )
            ->once()
            ->andReturn( 'wp_ffc_user_profiles' );

        // Profile exists
        $this->wpdb->shouldReceive( 'get_var' )
            ->with( "SELECT id FROM %i WHERE user_id = %d" )
            ->once()
            ->andReturn( '3' );

        // update call
        $this->wpdb->shouldReceive( 'update' )
            ->once()
            ->withArgs( function ( $table, $data, $where, $formats, $where_formats ) {
                // preferences should be JSON-encoded
                return isset( $data['preferences'] )
                    && is_string( $data['preferences'] )
                    && json_decode( $data['preferences'], true ) === array( 'theme' => 'dark', 'lang' => 'pt-BR' );
            } )
            ->andReturn( 1 );

        $result = UserManager::update_profile( 10, array(
            'preferences' => array( 'theme' => 'dark', 'lang' => 'pt-BR' ),
        ) );

        $this->assertTrue( $result );
    }

    public function test_update_profile_also_calls_wp_update_user_for_display_name(): void {
        // table_exists returns true
        $this->wpdb->shouldReceive( 'get_var' )
            ->with( 'SHOW TABLES LIKE %s' )
            ->once()
            ->andReturn( 'wp_ffc_user_profiles' );

        // Profile exists
        $this->wpdb->shouldReceive( 'get_var' )
            ->with( "SELECT id FROM %i WHERE user_id = %d" )
            ->once()
            ->andReturn( '1' );

        $this->wpdb->shouldReceive( 'update' )
            ->once()
            ->andReturn( 1 );

        Functions\expect( 'wp_update_user' )
            ->once()
            ->with( Mockery::on( function ( $args ) {
                return $args['ID'] === 42
                    && $args['display_name'] === 'New Display Name';
            } ) )
            ->andReturn( 42 );

        $result = UserManager::update_profile( 42, array( 'display_name' => 'New Display Name' ) );

        $this->assertTrue( $result );
    }

    public function test_update_profile_returns_false_on_db_failure(): void {
        // table_exists returns true
        $this->wpdb->shouldReceive( 'get_var' )
            ->with( 'SHOW TABLES LIKE %s' )
            ->once()
            ->andReturn( 'wp_ffc_user_profiles' );

        // Profile exists
        $this->wpdb->shouldReceive( 'get_var' )
            ->with( "SELECT id FROM %i WHERE user_id = %d" )
            ->once()
            ->andReturn( '1' );

        // update returns false (failure)
        $this->wpdb->shouldReceive( 'update' )
            ->once()
            ->andReturn( false );

        $result = UserManager::update_profile( 42, array( 'phone' => '123' ) );

        $this->assertFalse( $result );
    }

    public function test_update_profile_sanitizes_allowed_fields_only(): void {
        // table_exists returns true
        $this->wpdb->shouldReceive( 'get_var' )
            ->with( 'SHOW TABLES LIKE %s' )
            ->once()
            ->andReturn( 'wp_ffc_user_profiles' );

        // Profile does NOT exist → insert path
        $this->wpdb->shouldReceive( 'get_var' )
            ->with( "SELECT id FROM %i WHERE user_id = %d" )
            ->once()
            ->andReturn( null );

        $this->wpdb->shouldReceive( 'insert' )
            ->once()
            ->withArgs( function ( $table, $data, $formats ) {
                // Should only contain allowed fields + user_id + created_at
                // 'evil_field' must be stripped
                return ! isset( $data['evil_field'] )
                    && isset( $data['notes'] )
                    && $data['notes'] === 'Some notes'
                    && isset( $data['user_id'] )
                    && $data['user_id'] === 5;
            } )
            ->andReturn( 1 );

        $result = UserManager::update_profile( 5, array(
            'notes'      => 'Some notes',
            'evil_field' => 'should be ignored',
        ) );

        $this->assertTrue( $result );
    }

    // ==================================================================
    // get_user_cpf_masked()
    // ==================================================================

    public function test_get_user_cpf_masked_returns_null_when_no_cpfs(): void {
        // get_user_cpfs_masked will query $wpdb. Make get_results return empty.
        $this->wpdb->shouldReceive( 'get_results' )
            ->once()
            ->andReturn( array() );

        $result = UserManager::get_user_cpf_masked( 42 );

        $this->assertNull( $result );
    }

    // ==================================================================
    // get_user_cpfs_masked() — empty results path
    // ==================================================================

    public function test_get_user_cpfs_masked_returns_empty_when_no_rows(): void {
        $this->wpdb->shouldReceive( 'get_results' )
            ->once()
            ->andReturn( array() );

        $result = UserManager::get_user_cpfs_masked( 42 );

        $this->assertSame( array(), $result );
    }

    public function test_get_user_cpfs_masked_returns_empty_when_null_results(): void {
        $this->wpdb->shouldReceive( 'get_results' )
            ->once()
            ->andReturn( null );

        $result = UserManager::get_user_cpfs_masked( 42 );

        $this->assertSame( array(), $result );
    }

    // ==================================================================
    // get_user_cpfs_masked() — with decryption (runInSeparateProcess)
    // ==================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_get_user_cpfs_masked_decrypts_and_masks_cpf(): void {
        // Alias mock for Encryption::decrypt
        $encMock = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
        $encMock->shouldReceive( 'decrypt' )
            ->with( 'enc_cpf_123' )
            ->once()
            ->andReturn( '12345678901' );

        // Alias mock for DocumentFormatter::mask_cpf
        $fmtMock = Mockery::mock( 'alias:FreeFormCertificate\Core\DocumentFormatter' );
        $fmtMock->shouldReceive( 'mask_cpf' )
            ->with( '12345678901' )
            ->once()
            ->andReturn( '123.***.***-01' );

        // Alias mock for Utils::get_submissions_table
        $utilsMock = Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' );
        $utilsMock->shouldReceive( 'get_submissions_table' )
            ->andReturn( 'wp_ffc_submissions' );

        $this->wpdb->shouldReceive( 'get_results' )
            ->once()
            ->andReturn( array(
                array( 'cpf_encrypted' => 'enc_cpf_123', 'rf_encrypted' => null ),
            ) );

        $result = UserManager::get_user_cpfs_masked( 42 );

        $this->assertSame( array( '123.***.***-01' ), $result );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_get_user_cpfs_masked_handles_decrypt_exception(): void {
        $encMock = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
        $encMock->shouldReceive( 'decrypt' )
            ->andThrow( new \Exception( 'Decryption failed' ) );

        $fmtMock = Mockery::mock( 'alias:FreeFormCertificate\Core\DocumentFormatter' );
        $fmtMock->shouldReceive( 'mask_cpf' )->never();

        $utilsMock = Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' );
        $utilsMock->shouldReceive( 'get_submissions_table' )
            ->andReturn( 'wp_ffc_submissions' );

        $this->wpdb->shouldReceive( 'get_results' )
            ->once()
            ->andReturn( array(
                array( 'cpf_encrypted' => 'bad_data', 'rf_encrypted' => null ),
            ) );

        $result = UserManager::get_user_cpfs_masked( 42 );

        $this->assertSame( array(), $result );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_get_user_cpfs_masked_deduplicates_results(): void {
        $encMock = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
        $encMock->shouldReceive( 'decrypt' )
            ->andReturn( '12345678901' );

        $fmtMock = Mockery::mock( 'alias:FreeFormCertificate\Core\DocumentFormatter' );
        $fmtMock->shouldReceive( 'mask_cpf' )
            ->andReturn( '123.***.***-01' );

        $utilsMock = Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' );
        $utilsMock->shouldReceive( 'get_submissions_table' )
            ->andReturn( 'wp_ffc_submissions' );

        // Two rows with the same CPF → should deduplicate
        $this->wpdb->shouldReceive( 'get_results' )
            ->once()
            ->andReturn( array(
                array( 'cpf_encrypted' => 'enc_cpf', 'rf_encrypted' => null ),
                array( 'cpf_encrypted' => 'enc_cpf', 'rf_encrypted' => null ),
            ) );

        $result = UserManager::get_user_cpfs_masked( 42 );

        $this->assertCount( 1, $result );
        $this->assertSame( '123.***.***-01', $result[0] );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_get_user_cpfs_masked_uses_rf_when_cpf_empty(): void {
        $encMock = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
        $encMock->shouldReceive( 'decrypt' )
            ->with( 'enc_rf_456' )
            ->once()
            ->andReturn( 'RF12345' );

        $fmtMock = Mockery::mock( 'alias:FreeFormCertificate\Core\DocumentFormatter' );
        $fmtMock->shouldReceive( 'mask_cpf' )
            ->with( 'RF12345' )
            ->once()
            ->andReturn( 'RF1***5' );

        $utilsMock = Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' );
        $utilsMock->shouldReceive( 'get_submissions_table' )
            ->andReturn( 'wp_ffc_submissions' );

        $this->wpdb->shouldReceive( 'get_results' )
            ->once()
            ->andReturn( array(
                array( 'cpf_encrypted' => null, 'rf_encrypted' => 'enc_rf_456' ),
            ) );

        $result = UserManager::get_user_cpfs_masked( 42 );

        $this->assertSame( array( 'RF1***5' ), $result );
    }

    // ==================================================================
    // get_user_cpf_masked() — with decryption (runInSeparateProcess)
    // ==================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_get_user_cpf_masked_returns_first_cpf(): void {
        $encMock = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
        $encMock->shouldReceive( 'decrypt' )
            ->andReturnUsing( function ( $val ) {
                return $val === 'enc1' ? '11111111111' : '22222222222';
            } );

        $fmtMock = Mockery::mock( 'alias:FreeFormCertificate\Core\DocumentFormatter' );
        $fmtMock->shouldReceive( 'mask_cpf' )
            ->andReturnUsing( function ( $val ) {
                return $val === '11111111111' ? '111.***.***-11' : '222.***.***-22';
            } );

        $utilsMock = Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' );
        $utilsMock->shouldReceive( 'get_submissions_table' )
            ->andReturn( 'wp_ffc_submissions' );

        $this->wpdb->shouldReceive( 'get_results' )
            ->once()
            ->andReturn( array(
                array( 'cpf_encrypted' => 'enc1', 'rf_encrypted' => null ),
                array( 'cpf_encrypted' => 'enc2', 'rf_encrypted' => null ),
            ) );

        $result = UserManager::get_user_cpf_masked( 42 );

        $this->assertSame( '111.***.***-11', $result );
    }

    // ==================================================================
    // get_user_identifiers_masked() — empty results path
    // ==================================================================

    public function test_get_user_identifiers_masked_returns_empty_arrays_when_no_rows(): void {
        $this->wpdb->shouldReceive( 'get_results' )
            ->once()
            ->andReturn( array() );

        $result = UserManager::get_user_identifiers_masked( 42 );

        $this->assertSame( array( 'cpfs' => array(), 'rfs' => array() ), $result );
    }

    public function test_get_user_identifiers_masked_returns_empty_arrays_when_null(): void {
        $this->wpdb->shouldReceive( 'get_results' )
            ->once()
            ->andReturn( null );

        $result = UserManager::get_user_identifiers_masked( 42 );

        $this->assertSame( array( 'cpfs' => array(), 'rfs' => array() ), $result );
    }

    // ==================================================================
    // get_user_identifiers_masked() — with decryption
    // ==================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_get_user_identifiers_masked_separates_cpfs_and_rfs(): void {
        $encMock = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
        $encMock->shouldReceive( 'decrypt' )
            ->andReturnUsing( function ( $val ) {
                $map = array(
                    'enc_cpf' => '12345678901',
                    'enc_rf'  => 'RF999',
                );
                return $map[ $val ] ?? '';
            } );

        $fmtMock = Mockery::mock( 'alias:FreeFormCertificate\Core\DocumentFormatter' );
        $fmtMock->shouldReceive( 'mask_cpf' )
            ->andReturnUsing( function ( $val ) {
                $map = array(
                    '12345678901' => '123.***.***-01',
                    'RF999'       => 'RF***9',
                );
                return $map[ $val ] ?? '';
            } );

        $utilsMock = Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' );
        $utilsMock->shouldReceive( 'get_submissions_table' )
            ->andReturn( 'wp_ffc_submissions' );

        $this->wpdb->shouldReceive( 'get_results' )
            ->once()
            ->andReturn( array(
                array( 'cpf_encrypted' => 'enc_cpf', 'rf_encrypted' => null ),
                array( 'cpf_encrypted' => null, 'rf_encrypted' => 'enc_rf' ),
            ) );

        $result = UserManager::get_user_identifiers_masked( 42 );

        $this->assertSame( array( '123.***.***-01' ), $result['cpfs'] );
        $this->assertSame( array( 'RF***9' ), $result['rfs'] );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_get_user_identifiers_masked_handles_exception(): void {
        $encMock = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
        $encMock->shouldReceive( 'decrypt' )
            ->andThrow( new \Exception( 'Decryption error' ) );

        $fmtMock = Mockery::mock( 'alias:FreeFormCertificate\Core\DocumentFormatter' );
        $fmtMock->shouldReceive( 'mask_cpf' )->never();

        $utilsMock = Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' );
        $utilsMock->shouldReceive( 'get_submissions_table' )
            ->andReturn( 'wp_ffc_submissions' );

        $this->wpdb->shouldReceive( 'get_results' )
            ->once()
            ->andReturn( array(
                array( 'cpf_encrypted' => 'bad', 'rf_encrypted' => null ),
            ) );

        $result = UserManager::get_user_identifiers_masked( 42 );

        $this->assertSame( array( 'cpfs' => array(), 'rfs' => array() ), $result );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_get_user_identifiers_masked_deduplicates(): void {
        $encMock = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
        $encMock->shouldReceive( 'decrypt' )
            ->andReturn( '12345678901' );

        $fmtMock = Mockery::mock( 'alias:FreeFormCertificate\Core\DocumentFormatter' );
        $fmtMock->shouldReceive( 'mask_cpf' )
            ->andReturn( '123.***.***-01' );

        $utilsMock = Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' );
        $utilsMock->shouldReceive( 'get_submissions_table' )
            ->andReturn( 'wp_ffc_submissions' );

        // Same CPF in two rows
        $this->wpdb->shouldReceive( 'get_results' )
            ->once()
            ->andReturn( array(
                array( 'cpf_encrypted' => 'enc_cpf', 'rf_encrypted' => null ),
                array( 'cpf_encrypted' => 'enc_cpf', 'rf_encrypted' => null ),
            ) );

        $result = UserManager::get_user_identifiers_masked( 42 );

        $this->assertCount( 1, $result['cpfs'] );
        $this->assertEmpty( $result['rfs'] );
    }

    // ==================================================================
    // get_user_emails() — empty results path
    // ==================================================================

    public function test_get_user_emails_falls_back_to_user_email_when_no_encrypted(): void {
        $this->wpdb->shouldReceive( 'get_col' )
            ->once()
            ->andReturn( array() );

        $mock_user             = new \stdClass();
        $mock_user->user_email = 'fallback@example.com';

        Functions\expect( 'get_user_by' )
            ->once()
            ->with( 'id', 42 )
            ->andReturn( $mock_user );

        $result = UserManager::get_user_emails( 42 );

        $this->assertSame( array( 'fallback@example.com' ), $result );
    }

    public function test_get_user_emails_returns_empty_when_no_encrypted_and_no_user(): void {
        $this->wpdb->shouldReceive( 'get_col' )
            ->once()
            ->andReturn( array() );

        Functions\expect( 'get_user_by' )
            ->once()
            ->with( 'id', 999 )
            ->andReturn( false );

        $result = UserManager::get_user_emails( 999 );

        $this->assertSame( array(), $result );
    }

    public function test_get_user_emails_returns_empty_when_null_results_and_no_user(): void {
        $this->wpdb->shouldReceive( 'get_col' )
            ->once()
            ->andReturn( null );

        Functions\expect( 'get_user_by' )
            ->once()
            ->with( 'id', 42 )
            ->andReturn( false );

        $result = UserManager::get_user_emails( 42 );

        $this->assertSame( array(), $result );
    }

    // ==================================================================
    // get_user_emails() — with decryption (runInSeparateProcess)
    // ==================================================================

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_get_user_emails_decrypts_and_validates_emails(): void {
        $encMock = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
        $encMock->shouldReceive( 'decrypt' )
            ->andReturnUsing( function ( $val ) {
                $map = array(
                    'enc_email_1' => 'alice@example.com',
                    'enc_email_2' => 'bob@example.com',
                );
                return $map[ $val ] ?? '';
            } );

        $utilsMock = Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' );
        $utilsMock->shouldReceive( 'get_submissions_table' )
            ->andReturn( 'wp_ffc_submissions' );

        $this->wpdb->shouldReceive( 'get_col' )
            ->once()
            ->andReturn( array( 'enc_email_1', 'enc_email_2' ) );

        $mock_user             = new \stdClass();
        $mock_user->user_email = 'alice@example.com';

        Functions\expect( 'get_user_by' )
            ->once()
            ->with( 'id', 42 )
            ->andReturn( $mock_user );

        $result = UserManager::get_user_emails( 42 );

        // alice@example.com should be deduplicated (from encrypted + from user_email)
        $this->assertContains( 'alice@example.com', $result );
        $this->assertContains( 'bob@example.com', $result );
        $this->assertCount( 2, $result );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_get_user_emails_skips_invalid_emails(): void {
        $encMock = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
        $encMock->shouldReceive( 'decrypt' )
            ->andReturn( 'not-an-email' );

        $utilsMock = Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' );
        $utilsMock->shouldReceive( 'get_submissions_table' )
            ->andReturn( 'wp_ffc_submissions' );

        $this->wpdb->shouldReceive( 'get_col' )
            ->once()
            ->andReturn( array( 'enc_bad' ) );

        $mock_user             = new \stdClass();
        $mock_user->user_email = 'valid@example.com';

        Functions\expect( 'get_user_by' )
            ->once()
            ->with( 'id', 42 )
            ->andReturn( $mock_user );

        $result = UserManager::get_user_emails( 42 );

        // Only the user_email should be included; the decrypted value is invalid
        $this->assertSame( array( 'valid@example.com' ), $result );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_get_user_emails_handles_decrypt_exception(): void {
        $encMock = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
        $encMock->shouldReceive( 'decrypt' )
            ->andThrow( new \Exception( 'Key missing' ) );

        $utilsMock = Mockery::mock( 'alias:FreeFormCertificate\Core\Utils' );
        $utilsMock->shouldReceive( 'get_submissions_table' )
            ->andReturn( 'wp_ffc_submissions' );

        $this->wpdb->shouldReceive( 'get_col' )
            ->once()
            ->andReturn( array( 'enc_fail' ) );

        $mock_user             = new \stdClass();
        $mock_user->user_email = 'user@example.com';

        Functions\expect( 'get_user_by' )
            ->once()
            ->with( 'id', 42 )
            ->andReturn( $mock_user );

        $result = UserManager::get_user_emails( 42 );

        // Only the WP user email should remain
        $this->assertSame( array( 'user@example.com' ), $result );
    }

    // ==================================================================
    // get_user_names()
    // ==================================================================

    public function test_get_user_names_falls_back_to_display_name_when_no_submissions(): void {
        $this->wpdb->shouldReceive( 'get_col' )
            ->once()
            ->andReturn( array() );

        $mock_user               = new \stdClass();
        $mock_user->display_name = 'Fallback User';

        Functions\expect( 'get_user_by' )
            ->once()
            ->with( 'id', 42 )
            ->andReturn( $mock_user );

        $result = UserManager::get_user_names( 42 );

        $this->assertSame( array( 'Fallback User' ), $result );
    }

    public function test_get_user_names_returns_empty_when_no_submissions_and_no_user(): void {
        $this->wpdb->shouldReceive( 'get_col' )
            ->once()
            ->andReturn( array() );

        Functions\expect( 'get_user_by' )
            ->once()
            ->with( 'id', 999 )
            ->andReturn( false );

        $result = UserManager::get_user_names( 999 );

        $this->assertSame( array(), $result );
    }

    public function test_get_user_names_extracts_nome_completo_from_json(): void {
        $submission_data = json_encode( array( 'nome_completo' => 'Maria Silva' ) );

        $this->wpdb->shouldReceive( 'get_col' )
            ->once()
            ->andReturn( array( $submission_data ) );

        $result = UserManager::get_user_names( 42 );

        $this->assertSame( array( 'Maria Silva' ), $result );
    }

    public function test_get_user_names_extracts_nome_field(): void {
        $submission_data = json_encode( array( 'nome' => 'Joao Santos' ) );

        $this->wpdb->shouldReceive( 'get_col' )
            ->once()
            ->andReturn( array( $submission_data ) );

        $result = UserManager::get_user_names( 42 );

        $this->assertSame( array( 'Joao Santos' ), $result );
    }

    public function test_get_user_names_extracts_name_field(): void {
        $submission_data = json_encode( array( 'name' => 'John Doe' ) );

        $this->wpdb->shouldReceive( 'get_col' )
            ->once()
            ->andReturn( array( $submission_data ) );

        $result = UserManager::get_user_names( 42 );

        $this->assertSame( array( 'John Doe' ), $result );
    }

    public function test_get_user_names_extracts_full_name_field(): void {
        $submission_data = json_encode( array( 'full_name' => 'Jane Roe' ) );

        $this->wpdb->shouldReceive( 'get_col' )
            ->once()
            ->andReturn( array( $submission_data ) );

        $result = UserManager::get_user_names( 42 );

        $this->assertSame( array( 'Jane Roe' ), $result );
    }

    public function test_get_user_names_extracts_ffc_nome_field(): void {
        $submission_data = json_encode( array( 'ffc_nome' => 'Pedro Almeida' ) );

        $this->wpdb->shouldReceive( 'get_col' )
            ->once()
            ->andReturn( array( $submission_data ) );

        $result = UserManager::get_user_names( 42 );

        $this->assertSame( array( 'Pedro Almeida' ), $result );
    }

    public function test_get_user_names_extracts_participante_field(): void {
        $submission_data = json_encode( array( 'participante' => 'Ana Costa' ) );

        $this->wpdb->shouldReceive( 'get_col' )
            ->once()
            ->andReturn( array( $submission_data ) );

        $result = UserManager::get_user_names( 42 );

        $this->assertSame( array( 'Ana Costa' ), $result );
    }

    public function test_get_user_names_deduplicates_names(): void {
        $sub1 = json_encode( array( 'nome_completo' => 'Maria Silva' ) );
        $sub2 = json_encode( array( 'nome_completo' => 'Maria Silva' ) );

        $this->wpdb->shouldReceive( 'get_col' )
            ->once()
            ->andReturn( array( $sub1, $sub2 ) );

        $result = UserManager::get_user_names( 42 );

        $this->assertCount( 1, $result );
        $this->assertSame( 'Maria Silva', $result[0] );
    }

    public function test_get_user_names_collects_multiple_distinct_names(): void {
        $sub1 = json_encode( array( 'nome_completo' => 'Maria Silva' ) );
        $sub2 = json_encode( array( 'nome_completo' => 'Maria Santos' ) );

        $this->wpdb->shouldReceive( 'get_col' )
            ->once()
            ->andReturn( array( $sub1, $sub2 ) );

        $result = UserManager::get_user_names( 42 );

        $this->assertCount( 2, $result );
        $this->assertContains( 'Maria Silva', $result );
        $this->assertContains( 'Maria Santos', $result );
    }

    public function test_get_user_names_skips_invalid_json(): void {
        $this->wpdb->shouldReceive( 'get_col' )
            ->once()
            ->andReturn( array( 'not-valid-json', '{"also": "no name field"}' ) );

        $mock_user               = new \stdClass();
        $mock_user->display_name = 'Fallback Name';

        Functions\expect( 'get_user_by' )
            ->once()
            ->with( 'id', 42 )
            ->andReturn( $mock_user );

        $result = UserManager::get_user_names( 42 );

        // Invalid JSON is skipped, second has no name field, falls back to display_name
        $this->assertSame( array( 'Fallback Name' ), $result );
    }

    public function test_get_user_names_falls_back_when_submissions_have_no_name_fields(): void {
        $sub = json_encode( array( 'email' => 'test@test.com', 'cpf' => '123' ) );

        $this->wpdb->shouldReceive( 'get_col' )
            ->once()
            ->andReturn( array( $sub ) );

        $mock_user               = new \stdClass();
        $mock_user->display_name = 'WP Display Name';

        Functions\expect( 'get_user_by' )
            ->once()
            ->with( 'id', 42 )
            ->andReturn( $mock_user );

        $result = UserManager::get_user_names( 42 );

        $this->assertSame( array( 'WP Display Name' ), $result );
    }

    public function test_get_user_names_prefers_nome_completo_over_other_fields(): void {
        // nome_completo should be used even if other name fields exist
        $sub = json_encode( array(
            'nome_completo' => 'Priority Name',
            'nome'          => 'Secondary Name',
            'name'          => 'Tertiary Name',
        ) );

        $this->wpdb->shouldReceive( 'get_col' )
            ->once()
            ->andReturn( array( $sub ) );

        $result = UserManager::get_user_names( 42 );

        $this->assertSame( array( 'Priority Name' ), $result );
    }

    public function test_get_user_names_trims_whitespace(): void {
        $sub = json_encode( array( 'nome_completo' => '  Trimmed Name  ' ) );

        $this->wpdb->shouldReceive( 'get_col' )
            ->once()
            ->andReturn( array( $sub ) );

        $result = UserManager::get_user_names( 42 );

        $this->assertSame( array( 'Trimmed Name' ), $result );
    }

    public function test_get_user_names_skips_empty_name_values(): void {
        $sub = json_encode( array( 'nome_completo' => '' ) );

        $this->wpdb->shouldReceive( 'get_col' )
            ->once()
            ->andReturn( array( $sub ) );

        $mock_user               = new \stdClass();
        $mock_user->display_name = 'WP Name';

        Functions\expect( 'get_user_by' )
            ->once()
            ->with( 'id', 42 )
            ->andReturn( $mock_user );

        $result = UserManager::get_user_names( 42 );

        $this->assertSame( array( 'WP Name' ), $result );
    }

    // ==================================================================
    // Delegation methods — verify they exist as static methods
    // ==================================================================

    public function test_delegation_methods_exist(): void {
        $delegation_methods = array(
            'get_or_create_user',
            'generate_username',
            'get_all_capabilities',
            'register_role',
            'remove_role',
            'grant_certificate_capabilities',
            'grant_appointment_capabilities',
            'grant_audience_capabilities',
            'has_certificate_access',
            'has_appointment_access',
            'get_user_ffc_capabilities',
            'set_user_capability',
        );

        foreach ( $delegation_methods as $method ) {
            $this->assertTrue(
                method_exists( UserManager::class, $method ),
                "UserManager should have static method {$method}()"
            );

            $reflection = new \ReflectionMethod( UserManager::class, $method );
            $this->assertTrue(
                $reflection->isStatic(),
                "UserManager::{$method}() should be static"
            );
            $this->assertTrue(
                $reflection->isPublic(),
                "UserManager::{$method}() should be public"
            );
        }
    }

    // ==================================================================
    // Constants — verify backward-compatible aliases exist
    // ==================================================================

    public function test_context_constants_are_defined(): void {
        $this->assertTrue( defined( UserManager::class . '::CONTEXT_CERTIFICATE' ) );
        $this->assertTrue( defined( UserManager::class . '::CONTEXT_APPOINTMENT' ) );
        $this->assertTrue( defined( UserManager::class . '::CONTEXT_AUDIENCE' ) );
    }

    public function test_capability_constants_are_defined(): void {
        $this->assertTrue( defined( UserManager::class . '::CERTIFICATE_CAPABILITIES' ) );
        $this->assertTrue( defined( UserManager::class . '::APPOINTMENT_CAPABILITIES' ) );
        $this->assertTrue( defined( UserManager::class . '::AUDIENCE_CAPABILITIES' ) );
        $this->assertTrue( defined( UserManager::class . '::ADMIN_CAPABILITIES' ) );
        $this->assertTrue( defined( UserManager::class . '::FUTURE_CAPABILITIES' ) );
    }

    // ==================================================================
    // Own methods — verify signatures
    // ==================================================================

    public function test_get_profile_signature(): void {
        $ref = new \ReflectionMethod( UserManager::class, 'get_profile' );
        $this->assertTrue( $ref->isStatic() );
        $this->assertTrue( $ref->isPublic() );
        $this->assertCount( 1, $ref->getParameters() );
        $this->assertSame( 'array', $ref->getReturnType()->getName() );
    }

    public function test_update_profile_signature(): void {
        $ref = new \ReflectionMethod( UserManager::class, 'update_profile' );
        $this->assertTrue( $ref->isStatic() );
        $this->assertTrue( $ref->isPublic() );
        $this->assertCount( 2, $ref->getParameters() );
        $this->assertSame( 'bool', $ref->getReturnType()->getName() );
    }

    public function test_get_user_cpf_masked_signature(): void {
        $ref = new \ReflectionMethod( UserManager::class, 'get_user_cpf_masked' );
        $this->assertTrue( $ref->isStatic() );
        $this->assertTrue( $ref->isPublic() );
        $this->assertCount( 1, $ref->getParameters() );
        $this->assertTrue( $ref->getReturnType()->allowsNull() );
    }

    public function test_get_user_cpfs_masked_signature(): void {
        $ref = new \ReflectionMethod( UserManager::class, 'get_user_cpfs_masked' );
        $this->assertTrue( $ref->isStatic() );
        $this->assertTrue( $ref->isPublic() );
        $this->assertCount( 1, $ref->getParameters() );
        $this->assertSame( 'array', $ref->getReturnType()->getName() );
    }

    public function test_get_user_identifiers_masked_signature(): void {
        $ref = new \ReflectionMethod( UserManager::class, 'get_user_identifiers_masked' );
        $this->assertTrue( $ref->isStatic() );
        $this->assertTrue( $ref->isPublic() );
        $this->assertCount( 1, $ref->getParameters() );
        $this->assertSame( 'array', $ref->getReturnType()->getName() );
    }

    public function test_get_user_emails_signature(): void {
        $ref = new \ReflectionMethod( UserManager::class, 'get_user_emails' );
        $this->assertTrue( $ref->isStatic() );
        $this->assertTrue( $ref->isPublic() );
        $this->assertCount( 1, $ref->getParameters() );
        $this->assertSame( 'array', $ref->getReturnType()->getName() );
    }

    public function test_get_user_names_signature(): void {
        $ref = new \ReflectionMethod( UserManager::class, 'get_user_names' );
        $this->assertTrue( $ref->isStatic() );
        $this->assertTrue( $ref->isPublic() );
        $this->assertCount( 1, $ref->getParameters() );
        $this->assertSame( 'array', $ref->getReturnType()->getName() );
    }
}

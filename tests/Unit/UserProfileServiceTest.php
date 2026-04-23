<?php
/**
 * Tests for UserProfileService — the single entry point consolidating
 * profile reads and writes across wp_users, wp_ffc_user_profiles, and
 * wp_usermeta.
 *
 * The service is dependency-free on WP capabilities by design. These
 * tests focus on the observable routing contract:
 *   - unknown fields are dropped silently,
 *   - reads hit the correct storage per descriptor,
 *   - sensitive-field writes encrypt and store the lookup hash,
 *   - display_name writes mirror to wp_users,
 *   - FULL reads of sensitive data emit exactly one audit entry.
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\UserDashboard\UserProfileFieldMap;
use FreeFormCertificate\UserDashboard\UserProfileService;
use FreeFormCertificate\UserDashboard\ViewPolicy;

/**
 * @coversNothing
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class UserProfileServiceTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var Mockery\MockInterface */
    private $wpdb;

    /** @var array<string, array<string, mixed>> */
    private array $usermeta_store;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->usermeta_store = array();

        global $wpdb;
        $wpdb             = Mockery::mock( 'wpdb' );
        $wpdb->prefix     = 'wp_';
        $wpdb->last_error = '';
        $this->wpdb       = $wpdb;

        // Generic stubs used across tests.
        $this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () {
            return func_get_args()[0];
        } )->byDefault();

        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'absint' )->alias( function ( $v ) {
            return abs( (int) $v );
        } );
        Functions\when( 'current_time' )->justReturn( '2026-04-23 12:00:00' );
        Functions\when( 'get_current_user_id' )->justReturn( 7 );
        Functions\when( 'get_option' )->justReturn( array() );

        // Stateful get/update/delete_user_meta backed by $usermeta_store.
        $store =& $this->usermeta_store;
        Functions\when( 'get_user_meta' )->alias( function ( $uid, $key, $single = false ) use ( &$store ) {
            if ( ! isset( $store[ $uid ][ $key ] ) ) {
                return '';
            }
            return $store[ $uid ][ $key ];
        } );
        Functions\when( 'update_user_meta' )->alias( function ( $uid, $key, $value ) use ( &$store ) {
            $store[ $uid ][ $key ] = $value;
            return true;
        } );
        Functions\when( 'delete_user_meta' )->alias( function ( $uid, $key ) use ( &$store ) {
            unset( $store[ $uid ][ $key ] );
            return true;
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // read()
    // ==================================================================

    public function test_read_returns_empty_for_invalid_inputs(): void {
        $this->assertSame( array(), UserProfileService::read( 0, array( 'phone' ) ) );
        $this->assertSame( array(), UserProfileService::read( 42, array() ) );
    }

    public function test_read_drops_unknown_field_keys_silently(): void {
        $result = UserProfileService::read( 42, array( 'not_a_field', 'also_unknown' ) );
        $this->assertSame( array(), $result );
    }

    public function test_read_profile_table_returns_row_columns(): void {
        $this->wpdb->shouldReceive( 'get_row' )
            ->once()
            ->andReturn( array(
                'display_name' => 'Alice',
                'phone'        => '555-0100',
                'department'   => 'Sales',
                'organization' => 'Acme',
                'notes'        => '',
                'preferences'  => '{}',
                'user_id'      => 42,
            ) );

        $result = UserProfileService::read( 42, array( 'display_name', 'phone' ) );

        $this->assertSame( 'Alice', $result['display_name'] );
        $this->assertSame( '555-0100', $result['phone'] );
    }

    public function test_read_wp_user_returns_user_email(): void {
        $user              = new \stdClass();
        $user->user_email  = 'alice@example.com';
        $user->display_name = 'Alice';
        Functions\when( 'get_userdata' )->justReturn( $user );

        $result = UserProfileService::read( 42, array( 'user_email' ) );

        $this->assertSame( 'alice@example.com', $result['user_email'] );
    }

    public function test_read_usermeta_masks_sensitive_field_by_default(): void {
        $enc = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
        $enc->shouldReceive( 'decrypt' )->with( 'ENC_CPF' )->once()->andReturn( '12345678901' );

        $fmt = Mockery::mock( 'alias:FreeFormCertificate\Core\DocumentFormatter' );
        $fmt->shouldReceive( 'mask_cpf' )->with( '12345678901' )->once()->andReturn( '123.***.***-01' );

        $this->usermeta_store[42] = array( 'ffc_user_cpf' => 'ENC_CPF' );

        $result = UserProfileService::read( 42, array( 'cpf' ) );

        $this->assertSame( '123.***.***-01', $result['cpf'] );
    }

    public function test_read_usermeta_returns_full_plaintext_when_policy_is_full(): void {
        $enc = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
        $enc->shouldReceive( 'decrypt' )->with( 'ENC_CPF' )->once()->andReturn( '12345678901' );

        // FULL policy triggers an audit entry via ActivityLog::log; stub it.
        $log = Mockery::mock( 'alias:FreeFormCertificate\Core\ActivityLog' );
        $log->shouldReceive( 'log' )->once()
            ->with(
                'user_profile_read_full',
                Mockery::any(),
                Mockery::on( function ( $ctx ) {
                    return isset( $ctx['target_user_id'] ) && 42 === $ctx['target_user_id']
                        && isset( $ctx['fields'] ) && in_array( 'cpf', $ctx['fields'], true );
                } ),
                Mockery::any(),
                Mockery::any()
            )
            ->andReturn( true );
        $log->shouldReceive( 'is_enabled' )->andReturn( true );

        $this->usermeta_store[42] = array( 'ffc_user_cpf' => 'ENC_CPF' );

        $result = UserProfileService::read( 42, array( 'cpf' ), ViewPolicy::FULL );

        $this->assertSame( '12345678901', $result['cpf'] );
    }

    public function test_read_usermeta_returns_lookup_hash_for_hashed_only_policy(): void {
        $this->usermeta_store[42] = array(
            'ffc_user_cpf'      => 'ENC_CPF',
            'ffc_user_cpf_hash' => 'HASH_VALUE',
        );

        $result = UserProfileService::read( 42, array( 'cpf' ), ViewPolicy::HASHED_ONLY );

        $this->assertSame( 'HASH_VALUE', $result['cpf'] );
    }

    public function test_read_does_not_audit_masked_reads_even_for_sensitive_fields(): void {
        $enc = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
        $enc->shouldReceive( 'decrypt' )->with( 'ENC_CPF' )->andReturn( '12345678901' );

        $fmt = Mockery::mock( 'alias:FreeFormCertificate\Core\DocumentFormatter' );
        $fmt->shouldReceive( 'mask_cpf' )->andReturn( '123.***.***-01' );

        // ActivityLog::log must NEVER be called on MASKED reads.
        $log = Mockery::mock( 'alias:FreeFormCertificate\Core\ActivityLog' );
        $log->shouldReceive( 'log' )->never();
        $log->shouldReceive( 'is_enabled' )->andReturn( true );

        $this->usermeta_store[42] = array( 'ffc_user_cpf' => 'ENC_CPF' );

        UserProfileService::read( 42, array( 'cpf' ), ViewPolicy::MASKED );

        $this->addToAssertionCount( 1 ); // rely on Mockery strict shouldReceive.
    }

    // ==================================================================
    // write()
    // ==================================================================

    public function test_write_returns_false_for_empty_patch_or_invalid_user(): void {
        $this->assertFalse( UserProfileService::write( 0, array( 'phone' => 'x' ) ) );
        $this->assertFalse( UserProfileService::write( 42, array() ) );
    }

    public function test_write_drops_unknown_keys_silently(): void {
        $result = UserProfileService::write( 42, array( 'nope' => 'value', 'also_nope' => 'v' ) );
        $this->assertFalse( $result );
    }

    public function test_write_profile_table_inserts_when_row_missing(): void {
        $captured_insert = null;
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( 0 ); // row does NOT exist
        $this->wpdb->shouldReceive( 'insert' )
            ->once()
            ->andReturnUsing( function ( $table, $data ) use ( &$captured_insert ) {
                $captured_insert = $data;
                return 1;
            } );
        Functions\when( 'wp_update_user' )->justReturn( 42 );

        $result = UserProfileService::write( 42, array( 'phone' => '555-0100' ) );

        $this->assertTrue( $result );
        $this->assertSame( '555-0100', $captured_insert['phone'] );
        $this->assertSame( 42, $captured_insert['user_id'] );
    }

    public function test_write_profile_table_updates_when_row_exists(): void {
        $captured_update = null;
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( 42 ); // row exists
        $this->wpdb->shouldReceive( 'update' )
            ->once()
            ->andReturnUsing( function ( $table, $data, $where ) use ( &$captured_update ) {
                $captured_update = $data;
                return 1;
            } );
        Functions\when( 'wp_update_user' )->justReturn( 42 );

        UserProfileService::write( 42, array( 'phone' => '555-0100' ) );

        $this->assertSame( '555-0100', $captured_update['phone'] );
    }

    public function test_write_display_name_mirrors_to_wp_users(): void {
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( 42 );
        $this->wpdb->shouldReceive( 'update' )->once()->andReturn( 1 );

        $wp_update_calls = array();
        Functions\when( 'wp_update_user' )->alias( function ( $args ) use ( &$wp_update_calls ) {
            $wp_update_calls[] = $args;
            return 42;
        } );

        UserProfileService::write( 42, array( 'display_name' => 'Alice' ) );

        // Exactly one mirror call targeting wp_users.display_name via wp_update_user.
        $found_mirror = false;
        foreach ( $wp_update_calls as $call ) {
            if ( isset( $call['display_name'] ) && 'Alice' === $call['display_name'] ) {
                $found_mirror = true;
                break;
            }
        }
        $this->assertTrue(
            $found_mirror,
            'display_name write must mirror to wp_users via wp_update_user().'
        );
    }

    public function test_write_encrypts_sensitive_usermeta_and_stores_hash(): void {
        $enc = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
        $enc->shouldReceive( 'encrypt' )->with( '12345678901' )->once()->andReturn( 'ENC' );
        $enc->shouldReceive( 'hash' )->with( '12345678901' )->once()->andReturn( 'HASH' );

        $result = UserProfileService::write( 42, array( 'cpf' => '12345678901' ) );

        $this->assertTrue( $result );
        $this->assertSame( 'ENC', $this->usermeta_store[42]['ffc_user_cpf'] );
        $this->assertSame( 'HASH', $this->usermeta_store[42]['ffc_user_cpf_hash'] );
    }

    public function test_write_deletes_sensitive_meta_and_hash_when_value_empty(): void {
        $this->usermeta_store[42] = array(
            'ffc_user_cpf'      => 'PREVIOUS_ENC',
            'ffc_user_cpf_hash' => 'PREVIOUS_HASH',
        );

        $result = UserProfileService::write( 42, array( 'cpf' => '' ) );

        $this->assertTrue( $result );
        $this->assertArrayNotHasKey( 'ffc_user_cpf', $this->usermeta_store[42] );
        $this->assertArrayNotHasKey( 'ffc_user_cpf_hash', $this->usermeta_store[42] );
    }

    public function test_write_non_sensitive_usermeta_does_not_call_encryption(): void {
        $enc = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
        $enc->shouldReceive( 'encrypt' )->never();
        $enc->shouldReceive( 'hash' )->never();

        $result = UserProfileService::write( 42, array( 'jornada' => '8h_daily' ) );

        $this->assertTrue( $result );
        $this->assertSame( '8h_daily', $this->usermeta_store[42]['ffc_user_jornada'] );
    }

    // ==================================================================
    // write() with $extra_descriptors (Phase 3)
    // ==================================================================

    public function test_write_with_extra_descriptor_routes_dynamic_field_to_usermeta(): void {
        // A dynamic reregistration key (not in UserProfileFieldMap) becomes
        // writable when the caller supplies its descriptor inline. Writes
        // land in wp_usermeta at the declared meta_key.
        $result = UserProfileService::write(
            42,
            array( 'custom_dynamic_field' => 'hello' ),
            array(
                'custom_dynamic_field' => array(
                    'storage'   => \FreeFormCertificate\UserDashboard\UserProfileFieldMap::STORAGE_USERMETA,
                    'meta_key'  => 'ffc_user_custom_dynamic_field',
                    'sensitive' => false,
                ),
            )
        );

        $this->assertTrue( $result );
        $this->assertSame( 'hello', $this->usermeta_store[42]['ffc_user_custom_dynamic_field'] );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_write_with_extra_descriptor_encrypts_sensitive_dynamic_field(): void {
        $enc = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
        $enc->shouldReceive( 'encrypt' )->with( 'secret' )->once()->andReturn( 'ENC_DYNAMIC' );
        // hashable=false, so no hash() call is expected at all.
        $enc->shouldReceive( 'hash' )->never();

        UserProfileService::write(
            42,
            array( 'custom_secret' => 'secret' ),
            array(
                'custom_secret' => array(
                    'storage'   => \FreeFormCertificate\UserDashboard\UserProfileFieldMap::STORAGE_USERMETA,
                    'meta_key'  => 'ffc_user_custom_secret',
                    'sensitive' => true,
                    'hashable'  => false,
                ),
            )
        );

        $this->assertSame( 'ENC_DYNAMIC', $this->usermeta_store[42]['ffc_user_custom_secret'] );
        $this->assertArrayNotHasKey( 'ffc_user_custom_secret_hash', $this->usermeta_store[42] );
    }

    public function test_write_without_overrides_still_drops_unregistered_keys(): void {
        // Baseline invariant: unregistered keys with no extra_descriptor
        // are silently dropped, matching the pre-Phase-3 contract.
        $result = UserProfileService::write( 42, array( 'not_in_map' => 'x' ) );
        $this->assertFalse( $result );
        $this->assertArrayNotHasKey( 42, $this->usermeta_store );
    }

    public function test_write_clears_runtime_overrides_after_call(): void {
        // Overrides must not leak between calls: the second write uses
        // only the field map, so passing the dynamic key without a
        // descriptor again produces no write.
        $ref = new \ReflectionClass( UserProfileService::class );
        $prop = $ref->getProperty( 'runtime_overrides' );
        $prop->setAccessible( true );

        UserProfileService::write(
            42,
            array( 'leaky_key' => 'one' ),
            array(
                'leaky_key' => array(
                    'storage'   => \FreeFormCertificate\UserDashboard\UserProfileFieldMap::STORAGE_USERMETA,
                    'meta_key'  => 'ffc_user_leaky_key',
                    'sensitive' => false,
                ),
            )
        );

        $this->assertSame( array(), $prop->getValue(), 'runtime_overrides must reset after write.' );

        $result = UserProfileService::write( 42, array( 'leaky_key' => 'two' ) );
        $this->assertFalse( $result, 'second write without descriptors must ignore the dynamic key.' );
    }
}

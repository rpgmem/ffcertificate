<?php
/**
 * Tests for SensitiveFieldRegistry's payload-inspection helpers.
 *
 * Covers the additions that power the ActivityLog payload-based gate:
 *   - universal_sensitive_keys(): static union across contexts
 *   - dynamic_sensitive_keys(): is_sensitive=1 rows, cached
 *   - contains_sensitive(): recursive payload scan
 *   - invalidate_dynamic_cache(): drops the dynamic cache
 *
 * Static encryption behaviour is already pinned by SensitiveFieldPolicyTest;
 * here we focus on the classification helpers.
 */

declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\SensitiveFieldRegistry;

/**
 * @covers \FreeFormCertificate\Core\SensitiveFieldRegistry
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class SensitiveFieldRegistryTest extends TestCase {

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
        $this->wpdb = $wpdb;

        $this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () {
            return func_get_args()[0];
        } )->byDefault();
        $this->wpdb->shouldReceive( 'get_var' )->andReturn( null )->byDefault();
        $this->wpdb->shouldReceive( 'get_col' )->andReturn( array() )->byDefault();

        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );
        Functions\when( 'wp_cache_delete' )->justReturn( true );

        // Reset in-memory static cache between tests (subclasses run in
        // separate processes but be explicit anyway).
        $ref = new \ReflectionClass( SensitiveFieldRegistry::class );
        $prop = $ref->getProperty( 'universal_static_cache' );
        $prop->setAccessible( true );
        $prop->setValue( null );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_universal_sensitive_keys_unions_static_contexts(): void {
        $keys = SensitiveFieldRegistry::universal_sensitive_keys();

        // Every static field declared in either context must appear.
        $expected = array( 'email', 'cpf', 'rf', 'user_ip', 'data', 'ticket', 'phone', 'custom_data' );
        foreach ( $expected as $key ) {
            $this->assertArrayHasKey( $key, $keys, "Missing universal key: $key" );
        }
        $this->assertCount( count( $expected ), $keys );
    }

    public function test_contains_sensitive_returns_false_for_empty_payload(): void {
        $this->assertFalse( SensitiveFieldRegistry::contains_sensitive( array() ) );
    }

    public function test_contains_sensitive_returns_false_when_no_keys_match(): void {
        $payload = array( 'form_id' => 5, 'audience_id' => 3, 'note' => 'x' );
        $this->assertFalse( SensitiveFieldRegistry::contains_sensitive( $payload ) );
    }

    public function test_contains_sensitive_detects_top_level_static_key(): void {
        $payload = array( 'email' => 'alice@example.com' );
        $this->assertTrue( SensitiveFieldRegistry::contains_sensitive( $payload ) );
    }

    public function test_contains_sensitive_detects_nested_static_key(): void {
        $payload = array(
            'audience_id' => 3,
            'fields'      => array( 'cpf' => '12345678901' ),
        );
        $this->assertTrue( SensitiveFieldRegistry::contains_sensitive( $payload ) );
    }

    public function test_contains_sensitive_detects_deeply_nested_key(): void {
        $payload = array(
            'wrapper' => array(
                'outer' => array(
                    'inner' => array( 'rf' => '1234567' ),
                ),
            ),
        );
        $this->assertTrue( SensitiveFieldRegistry::contains_sensitive( $payload ) );
    }

    public function test_dynamic_sensitive_keys_returns_distinct_field_keys(): void {
        // Simulate wp_ffc_custom_fields present and two sensitive rows.
        $this->wpdb->shouldReceive( 'get_var' )
            ->once()
            ->andReturn( 'wp_ffc_custom_fields' );
        $this->wpdb->shouldReceive( 'get_col' )
            ->once()
            ->andReturn( array( 'rg', 'cpf_aluno', '' ) );

        $keys = SensitiveFieldRegistry::dynamic_sensitive_keys();

        $this->assertArrayHasKey( 'rg', $keys );
        $this->assertArrayHasKey( 'cpf_aluno', $keys );
        // Empty strings from the column are filtered out.
        $this->assertArrayNotHasKey( '', $keys );
    }

    public function test_dynamic_sensitive_keys_returns_empty_when_table_missing(): void {
        $this->wpdb->shouldReceive( 'get_var' )
            ->once()
            ->andReturn( null );
        // get_col must NOT be called when the table is absent.
        $this->wpdb->shouldNotReceive( 'get_col' );

        $keys = SensitiveFieldRegistry::dynamic_sensitive_keys();
        $this->assertSame( array(), $keys );
    }

    public function test_contains_sensitive_detects_dynamic_custom_field_key(): void {
        // Admin has flagged 'rg' as is_sensitive=1 via the repository.
        $this->wpdb->shouldReceive( 'get_var' )
            ->andReturn( 'wp_ffc_custom_fields' );
        $this->wpdb->shouldReceive( 'get_col' )
            ->andReturn( array( 'rg' ) );

        $payload = array( 'rg' => 'AB1234567' );
        $this->assertTrue( SensitiveFieldRegistry::contains_sensitive( $payload ) );
    }

    public function test_invalidate_dynamic_cache_drops_wp_cache(): void {
        // The method is a thin wrapper — assert it calls wp_cache_delete with
        // the registry's cache key/group.
        Monkey\tearDown();
        Monkey\setUp();

        Functions\expect( 'wp_cache_delete' )
            ->once()
            ->with( 'ffc_sensitive_field_keys_dynamic', 'ffc_sensitive_fields' )
            ->andReturn( true );

        SensitiveFieldRegistry::invalidate_dynamic_cache();
        $this->assertTrue( true );  // Mockery handled the expectation.
    }
}

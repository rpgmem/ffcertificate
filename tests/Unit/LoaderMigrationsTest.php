<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Loader;

/**
 * Tests for Loader's one-shot capability migrations (the `ensure_*` helpers)
 * and `register_ffc_roles_safe()`.
 *
 * Each helper is version-flagged: it reads an option flag, runs a
 * CapabilityMigrator migration (or a RoleRegistrar registration) once, then
 * writes the flag. Those classes are alias-mocked, so these run in separate
 * processes.
 *
 * @covers \FreeFormCertificate\Loader
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class LoaderMigrationsTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        // Constructor wiring — irrelevant here, stub so `new Loader()` is cheap.
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'register_activation_hook' )->justReturn( true );
        Functions\when( 'register_deactivation_hook' )->justReturn( true );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** Invoke a private Loader method via Reflection. */
    private function invoke_private( Loader $loader, string $method ) {
        $ref = new \ReflectionMethod( Loader::class, $method );
        $ref->setAccessible( true );
        return $ref->invokeArgs( $loader, array() );
    }

    /**
     * @dataProvider migration_method_provider
     */
    public function test_ensure_migration_skips_when_flag_already_set( string $method, string $flag, string $cap_method ): void {
        $loader = new Loader();

        Functions\when( 'get_option' )->justReturn( '1' );
        $updated = array();
        Functions\when( 'update_option' )->alias(
            function ( $key, $value ) use ( &$updated ) {
                $updated[ $key ] = $value;
                return true;
            }
        );

        $cm = Mockery::mock( 'alias:FreeFormCertificate\UserDashboard\CapabilityMigrator' );
        $cm->shouldReceive( $cap_method )->never();

        $this->invoke_private( $loader, $method );

        $this->assertArrayNotHasKey( $flag, $updated, 'No re-write when already migrated.' );
    }

    /**
     * @dataProvider migration_method_provider
     */
    public function test_ensure_migration_runs_and_sets_flag( string $method, string $flag, string $cap_method ): void {
        $loader = new Loader();

        Functions\when( 'get_option' )->justReturn( '' );
        $updated = array();
        Functions\when( 'update_option' )->alias(
            function ( $key, $value ) use ( &$updated ) {
                $updated[ $key ] = $value;
                return true;
            }
        );

        $cm = Mockery::mock( 'alias:FreeFormCertificate\UserDashboard\CapabilityMigrator' );
        $cm->shouldReceive( $cap_method )->once();

        $this->invoke_private( $loader, $method );

        $this->assertSame( '1', $updated[ $flag ] ?? null, 'Flag must be set after migration.' );
    }

    /**
     * @return array<string, array{0:string,1:string,2:string}>
     */
    public static function migration_method_provider(): array {
        return array(
            'legacy caps'  => array( 'ensure_legacy_caps_renamed', 'ffc_legacy_caps_renamed_v1', 'migrate_legacy_certificate_caps' ),
            'taxonomy'     => array( 'ensure_taxonomy_renamed', 'ffc_taxonomy_caps_renamed_v1', 'migrate_taxonomy_renames' ),
            'delete caps'  => array( 'ensure_delete_caps_granted', 'ffc_delete_caps_granted_v1', 'migrate_delete_caps_grant' ),
            'export caps'  => array( 'ensure_export_caps_granted', 'ffc_export_caps_granted_v1', 'migrate_export_caps_grant' ),
            'import caps'  => array( 'ensure_import_caps_granted', 'ffc_import_caps_granted_v1', 'migrate_import_caps_grant' ),
            'reasons caps' => array( 'ensure_reasons_caps_wired', 'ffc_reasons_caps_wired_v1', 'migrate_reasons_caps_grant' ),
        );
    }

    // ==================================================================
    // register_ffc_roles_safe()
    // ==================================================================

    public function test_register_ffc_roles_safe_registers_roles_and_relabel_hook(): void {
        $loader = new Loader();

        $rr = Mockery::mock( 'alias:FreeFormCertificate\UserDashboard\RoleRegistrar' );
        $rr->shouldReceive( 'register_role' )->once();
        $rr->shouldReceive( 'register_module_roles' )->once();

        $added = array();
        Functions\when( 'add_action' )->alias(
            function ( $hook ) use ( &$added ) {
                $added[] = $hook;
            }
        );

        $loader->register_ffc_roles_safe();

        $this->assertContains( 'wp_roles_init', $added, 'Should hook relabel_ffc_roles onto wp_roles_init.' );
    }
}

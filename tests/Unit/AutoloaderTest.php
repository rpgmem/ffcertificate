<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for FFC_Autoloader: namespace mapping, kebab-case conversion,
 * class file resolution, and debug helpers.
 *
 * The autoloader is a non-namespaced class (FFC_Autoloader), loaded
 * in bootstrap.php before tests run, so we test the already-registered
 * instance plus a fresh instance for isolated assertions.
 */
class AutoloaderTest extends TestCase {

    private \FFC_Autoloader $autoloader;

    protected function setUp(): void {
        parent::setUp();
        $this->autoloader = new \FFC_Autoloader( FFC_PLUGIN_DIR . 'includes' );
    }

    // ------------------------------------------------------------------
    // Helper: invoke private method via Reflection
    // ------------------------------------------------------------------

    private function invoke_private( string $method, array $args = [] ) {
        $ref = new \ReflectionMethod( \FFC_Autoloader::class, $method );
        $ref->setAccessible( true );
        return $ref->invoke( $this->autoloader, ...$args );
    }

    // ==================================================================
    // to_kebab_case()
    // ==================================================================

    public function test_to_kebab_case_simple(): void {
        $this->assertSame( 'admin', $this->invoke_private( 'to_kebab_case', [ 'Admin' ] ) );
    }

    public function test_to_kebab_case_multi_word(): void {
        $this->assertSame( 'form-editor', $this->invoke_private( 'to_kebab_case', [ 'FormEditor' ] ) );
    }

    public function test_to_kebab_case_with_acronym_csv(): void {
        $result = $this->invoke_private( 'to_kebab_case', [ 'CsvExporter' ] );
        $this->assertSame( 'csv-exporter', $result );
    }

    public function test_to_kebab_case_with_acronym_rest(): void {
        $result = $this->invoke_private( 'to_kebab_case', [ 'RestController' ] );
        $this->assertSame( 'rest-controller', $result );
    }

    public function test_to_kebab_case_with_acronym_cpt(): void {
        $result = $this->invoke_private( 'to_kebab_case', [ 'SelfSchedulingCPT' ] );
        $this->assertStringContainsString( 'cpt', $result );
    }

    public function test_to_kebab_case_with_acronym_pdf(): void {
        $result = $this->invoke_private( 'to_kebab_case', [ 'PdfGenerator' ] );
        $this->assertSame( 'pdf-generator', $result );
    }

    // ==================================================================
    // get_possible_filenames()
    // ==================================================================

    public function test_get_possible_filenames_standard_class(): void {
        $filenames = $this->invoke_private( 'get_possible_filenames', [ 'Admin', '' ] );

        $this->assertContains( 'class-ffc-admin.php', $filenames );
        $this->assertContains( 'ffc-admin.php', $filenames );
        $this->assertContains( 'Admin.php', $filenames );
    }

    public function test_get_possible_filenames_self_scheduling_namespace(): void {
        $filenames = $this->invoke_private( 'get_possible_filenames', [ 'AppointmentHandler', 'SelfScheduling' ] );

        $this->assertContains( 'class-ffc-self-scheduling-appointment-handler.php', $filenames );
        $this->assertContains( 'class-ffc-appointment-handler.php', $filenames );
    }

    public function test_get_possible_filenames_interface(): void {
        $filenames = $this->invoke_private( 'get_possible_filenames', [ 'MigrationStrategyInterface', '' ] );

        $this->assertTrue(
            in_array( 'interface-ffc-migration-strategy-interface.php', $filenames, true )
            || in_array( 'class-ffc-migration-strategy-interface.php', $filenames, true ),
            'Should generate an interface-style filename'
        );
    }

    // ==================================================================
    // get_namespace_map()
    // ==================================================================

    public function test_namespace_map_contains_core_namespaces(): void {
        $namespaces = $this->autoloader->get_namespaces();

        $this->assertContains( 'Admin', $namespaces );
        $this->assertContains( 'Core', $namespaces );
        $this->assertContains( 'Frontend', $namespaces );
        $this->assertContains( 'API', $namespaces );
        $this->assertContains( 'Security', $namespaces );
    }

    public function test_namespace_map_contains_module_namespaces(): void {
        $namespaces = $this->autoloader->get_namespaces();

        $this->assertContains( 'SelfScheduling', $namespaces );
        $this->assertContains( 'Audience', $namespaces );
        $this->assertContains( 'Reregistration', $namespaces );
        $this->assertContains( 'UrlShortener', $namespaces );
        $this->assertContains( 'Scheduling', $namespaces );
    }

    public function test_namespace_map_contains_empty_root(): void {
        $namespaces = $this->autoloader->get_namespaces();
        $this->assertContains( '', $namespaces );
    }

    // ==================================================================
    // find_class_file()
    // ==================================================================

    public function test_find_class_file_admin(): void {
        $file = $this->invoke_private( 'find_class_file', [ 'Admin\\Admin' ] );
        $this->assertNotNull( $file );
        $this->assertStringEndsWith( 'class-ffc-admin.php', $file );
    }

    public function test_find_class_file_encryption(): void {
        $file = $this->invoke_private( 'find_class_file', [ 'Core\\Encryption' ] );
        $this->assertNotNull( $file );
        $this->assertStringEndsWith( 'class-ffc-encryption.php', $file );
    }

    public function test_find_class_file_geofence(): void {
        $file = $this->invoke_private( 'find_class_file', [ 'Security\\Geofence' ] );
        $this->assertNotNull( $file );
        $this->assertStringEndsWith( 'class-ffc-geofence.php', $file );
    }

    public function test_find_class_file_self_scheduling_appointment(): void {
        $file = $this->invoke_private( 'find_class_file', [ 'SelfScheduling\\AppointmentHandler' ] );
        $this->assertNotNull( $file );
        $this->assertStringEndsWith( 'class-ffc-self-scheduling-appointment-handler.php', $file );
    }

    public function test_find_class_file_repository(): void {
        $file = $this->invoke_private( 'find_class_file', [ 'Repositories\\AbstractRepository' ] );
        $this->assertNotNull( $file );
        $this->assertStringEndsWith( 'ffc-abstract-repository.php', $file );
    }

    public function test_find_class_file_unknown_returns_null(): void {
        $file = $this->invoke_private( 'find_class_file', [ 'Nonexistent\\FakeClass' ] );
        $this->assertNull( $file );
    }

    // ==================================================================
    // autoload() — does not load classes outside our namespace
    // ==================================================================

    public function test_autoload_ignores_foreign_namespace(): void {
        $this->autoloader->autoload( 'Some\\Other\\Namespace\\Class' );
        $this->assertTrue( true );
    }

    // ==================================================================
    // debug_class_mapping()
    // ==================================================================

    public function test_debug_class_mapping_valid_class(): void {
        $info = $this->autoloader->debug_class_mapping( 'FreeFormCertificate\\Core\\Encryption' );

        $this->assertSame( 'FreeFormCertificate\\Core\\Encryption', $info['class'] );
        $this->assertSame( 'Core\\Encryption', $info['relative_class'] );
        $this->assertTrue( $info['exists'] );
    }

    public function test_debug_class_mapping_foreign_namespace(): void {
        $info = $this->autoloader->debug_class_mapping( 'Some\\Other\\Class' );

        $this->assertArrayHasKey( 'error', $info );
    }

    public function test_debug_class_mapping_nonexistent_class(): void {
        $info = $this->autoloader->debug_class_mapping( 'FreeFormCertificate\\Core\\NonexistentClass' );

        $this->assertFalse( $info['exists'] );
    }

    // ==================================================================
    // register() — integration test
    // ==================================================================

    public function test_register_makes_autoloader_available(): void {
        $this->assertTrue( class_exists( 'FreeFormCertificate\\Core\\Encryption' ) );
    }

    public function test_register_loads_self_scheduling_classes(): void {
        $this->assertTrue( class_exists( 'FreeFormCertificate\\SelfScheduling\\SelfSchedulingActivator' ) );
    }

    public function test_register_loads_migration_strategy_interface(): void {
        $this->assertTrue( interface_exists( 'FreeFormCertificate\\Migrations\\Strategies\\MigrationStrategyInterface' ) );
    }
}

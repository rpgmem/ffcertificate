<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationCsvExporter;

/**
 * @covers \FreeFormCertificate\Reregistration\ReregistrationCsvExporter
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ReregistrationCsvExporterTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'sanitize_file_name' )->returnArg();

        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', '/tmp/' );
        }
    }

    protected function tearDown(): void {
        unset( $_GET['action'], $_GET['id'], $_GET['_wpnonce'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // handle_export() — no action parameter
    // ==================================================================

    public function test_handle_export_returns_early_without_action(): void {
        unset( $_GET['action'] );
        ReregistrationCsvExporter::handle_export();
        $this->assertTrue( true ); // Early return, no error
    }

    // ==================================================================
    // handle_export() — wrong action
    // ==================================================================

    public function test_handle_export_returns_early_with_wrong_action(): void {
        $_GET['action'] = 'something_else';
        $_GET['id'] = 1;
        ReregistrationCsvExporter::handle_export();
        $this->assertTrue( true );
    }

    // ==================================================================
    // handle_export() — missing id
    // ==================================================================

    public function test_handle_export_returns_early_without_id(): void {
        $_GET['action'] = 'export_csv';
        unset( $_GET['id'] );
        ReregistrationCsvExporter::handle_export();
        $this->assertTrue( true );
    }

    // ==================================================================
    // handle_export() — invalid nonce
    // ==================================================================

    public function test_handle_export_returns_early_with_invalid_nonce(): void {
        $_GET['action'] = 'export_csv';
        $_GET['id'] = '5';
        $_GET['_wpnonce'] = 'bad_nonce';
        Functions\when( 'wp_verify_nonce' )->justReturn( false );

        ReregistrationCsvExporter::handle_export();
        $this->assertTrue( true );
    }

    // ==================================================================
    // handle_export() — reregistration not found
    // ==================================================================

    public function test_handle_export_returns_early_when_rereg_not_found(): void {
        $_GET['action'] = 'export_csv';
        $_GET['id'] = '5';
        $_GET['_wpnonce'] = 'valid';
        Functions\when( 'wp_verify_nonce' )->justReturn( true );
        // Holds the export cap (GAP G) so the gate passes and the not-found
        // path is exercised.
        Functions\when( 'current_user_can' )->justReturn( true );

        $repoMock = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationRepository' );
        $repoMock->shouldReceive( 'get_by_id' )->with( 5 )->andReturn( null );

        ReregistrationCsvExporter::handle_export();
        $this->assertTrue( true );
    }

    // ==================================================================
    // handle_export() — lacks the ffc_export_reregistration cap (GAP G)
    // ==================================================================

    public function test_handle_export_returns_early_without_export_cap(): void {
        $_GET['action'] = 'export_csv';
        $_GET['id'] = '5';
        $_GET['_wpnonce'] = 'valid';
        Functions\when( 'wp_verify_nonce' )->justReturn( true );
        // No FFC caps and not an admin → export gate must block before lookup.
        Functions\when( 'current_user_can' )->justReturn( false );

        $repoMock = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationRepository' );
        $repoMock->shouldReceive( 'get_by_id' )->never();

        ReregistrationCsvExporter::handle_export();
        $this->assertTrue( true );
    }

    // ==================================================================
    // Reflection helpers
    // ==================================================================

    /**
     * Invoke a private static method by name.
     *
     * @param string            $method Method name.
     * @param array<int, mixed> $args   Positional args.
     * @return mixed
     */
    private function invoke_static( string $method, array $args ) {
        $ref = new \ReflectionMethod( ReregistrationCsvExporter::class, $method );
        $ref->setAccessible( true );
        return $ref->invokeArgs( null, $args );
    }

    // ==================================================================
    // stringify_value() — checkbox
    // ==================================================================

    public function test_stringify_value_checkbox_truthy_returns_yes(): void {
        $field = (object) array( 'field_type' => 'checkbox', 'field_key' => 'agree' );

        $this->assertSame( 'Yes', $this->invoke_static( 'stringify_value', array( $field, '1' ) ) );
        $this->assertSame( 'Yes', $this->invoke_static( 'stringify_value', array( $field, 1 ) ) );
        $this->assertSame( 'Yes', $this->invoke_static( 'stringify_value', array( $field, true ) ) );
    }

    public function test_stringify_value_checkbox_falsy_returns_no(): void {
        $field = (object) array( 'field_type' => 'checkbox', 'field_key' => 'agree' );

        $this->assertSame( 'No', $this->invoke_static( 'stringify_value', array( $field, '0' ) ) );
        $this->assertSame( 'No', $this->invoke_static( 'stringify_value', array( $field, '' ) ) );
    }

    // ==================================================================
    // stringify_value() — dependent_select
    // ==================================================================

    public function test_stringify_value_dependent_select_from_json(): void {
        $field = (object) array( 'field_type' => 'dependent_select', 'field_key' => 'loc' );
        $json  = wp_json_encode( array( 'parent' => 'SP', 'child' => 'Centro' ) );

        $this->assertSame( 'SP / Centro', $this->invoke_static( 'stringify_value', array( $field, $json ) ) );
    }

    public function test_stringify_value_dependent_select_from_array(): void {
        $field = (object) array( 'field_type' => 'dependent_select', 'field_key' => 'loc' );

        $this->assertSame(
            'SP / Centro',
            $this->invoke_static( 'stringify_value', array( $field, array( 'parent' => 'SP', 'child' => 'Centro' ) ) )
        );
    }

    public function test_stringify_value_dependent_select_parent_only_trims_separator(): void {
        $field = (object) array( 'field_type' => 'dependent_select', 'field_key' => 'loc' );

        $this->assertSame(
            'SP',
            $this->invoke_static( 'stringify_value', array( $field, array( 'parent' => 'SP', 'child' => '' ) ) )
        );
    }

    public function test_stringify_value_dependent_select_non_array_returns_empty(): void {
        $field = (object) array( 'field_type' => 'dependent_select', 'field_key' => 'loc' );

        $this->assertSame( '', $this->invoke_static( 'stringify_value', array( $field, 'not-json' ) ) );
    }

    // ==================================================================
    // stringify_value() — working_hours
    // ==================================================================

    public function test_stringify_value_working_hours_empty_json_returns_empty(): void {
        $field = (object) array( 'field_type' => 'working_hours', 'field_key' => 'wh' );

        $this->assertSame( '', $this->invoke_static( 'stringify_value', array( $field, '[]' ) ) );
    }

    public function test_stringify_value_working_hours_keeps_raw_json_string(): void {
        $field = (object) array( 'field_type' => 'working_hours', 'field_key' => 'wh' );

        $this->assertSame( '{"mon":"9-5"}', $this->invoke_static( 'stringify_value', array( $field, '{"mon":"9-5"}' ) ) );
    }

    public function test_stringify_value_working_hours_array_encodes_json(): void {
        $field = (object) array( 'field_type' => 'working_hours', 'field_key' => 'wh' );

        $this->assertSame(
            '{"mon":"9-5"}',
            $this->invoke_static( 'stringify_value', array( $field, array( 'mon' => '9-5' ) ) )
        );
    }

    // ==================================================================
    // stringify_value() — default
    // ==================================================================

    public function test_stringify_value_default_scalar(): void {
        $field = (object) array( 'field_type' => 'text', 'field_key' => 'name' );

        $this->assertSame( 'Alice', $this->invoke_static( 'stringify_value', array( $field, 'Alice' ) ) );
        $this->assertSame( '42', $this->invoke_static( 'stringify_value', array( $field, 42 ) ) );
    }

    public function test_stringify_value_default_array_imploded(): void {
        $field = (object) array( 'field_type' => 'multiselect', 'field_key' => 'tags' );

        $this->assertSame(
            'a, b, c',
            $this->invoke_static( 'stringify_value', array( $field, array( 'a', 'b', 'c' ) ) )
        );
    }

    public function test_stringify_value_default_non_scalar_returns_empty(): void {
        $field = (object) array( 'field_type' => 'text', 'field_key' => 'x' );

        $this->assertSame( '', $this->invoke_static( 'stringify_value', array( $field, new \stdClass() ) ) );
    }

    // ==================================================================
    // decrypt_sensitive()
    // ==================================================================

    public function test_decrypt_sensitive_skips_non_sensitive_and_decrypts_sensitive(): void {
        $enc = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
        $enc->shouldReceive( 'decrypt' )->with( 'ENC' )->andReturn( '123.456.789-00' );

        $fields = array(
            (object) array( 'field_key' => 'cpf', 'is_sensitive' => 1 ),
            (object) array( 'field_key' => 'name', 'is_sensitive' => 0 ),
            (object) array( 'field_key' => 'rf', 'is_sensitive' => 1 ),
        );
        $values = array(
            'cpf'  => 'ENC',
            'name' => 'Alice',
            // rf missing → skipped.
        );

        $out = $this->invoke_static( 'decrypt_sensitive', array( $fields, $values ) );

        $this->assertSame( '123.456.789-00', $out['cpf'] );
        $this->assertSame( 'Alice', $out['name'] );
    }

    public function test_decrypt_sensitive_keeps_value_when_decrypt_returns_null(): void {
        $enc = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
        $enc->shouldReceive( 'decrypt' )->with( 'BAD' )->andReturn( null );

        $fields = array( (object) array( 'field_key' => 'cpf', 'is_sensitive' => 1 ) );
        $values = array( 'cpf' => 'BAD' );

        $out = $this->invoke_static( 'decrypt_sensitive', array( $fields, $values ) );
        $this->assertSame( 'BAD', $out['cpf'] );
    }

    public function test_decrypt_sensitive_skips_empty_or_non_string_values(): void {
        Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );

        $fields = array(
            (object) array( 'field_key' => 'cpf', 'is_sensitive' => 1 ),
            (object) array( 'field_key' => 'rf', 'is_sensitive' => 1 ),
        );
        $values = array(
            'cpf' => '',
            'rf'  => 12345,
        );

        $out = $this->invoke_static( 'decrypt_sensitive', array( $fields, $values ) );
        $this->assertSame( '', $out['cpf'] );
        $this->assertSame( 12345, $out['rf'] );
    }

    // ==================================================================
    // get_custom_fields_for_reregistration() — de-dups by field id
    // ==================================================================

    public function test_get_custom_fields_dedups_across_audiences(): void {
        $reregMock = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\ReregistrationRepository' );
        $reregMock->shouldReceive( 'get_audience_ids' )->with( 7 )->andReturn( array( 1, 2 ) );

        $field_a = (object) array( 'id' => 10, 'field_label' => 'A' );
        $field_b = (object) array( 'id' => 20, 'field_label' => 'B' );

        $cfMock = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\CustomFieldRepository' );
        $cfMock->shouldReceive( 'get_by_audience_with_parents' )
            ->with( 1, true )
            ->andReturn( array( $field_a, $field_b ) );
        $cfMock->shouldReceive( 'get_by_audience_with_parents' )
            ->with( 2, true )
            // Field A repeats → must be de-duped.
            ->andReturn( array( $field_a ) );

        $rereg = (object) array( 'id' => 7 );
        $out   = $this->invoke_static( 'get_custom_fields_for_reregistration', array( $rereg ) );

        $this->assertCount( 2, $out );
        $this->assertSame( 10, $out[0]->id );
        $this->assertSame( 20, $out[1]->id );
    }
}

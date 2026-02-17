<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\CsvExporter;

/**
 * Tests for CsvExporter: fixed headers, CSV row formatting, and CsvExportTrait.
 *
 * Uses Reflection to access private/protected methods for testing business logic.
 * Uses newInstanceWithoutConstructor() to avoid SubmissionRepository dependency.
 */
class CsvExporterTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var CsvExporter */
    private $exporter;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'get_the_title' )->justReturn( 'Test Form' );
        Functions\when( 'get_userdata' )->alias( function ( $id ) {
            $user = new \stdClass();
            $user->display_name = 'Admin User';
            return $user;
        } );

        // Create instance without constructor to avoid SubmissionRepository
        $ref = new \ReflectionClass( CsvExporter::class );
        $this->exporter = $ref->newInstanceWithoutConstructor();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Invoke a private/protected method on the exporter.
     */
    private function invoke( string $method, array $args = [] ) {
        $ref = new \ReflectionMethod( CsvExporter::class, $method );
        $ref->setAccessible( true );
        return $ref->invokeArgs( $this->exporter, $args );
    }

    // ==================================================================
    // get_fixed_headers()
    // ==================================================================

    public function test_fixed_headers_without_edit_columns_returns_14(): void {
        $headers = $this->invoke( 'get_fixed_headers', array( false ) );
        $this->assertCount( 14, $headers );
    }

    public function test_fixed_headers_with_edit_columns_returns_17(): void {
        $headers = $this->invoke( 'get_fixed_headers', array( true ) );
        $this->assertCount( 17, $headers );
    }

    public function test_fixed_headers_contains_expected_strings(): void {
        $headers = $this->invoke( 'get_fixed_headers', array( false ) );
        $this->assertContains( 'ID', $headers );
        $this->assertContains( 'Form', $headers );
        $this->assertContains( 'E-mail', $headers );
        $this->assertContains( 'User IP', $headers );
        $this->assertContains( 'CPF/RF', $headers );
        $this->assertContains( 'Auth Code', $headers );
        $this->assertContains( 'Token', $headers );
        $this->assertContains( 'Consent Given', $headers );
        $this->assertContains( 'Status', $headers );
    }

    public function test_fixed_headers_edit_columns_at_end(): void {
        $headers = $this->invoke( 'get_fixed_headers', array( true ) );
        $this->assertSame( 'Was Edited', $headers[14] );
        $this->assertSame( 'Edit Date', $headers[15] );
        $this->assertSame( 'Edited By', $headers[16] );
    }

    // ==================================================================
    // format_csv_row()
    // ==================================================================

    private function sample_row(): array {
        return array(
            'id'                => 1,
            'form_id'           => 42,
            'user_id'           => 10,
            'submission_date'   => '2025-01-15 10:30:00',
            'email'             => 'test@example.com',
            'email_encrypted'   => '',
            'user_ip'           => '192.168.1.1',
            'user_ip_encrypted' => '',
            'cpf_rf'            => '123.456.789-00',
            'cpf_rf_encrypted'  => '',
            'auth_code'         => 'ABC123',
            'magic_token'       => 'abc123def456',
            'consent_given'     => 1,
            'consent_date'      => '2025-01-15 10:30:00',
            'consent_ip'        => '192.168.1.1',
            'consent_text'      => 'I agree',
            'status'            => 'publish',
            'data'              => '{"field_name":"John","field_city":"SP"}',
            'data_encrypted'    => '',
            'edited_at'         => '',
            'edited_by'         => '',
        );
    }

    public function test_format_csv_row_basic_returns_correct_count(): void {
        $row = $this->sample_row();
        $dynamic_keys = array( 'field_name', 'field_city' );
        $result = $this->invoke( 'format_csv_row', array( $row, $dynamic_keys, false ) );
        // 14 fixed + 2 dynamic = 16
        $this->assertCount( 16, $result );
    }

    public function test_format_csv_row_fixed_columns_values(): void {
        $row = $this->sample_row();
        $result = $this->invoke( 'format_csv_row', array( $row, array(), false ) );
        $this->assertSame( 1, $result[0] );                           // ID
        $this->assertSame( 'Test Form', $result[1] );                 // Form title
        $this->assertSame( 10, $result[2] );                          // User ID
        $this->assertSame( '2025-01-15 10:30:00', $result[3] );       // Date
        $this->assertSame( 'test@example.com', $result[4] );          // Email
        $this->assertSame( '192.168.1.1', $result[5] );               // IP
        $this->assertSame( '123.456.789-00', $result[6] );            // CPF
        $this->assertSame( 'ABC123', $result[7] );                    // Auth Code
        $this->assertSame( 'abc123def456', $result[8] );              // Token
        $this->assertSame( 'Yes', $result[9] );                       // Consent Given
        $this->assertSame( 'publish', $result[13] );                  // Status
    }

    public function test_format_csv_row_consent_no(): void {
        $row = $this->sample_row();
        $row['consent_given'] = 0;
        $result = $this->invoke( 'format_csv_row', array( $row, array(), false ) );
        $this->assertSame( 'No', $result[9] );
    }

    public function test_format_csv_row_deleted_form_title(): void {
        Functions\when( 'get_the_title' )->justReturn( '' );
        $row = $this->sample_row();
        $result = $this->invoke( 'format_csv_row', array( $row, array(), false ) );
        $this->assertSame( '(Deleted)', $result[1] );
    }

    public function test_format_csv_row_with_edit_columns(): void {
        $row = $this->sample_row();
        $row['edited_at'] = '2025-02-01 09:00:00';
        $row['edited_by'] = 5;
        $result = $this->invoke( 'format_csv_row', array( $row, array(), true ) );
        // 14 fixed + 3 edit = 17
        $this->assertCount( 17, $result );
        $this->assertSame( 'Yes', $result[14] );                      // Was Edited
        $this->assertSame( '2025-02-01 09:00:00', $result[15] );      // Edit Date
        $this->assertSame( 'Admin User', $result[16] );               // Edited By
    }

    public function test_format_csv_row_not_edited_empty_edit_columns(): void {
        $row = $this->sample_row();
        $result = $this->invoke( 'format_csv_row', array( $row, array(), true ) );
        $this->assertSame( '', $result[14] );
        $this->assertSame( '', $result[15] );
        $this->assertSame( '', $result[16] );
    }

    public function test_format_csv_row_dynamic_columns_values(): void {
        $row = $this->sample_row();
        $dynamic_keys = array( 'field_name', 'field_city' );
        $result = $this->invoke( 'format_csv_row', array( $row, $dynamic_keys, false ) );
        $this->assertSame( 'John', $result[14] );
        $this->assertSame( 'SP', $result[15] );
    }

    public function test_format_csv_row_missing_dynamic_key_returns_empty(): void {
        $row = $this->sample_row();
        $dynamic_keys = array( 'field_name', 'nonexistent_field' );
        $result = $this->invoke( 'format_csv_row', array( $row, $dynamic_keys, false ) );
        $this->assertSame( 'John', $result[14] );
        $this->assertSame( '', $result[15] );
    }

    public function test_format_csv_row_empty_optional_fields(): void {
        $row = $this->sample_row();
        $row['user_id']       = '';
        $row['auth_code']     = '';
        $row['magic_token']   = '';
        $row['consent_date']  = '';
        $row['consent_ip']    = '';
        $row['consent_text']  = '';
        $result = $this->invoke( 'format_csv_row', array( $row, array(), false ) );
        $this->assertSame( '', $result[2] );  // User ID
        $this->assertSame( '', $result[7] );  // Auth Code
        $this->assertSame( '', $result[8] );  // Token
        $this->assertSame( '', $result[10] ); // Consent Date
        $this->assertSame( '', $result[11] ); // Consent IP
        $this->assertSame( '', $result[12] ); // Consent Text
    }

    // ==================================================================
    // CsvExportTrait: build_dynamic_headers()
    // ==================================================================

    public function test_trait_build_dynamic_headers_snake_case(): void {
        $keys = array( 'first_name', 'last_name', 'phone_number' );
        $headers = $this->invoke( 'build_dynamic_headers', array( $keys ) );
        $this->assertSame( 'First Name', $headers[0] );
        $this->assertSame( 'Last Name', $headers[1] );
        $this->assertSame( 'Phone Number', $headers[2] );
    }

    public function test_trait_build_dynamic_headers_kebab_case(): void {
        $headers = $this->invoke( 'build_dynamic_headers', array( array( 'my-field' ) ) );
        $this->assertSame( 'My Field', $headers[0] );
    }

    public function test_trait_build_dynamic_headers_empty_array(): void {
        $headers = $this->invoke( 'build_dynamic_headers', array( array() ) );
        $this->assertSame( array(), $headers );
    }

    // ==================================================================
    // CsvExportTrait: decode_json_field()
    // ==================================================================

    public function test_trait_decode_json_field_plain_text(): void {
        $row = array( 'data' => '{"name":"Alice","age":"30"}', 'data_encrypted' => '' );
        $result = $this->invoke( 'decode_json_field', array( $row, 'data', 'data_encrypted' ) );
        $this->assertSame( array( 'name' => 'Alice', 'age' => '30' ), $result );
    }

    public function test_trait_decode_json_field_empty_returns_empty_array(): void {
        $row = array( 'data' => '', 'data_encrypted' => '' );
        $result = $this->invoke( 'decode_json_field', array( $row, 'data', 'data_encrypted' ) );
        $this->assertSame( array(), $result );
    }

    public function test_trait_decode_json_field_invalid_json_returns_empty_array(): void {
        $row = array( 'data' => 'not json', 'data_encrypted' => '' );
        $result = $this->invoke( 'decode_json_field', array( $row, 'data', 'data_encrypted' ) );
        $this->assertSame( array(), $result );
    }

    // ==================================================================
    // CsvExportTrait: extract_dynamic_keys()
    // ==================================================================

    public function test_trait_extract_dynamic_keys_unique_across_rows(): void {
        $rows = array(
            array( 'data' => '{"name":"A","email":"a@b.com"}', 'data_encrypted' => '' ),
            array( 'data' => '{"name":"B","phone":"123"}', 'data_encrypted' => '' ),
        );
        $keys = $this->invoke( 'extract_dynamic_keys', array( $rows, 'data', 'data_encrypted' ) );
        $this->assertContains( 'name', $keys );
        $this->assertContains( 'email', $keys );
        $this->assertContains( 'phone', $keys );
        // name should appear only once
        $this->assertCount( 3, $keys );
    }

    public function test_trait_extract_dynamic_keys_empty_rows(): void {
        $keys = $this->invoke( 'extract_dynamic_keys', array( array(), 'data', 'data_encrypted' ) );
        $this->assertSame( array(), $keys );
    }

    // ==================================================================
    // CsvExportTrait: extract_dynamic_values()
    // ==================================================================

    public function test_trait_extract_dynamic_values_in_key_order(): void {
        $row = array( 'data' => '{"b":"2","a":"1","c":"3"}', 'data_encrypted' => '' );
        $keys = array( 'a', 'b', 'c' );
        $values = $this->invoke( 'extract_dynamic_values', array( $row, $keys, 'data', 'data_encrypted' ) );
        $this->assertSame( array( '1', '2', '3' ), $values );
    }

    public function test_trait_extract_dynamic_values_missing_key(): void {
        $row = array( 'data' => '{"a":"1"}', 'data_encrypted' => '' );
        $keys = array( 'a', 'missing' );
        $values = $this->invoke( 'extract_dynamic_values', array( $row, $keys, 'data', 'data_encrypted' ) );
        $this->assertSame( array( '1', '' ), $values );
    }

    public function test_trait_extract_dynamic_values_array_value_joined(): void {
        $row = array( 'data' => '{"tags":["php","js"]}', 'data_encrypted' => '' );
        $keys = array( 'tags' );
        $values = $this->invoke( 'extract_dynamic_values', array( $row, $keys, 'data', 'data_encrypted' ) );
        $this->assertSame( array( 'php, js' ), $values );
    }
}

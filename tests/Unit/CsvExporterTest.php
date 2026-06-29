<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\CsvExporter;

/**
 * Tests for CsvExporter: fixed headers, CSV row formatting, CsvExportTrait,
 * and the AJAX-driven batch export machinery (start / batch / download /
 * cleanup / key-scan / row-count).
 *
 * Uses Reflection to access private/protected methods for testing business logic.
 * Uses newInstanceWithoutConstructor() to avoid SubmissionRepository dependency.
 *
 * Runs in separate processes: the AJAX/stream paths define namespaced helper
 * stubs (FreeFormCertificate\Admin\header, readfile, fopen, set_time_limit, …)
 * for native functions PHP cannot redefine globally and cannot undefine, which
 * would otherwise leak "MissingFunctionExpectations" into sibling tests.
 *
 * v5.0.0: Updated for split CPF/RF columns (CPF + RF instead of CPF/RF).
 *         Fixed columns count: 15 (was 14), with edit: 18 (was 17).
 *
 * @covers \FreeFormCertificate\Admin\CsvExporter
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CsvExporterTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var CsvExporter */
    private $exporter;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // pcov does not attribute coverage to a class first autoloaded mid-test
        // method, so preload the target class here.
        class_exists( '\\FreeFormCertificate\Admin\CsvExporter' );

        Functions\when( '__' )->returnArg();
        Functions\when( 'get_the_title' )->justReturn( 'Test Form' );
        Functions\when( 'get_userdata' )->alias( function ( $id ) {
            $user = new \stdClass();
            $user->display_name = 'Admin User';
            return $user;
        } );
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'wp_date' )->alias( function ( $format, $ts = null ) {
            return gmdate( $format, $ts ?? time() );
        } );
        Functions\when( 'wp_timezone' )->alias( function () {
            return new \DateTimeZone( 'UTC' );
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
    // v5.0.0: 15 fixed headers (CPF + RF instead of CPF/RF)
    // ==================================================================

    public function test_fixed_headers_without_edit_columns_returns_15(): void {
        $headers = $this->invoke( 'get_fixed_headers', array( false ) );
        $this->assertCount( 15, $headers );
    }

    public function test_fixed_headers_with_edit_columns_returns_18(): void {
        $headers = $this->invoke( 'get_fixed_headers', array( true ) );
        $this->assertCount( 18, $headers );
    }

    public function test_fixed_headers_contains_expected_strings(): void {
        $headers = $this->invoke( 'get_fixed_headers', array( false ) );
        $this->assertContains( 'ID', $headers );
        $this->assertContains( 'Form', $headers );
        $this->assertContains( 'E-mail', $headers );
        $this->assertContains( 'User IP', $headers );
        $this->assertContains( 'CPF', $headers );
        $this->assertContains( 'RF', $headers );
        $this->assertContains( 'Auth Code', $headers );
        $this->assertContains( 'Token', $headers );
        $this->assertContains( 'Consent Given', $headers );
        $this->assertContains( 'Status', $headers );
    }

    public function test_fixed_headers_edit_columns_at_end(): void {
        $headers = $this->invoke( 'get_fixed_headers', array( true ) );
        $this->assertSame( 'Was Edited', $headers[15] );
        $this->assertSame( 'Edit Date', $headers[16] );
        $this->assertSame( 'Edited By', $headers[17] );
    }

    // ==================================================================
    // format_csv_row()
    // v5.0.0: Split CPF/RF — indices shifted by +1 from Auth Code onward
    //   [0] ID, [1] Form, [2] User ID, [3] Date, [4] Email, [5] IP,
    //   [6] CPF, [7] RF, [8] Auth Code, [9] Token, [10] Consent Given,
    //   [11] Consent Date, [12] Consent IP, [13] Consent Text, [14] Status
    // ==================================================================

    private function sample_row(): array {
        return array(
            'id'                => 1,
            'form_id'           => 42,
            'user_id'           => 10,
            // `submission_date` is unix UTC int since 6.6.0 (#249 sub-escopo a).
            // 1736937000 = 2025-01-15 10:30:00 UTC.
            'submission_date'   => 1736937000,
            'email'             => 'test@example.com',
            'email_encrypted'   => '',
            'user_ip'           => '192.168.1.1',
            'user_ip_encrypted' => '',
            'cpf'               => '123.456.789-00',
            'cpf_encrypted'     => '',
            'rf'                => '',
            'rf_encrypted'      => '',
            'cpf_rf'            => '',
            'cpf_rf_encrypted'  => '',
            'auth_code'         => 'ABC123',
            'magic_token'       => 'abc123def456',
            'consent_given'     => 1,
            // Category A instant since 6.6.0 (#249 sub-escopo d) — unix UTC.
            'consent_date'      => 1736937000,
            // consent_ip derived from decrypted user_ip
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
        // 15 fixed + 2 dynamic = 17
        $this->assertCount( 17, $result );
    }

    public function test_format_csv_row_fixed_columns_values(): void {
        $row = $this->sample_row();
        $result = $this->invoke( 'format_csv_row', array( $row, array(), false ) );
        $this->assertSame( 1, $result[0] );                           // ID
        $this->assertSame( 'Test Form', $result[1] );                 // Form title
        $this->assertSame( 10, $result[2] );                          // User ID
        // Formatted via DateFormatter (UTC stub in setUp) — plugin default
        // `date_format` is 'd/m/Y' and `time_format` is 'H:i', so the unix
        // 1736937000 (= 2025-01-15 10:30 UTC) lands as below.
        $this->assertSame( '15/01/2025 10:30', $result[3] );          // Date
        $this->assertSame( 'test@example.com', $result[4] );          // Email
        $this->assertSame( '192.168.1.1', $result[5] );               // IP
        $this->assertSame( '123.456.789-00', $result[6] );            // CPF
        $this->assertSame( '', $result[7] );                          // RF (empty)
        $this->assertSame( 'ABC123', $result[8] );                    // Auth Code
        $this->assertSame( 'abc123def456', $result[9] );              // Token
        $this->assertSame( 'Yes', $result[10] );                      // Consent Given
        $this->assertSame( 'publish', $result[14] );                  // Status
    }

    public function test_format_csv_row_consent_no(): void {
        $row = $this->sample_row();
        $row['consent_given'] = 0;
        $result = $this->invoke( 'format_csv_row', array( $row, array(), false ) );
        $this->assertSame( 'No', $result[10] );
    }

    public function test_format_csv_row_deleted_form_title(): void {
        Functions\when( 'get_the_title' )->justReturn( '' );
        $row = $this->sample_row();
        $result = $this->invoke( 'format_csv_row', array( $row, array(), false ) );
        $this->assertSame( '(Deleted)', $result[1] );
    }

    public function test_format_csv_row_with_edit_columns(): void {
        $row = $this->sample_row();
        // `edited_at` is unix UTC int since 6.6.0 (#249 sub-escopo d).
        // 1738400400 = 2025-02-01 09:00:00 UTC.
        $row['edited_at'] = 1738400400;
        $row['edited_by'] = 5;
        $result = $this->invoke( 'format_csv_row', array( $row, array(), true ) );
        // 15 fixed + 3 edit = 18
        $this->assertCount( 18, $result );
        $this->assertSame( 'Yes', $result[15] );                      // Was Edited
        // DateFormatter default ('d/m/Y H:i') under UTC stub.
        $this->assertSame( '01/02/2025 09:00', $result[16] );         // Edit Date
        $this->assertSame( 'Admin User', $result[17] );               // Edited By
    }

    public function test_format_csv_row_not_edited_empty_edit_columns(): void {
        $row = $this->sample_row();
        $result = $this->invoke( 'format_csv_row', array( $row, array(), true ) );
        $this->assertSame( '', $result[15] );
        $this->assertSame( '', $result[16] );
        $this->assertSame( '', $result[17] );
    }

    public function test_format_csv_row_dynamic_columns_values(): void {
        $row = $this->sample_row();
        $dynamic_keys = array( 'field_name', 'field_city' );
        $result = $this->invoke( 'format_csv_row', array( $row, $dynamic_keys, false ) );
        $this->assertSame( 'John', $result[15] );
        $this->assertSame( 'SP', $result[16] );
    }

    public function test_format_csv_row_missing_dynamic_key_returns_empty(): void {
        $row = $this->sample_row();
        $dynamic_keys = array( 'field_name', 'nonexistent_field' );
        $result = $this->invoke( 'format_csv_row', array( $row, $dynamic_keys, false ) );
        $this->assertSame( 'John', $result[15] );
        $this->assertSame( '', $result[16] );
    }

    public function test_format_csv_row_empty_optional_fields(): void {
        $row = $this->sample_row();
        $row['user_id']       = '';
        $row['auth_code']     = '';
        $row['magic_token']   = '';
        $row['consent_date']  = '';
        $row['user_ip']       = '';
        $row['user_ip_encrypted'] = '';
        $row['consent_text']  = '';
        $result = $this->invoke( 'format_csv_row', array( $row, array(), false ) );
        $this->assertSame( '', $result[2] );   // User ID
        $this->assertSame( '', $result[8] );   // Auth Code
        $this->assertSame( '', $result[9] );   // Token
        $this->assertSame( '', $result[11] );  // Consent Date
        $this->assertSame( '', $result[12] );  // Consent IP
        $this->assertSame( '', $result[13] );  // Consent Text
    }

    public function test_format_csv_row_rf_only(): void {
        $row = $this->sample_row();
        $row['cpf'] = '';
        $row['rf'] = '1234567';
        $result = $this->invoke( 'format_csv_row', array( $row, array(), false ) );
        $this->assertSame( '', $result[6] );           // CPF empty
        $this->assertSame( '1234567', $result[7] );    // RF populated
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

    // ==================================================================
    // AJAX machinery helpers
    // ==================================================================

    /** Inject a mock SubmissionRepository into the protected `repository` property. */
    private function set_repository( $repo ): void {
        $prop = new \ReflectionProperty( CsvExporter::class, 'repository' );
        $prop->setAccessible( true );
        $prop->setValue( $this->exporter, $repo );
    }

    /** Make wp_send_json_error / wp_send_json_success / wp_die halt as in production. */
    private function stub_terminators(): void {
        Functions\when( 'wp_send_json_error' )->alias(
            static function () {
                throw new \RuntimeException( 'json_error' );
            }
        );
        Functions\when( 'wp_send_json_success' )->alias(
            static function () {
                throw new \RuntimeException( 'json_success' );
            }
        );
        Functions\when( 'wp_die' )->alias(
            static function () {
                throw new \RuntimeException( 'wp_die' );
            }
        );
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
    }

    /** Pass the shared check_ajax_referer / capability gate. */
    private function pass_gates(): void {
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'wp_verify_nonce' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'get_current_user_id' )->justReturn( 1 );
    }

    // ==================================================================
    // register_ajax_hooks()
    // ==================================================================

    public function test_register_ajax_hooks_registers_three_actions(): void {
        $hooks = array();
        Functions\when( 'add_action' )->alias(
            static function ( $hook ) use ( &$hooks ) {
                $hooks[] = $hook;
                return true;
            }
        );

        $this->exporter->register_ajax_hooks();

        $this->assertSame(
            array( 'wp_ajax_ffc_csv_export_start', 'wp_ajax_ffc_csv_export_batch', 'wp_ajax_ffc_csv_export_download' ),
            $hooks
        );
    }

    // ==================================================================
    // ajax_start() — guard branches + happy path
    // ==================================================================

    public function test_ajax_start_rejects_without_capability(): void {
        $this->stub_terminators();
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( false );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'json_error' );
        $this->exporter->ajax_start();
    }

    public function test_ajax_start_rejects_when_no_records(): void {
        $this->stub_terminators();
        $this->pass_gates();
        Functions\when( 'wp_raise_memory_limit' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Admin\set_time_limit' )->justReturn( true );

        $repo = \Mockery::mock( 'FreeFormCertificate\Repositories\SubmissionRepository' );
        $repo->shouldReceive( 'getExportKeysBatch' )->andReturn( array() );
        $repo->shouldReceive( 'countForExport' )->once()->andReturn( 0 );
        $this->set_repository( $repo );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'json_error' );
        $this->exporter->ajax_start();
    }

    public function test_ajax_start_happy_path_writes_header_and_returns_job(): void {
        $this->stub_terminators();
        $this->pass_gates();
        Functions\when( 'wp_raise_memory_limit' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Admin\set_time_limit' )->justReturn( true );
        Functions\when( 'sanitize_key' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'absint' )->alias( static function ( $v ) { return abs( (int) $v ); } );
        Functions\when( 'get_the_title' )->justReturn( 'My Form' );
        Functions\when( 'trailingslashit' )->alias( static function ( $p ) { return rtrim( (string) $p, '/' ) . '/'; } );
        Functions\when( 'wp_mkdir_p' )->alias(
            static function ( $dir ) { return is_dir( $dir ) || @mkdir( $dir, 0777, true ); }
        );
        Functions\when( 'wp_generate_uuid4' )->justReturn( 'job-uuid-1234' );
        Functions\when( 'set_transient' )->justReturn( true );
        Functions\when( 'apply_filters' )->alias(
            static function () {
                $a = func_get_args();
                return $a[1] ?? null;
            }
        );

        $tmp_base = sys_get_temp_dir() . '/ffc-start-' . uniqid();
        @mkdir( $tmp_base, 0777, true );
        Functions\when( 'wp_upload_dir' )->justReturn( array( 'basedir' => $tmp_base ) );

        // Native file fns used inside the Admin namespace: stub to no-op so the
        // .htaccess guard write does not touch a real fs path unexpectedly.
        Functions\when( 'FreeFormCertificate\Admin\file_exists' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Admin\file_put_contents' )->justReturn( 1 );

        $_POST['form_ids'] = array( 42 );
        $_POST['status']   = 'publish';

        $repo = \Mockery::mock( 'FreeFormCertificate\Repositories\SubmissionRepository' );
        $repo->shouldReceive( 'getExportKeysBatch' )->andReturn(
            array( array( 'id' => 5, 'data' => '{"city":"SP"}' ) ),
            array()
        );
        $repo->shouldReceive( 'countForExport' )->once()->andReturn( 3 );
        $repo->shouldReceive( 'hasEditInfo' )->once()->andReturn( false );
        $this->set_repository( $repo );

        $captured = null;
        Functions\when( 'wp_send_json_success' )->alias(
            static function ( $data ) use ( &$captured ) {
                $captured = $data;
                throw new \RuntimeException( 'json_success' );
            }
        );

        try {
            $this->exporter->ajax_start();
            $this->fail( 'expected wp_send_json_success to halt' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 'json_success', $e->getMessage() );
        }

        $this->assertIsArray( $captured );
        $this->assertSame( 'job-uuid-1234', $captured['job_id'] );
        $this->assertSame( 3, $captured['total'] );

        // Header row should have been written to the temp file by Csv::writer.
        $expected_file = $tmp_base . '/ffc-tmp/ffc-export-job-uuid-1234.csv';
        $this->assertFileExists( $expected_file );
        $this->assertNotEmpty( (string) file_get_contents( $expected_file ) );

        @unlink( $expected_file );
        @unlink( $tmp_base . '/ffc-tmp/.htaccess' );
        unset( $_POST['form_ids'], $_POST['status'] );
    }

    public function test_ajax_start_multi_form_filename_branch(): void {
        $this->stub_terminators();
        $this->pass_gates();
        Functions\when( 'wp_raise_memory_limit' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Admin\set_time_limit' )->justReturn( true );
        Functions\when( 'sanitize_key' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'absint' )->alias( static function ( $v ) { return abs( (int) $v ); } );
        Functions\when( 'trailingslashit' )->alias( static function ( $p ) { return rtrim( (string) $p, '/' ) . '/'; } );
        Functions\when( 'wp_mkdir_p' )->alias(
            static function ( $dir ) { return is_dir( $dir ) || @mkdir( $dir, 0777, true ); }
        );
        Functions\when( 'wp_generate_uuid4' )->justReturn( 'multi-job' );
        Functions\when( 'set_transient' )->justReturn( true );
        Functions\when( 'apply_filters' )->alias(
            static function () {
                $a = func_get_args();
                return $a[1] ?? null;
            }
        );
        Functions\when( 'FreeFormCertificate\Admin\file_exists' )->justReturn( true ); // .htaccess already present.

        $tmp_base = sys_get_temp_dir() . '/ffc-multi-' . uniqid();
        @mkdir( $tmp_base, 0777, true );
        Functions\when( 'wp_upload_dir' )->justReturn( array( 'basedir' => $tmp_base ) );

        $_POST['form_ids'] = array( 1, 2, 3 );
        $_POST['status']   = 'publish';

        $repo = \Mockery::mock( 'FreeFormCertificate\Repositories\SubmissionRepository' );
        $repo->shouldReceive( 'getExportKeysBatch' )->andReturn( array() );
        $repo->shouldReceive( 'countForExport' )->once()->andReturn( 2 );
        $repo->shouldReceive( 'hasEditInfo' )->once()->andReturn( true );
        $this->set_repository( $repo );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'json_success' );
        try {
            $this->exporter->ajax_start();
        } finally {
            @unlink( $tmp_base . '/ffc-tmp/ffc-export-multi-job.csv' );
            unset( $_POST['form_ids'], $_POST['status'] );
        }
    }

    // ==================================================================
    // ajax_batch() — guard branches + happy paths
    // ==================================================================

    public function test_ajax_batch_rejects_without_capability(): void {
        $this->stub_terminators();
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( false );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'json_error' );
        $this->exporter->ajax_batch();
    }

    public function test_ajax_batch_rejects_when_job_missing(): void {
        $this->stub_terminators();
        $this->pass_gates();
        Functions\when( 'get_transient' )->justReturn( false );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'json_error' );
        $this->exporter->ajax_batch();
    }

    public function test_ajax_batch_rejects_on_user_mismatch(): void {
        $this->stub_terminators();
        $this->pass_gates();
        // Current user is 1, job belongs to 99.
        Functions\when( 'get_transient' )->justReturn(
            array( 'user_id' => 99, 'form_ids' => array( 1 ), 'status' => 'publish', 'cursor' => 0 )
        );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'json_error' );
        $this->exporter->ajax_batch();
    }

    public function test_ajax_batch_completes_when_batch_empty(): void {
        $this->stub_terminators();
        $this->pass_gates();
        Functions\when( 'FreeFormCertificate\Admin\set_time_limit' )->justReturn( true );
        Functions\when( 'do_action' )->justReturn( null );
        Functions\when( 'get_transient' )->justReturn(
            array(
                'user_id'              => 1,
                'form_ids'             => array( 1 ),
                'status'               => 'publish',
                'cursor'               => 0,
                'processed'            => 0,
                'total'                => 0,
                'file'                 => '/tmp/x.csv',
                'dynamic_keys'         => array(),
                'include_edit_columns' => false,
            )
        );

        $repo = \Mockery::mock( 'FreeFormCertificate\Repositories\SubmissionRepository' );
        $repo->shouldReceive( 'getExportBatch' )->once()->andReturn( array() );
        $this->set_repository( $repo );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'json_success' );
        $this->exporter->ajax_batch();
    }

    public function test_ajax_batch_writes_rows_and_advances_cursor(): void {
        $this->stub_terminators();
        $this->pass_gates();
        Functions\when( 'FreeFormCertificate\Admin\set_time_limit' )->justReturn( true );
        Functions\when( 'set_transient' )->justReturn( true );
        Functions\when( 'apply_filters' )->alias(
            static function () {
                $a = func_get_args();
                return $a[1] ?? null;
            }
        );

        $tmp = (string) tempnam( sys_get_temp_dir(), 'ffccsv' );
        Functions\when( 'get_transient' )->justReturn(
            array(
                'user_id'              => 1,
                'form_ids'             => array( 1 ),
                'status'               => 'publish',
                'cursor'              => 0,
                'processed'           => 0,
                'total'               => 5,
                'file'                => $tmp,
                'dynamic_keys'        => array(),
                'include_edit_columns' => false,
            )
        );

        $repo = \Mockery::mock( 'FreeFormCertificate\Repositories\SubmissionRepository' );
        $repo->shouldReceive( 'getExportBatch' )->once()->andReturn(
            array(
                array(
                    'id'              => 11,
                    'form_id'         => 1,
                    'submission_date' => 0,
                    'consent_given'   => 0,
                    'data'            => '{}',
                    'data_encrypted'  => '',
                    'auth_code'       => 'A1',
                ),
            )
        );
        $this->set_repository( $repo );

        try {
            $this->exporter->ajax_batch();
            $this->fail( 'expected wp_send_json_success to halt' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 'json_success', $e->getMessage() );
        }

        $this->assertNotEmpty( (string) file_get_contents( $tmp ), 'a CSV row should have been appended' );
        @unlink( $tmp );
    }

    // ==================================================================
    // ajax_download() — guard branches
    // ==================================================================

    public function test_ajax_download_rejects_on_bad_nonce(): void {
        $this->stub_terminators();
        Functions\when( 'wp_verify_nonce' )->justReturn( false );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'wp_die' );
        $this->exporter->ajax_download();
    }

    public function test_ajax_download_rejects_without_capability(): void {
        $this->stub_terminators();
        Functions\when( 'wp_verify_nonce' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( false );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'wp_die' );
        $this->exporter->ajax_download();
    }

    public function test_ajax_download_rejects_when_job_missing(): void {
        $this->stub_terminators();
        $this->pass_gates();
        Functions\when( 'get_transient' )->justReturn( false );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'wp_die' );
        $this->exporter->ajax_download();
    }

    public function test_ajax_download_rejects_when_file_missing(): void {
        $this->stub_terminators();
        $this->pass_gates();
        Functions\when( 'get_transient' )->justReturn(
            array( 'user_id' => 1, 'file' => '/no/such/ffc-file.csv', 'filename' => 'x.csv' )
        );
        // Native file_exists inside the Admin namespace → report missing.
        Functions\when( 'FreeFormCertificate\Admin\file_exists' )->justReturn( false );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'wp_die' );
        $this->exporter->ajax_download();
    }

    // ==================================================================
    // cleanup_stale_export_jobs()
    // ==================================================================

    public function test_cleanup_stale_export_jobs_reclaims_expired_and_unlinks_file(): void {
        $tmp = (string) tempnam( sys_get_temp_dir(), 'ffcstale' );

        $wpdb = \Mockery::mock();
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static function ( $v ) { return $v; } );
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing(
            static function ( $q, $arg ) { return str_replace( '%s', $arg, $q ); }
        );
        // First prefix (admin) → one expired row referencing $tmp; second prefix → none.
        $wpdb->shouldReceive( 'get_results' )->andReturn(
            array(
                (object) array(
                    'option_name'  => '_transient_timeout_ffc_csv_export_abc',
                    'option_value' => (string) ( time() - 100 ), // expired.
                ),
                (object) array(
                    'option_name'  => '_transient_timeout_ffc_csv_export_fresh',
                    'option_value' => (string) ( time() + 9999 ), // still valid.
                ),
            ),
            array()
        );
        $GLOBALS['wpdb'] = $wpdb;

        Functions\when( 'get_option' )->alias(
            static function ( $name ) use ( $tmp ) {
                if ( '_transient_ffc_csv_export_abc' === $name ) {
                    return array( 'file' => $tmp );
                }
                return false;
            }
        );
        $deleted = array();
        Functions\when( 'delete_transient' )->alias(
            static function ( $key ) use ( &$deleted ) {
                $deleted[] = $key;
                return true;
            }
        );
        Functions\when( 'FreeFormCertificate\Admin\file_exists' )->alias(
            static function ( $f ) { return file_exists( $f ); }
        );
        Functions\when( 'FreeFormCertificate\Admin\unlink' )->alias(
            static function ( $f ) { return @unlink( $f ); }
        );

        $reclaimed = CsvExporter::cleanup_stale_export_jobs();

        $this->assertSame( 1, $reclaimed );
        $this->assertContains( 'ffc_csv_export_abc', $deleted );
        $this->assertFileDoesNotExist( $tmp );

        unset( $GLOBALS['wpdb'] );
        @unlink( $tmp );
    }

    public function test_cleanup_stale_export_jobs_returns_zero_when_no_rows(): void {
        $wpdb = \Mockery::mock();
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static function ( $v ) { return $v; } );
        $wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
        $wpdb->shouldReceive( 'get_results' )->andReturn( array(), array() );
        $GLOBALS['wpdb'] = $wpdb;

        $this->assertSame( 0, CsvExporter::cleanup_stale_export_jobs() );

        unset( $GLOBALS['wpdb'] );
    }

    // ==================================================================
    // scan_dynamic_keys() / count_export_rows()
    // ==================================================================

    public function test_scan_dynamic_keys_merges_unique_keys_across_batches(): void {
        $repo = \Mockery::mock( 'FreeFormCertificate\Repositories\SubmissionRepository' );
        $repo->shouldReceive( 'getExportKeysBatch' )
            ->twice()
            ->andReturn(
                array(
                    array( 'id' => 10, 'data' => '{"name":"A","city":"SP"}' ),
                    array( 'id' => 20, 'data' => '{"name":"B","age":"30"}' ),
                ),
                array()
            );
        $this->set_repository( $repo );

        $keys = $this->invoke( 'scan_dynamic_keys', array( array( 1 ), 'publish' ) );
        sort( $keys );
        $this->assertSame( array( 'age', 'city', 'name' ), $keys );
    }

    public function test_scan_dynamic_keys_returns_empty_when_no_rows(): void {
        $repo = \Mockery::mock( 'FreeFormCertificate\Repositories\SubmissionRepository' );
        $repo->shouldReceive( 'getExportKeysBatch' )->once()->andReturn( array() );
        $this->set_repository( $repo );

        $this->assertSame( array(), $this->invoke( 'scan_dynamic_keys', array( null, 'publish' ) ) );
    }

    public function test_count_export_rows_delegates_to_repository(): void {
        $repo = \Mockery::mock( 'FreeFormCertificate\Repositories\SubmissionRepository' );
        $repo->shouldReceive( 'countForExport' )->once()->with( array( 7 ), 'trash' )->andReturn( 42 );
        $this->set_repository( $repo );

        $this->assertSame( 42, $this->invoke( 'count_export_rows', array( array( 7 ), 'trash' ) ) );
    }
}

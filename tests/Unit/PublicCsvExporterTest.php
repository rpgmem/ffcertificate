<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\PublicCsvExporter;

/**
 * Tests for PublicCsvExporter: column layout + row formatting.
 *
 * The public exporter intentionally mirrors the admin CsvExporter so an
 * admin can cross-check both files. These tests lock the column layout
 * (15 fixed + 3 edit-tracking + N dynamic) so refactors can't silently
 * drift the public and admin outputs apart.
 *
 * Uses Reflection + newInstanceWithoutConstructor() to avoid the real
 * SubmissionRepository (which needs a wpdb).
 */
class PublicCsvExporterTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var PublicCsvExporter */
    private $exporter;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'get_the_title' )->justReturn( 'Public Test Form' );
        Functions\when( 'get_userdata' )->alias( function ( $id ) {
            $user = new \stdClass();
            $user->display_name = 'Admin User';
            return $user;
        } );

        $ref            = new \ReflectionClass( PublicCsvExporter::class );
        $this->exporter = $ref->newInstanceWithoutConstructor();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Invoke a private/protected method via Reflection.
     *
     * @param array<int, mixed> $args
     * @return mixed
     */
    private function invoke( string $method, array $args = array() ) {
        $ref = new \ReflectionMethod( PublicCsvExporter::class, $method );
        $ref->setAccessible( true );
        return $ref->invokeArgs( $this->exporter, $args );
    }

    // ==================================================================
    //  get_fixed_headers()
    // ==================================================================

    public function test_fixed_headers_without_edit_columns_returns_15(): void {
        $headers = $this->invoke( 'get_fixed_headers', array( false ) );
        $this->assertCount( 15, $headers );
    }

    public function test_fixed_headers_with_edit_columns_returns_18(): void {
        $headers = $this->invoke( 'get_fixed_headers', array( true ) );
        $this->assertCount( 18, $headers );
    }

    public function test_fixed_headers_match_admin_csv_exporter_layout(): void {
        $headers = $this->invoke( 'get_fixed_headers', array( false ) );
        $this->assertSame(
            array(
                'ID',
                'Form',
                'User ID',
                'Submission Date',
                'E-mail',
                'User IP',
                'CPF',
                'RF',
                'Auth Code',
                'Token',
                'Consent Given',
                'Consent Date',
                'Consent IP',
                'Consent Text',
                'Status',
            ),
            $headers
        );
    }

    public function test_fixed_headers_edit_columns_appended_at_end(): void {
        $headers = $this->invoke( 'get_fixed_headers', array( true ) );
        $this->assertSame( 'Was Edited', $headers[15] );
        $this->assertSame( 'Edit Date', $headers[16] );
        $this->assertSame( 'Edited By', $headers[17] );
    }

    // ==================================================================
    //  format_csv_row()
    //
    //  Column layout (must match admin CsvExporter):
    //  [0]  ID              [8]  Auth Code
    //  [1]  Form            [9]  Token
    //  [2]  User ID         [10] Consent Given
    //  [3]  Date            [11] Consent Date
    //  [4]  Email           [12] Consent IP
    //  [5]  IP              [13] Consent Text
    //  [6]  CPF             [14] Status
    //  [7]  RF
    // ==================================================================

    /**
     * @return array<string, mixed>
     */
    private function sample_row(): array {
        return array(
            'id'                => 7,
            'form_id'           => 42,
            'user_id'           => 10,
            'submission_date'   => '2026-01-15 10:30:00',
            'email'             => 'test@example.com',
            'email_encrypted'   => '',
            'user_ip'           => '203.0.113.1',
            'user_ip_encrypted' => '',
            'cpf'               => '123.456.789-00',
            'cpf_encrypted'     => '',
            'rf'                => '',
            'rf_encrypted'      => '',
            'auth_code'         => 'ABC123',
            'magic_token'       => 'tok_abc',
            'consent_given'     => 1,
            'consent_date'      => '2026-01-15 10:30:00',
            'consent_text'      => 'I agree',
            'status'            => 'publish',
            'data'              => '{"field_name":"John","field_city":"SP"}',
            'data_encrypted'    => '',
            'edited_at'         => '',
            'edited_by'         => '',
        );
    }

    public function test_format_csv_row_basic_count(): void {
        $row    = $this->sample_row();
        $result = $this->invoke( 'format_csv_row', array( $row, array( 'field_name', 'field_city' ), false ) );
        // 15 fixed + 2 dynamic = 17
        $this->assertCount( 17, $result );
    }

    public function test_format_csv_row_fixed_columns_have_expected_values(): void {
        $row    = $this->sample_row();
        $result = $this->invoke( 'format_csv_row', array( $row, array(), false ) );

        $this->assertSame( 7, $result[0] );
        $this->assertSame( 'Public Test Form', $result[1] );
        $this->assertSame( 10, $result[2] );
        $this->assertSame( '2026-01-15 10:30:00', $result[3] );
        $this->assertSame( 'test@example.com', $result[4] );
        $this->assertSame( '203.0.113.1', $result[5] );
        $this->assertSame( '123.456.789-00', $result[6] );
        $this->assertSame( '', $result[7] );
        $this->assertSame( 'ABC123', $result[8] );
        $this->assertSame( 'tok_abc', $result[9] );
        $this->assertSame( 'Yes', $result[10] );
        $this->assertSame( '2026-01-15 10:30:00', $result[11] );
        $this->assertSame( '203.0.113.1', $result[12] );
        $this->assertSame( 'I agree', $result[13] );
        $this->assertSame( 'publish', $result[14] );
    }

    public function test_format_csv_row_consent_no(): void {
        $row                  = $this->sample_row();
        $row['consent_given'] = 0;
        $result               = $this->invoke( 'format_csv_row', array( $row, array(), false ) );
        $this->assertSame( 'No', $result[10] );
    }

    public function test_format_csv_row_uses_deleted_placeholder_when_form_is_missing(): void {
        Functions\when( 'get_the_title' )->justReturn( '' );

        // The form title is cached per-instance, so reset the reflection cache.
        $ref = new \ReflectionClass( PublicCsvExporter::class );
        $this->exporter = $ref->newInstanceWithoutConstructor();

        $row    = $this->sample_row();
        $result = $this->invoke( 'format_csv_row', array( $row, array(), false ) );
        $this->assertSame( '(Deleted)', $result[1] );
    }

    public function test_format_csv_row_with_edit_columns(): void {
        $row              = $this->sample_row();
        $row['edited_at'] = '2026-02-01 09:00:00';
        $row['edited_by'] = 5;
        $result           = $this->invoke( 'format_csv_row', array( $row, array(), true ) );

        // 15 fixed + 3 edit = 18
        $this->assertCount( 18, $result );
        $this->assertSame( 'Yes', $result[15] );
        $this->assertSame( '2026-02-01 09:00:00', $result[16] );
        $this->assertSame( 'Admin User', $result[17] );
    }

    public function test_format_csv_row_not_edited_leaves_edit_columns_empty(): void {
        $row    = $this->sample_row();
        $result = $this->invoke( 'format_csv_row', array( $row, array(), true ) );
        $this->assertSame( '', $result[15] );
        $this->assertSame( '', $result[16] );
        $this->assertSame( '', $result[17] );
    }

    public function test_format_csv_row_dynamic_keys_appended_after_fixed_columns(): void {
        $row          = $this->sample_row();
        $dynamic_keys = array( 'field_name', 'field_city' );
        $result       = $this->invoke( 'format_csv_row', array( $row, $dynamic_keys, false ) );
        $this->assertSame( 'John', $result[15] );
        $this->assertSame( 'SP', $result[16] );
    }

    public function test_format_csv_row_missing_dynamic_key_is_empty(): void {
        $row          = $this->sample_row();
        $dynamic_keys = array( 'field_name', 'field_missing' );
        $result       = $this->invoke( 'format_csv_row', array( $row, $dynamic_keys, false ) );
        $this->assertSame( 'John', $result[15] );
        $this->assertSame( '', $result[16] );
    }

    public function test_format_csv_row_rf_only(): void {
        $row        = $this->sample_row();
        $row['cpf'] = '';
        $row['rf']  = '1234567';
        $result     = $this->invoke( 'format_csv_row', array( $row, array(), false ) );
        $this->assertSame( '', $result[6] );
        $this->assertSame( '1234567', $result[7] );
    }

    // ==================================================================
    //  Constants
    // ==================================================================

    public function test_batch_size_constants_are_sensible(): void {
        $this->assertGreaterThan( 0, PublicCsvExporter::EXPORT_BATCH_SIZE );
        $this->assertGreaterThan( 0, PublicCsvExporter::KEYS_BATCH_SIZE );
        // Keys batch scans are much cheaper per row than full-row exports,
        // so the keys batch should never be smaller than the export batch.
        $this->assertGreaterThanOrEqual(
            PublicCsvExporter::EXPORT_BATCH_SIZE,
            PublicCsvExporter::KEYS_BATCH_SIZE
        );
    }

    public function test_job_ttl_constant_is_one_hour(): void {
        $this->assertSame( 3600, PublicCsvExporter::JOB_TTL );
    }

    // ==================================================================
    //  get_sync_max_rows() — clamping and default
    // ==================================================================

    public function test_sync_max_rows_default_when_setting_missing(): void {
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );

        $this->assertSame(
            PublicCsvExporter::DEFAULT_SYNC_MAX_ROWS,
            PublicCsvExporter::get_sync_max_rows()
        );
    }

    public function test_sync_max_rows_clamps_below_minimum(): void {
        Functions\when( 'get_option' )->justReturn( array( 'public_csv_sync_max_rows' => 10 ) );
        Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );

        $this->assertSame(
            PublicCsvExporter::SYNC_MAX_ROWS_MIN,
            PublicCsvExporter::get_sync_max_rows()
        );
    }

    public function test_sync_max_rows_clamps_above_maximum(): void {
        Functions\when( 'get_option' )->justReturn( array( 'public_csv_sync_max_rows' => 99999 ) );
        Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );

        $this->assertSame(
            PublicCsvExporter::SYNC_MAX_ROWS_MAX,
            PublicCsvExporter::get_sync_max_rows()
        );
    }

    public function test_sync_max_rows_returns_configured_value_in_range(): void {
        Functions\when( 'get_option' )->justReturn( array( 'public_csv_sync_max_rows' => 3500 ) );
        Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );

        $this->assertSame( 3500, PublicCsvExporter::get_sync_max_rows() );
    }
}

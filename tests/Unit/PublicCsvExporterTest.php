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
 *
 * Runs in separate processes: the AJAX/stream paths define namespaced
 * helper stubs (FreeFormCertificate\Frontend\__, apply_filters, header, …)
 * that PHP cannot undefine, which would otherwise leak "MissingFunctionExpectations"
 * into sibling Frontend-namespace tests (ScheduleExceptionAction, VerificationHandler).
 *
 * @covers \FreeFormCertificate\Frontend\PublicCsvExporter
 * @covers \FreeFormCertificate\Frontend\Csv\PublicCsvRowFormatter
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class PublicCsvExporterTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var PublicCsvExporter */
    private $exporter;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Preload the extracted collaborator so pcov attributes the coverage
        // this test drives through PublicCsvExporter delegation — pcov does not
        // record lines for files first autoloaded mid-test-method (#589 E3).
        class_exists( '\\FreeFormCertificate\Frontend\Csv\PublicCsvRowFormatter' );

        Functions\when( '__' )->returnArg();
        // The exporter calls i18n/escape helpers unqualified inside the Frontend
        // namespace; once Brain Monkey defines a namespaced function in the
        // process it must stay mocked, so stub them globally here.
        Functions\when( 'FreeFormCertificate\Frontend\__' )->returnArg();
        Functions\when( 'FreeFormCertificate\Frontend\esc_html' )->returnArg();
        Functions\when( 'FreeFormCertificate\Frontend\esc_html__' )->returnArg();
        Functions\when( 'get_the_title' )->justReturn( 'Public Test Form' );
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
            // `submission_date` is unix UTC int since 6.6.0 (#249 sub-escopo a).
            // 1768473000 = 2026-01-15 10:30:00 UTC.
            'submission_date'   => 1768473000,
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
            // Category A instant since 6.6.0 (#249 sub-escopo d) — unix UTC.
            'consent_date'      => 1768473000,
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
        // Formatted via DateFormatter (UTC stub in setUp) — plugin default
        // `date_format` is 'd/m/Y' and `time_format` is 'H:i'.
        $this->assertSame( '15/01/2026 10:30', $result[3] );
        $this->assertSame( 'test@example.com', $result[4] );
        $this->assertSame( '203.0.113.1', $result[5] );
        $this->assertSame( '123.456.789-00', $result[6] );
        $this->assertSame( '', $result[7] );
        $this->assertSame( 'ABC123', $result[8] );
        $this->assertSame( 'tok_abc', $result[9] );
        $this->assertSame( 'Yes', $result[10] );
        // DateFormatter default ('d/m/Y H:i') under UTC stub.
        $this->assertSame( '15/01/2026 10:30', $result[11] );
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
        // `edited_at` is unix UTC int since 6.6.0 (#249 sub-escopo d).
        // 1770368400 = 2026-02-06 09:00:00 UTC.
        $row['edited_at'] = 1770368400;
        $row['edited_by'] = 5;
        $result           = $this->invoke( 'format_csv_row', array( $row, array(), true ) );

        // 15 fixed + 3 edit = 18
        $this->assertCount( 18, $result );
        $this->assertSame( 'Yes', $result[15] );
        $this->assertSame( '06/02/2026 09:00', $result[16] );
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

    // ==================================================================
    // get_form_title_cached()
    // ==================================================================

    public function test_get_form_title_cached_returns_title(): void {
        Functions\when( 'get_the_title' )->justReturn( 'Form X' );
        // get_form_title_cached moved to PublicCsvRowFormatter (#589 Sprint E3).
        $formatter = new \FreeFormCertificate\Frontend\Csv\PublicCsvRowFormatter();
        $this->assertSame( 'Form X', $formatter->get_form_title_cached( 5 ) );
    }

    public function test_get_form_title_cached_uses_placeholder_for_deleted(): void {
        Functions\when( 'get_the_title' )->justReturn( '' );
        $formatter = new \FreeFormCertificate\Frontend\Csv\PublicCsvRowFormatter();
        $this->assertSame( '(Deleted)', $formatter->get_form_title_cached( 99 ) );
    }

    public function test_get_form_title_cached_memoizes_lookup(): void {
        // First lookup populates the cache; second must not call get_the_title
        // again (we flip the stub to a sentinel that would fail the assert).
        $formatter = new \FreeFormCertificate\Frontend\Csv\PublicCsvRowFormatter();
        Functions\when( 'get_the_title' )->justReturn( 'Cached Title' );
        $first = $formatter->get_form_title_cached( 7 );

        Functions\when( 'get_the_title' )->justReturn( 'DIFFERENT' );
        $second = $formatter->get_form_title_cached( 7 );

        $this->assertSame( 'Cached Title', $first );
        $this->assertSame( 'Cached Title', $second );
    }

    // ==================================================================
    // scan_dynamic_keys() — paginated key harvesting
    // ==================================================================

    /** Inject a mock SubmissionRepository into the protected property. */
    private function set_repository( $repo ): void {
        $prop = new \ReflectionProperty( PublicCsvExporter::class, 'repository' );
        $prop->setAccessible( true );
        $prop->setValue( $this->exporter, $repo );
    }

    public function test_scan_dynamic_keys_merges_unique_keys_across_batches(): void {
        $repo = \Mockery::mock( 'FreeFormCertificate\Repositories\SubmissionRepository' );
        // First batch returns two rows, second batch empty → loop terminates.
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

        $this->assertSame( array(), $this->invoke( 'scan_dynamic_keys', array( array( 1 ), 'publish' ) ) );
    }

    // ==================================================================
    //  stream_form_csv() — synchronous export guard (over-limit path)
    // ==================================================================

    public function test_stream_form_csv_renders_limit_page_when_over_threshold(): void {
        // The exporter calls these unqualified inside the Frontend namespace,
        // so intercept the namespaced resolutions (raw header() included, to
        // avoid "headers already sent" from PHPUnit's own output).
        Functions\when( 'FreeFormCertificate\Frontend\status_header' )->justReturn( null );
        Functions\when( 'FreeFormCertificate\Frontend\nocache_headers' )->justReturn( null );
        Functions\when( 'FreeFormCertificate\Frontend\header' )->justReturn( null );
        Functions\when( 'FreeFormCertificate\Frontend\esc_html' )->returnArg();
        Functions\when( 'FreeFormCertificate\Frontend\__' )->returnArg();
        // get_sync_max_rows() reads SettingsReader::get_int → default; the
        // mocked count far exceeds it, so the sync path is refused.
        $repo = \Mockery::mock( 'FreeFormCertificate\Repositories\SubmissionRepository' );
        $repo->shouldReceive( 'countForExport' )->once()->andReturn( 9999999 );
        $this->set_repository( $repo );

        ob_start();
        $this->exporter->stream_form_csv( 1, 'publish' );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'Export too large', $html );
        $this->assertStringContainsString( '9999999', $html );
    }

    // ==================================================================
    //  ajax_batch() / ajax_download() — request-security guard branches
    // ==================================================================

    /** Make wp_send_json_error / wp_die halt (as they do in production). */
    private function stub_terminators(): void {
        Functions\when( 'FreeFormCertificate\Frontend\wp_send_json_error' )->alias(
            static function () {
                throw new \RuntimeException( 'json_error' );
            }
        );
        Functions\when( 'FreeFormCertificate\Frontend\wp_die' )->alias(
            static function () {
                throw new \RuntimeException( 'wp_die' );
            }
        );
        Functions\when( 'FreeFormCertificate\Frontend\esc_html__' )->returnArg();
    }

    public function test_ajax_batch_rejects_when_job_missing(): void {
        $this->stub_terminators();
        Functions\when( 'FreeFormCertificate\Frontend\get_transient' )->justReturn( false );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'json_error' );
        $this->exporter->ajax_batch();
    }

    public function test_ajax_batch_rejects_on_bad_nonce(): void {
        $this->stub_terminators();
        Functions\when( 'FreeFormCertificate\Frontend\get_transient' )->justReturn(
            array( 'ip_hash' => 'x', 'form_ids' => array( 1 ), 'status' => 'publish', 'cursor' => 0 )
        );
        Functions\when( 'FreeFormCertificate\Frontend\wp_verify_nonce' )->justReturn( false );

        $this->expectException( \RuntimeException::class );
        $this->exporter->ajax_batch();
    }

    public function test_ajax_batch_rejects_on_ip_mismatch(): void {
        $this->stub_terminators();
        Functions\when( 'FreeFormCertificate\Frontend\get_transient' )->justReturn(
            array( 'ip_hash' => 'no-match', 'form_ids' => array( 1 ), 'status' => 'publish', 'cursor' => 0 )
        );
        Functions\when( 'FreeFormCertificate\Frontend\wp_verify_nonce' )->justReturn( true );

        $this->expectException( \RuntimeException::class );
        $this->exporter->ajax_batch();
    }

    public function test_ajax_download_rejects_when_job_missing(): void {
        $this->stub_terminators();
        Functions\when( 'FreeFormCertificate\Frontend\get_transient' )->justReturn( false );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'wp_die' );
        $this->exporter->ajax_download();
    }

    public function test_ajax_download_rejects_when_file_missing(): void {
        $this->stub_terminators();
        Functions\when( 'FreeFormCertificate\Frontend\get_transient' )->justReturn(
            array( 'ip_hash' => sha1( '' ), 'file' => '/no/such/file.csv' )
        );
        Functions\when( 'FreeFormCertificate\Frontend\wp_verify_nonce' )->justReturn( true );

        // job found + nonce ok + ip matches (sha1 of empty REMOTE_ADDR) → file_exists false → wp_die.
        $this->expectException( \RuntimeException::class );
        $this->exporter->ajax_download();
    }

    /** Job present, nonce ok, IP matches — set up the shared preconditions. */
    private function prime_batch_job( array $overrides = array() ): void {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        Functions\when( 'FreeFormCertificate\Core\sanitize_text_field' )->returnArg();
        Functions\when( 'FreeFormCertificate\Core\wp_unslash' )->returnArg();
        Functions\when( 'FreeFormCertificate\Frontend\wp_verify_nonce' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Frontend\set_transient' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Frontend\do_action' )->justReturn( null );
        Functions\when( 'FreeFormCertificate\Frontend\apply_filters' )->alias(
            static function () {
                $a = func_get_args();
                return $a[1] ?? null;
            }
        );
        Functions\when( 'FreeFormCertificate\Frontend\wp_send_json_success' )->alias(
            static function () {
                throw new \RuntimeException( 'json_success' );
            }
        );
        $job = array_merge(
            array(
                'ip_hash'              => sha1( '203.0.113.7' ),
                'form_ids'            => array( 1 ),
                'status'              => 'publish',
                'cursor'              => 0,
                'processed'           => 0,
                'total'               => 5,
                'dynamic_keys'        => array(),
                'include_edit_columns' => false,
            ),
            $overrides
        );
        Functions\when( 'FreeFormCertificate\Frontend\get_transient' )->justReturn( $job );
    }

    public function test_ajax_batch_completes_when_batch_empty(): void {
        $this->stub_terminators();
        $this->prime_batch_job();
        $repo = \Mockery::mock( 'FreeFormCertificate\Repositories\SubmissionRepository' );
        $repo->shouldReceive( 'getExportBatch' )->once()->andReturn( array() );
        $this->set_repository( $repo );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'json_success' );
        $this->exporter->ajax_batch();

        unset( $_SERVER['REMOTE_ADDR'] );
    }

    public function test_ajax_batch_writes_rows_and_advances_cursor(): void {
        $this->stub_terminators();
        $tmp = (string) tempnam( sys_get_temp_dir(), 'ffccsv' );
        $this->prime_batch_job( array( 'file' => $tmp ) );
        $repo = \Mockery::mock( 'FreeFormCertificate\Repositories\SubmissionRepository' );
        $repo->shouldReceive( 'getExportBatch' )->once()->andReturn(
            array(
                array( 'id' => 11, 'form_id' => 1, 'data' => array(), 'auth_code' => 'A1' ),
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
        unset( $_SERVER['REMOTE_ADDR'] );
    }

    // ==================================================================
    //  stream_form_csv() — full synchronous success body
    // ==================================================================

    /**
     * Stub every namespaced WP helper the stream/ajax success bodies touch so
     * no real header()/exit fires and apply_filters returns the payload arg.
     */
    private function stub_stream_helpers(): void {
        Functions\when( 'FreeFormCertificate\Frontend\header' )->justReturn( null );
        Functions\when( 'FreeFormCertificate\Frontend\status_header' )->justReturn( null );
        Functions\when( 'FreeFormCertificate\Frontend\nocache_headers' )->justReturn( null );
        Functions\when( 'FreeFormCertificate\Frontend\set_time_limit' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Frontend\wp_raise_memory_limit' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Frontend\get_the_title' )->justReturn( 'Stream Form' );
        Functions\when( 'FreeFormCertificate\Frontend\gmdate' )->alias(
            static function ( $fmt, $ts = null ) {
                return gmdate( $fmt, $ts ?? 0 );
            }
        );
        Functions\when( 'FreeFormCertificate\Frontend\do_action' )->justReturn( null );
        Functions\when( 'FreeFormCertificate\Frontend\apply_filters' )->alias(
            static function () {
                $a = func_get_args();
                return $a[1] ?? null;
            }
        );
        // get_option drives SettingsReader::get_int → sync limit; keep it high.
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'absint' )->alias( static function ( $v ) {
            return abs( (int) $v );
        } );
    }

    public function test_stream_form_csv_writes_header_and_rows_then_exits(): void {
        $this->stub_stream_helpers();

        // stream_form_csv() ends in a bare `exit` (a language construct, not a
        // stubbable function). The last call before it is do_action(); make
        // that throw so the body runs to completion and we halt right before
        // the un-coverable exit line.
        Functions\when( 'FreeFormCertificate\Frontend\do_action' )->alias(
            static function () {
                throw new \RuntimeException( 'stream_exit' );
            }
        );

        $repo = \Mockery::mock( 'FreeFormCertificate\Repositories\SubmissionRepository' );
        // Row count under the sync limit → success path.
        $repo->shouldReceive( 'countForExport' )->once()->andReturn( 3 );
        $repo->shouldReceive( 'hasEditInfo' )->once()->andReturn( false );
        // scan_dynamic_keys: one batch then empty.
        $repo->shouldReceive( 'getExportKeysBatch' )
            ->andReturn(
                array( array( 'id' => 1, 'data' => '{"name":"A"}' ) ),
                array()
            );
        // getExportBatch: one populated batch then empty terminator.
        $repo->shouldReceive( 'getExportBatch' )
            ->andReturn(
                array(
                    array( 'id' => 11, 'form_id' => 1, 'data' => '{"name":"A"}', 'auth_code' => 'A1' ),
                    array( 'id' => 12, 'form_id' => 1, 'data' => '{"name":"B"}', 'auth_code' => 'A2' ),
                ),
                array()
            );
        $this->set_repository( $repo );

        // The body writes to php://output and discards any wrapping buffer via
        // `while (@ob_end_clean())`, so we can't capture the CSV bytes here.
        // Reaching the do_action throw proves header write + row streaming +
        // batch loop all ran (the mocked getExportBatch was consumed twice).
        $this->run_buffer_safe(
            function () {
                $this->exporter->stream_form_csv( 1, 'publish' );
            },
            'stream_exit'
        );
    }

    /**
     * Run a callable that (a) throws to halt and (b) tears down output buffers
     * via `while (@ob_end_clean())` mid-flight. We push throwaway buffers first
     * so the body eats those instead of PHPUnit's, then restore the original
     * nesting level so the test isn't flagged risky.
     */
    private function run_buffer_safe( callable $fn, string $expected_message ): void {
        $base = ob_get_level();
        // Push a couple of disposable buffers for the body to consume.
        ob_start();
        ob_start();
        $caught = null;
        try {
            $fn();
        } catch ( \RuntimeException $e ) {
            $caught = $e;
        }
        // Restore the buffer nesting PHPUnit started with.
        while ( ob_get_level() > $base ) {
            ob_end_clean();
        }
        while ( ob_get_level() < $base ) {
            ob_start();
        }
        $this->assertNotNull( $caught, 'expected the body to throw and halt' );
        $this->assertSame( $expected_message, $caught->getMessage() );
    }

    public function test_stream_form_csv_header_only_when_no_rows(): void {
        $this->stub_stream_helpers();
        Functions\when( 'FreeFormCertificate\Frontend\do_action' )->alias(
            static function () {
                throw new \RuntimeException( 'stream_exit' );
            }
        );

        $repo = \Mockery::mock( 'FreeFormCertificate\Repositories\SubmissionRepository' );
        $repo->shouldReceive( 'countForExport' )->once()->andReturn( 0 );
        $repo->shouldReceive( 'hasEditInfo' )->once()->andReturn( false );
        $repo->shouldReceive( 'getExportKeysBatch' )->andReturn( array() );
        // Immediately-empty batch → loop breaks at once.
        $repo->shouldReceive( 'getExportBatch' )->once()->andReturn( array() );
        $this->set_repository( $repo );

        $this->run_buffer_safe(
            function () {
                $this->exporter->stream_form_csv( 99, 'publish' );
            },
            'stream_exit'
        );
    }

    // ==================================================================
    //  ajax_start() — full job-priming success + guard branches
    // ==================================================================

    /**
     * Stub the security gate collaborators so ajax_start reaches its body.
     * Uses Mockery alias mocks (classes are not preloaded in this process).
     */
    private function stub_ajax_start_gate(): void {
        $this->stub_terminators();
        Functions\when( 'FreeFormCertificate\Frontend\wp_send_json_error' )->alias(
            static function () {
                throw new \RuntimeException( 'json_error' );
            }
        );
        Functions\when( 'FreeFormCertificate\Frontend\wp_send_json_success' )->alias(
            static function () {
                throw new \RuntimeException( 'json_success' );
            }
        );
        Functions\when( 'FreeFormCertificate\Frontend\wp_verify_nonce' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Frontend\set_time_limit' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Frontend\wp_raise_memory_limit' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Frontend\get_post_meta' )->justReturn( 0 );
        Functions\when( 'FreeFormCertificate\Frontend\update_post_meta' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Frontend\get_the_title' )->justReturn( 'Job Form' );
        Functions\when( 'FreeFormCertificate\Frontend\gmdate' )->alias(
            static function ( $fmt, $ts = null ) {
                return gmdate( $fmt, $ts ?? 0 );
            }
        );
        // Actually create the temp dir so Csv::writer() can open the temp file.
        Functions\when( 'FreeFormCertificate\Frontend\wp_mkdir_p' )->alias(
            static function ( $dir ) {
                return is_dir( $dir ) || mkdir( $dir, 0777, true );
            }
        );
        // .htaccess already present → skip the file_put_contents branch.
        Functions\when( 'FreeFormCertificate\Frontend\file_exists' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Frontend\trailingslashit' )->alias(
            static function ( $p ) {
                return rtrim( (string) $p, '/' ) . '/';
            }
        );
        Functions\when( 'FreeFormCertificate\Frontend\wp_generate_uuid4' )->justReturn( 'job-uuid-1' );
        Functions\when( 'FreeFormCertificate\Frontend\wp_create_nonce' )->justReturn( 'nonce-batch-1' );
        Functions\when( 'FreeFormCertificate\Frontend\set_transient' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Frontend\apply_filters' )->alias(
            static function () {
                $a = func_get_args();
                return $a[1] ?? null;
            }
        );
        // wp_upload_dir points at the scratch dir so the temp CSV writer succeeds.
        $base = sys_get_temp_dir();
        Functions\when( 'FreeFormCertificate\Frontend\wp_upload_dir' )->justReturn(
            array( 'basedir' => $base )
        );

        // Static-call collaborators (fully qualified in the class) via alias mocks.
        \Mockery::mock( 'alias:FreeFormCertificate\Security\RateLimiter' )
            ->shouldReceive( 'check_ip_limit' )
            ->andReturn( array( 'allowed' => true ) );
        \Mockery::mock( 'alias:FreeFormCertificate\Core\SecurityService' )
            ->shouldReceive( 'validate_security_fields' )
            ->andReturn( true );
        \Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
            ->shouldReceive( 'get_user_ip' )->andReturn( '203.0.113.9' )
            ->shouldReceive( 'get_post_string' )->andReturnUsing(
                static function ( $key ) {
                    $map = array( 'hash' => 'abc', 'cpf' => '123.456.789-00', '_ffc_pcd_nonce' => 'n' );
                    return $map[ $key ] ?? '';
                }
            );

        $_POST['form_id'] = 7;
        Functions\when( 'absint' )->alias( static function ( $v ) {
            return abs( (int) $v );
        } );
        Functions\when( 'wp_unslash' )->returnArg();
    }

    /**
     * Define a lightweight real PublicCsvDownload shim (with the constants the
     * exporter references) before the autoloader can pull in the real class.
     * `$access`/$cpf control the two validators' return values.
     */
    private function define_public_csv_download_shim( $access, $cpf ): void {
        if ( class_exists( '\\FreeFormCertificate\Frontend\PublicCsvDownload', false ) ) {
            return;
        }
        $GLOBALS['__pcd_access'] = $access;
        $GLOBALS['__pcd_cpf']    = $cpf;
        eval(
            'namespace FreeFormCertificate\Frontend;'
            . ' class PublicCsvDownload {'
            . ' const NONCE_ACTION = "ffc_public_csv_download";'
            . ' const META_COUNT = "_ffc_csv_public_count";'
            . ' public function validate_form_access( $f, $h ) { return $GLOBALS["__pcd_access"]; }'
            . ' public function validate_cpf_requirement( $f, $c ) { return $GLOBALS["__pcd_cpf"]; }'
            . ' }'
        );
    }

    public function test_ajax_start_primes_job_and_returns_success(): void {
        $this->define_public_csv_download_shim( null, null );
        $this->stub_ajax_start_gate();

        $repo = \Mockery::mock( 'FreeFormCertificate\Repositories\SubmissionRepository' );
        $repo->shouldReceive( 'hasEditInfo' )->andReturn( false );
        $repo->shouldReceive( 'getExportKeysBatch' )->andReturn( array() );
        $repo->shouldReceive( 'count' )->andReturn( 4 );
        $this->set_repository( $repo );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'json_success' );
        try {
            $this->exporter->ajax_start();
        } finally {
            unset( $_POST['form_id'] );
        }
    }

    public function test_ajax_start_rejects_when_no_records(): void {
        $this->define_public_csv_download_shim( null, null );
        $this->stub_ajax_start_gate();

        $repo = \Mockery::mock( 'FreeFormCertificate\Repositories\SubmissionRepository' );
        $repo->shouldReceive( 'hasEditInfo' )->andReturn( false );
        $repo->shouldReceive( 'getExportKeysBatch' )->andReturn( array() );
        // Zero total → "No records found" json_error.
        $repo->shouldReceive( 'count' )->andReturn( 0 );
        $this->set_repository( $repo );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'json_error' );
        try {
            $this->exporter->ajax_start();
        } finally {
            unset( $_POST['form_id'] );
        }
    }

    public function test_ajax_start_rejects_on_form_access_error(): void {
        $this->define_public_csv_download_shim( 'Access denied', null );
        $this->stub_ajax_start_gate();

        // Repo not reached; still inject a harmless mock.
        $this->set_repository( \Mockery::mock( 'FreeFormCertificate\Repositories\SubmissionRepository' ) );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'json_error' );
        try {
            $this->exporter->ajax_start();
        } finally {
            unset( $_POST['form_id'] );
        }
    }

    public function test_ajax_start_rejects_on_bad_nonce(): void {
        $this->stub_terminators();
        Functions\when( 'FreeFormCertificate\Frontend\wp_send_json_error' )->alias(
            static function () {
                throw new \RuntimeException( 'json_error' );
            }
        );
        Functions\when( 'FreeFormCertificate\Frontend\wp_verify_nonce' )->justReturn( false );
        \Mockery::mock( 'alias:FreeFormCertificate\Security\RateLimiter' )
            ->shouldReceive( 'check_ip_limit' )->andReturn( array( 'allowed' => true ) );
        \Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
            ->shouldReceive( 'get_user_ip' )->andReturn( '1.2.3.4' )
            ->shouldReceive( 'get_post_string' )->andReturn( 'n' );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'json_error' );
        $this->exporter->ajax_start();
    }

    public function test_ajax_start_rejects_when_rate_limited(): void {
        $this->stub_terminators();
        Functions\when( 'FreeFormCertificate\Frontend\wp_send_json_error' )->alias(
            static function () {
                throw new \RuntimeException( 'json_error' );
            }
        );
        \Mockery::mock( 'alias:FreeFormCertificate\Security\RateLimiter' )
            ->shouldReceive( 'check_ip_limit' )
            ->andReturn( array( 'allowed' => false, 'message' => 'slow down' ) );
        \Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
            ->shouldReceive( 'get_user_ip' )->andReturn( '1.2.3.4' );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'json_error' );
        $this->exporter->ajax_start();
    }

    public function test_ajax_start_rejects_on_security_field_failure(): void {
        $this->stub_terminators();
        Functions\when( 'FreeFormCertificate\Frontend\wp_send_json_error' )->alias(
            static function () {
                throw new \RuntimeException( 'json_error' );
            }
        );
        Functions\when( 'FreeFormCertificate\Frontend\wp_verify_nonce' )->justReturn( true );
        \Mockery::mock( 'alias:FreeFormCertificate\Security\RateLimiter' )
            ->shouldReceive( 'check_ip_limit' )->andReturn( array( 'allowed' => true ) );
        \Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
            ->shouldReceive( 'get_user_ip' )->andReturn( '1.2.3.4' )
            ->shouldReceive( 'get_post_string' )->andReturn( 'n' );
        // Honeypot/CAPTCHA fails → returns an error string, not true.
        \Mockery::mock( 'alias:FreeFormCertificate\Core\SecurityService' )
            ->shouldReceive( 'validate_security_fields' )->andReturn( 'spam detected' );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'json_error' );
        $this->exporter->ajax_start();
    }

    public function test_ajax_start_rejects_when_form_id_or_hash_empty(): void {
        $this->stub_terminators();
        Functions\when( 'FreeFormCertificate\Frontend\wp_send_json_error' )->alias(
            static function () {
                throw new \RuntimeException( 'json_error' );
            }
        );
        Functions\when( 'FreeFormCertificate\Frontend\wp_verify_nonce' )->justReturn( true );
        Functions\when( 'absint' )->alias( static function ( $v ) {
            return abs( (int) $v );
        } );
        Functions\when( 'wp_unslash' )->returnArg();
        \Mockery::mock( 'alias:FreeFormCertificate\Security\RateLimiter' )
            ->shouldReceive( 'check_ip_limit' )->andReturn( array( 'allowed' => true ) );
        \Mockery::mock( 'alias:FreeFormCertificate\Core\SecurityService' )
            ->shouldReceive( 'validate_security_fields' )->andReturn( true );
        // form_id present but hash empty → guard fires.
        \Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
            ->shouldReceive( 'get_user_ip' )->andReturn( '1.2.3.4' )
            ->shouldReceive( 'get_post_string' )->andReturn( '' );
        $_POST['form_id'] = 5;

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'json_error' );
        try {
            $this->exporter->ajax_start();
        } finally {
            unset( $_POST['form_id'] );
        }
    }

    // ==================================================================
    //  ajax_download() — full success body
    // ==================================================================

    public function test_ajax_download_serves_file_and_cleans_up(): void {
        $this->stub_terminators();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';

        Functions\when( 'FreeFormCertificate\Frontend\wp_verify_nonce' )->justReturn( true );
        Functions\when( 'FreeFormCertificate\Frontend\header' )->justReturn( null );
        Functions\when( 'FreeFormCertificate\Frontend\get_post_meta' )->justReturn( 'none' );
        // ajax_download() ends in a bare `exit`; delete_transient() is the last
        // call before it (after readfile + unlink), so throw there to halt.
        Functions\when( 'FreeFormCertificate\Frontend\delete_transient' )->alias(
            static function () {
                throw new \RuntimeException( 'download_exit' );
            }
        );

        // CsvDownloadValidator audit row is fire-and-forget; overload it.
        \Mockery::mock( 'overload:FreeFormCertificate\Frontend\CsvDownloadValidator' )
            ->shouldReceive( 'record_download_log_entry' )->andReturnNull();

        \Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
            ->shouldReceive( 'get_user_ip' )->andReturn( '203.0.113.7' )
            ->shouldReceive( 'get_get_string' )->andReturn( 'job-uuid-1' );

        $tmp = (string) tempnam( sys_get_temp_dir(), 'ffcdl' );
        file_put_contents( $tmp, "BOM;header\n1;row\n" );

        $job = array(
            'ip_hash'  => sha1( '203.0.113.7' ),
            'file'     => $tmp,
            'filename' => 'export.csv',
            'form_id'  => 7,
            'cpf_digits' => '12345678900',
        );
        Functions\when( 'FreeFormCertificate\Frontend\get_transient' )->justReturn( $job );

        // readfile() streams to php://output (buffer discarded by the body), so
        // assert on the side effect instead: reaching the delete_transient throw
        // means readfile + unlink already ran, so the file is gone.
        $this->run_buffer_safe(
            function () {
                $this->exporter->ajax_download();
            },
            'download_exit'
        );

        // File should have been unlinked by the cleanup step.
        $this->assertFileDoesNotExist( $tmp );
        unset( $_SERVER['REMOTE_ADDR'] );
    }

    public function test_ajax_download_rejects_on_bad_nonce(): void {
        $this->stub_terminators();
        Functions\when( 'FreeFormCertificate\Frontend\get_transient' )->justReturn(
            array( 'ip_hash' => sha1( '' ), 'file' => '/x' )
        );
        Functions\when( 'FreeFormCertificate\Frontend\wp_verify_nonce' )->justReturn( false );
        \Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
            ->shouldReceive( 'get_user_ip' )->andReturn( '' )
            ->shouldReceive( 'get_get_string' )->andReturn( 'jid' );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'wp_die' );
        $this->exporter->ajax_download();
    }

    public function test_ajax_download_rejects_on_ip_mismatch(): void {
        $this->stub_terminators();
        Functions\when( 'FreeFormCertificate\Frontend\get_transient' )->justReturn(
            array( 'ip_hash' => 'will-not-match', 'file' => '/x' )
        );
        Functions\when( 'FreeFormCertificate\Frontend\wp_verify_nonce' )->justReturn( true );
        \Mockery::mock( 'alias:FreeFormCertificate\Core\RequestInput' )
            ->shouldReceive( 'get_user_ip' )->andReturn( '9.9.9.9' )
            ->shouldReceive( 'get_get_string' )->andReturn( 'jid' );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'wp_die' );
        $this->exporter->ajax_download();
    }
}

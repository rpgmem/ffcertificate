<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\CsvDownloadValidator;
use FreeFormCertificate\Frontend\PublicCsvDownload;

/**
 * Tests for CsvDownloadValidator: the form-access gate (steps 5–9), the
 * hash-only subset, the per-form CPF gate (none/audit/whitelist/owner/
 * participants/unknown) and the audit-log writer.
 *
 * Runs in separate processes: the CPF gate + audit writer call the real
 * DocumentFormatter / Encryption / Utils statics, and a leaked global
 * function expectation from an earlier same-process test (e.g. a torn-down
 * `wp_unslash` mock) would otherwise poison `Utils::get_user_ip()`. Process
 * isolation guarantees a clean function table per test.
 *
 * @covers \FreeFormCertificate\Frontend\CsvDownloadValidator
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CsvDownloadValidatorTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var CsvDownloadValidator */
    private $validator;

    /** @var array<string, mixed> post-meta store keyed by "post_id:key" */
    private $meta_store = array();

    /** @var array<string, int> update_post_meta call counts */
    private $meta_updates = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->meta_store   = array();
        $this->meta_updates = array();

        Functions\when( '__' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        // Utils::get_user_ip() calls sanitize_text_field()/wp_unslash()
        // unqualified from the Core namespace. Define them as plain stable
        // passthroughs (NOT Brain\Monkey stubs) so they survive tearDown
        // without leaving a torn-down expectation that would poison a later
        // test running in the same process (e.g. DecryptFailureLoggingTest's
        // ActivityLog::log() which also resolves Core\sanitize_text_field).
        self::define_core_passthrough( 'sanitize_text_field' );
        self::define_core_passthrough( 'wp_unslash' );
        Functions\when( 'wp_timezone' )->alias( fn () => new \DateTimeZone( 'UTC' ) );
        Functions\when( 'get_post_type' )->justReturn( 'ffc_form' );
        Functions\when( 'get_option' )->justReturn( array() );

        Functions\when( 'get_post_meta' )->alias(
            function ( $post_id, $key, $single = false ) {
                return $this->meta_store[ (int) $post_id . ':' . $key ] ?? '';
            }
        );
        Functions\when( 'update_post_meta' )->alias(
            function ( $post_id, $key, $value ) {
                $sk                       = (int) $post_id . ':' . $key;
                $this->meta_store[ $sk ]    = $value;
                $this->meta_updates[ $sk ]  = ( $this->meta_updates[ $sk ] ?? 0 ) + 1;
                return true;
            }
        );
        Functions\when( 'get_post_field' )->alias(
            function ( $field, $post_id ) {
                return $this->meta_store[ (int) $post_id . ':__field_' . $field ] ?? 0;
            }
        );
        Functions\when( 'get_user_meta' )->alias(
            function ( $user_id, $key, $single = false ) {
                return $this->meta_store[ 'user_' . (int) $user_id . ':' . $key ] ?? '';
            }
        );

        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';

        $this->validator = new CsvDownloadValidator();
    }

    protected function tearDown(): void {
        $_SERVER = array();
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Define a passthrough function in the FreeFormCertificate\Core namespace
     * once per process. Idempotent: subsequent calls are no-ops. Unlike a
     * Brain\Monkey namespaced stub, this leaves no expectation to be torn
     * down, so it can't poison later same-process tests.
     */
    private static function define_core_passthrough( string $name ): void {
        $fqn = 'FreeFormCertificate\\Core\\' . $name;
        if ( ! function_exists( $fqn ) ) {
            eval( "namespace FreeFormCertificate\\Core; function {$name}( \$v ) { return \$v; }" );
        }
    }

    /** Seed meta so validate_form_access passes every gate by default. */
    private function seed_access( int $form_id, array $overrides = array() ): void {
        $cfg = array_merge(
            array(
                'type'        => 'ffc_form',
                'enabled'     => '1',
                'hash'        => 'storedhash',
                'download'    => '1',
                'limit'       => 5,
                'count'       => 0,
                'date_end'    => '2000-01-01',
                'time_end'    => '00:00:00',
            ),
            $overrides
        );
        Functions\when( 'get_post_type' )->justReturn( $cfg['type'] );
        $this->meta_store[ $form_id . ':' . PublicCsvDownload::META_ENABLED ]        = $cfg['enabled'];
        $this->meta_store[ $form_id . ':' . PublicCsvDownload::META_HASH ]           = $cfg['hash'];
        $this->meta_store[ $form_id . ':_ffc_csv_public_download_enabled' ]          = $cfg['download'];
        $this->meta_store[ $form_id . ':' . PublicCsvDownload::META_LIMIT ]          = $cfg['limit'];
        $this->meta_store[ $form_id . ':' . PublicCsvDownload::META_COUNT ]          = $cfg['count'];
        $this->meta_store[ $form_id . ':_ffc_geofence_config' ]                      = array(
            'date_end' => $cfg['date_end'],
            'time_end' => $cfg['time_end'],
        );
    }

    // ==================================================================
    // validate_form_access
    // ==================================================================

    public function test_form_access_success(): void {
        $this->seed_access( 42 );
        $this->assertNull( $this->validator->validate_form_access( 42, 'storedhash' ) );
    }

    public function test_form_access_rejects_wrong_post_type(): void {
        $this->seed_access( 42, array( 'type' => 'post' ) );
        $this->assertSame( 'Form not found.', $this->validator->validate_form_access( 42, 'storedhash' ) );
    }

    public function test_form_access_rejects_feature_disabled(): void {
        $this->seed_access( 42, array( 'enabled' => '0' ) );
        $this->assertStringContainsString( 'not enabled', $this->validator->validate_form_access( 42, 'storedhash' ) );
    }

    public function test_form_access_rejects_hash_mismatch(): void {
        $this->seed_access( 42 );
        $this->assertSame( 'Invalid access hash.', $this->validator->validate_form_access( 42, 'wronghash' ) );
    }

    public function test_form_access_rejects_empty_stored_hash(): void {
        $this->seed_access( 42, array( 'hash' => '' ) );
        $this->assertSame( 'Invalid access hash.', $this->validator->validate_form_access( 42, '' ) );
    }

    public function test_form_access_rejects_download_disabled(): void {
        $this->seed_access( 42, array( 'download' => '0' ) );
        $this->assertStringContainsString( 'CSV download is disabled', $this->validator->validate_form_access( 42, 'storedhash' ) );
    }

    public function test_form_access_empty_download_meta_defaults_enabled(): void {
        $this->seed_access( 42, array( 'download' => '' ) );
        $this->assertNull( $this->validator->validate_form_access( 42, 'storedhash' ) );
    }

    public function test_form_access_rejects_when_no_end_date(): void {
        $this->seed_access( 42 );
        // Remove geofence config so Geofence::get_form_end_timestamp() returns null.
        $this->meta_store[ '42:_ffc_geofence_config' ] = '';
        $this->assertStringContainsString( 'no end date', $this->validator->validate_form_access( 42, 'storedhash' ) );
    }

    public function test_form_access_rejects_when_form_still_active(): void {
        $this->seed_access( 42, array( 'date_end' => '2999-01-01', 'time_end' => '00:00:00' ) );
        $this->assertStringContainsString( 'still active', $this->validator->validate_form_access( 42, 'storedhash' ) );
    }

    public function test_form_access_rejects_when_quota_reached(): void {
        $this->seed_access( 42, array( 'limit' => 2, 'count' => 2 ) );
        $this->assertStringContainsString( 'maximum number of downloads', $this->validator->validate_form_access( 42, 'storedhash' ) );
    }

    public function test_form_access_falls_back_to_default_limit_when_zero(): void {
        // limit meta 0 → falls back; SettingsReader::get_int returns 0 (no option)
        // → effective limit 1. count 0 < 1 passes.
        $this->seed_access( 42, array( 'limit' => 0, 'count' => 0 ) );
        $this->assertNull( $this->validator->validate_form_access( 42, 'storedhash' ) );
    }

    // ==================================================================
    // validate_hash_only
    // ==================================================================

    public function test_hash_only_success(): void {
        $this->seed_access( 7 );
        $this->assertNull( $this->validator->validate_hash_only( 7, 'storedhash' ) );
    }

    public function test_hash_only_rejects_non_positive_id(): void {
        $this->assertSame( 'Form not found.', $this->validator->validate_hash_only( 0, 'x' ) );
    }

    public function test_hash_only_rejects_wrong_type(): void {
        $this->seed_access( 7, array( 'type' => 'page' ) );
        $this->assertSame( 'Form not found.', $this->validator->validate_hash_only( 7, 'storedhash' ) );
    }

    public function test_hash_only_rejects_disabled(): void {
        $this->seed_access( 7, array( 'enabled' => '0' ) );
        $this->assertStringContainsString( 'not enabled', $this->validator->validate_hash_only( 7, 'storedhash' ) );
    }

    public function test_hash_only_rejects_hash_mismatch(): void {
        $this->seed_access( 7 );
        $this->assertSame( 'Invalid access hash.', $this->validator->validate_hash_only( 7, 'nope' ) );
    }

    // ==================================================================
    // validate_cpf_requirement — none / audit / whitelist / owner
    // ==================================================================

    public function test_cpf_none_mode_returns_null(): void {
        // No mode meta → defaults to 'none'.
        $this->assertNull( $this->validator->validate_cpf_requirement( 10, '' ) );
    }

    public function test_cpf_none_mode_audits_voluntary_valid_cpf(): void {
        $this->meta_store[ '10:' . PublicCsvDownload::META_CPF_MODE ] = 'none';
        $this->assertNull( $this->validator->validate_cpf_requirement( 10, '111.444.777-35' ) );
        // A voluntary audit row was written.
        $this->assertSame( 1, $this->meta_updates[ '10:' . PublicCsvDownload::META_DOWNLOAD_LOG ] ?? 0 );
    }

    public function test_cpf_none_mode_drops_junk_silently(): void {
        $this->meta_store[ '10:' . PublicCsvDownload::META_CPF_MODE ] = 'none';
        $this->assertNull( $this->validator->validate_cpf_requirement( 10, '123' ) );
        $this->assertArrayNotHasKey( '10:' . PublicCsvDownload::META_DOWNLOAD_LOG, $this->meta_updates );
    }

    public function test_cpf_audit_mode_requires_missing_cpf(): void {
        $this->meta_store[ '10:' . PublicCsvDownload::META_CPF_MODE ] = 'audit';
        $msg = $this->validator->validate_cpf_requirement( 10, '' );
        $this->assertStringContainsString( 'CPF is required', $msg );
    }

    public function test_cpf_audit_mode_rejects_bad_format(): void {
        $this->meta_store[ '10:' . PublicCsvDownload::META_CPF_MODE ] = 'audit';
        $this->assertSame( 'Invalid CPF.', $this->validator->validate_cpf_requirement( 10, '00000000000' ) );
    }

    public function test_cpf_audit_mode_passes_valid_cpf(): void {
        $this->meta_store[ '10:' . PublicCsvDownload::META_CPF_MODE ] = 'audit';
        $this->assertNull( $this->validator->validate_cpf_requirement( 10, '11144477735' ) );
    }

    public function test_cpf_whitelist_allows_listed_cpf(): void {
        $this->meta_store[ '10:' . PublicCsvDownload::META_CPF_MODE ]      = 'whitelist';
        $this->meta_store[ '10:' . PublicCsvDownload::META_CPF_WHITELIST ] = "529.982.247-25\n111.444.777-35";
        $this->assertNull( $this->validator->validate_cpf_requirement( 10, '11144477735' ) );
    }

    public function test_cpf_whitelist_blocks_unlisted_cpf(): void {
        $this->meta_store[ '10:' . PublicCsvDownload::META_CPF_MODE ]      = 'whitelist';
        $this->meta_store[ '10:' . PublicCsvDownload::META_CPF_WHITELIST ] = '529.982.247-25';
        $msg = $this->validator->validate_cpf_requirement( 10, '11144477735' );
        $this->assertStringContainsString( 'not authorized', $msg );
    }

    public function test_cpf_owner_blocks_when_no_author(): void {
        $this->meta_store[ '10:' . PublicCsvDownload::META_CPF_MODE ] = 'owner';
        $this->meta_store[ '10:__field_post_author' ]                = 0;
        $msg = $this->validator->validate_cpf_requirement( 10, '11144477735' );
        $this->assertStringContainsString( 'no author', $msg );
    }

    public function test_cpf_owner_matches_author_cpf(): void {
        $this->meta_store[ '10:' . PublicCsvDownload::META_CPF_MODE ] = 'owner';
        $this->meta_store[ '10:__field_post_author' ]                = 88;
        $this->meta_store[ 'user_88:ffc_user_cpf' ]                  = '111.444.777-35';
        $this->assertNull( $this->validator->validate_cpf_requirement( 10, '11144477735' ) );
    }

    public function test_cpf_owner_blocks_on_mismatch(): void {
        $this->meta_store[ '10:' . PublicCsvDownload::META_CPF_MODE ] = 'owner';
        $this->meta_store[ '10:__field_post_author' ]                = 88;
        $this->meta_store[ 'user_88:ffc_user_cpf' ]                  = '529.982.247-25';
        $msg = $this->validator->validate_cpf_requirement( 10, '11144477735' );
        $this->assertStringContainsString( 'does not match', $msg );
    }

    public function test_cpf_unknown_mode_fails_closed(): void {
        $this->meta_store[ '10:' . PublicCsvDownload::META_CPF_MODE ] = 'bogus';
        $msg = $this->validator->validate_cpf_requirement( 10, '11144477735' );
        $this->assertStringContainsString( 'misconfigured', $msg );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_cpf_participants_mode_passes_when_submission_found(): void {
        $repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\SubmissionRepository' );
        $repo->shouldReceive( 'countByFormAndCpfHash' )->once()->andReturn( 1 );

        $this->meta_store[ '10:' . PublicCsvDownload::META_CPF_MODE ] = 'participants';
        $this->assertNull( $this->validator->validate_cpf_requirement( 10, '11144477735' ) );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_cpf_participants_mode_blocks_when_no_submission(): void {
        $repo = Mockery::mock( 'overload:FreeFormCertificate\Repositories\SubmissionRepository' );
        $repo->shouldReceive( 'countByFormAndCpfHash' )->once()->andReturn( 0 );

        $this->meta_store[ '10:' . PublicCsvDownload::META_CPF_MODE ] = 'participants';
        $msg = $this->validator->validate_cpf_requirement( 10, '11144477735' );
        $this->assertStringContainsString( 'No submission with this CPF', $msg );
    }

    public function test_cpf_silent_audit_suppresses_log_row(): void {
        $this->meta_store[ '10:' . PublicCsvDownload::META_CPF_MODE ] = 'audit';
        $this->validator->validate_cpf_requirement( 10, '11144477735', true );
        $this->assertArrayNotHasKey( '10:' . PublicCsvDownload::META_DOWNLOAD_LOG, $this->meta_updates );
    }

    // ==================================================================
    // record_download_log_entry — ring buffer + encryption
    // ==================================================================

    public function test_record_download_log_entry_appends_row(): void {
        $this->validator->record_download_log_entry( 10, 'access', '', 'fail_other' );
        $log = $this->meta_store[ '10:' . PublicCsvDownload::META_DOWNLOAD_LOG ];
        $this->assertCount( 1, $log );
        $this->assertSame( 'fail_other', $log[0]['result'] );
        $this->assertSame( 'access', $log[0]['mode'] );
    }

    public function test_record_download_log_entry_encrypts_cpf_when_digits_present(): void {
        // Encryption is configured in the unit bootstrap, so cpf_encrypted
        // should be a non-empty ciphertext.
        $this->validator->record_download_log_entry( 10, 'audit', '11144477735', 'audit_pass' );
        $log = $this->meta_store[ '10:' . PublicCsvDownload::META_DOWNLOAD_LOG ];
        $this->assertNotSame( '', $log[0]['cpf_encrypted'] );
    }

    public function test_record_download_log_entry_prunes_to_max(): void {
        // Pre-seed DOWNLOAD_LOG_MAX rows, then append one more.
        $rows = array_fill( 0, PublicCsvDownload::DOWNLOAD_LOG_MAX, array( 'result' => 'old' ) );
        $this->meta_store[ '10:' . PublicCsvDownload::META_DOWNLOAD_LOG ] = $rows;

        $this->validator->record_download_log_entry( 10, 'access', '', 'success' );

        $log = $this->meta_store[ '10:' . PublicCsvDownload::META_DOWNLOAD_LOG ];
        $this->assertCount( PublicCsvDownload::DOWNLOAD_LOG_MAX, $log );
        // The oldest row is dropped; the new row is last.
        $this->assertSame( 'success', $log[ PublicCsvDownload::DOWNLOAD_LOG_MAX - 1 ]['result'] );
    }
}

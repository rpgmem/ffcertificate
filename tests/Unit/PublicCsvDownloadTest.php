<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\CsvDownloadFormInfoBuilder;
use FreeFormCertificate\Frontend\PublicCsvDownload;
use FreeFormCertificate\Security\RateLimiter;
use FreeFormCertificate\Security\RateLimitChecker;

/**
 * Tests for PublicCsvDownload: shortcode + admin-post handler.
 *
 * Strategy:
 *  - handle_request() ends every failure branch with wp_safe_redirect + exit;
 *    we mock wp_safe_redirect to throw a RuntimeException so the test can
 *    verify which branch was hit without actually terminating the process.
 *  - RateLimiter is a static dependency. Its internal $wpdb / wp_cache_* calls
 *    are stubbed so it returns allowed=true by default (see setUp()).
 *  - The happy-path test stops one step before the CSV stream by throwing in
 *    wp_raise_memory_limit — that runs at the top of PublicCsvExporter::stream_form_csv
 *    AFTER the counter has been incremented, which is the observable behavior
 *    we care about here.
 */
class PublicCsvDownloadTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var PublicCsvDownload */
    private $handler;

    /** @var array<string, array<string, int>> post meta store, keyed by post_id/key */
    private $meta_store = array();

    /** @var array<string, int> track update_post_meta calls */
    private $meta_updates = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->meta_store   = array();
        $this->meta_updates = array();

        // Reset RateLimiter's static settings cache between tests. The cache
        // moved to RateLimitChecker in the S4 facade refactor.
        $rl = new \ReflectionClass( RateLimitChecker::class );
        if ( $rl->hasProperty( 'settings_cache' ) ) {
            $prop = $rl->getProperty( 'settings_cache' );
            $prop->setAccessible( true );
            $prop->setValue( null, null );
        }

        // --- Generic WP stubs -----------------------------------------
        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'absint' )->alias( function ( $val ) { return abs( (int) $val ); } );
        Functions\when( 'shortcode_atts' )->alias( function ( $defaults, $atts ) {
            return array_merge( $defaults, is_array( $atts ) ? $atts : array() );
        } );
        Functions\when( 'admin_url' )->alias( function ( $path ) { return 'https://example.com/wp-admin/' . ltrim( (string) $path, '/' ); } );
        Functions\when( 'home_url' )->alias( function ( $path = '/' ) { return 'https://example.com' . $path; } );
        Functions\when( 'wp_nonce_field' )->alias( function () { echo '<input type="hidden" name="_ffc_pcd_nonce" value="nonce">'; } );
        Functions\when( 'wp_verify_nonce' )->justReturn( true );
        Functions\when( 'wp_rand' )->justReturn( 3 );
        Functions\when( 'wp_hash' )->justReturn( 'captcha_hash' );
        Functions\when( 'wp_get_referer' )->justReturn( 'https://example.com/download' );

        // Utilities used by the handler.
        Functions\when( 'set_transient' )->justReturn( true );
        Functions\when( 'get_transient' )->justReturn( false );
        Functions\when( 'delete_transient' )->justReturn( true );

        // --- Utils::get_user_ip() avoids touching $_SERVER --------------
        $_SERVER['REMOTE_ADDR'] = '203.0.113.99';

        // Namespaced stubs in FreeFormCertificate\Core — needed because
        // earlier tests (UrlShortenerMetaBoxTest, AppointmentHandlerTest, …)
        // may have already defined these as real PHP functions, in which
        // case an unqualified call from inside a FreeFormCertificate\Core
        // class resolves there first rather than falling through to the
        // global stub. Re-registering them attaches an expectation for
        // this test run.
        Functions\when( 'FreeFormCertificate\Core\wp_unslash' )->returnArg();
        Functions\when( 'FreeFormCertificate\Core\sanitize_text_field' )->returnArg();

        // Global stubs relied on by RateLimiter (called from our handler).
        // IMPORTANT: these are registered as GLOBAL stubs, not as namespaced
        // FreeFormCertificate\Security\* stubs. Brain\Monkey's namespaced
        // stubs define real PHP functions that persist across tearDown —
        // polluting subsequent tests that don't re-register them. Relying
        // on PHP's fallback from an unqualified call to the global function
        // avoids that pollution.
        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );
        Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults = array() ) {
            if ( is_array( $args ) ) {
                return array_merge( $defaults, $args );
            }
            return $defaults;
        } );

        // Stub $wpdb for RateLimiter's get_count_from_db().
        global $wpdb;
        $wpdb         = Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' )->byDefault();
        $wpdb->shouldReceive( 'get_var' )->andReturn( '0' )->byDefault();
        $wpdb->shouldReceive( 'get_row' )->andReturn( null )->byDefault();

        // --- Meta store backing for get_post_meta / update_post_meta ----
        Functions\when( 'get_post_meta' )->alias( function ( $post_id, $key, $single = false ) {
            $store_key = (int) $post_id . ':' . $key;
            return $this->meta_store[ $store_key ] ?? '';
        } );
        Functions\when( 'update_post_meta' )->alias( function ( $post_id, $key, $value ) {
            $store_key                     = (int) $post_id . ':' . $key;
            $this->meta_store[ $store_key ]   = $value;
            $this->meta_updates[ $store_key ] = ( $this->meta_updates[ $store_key ] ?? 0 ) + 1;
            return true;
        } );
        Functions\when( 'get_post_type' )->justReturn( 'ffc_form' );
        Functions\when( 'get_option' )->justReturn( array() );

        $this->handler = new PublicCsvDownload();
    }

    protected function tearDown(): void {
        unset( $_POST, $_GET );
        $_SERVER = array();
        // Clear the $wpdb Mockery mock so it does not leak into the next test.
        global $wpdb;
        $wpdb = null;
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Expect a redirect by making wp_safe_redirect throw; returns the caught
     * exception message (so tests can inspect which branch was taken).
     */
    private function captureRedirect( callable $run ): \RuntimeException {
        Functions\when( 'wp_safe_redirect' )->alias( function () {
            throw new \RuntimeException( 'redirected' );
        } );
        try {
            $run();
            $this->fail( 'Expected wp_safe_redirect to be invoked' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 'redirected', $e->getMessage() );
            return $e;
        }
        // @phpstan-ignore-next-line — unreachable
        return new \RuntimeException();
    }

    /**
     * Builds a valid POST payload and seeds the form meta so all checks pass.
     *
     * Override any key to simulate a specific failure branch.
     */
    private function seed_valid_request( int $form_id = 42, array $overrides = array() ): void {
        $defaults = array(
            'enabled'     => '1',
            'stored_hash' => 'validhash123',
            'limit'       => 5,
            'count'       => 0,
            'date_end'    => '2000-01-01', // Past by default → form expired.
            'time_end'    => '00:00:00',
            'post_type'   => 'ffc_form',
            'posted_hash' => 'validhash123',
        );
        $cfg = array_merge( $defaults, $overrides );

        $this->meta_store[ $form_id . ':_ffc_csv_public_enabled' ] = $cfg['enabled'];
        $this->meta_store[ $form_id . ':_ffc_csv_public_hash' ]    = $cfg['stored_hash'];
        $this->meta_store[ $form_id . ':_ffc_csv_public_limit' ]   = $cfg['limit'];
        $this->meta_store[ $form_id . ':_ffc_csv_public_count' ]   = $cfg['count'];
        $this->meta_store[ $form_id . ':_ffc_geofence_config' ]    = array(
            'date_end' => $cfg['date_end'],
            'time_end' => $cfg['time_end'],
        );

        Functions\when( 'get_post_type' )->justReturn( $cfg['post_type'] );
        Functions\when( 'wp_timezone' )->alias( function () { return new \DateTimeZone( 'UTC' ); } );

        // Honeypot must be empty and captcha must match wp_hash() stub
        // (returning 'captcha_hash' from setUp's Functions\when).
        $_POST = array(
            '_ffc_pcd_nonce'    => 'nonce-value',
            'form_id'           => (string) $form_id,
            'hash'              => $cfg['posted_hash'],
            'ffc_honeypot_trap' => '',
            'ffc_captcha_ans'   => '6',
            'ffc_captcha_hash'  => 'captcha_hash',
        );
    }

    // ==================================================================
    //  Constants + hook registration
    // ==================================================================

    public function test_constants_have_expected_values(): void {
        $this->assertSame( 'ffc_csv_download', PublicCsvDownload::SHORTCODE );
        $this->assertSame( 'ffc_public_csv_download', PublicCsvDownload::ACTION );
        $this->assertSame( 'ffc_public_csv_download', PublicCsvDownload::NONCE_ACTION );
        $this->assertSame( '_ffc_csv_public_enabled', PublicCsvDownload::META_ENABLED );
        $this->assertSame( '_ffc_csv_public_hash', PublicCsvDownload::META_HASH );
        $this->assertSame( '_ffc_csv_public_limit', PublicCsvDownload::META_LIMIT );
        $this->assertSame( '_ffc_csv_public_count', PublicCsvDownload::META_COUNT );
        $this->assertSame( '_ffc_csv_public_cpf_mode', PublicCsvDownload::META_CPF_MODE );
        $this->assertSame( '_ffc_csv_public_cpf_whitelist', PublicCsvDownload::META_CPF_WHITELIST );
        $this->assertSame( '_ffc_csv_public_download_log', PublicCsvDownload::META_DOWNLOAD_LOG );
    }

    // ==================================================================
    //  validate_cpf_requirement() — five modes + audit log
    // ==================================================================

    public function test_validate_cpf_requirement_none_mode_skips_check(): void {
        $form_id = 42;
        $this->meta_store[ $form_id . ':_ffc_csv_public_cpf_mode' ] = 'none';

        $result = $this->handler->validate_cpf_requirement( $form_id, '' );

        $this->assertNull( $result, 'Mode "none" should never block' );
        $this->assertArrayNotHasKey(
            $form_id . ':_ffc_csv_public_download_log',
            $this->meta_store,
            'Mode "none" should never write to the audit log'
        );
    }

    public function test_validate_cpf_requirement_audit_logs_but_never_blocks(): void {
        $form_id = 42;
        $this->meta_store[ $form_id . ':_ffc_csv_public_cpf_mode' ] = 'audit';

        // Use a valid CPF to bypass format gate.
        $valid_cpf = '529.982.247-25';
        $result    = $this->handler->validate_cpf_requirement( $form_id, $valid_cpf );

        $this->assertNull( $result, 'Audit mode must not block' );
        $log = $this->meta_store[ $form_id . ':_ffc_csv_public_download_log' ] ?? array();
        $this->assertCount( 1, $log );
        $this->assertSame( 'audit', $log[0]['mode'] );
        $this->assertSame( 'audit_pass', $log[0]['result'] );
        $this->assertArrayHasKey( 'cpf_encrypted', $log[0] );
        $this->assertArrayNotHasKey( 'cpf_hash', $log[0], 'cpf_hash dropped in 6.3.3' );
    }

    public function test_validate_cpf_requirement_blocks_missing_cpf(): void {
        $form_id = 42;
        $this->meta_store[ $form_id . ':_ffc_csv_public_cpf_mode' ] = 'whitelist';

        $result = $this->handler->validate_cpf_requirement( $form_id, '' );

        $this->assertIsString( $result );
        $log = $this->meta_store[ $form_id . ':_ffc_csv_public_download_log' ] ?? array();
        $this->assertSame( 'fail_missing', $log[0]['result'] );
    }

    public function test_validate_cpf_requirement_blocks_invalid_format(): void {
        $form_id = 42;
        $this->meta_store[ $form_id . ':_ffc_csv_public_cpf_mode' ] = 'whitelist';

        // 11 same digits → invalid CPF check digit.
        $result = $this->handler->validate_cpf_requirement( $form_id, '11111111111' );

        $this->assertIsString( $result );
        $log = $this->meta_store[ $form_id . ':_ffc_csv_public_download_log' ] ?? array();
        $this->assertSame( 'fail_format', $log[0]['result'] );
    }

    public function test_validate_cpf_requirement_whitelist_match_passes(): void {
        $form_id = 42;
        $this->meta_store[ $form_id . ':_ffc_csv_public_cpf_mode' ]      = 'whitelist';
        $this->meta_store[ $form_id . ':_ffc_csv_public_cpf_whitelist' ] = "52998224725\n11144477735";

        $result = $this->handler->validate_cpf_requirement( $form_id, '529.982.247-25' );

        $this->assertNull( $result );
        $log = $this->meta_store[ $form_id . ':_ffc_csv_public_download_log' ] ?? array();
        $this->assertSame( 'success', $log[0]['result'] );
    }

    public function test_validate_cpf_requirement_whitelist_miss_blocks(): void {
        $form_id = 42;
        $this->meta_store[ $form_id . ':_ffc_csv_public_cpf_mode' ]      = 'whitelist';
        $this->meta_store[ $form_id . ':_ffc_csv_public_cpf_whitelist' ] = '52998224725';

        // Different valid CPF.
        $result = $this->handler->validate_cpf_requirement( $form_id, '11144477735' );

        $this->assertIsString( $result );
        $log = $this->meta_store[ $form_id . ':_ffc_csv_public_download_log' ] ?? array();
        $this->assertSame( 'fail_match', $log[0]['result'] );
    }

    public function test_validate_cpf_requirement_audit_log_caps_at_max_entries(): void {
        $form_id = 42;
        $this->meta_store[ $form_id . ':_ffc_csv_public_cpf_mode' ] = 'audit';

        // Pre-fill DOWNLOAD_LOG_MAX existing entries.
        $existing = array();
        for ( $i = 0; $i < PublicCsvDownload::DOWNLOAD_LOG_MAX; $i++ ) {
            $existing[] = array( 'ts' => $i, 'ip' => '0.0.0.0', 'mode' => 'audit', 'cpf_encrypted' => 'h', 'result' => 'audit_pass' );
        }
        $this->meta_store[ $form_id . ':_ffc_csv_public_download_log' ] = $existing;

        $this->handler->validate_cpf_requirement( $form_id, '529.982.247-25' );

        $log = $this->meta_store[ $form_id . ':_ffc_csv_public_download_log' ] ?? array();
        $this->assertCount( PublicCsvDownload::DOWNLOAD_LOG_MAX, $log, 'Log should be capped' );
        $this->assertNotSame( 0, $log[0]['ts'], 'Oldest entry should have been dropped' );
    }

    // ==================================================================
    //  6.3.3 — voluntary logging in mode='none' + encrypted CPF + helpers
    // ==================================================================

    public function test_validate_cpf_requirement_none_mode_logs_voluntary_when_filled_and_valid(): void {
        $form_id = 42;
        $this->meta_store[ $form_id . ':_ffc_csv_public_cpf_mode' ] = 'none';

        $result = $this->handler->validate_cpf_requirement( $form_id, '529.982.247-25' );

        $this->assertNull( $result, 'mode=none must never block, even with CPF filled' );
        $log = $this->meta_store[ $form_id . ':_ffc_csv_public_download_log' ] ?? array();
        $this->assertCount( 1, $log );
        $this->assertSame( 'none', $log[0]['mode'] );
        $this->assertSame( 'voluntary', $log[0]['result'] );
        $this->assertArrayHasKey( 'cpf_encrypted', $log[0] );
    }

    public function test_validate_cpf_requirement_none_mode_skips_voluntary_log_for_invalid_format(): void {
        $form_id = 42;
        $this->meta_store[ $form_id . ':_ffc_csv_public_cpf_mode' ] = 'none';

        // 11 same digits → checksum invalid; junk drops silently.
        $result = $this->handler->validate_cpf_requirement( $form_id, '11111111111' );

        $this->assertNull( $result );
        $this->assertArrayNotHasKey(
            $form_id . ':_ffc_csv_public_download_log',
            $this->meta_store,
            'Invalid voluntary CPFs must not pollute the audit log'
        );
    }

    public function test_record_log_entry_uses_cpf_encrypted_field_only(): void {
        $form_id = 42;
        $this->meta_store[ $form_id . ':_ffc_csv_public_cpf_mode' ] = 'audit';

        $this->handler->validate_cpf_requirement( $form_id, '529.982.247-25' );

        $log = $this->meta_store[ $form_id . ':_ffc_csv_public_download_log' ] ?? array();
        $this->assertNotEmpty( $log );
        $entry = $log[0];

        // 6.3.3 schema: cpf_encrypted only, cpf_hash dropped.
        $this->assertArrayHasKey( 'cpf_encrypted', $entry );
        $this->assertArrayNotHasKey( 'cpf_hash', $entry );

        // Encryption is configured in tests/bootstrap.php (SECURE_AUTH_KEY +
        // LOGGED_IN_KEY constants are seeded), so cpf_encrypted should be a
        // non-empty string different from the digits themselves.
        $this->assertIsString( $entry['cpf_encrypted'] );
        $this->assertNotEmpty( $entry['cpf_encrypted'] );
        $this->assertNotSame( '52998224725', $entry['cpf_encrypted'] );
    }

    public function test_get_audit_log_summary_returns_count_and_url_when_log_present(): void {
        $form_id = 42;
        $this->meta_store[ $form_id . ':_ffc_csv_public_download_log' ] = array(
            array( 'ts' => time(), 'ip' => '1.2.3.4', 'mode' => 'audit', 'cpf_encrypted' => 'x', 'result' => 'audit_pass' ),
            array( 'ts' => time(), 'ip' => '1.2.3.4', 'mode' => 'audit', 'cpf_encrypted' => 'y', 'result' => 'audit_pass' ),
        );
        Functions\when( 'wp_create_nonce' )->justReturn( 'n' );
        Functions\when( 'add_query_arg' )->alias( function ( $args, $url ) {
            return $url . '?' . http_build_query( $args );
        } );

        $summary = PublicCsvDownload::get_audit_log_summary( $form_id );

        $this->assertSame( 2, $summary['count'] );
        $this->assertIsString( $summary['url'] );
        $this->assertStringContainsString( PublicCsvDownload::EXPORT_LOG_ACTION, $summary['url'] );
    }

    public function test_get_audit_log_summary_returns_null_url_when_log_empty(): void {
        $form_id = 42;
        // No meta seeded.
        $summary = PublicCsvDownload::get_audit_log_summary( $form_id );

        $this->assertSame( 0, $summary['count'] );
        $this->assertNull( $summary['url'] );
    }

    public function test_get_audit_log_summary_three_buckets(): void {
        $form_id = 99;
        $this->meta_store[ $form_id . ':_ffc_csv_public_count' ] = 7;
        $this->meta_store[ $form_id . ':_ffc_csv_public_download_log' ] = array(
            array( 'result' => 'success' ),
            array( 'result' => 'audit_pass' ),
            array( 'result' => 'voluntary' ),
            array( 'result' => 'fail_format' ),
            array( 'result' => 'fail_captcha' ),
            array( 'result' => 'fail_other' ),
            array( 'result' => 'fail_match' ),
            array( 'result' => 'fail_missing' ),
        );
        Functions\when( 'wp_create_nonce' )->justReturn( 'n' );
        Functions\when( 'add_query_arg' )->alias( fn( $args, $url ) => $url . '?' . http_build_query( $args ) );

        $summary = PublicCsvDownload::get_audit_log_summary( $form_id );

        $this->assertSame( 3, $summary['access_success'], 'success + audit_pass + voluntary' );
        $this->assertSame( 7, $summary['download_success'], 'META_COUNT (long-lived counter)' );
        $this->assertSame( 5, $summary['failed_access'], 'all fail_* tags incl. fail_captcha + fail_other' );
        // Legacy keys still populated for backwards compat.
        $this->assertSame( 3, $summary['success'] );
        $this->assertSame( 5, $summary['fail'] );
    }

    public function test_get_audit_log_summary_unknown_tag_counted_as_failure(): void {
        $form_id = 100;
        $this->meta_store[ $form_id . ':_ffc_csv_public_download_log' ] = array(
            array( 'result' => 'success' ),
            array( 'result' => 'totally_unknown_future_tag' ),
        );
        Functions\when( 'wp_create_nonce' )->justReturn( 'n' );
        Functions\when( 'add_query_arg' )->alias( fn( $args, $url ) => $url . '?' . http_build_query( $args ) );

        $summary = PublicCsvDownload::get_audit_log_summary( $form_id );

        $this->assertSame( 1, $summary['access_success'] );
        // Unknown tag falls through to failed_access — prevents silent
        // inflation of the success bucket if we ever forget to update the
        // tag list when adding a new positive outcome.
        $this->assertSame( 1, $summary['failed_access'] );
    }

    public function test_get_audit_log_summary_skips_download_delivered_in_buckets(): void {
        // `download_delivered` rows are written by the post-#241 audit
        // fix to record actual file deliveries (independent of CPF
        // gate). They MUST be excluded from both access_success and
        // failed_access — otherwise CPF-gated flows would double-count
        // (one row from the validator + one delivery row per download).
        // META_COUNT remains the canonical source for download_success.
        $form_id = 101;
        $this->meta_store[ $form_id . ':_ffc_csv_public_count' ]        = 5;
        $this->meta_store[ $form_id . ':_ffc_csv_public_download_log' ] = array(
            array( 'result' => 'success' ),
            array( 'result' => 'download_delivered' ),
            array( 'result' => 'download_delivered' ),
            array( 'result' => 'fail_match' ),
        );
        Functions\when( 'wp_create_nonce' )->justReturn( 'n' );
        Functions\when( 'add_query_arg' )->alias( fn( $args, $url ) => $url . '?' . http_build_query( $args ) );

        $summary = PublicCsvDownload::get_audit_log_summary( $form_id );

        $this->assertSame( 1, $summary['access_success'], 'success row counts; download_delivered does NOT' );
        $this->assertSame( 1, $summary['failed_access'], 'fail_match counts; download_delivered does NOT fall through to failures' );
        $this->assertSame( 5, $summary['download_success'], 'download_success keeps coming from META_COUNT' );
    }

    public function test_register_hooks_registers_shortcode_and_admin_post_actions(): void {
        $captured = array();
        Functions\when( 'add_shortcode' )->alias( function ( $tag, $cb ) use ( &$captured ) {
            $captured[] = array( 'tag' => $tag, 'cb' => $cb );
            return true;
        } );
        Actions\expectAdded( 'admin_post_ffc_public_csv_download' )->once();
        Actions\expectAdded( 'admin_post_nopriv_ffc_public_csv_download' )->once();

        $this->handler->register_hooks();

        $this->assertCount( 1, $captured );
        $this->assertSame( 'ffc_csv_download', $captured[0]['tag'] );
    }

    // ==================================================================
    //  render_shortcode()
    // ==================================================================

    public function test_render_shortcode_outputs_form_with_nonce_and_fields(): void {
        unset( $_GET['form_id'], $_GET['hash'] );

        $html = $this->handler->render_shortcode( array() );

        $this->assertStringContainsString( 'ffc-public-csv-download', $html );
        $this->assertStringContainsString( 'name="action" value="ffc_public_csv_download"', $html );
        $this->assertStringContainsString( 'name="form_id"', $html );
        $this->assertStringContainsString( 'name="hash"', $html );
        $this->assertStringContainsString( '_ffc_pcd_nonce', $html );
        // Honeypot rendered by Shortcodes::generate_security_fields()
        $this->assertStringContainsString( 'ffc_honeypot_trap', $html );
        $this->assertStringContainsString( 'ffc_captcha_ans', $html );
    }

    public function test_render_shortcode_prefills_form_id_and_hash_from_query_string(): void {
        $_GET['form_id'] = '123';
        $_GET['hash']    = 'prefilledhash';

        $html = $this->handler->render_shortcode( array() );

        $this->assertStringContainsString( 'value="123"', $html );
        $this->assertStringContainsString( 'prefilledhash', $html );
    }

    public function test_render_shortcode_uses_custom_title(): void {
        unset( $_GET['form_id'], $_GET['hash'] );

        $html = $this->handler->render_shortcode( array( 'title' => 'Get participants list' ) );

        $this->assertStringContainsString( 'Get participants list', $html );
    }

    public function test_render_shortcode_shows_flash_message_when_set(): void {
        unset( $_GET['form_id'], $_GET['hash'] );
        Functions\when( 'get_transient' )->justReturn( array(
            'type'    => 'error',
            'message' => 'Invalid access hash.',
        ) );

        $html = $this->handler->render_shortcode( array() );

        $this->assertStringContainsString( 'ffc-pcd-message', $html );
        $this->assertStringContainsString( 'Invalid access hash.', $html );
    }

    // ==================================================================
    //  handle_request() — failure branches
    // ==================================================================

    public function test_handle_request_rejects_invalid_nonce(): void {
        $this->seed_valid_request();
        Functions\when( 'wp_verify_nonce' )->justReturn( false );

        $this->captureRedirect( function () {
            $this->handler->handle_request();
        } );

        $this->assertArrayNotHasKey( '42:_ffc_csv_public_count', $this->meta_updates );
    }

    public function test_handle_request_rejects_missing_form_id(): void {
        $this->seed_valid_request();
        unset( $_POST['form_id'] );

        $this->captureRedirect( function () {
            $this->handler->handle_request();
        } );

        $this->assertArrayNotHasKey( '42:_ffc_csv_public_count', $this->meta_updates );
    }

    public function test_handle_request_rejects_missing_hash_input(): void {
        $this->seed_valid_request();
        $_POST['hash'] = '';

        $this->captureRedirect( function () {
            $this->handler->handle_request();
        } );

        $this->assertArrayNotHasKey( '42:_ffc_csv_public_count', $this->meta_updates );
    }

    public function test_handle_request_rejects_non_ffc_form_post_type(): void {
        $this->seed_valid_request();
        Functions\when( 'get_post_type' )->justReturn( 'post' );

        $this->captureRedirect( function () {
            $this->handler->handle_request();
        } );

        $this->assertArrayNotHasKey( '42:_ffc_csv_public_count', $this->meta_updates );
    }

    public function test_handle_request_rejects_when_feature_disabled(): void {
        $this->seed_valid_request( 42, array( 'enabled' => '0' ) );

        $this->captureRedirect( function () {
            $this->handler->handle_request();
        } );

        $this->assertArrayNotHasKey( '42:_ffc_csv_public_count', $this->meta_updates );
    }

    public function test_handle_request_rejects_wrong_hash(): void {
        $this->seed_valid_request( 42, array( 'stored_hash' => 'correct', 'posted_hash' => 'incorrect' ) );

        $this->captureRedirect( function () {
            $this->handler->handle_request();
        } );

        $this->assertArrayNotHasKey( '42:_ffc_csv_public_count', $this->meta_updates );
    }

    public function test_handle_request_rejects_when_hash_never_generated(): void {
        $this->seed_valid_request( 42, array( 'stored_hash' => '', 'posted_hash' => 'anything' ) );

        $this->captureRedirect( function () {
            $this->handler->handle_request();
        } );

        $this->assertArrayNotHasKey( '42:_ffc_csv_public_count', $this->meta_updates );
    }

    public function test_handle_request_rejects_when_geofence_has_no_end_date(): void {
        $this->seed_valid_request( 42, array( 'date_end' => '' ) );

        $this->captureRedirect( function () {
            $this->handler->handle_request();
        } );

        $this->assertArrayNotHasKey( '42:_ffc_csv_public_count', $this->meta_updates );
    }

    public function test_handle_request_rejects_when_form_still_active(): void {
        $future = gmdate( 'Y-m-d', time() + 86400 * 7 );
        $this->seed_valid_request( 42, array( 'date_end' => $future, 'time_end' => '23:59:59' ) );

        $this->captureRedirect( function () {
            $this->handler->handle_request();
        } );

        $this->assertArrayNotHasKey( '42:_ffc_csv_public_count', $this->meta_updates );
    }

    public function test_handle_request_rejects_when_quota_reached(): void {
        $this->seed_valid_request( 42, array( 'limit' => 3, 'count' => 3 ) );

        $this->captureRedirect( function () {
            $this->handler->handle_request();
        } );

        $this->assertArrayNotHasKey( '42:_ffc_csv_public_count', $this->meta_updates );
    }

    public function test_handle_request_rejects_when_count_exceeds_limit(): void {
        $this->seed_valid_request( 42, array( 'limit' => 2, 'count' => 5 ) );

        $this->captureRedirect( function () {
            $this->handler->handle_request();
        } );

        $this->assertArrayNotHasKey( '42:_ffc_csv_public_count', $this->meta_updates );
    }

    public function test_handle_request_falls_back_to_settings_default_limit_when_form_limit_zero(): void {
        // Form limit = 0 → fall back to ffc_settings['public_csv_default_limit'] = 2,
        // count = 2 → blocked.
        $this->seed_valid_request( 42, array( 'limit' => 0, 'count' => 2 ) );
        Functions\when( 'get_option' )->alias( function ( $key, $default = array() ) {
            if ( $key === 'ffc_settings' ) {
                return array( 'public_csv_default_limit' => 2 );
            }
            return $default;
        } );

        $this->captureRedirect( function () {
            $this->handler->handle_request();
        } );

        $this->assertArrayNotHasKey( '42:_ffc_csv_public_count', $this->meta_updates );
    }

    // ==================================================================
    //  handle_request() — happy path observable effects
    // ==================================================================

    public function test_handle_request_increments_counter_before_streaming(): void {
        $this->seed_valid_request( 42, array( 'limit' => 5, 'count' => 2 ) );

        // wp_raise_memory_limit is the first call inside PublicCsvExporter::stream_form_csv().
        // Throwing here stops execution *after* update_post_meta was called, letting
        // us verify the counter was incremented before the stream started.
        Functions\when( 'wp_raise_memory_limit' )->alias( function () {
            throw new \RuntimeException( 'stream_started' );
        } );

        try {
            $this->handler->handle_request();
            $this->fail( 'Expected handle_request to reach stream_form_csv' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 'stream_started', $e->getMessage() );
        }

        $this->assertSame( 3, $this->meta_store['42:_ffc_csv_public_count'] );
        $this->assertSame( 1, $this->meta_updates['42:_ffc_csv_public_count'] );
    }

    // ==================================================================
    //  validate_form_access() — direct unit tests
    // ==================================================================

    public function test_validate_form_access_returns_null_on_success(): void {
        $this->seed_valid_request( 42 );
        $result = $this->handler->validate_form_access( 42, 'validhash123' );
        $this->assertNull( $result );
    }

    public function test_validate_form_access_rejects_non_ffc_form(): void {
        $this->seed_valid_request( 42, array( 'post_type' => 'post' ) );
        $result = $this->handler->validate_form_access( 42, 'validhash123' );
        $this->assertNotNull( $result );
        $this->assertStringContainsString( 'Form not found', $result );
    }

    public function test_validate_form_access_rejects_disabled_feature(): void {
        $this->seed_valid_request( 42, array( 'enabled' => '0' ) );
        $result = $this->handler->validate_form_access( 42, 'validhash123' );
        $this->assertNotNull( $result );
        $this->assertStringContainsString( 'not enabled', $result );
    }

    public function test_validate_form_access_rejects_invalid_hash(): void {
        $this->seed_valid_request( 42 );
        $result = $this->handler->validate_form_access( 42, 'wronghash' );
        $this->assertNotNull( $result );
        $this->assertStringContainsString( 'Invalid access hash', $result );
    }

    public function test_validate_form_access_rejects_missing_end_date(): void {
        $this->seed_valid_request( 42, array( 'date_end' => '' ) );
        $result = $this->handler->validate_form_access( 42, 'validhash123' );
        $this->assertNotNull( $result );
        $this->assertStringContainsString( 'no end date', $result );
    }

    public function test_validate_form_access_rejects_active_form(): void {
        $future = gmdate( 'Y-m-d', time() + 86400 * 7 );
        $this->seed_valid_request( 42, array( 'date_end' => $future, 'time_end' => '23:59:59' ) );
        $result = $this->handler->validate_form_access( 42, 'validhash123' );
        $this->assertNotNull( $result );
        $this->assertStringContainsString( 'still active', $result );
    }

    public function test_validate_form_access_rejects_exceeded_quota(): void {
        $this->seed_valid_request( 42, array( 'limit' => 3, 'count' => 3 ) );
        $result = $this->handler->validate_form_access( 42, 'validhash123' );
        $this->assertNotNull( $result );
        $this->assertStringContainsString( 'maximum number of downloads', $result );
    }

    public function test_validate_form_access_uses_settings_default_when_form_limit_zero(): void {
        $this->seed_valid_request( 42, array( 'limit' => 0, 'count' => 1 ) );
        Functions\when( 'get_option' )->alias( function ( $key, $default = array() ) {
            if ( $key === 'ffc_settings' ) {
                return array( 'public_csv_default_limit' => 1 );
            }
            return $default;
        } );
        $result = $this->handler->validate_form_access( 42, 'validhash123' );
        $this->assertNotNull( $result );
        $this->assertStringContainsString( 'maximum number of downloads', $result );
    }

    // ==================================================================
    //  register_hooks() — AJAX hook registration
    // ==================================================================

    public function test_register_hooks_registers_ajax_actions(): void {
        $registered = array();
        Functions\when( 'add_shortcode' )->justReturn( true );
        Functions\when( 'add_action' )->alias( function ( $tag, $cb ) use ( &$registered ) {
            $registered[] = $tag;
        } );

        $this->handler->register_hooks();

        $this->assertContains( 'wp_ajax_ffc_public_csv_start', $registered );
        $this->assertContains( 'wp_ajax_nopriv_ffc_public_csv_start', $registered );
        $this->assertContains( 'wp_ajax_ffc_public_csv_batch', $registered );
        $this->assertContains( 'wp_ajax_nopriv_ffc_public_csv_batch', $registered );
        $this->assertContains( 'wp_ajax_ffc_public_csv_download', $registered );
        $this->assertContains( 'wp_ajax_nopriv_ffc_public_csv_download', $registered );
    }

    // ==================================================================
    //  6.3.5 — build_datetime_info() timezone anchoring (regression)
    // ==================================================================

    /**
     * Configured 2026-05-12 in America/Sao_Paulo (UTC-3) was being
     * displayed as 11/05/2026 because strtotime('2026-05-12') reads as
     * UTC midnight, then wp_date() converts to BRT (-3h) and renders
     * the previous day. The fix anchors via DateTimeImmutable($date, $tz).
     */
    public function test_build_datetime_info_keeps_configured_date_when_tz_is_brt(): void {
        Functions\when( 'wp_date' )->alias( function ( $format, $timestamp, $tz = null ) {
            $dt = new \DateTimeImmutable( '@' . $timestamp );
            if ( $tz instanceof \DateTimeZone ) {
                $dt = $dt->setTimezone( $tz );
            }
            return $dt->format( $format );
        } );
        Functions\when( 'get_option' )->alias( function ( $key, $default = '' ) {
            if ( 'date_format' === $key ) {
                return 'd/m/Y';
            }
            return $default;
        } );

        $tz = new \DateTimeZone( 'America/Sao_Paulo' );

        // S5 split (#141): build_datetime_info moved to CsvDownloadFormInfoBuilder.
        $builder = new CsvDownloadFormInfoBuilder();
        $ref     = new \ReflectionMethod( CsvDownloadFormInfoBuilder::class, 'build_datetime_info' );
        $ref->setAccessible( true );

        $config = array(
            'date_start' => '2026-05-12',
            'date_end'   => '2026-05-12',
            'time_start' => '17:00:00',
            'time_end'   => '18:00:00',
            'time_mode'  => 'daily',
        );

        $result = $ref->invoke( $builder, $config, $tz );

        $this->assertSame( '12/05/2026', $result['date_start'], 'date_start must not drift to 11/05 when site TZ is BRT' );
        $this->assertSame( '12/05/2026', $result['date_end'], 'date_end must not drift either' );
        $this->assertSame( '2026-05-12', $result['date_start_raw'] );
        $this->assertSame( '2026-05-12', $result['date_end_raw'] );
    }

    public function test_build_datetime_info_handles_blank_dates(): void {
        $tz = new \DateTimeZone( 'UTC' );

        // S5 split (#141): build_datetime_info moved to CsvDownloadFormInfoBuilder.
        $builder = new CsvDownloadFormInfoBuilder();
        $ref     = new \ReflectionMethod( CsvDownloadFormInfoBuilder::class, 'build_datetime_info' );
        $ref->setAccessible( true );

        $result = $ref->invoke( $builder, array(), $tz );

        $this->assertFalse( $result['has_dates'] );
        $this->assertNull( $result['date_start'] );
        $this->assertNull( $result['date_end'] );
        $this->assertNull( $result['date_start_raw'] );
        $this->assertNull( $result['date_end_raw'] );
    }
}

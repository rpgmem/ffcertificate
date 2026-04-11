<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\PublicCsvDownload;
use FreeFormCertificate\Security\RateLimiter;

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

        // Reset RateLimiter's static settings cache between tests.
        $rl = new \ReflectionClass( RateLimiter::class );
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
}

<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Frontend\ScheduleExceptionAction;

/**
 * Tests for ScheduleExceptionAction — Sprint 4 of #366.
 *
 * We deliberately avoid `Mockery::mock('alias:\FreeFormCertificate\
 * Security\Geofence')`: alias mocks are single-shot per PHP process
 * and would force `@runTestsInSeparateProcesses`. Instead we stub
 * `get_post_meta` + `wp_timezone` so the REAL `Geofence::get_form_*_
 * timestamp()` helpers compute meaningful values from the seeded
 * geofence config — closer to production behaviour and free of
 * cross-test pollution.
 *
 * @covers \FreeFormCertificate\Frontend\ScheduleExceptionAction
 */
class ScheduleExceptionActionTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var array<string, mixed> Reset per-test bag of post-meta values keyed by [post_id][meta_key]. */
    private array $meta_store = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // WP helpers used by the action (or its callees, incl. the
        // real Geofence::get_form_*_timestamp() helpers we exercise
        // through the action).
        Functions\when( 'wp_salt' )->justReturn( 'test-nonce-salt' );
        Functions\when( 'is_ssl' )->justReturn( false );
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
        Functions\when( 'home_url' )->alias( static fn( $p = '' ) => 'https://example.test' . $p );
        Functions\when( 'apply_filters' )->returnArg( 2 );

        // Form-URL auto-discovery (Sprint 5 of #366). Default: no page
        // embeds the form, so resolve_form_url() falls back to home_url().
        // Individual tests re-stub these to exercise the discovery path.
        Functions\when( 'get_posts' )->justReturn( array() );
        Functions\when( 'get_permalink' )->alias( static fn( $id = 0 ) => 'https://example.test/?p=' . (int) $id );
        Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );

        // Cookie path lives in ScheduleExceptionSession; capture but don't
        // assert on it — those assertions live in ScheduleExceptionSessionTest.
        Functions\when( 'setcookie' )->justReturn( true );

        // Reset per-test.
        $this->meta_store = array();
        Functions\when( 'get_post_meta' )->alias(
            function ( $post_id, $key, $single = false ) {
                return $this->meta_store[ (int) $post_id ][ $key ] ?? ( $single ? '' : array() );
            }
        );
        Functions\when( 'get_post_type' )->alias(
            fn( $post_id ) => $this->meta_store[ (int) $post_id ]['__post_type'] ?? 'ffc_form'
        );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Build a minimum-viable form: existing CPT, CSV public on, valid
     * hash, datetime ON, schedule exception ON, geofence window spans
     * yesterday → tomorrow so the real Geofence::get_form_*_timestamp()
     * helpers report the form as "open right now" regardless of when
     * the test runs.
     */
    private function seed_form(
        int $form_id = 42,
        array $overrides = array()
    ): void {
        // Yesterday / tomorrow in UTC (matches the wp_timezone stub).
        $yesterday = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
        $tomorrow  = gmdate( 'Y-m-d', time() + DAY_IN_SECONDS );

        $defaults = array(
            '__post_type'                 => 'ffc_form',
            '_ffc_csv_public_enabled'     => '1',
            '_ffc_csv_public_hash'        => 'good-hash',
            '_ffc_geofence_config'        => array(
                'datetime_enabled'           => '1',
                'schedule_exception_enabled' => '1',
                'time_start'                 => '08:00',
                'time_end'                   => '18:00',
                'class_time_start'           => '',
                'class_time_end'             => '',
                'date_start'                 => $yesterday,
                'date_end'                   => $tomorrow,
            ),
        );
        $this->meta_store[ $form_id ] = array_merge( $defaults, $overrides );
    }

    // ==================================================================
    // is_eligible() — gate cascade
    // ==================================================================

    public function test_is_eligible_rejects_unknown_form(): void {
        $this->meta_store[ 1 ] = array( '__post_type' => 'post' );

        $result = ScheduleExceptionAction::is_eligible( 1, 'whatever' );

        $this->assertFalse( $result['ok'] );
        $this->assertSame( 'unknown_form', $result['reason'] );
    }

    public function test_is_eligible_rejects_when_csv_disabled(): void {
        $this->seed_form( 42, array( '_ffc_csv_public_enabled' => '0' ) );

        $result = ScheduleExceptionAction::is_eligible( 42, 'good-hash' );

        $this->assertFalse( $result['ok'] );
        $this->assertSame( 'csv_disabled', $result['reason'] );
    }

    public function test_is_eligible_rejects_bad_hash(): void {
        $this->seed_form();

        $result = ScheduleExceptionAction::is_eligible( 42, 'wrong-hash' );

        $this->assertFalse( $result['ok'] );
        $this->assertSame( 'bad_hash', $result['reason'] );
    }

    public function test_is_eligible_rejects_when_toggle_off(): void {
        $this->seed_form();
        $this->meta_store[ 42 ]['_ffc_geofence_config']['schedule_exception_enabled'] = '0';

        $result = ScheduleExceptionAction::is_eligible( 42, 'good-hash' );

        $this->assertFalse( $result['ok'] );
        $this->assertSame( 'schedule_exception_disabled', $result['reason'] );
    }

    public function test_is_eligible_rejects_when_datetime_off(): void {
        $this->seed_form();
        $this->meta_store[ 42 ]['_ffc_geofence_config']['datetime_enabled'] = '0';

        $result = ScheduleExceptionAction::is_eligible( 42, 'good-hash' );

        $this->assertFalse( $result['ok'] );
        $this->assertSame( 'datetime_disabled', $result['reason'] );
    }

    public function test_is_eligible_passes_inside_window(): void {
        $this->seed_form();

        $result = ScheduleExceptionAction::is_eligible( 42, 'good-hash' );

        $this->assertTrue( $result['ok'], 'with a green-path seed the action must report eligible' );
    }

    // ==================================================================
    // execute() — validation envelope
    // ==================================================================

    public function test_execute_rejects_inverted_range(): void {
        $this->seed_form();

        $result = ScheduleExceptionAction::execute( 42, 'good-hash', '17:00', '09:00', '12345678900' );

        $this->assertFalse( $result['ok'] );
        $this->assertSame( 'range_inverted', $result['reason'] );
    }

    public function test_execute_rejects_out_of_window(): void {
        $this->seed_form();
        // Window is 08:00-18:00; ask for 19:00 end.
        $result = ScheduleExceptionAction::execute( 42, 'good-hash', '08:00', '19:00', '12345678900' );

        $this->assertFalse( $result['ok'] );
        $this->assertSame( 'out_of_window', $result['reason'] );
    }

    public function test_execute_allows_end_now_when_baseline_start_is_outside_window(): void {
        // Regression (#366): "End now (start stays at baseline)" keeps the
        // start empty. With a baseline schedule (00:00-23:59) wider than the
        // override window (14:30-23:00), the unchanged baseline start (00:00)
        // sits below window_start — but it must NOT be window-checked, since
        // the operator only overrode the end.
        $this->seed_form( 42, array(
            '_ffc_geofence_config' => array(
                'datetime_enabled'           => '1',
                'schedule_exception_enabled' => '1',
                'time_start'                 => '14:30',
                'time_end'                   => '23:00',
                'class_time_start'           => '00:00',
                'class_time_end'             => '23:59',
                'date_start'                 => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ),
                'date_end'                   => gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ),
            ),
        ) );

        // start_override '' = keep baseline 00:00; end_override 15:40 sits
        // inside the window. Previously this failed with out_of_window.
        $result = ScheduleExceptionAction::execute( 42, 'good-hash', '', '15:40', '12345678900' );

        $this->assertTrue( $result['ok'], 'baseline start outside window must not block an end-only override' );
        $this->assertArrayHasKey( 'token', $result );
    }

    public function test_execute_still_rejects_an_overridden_start_below_window(): void {
        // The fix must not disable window validation for values the operator
        // actually changes: an explicit start below window_start still fails.
        $this->seed_form();
        // Window 08:00-18:00; explicitly override the start to 07:00.
        $result = ScheduleExceptionAction::execute( 42, 'good-hash', '07:00', '17:00', '12345678900' );

        $this->assertFalse( $result['ok'] );
        $this->assertSame( 'out_of_window', $result['reason'] );
    }

    public function test_execute_rejects_bad_time_format(): void {
        $this->seed_form();

        $result = ScheduleExceptionAction::execute( 42, 'good-hash', 'not-a-time', '', '12345678900' );

        $this->assertFalse( $result['ok'] );
        $this->assertSame( 'bad_time_format', $result['reason'] );
    }

    public function test_execute_rejects_no_change(): void {
        $this->seed_form();
        // Both overrides empty AND baseline (class_time_*) is empty too,
        // so effective range collapses to (time_start, time_end) = baseline.
        $result = ScheduleExceptionAction::execute( 42, 'good-hash', '', '', '12345678900' );

        $this->assertFalse( $result['ok'] );
        $this->assertSame( 'no_change', $result['reason'] );
    }

    public function test_execute_returns_token_and_url_on_success(): void {
        $this->seed_form();

        $result = ScheduleExceptionAction::execute( 42, 'good-hash', '09:00', '17:00', '12345678900' );

        $this->assertTrue( $result['ok'] );
        $this->assertNotEmpty( $result['token'] );
        $this->assertNotEmpty( $result['form_url'] );
        $this->assertStringContainsString( '.', $result['token'], 'token has body.signature shape' );
    }

    public function test_execute_resolves_class_time_baseline_before_geofence(): void {
        $this->seed_form();
        // class_time_* overrides time_* as the baseline source.
        $this->meta_store[ 42 ]['_ffc_geofence_config']['class_time_start'] = '08:30';
        $this->meta_store[ 42 ]['_ffc_geofence_config']['class_time_end']   = '17:30';

        // Override end at 17:30 (matches class_time_end), start blank.
        // Effective range = (08:30, 17:30) which IS the class baseline → no_change.
        $result = ScheduleExceptionAction::execute( 42, 'good-hash', '', '17:30', '12345678900' );

        $this->assertFalse( $result['ok'] );
        $this->assertSame( 'no_change', $result['reason'], 'class_time_* must be the baseline source when present' );
    }

    public function test_execute_filter_overrides_form_url(): void {
        $this->seed_form();
        Functions\when( 'apply_filters' )->alias(
            static function ( $hook, $value, $form_id ) {
                if ( 'ffc_schedule_exception_form_url' === $hook ) {
                    return 'https://example.test/custom-form-' . $form_id;
                }
                return $value;
            }
        );

        $result = ScheduleExceptionAction::execute( 42, 'good-hash', '09:00', '17:00', '12345678900' );

        $this->assertTrue( $result['ok'] );
        $this->assertSame( 'https://example.test/custom-form-42', $result['form_url'] );
    }

    public function test_execute_resolves_form_url_from_embedding_page(): void {
        $this->seed_form();
        // No filter override (default stub returns ''), so resolution falls
        // through to the embedded-page lookup. Page 99 carries the shortcode.
        Functions\when( 'get_posts' )->justReturn( array( 99 ) );
        Functions\when( 'get_permalink' )->alias(
            static fn( $id = 0 ) => 99 === (int) $id ? 'https://example.test/the-form-page/' : ''
        );

        $result = ScheduleExceptionAction::execute( 42, 'good-hash', '09:00', '17:00', '12345678900' );

        $this->assertTrue( $result['ok'] );
        $this->assertSame( 'https://example.test/the-form-page/', $result['form_url'] );
    }

    public function test_execute_falls_back_to_home_when_form_not_embedded(): void {
        $this->seed_form();
        // Default get_posts stub returns [] — form isn't embedded anywhere.
        $result = ScheduleExceptionAction::execute( 42, 'good-hash', '09:00', '17:00', '12345678900' );

        $this->assertTrue( $result['ok'] );
        $this->assertSame( 'https://example.test/', $result['form_url'] );
    }

    public function test_resolve_form_url_is_public_and_discovers_embedding_page(): void {
        // Public entry point used by the info-screen builder to pre-resolve
        // the URL at validation time (#366 Sprint 5).
        Functions\when( 'get_posts' )->justReturn( array( 7 ) );
        Functions\when( 'get_permalink' )->alias(
            static fn( $id = 0 ) => 7 === (int) $id ? 'https://example.test/inscricao/' : ''
        );

        $this->assertSame(
            'https://example.test/inscricao/',
            ScheduleExceptionAction::resolve_form_url( 42 )
        );
    }

    public function test_find_form_page_url_returns_empty_when_form_not_embedded(): void {
        // Default stubs: filter returns '', get_posts returns []. Unlike
        // resolve_form_url(), find_form_page_url() must NOT fall back to home —
        // '' is the "no embed" signal the builder uses to hide the summary link.
        $this->assertSame( '', ScheduleExceptionAction::find_form_page_url( 42 ) );
    }

    public function test_find_form_page_url_returns_permalink_of_embedding_page(): void {
        Functions\when( 'get_posts' )->justReturn( array( 11 ) );
        Functions\when( 'get_permalink' )->alias(
            static fn( $id = 0 ) => 11 === (int) $id ? 'https://example.test/the-page/' : ''
        );

        $this->assertSame(
            'https://example.test/the-page/',
            ScheduleExceptionAction::find_form_page_url( 42 )
        );
    }

    public function test_execute_falls_back_to_home_when_permalink_empty(): void {
        $this->seed_form();
        // A page matches but get_permalink() returns false/'' (e.g. the
        // post was trashed between the query and the permalink lookup).
        Functions\when( 'get_posts' )->justReturn( array( 99 ) );
        Functions\when( 'get_permalink' )->justReturn( false );

        $result = ScheduleExceptionAction::execute( 42, 'good-hash', '09:00', '17:00', '12345678900' );

        $this->assertTrue( $result['ok'] );
        $this->assertSame( 'https://example.test/', $result['form_url'] );
    }
}

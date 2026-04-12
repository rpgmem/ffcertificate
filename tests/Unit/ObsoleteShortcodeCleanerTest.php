<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Migrations\ObsoleteShortcodeCleaner;

/**
 * Tests for the ObsoleteShortcodeCleaner service.
 *
 * Covers:
 *  - find_expired_form_ids() filters through Geofence::has_form_expired_by_days()
 *  - scan_posts_for_expired_forms() finds embedded shortcodes across post types
 *  - extract_form_ids() regex handles classic + with-extra-attrs + single/double/no quotes
 *  - strip_shortcodes_from_content() removes both classic and Gutenberg wrapper
 *    formats and keeps unrelated content intact
 *  - run(dry_run=true) never calls wp_update_post and returns count preview
 *  - run(dry_run=false) calls wp_update_post with the rewritten content
 *  - run() reports truncation when affected count exceeds REPORT_LIMIT
 *
 * @since 5.1.0
 */
class ObsoleteShortcodeCleanerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var array<int, array<string, mixed>> */
    private $updated_posts = array();

    /** @var array<int, object> */
    private $post_store = array();

    /** @var array<int, array<string, string>> */
    private $geofence_meta = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $this->updated_posts = array();
        $this->post_store    = array();
        $this->geofence_meta = array();

        $GLOBALS['ffc_test_wp_query_queue'] = array();
        $GLOBALS['ffc_test_wp_query_calls'] = array();

        // Generic WP stubs.
        Functions\when( '__' )->returnArg();
        Functions\when( 'is_wp_error' )->justReturn( false );

        // Namespaced stubs — earlier tests may have registered
        // `Functions\when('FreeFormCertificate\Migrations\...')` which Brain\Monkey
        // leaks as real PHP functions. From inside a FreeFormCertificate\Migrations
        // class, unqualified calls resolve to the namespace first, so we re-stub
        // them here to attach a fresh expectation for this test.
        Functions\when( 'FreeFormCertificate\Migrations\is_wp_error' )->justReturn( false );
        Functions\when( 'FreeFormCertificate\Migrations\wp_update_post' )->alias( function ( $postarr, $wp_error = false ) {
            $this->updated_posts[] = $postarr;
            return (int) ( $postarr['ID'] ?? 1 );
        } );
        Functions\when( 'FreeFormCertificate\Migrations\get_post' )->alias( function ( $post_id ) {
            $id = (int) $post_id;
            return $this->post_store[ $id ] ?? null;
        } );

        // Store-backed get_post.
        Functions\when( 'get_post' )->alias( function ( $post_id ) {
            $id = (int) $post_id;
            return $this->post_store[ $id ] ?? null;
        } );

        // Geofence reads _ffc_geofence_config via get_post_meta.
        Functions\when( 'get_post_meta' )->alias( function ( $post_id, $key, $single = false ) {
            if ( $key !== '_ffc_geofence_config' ) {
                return '';
            }
            $id = (int) $post_id;
            return $this->geofence_meta[ $id ] ?? '';
        } );

        // wp_timezone() used by Geofence::get_form_end_timestamp().
        Functions\when( 'wp_timezone' )->alias( function () {
            return new \DateTimeZone( 'UTC' );
        } );

        // Track wp_update_post calls so tests can assert writes.
        Functions\when( 'wp_update_post' )->alias( function ( $postarr, $wp_error = false ) {
            $this->updated_posts[] = $postarr;
            return (int) ( $postarr['ID'] ?? 1 );
        } );
    }

    protected function tearDown(): void {
        unset( $GLOBALS['ffc_test_wp_query_queue'], $GLOBALS['ffc_test_wp_query_calls'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    // ──────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Seed a form as "ended N days ago".
     *
     * @param int $form_id
     * @param int $days_ago How many days ago the form ended.
     */
    private function seed_expired_form( int $form_id, int $days_ago ): void {
        $end_date = gmdate( 'Y-m-d', time() - ( $days_ago * DAY_IN_SECONDS ) );
        $this->geofence_meta[ $form_id ] = array(
            'date_end' => $end_date,
            'time_end' => '00:00:00',
        );
    }

    /**
     * Seed a form as "ends in the future" (not expired).
     */
    private function seed_future_form( int $form_id ): void {
        $end_date = gmdate( 'Y-m-d', time() + ( 30 * DAY_IN_SECONDS ) );
        $this->geofence_meta[ $form_id ] = array(
            'date_end' => $end_date,
            'time_end' => '23:59:59',
        );
    }

    /**
     * Queue up the next WP_Query result for the code under test.
     *
     * @param array<int, int|object> $posts
     */
    private function queue_wp_query_result( array $posts ): void {
        $GLOBALS['ffc_test_wp_query_queue'][] = $posts;
    }

    private function seed_post( int $post_id, string $type, string $title, string $content ): void {
        $this->post_store[ $post_id ] = (object) array(
            'ID'           => $post_id,
            'post_type'    => $type,
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'publish',
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  extract_form_ids()
    // ──────────────────────────────────────────────────────────────

    public function test_extract_form_ids_handles_all_quote_styles(): void {
        $cleaner = new ObsoleteShortcodeCleaner();

        $content = 'Hello [ffc_form id="10"] middle [ffc_form id=\'20\'] and [ffc_form id=30] end';
        $ids     = $cleaner->extract_form_ids( $content );

        sort( $ids );
        $this->assertSame( array( 10, 20, 30 ), $ids );
    }

    public function test_extract_form_ids_ignores_other_shortcodes(): void {
        $cleaner = new ObsoleteShortcodeCleaner();

        $content = '[ffc_verification] [other_shortcode id="5"] [ffc_form id="99"]';
        $ids     = $cleaner->extract_form_ids( $content );

        $this->assertSame( array( 99 ), $ids );
    }

    public function test_extract_form_ids_handles_extra_attributes(): void {
        $cleaner = new ObsoleteShortcodeCleaner();

        $content = '[ffc_form id="42" class="foo" style="bar"] plus [ffc_form foo="bar" id="7"]';
        $ids     = $cleaner->extract_form_ids( $content );

        sort( $ids );
        $this->assertSame( array( 7, 42 ), $ids );
    }

    public function test_extract_form_ids_returns_empty_for_no_match(): void {
        $cleaner = new ObsoleteShortcodeCleaner();
        $this->assertSame( array(), $cleaner->extract_form_ids( '' ) );
        $this->assertSame( array(), $cleaner->extract_form_ids( 'nothing to see here' ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  strip_shortcodes_from_content()
    // ──────────────────────────────────────────────────────────────

    public function test_strip_removes_gutenberg_wrapper_completely(): void {
        $cleaner = new ObsoleteShortcodeCleaner();

        $content = "Intro\n<!-- wp:shortcode -->\n[ffc_form id=\"42\"]\n<!-- /wp:shortcode -->\nOutro";
        $result  = $cleaner->strip_shortcodes_from_content( $content, array( 42 ) );

        $this->assertSame( 1, $result['removed'] );
        $this->assertStringNotContainsString( 'wp:shortcode', $result['content'] );
        $this->assertStringNotContainsString( 'ffc_form', $result['content'] );
        $this->assertStringContainsString( 'Intro', $result['content'] );
        $this->assertStringContainsString( 'Outro', $result['content'] );
    }

    public function test_strip_removes_classic_shortcode(): void {
        $cleaner = new ObsoleteShortcodeCleaner();

        $content = 'Before [ffc_form id="7"] after';
        $result  = $cleaner->strip_shortcodes_from_content( $content, array( 7 ) );

        $this->assertSame( 1, $result['removed'] );
        $this->assertStringNotContainsString( 'ffc_form', $result['content'] );
        $this->assertStringContainsString( 'Before', $result['content'] );
        $this->assertStringContainsString( 'after', $result['content'] );
    }

    public function test_strip_preserves_non_matching_shortcodes(): void {
        $cleaner = new ObsoleteShortcodeCleaner();

        $content = '[ffc_form id="7"] and [ffc_form id="8"]';
        $result  = $cleaner->strip_shortcodes_from_content( $content, array( 7 ) );

        $this->assertSame( 1, $result['removed'] );
        $this->assertStringContainsString( '[ffc_form id="8"]', $result['content'] );
        $this->assertStringNotContainsString( '[ffc_form id="7"]', $result['content'] );
    }

    public function test_strip_handles_mixed_classic_and_gutenberg(): void {
        $cleaner = new ObsoleteShortcodeCleaner();

        $content = "Head\n<!-- wp:shortcode -->[ffc_form id=\"10\"]<!-- /wp:shortcode -->\nMiddle [ffc_form id=\"11\"] tail";
        $result  = $cleaner->strip_shortcodes_from_content( $content, array( 10, 11 ) );

        $this->assertSame( 2, $result['removed'] );
        $this->assertStringNotContainsString( 'ffc_form', $result['content'] );
        $this->assertStringNotContainsString( 'wp:shortcode', $result['content'] );
    }

    public function test_strip_returns_zero_when_list_empty(): void {
        $cleaner = new ObsoleteShortcodeCleaner();

        $content = '[ffc_form id="7"]';
        $result  = $cleaner->strip_shortcodes_from_content( $content, array() );

        $this->assertSame( 0, $result['removed'] );
        $this->assertSame( $content, $result['content'] );
    }

    public function test_strip_returns_zero_when_content_empty(): void {
        $cleaner = new ObsoleteShortcodeCleaner();

        $result = $cleaner->strip_shortcodes_from_content( '', array( 1, 2 ) );

        $this->assertSame( 0, $result['removed'] );
        $this->assertSame( '', $result['content'] );
    }

    // ──────────────────────────────────────────────────────────────
    //  find_expired_form_ids()
    // ──────────────────────────────────────────────────────────────

    public function test_find_expired_form_ids_filters_by_days(): void {
        $this->seed_expired_form( 1, 120 ); // Eligible for 90-day window.
        $this->seed_expired_form( 2, 30 );  // Too recent for 90-day window.
        $this->seed_future_form( 3 );       // Not expired at all.
        $this->queue_wp_query_result( array( 1, 2, 3 ) );

        $cleaner = new ObsoleteShortcodeCleaner();
        $ids     = $cleaner->find_expired_form_ids( 90 );

        $this->assertSame( array( 1 ), $ids );
    }

    public function test_find_expired_form_ids_returns_empty_when_no_forms_exist(): void {
        $this->queue_wp_query_result( array() );

        $cleaner = new ObsoleteShortcodeCleaner();
        $this->assertSame( array(), $cleaner->find_expired_form_ids( 90 ) );
    }

    // ──────────────────────────────────────────────────────────────
    //  scan_posts_for_expired_forms()
    // ──────────────────────────────────────────────────────────────

    public function test_scan_posts_lists_only_posts_with_expired_shortcodes(): void {
        $this->seed_post( 101, 'post', 'With expired', 'Intro [ffc_form id="1"] outro' );
        $this->seed_post( 102, 'page', 'With current', '[ffc_form id="3"]' );
        $this->seed_post( 103, 'page', 'With both', '[ffc_form id="1"] and [ffc_form id="3"]' );
        $this->queue_wp_query_result( array( 101, 102, 103 ) );

        $cleaner = new ObsoleteShortcodeCleaner();
        $report  = $cleaner->scan_posts_for_expired_forms( array( 1 ) );

        $this->assertSame( 3, $report['posts_scanned'] );
        $this->assertCount( 2, $report['affected'] );

        $ids_found = array_map( static fn( $r ) => $r['post_id'], $report['affected'] );
        sort( $ids_found );
        $this->assertSame( array( 101, 103 ), $ids_found );
    }

    public function test_scan_posts_returns_empty_when_no_expired_ids(): void {
        $cleaner = new ObsoleteShortcodeCleaner();
        $report  = $cleaner->scan_posts_for_expired_forms( array() );

        $this->assertSame( 0, $report['posts_scanned'] );
        $this->assertSame( array(), $report['affected'] );
        $this->assertSame( array(), $GLOBALS['ffc_test_wp_query_calls'] );
    }

    // ──────────────────────────────────────────────────────────────
    //  run() — full pipeline
    // ──────────────────────────────────────────────────────────────

    public function test_run_dry_run_does_not_call_wp_update_post(): void {
        $this->seed_expired_form( 1, 120 );
        $this->seed_post( 200, 'post', 'Foo', '[ffc_form id="1"]' );

        // Two queries: find_expired_form_ids → scan.
        $this->queue_wp_query_result( array( 1 ) );
        $this->queue_wp_query_result( array( 200 ) );

        $cleaner = new ObsoleteShortcodeCleaner();
        $report  = $cleaner->run( 90, array( 'dry_run' => true ) );

        $this->assertTrue( $report['dry_run'] );
        $this->assertSame( 1, $report['expired_forms'] );
        $this->assertSame( 1, $report['posts_scanned'] );
        $this->assertSame( 1, $report['posts_affected'] );
        $this->assertSame( 1, $report['shortcodes_removed'] );
        $this->assertSame( array(), $this->updated_posts );
    }

    public function test_run_apply_calls_wp_update_post_with_cleaned_content(): void {
        $this->seed_expired_form( 1, 200 );
        $this->seed_post( 300, 'post', 'Foo', "Before [ffc_form id=\"1\"] after" );

        $this->queue_wp_query_result( array( 1 ) );
        $this->queue_wp_query_result( array( 300 ) );

        $cleaner = new ObsoleteShortcodeCleaner();
        $report  = $cleaner->run( 90, array( 'dry_run' => false ) );

        $this->assertFalse( $report['dry_run'] );
        $this->assertSame( 1, $report['shortcodes_removed'] );
        $this->assertCount( 1, $this->updated_posts );

        $update = $this->updated_posts[0];
        $this->assertSame( 300, (int) $update['ID'] );
        $this->assertStringNotContainsString( 'ffc_form', (string) $update['post_content'] );
        $this->assertStringContainsString( 'Before', (string) $update['post_content'] );
        $this->assertStringContainsString( 'after', (string) $update['post_content'] );
    }

    public function test_run_apply_ignores_posts_that_become_noop_after_update(): void {
        $this->seed_expired_form( 1, 200 );
        // Post has no shortcode that matches — scanner will skip it.
        $this->seed_post( 400, 'post', 'Clean', 'nothing to remove here' );

        $this->queue_wp_query_result( array( 1 ) );
        $this->queue_wp_query_result( array( 400 ) );

        $cleaner = new ObsoleteShortcodeCleaner();
        $report  = $cleaner->run( 90, array( 'dry_run' => false ) );

        $this->assertSame( 0, $report['posts_affected'] );
        $this->assertSame( 0, $report['shortcodes_removed'] );
        $this->assertSame( array(), $this->updated_posts );
    }

    public function test_run_reports_truncation_when_over_report_limit(): void {
        $this->seed_expired_form( 1, 400 );

        $post_ids = array();
        for ( $i = 1; $i <= ObsoleteShortcodeCleaner::REPORT_LIMIT + 5; $i++ ) {
            $pid = 1000 + $i;
            $this->seed_post( $pid, 'post', "Post {$i}", '[ffc_form id="1"]' );
            $post_ids[] = $pid;
        }

        $this->queue_wp_query_result( array( 1 ) );
        $this->queue_wp_query_result( $post_ids );

        $cleaner = new ObsoleteShortcodeCleaner();
        $report  = $cleaner->run( 90, array( 'dry_run' => true ) );

        $this->assertSame( ObsoleteShortcodeCleaner::REPORT_LIMIT + 5, $report['posts_affected'] );
        $this->assertTrue( $report['truncated'] );
        $this->assertCount( ObsoleteShortcodeCleaner::REPORT_LIMIT, $report['affected'] );
    }

    public function test_run_returns_zero_report_when_no_forms_expired(): void {
        $this->seed_future_form( 1 );
        $this->queue_wp_query_result( array( 1 ) );

        $cleaner = new ObsoleteShortcodeCleaner();
        $report  = $cleaner->run( 90 );

        $this->assertSame( 0, $report['expired_forms'] );
        $this->assertSame( 0, $report['posts_scanned'] );
        $this->assertSame( 0, $report['posts_affected'] );
        $this->assertSame( 0, $report['shortcodes_removed'] );
        $this->assertFalse( $report['truncated'] );
    }
}

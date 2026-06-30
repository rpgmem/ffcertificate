<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\FormListColumns;

/**
 * Process-isolated tests for FormListColumns paths that need alias mocks:
 * init() hook registration, the conditional enqueue, and the batch
 * submission-count loader (alias SubmissionRepository + $wpdb).
 *
 * @covers \FreeFormCertificate\Admin\FormListColumns
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class FormListColumnsHooksTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        class_exists( '\\FreeFormCertificate\\Admin\\FormListColumns' );

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();

        // Reset the static cache.
        $ref  = new \ReflectionClass( FormListColumns::class );
        $prop = $ref->getProperty( 'submission_counts_cache' );
        $prop->setAccessible( true );
        $prop->setValue( null, null );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // init()
    // ------------------------------------------------------------------

    public function test_init_registers_all_hooks(): void {
        Filters\expectAdded( 'manage_ffc_form_posts_columns' )->once();
        Actions\expectAdded( 'manage_ffc_form_posts_custom_column' )->once();
        Filters\expectAdded( 'manage_edit-ffc_form_sortable_columns' )->once();
        Actions\expectAdded( 'pre_get_posts' )->once();
        Actions\expectAdded( 'admin_enqueue_scripts' )->once();

        FormListColumns::init();
    }

    // ------------------------------------------------------------------
    // enqueue_features_script()
    // ------------------------------------------------------------------

    public function test_enqueue_features_script_returns_early_off_edit_php(): void {
        $enqueued = array();
        Functions\when( 'wp_enqueue_script' )->alias( function ( $h ) use ( &$enqueued ) {
            $enqueued[] = $h;
        } );

        FormListColumns::enqueue_features_script( 'post.php' );

        $this->assertSame( array(), $enqueued );
    }

    public function test_enqueue_features_script_returns_early_for_other_post_type(): void {
        $enqueued = array();
        Functions\when( 'wp_enqueue_script' )->alias( function ( $h ) use ( &$enqueued ) {
            $enqueued[] = $h;
        } );
        Functions\when( 'get_current_screen' )->justReturn(
            (object) array( 'post_type' => 'post' )
        );

        FormListColumns::enqueue_features_script( 'edit.php' );

        $this->assertSame( array(), $enqueued );
    }

    public function test_enqueue_features_script_returns_early_with_no_screen(): void {
        $enqueued = array();
        Functions\when( 'wp_enqueue_script' )->alias( function ( $h ) use ( &$enqueued ) {
            $enqueued[] = $h;
        } );
        Functions\when( 'get_current_screen' )->justReturn( null );

        FormListColumns::enqueue_features_script( 'edit.php' );

        $this->assertSame( array(), $enqueued );
    }

    public function test_enqueue_features_script_loads_on_ffc_form_list_screen(): void {
        $enqueued = array();
        Functions\when( 'get_current_screen' )->justReturn(
            (object) array( 'post_type' => 'ffc_form' )
        );
        Functions\when( 'wp_enqueue_script' )->alias( function ( $h ) use ( &$enqueued ) {
            $enqueued[] = $h;
        } );
        Functions\when( 'wp_localize_script' )->justReturn( true );
        Functions\when( 'wp_create_nonce' )->justReturn( 'nonce123' );

        $helper = Mockery::mock( 'alias:\FreeFormCertificate\Core\AssetHelper' );
        $helper->shouldReceive( 'asset_suffix' )->andReturn( '.min' );

        FormListColumns::enqueue_features_script( 'edit.php' );

        $this->assertContains( 'ffc-core', $enqueued );
        $this->assertContains( 'ffc-form-list-features', $enqueued );
        $this->assertContains( 'ffc-form-list-copy-shortcode', $enqueued );
    }

    // ------------------------------------------------------------------
    // get_submission_count() => load_submission_counts() (DB batch path)
    // ------------------------------------------------------------------

    public function test_submission_count_batch_loads_from_db(): void {
        Functions\when( 'number_format_i18n' )->alias( function ( $n ) {
            return (string) $n;
        } );
        Functions\when( 'admin_url' )->alias( function ( $p ) {
            return 'https://example.test/wp-admin/' . $p;
        } );

        $repo = Mockery::mock( 'alias:\FreeFormCertificate\Repositories\SubmissionRepository' );
        $repo->shouldReceive( 'get_submissions_table' )->andReturn( 'wp_ffc_submissions' );

        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () {
            return func_get_args()[0];
        } );
        $wpdb->shouldReceive( 'get_results' )->once()->andReturn(
            array(
                array( 'form_id' => 42, 'cnt' => 3 ),
                array( 'form_id' => 7, 'cnt' => 11 ),
            )
        );

        // First render triggers the single batch query.
        ob_start();
        FormListColumns::render_column( 'ffc_submissions', 42 );
        $out42 = ob_get_clean();

        // Second render hits the cache (get_results already used ->once()).
        ob_start();
        FormListColumns::render_column( 'ffc_submissions', 7 );
        $out7 = ob_get_clean();

        $this->assertStringContainsString( '<strong>3</strong>', $out42 );
        $this->assertStringContainsString( '<strong>11</strong>', $out7 );
    }

    public function test_submission_count_handles_empty_db_result(): void {
        Functions\when( 'number_format_i18n' )->alias( function ( $n ) {
            return (string) $n;
        } );

        $repo = Mockery::mock( 'alias:\FreeFormCertificate\Repositories\SubmissionRepository' );
        $repo->shouldReceive( 'get_submissions_table' )->andReturn( 'wp_ffc_submissions' );

        global $wpdb;
        $wpdb = Mockery::mock( 'wpdb' );
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing( function () {
            return func_get_args()[0];
        } );
        $wpdb->shouldReceive( 'get_results' )->once()->andReturn( null );

        ob_start();
        FormListColumns::render_column( 'ffc_submissions', 42 );
        $out = ob_get_clean();

        // No rows => count 0 => empty marker.
        $this->assertStringContainsString( '&mdash;', $out );
    }
}

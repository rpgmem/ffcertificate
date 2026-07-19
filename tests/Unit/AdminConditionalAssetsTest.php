<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\AdminConditionalAssets;

/**
 * Tests for AdminConditionalAssets: per-screen conditional admin asset loading.
 *
 * Drives enqueue_conditional_assets() through each screen-detection branch by
 * setting the relevant $_GET routing params, and asserts the right wp_enqueue_*
 * handles register (captured via global function stubs).
 *
 * @covers \FreeFormCertificate\Admin\AdminConditionalAssets
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AdminConditionalAssetsTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var array<int, string> */
    private $styles = array();

    /** @var array<int, string> */
    private $scripts = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // pcov attribution: preload the class so its lines attribute here.
        class_exists( '\\FreeFormCertificate\\Admin\\AdminConditionalAssets' );

        $this->styles  = array();
        $this->scripts = array();

        // Common i18n / escaping / URL stubs.
        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_url_raw' )->returnArg();
        Functions\when( 'admin_url' )->alias( static function ( $p = '' ) {
            return 'https://example.com/wp-admin/' . $p;
        } );
        Functions\when( 'rest_url' )->alias( static function ( $p = '' ) {
            return 'https://example.com/wp-json/' . $p;
        } );
        Functions\when( 'wp_create_nonce' )->justReturn( 'nonce123' );
        Functions\when( 'sanitize_key' )->alias( static function ( $key ) {
            return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
        } );
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'absint' )->alias( static function ( $v ) {
            return abs( (int) $v );
        } );

        // Capture enqueues.
        Functions\when( 'wp_enqueue_style' )->alias( function ( $handle ) {
            $this->styles[] = $handle;
        } );
        Functions\when( 'wp_enqueue_script' )->alias( function ( $handle ) {
            $this->scripts[] = $handle;
        } );
        Functions\when( 'wp_localize_script' )->justReturn( true );

        // AssetHelper::asset_suffix() is called by every branch.
        $helper = Mockery::mock( 'alias:\FreeFormCertificate\Core\AssetHelper' );
        $helper->shouldReceive( 'asset_suffix' )->andReturn( '.min' )->byDefault();
    }

    protected function tearDown(): void {
        unset(
            $_GET['page'],
            $_GET['tab'],
            $_GET['action'],
            $_GET['filter_form_id']
        );
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // No condition matches — nothing is enqueued.
    // ==================================================================

    public function test_no_condition_matches_enqueues_nothing(): void {
        // No $_GET routing params at all.
        $assets = new AdminConditionalAssets();
        $assets->enqueue_conditional_assets();

        $this->assertSame( array(), $this->styles );
        $this->assertSame( array(), $this->scripts );
    }

    public function test_unrelated_page_enqueues_nothing(): void {
        $_GET['page'] = 'some-other-page';

        $assets = new AdminConditionalAssets();
        $assets->enqueue_conditional_assets();

        $this->assertSame( array(), $this->styles );
        $this->assertSame( array(), $this->scripts );
    }

    // ==================================================================
    // Settings page branch.
    // ==================================================================

    public function test_settings_page_enqueues_settings_assets(): void {
        $_GET['page'] = 'ffc-settings';

        $assets = new AdminConditionalAssets();
        $assets->enqueue_conditional_assets();

        $this->assertContains( 'ffc-admin-settings', $this->styles );
        $this->assertContains( 'ffc-admin-migrations', $this->scripts );
    }

    // ==================================================================
    // Submission edit page branch.
    // ==================================================================

    public function test_submission_edit_page_enqueues_edit_assets(): void {
        $_GET['page']   = 'ffc-submissions';
        $_GET['action'] = 'edit';

        $assets = new AdminConditionalAssets();
        $assets->enqueue_conditional_assets();

        $this->assertContains( 'ffc-admin-submission-edit', $this->styles );
        $this->assertContains( 'ffc-admin-submission-edit', $this->scripts );
    }

    // ==================================================================
    // Submissions list page branch (bulk + optional move modal).
    // ==================================================================

    public function test_submissions_list_without_filter_enqueues_bulk_only(): void {
        $_GET['page'] = 'ffc-submissions';
        // No filter_form_id => move-submissions assets short-circuit.

        $assets = new AdminConditionalAssets();
        $assets->enqueue_conditional_assets();

        // Bulk assets always load on the list page.
        $this->assertContains( 'ffc-admin-submissions-bulk', $this->scripts );
        // Move-submissions modal does NOT load without a single filter form.
        $this->assertNotContains( 'ffc-admin-move-submissions', $this->scripts );
        $this->assertNotContains( 'ffc-admin-move-submissions', $this->styles );
    }

    public function test_submissions_list_with_single_filter_enqueues_move_modal(): void {
        $_GET['page']           = 'ffc-submissions';
        $_GET['filter_form_id'] = '17';

        // The move-submissions branch loads available forms for the <select>.
        Functions\when( 'get_posts' )->justReturn(
            array(
                (object) array( 'ID' => 5, 'post_title' => 'Form A' ),
                (object) array( 'ID' => 9, 'post_title' => 'Form B' ),
            )
        );

        $assets = new AdminConditionalAssets();
        $assets->enqueue_conditional_assets();

        $this->assertContains( 'ffc-admin-move-submissions', $this->styles );
        $this->assertContains( 'ffc-admin-move-submissions', $this->scripts );
        $this->assertContains( 'ffc-admin-submissions-bulk', $this->scripts );
    }

    public function test_submissions_list_with_array_filter_single_value_enqueues_move_modal(): void {
        $_GET['page']           = 'ffc-submissions';
        $_GET['filter_form_id'] = array( '23' );

        Functions\when( 'get_posts' )->justReturn( array() );

        $assets = new AdminConditionalAssets();
        $assets->enqueue_conditional_assets();

        // A single-element array resolves to a valid source form id.
        $this->assertContains( 'ffc-admin-move-submissions', $this->scripts );
    }

    public function test_submissions_list_with_array_filter_multiple_values_skips_move_modal(): void {
        $_GET['page']           = 'ffc-submissions';
        $_GET['filter_form_id'] = array( '23', '24' );

        $assets = new AdminConditionalAssets();
        $assets->enqueue_conditional_assets();

        // Multiple filter values => ambiguous source => modal skipped.
        $this->assertNotContains( 'ffc-admin-move-submissions', $this->scripts );
        // But bulk assets still load.
        $this->assertContains( 'ffc-admin-submissions-bulk', $this->scripts );
    }

    public function test_submission_edit_subpage_is_not_treated_as_list_page(): void {
        $_GET['page']   = 'ffc-submissions';
        $_GET['action'] = 'edit';

        $assets = new AdminConditionalAssets();
        $assets->enqueue_conditional_assets();

        // The edit subpage must NOT trigger the list-page bulk assets.
        $this->assertNotContains( 'ffc-admin-submissions-bulk', $this->scripts );
    }

    // ==================================================================
    // Certificates Dashboard branch.
    // ==================================================================

    public function test_certificates_dashboard_page_enqueues_calendar_assets(): void {
        $_GET['page'] = \FreeFormCertificate\Admin\CertificatesDashboard::MENU_SLUG;

        $assets = new AdminConditionalAssets();
        $assets->enqueue_conditional_assets();

        $this->assertContains( 'ffc-certificates-dashboard', $this->styles );
        $this->assertContains( 'ffc-calendar-core', $this->scripts );
        $this->assertContains( 'ffc-certificates-dashboard', $this->scripts );
    }

    // ==================================================================
    // Documentation tab branch.
    // ==================================================================

    public function test_documentation_tab_enqueues_search_script(): void {
        $_GET['page'] = 'ffc-settings';
        $_GET['tab']  = 'documentation';

        $assets = new AdminConditionalAssets();
        $assets->enqueue_conditional_assets();

        // Settings page assets load (page=ffc-settings) AND the doc search filter.
        $this->assertContains( 'ffc-doc-search', $this->scripts );
    }

    public function test_settings_page_non_documentation_tab_skips_search(): void {
        $_GET['page'] = 'ffc-settings';
        $_GET['tab']  = 'general';

        $assets = new AdminConditionalAssets();
        $assets->enqueue_conditional_assets();

        $this->assertNotContains( 'ffc-doc-search', $this->scripts );
    }
}

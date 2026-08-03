<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentAdminPage;

/**
 * Dispatch-level tests for {@see RecruitmentAdminPage} — the `render_page()`
 * router, `register_menu()` wiring and the four 3-state cap gate helpers that
 * the helper-focused sibling test (`RecruitmentAdminPageTest`) declared out of
 * scope. Collaborators (Capabilities, the edit pages, the action dispatcher,
 * the tab renderer) are alias-mocked; capability outcomes are driven per-test
 * by a granted-caps allowlist so each 3-state branch is reachable.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentAdminPage
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecruitmentAdminPageDispatchTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var array<int,string> Capabilities the mocked Capabilities gate grants. */
    private $granted = array();

    /** @var array<string,int> Render calls recorded from the alias-mocked renderer. */
    private $renderCalls = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'wp_admin_notice' )->alias(
            static function ( $message, $args = array() ) {
                $ffc_type = isset( $args['type'] ) ? $args['type'] : 'info';
                $ffc_cls  = 'notice notice-' . $ffc_type;
                if ( ! empty( $args['dismissible'] ) ) { $ffc_cls .= ' is-dismissible'; }
                if ( ! empty( $args['additional_classes'] ) ) { $ffc_cls .= ' ' . implode( ' ', $args['additional_classes'] ); }
                $ffc_wrap = ! array_key_exists( 'paragraph_wrap', $args ) || $args['paragraph_wrap'];
                echo '<div class="' . $ffc_cls . '">' . ( $ffc_wrap ? '<p>' . $message . '</p>' : $message ) . '</div>';
            }
        );

        class_exists( '\FreeFormCertificate\Recruitment\RecruitmentAdminPage' );

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'sanitize_key' )->alias(
            static fn( $key ) => strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) )
        );
        // wp_die halts in production; make it observable as an exception.
        Functions\when( 'wp_die' )->alias(
            static function ( $msg = '' ) {
                throw new \RuntimeException( 'wp_die:' . (string) $msg );
            }
        );

        $caps = Mockery::mock( 'alias:FreeFormCertificate\Core\Capabilities' );
        $caps->shouldReceive( 'current_user_can_admin_or' )->andReturnUsing(
            fn( $cap ) => in_array( $cap, $this->granted, true )
        );

        // The edit pages + action dispatcher are fire-and-observe voids.
        foreach (
            array(
                'RecruitmentNoticeEditPage',
                'RecruitmentCandidateEditPage',
                'RecruitmentAdjutancyEditPage',
                'RecruitmentReasonEditPage',
            ) as $cls
        ) {
            $m = Mockery::mock( 'alias:FreeFormCertificate\\Recruitment\\' . $cls );
            $m->shouldReceive( 'render' )->andReturnNull()->byDefault();
        }

        $actions = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentAdminActions' );
        $actions->shouldReceive( 'dispatch' )->andReturnNull()->byDefault();

        // Tab renderer: record which tab partial ran so the router branch is
        // observable without depending on real markup.
        $renderer = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentAdminPageRenderer' );
        foreach (
            array(
                'render_flash_notice',
                'render_tabs',
                'render_notices_tab',
                'render_adjutancies_tab',
                'render_reasons_tab',
                'render_candidates_tab',
                'render_settings_tab',
            ) as $method
        ) {
            $renderer->shouldReceive( $method )->andReturnUsing(
                function () use ( $method ) {
                    $this->renderCalls[ $method ] = ( $this->renderCalls[ $method ] ?? 0 ) + 1;
                    return null;
                }
            )->byDefault();
        }
    }

    protected function tearDown(): void {
        $_GET = array();
        Monkey\tearDown();
        parent::tearDown();
    }

    /** Capture render_page() output. */
    private function render(): string {
        ob_start();
        try {
            RecruitmentAdminPage::render_page();
        } finally {
            $out = (string) ob_get_clean();
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // render_page() — access gate
    // ------------------------------------------------------------------

    public function test_render_page_denies_without_view_cap(): void {
        $this->granted = array(); // no caps
        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'wp_die:' );
        RecruitmentAdminPage::render_page();
    }

    // ------------------------------------------------------------------
    // render_page() — edit-screen hijack
    // ------------------------------------------------------------------

    public function test_render_page_edit_notice_requires_manage_cap(): void {
        $this->granted = array( 'ffc_view_recruitment' ); // view only, not manage
        $_GET          = array( 'action' => 'edit-notice' );

        $this->expectException( \RuntimeException::class );
        $this->render();
    }

    public function test_render_page_edit_notice_renders_edit_screen(): void {
        $this->granted = array( 'ffc_view_recruitment', 'ffc_manage_recruitment' );
        $_GET          = array(
            'action'  => 'edit-notice',
            'ffc_msg' => 'saved',
        );

        $out = $this->render();

        $this->assertStringContainsString( 'ffc-recruitment-admin', $out );
        $this->assertArrayHasKey( 'render_flash_notice', $this->renderCalls );
    }

    public function test_render_page_edit_candidate_and_adjutancy_and_reason_screens(): void {
        $this->granted = array( 'ffc_view_recruitment', 'ffc_manage_recruitment' );

        foreach ( array( 'edit-candidate', 'edit-adjutancy', 'edit-reason' ) as $action ) {
            $_GET = array( 'action' => $action );
            $out  = $this->render();
            $this->assertStringContainsString( 'wrap ffc-recruitment-admin', $out );
        }
    }

    // ------------------------------------------------------------------
    // render_page() — action dispatch + tab routing
    // ------------------------------------------------------------------

    public function test_render_page_dispatches_non_edit_action_then_renders_tab(): void {
        $this->granted = array( 'ffc_view_recruitment' );
        $_GET          = array( 'action' => 'delete-notice' );

        $out = $this->render();

        $this->assertArrayHasKey( 'render_notices_tab', $this->renderCalls );
        $this->assertStringContainsString( 'ffc-recruitment-admin', $out );
    }

    public function test_render_page_defaults_to_notices_tab(): void {
        $this->granted = array( 'ffc_view_recruitment' );
        $_GET          = array();

        $this->render();

        $this->assertArrayHasKey( 'render_notices_tab', $this->renderCalls );
    }

    public function test_render_page_renders_adjutancies_and_candidates_tabs(): void {
        $this->granted = array( 'ffc_view_recruitment' );

        $_GET = array( 'tab' => 'adjutancies' );
        $this->render();
        $this->assertArrayHasKey( 'render_adjutancies_tab', $this->renderCalls );

        $_GET = array( 'tab' => 'candidates' );
        $this->render();
        $this->assertArrayHasKey( 'render_candidates_tab', $this->renderCalls );
    }

    public function test_render_page_settings_tab_needs_settings_view_cap(): void {
        // Holds the page view cap but NOT the settings view cap → the router
        // downgrades the settings tab back to notices.
        $this->granted = array( 'ffc_view_recruitment' );
        $_GET          = array( 'tab' => 'settings' );

        $this->render();

        $this->assertArrayHasKey( 'render_notices_tab', $this->renderCalls );
        $this->assertArrayNotHasKey( 'render_settings_tab', $this->renderCalls );
    }

    public function test_render_page_settings_tab_renders_with_settings_view_cap(): void {
        $this->granted = array( 'ffc_view_recruitment', 'ffc_view_recruitment_settings' );
        $_GET          = array( 'tab' => 'settings' );

        $this->render();

        $this->assertArrayHasKey( 'render_settings_tab', $this->renderCalls );
    }

    public function test_render_page_reasons_tab_needs_reasons_cap(): void {
        $this->granted = array( 'ffc_view_recruitment' ); // no reasons cap
        $_GET          = array( 'tab' => 'reasons' );

        $this->render();

        $this->assertArrayHasKey( 'render_notices_tab', $this->renderCalls );
        $this->assertArrayNotHasKey( 'render_reasons_tab', $this->renderCalls );
    }

    public function test_render_page_reasons_tab_renders_with_reasons_view_cap(): void {
        $this->granted = array( 'ffc_view_recruitment', 'ffc_view_recruitment_reasons' );
        $_GET          = array( 'tab' => 'reasons' );

        $this->render();

        $this->assertArrayHasKey( 'render_reasons_tab', $this->renderCalls );
    }

    public function test_render_page_unknown_tab_falls_back_to_notices(): void {
        $this->granted = array( 'ffc_view_recruitment' );
        $_GET          = array( 'tab' => 'bogus' );

        $this->render();

        $this->assertArrayHasKey( 'render_notices_tab', $this->renderCalls );
    }

    // ------------------------------------------------------------------
    // 3-state cap helpers
    // ------------------------------------------------------------------

    public function test_can_view_settings_true_for_view_or_manage(): void {
        $this->granted = array( 'ffc_view_recruitment_settings' );
        $this->assertTrue( RecruitmentAdminPage::can_view_settings() );

        $this->granted = array( 'ffc_manage_recruitment_settings' );
        $this->assertTrue( RecruitmentAdminPage::can_view_settings() );

        $this->granted = array();
        $this->assertFalse( RecruitmentAdminPage::can_view_settings() );
    }

    public function test_can_edit_settings_needs_manage_cap(): void {
        $this->granted = array( 'ffc_manage_recruitment_settings' );
        $this->assertTrue( RecruitmentAdminPage::can_edit_settings() );

        $this->granted = array( 'ffc_view_recruitment_settings' );
        $this->assertFalse( RecruitmentAdminPage::can_edit_settings() );
    }

    public function test_can_view_reasons_true_for_view_or_manage(): void {
        $this->granted = array( 'ffc_view_recruitment_reasons' );
        $this->assertTrue( RecruitmentAdminPage::can_view_reasons() );

        $this->granted = array( 'ffc_manage_recruitment_reasons' );
        $this->assertTrue( RecruitmentAdminPage::can_view_reasons() );

        $this->granted = array();
        $this->assertFalse( RecruitmentAdminPage::can_view_reasons() );
    }

    public function test_can_edit_reasons_needs_manage_cap(): void {
        $this->granted = array( 'ffc_manage_recruitment_reasons' );
        $this->assertTrue( RecruitmentAdminPage::can_edit_reasons() );

        $this->granted = array( 'ffc_view_recruitment_reasons' );
        $this->assertFalse( RecruitmentAdminPage::can_edit_reasons() );
    }

    // ------------------------------------------------------------------
    // register_menu()
    // ------------------------------------------------------------------

    public function test_register_menu_registers_top_level_and_per_tab_submenus(): void {
        $menuCalls    = 0;
        $submenuCalls = 0;
        $filterAdded  = false;

        Functions\when( 'add_menu_page' )->alias(
            function () use ( &$menuCalls ) {
                ++$menuCalls;
            }
        );
        Functions\when( 'add_submenu_page' )->alias(
            function () use ( &$submenuCalls ) {
                ++$submenuCalls;
            }
        );
        Functions\when( 'add_filter' )->alias(
            function () use ( &$filterAdded ) {
                $filterAdded = true;
            }
        );

        global $submenu;
        $submenu = array( RecruitmentAdminPage::PAGE_SLUG => array( array( 'auto-generated' ) ) );

        RecruitmentAdminPage::register_menu();

        $this->assertSame( 1, $menuCalls, 'one top-level menu' );
        $this->assertSame( 5, $submenuCalls, 'five per-tab submenus' );
        $this->assertTrue( $filterAdded, 'submenu_file highlight filter wired' );
        // The auto-generated duplicate first row was cleared.
        $this->assertSame( array(), $submenu[ RecruitmentAdminPage::PAGE_SLUG ] );
    }
}

<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentAdminPage;

/**
 * Tests for RecruitmentAdminPage — the label/badge static helpers used
 * across the admin surface (notice + classification status maps). The
 * register_menu() / render_page() dispatchers are out of scope for the
 * smoke tier — they tie into the full WP-admin runtime + tab routing.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentAdminPage
 * @covers \FreeFormCertificate\Recruitment\RecruitmentAdminPageRenderer
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecruitmentAdminPageTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var \Mockery\MockInterface */
    private $settingsMock;

    /** @var \Mockery\MockInterface */
    private $badgeMock;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // pcov does not attribute coverage to a class first autoloaded
        // mid-test-method; preload the renderer here so its rendering
        // helpers (exercised via the controller static entry points)
        // register against the line-coverage floor.
        class_exists( '\FreeFormCertificate\Recruitment\RecruitmentAdminPageRenderer' );

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'sanitize_key' )->alias(
            static fn( $key ) => strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) )
        );

        $this->settingsMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentSettings' );
        $this->settingsMock->shouldReceive( 'all' )->andReturn(
            array(
                'status_color_empty'              => '#fff',
                'status_color_called'             => '#aaa',
                'status_color_hired'              => '#0f0',
                'status_color_not_shown'          => '#f00',
                'status_color_withdrew'           => '#f0a',
                'notice_status_color_draft'       => '#ccc',
                'notice_status_color_preliminary' => '#ff0',
                'notice_status_color_definitive'  => '#0f0',
                'notice_status_color_closed'      => '#999',
            )
        )->byDefault();

        $this->badgeMock = Mockery::mock( 'alias:FreeFormCertificate\Core\BadgeHtml' );
        $this->badgeMock->shouldReceive( 'render' )->andReturnUsing(
            fn( $base, $cls, $color, $label ) => "[BADGE:$cls:$color:$label]"
        );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_notice_status_label_maps_each_known_status(): void {
        $this->assertSame( 'Draft', RecruitmentAdminPage::notice_status_label( 'draft' ) );
        $this->assertSame( 'Preliminary', RecruitmentAdminPage::notice_status_label( 'preliminary' ) );
        $this->assertSame( 'Definitive', RecruitmentAdminPage::notice_status_label( 'definitive' ) );
        $this->assertSame( 'Closed', RecruitmentAdminPage::notice_status_label( 'closed' ) );
    }

    public function test_notice_status_label_falls_back_to_raw_value_for_unknown_status(): void {
        $this->assertSame( 'bogus-status', RecruitmentAdminPage::notice_status_label( 'bogus-status' ) );
    }

    public function test_classification_status_label_maps_each_known_status(): void {
        $this->assertSame( 'Waiting', RecruitmentAdminPage::classification_status_label( 'empty' ) );
        $this->assertSame( 'Called', RecruitmentAdminPage::classification_status_label( 'called' ) );
        $this->assertSame( 'Accepted', RecruitmentAdminPage::classification_status_label( 'accepted' ) );
        $this->assertSame( 'Did not show up', RecruitmentAdminPage::classification_status_label( 'not_shown' ) );
        $this->assertSame( 'Hired', RecruitmentAdminPage::classification_status_label( 'hired' ) );
    }

    public function test_classification_status_label_falls_back_to_raw_value_for_unknown(): void {
        $this->assertSame( 'unknown', RecruitmentAdminPage::classification_status_label( 'unknown' ) );
    }

    public function test_classification_status_badge_delegates_to_badge_html(): void {
        $html = RecruitmentAdminPage::classification_status_badge( 'called' );

        $this->assertStringContainsString( '[BADGE:', $html );
        $this->assertStringContainsString( 'ffc-status-called', $html );
    }

    public function test_notice_status_badge_delegates_to_badge_html(): void {
        $html = RecruitmentAdminPage::notice_status_badge( 'preliminary' );

        $this->assertStringContainsString( '[BADGE:', $html );
        $this->assertStringContainsString( 'preliminary', $html );
    }

    public function test_notice_status_badge_falls_back_to_neutral_color_for_unknown_status(): void {
        $html = RecruitmentAdminPage::notice_status_badge( 'bogus' );

        // Unknown status → the `?? '#e9ecef'` neutral fallback color.
        $this->assertStringContainsString( '#e9ecef', $html );
    }

    public function test_adjutancy_badge_returns_empty_string_for_null(): void {
        $this->assertSame( '', RecruitmentAdminPage::adjutancy_badge( null ) );
    }

    public function test_adjutancy_badge_uses_row_color_when_present(): void {
        $adjutancy = (object) array(
            'name'  => 'Matemática',
            'color' => '#123456',
        );

        $html = RecruitmentAdminPage::adjutancy_badge( $adjutancy );

        $this->assertStringContainsString( '#123456', $html );
        $this->assertStringContainsString( 'Matemática', $html );
    }

    public function test_adjutancy_badge_falls_back_to_default_color_when_blank(): void {
        $adjutancy = (object) array(
            'name'  => 'Português',
            'color' => '',
        );

        $html = RecruitmentAdminPage::adjutancy_badge( $adjutancy );

        $this->assertStringContainsString(
            \FreeFormCertificate\Recruitment\RecruitmentAdjutancyReader::DEFAULT_COLOR,
            $html
        );
    }

    // ==================================================================
    // highlight_active_tab() — submenu_file filter for tab highlighting
    // ==================================================================

    public function test_highlight_active_tab_passes_through_on_other_pages(): void {
        $_GET = array( 'page' => 'some-other-page' );
        $this->assertSame(
            'some-other-page.php',
            RecruitmentAdminPage::highlight_active_tab( 'some-other-page.php' )
        );
        $_GET = array();
    }

    public function test_highlight_active_tab_defaults_to_notices_without_tab(): void {
        $_GET = array( 'page' => 'ffc-recruitment' );
        $this->assertSame(
            'ffc-recruitment',
            RecruitmentAdminPage::highlight_active_tab( 'ffc-recruitment' )
        );
        $_GET = array();
    }

    public function test_highlight_active_tab_maps_known_tab_to_submenu_slug(): void {
        $_GET = array(
            'page' => 'ffc-recruitment',
            'tab'  => 'candidates',
        );
        $this->assertSame(
            'ffc-recruitment&tab=candidates',
            RecruitmentAdminPage::highlight_active_tab( 'ffc-recruitment' )
        );
        $_GET = array();
    }

    public function test_highlight_active_tab_falls_back_to_notices_for_invalid_tab(): void {
        $_GET = array(
            'page' => 'ffc-recruitment',
            'tab'  => 'bogus',
        );
        $this->assertSame(
            'ffc-recruitment',
            RecruitmentAdminPage::highlight_active_tab( 'ffc-recruitment' )
        );
        $_GET = array();
    }
}

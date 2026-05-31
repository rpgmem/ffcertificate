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

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();

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
}

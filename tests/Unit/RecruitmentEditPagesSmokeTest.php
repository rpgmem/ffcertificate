<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentReasonEditPage;
use FreeFormCertificate\Recruitment\RecruitmentAdjutancyEditPage;
use FreeFormCertificate\Recruitment\RecruitmentCandidateEditPage;
use FreeFormCertificate\Recruitment\RecruitmentNoticeEditPage;
use FreeFormCertificate\Recruitment\RecruitmentNoticeEditPageRenderer;

/**
 * Smoke tests for the 4 recruitment edit-page screens + the notice
 * edit-page renderer. Each screen exposes static register() / render() /
 * handle_*() methods bound to the WP-admin runtime; full coverage
 * requires the real admin context. This suite pins the register() hook
 * contract (every page declares the admin-post action it handles) and
 * the few pure helpers exposed on the renderer.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentReasonEditPage
 * @covers \FreeFormCertificate\Recruitment\RecruitmentAdjutancyEditPage
 * @covers \FreeFormCertificate\Recruitment\RecruitmentCandidateEditPage
 * @covers \FreeFormCertificate\Recruitment\RecruitmentNoticeEditPage
 * @covers \FreeFormCertificate\Recruitment\RecruitmentNoticeEditPageRenderer
 */
class RecruitmentEditPagesSmokeTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // register() — admin-post action wiring
    // ------------------------------------------------------------------

    public function test_reason_edit_page_register_hooks_save_handler(): void {
        Actions\expectAdded( 'admin_post_ffc_recruitment_save_reason' )
            ->once()
            ->with( array( RecruitmentReasonEditPage::class, 'handle_save' ), 10 );

        RecruitmentReasonEditPage::register();
    }

    public function test_adjutancy_edit_page_register_hooks_save_handler(): void {
        Actions\expectAdded( 'admin_post_ffc_recruitment_save_adjutancy' )
            ->once()
            ->with( array( RecruitmentAdjutancyEditPage::class, 'handle_save' ), 10 );

        RecruitmentAdjutancyEditPage::register();
    }

    public function test_candidate_edit_page_register_hooks_all_admin_post_handlers(): void {
        Actions\expectAdded( 'admin_post_ffc_recruitment_save_candidate' )->once();
        Actions\expectAdded( 'admin_post_ffc_recruitment_delete_candidate' )->once();
        Actions\expectAdded( 'admin_post_ffc_recruitment_link_candidate_user' )->once();
        Actions\expectAdded( 'admin_post_ffc_recruitment_unlink_candidate_user' )->once();

        RecruitmentCandidateEditPage::register();
    }

    public function test_notice_edit_page_register_hooks_save_transition_and_csv_handlers(): void {
        Actions\expectAdded( 'admin_post_ffc_recruitment_save_notice' )->once();
        Actions\expectAdded( 'admin_post_ffc_recruitment_transition_notice' )->once();
        Actions\expectAdded( 'admin_post_ffc_recruitment_download_csv_example' )->once();

        RecruitmentNoticeEditPage::register();
    }

    // ------------------------------------------------------------------
    // RecruitmentNoticeEditPageRenderer — columns_label_map() helper
    // ------------------------------------------------------------------

    public function test_renderer_columns_label_map_covers_every_public_columns_key(): void {
        $map = RecruitmentNoticeEditPageRenderer::columns_label_map();

        $expected_keys = array(
            'rank', 'name', 'adjutancy', 'status', 'pcd_badge',
            'date_to_assume', 'time_to_assume', 'score', 'time_points',
            'hab_emebs', 'cpf_masked', 'rf_masked', 'email_masked',
            'preview_reason',
        );
        foreach ( $expected_keys as $key ) {
            $this->assertArrayHasKey( $key, $map, "label missing for column: $key" );
        }
    }

    public function test_renderer_columns_label_map_labels_are_non_empty_strings(): void {
        $map = RecruitmentNoticeEditPageRenderer::columns_label_map();

        foreach ( $map as $key => $label ) {
            $this->assertIsString( $label, "label for '$key' must be a string" );
            $this->assertNotSame( '', $label, "label for '$key' must not be empty" );
        }
    }
}

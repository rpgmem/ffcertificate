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

    // ------------------------------------------------------------------
    // RecruitmentCandidateEditPage::build_delete_consequences() — #331
    // ------------------------------------------------------------------

    /**
     * Reflect into the private static helper since it has no public
     * surface and rendering the whole delete section pulls in repository
     * + DateFormatter stubs that would clutter this smoke suite.
     *
     * @param object $candidate Candidate row stub.
     * @return list<string>
     */
    private function invoke_build_delete_consequences( object $candidate ): array {
        $ref = new \ReflectionMethod( RecruitmentCandidateEditPage::class, 'build_delete_consequences' );
        $ref->setAccessible( true );
        /** @var list<string> $out */
        $out = $ref->invoke( null, $candidate );
        return $out;
    }

    public function test_build_delete_consequences_prepends_created_on_line(): void {
        Functions\when( 'get_userdata' )->justReturn( false );
        // DateFormatter is fully namespaced, but format_datetime is a
        // static method on the helper class — stub the WordPress
        // primitives it relies on so we exercise the formatter for
        // real (it falls back to the input string when wp_date /
        // wp_timezone are unavailable in test, which is fine for
        // assertion purposes).
        Functions\when( 'wp_date' )->returnArg();
        Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
        Functions\when( 'get_option' )->justReturn( array() );

        $candidate = (object) array(
            'id'         => 5,
            'user_id'    => null,
            'created_at' => '2026-05-10 12:00:00',
            'updated_at' => '2026-05-10 12:00:00',
        );

        $lines = $this->invoke_build_delete_consequences( $candidate );

        $this->assertNotEmpty( $lines );
        $this->assertStringStartsWith( 'Created on ', $lines[0] );
        $this->assertCount( 4, $lines, 'created + 3 standard consequences when updated_at == created_at' );
    }

    public function test_build_delete_consequences_separates_created_and_updated_when_different(): void {
        Functions\when( 'get_userdata' )->justReturn( false );
        Functions\when( 'wp_date' )->returnArg();
        Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
        Functions\when( 'get_option' )->justReturn( array() );

        $candidate = (object) array(
            'id'         => 5,
            'user_id'    => null,
            'created_at' => '2026-05-10 12:00:00',
            'updated_at' => '2026-05-12 09:30:00',
        );

        $lines = $this->invoke_build_delete_consequences( $candidate );

        $this->assertStringStartsWith( 'Created on ', $lines[0] );
        $this->assertStringStartsWith( 'Last updated on ', $lines[1] );
        $this->assertCount( 5, $lines, 'created + last-updated + 3 standard consequences' );
    }

    public function test_build_delete_consequences_surfaces_linked_user_when_promoted(): void {
        $wp_user             = new \stdClass();
        $wp_user->user_login = 'alice';
        Functions\when( 'get_userdata' )->justReturn( $wp_user );
        Functions\when( 'wp_date' )->returnArg();
        Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
        Functions\when( 'get_option' )->justReturn( array() );

        $candidate = (object) array(
            'id'         => 5,
            'user_id'    => 99,
            'created_at' => '2026-05-10 12:00:00',
            'updated_at' => '2026-05-10 12:00:00',
        );

        $lines = $this->invoke_build_delete_consequences( $candidate );

        $found_user_line = false;
        foreach ( $lines as $line ) {
            if ( str_contains( $line, '@alice' ) ) {
                $found_user_line = true;
                break;
            }
        }
        $this->assertTrue( $found_user_line, 'expected the linked user line to surface @alice' );
    }
}

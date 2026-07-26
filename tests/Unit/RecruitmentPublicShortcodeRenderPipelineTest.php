<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentPublicShortcodeRenderer;

/**
 * Pipeline-level tests for {@see RecruitmentPublicShortcodeRenderer} — the
 * heavy rendering methods the helper-focused sibling test deliberately left
 * out: `render_section()` (pagination math + candidate cache warm + row
 * loop), the full column matrix of `render_row()` (every optional column
 * branch, preview vs. definitive status, PCD true/false, date/time cells),
 * `render_filters_bar()` (preserved-param re-emission + the adjutancy/name/
 * subscription sub-inputs) and `render_pagination()` (the sliding window).
 *
 * Collaborating readers are alias-mocked so the renderer is exercised in
 * isolation; adjutancy rows always carry a color so the badge helper never
 * needs `RecruitmentAdjutancyReader::DEFAULT_COLOR` (absent on an alias).
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentPublicShortcodeRenderer
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecruitmentPublicShortcodeRenderPipelineTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var \Mockery\MockInterface */
    private $candMock;

    /** @var \Mockery\MockInterface */
    private $callMock;

    /** @var \Mockery\MockInterface */
    private $pcdMock;

    /** @var \Mockery\MockInterface */
    private $noticeAdjMock;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // pcov coverage-attribution: preload so lines attribute to this test.
        class_exists( '\FreeFormCertificate\Recruitment\RecruitmentPublicShortcodeRenderer' );

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_attr__' )->returnArg();
        Functions\when( 'esc_html' )->alias( fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ) );
        Functions\when( 'esc_attr' )->alias( fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ) );
        Functions\when( 'esc_url' )->alias( fn( $s ) => (string) $s );
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'selected' )->justReturn( '' );
        Functions\when( 'number_format_i18n' )->alias( fn( $n ) => (string) $n );
        Functions\when( '_n' )->alias( fn( $single, $plural, $number ) => 1 === (int) $number ? $single : $plural );
        Functions\when( 'add_query_arg' )->alias( fn( $k, $v, $base = '' ) => "?{$k}={$v}" );
        Functions\when( 'remove_query_arg' )->alias( fn( $k, $base = '' ) => '?' );

        $settings = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentSettings' );
        $settings->shouldReceive( 'all' )->andReturn(
            array(
                'status_color_empty'              => '#ffe066',
                'status_color_called'             => '#c3aed6',
                'status_color_hired'              => '#a3e0a3',
                'status_color_not_shown'          => '#f5a3a3',
                'status_color_withdrew'           => '#d0d0d0',
                'preview_color_empty'             => '#eeeeee',
                'preview_color_denied'            => '#f5a3a3',
                'preview_color_granted'           => '#a3e0a3',
                'preview_color_appeal_denied'     => '#f0b0b0',
                'preview_color_appeal_granted'    => '#b0f0b0',
                'subscription_color_pcd'          => '#8ec7ff',
                'subscription_color_geral'        => '#cccccc',
                'notice_status_color_preliminary' => '#ffe066',
                'notice_status_color_definitive'  => '#a3e0a3',
                'notice_status_color_closed'      => '#cccccc',
            )
        )->byDefault();

        $badge = Mockery::mock( 'alias:FreeFormCertificate\Core\BadgeHtml' );
        $badge->shouldReceive( 'render' )->andReturnUsing(
            fn( $base, $cls, $color, $label, $title = '' ) => "[BADGE:{$cls}:{$color}:{$label}:{$title}]"
        );

        $enc = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
        $enc->shouldReceive( 'decrypt' )->andReturnUsing( fn( $v ) => 'plain:' . $v )->byDefault();

        $doc = Mockery::mock( 'alias:FreeFormCertificate\Core\DocumentFormatter' );
        $doc->shouldReceive( 'mask_cpf' )->andReturn( '***.***.***-11' )->byDefault();
        $doc->shouldReceive( 'mask_rf' )->andReturn( '***.***-2' )->byDefault();
        $doc->shouldReceive( 'mask_email' )->andReturn( 'a***@e***.com' )->byDefault();

        $this->pcdMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentPcdHasher' );
        // Default: verify() true; individual tests override for the false path.
        $this->pcdMock->shouldReceive( 'verify' )->andReturn( true )->byDefault();

        $reason = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentReasonReader' );
        $reason->shouldReceive( 'get_by_id' )->andReturn( (object) array( 'label' => 'Fora do prazo' ) )->byDefault();

        $this->callMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCallReader' );
        $this->callMock->shouldReceive( 'get_active_for_classification' )->andReturn(
            (object) array(
                'date_to_assume' => '2026-05-20',
                'time_to_assume' => '09:00:00',
            )
        )->byDefault();

        $adj = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentAdjutancyReader' );
        $adj->shouldReceive( 'get_by_id' )->andReturnUsing(
            fn( $id ) => (object) array(
                'name'  => 'Adjutancy ' . $id,
                'slug'  => 'adj-' . $id,
                'color' => '#123456',
            )
        )->byDefault();

        $this->noticeAdjMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentNoticeAdjutancyRepository' );
        $this->noticeAdjMock->shouldReceive( 'get_adjutancy_ids_for_notice' )->andReturn( array( 1, 2 ) )->byDefault();

        $this->candMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentCandidateReader' );
        $this->candMock->shouldReceive( 'get_by_ids' )->andReturnNull()->byDefault();
        $this->candMock->shouldReceive( 'get_by_id' )->andReturnUsing(
            fn( $id ) => self::candidate( (int) $id )
        )->byDefault();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** Build a candidate row with every field the renderer reads. */
    private static function candidate( int $id ): \stdClass {
        return (object) array(
            'id'              => (string) $id,
            'name'            => "Candidate {$id}",
            'cpf_encrypted'   => 'cpf-ct',
            'rf_encrypted'    => 'rf-ct',
            'email_encrypted' => 'email-ct',
            'pcd_hash'        => 'pcd-hash',
        );
    }

    /** Build a classification row; overrides merge over the defaults. */
    private static function row( array $over = array() ): \stdClass {
        return (object) array_merge(
            array(
                'id'                => 10,
                'candidate_id'      => 1,
                'rank'              => 1,
                'adjutancy_id'      => 3,
                'status'            => 'called',
                'list_type'         => 'definitive',
                'preview_status'    => 'granted',
                'preview_reason_id' => null,
                'score'             => 53.0,
                'time_points'       => 4.5,
                'hab_emebs'         => 1,
            ),
            $over
        );
    }

    /** Every optional column switched on. */
    private static function all_columns(): array {
        return array(
            'rank'           => true,
            'name'           => true,
            'adjutancy'      => true,
            'cpf_masked'     => true,
            'rf_masked'      => true,
            'email_masked'   => true,
            'score'          => true,
            'time_points'    => true,
            'hab_emebs'      => true,
            'status'         => true,
            'pcd_badge'      => true,
            'date_to_assume' => true,
            'time_to_assume' => true,
            'preview_reason' => true,
        );
    }

    // ------------------------------------------------------------------
    // render_section()
    // ------------------------------------------------------------------

    public function test_render_section_returns_empty_string_for_no_rows(): void {
        $out = RecruitmentPublicShortcodeRenderer::render_section(
            'Waiting',
            array(),
            self::all_columns(),
            true,
            1,
            25,
            'p'
        );
        $this->assertSame( '', $out );
    }

    public function test_render_section_renders_full_table_with_all_columns_and_definitive_status(): void {
        $out = RecruitmentPublicShortcodeRenderer::render_section(
            'Called',
            array( self::row() ),
            self::all_columns(),
            true,
            1,
            25,
            'p'
        );

        $this->assertStringContainsString( '<section class="ffc-recruitment-section">', $out );
        $this->assertStringContainsString( 'Candidate 1', $out );
        $this->assertStringContainsString( '***.***.***-11', $out ); // masked cpf
        $this->assertStringContainsString( '***.***-2', $out );       // masked rf
        $this->assertStringContainsString( 'a***@e***.com', $out );   // masked email
        $this->assertStringContainsString( '53.00', $out );           // score, 2 decimals
        $this->assertStringContainsString( '4.50', $out );            // time_points
        $this->assertStringContainsString( '20-05-2026', $out );      // date reformatted d-m-Y
        $this->assertStringContainsString( '09:00', $out );           // time HH:MM
        $this->assertStringContainsString( '</tbody></table>', $out );
        $this->assertStringContainsString( '</section>', $out );
    }

    public function test_render_section_renders_preview_status_with_reason_tooltip(): void {
        $out = RecruitmentPublicShortcodeRenderer::render_section(
            'Preview',
            array( self::row( array( 'list_type' => 'preview', 'preview_status' => 'granted', 'preview_reason_id' => 5 ) ) ),
            self::all_columns(),
            true,
            1,
            25,
            'p'
        );

        // Preview badge carries the resolved reason label as its tooltip (5th arg).
        $this->assertStringContainsString( 'Fora do prazo', $out );
        $this->assertStringContainsString( 'ffc-recruitment-preview-status-granted', $out );
    }

    public function test_render_section_skips_row_whose_candidate_is_missing(): void {
        $this->candMock->shouldReceive( 'get_by_id' )->andReturnNull(); // every candidate missing

        $out = RecruitmentPublicShortcodeRenderer::render_section(
            'Called',
            array( self::row() ),
            self::all_columns(),
            true,
            1,
            25,
            'p'
        );

        // Table chrome still renders; the row itself is skipped (no candidate name).
        $this->assertStringContainsString( '<tbody>', $out );
        $this->assertStringNotContainsString( 'Candidate 1', $out );
    }

    public function test_render_section_hab_emebs_off_renders_dash(): void {
        $out = RecruitmentPublicShortcodeRenderer::render_section(
            'Called',
            array( self::row( array( 'hab_emebs' => 0 ) ) ),
            self::all_columns(),
            true,
            1,
            25,
            'p'
        );
        $this->assertStringContainsString( '—', $out );
    }

    public function test_render_section_paginates_across_multiple_pages(): void {
        $rows = array();
        for ( $i = 1; $i <= 5; $i++ ) {
            $rows[] = self::row( array( 'id' => $i, 'candidate_id' => $i, 'rank' => $i ) );
        }

        // page_size 2 over 5 rows → 3 pages → the pagination template renders.
        $out = RecruitmentPublicShortcodeRenderer::render_section(
            'Called',
            $rows,
            self::all_columns(),
            true,
            1,
            2,
            'p'
        );

        $this->assertStringContainsString( 'ffc-recruitment-pagination', $out );
    }

    public function test_render_section_clamps_current_page_past_last(): void {
        $rows = array( self::row( array( 'id' => 1, 'candidate_id' => 1 ) ), self::row( array( 'id' => 2, 'candidate_id' => 2 ) ) );

        // Ask for page 99 with 1 page-worth of data → clamps to the last page.
        $out = RecruitmentPublicShortcodeRenderer::render_section(
            'Called',
            $rows,
            self::all_columns(),
            true,
            99,
            25,
            'p'
        );
        $this->assertStringContainsString( 'Candidate 1', $out );
    }

    // ------------------------------------------------------------------
    // render_row() — date/time cells when no active call
    // ------------------------------------------------------------------

    public function test_render_row_empty_date_time_when_no_active_call(): void {
        $this->callMock->shouldReceive( 'get_active_for_classification' )->andReturnNull();

        $out = RecruitmentPublicShortcodeRenderer::render_section(
            'Called',
            array( self::row() ),
            self::all_columns(),
            true,
            1,
            25,
            'p'
        );

        // Row still renders; the date/time cells are simply blank.
        $this->assertStringContainsString( 'Candidate 1', $out );
        $this->assertStringNotContainsString( '20-05-2026', $out );
    }

    public function test_render_row_pcd_false_renders_geral_badge(): void {
        $this->pcdMock->shouldReceive( 'verify' )->andReturn( false );

        $out = RecruitmentPublicShortcodeRenderer::render_section(
            'Called',
            array( self::row() ),
            self::all_columns(),
            true,
            1,
            25,
            'p'
        );

        $this->assertStringContainsString( 'ffc-recruitment-subscription-geral', $out );
    }

    // ------------------------------------------------------------------
    // render_filters_bar()
    // ------------------------------------------------------------------

    public function test_render_filters_bar_renders_all_controls_and_preserves_params(): void {
        $_GET = array(
            'page'      => 'notice-x',
            'q'         => 'ana',
            'adjutancy' => '3',
        );

        $notice = (object) array( 'id' => 7 );
        $out    = RecruitmentPublicShortcodeRenderer::render_filters_bar( $notice, false, 'ana', '' );

        $this->assertStringContainsString( '<form class="ffc-recruitment-filters"', $out );
        // Preserved param (page) is re-emitted as a hidden input; the filter
        // controls' own params (q/adjutancy) are NOT re-emitted as hidden
        // inputs — those live in the visible controls, which own them.
        $this->assertStringContainsString( '<input type="hidden" name="page"', $out );
        $this->assertStringNotContainsString( '<input type="hidden" name="q"', $out );
        $this->assertStringNotContainsString( '<input type="hidden" name="adjutancy"', $out );

        $_GET = array();
    }

    public function test_render_filters_bar_locked_omits_adjutancy_select(): void {
        $_GET   = array();
        $notice = (object) array( 'id' => 7 );

        $out = RecruitmentPublicShortcodeRenderer::render_filters_bar( $notice, true, '', '' );

        // Locked → the adjutancy sub-input is skipped, but the form (name +
        // subscription controls) still renders.
        $this->assertStringContainsString( '<form class="ffc-recruitment-filters"', $out );
    }

    public function test_render_filters_bar_single_adjutancy_hides_dropdown(): void {
        $this->noticeAdjMock->shouldReceive( 'get_adjutancy_ids_for_notice' )->andReturn( array( 1 ) ); // < 2 → hidden

        $_GET   = array();
        $notice = (object) array( 'id' => 7 );

        $out = RecruitmentPublicShortcodeRenderer::render_filters_bar( $notice, false, '', '' );

        $this->assertStringContainsString( '<form class="ffc-recruitment-filters"', $out );
    }
}

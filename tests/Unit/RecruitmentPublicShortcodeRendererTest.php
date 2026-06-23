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
 * Tests for the public recruitment shortcode renderer — focused on the
 * static helpers that compose the listing HTML (msg, parse_columns_config,
 * wrap_with_banner). The full render_section pipeline depends on the
 * full classification + candidate runtime and is out of scope for the
 * smoke tier.
 *
 * Coverage emphasises the escape paths because the renderer feeds the
 * public shortcode output without a WordPress template wrapper — any
 * bug here is an XSS regression vector.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentPublicShortcodeRenderer
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RecruitmentPublicShortcodeRendererTest extends TestCase {

    use MockeryPHPUnitIntegration;

    /** @var \Mockery\MockInterface */
    private $settingsMock;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        // Escape stubs intentionally reflect the WP semantics so the
        // assertions can detect missing-escape regressions.
        Functions\when( 'esc_html' )->alias( fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ) );
        Functions\when( 'esc_attr' )->alias( fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ) );
        Functions\when( 'esc_url' )->alias( fn( $s ) => filter_var( (string) $s, FILTER_SANITIZE_URL ) ?: (string) $s );

        // parse_columns_config reads RecruitmentNoticeReader::DEFAULT_PUBLIC_COLUMNS_CONFIG
        // (a class constant). We let the real class autoload so the constant
        // resolves — the renderer never calls methods on the repository.

        $this->settingsMock = Mockery::mock( 'alias:FreeFormCertificate\Recruitment\RecruitmentSettings' );
        $this->settingsMock->shouldReceive( 'all' )->andReturn(
            array(
                'notice_status_color_preliminary' => '#ffe066',
                'notice_status_color_definitive'  => '#a3e0a3',
                'notice_status_color_closed'      => '#cccccc',
            )
        )->byDefault();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // msg() — escape contract
    // ------------------------------------------------------------------

    public function test_msg_escapes_text_and_kind(): void {
        $out = RecruitmentPublicShortcodeRenderer::msg( '<script>alert("x")</script>', 'info' );

        $this->assertStringContainsString( 'ffc-recruitment-message-info', $out );
        $this->assertStringNotContainsString( '<script>', $out );
        $this->assertStringContainsString( '&lt;script&gt;', $out );
    }

    public function test_msg_escapes_quote_in_kind_attribute(): void {
        $out = RecruitmentPublicShortcodeRenderer::msg( 'hello', 'info" onclick="alert(1)' );

        // Quote in $kind must be escaped so it can't break the host
        // class="ffc-recruitment-message-..." attribute.
        $this->assertStringNotContainsString( '" onclick="', $out );
    }

    // ------------------------------------------------------------------
    // parse_columns_config()
    // ------------------------------------------------------------------

    public function test_parse_columns_config_always_forces_rank_and_name_to_true(): void {
        // Even if the operator persists explicit `false` for rank / name,
        // the schema-level invariant flips them back to true.
        $json = json_encode( array( 'rank' => false, 'name' => false ) );

        $cols = RecruitmentPublicShortcodeRenderer::parse_columns_config( (string) $json );

        $this->assertTrue( $cols['rank'] );
        $this->assertTrue( $cols['name'] );
    }

    public function test_parse_columns_config_returns_all_known_keys(): void {
        $cols = RecruitmentPublicShortcodeRenderer::parse_columns_config( '{}' );

        $expected_keys = array(
            'rank', 'name', 'adjutancy', 'status', 'pcd_badge', 'date_to_assume',
            'time_to_assume', 'score', 'time_points', 'hab_emebs',
            'cpf_masked', 'rf_masked', 'email_masked', 'preview_reason',
        );
        foreach ( $expected_keys as $key ) {
            $this->assertArrayHasKey( $key, $cols, "missing key: $key" );
        }
    }

    public function test_parse_columns_config_overrides_optional_keys_from_json(): void {
        $json = json_encode( array( 'cpf_masked' => true, 'preview_reason' => true ) );

        $cols = RecruitmentPublicShortcodeRenderer::parse_columns_config( (string) $json );

        $this->assertTrue( $cols['cpf_masked'] );
        $this->assertTrue( $cols['preview_reason'] );
    }

    public function test_parse_columns_config_tolerates_malformed_json(): void {
        $cols = RecruitmentPublicShortcodeRenderer::parse_columns_config( 'not-json' );

        // Falls back to defaults (everything optional false, mandatory true).
        $this->assertTrue( $cols['rank'] );
        $this->assertTrue( $cols['name'] );
        $this->assertFalse( $cols['cpf_masked'] );
    }

    // ------------------------------------------------------------------
    // wrap_with_banner()
    // ------------------------------------------------------------------

    public function test_wrap_with_banner_renders_preliminary_status_banner_with_color(): void {
        $notice = (object) array(
            'status' => 'preliminary',
            'code'   => 'EDITAL-01',
            'name'   => 'Concurso 2026',
        );

        $out = RecruitmentPublicShortcodeRenderer::wrap_with_banner( $notice, '<p>body</p>' );

        $this->assertStringContainsString( 'ffc-recruitment-banner-preliminary', $out );
        $this->assertStringContainsString( '#ffe066', $out );
        $this->assertStringContainsString( 'EDITAL-01', $out );
        $this->assertStringContainsString( 'Concurso 2026', $out );
        $this->assertStringContainsString( '<p>body</p>', $out, 'body must follow the header' );
    }

    public function test_wrap_with_banner_renders_no_banner_when_status_unknown(): void {
        $notice = (object) array(
            'status' => 'draft', // not in the known status_messages map.
            'code'   => 'EDITAL-X',
            'name'   => 'X',
        );

        $out = RecruitmentPublicShortcodeRenderer::wrap_with_banner( $notice, 'BODY' );

        $this->assertStringNotContainsString( 'ffc-recruitment-banner', $out );
        $this->assertStringContainsString( 'EDITAL-X', $out );
        $this->assertStringContainsString( 'BODY', $out );
    }

    public function test_wrap_with_banner_escapes_notice_code_and_name(): void {
        $notice = (object) array(
            'status' => 'definitive',
            'code'   => '<script>x</script>',
            'name'   => 'Concurso "2026"',
        );

        $out = RecruitmentPublicShortcodeRenderer::wrap_with_banner( $notice, '' );

        $this->assertStringNotContainsString( '<script>', $out );
        $this->assertStringContainsString( '&lt;script&gt;', $out );
        $this->assertStringContainsString( '&quot;2026&quot;', $out );
    }

    // ------------------------------------------------------------------
    // Private logic helpers — invoked via reflection.
    // ------------------------------------------------------------------

    /**
     * @return mixed
     */
    private function invoke( string $method, ...$args ) {
        $ref = new \ReflectionClass( RecruitmentPublicShortcodeRenderer::class );
        $m   = $ref->getMethod( $method );
        $m->setAccessible( true );
        return $m->invoke( null, ...$args );
    }

    public function test_status_label_maps_known_and_unknown_values(): void {
        $this->assertSame( 'Waiting', $this->invoke( 'status_label', 'empty' ) );
        $this->assertSame( 'Called', $this->invoke( 'status_label', 'called' ) );
        // accepted is rendered as "Called" for the public view (§5.2).
        $this->assertSame( 'Called', $this->invoke( 'status_label', 'accepted' ) );
        $this->assertSame( 'Did not show up', $this->invoke( 'status_label', 'not_shown' ) );
        $this->assertSame( 'Hired', $this->invoke( 'status_label', 'hired' ) );
        $this->assertSame( 'Withdrew', $this->invoke( 'status_label', 'withdrew' ) );
        $this->assertSame( 'weird', $this->invoke( 'status_label', 'weird' ) );
    }

    public function test_preview_status_label_maps_known_and_unknown_values(): void {
        $this->assertSame( 'Empty', $this->invoke( 'preview_status_label', 'empty' ) );
        $this->assertSame( 'Denied', $this->invoke( 'preview_status_label', 'denied' ) );
        $this->assertSame( 'Granted', $this->invoke( 'preview_status_label', 'granted' ) );
        $this->assertSame( 'Appeal denied', $this->invoke( 'preview_status_label', 'appeal_denied' ) );
        $this->assertSame( 'Appeal granted', $this->invoke( 'preview_status_label', 'appeal_granted' ) );
        $this->assertSame( 'mystery', $this->invoke( 'preview_status_label', 'mystery' ) );
    }

    public function test_format_date_br_reformats_iso_to_d_m_y(): void {
        $this->assertSame( '20-05-2026', $this->invoke( 'format_date_br', '2026-05-20' ) );
    }

    public function test_format_date_br_returns_empty_for_empty_input(): void {
        $this->assertSame( '', $this->invoke( 'format_date_br', '' ) );
    }

    public function test_format_date_br_passes_through_unparseable_input(): void {
        $this->assertSame( 'not-a-date', $this->invoke( 'format_date_br', 'not-a-date' ) );
    }

    public function test_format_time_hm_strips_seconds(): void {
        $this->assertSame( '09:30', $this->invoke( 'format_time_hm', '09:30:00' ) );
    }

    public function test_format_time_hm_returns_empty_for_empty_input(): void {
        $this->assertSame( '', $this->invoke( 'format_time_hm', '' ) );
    }

    public function test_decrypt_field_returns_null_for_blank_or_non_string(): void {
        $this->assertNull( $this->invoke( 'decrypt_field', '' ) );
        $this->assertNull( $this->invoke( 'decrypt_field', null ) );
        $this->assertNull( $this->invoke( 'decrypt_field', 123 ) );
    }

    public function test_decrypt_field_delegates_to_encryption(): void {
        $enc = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
        $enc->shouldReceive( 'decrypt' )->with( 'cipher' )->andReturn( 'plain' );

        $this->assertSame( 'plain', $this->invoke( 'decrypt_field', 'cipher' ) );
    }

    public function test_decrypt_field_returns_null_when_decrypt_fails(): void {
        $enc = Mockery::mock( 'alias:FreeFormCertificate\Core\Encryption' );
        $enc->shouldReceive( 'decrypt' )->andReturn( null );

        $this->assertNull( $this->invoke( 'decrypt_field', 'cipher' ) );
    }

    public function test_render_adjutancy_badge_returns_empty_for_null(): void {
        $this->assertSame( '', $this->invoke( 'render_adjutancy_badge', null ) );
    }

    public function test_render_adjutancy_badge_uses_color_and_falls_back(): void {
        $badge = Mockery::mock( 'alias:FreeFormCertificate\Core\BadgeHtml' );
        $badge->shouldReceive( 'render' )->andReturnUsing(
            fn( $base, $cls, $color, $label ) => "[$color|$label]"
        );

        $this->assertSame(
            '[#abcabc|Mat]',
            $this->invoke( 'render_adjutancy_badge', (object) array( 'name' => 'Mat', 'color' => '#abcabc' ) )
        );
        // Blank color → repository default.
        $out = $this->invoke( 'render_adjutancy_badge', (object) array( 'name' => 'Por', 'color' => '' ) );
        $this->assertStringContainsString(
            \FreeFormCertificate\Recruitment\RecruitmentAdjutancyRepository::DEFAULT_COLOR,
            $out
        );
    }

    public function test_render_subscription_badge_pcd_and_geral(): void {
        $this->settingsMock->shouldReceive( 'all' )->andReturn(
            array(
                'subscription_color_pcd'   => '#pcd',
                'subscription_color_geral' => '#geral',
            )
        );
        $badge = Mockery::mock( 'alias:FreeFormCertificate\Core\BadgeHtml' );
        $badge->shouldReceive( 'render' )->andReturnUsing(
            fn( $base, $cls, $color, $label ) => "[$cls|$color|$label]"
        );

        $pcd   = RecruitmentPublicShortcodeRenderer::render_subscription_badge( true );
        $geral = RecruitmentPublicShortcodeRenderer::render_subscription_badge( false );

        $this->assertStringContainsString( 'pcd', $pcd );
        $this->assertStringContainsString( '#pcd', $pcd );
        $this->assertStringContainsString( 'geral', $geral );
        $this->assertStringContainsString( '#geral', $geral );
    }

    public function test_render_status_badge_maps_color_by_status(): void {
        $this->settingsMock->shouldReceive( 'all' )->andReturn(
            array(
                'status_color_empty'     => '#e1',
                'status_color_called'    => '#c1',
                'status_color_hired'     => '#h1',
                'status_color_not_shown' => '#n1',
                'status_color_withdrew'  => '#w1',
            )
        );
        $badge = Mockery::mock( 'alias:FreeFormCertificate\Core\BadgeHtml' );
        $badge->shouldReceive( 'render' )->andReturnUsing(
            fn( $base, $cls, $color, $label ) => "[$color]"
        );

        $this->assertSame( '[#c1]', $this->invoke( 'render_status_badge', 'called' ) );
        // accepted shares the called color.
        $this->assertSame( '[#c1]', $this->invoke( 'render_status_badge', 'accepted' ) );
        // Unknown status → neutral fallback.
        $this->assertSame( '[#e9ecef]', $this->invoke( 'render_status_badge', 'bogus' ) );
    }

    public function test_render_preview_status_badge_maps_color_and_passes_reason(): void {
        $this->settingsMock->shouldReceive( 'all' )->andReturn(
            array(
                'preview_color_empty'          => '#pe',
                'preview_color_denied'         => '#pd',
                'preview_color_granted'        => '#pg',
                'preview_color_appeal_denied'  => '#pad',
                'preview_color_appeal_granted' => '#pag',
            )
        );
        $badge = Mockery::mock( 'alias:FreeFormCertificate\Core\BadgeHtml' );
        $badge->shouldReceive( 'render' )->andReturnUsing(
            fn( $base, $cls, $color, $label, $reason = '' ) => "[$color|$label|$reason]"
        );

        $this->assertSame( '[#pg|Granted|]', $this->invoke( 'render_preview_status_badge', 'granted', '' ) );
        $this->assertSame( '[#e9ecef|weird|hint]', $this->invoke( 'render_preview_status_badge', 'weird', 'hint' ) );
    }
}

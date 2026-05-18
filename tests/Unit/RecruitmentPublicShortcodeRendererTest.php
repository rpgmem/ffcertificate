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

        // parse_columns_config reads RecruitmentNoticeRepository::DEFAULT_PUBLIC_COLUMNS_CONFIG
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
}

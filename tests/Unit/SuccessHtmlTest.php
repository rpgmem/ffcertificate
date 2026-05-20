<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Core\Utils;

/**
 * Sprint 1 of #313 (6.6.2): success card now exposes the magic link, an
 * auth-code copy button, a persistent "download again" button, and
 * platform-specific "where is my file" hints. These tests pin the
 * additions to the template + the contract between
 * Utils::generate_success_html() and MagicLinkHelper.
 *
 * @runClassInSeparateProcess
 * @preserveGlobalState disabled
 */
class SuccessHtmlTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // WP helpers used by the template + the helper code path.
        Functions\when( '__' )->returnArg();
        Functions\when( '_e' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_attr__' )->returnArg();
        Functions\when( 'esc_html_e' )->alias( static function ( $v ): void {
            echo (string) $v;
        } );
        Functions\when( 'esc_attr_e' )->alias( static function ( $v ): void {
            echo (string) $v;
        } );
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'get_post_meta' )->justReturn( array() );
        Functions\when( 'get_post' )->alias( static function ( $id ) {
            $p              = new \stdClass();
            $p->ID          = (int) $id;
            $p->post_title  = 'Test Form';
            return $p;
        } );
        Functions\when( 'home_url' )->alias( static function ( $path = '/' ) {
            return 'https://example.org' . $path;
        } );
        Functions\when( 'get_option' )->justReturn( array() );
        // DateFormatter::format_datetime() reads the WP timezone setting.
        Functions\when( 'wp_timezone' )->alias( static function () {
            return new \DateTimeZone( 'UTC' );
        } );
        Functions\when( 'wp_date' )->alias( static function ( $fmt, $ts = null ) {
            return gmdate( $fmt, is_int( $ts ) ? $ts : (int) strtotime( (string) $ts ) );
        } );
        Functions\when( 'wp_parse_url' )->alias( static function ( $url, $part = -1 ) {
            return parse_url( $url, $part );
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Stub submission handler with ensure_magic_token().
     *
     * Stays anonymous: MagicLinkHelper picks it up via method_exists().
     */
    private function make_handler( string $token ): object {
        return new class( $token ) {
            private string $token;
            public function __construct( string $token ) {
                $this->token = $token;
            }
            public function ensure_magic_token( int $submission_id ): string {
                return $this->token . '_' . $submission_id;
            }
        };
    }

    public function test_success_html_includes_auth_code_with_copy_button(): void {
        $html = Utils::generate_success_html(
            array( 'auth_code' => 'ABC123XYZ' ),
            42,
            '2026-05-20 10:00:00',
            'Done!'
        );

        $this->assertStringContainsString( 'ffc-success-auth-code', $html );
        $this->assertStringContainsString( 'data-ffc-copy=', $html );
        $this->assertStringContainsString( 'Save this code', $html );
        // The auth-code value renders inside a <code class="ffc-success-code">.
        // CSS pins `white-space: nowrap` on that selector so the code cannot
        // split mid-string ("C-YK2K-RKFA-6EQC" wrapping into "...EQ" + "C"
        // was the original bug). Test that the wrapper class is present.
        $this->assertStringContainsString( 'ffc-success-code', $html );
    }

    public function test_success_html_omits_auth_code_block_when_missing(): void {
        $html = Utils::generate_success_html(
            array(),
            42,
            '2026-05-20 10:00:00',
            'Done!'
        );

        $this->assertStringNotContainsString( 'ffc-success-auth-code', $html );
        $this->assertStringNotContainsString( 'Save this code', $html );
    }

    public function test_success_html_includes_magic_link_when_handler_provided(): void {
        // 32-char hex token — MagicLinkHelper::is_valid_token regex.
        $token   = str_repeat( 'a', 32 );
        $handler = $this->make_handler( $token );

        $html = Utils::generate_success_html(
            array( 'auth_code' => 'ABC' ),
            42,
            '2026-05-20 10:00:00',
            'Done!',
            7,
            $handler
        );

        // Token comes through ensure_magic_token() prefixed with submission_id.
        // The full link goes through MagicLinkHelper::generate_magic_link()
        // which we don't stub — but we can assert the magic-link section
        // wrapper rendered, and the URL string is present.
        $this->assertStringContainsString( 'ffc-success-magic-link', $html );
        $this->assertStringContainsString( 'ffc-magic-link-url', $html );
        $this->assertStringContainsString( 'Save this link', $html );
    }

    public function test_success_html_omits_magic_link_section_without_handler(): void {
        $html = Utils::generate_success_html(
            array( 'auth_code' => 'ABC' ),
            42,
            '2026-05-20 10:00:00',
            'Done!',
            0,
            null
        );

        $this->assertStringNotContainsString( 'ffc-success-magic-link', $html );
        $this->assertStringNotContainsString( 'ffc-magic-link-url', $html );
    }

    public function test_success_html_always_renders_persistent_download_button(): void {
        $html = Utils::generate_success_html(
            array( 'auth_code' => 'X' ),
            42,
            '2026-05-20 10:00:00',
            'Done!'
        );

        $this->assertStringContainsString( 'ffc-download-pdf-btn', $html );
        $this->assertStringContainsString( 'ffc-success-download-btn', $html );
    }

    public function test_success_html_renders_all_three_platform_hints(): void {
        $html = Utils::generate_success_html(
            array( 'auth_code' => 'X' ),
            42,
            '2026-05-20 10:00:00',
            'Done!'
        );

        // All three lines are present in DOM; JS hides the irrelevant ones.
        // Forwarded links / JS-disabled visitors still see every hint.
        $this->assertStringContainsString( 'data-platform="android"', $html );
        $this->assertStringContainsString( 'data-platform="ios"', $html );
        $this->assertStringContainsString( 'data-platform="desktop"', $html );
        $this->assertStringContainsString( 'Downloads folder', $html );
        $this->assertStringContainsString( 'Save to Files', $html );
    }

    public function test_success_html_keeps_legacy_form_and_date_rows(): void {
        $html = Utils::generate_success_html(
            array( 'auth_code' => 'X' ),
            42,
            '2026-05-20 10:00:00',
            'Done!'
        );

        // Pre-existing API: success card surfaces the form title and date.
        $this->assertStringContainsString( 'Test Form', $html );
        $this->assertStringContainsString( 'Form:', $html );
        $this->assertStringContainsString( 'Date:', $html );
    }
}

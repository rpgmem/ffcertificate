<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\FormEditorShortcodeMetabox;

/**
 * @covers \FreeFormCertificate\Admin\FormEditorShortcodeMetabox
 */
class FormEditorShortcodeMetaboxTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private FormEditorShortcodeMetabox $metabox;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'wp_kses_post' )->returnArg();

        $this->metabox = new FormEditorShortcodeMetabox();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function render( int $post_id ): string {
        $post     = Mockery::mock( 'WP_Post' );
        $post->ID = $post_id;
        ob_start();
        $this->metabox->render( $post );
        return (string) ob_get_clean();
    }

    public function test_render_emits_the_shortcode_with_post_id(): void {
        $html = $this->render( 42 );

        $this->assertStringContainsString( '[ffc_form id="42"]', $html );
        $this->assertStringContainsString( 'ffc-shortcode-display', $html );
    }

    public function test_render_includes_tip_list_with_placeholder_examples(): void {
        $html = $this->render( 7 );

        $this->assertStringContainsString( 'ffc-tips-list', $html );
        $this->assertStringContainsString( '{{field_name}}', $html );
        $this->assertStringContainsString( '{{auth_code}}', $html );
    }

    public function test_render_uses_the_provided_post_id_verbatim_in_the_shortcode(): void {
        $this->assertStringContainsString( 'id="1"', $this->render( 1 ) );
        $this->assertStringContainsString( 'id="9999"', $this->render( 9999 ) );
    }
}

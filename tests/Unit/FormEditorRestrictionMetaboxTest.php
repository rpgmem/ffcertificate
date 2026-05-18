<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\FormEditorRestrictionMetabox;

/**
 * @covers \FreeFormCertificate\Admin\FormEditorRestrictionMetabox
 */
class FormEditorRestrictionMetaboxTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private FormEditorRestrictionMetabox $metabox;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'admin_url' )->alias( function ( $p = '' ) { return '/wp-admin/' . $p; } );
        Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'esc_textarea' )->returnArg();
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'wp_kses' )->returnArg();
        Functions\when( 'get_post_meta' )->justReturn( '' );
        Functions\when( 'checked' )->justReturn( '' );
        Functions\when( 'selected' )->justReturn( '' );

        $this->metabox = new FormEditorRestrictionMetabox();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function render( $config = array() ): string {
        Functions\when( 'get_post_meta' )->justReturn( $config );
        $post     = Mockery::mock( 'WP_Post' );
        $post->ID = 55;
        ob_start();
        $this->metabox->render( $post );
        return (string) ob_get_clean();
    }

    public function test_render_outputs_restriction_section_table(): void {
        $html = $this->render();

        $this->assertStringContainsString( 'Form Restrictions', $html );
        $this->assertStringContainsString( 'form-table', $html );
    }

    public function test_render_includes_restriction_label_rows(): void {
        $html = $this->render();

        // Each toggle ships inside a .ffc-restriction-label row.
        $this->assertStringContainsString( 'ffc-restriction-label', $html );
    }

    public function test_render_does_not_throw_when_config_is_empty_string(): void {
        // get_post_meta returning '' must coerce safely.
        $html = $this->render( '' );

        $this->assertNotSame( '', $html );
    }

    public function test_render_does_not_throw_when_config_is_array(): void {
        $html = $this->render(
            array(
                'one_per_cpf'   => '1',
                'one_per_ip'    => '1',
                'require_login' => '1',
            )
        );

        $this->assertNotSame( '', $html );
    }
}

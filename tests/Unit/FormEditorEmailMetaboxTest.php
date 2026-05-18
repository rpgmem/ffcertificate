<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\FormEditorEmailMetabox;

/**
 * @covers \FreeFormCertificate\Admin\FormEditorEmailMetabox
 */
class FormEditorEmailMetaboxTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private FormEditorEmailMetabox $metabox;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_textarea' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'admin_url' )->alias( function ( $p = '' ) { return '/wp-admin/' . $p; } );
        Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'wp_editor' )->alias( function ( $content, $id ) {
            echo '<textarea id="' . $id . '">' . $content . '</textarea>';
        } );
        Functions\when( 'get_post_meta' )->justReturn( '' );
        Functions\when( 'checked' )->justReturn( '' );
        Functions\when( 'selected' )->justReturn( '' );

        $this->metabox = new FormEditorEmailMetabox();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function render( $config = array() ): string {
        Functions\when( 'get_post_meta' )->justReturn( $config );
        $post     = Mockery::mock( 'WP_Post' );
        $post->ID = 33;
        ob_start();
        $this->metabox->render( $post );
        return (string) ob_get_clean();
    }

    public function test_render_exposes_master_toggle_for_user_email(): void {
        $html = $this->render();

        $this->assertStringContainsString( 'Send Email to User?', $html );
        $this->assertStringContainsString( 'ffc_config[send_user_email]', $html );
    }

    public function test_render_pre_populates_subject_and_body_from_config(): void {
        $html = $this->render(
            array(
                'send_user_email' => '1',
                'email_subject'   => 'Your custom subject',
                'email_body'      => '<p>Your custom body</p>',
            )
        );

        $this->assertStringContainsString( 'Your custom subject', $html );
        $this->assertStringContainsString( '<p>Your custom body</p>', $html );
        $this->assertStringContainsString( 'name="ffc_config[email_subject]"', $html );
    }

    public function test_render_collapses_subject_body_when_master_is_off(): void {
        $html = $this->render( array( 'send_user_email' => '0' ) );

        // Collapsed wrapper carries the ffc-collapsed modifier class.
        $this->assertStringContainsString( 'ffc-collapsed', $html );
    }

    public function test_render_uses_default_subject_when_meta_empty(): void {
        $html = $this->render();

        $this->assertStringContainsString( 'Your Certificate', $html );
    }
}

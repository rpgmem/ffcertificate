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
        Functions\when( 'wp_enqueue_script' )->justReturn( true );
        Functions\when( 'wp_localize_script' )->justReturn( true );
        // The EmailDisabledNotice at the top of the metabox reads ffc_settings
        // via SettingsReader; an empty array keeps emails "enabled" so the
        // notice no-ops during these render assertions.
        Functions\when( 'get_option' )->justReturn( array() );

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

    public function test_render_exposes_admin_notification_toggle_and_recipient(): void {
        $html = $this->render();

        $this->assertStringContainsString( 'Notify Admin on Submission?', $html );
        $this->assertStringContainsString( 'ffc_config[send_admin_email]', $html );
        $this->assertStringContainsString( 'name="ffc_config[email_admin]"', $html );
    }

    public function test_render_pre_populates_admin_recipient_from_config(): void {
        $html = $this->render(
            array( 'send_admin_email' => '1', 'email_admin' => 'ops@example.com' )
        );

        $this->assertStringContainsString( 'ops@example.com', $html );
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

        $this->assertStringContainsString( 'Your document is ready', $html );
    }

    public function test_render_seeds_default_body_when_email_body_empty(): void {
        // Email enabled, no custom body yet → editor is pre-filled with the
        // default intro instead of a blank field.
        $html = $this->render( array( 'send_user_email' => '1' ) );

        $this->assertStringContainsString( FormEditorEmailMetabox::default_email_body(), $html );
        $this->assertStringContainsString( 'Hello {{name}},', $html );
    }

    public function test_render_includes_restore_default_button(): void {
        $html = $this->render( array( 'send_user_email' => '1' ) );

        $this->assertStringContainsString( 'id="ffc-restore-default-email-body"', $html );
        $this->assertStringContainsString( 'Restore Default Text', $html );
    }

    public function test_render_keeps_custom_body_over_default(): void {
        // A real custom body is preserved (not overwritten by the default).
        $html = $this->render(
            array(
                'send_user_email' => '1',
                'email_body'      => '<p>My own message</p>',
            )
        );

        $this->assertStringContainsString( '<p>My own message</p>', $html );
        $this->assertStringNotContainsString( 'Use the button below to view and download it', $html );
    }
}

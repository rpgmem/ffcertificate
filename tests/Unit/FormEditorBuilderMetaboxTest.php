<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\FormEditorBuilderMetabox;

/**
 * @covers \FreeFormCertificate\Admin\FormEditorBuilderMetabox
 */
class FormEditorBuilderMetaboxTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private FormEditorBuilderMetabox $metabox;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_attr__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_textarea' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'admin_url' )->alias( function ( $p = '' ) { return '/wp-admin/' . $p; } );
        Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'get_post_meta' )->justReturn( '' );
        Functions\when( 'selected' )->justReturn( '' );
        Functions\when( 'checked' )->justReturn( '' );

        $this->metabox = new FormEditorBuilderMetabox();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function render( $fields_meta = '' ): string {
        Functions\when( 'get_post_meta' )->justReturn( $fields_meta );
        $post     = Mockery::mock( 'WP_Post' );
        $post->ID = 22;
        ob_start();
        $this->metabox->render( $post );
        return (string) ob_get_clean();
    }

    public function test_render_with_no_saved_fields_outputs_add_field_button(): void {
        $html = $this->render();

        $this->assertStringContainsString( 'ffc-add-field', $html );
        $this->assertStringContainsString( 'Add New Field', $html );
    }

    public function test_render_emits_field_template_for_dynamic_field_cloning(): void {
        $html = $this->render();

        $this->assertStringContainsString( 'ffc-field-template', $html );
        $this->assertStringContainsString( 'ffc-hidden', $html );
    }

    public function test_render_mentions_the_minimal_required_field_tags(): void {
        $html = $this->render();

        // The description hints the operator at the required field keys.
        $this->assertStringContainsString( '<code>name</code>', $html );
        $this->assertStringContainsString( '<code>email</code>', $html );
        $this->assertStringContainsString( '<code>cpf_rf</code>', $html );
    }

    public function test_render_iterates_saved_fields_payload(): void {
        $saved = array(
            array(
                'name'  => 'name',
                'label' => 'Full Name',
                'type'  => 'text',
            ),
        );
        $html = $this->render( $saved );

        // Saved fields surface their label in a builder row.
        $this->assertStringContainsString( 'Full Name', $html );
    }
}

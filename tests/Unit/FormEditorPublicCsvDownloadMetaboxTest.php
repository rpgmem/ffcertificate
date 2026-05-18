<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\FormEditorPublicCsvDownloadMetabox;

/**
 * @covers \FreeFormCertificate\Admin\FormEditorPublicCsvDownloadMetabox
 */
class FormEditorPublicCsvDownloadMetaboxTest extends TestCase {

    use MockeryPHPUnitIntegration;

    private FormEditorPublicCsvDownloadMetabox $metabox;

    /** @var array<string, mixed> */
    private array $meta_values = array();

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_attr__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'esc_attr_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'checked' )->justReturn( '' );
        Functions\when( 'selected' )->justReturn( '' );
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'admin_url' )->alias( function ( $path = '' ) { return '/wp-admin/' . $path; } );
        Functions\when( 'wp_nonce_url' )->returnArg();
        Functions\when( 'site_url' )->alias( function ( $path = '' ) { return 'https://example.com' . $path; } );
        Functions\when( 'home_url' )->alias( function ( $path = '' ) { return 'https://example.com' . $path; } );
        Functions\when( 'esc_textarea' )->returnArg();
        Functions\when( 'apply_filters' )->returnArg( 2 );

        Functions\when( 'get_post_meta' )->alias( function ( $post_id, $key ) {
            return $this->meta_values[ $key ] ?? '';
        } );

        $this->metabox = new FormEditorPublicCsvDownloadMetabox();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function render(): string {
        $post     = Mockery::mock( 'WP_Post' );
        $post->ID = 88;
        ob_start();
        $this->metabox->render( $post );
        return (string) ob_get_clean();
    }

    public function test_render_emits_three_operator_sub_toggles(): void {
        $html = $this->render();

        // The 3 sibling sub-features gated under the master, named via
        // bracket notation on the ffc_csv_public form group.
        $this->assertStringContainsString( 'ffc_csv_public[download_enabled]', $html );
        $this->assertStringContainsString( 'ffc_csv_public[start_early_enabled]', $html );
        $this->assertStringContainsString( 'ffc_csv_public[extend_end_enabled]', $html );
    }

    public function test_render_emits_the_master_present_sentinel(): void {
        $html = $this->render();

        // Hidden sentinel that guarantees the group is present in $_POST even
        // when every toggle is off — required by the save handler.
        $this->assertStringContainsString( 'ffc_csv_public[present]', $html );
    }

    public function test_render_pre_populates_limit_from_meta(): void {
        $this->meta_values['_ffc_csv_public_enabled'] = '1';
        $this->meta_values['_ffc_csv_public_limit']   = 250;

        $html = $this->render();

        $this->assertStringContainsString( '250', $html );
    }

    public function test_render_pre_populates_count_from_meta(): void {
        $this->meta_values['_ffc_csv_public_count'] = 17;

        $html = $this->render();

        $this->assertStringContainsString( '17', $html );
    }

    public function test_render_does_not_throw_with_no_meta(): void {
        $html = $this->render();
        $this->assertNotSame( '', $html );
    }
}

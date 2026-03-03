<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Admin\AdminSubmissionEditPage;

/**
 * @covers \FreeFormCertificate\Admin\AdminSubmissionEditPage
 */
class AdminSubmissionEditPageTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_html__' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'esc_html_e' )->alias( function ( $text ) { echo $text; } );
        Functions\when( 'wp_kses_post' )->returnArg();
        Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/' );

        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', '/tmp/' );
        }
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // Constructor
    // ==================================================================

    public function test_constructor_creates_instance(): void {
        $handler = Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' );
        $page = new AdminSubmissionEditPage( $handler );
        $this->assertInstanceOf( AdminSubmissionEditPage::class, $page );
    }

    // ==================================================================
    // render() — permission denied
    // ==================================================================

    public function test_render_shows_error_without_permission(): void {
        $handler = Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' );
        $page = new AdminSubmissionEditPage( $handler );

        // Utils::current_user_can_manage() is autoloaded
        Functions\when( 'current_user_can' )->justReturn( false );

        ob_start();
        $page->render( 1 );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'do not have permission', $output );
    }

    // ==================================================================
    // render() — submission not found
    // ==================================================================

    public function test_render_shows_error_for_invalid_submission(): void {
        $handler = Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' );
        $handler->shouldReceive( 'get_submission' )->with( 999 )->andReturn( null );

        Functions\when( 'current_user_can' )->justReturn( true );

        $page = new AdminSubmissionEditPage( $handler );

        ob_start();
        $page->render( 999 );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'not found', $output );
    }

    // ==================================================================
    // handle_save() — delegates to handler
    // ==================================================================

    public function test_handle_save_does_nothing_without_post(): void {
        unset( $_POST['ffc_edit_submission'] );
        $handler = Mockery::mock( 'FreeFormCertificate\Submissions\SubmissionHandler' );
        $page = new AdminSubmissionEditPage( $handler );
        $page->handle_save();
        $this->assertTrue( true );
    }
}

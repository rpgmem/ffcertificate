<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Reregistration\ReregistrationCustomFieldsPage;

/**
 * @covers \FreeFormCertificate\Reregistration\ReregistrationCustomFieldsPage
 */
class ReregistrationCustomFieldsPageTest extends TestCase {

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
        Functions\when( 'admin_url' )->justReturn( 'https://example.com/wp-admin/admin.php' );
        Functions\when( 'absint' )->alias( function ( $v ) { return abs( (int) $v ); } );

        if ( ! defined( 'ABSPATH' ) ) {
            define( 'ABSPATH', '/tmp/' );
        }
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // render() — permission denied
    // ==================================================================

    public function test_render_dies_without_manage_options(): void {
        Functions\when( 'current_user_can' )->justReturn( false );
        Functions\when( 'wp_die' )->alias( function ( $msg ) {
            throw new \RuntimeException( $msg );
        } );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionMessage( 'Permission denied.' );
        ReregistrationCustomFieldsPage::render();
    }

    // ==================================================================
    // render() — empty audiences
    // ==================================================================

    public function test_render_shows_no_audiences_message(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $audienceRepoMock = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceRepository' );
        $audienceRepoMock->shouldReceive( 'get_hierarchical' )
            ->with( 'active' )
            ->andReturn( array() );

        ob_start();
        ReregistrationCustomFieldsPage::render();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Custom Fields', $output );
        $this->assertStringContainsString( 'No audiences found', $output );
    }

    // ==================================================================
    // render() — with audiences
    // ==================================================================

    public function test_render_shows_audiences_with_field_counts(): void {
        Functions\when( 'current_user_can' )->justReturn( true );

        $parent = (object) array(
            'id'       => 1,
            'name'     => 'Teachers',
            'color'    => '#ff0000',
            'children' => array(
                (object) array( 'id' => 2, 'name' => 'Math Teachers', 'color' => '#00ff00', 'children' => array() ),
            ),
        );

        $audienceRepoMock = Mockery::mock( 'alias:FreeFormCertificate\Audience\AudienceRepository' );
        $audienceRepoMock->shouldReceive( 'get_hierarchical' )
            ->with( 'active' )
            ->andReturn( array( $parent ) );

        $customFieldRepoMock = Mockery::mock( 'alias:FreeFormCertificate\Reregistration\CustomFieldRepository' );
        $customFieldRepoMock->shouldReceive( 'count_by_audience' )
            ->andReturnUsing( function ( int $id, bool $active_only ) {
                if ( $id === 1 ) return $active_only ? 3 : 5;
                return $active_only ? 0 : 0;
            } );

        ob_start();
        ReregistrationCustomFieldsPage::render();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'Teachers', $output );
        $this->assertStringContainsString( 'Math Teachers', $output );
        $this->assertStringContainsString( '3', $output ); // active count
        $this->assertStringContainsString( '5', $output ); // total count
        $this->assertStringContainsString( 'Edit Fields', $output );
    }
}

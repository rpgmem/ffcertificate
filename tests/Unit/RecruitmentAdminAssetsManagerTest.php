<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Recruitment\RecruitmentAdminAssetsManager;

/**
 * Tests for the recruitment admin assets manager — registration of the
 * admin_enqueue_scripts hook plus the screen-gate inside maybe_enqueue.
 *
 * @covers \FreeFormCertificate\Recruitment\RecruitmentAdminAssetsManager
 */
class RecruitmentAdminAssetsManagerTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( '__' )->returnArg();
        Functions\when( 'esc_url_raw' )->returnArg();
        Functions\when( 'rest_url' )->alias( fn( $p = '' ) => 'https://example.test/wp-json/' . $p );
        Functions\when( 'wp_create_nonce' )->justReturn( 'test-nonce' );

        if ( ! defined( 'FFC_PLUGIN_DIR' ) ) {
            define( 'FFC_PLUGIN_DIR', '/tmp/ffc_recruitment_assets_test/' );
        }
        if ( ! defined( 'FFC_PLUGIN_URL' ) ) {
            define( 'FFC_PLUGIN_URL', 'https://example.test/plugins/ffc/' );
        }
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // register()
    // ------------------------------------------------------------------

    public function test_register_hooks_into_admin_enqueue_scripts(): void {
        Actions\expectAdded( 'admin_enqueue_scripts' )
            ->once()
            ->with( array( RecruitmentAdminAssetsManager::class, 'maybe_enqueue' ), 10 );

        RecruitmentAdminAssetsManager::register();
    }

    // ------------------------------------------------------------------
    // maybe_enqueue() — screen gate
    // ------------------------------------------------------------------

    public function test_maybe_enqueue_no_op_on_unrelated_admin_screen(): void {
        $style_calls  = 0;
        $script_calls = 0;
        Functions\when( 'wp_enqueue_style' )->alias( function () use ( &$style_calls ) { $style_calls++; } );
        Functions\when( 'wp_enqueue_script' )->alias( function () use ( &$script_calls ) { $script_calls++; } );
        Functions\when( 'wp_localize_script' )->justReturn( true );

        RecruitmentAdminAssetsManager::maybe_enqueue( 'edit.php' );

        $this->assertSame( 0, $style_calls, 'no CSS enqueue outside recruitment screen' );
        $this->assertSame( 0, $script_calls, 'no JS enqueue outside recruitment screen' );
    }

    public function test_maybe_enqueue_enqueues_assets_on_recruitment_top_level_page(): void {
        $enqueued_handles = array();
        Functions\when( 'wp_enqueue_style' )->alias( function ( $handle ) use ( &$enqueued_handles ) {
            $enqueued_handles[] = "style:$handle";
        } );
        Functions\when( 'wp_enqueue_script' )->alias( function ( $handle ) use ( &$enqueued_handles ) {
            $enqueued_handles[] = "script:$handle";
        } );
        $localized = null;
        Functions\when( 'wp_localize_script' )->alias( function ( $handle, $name, $data ) use ( &$localized ) {
            $localized = compact( 'handle', 'name', 'data' );
        } );

        RecruitmentAdminAssetsManager::maybe_enqueue( 'toplevel_page_ffc-recruitment' );

        $this->assertContains( 'style:' . RecruitmentAdminAssetsManager::HANDLE_CSS, $enqueued_handles );
        $this->assertContains( 'script:' . RecruitmentAdminAssetsManager::HANDLE_JS, $enqueued_handles );
        $this->assertSame( 'ffcRecruitmentAdmin', $localized['name'] );
        $this->assertArrayHasKey( 'restRoot', $localized['data'] );
        $this->assertArrayHasKey( 'nonce', $localized['data'] );
        $this->assertSame( 'test-nonce', $localized['data']['nonce'] );
    }

    public function test_maybe_enqueue_enqueues_on_recruitment_sub_pages_too(): void {
        $count = 0;
        Functions\when( 'wp_enqueue_style' )->alias( function () use ( &$count ) { $count++; } );
        Functions\when( 'wp_enqueue_script' )->alias( function () use ( &$count ) { $count++; } );
        Functions\when( 'wp_localize_script' )->justReturn( true );

        RecruitmentAdminAssetsManager::maybe_enqueue( 'ffc-recruitment_page_ffc-recruitment-some-sub' );

        $this->assertGreaterThan( 0, $count, 'sub-pages of recruitment should also enqueue' );
    }
}

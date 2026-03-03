<?php
declare(strict_types=1);

namespace FreeFormCertificate\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use FreeFormCertificate\Audience\AudienceLoader;

/**
 * Tests for AudienceLoader: singleton, hook registration, asset enqueue logic,
 * register_capabilities, register_rest_routes, and admin string localisation.
 *
 * @covers \FreeFormCertificate\Audience\AudienceLoader
 */
class AudienceLoaderTest extends TestCase {

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('wp_kses_post')->returnArg();
        Functions\when('absint')->alias(function ($val) {
            return abs(intval($val));
        });

        // Reset singleton between tests via reflection
        $ref = new \ReflectionClass(AudienceLoader::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==================================================================
    // Singleton
    // ==================================================================

    public function test_get_instance_returns_same_instance(): void {
        $a = AudienceLoader::get_instance();
        $b = AudienceLoader::get_instance();

        $this->assertSame($a, $b);
    }

    public function test_get_instance_returns_audience_loader(): void {
        $instance = AudienceLoader::get_instance();

        $this->assertInstanceOf(AudienceLoader::class, $instance);
    }

    // ==================================================================
    // register_capabilities()
    // ==================================================================

    public function test_register_capabilities_fires_action(): void {
        $loader = AudienceLoader::get_instance();

        Actions\expectDone('ffcertificate_audience_register_capabilities')->once();

        $loader->register_capabilities();
    }

    // ==================================================================
    // init() — hook registration
    // ==================================================================

    public function test_init_registers_hooks_in_non_admin(): void {
        Functions\when('is_admin')->justReturn(false);

        // AudienceShortcode::init() calls add_shortcode; stub it
        Functions\when('add_shortcode')->justReturn(null);

        $loader = AudienceLoader::get_instance();

        // We expect add_action to be called with various hooks
        Actions\expectAdded('init');
        Actions\expectAdded('wp_ajax_ffc_audience_check_conflicts');
        Actions\expectAdded('wp_ajax_ffc_audience_create_booking');
        Actions\expectAdded('wp_ajax_ffc_audience_cancel_booking');
        Actions\expectAdded('wp_ajax_ffc_audience_get_booking');
        Actions\expectAdded('wp_ajax_ffc_audience_get_schedule_slots');
        Actions\expectAdded('wp_ajax_ffc_search_users');
        Actions\expectAdded('wp_ajax_ffc_audience_get_environments');
        Actions\expectAdded('wp_ajax_ffc_audience_add_user_permission');
        Actions\expectAdded('wp_ajax_ffc_audience_update_user_permission');
        Actions\expectAdded('wp_ajax_ffc_audience_remove_user_permission');
        Actions\expectAdded('wp_ajax_ffc_save_custom_fields');
        Actions\expectAdded('wp_ajax_ffc_delete_custom_field');
        Actions\expectAdded('wp_enqueue_scripts');
        Actions\expectAdded('rest_api_init');

        $loader->init();
    }

    public function test_init_registers_admin_hooks_when_in_admin(): void {
        Functions\when('is_admin')->justReturn(true);
        Functions\when('add_shortcode')->justReturn(null);

        $loader = AudienceLoader::get_instance();

        // In admin, admin_enqueue_scripts should be registered
        Actions\expectAdded('admin_enqueue_scripts');

        $loader->init();
    }

    // ==================================================================
    // enqueue_admin_assets() — bail on irrelevant page
    // ==================================================================

    public function test_enqueue_admin_assets_returns_early_on_unrelated_hook(): void {
        $loader = AudienceLoader::get_instance();

        // wp_enqueue_style/script should NOT be called
        Functions\expect('wp_enqueue_style')->never();
        Functions\expect('wp_enqueue_script')->never();

        $loader->enqueue_admin_assets('edit.php');
    }

    public function test_enqueue_admin_assets_enqueues_on_audience_page(): void {
        $loader = AudienceLoader::get_instance();

        // Stub the Utils::asset_suffix call
        // The class is already loaded via autoloader; we mock the static call
        // by defining SCRIPT_DEBUG
        if (!defined('SCRIPT_DEBUG')) {
            define('SCRIPT_DEBUG', true);
        }

        Functions\expect('wp_enqueue_style')
            ->atLeast()->once();
        Functions\expect('wp_enqueue_script')
            ->atLeast()->once();
        Functions\expect('wp_localize_script')->once();
        Functions\when('admin_url')->justReturn('https://example.com/wp-admin/admin-ajax.php');
        Functions\when('rest_url')->justReturn('https://example.com/wp-json/ffc/v1/audience/');
        Functions\when('wp_create_nonce')->justReturn('test-nonce');
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_unslash')->returnArg();

        $loader->enqueue_admin_assets('toplevel_page_ffc-audience');
    }

    // ==================================================================
    // enqueue_frontend_assets() — bail when no post
    // ==================================================================

    public function test_enqueue_frontend_assets_returns_early_when_no_post(): void {
        global $post;
        $post = null;

        $loader = AudienceLoader::get_instance();

        Functions\expect('wp_enqueue_style')->never();
        Functions\expect('wp_enqueue_script')->never();

        $loader->enqueue_frontend_assets();
    }

    public function test_enqueue_frontend_assets_returns_early_when_no_shortcode(): void {
        global $post;
        $post = (object) ['post_content' => 'Hello world'];

        $loader = AudienceLoader::get_instance();

        Functions\when('has_shortcode')->justReturn(false);
        Functions\expect('wp_enqueue_style')->never();
        Functions\expect('wp_enqueue_script')->never();

        $loader->enqueue_frontend_assets();
    }

    public function test_enqueue_frontend_assets_enqueues_when_shortcode_present(): void {
        global $post;
        $post = (object) ['post_content' => '[ffc_audience]'];

        $loader = AudienceLoader::get_instance();

        Functions\when('has_shortcode')->justReturn(true);

        Functions\expect('wp_enqueue_style')->atLeast()->once();
        Functions\expect('wp_enqueue_script')->once();

        $loader->enqueue_frontend_assets();
    }

    // ==================================================================
    // register_rest_routes()
    // ==================================================================

    public function test_register_rest_routes_creates_controller(): void {
        // AudienceRestController class exists (loaded by autoloader)
        // We just verify it doesn't throw
        $loader = AudienceLoader::get_instance();

        // Mock register_rest_route to accept any calls
        Functions\when('register_rest_route')->justReturn(true);

        // Should not throw
        $loader->register_rest_routes();

        // If we reached here without exception, the test passes
        $this->assertTrue(true);
    }
}
